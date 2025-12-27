<?php
/**
 * Sarfaraz Gems — Independent AJAX Product Filters
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class SG_Product_Filter {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_sg_filter_products', [ $this, 'ajax_filter_products' ] );
        add_action( 'wp_ajax_nopriv_sg_filter_products', [ $this, 'ajax_filter_products' ] );
        
        // This hook will display the entire filter system on shop/category pages
        add_action( 'woocommerce_before_shop_loop', [ $this, 'display_filter_container' ], 15 );

        // Hooks to clear caches when products or terms are updated
        add_action( 'save_post_product', [ $this, 'clear_filter_transients' ] );
        add_action( 'woocommerce_update_product', [ $this, 'clear_filter_transients' ] );
        add_action( 'set_object_terms', [ $this, 'clear_filter_transients_on_term_change' ], 10, 4 );
    }

    public function build_filter_query_args( $filter_data ) {
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'meta_query'     => [ 'relation' => 'AND', [ 'key' => '_stock_status', 'value' => 'instock' ] ],
            'tax_query'      => [ 'relation' => 'AND' ],
        ];
        
        if ( ! empty( $filter_data['product_cat'] ) ) $args['tax_query'][] = [ 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => sanitize_text_field( $filter_data['product_cat'] ) ];
        if ( ! empty( $filter_data['min_price'] ) && is_numeric($filter_data['min_price']) ) $args['meta_query'][] = [ 'key' => '_price', 'value' => (float) $filter_data['min_price'], 'compare' => '>=', 'type' => 'NUMERIC' ];
        if ( ! empty( $filter_data['max_price'] ) && is_numeric($filter_data['max_price']) ) $args['meta_query'][] = [ 'key' => '_price', 'value' => (float) $filter_data['max_price'], 'compare' => '<=', 'type' => 'NUMERIC' ];
        
        // Carat values are stored in the WooCommerce weight meta field.
        if ( ! empty( $filter_data['min_carat'] ) && is_numeric($filter_data['min_carat']) ) $args['meta_query'][] = [ 'key' => '_weight', 'value' => (float) $filter_data['min_carat'], 'compare' => '>=', 'type' => 'DECIMAL(10,3)' ];
        if ( ! empty( $filter_data['max_carat'] ) && is_numeric($filter_data['max_carat']) ) $args['meta_query'][] = [ 'key' => '_weight', 'value' => (float) $filter_data['max_carat'], 'compare' => '<=', 'type' => 'DECIMAL(10,3)' ];

        if ( ! empty( $filter_data['filter_origin'] ) ) {
            $terms = is_array($filter_data['filter_origin']) ? $filter_data['filter_origin'] : explode( ',', $filter_data['filter_origin'] );
            $args['tax_query'][] = [ 'taxonomy' => 'pa_origin', 'field' => 'slug', 'terms' => array_map('sanitize_text_field', $terms), 'operator' => 'IN' ];
        }

        return $args;
    }
    
    public function get_current_products_filter_data() {
        $context = is_product_category() ? 'cat_' . get_queried_object()->slug : (is_product_tag() ? 'tag_' . get_queried_object()->slug : 'global_shop');
        $transient_key = 'sg_filter_data_' . md5( $context );
        if ( false !== ( $cached_data = get_transient( $transient_key ) ) ) return $cached_data;

        $tax_query = [];
        if ( is_product_category() ) $tax_query[] = [ 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => get_queried_object()->slug ];
        if ( is_product_tag() )    $tax_query[] = [ 'taxonomy' => 'product_tag', 'field' => 'slug', 'terms' => get_queried_object()->slug ];
        $query_args = [ 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'tax_query' => $tax_query, 'meta_query' => [ [ 'key' => '_stock_status', 'value' => 'instock' ] ], 'no_found_rows' => true ];
        $product_ids = ( new WP_Query( $query_args ) )->posts;

        if ( empty( $product_ids ) ) {
            $data = [ 'min_price' => 0, 'max_price' => 0, 'min_carat' => 0, 'max_carat' => 0, 'origins' => [] ];
            set_transient( $transient_key, $data, HOUR_IN_SECONDS );
            return $data;
        }

        global $wpdb;
        $ids_placeholder = implode( ',', array_map('absint', $product_ids) );
        $min_max_price = $wpdb->get_row("SELECT MIN(CAST(meta_value AS DECIMAL(18,2))) AS min_price, MAX(CAST(meta_value AS DECIMAL(18,2))) AS max_price FROM {$wpdb->postmeta} WHERE post_id IN ($ids_placeholder) AND meta_key='_price'");
        $min_max_carat = $wpdb->get_row("SELECT MIN(CAST(meta_value AS DECIMAL(18,3))) AS min_carat, MAX(CAST(meta_value AS DECIMAL(18,3))) AS max_carat FROM {$wpdb->postmeta} WHERE post_id IN ($ids_placeholder) AND meta_key='_weight' AND meta_value > 0");
        $origin_terms = get_terms(['taxonomy' => 'pa_origin', 'object_ids' => $product_ids, 'hide_empty' => true]);

        $data = [
            'min_price' => $min_max_price ? floor( (float) $min_max_price->min_price ) : 0,
            'max_price' => $min_max_price ? ceil( (float) $min_max_price->max_price ) : 0,
            'min_carat' => $min_max_carat ? (float) $min_max_carat->min_carat : 0,
            'max_carat' => $min_max_carat ? (float) $min_max_carat->max_carat : 0,
            'origins'   => ! is_wp_error( $origin_terms ) ? $origin_terms : [],
        ];

        set_transient( $transient_key, $data, HOUR_IN_SECONDS );
        return $data;
    }
    
    public function get_active_filters_html( $get_params, $clear_url = '' ) {
        $filters = [];
        if ( ! empty( $get_params['min_price'] ) || ! empty( $get_params['max_price'] ) ) {
            $min = wc_price( (float) ($get_params['min_price'] ?? 0) );
            $max = wc_price( (float) ($get_params['max_price'] ?? 0) );
            $value_html = "{$min} - {$max}";
            $filters[] = '<li data-filter="price"><span class="filter-label">Price:</span> <span class="filter-value">' . $value_html . '</span><button class="remove-filter" aria-label="Remove Price Filter">&times;</button></li>';
        }
        if ( ! empty( $get_params['min_carat'] ) || ! empty( $get_params['max_carat'] ) ) {
            $min = (float) ($get_params['min_carat'] ?? 0) . ' ct';
            $max = (float) ($get_params['max_carat'] ?? 0) . ' ct';
            $value_html = "{$min} - {$max}";
            $filters[] = '<li data-filter="carat"><span class="filter-label">Carat:</span> <span class="filter-value">' . $value_html . '</span><button class="remove-filter" aria-label="Remove Carat Filter">&times;</button></li>';
        }
        if ( ! empty( $get_params['filter_origin'] ) ) {
            $slugs = explode(',', $get_params['filter_origin']);
            foreach($slugs as $slug) {
                $term = get_term_by('slug', $slug, 'pa_origin');
                if ($term) $filters[] = '<li data-filter="origin" data-value="' . esc_attr($slug) . '"><span class="filter-label">Origin:</span> <span class="filter-value">' . esc_html($term->name) . '</span><button class="remove-filter" aria-label="Remove ' . esc_attr($term->name) . ' Origin Filter">&times;</button></li>';
            }
        }

        if ( empty( $filters ) ) return '';
        
        if ( ! $clear_url ) {
            $clear_url = is_product_taxonomy() ? get_term_link( get_queried_object() ) : get_permalink( wc_get_page_id( 'shop' ) );
        }
        return '<div class="sg-active-filters"><h4>Active Filters</h4><ul>' . implode('', $filters) . '</ul><a href="' . esc_url( $clear_url ) . '" class="sg-clear-filters">Clear All</a></div>';
    }

    public function ajax_filter_products() {
        check_ajax_referer( 'sg_filter_nonce', 'nonce' );
        parse_str( wp_unslash( $_POST['form_data'] ?? '' ), $form_data );
        
        $base_url = ! empty( $_POST['base_url'] ) ? esc_url_raw( $_POST['base_url'] ) : get_permalink( wc_get_page_id( 'shop' ) );
        
        $args = $this->build_filter_query_args( $form_data );
        $args['posts_per_page'] = (int) get_option( 'posts_per_page' );
        $args['paged'] = 1;
        
        $query = new WP_Query( $args );

        ob_start();
        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                wc_get_template_part( 'content', 'product' );
            }
        } else {
            wc_get_template( 'loop/no-products-found.php' );
        }
        $products_html = ob_get_clean();
        wp_reset_postdata();

        $active_filters_html = $this->get_active_filters_html($form_data, $base_url);

        wp_send_json_success( [ 'products' => $products_html, 'active_filters' => $active_filters_html ] );
    }
    
    public function display_filter_container() {
        if ( ! is_shop() && ! is_product_taxonomy() ) return;
        
        $data = $this->get_current_products_filter_data();
        $get = [];
        if ( ! empty( $_GET ) ) {
            $get = map_deep( wp_unslash( $_GET ), 'sanitize_text_field' );
        }
        $current_min_price = isset( $get['min_price'] ) && is_numeric( $get['min_price'] ) ? (float) $get['min_price'] : $data['min_price'];
        $current_max_price = isset( $get['max_price'] ) && is_numeric( $get['max_price'] ) ? (float) $get['max_price'] : $data['max_price'];
        $current_min_carat = isset( $get['min_carat'] ) && is_numeric( $get['min_carat'] ) ? (float) $get['min_carat'] : $data['min_carat'];
        $current_max_carat = isset( $get['max_carat'] ) && is_numeric( $get['max_carat'] ) ? (float) $get['max_carat'] : $data['max_carat'];
        $current_origins = isset($get['filter_origin']) ? explode(',', $get['filter_origin']) : [];
        $form_action = is_product_taxonomy() ? get_term_link( get_queried_object() ) : get_permalink( wc_get_page_id( 'shop' ) );
        ?>
        <div class="sg-filter-wrapper">
            <div class="sg-filter-toolbar">
                <button class="sg-filter-toggle">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
                    <span>Filters</span>
                </button>
                <div id="sg-active-filters-wrapper">
                    <?php echo $this->get_active_filters_html($get); ?>
                </div>
            </div>
            <div id="sg-filters-container" class="sg-filters-collapsible">
                <div class="sg-filter-content">
                    <form method="GET" action="<?php echo esc_url( $form_action ); ?>" id="sg-filters-form">
                        <?php if ( $data['max_price'] > $data['min_price'] ) : ?>
                        <div class="filter-group">
                            <h4>Price Range</h4>
                            <div class="filter-slider" id="price-range-slider" data-min="<?php echo esc_attr( $data['min_price'] ); ?>" data-max="<?php echo esc_attr( $data['max_price'] ); ?>" data-current-min="<?php echo esc_attr( $current_min_price ); ?>" data-current-max="<?php echo esc_attr( $current_max_price ); ?>"></div>
                            <div class="slider-values"><span id="price-range-min-text"></span> &ndash; <span id="price-range-max-text"></span></div>
                            <input type="hidden" name="min_price" id="min_price" value="<?php echo esc_attr( $current_min_price ); ?>">
                            <input type="hidden" name="max_price" id="max_price" value="<?php echo esc_attr( $current_max_price ); ?>">
                        </div>
                        <?php endif; ?>
                        <?php if ( $data['max_carat'] > $data['min_carat'] ) : ?>
                        <div class="filter-group">
                            <h4>Carat Weight</h4>
                            <div class="filter-slider" id="carat-range-slider" data-min="<?php echo esc_attr( $data['min_carat'] ); ?>" data-max="<?php echo esc_attr( $data['max_carat'] ); ?>" data-current-min="<?php echo esc_attr( $current_min_carat ); ?>" data-current-max="<?php echo esc_attr( $current_max_carat ); ?>"></div>
                            <div class="slider-values"><span id="carat-range-min-text"></span> &ndash; <span id="carat-range-max-text"></span></div>
                            <input type="hidden" name="min_carat" id="min_carat" value="<?php echo esc_attr( $current_min_carat ); ?>">
                            <input type="hidden" name="max_carat" id="max_carat" value="<?php echo esc_attr( $current_max_carat ); ?>">
                        </div>
                        <?php endif; ?>
                        <?php if ( ! empty( $data['origins'] ) ) : ?>
                        <div class="filter-group">
                            <h4>Product Origin</h4>
                            <ul class="origin-list">
                                <?php foreach ( $data['origins'] as $origin ) : ?>
                                <li><label><input type="checkbox" name="filter_origin" value="<?php echo esc_attr( $origin->slug ); ?>" <?php checked( in_array( $origin->slug, $current_origins, true ) ); ?>> <span class="origin-name"><?php echo esc_html( $origin->name ); ?></span></label></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
        <?php
        // We need a wrapper around the product loop for AJAX replacement
        echo '<div id="sg-product-archive">';
    }

    public function clear_filter_transients() { global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_sg\_filter\_data\_%'" ); }
    public function clear_filter_transients_on_term_change( $object_id, $terms, $tt_ids, $taxonomy ) { if ( in_array( $taxonomy, [ 'product_cat', 'product_tag', 'pa_origin' ], true ) ) $this->clear_filter_transients(); }
}

SG_Product_Filter::instance();

// Add a closing div tag after the loop
add_action('woocommerce_after_shop_loop', function(){
    if ( is_shop() || is_product_taxonomy() ) {
        echo '</div>';
    }
}, 5);
