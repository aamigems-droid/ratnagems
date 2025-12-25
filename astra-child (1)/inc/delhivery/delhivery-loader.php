<?php
/**
 * Delhivery B2C API Integration for WooCommerce
 *
 * Main loader file that initializes all components of the Delhivery integration.
 * This file should be included in your theme's functions.php or as a must-use plugin.
 *
 * @package Ratna Gems
 * @version 2.1.0
 *
 * Required Constants (define in wp-config.php):
 * - DELHIVERY_API_TOKEN       (required) Your Delhivery API token
 * - DELHIVERY_WAREHOUSE_NAME  (required) Your registered warehouse name (case-sensitive)
 * - DELHIVERY_ORIGIN_PINCODE  (optional) Default origin pincode for cost calculation
 * - DELHIVERY_API_SECRET      (optional) For webhook signature validation
 * - DELHIVERY_STAGING         (optional) Set to true to use staging environment
 * - RG_DELHIVERY_NDR_EMAIL    (optional) Email address for NDR notifications (defaults to admin_email)
 *
 * Official Delhivery Status Reference:
 * StatusType codes: UD (Undelivered), DL (Delivered), RT (Return to Origin), PP (Pickup Pending), PU (Picked Up), CN (Cancelled)
 * Forward statuses: Manifested -> Not Picked -> In Transit -> Pending -> Dispatched -> Delivered
 * RTO statuses: In Transit (RT) -> Pending (RT) -> Dispatched (RT) -> RTO (DL)
 * Reverse statuses: Open (PP) -> Scheduled (PP) -> Dispatched (PP) -> In Transit (PU) -> Pending (PU) -> Dispatched (PU) -> DTO (DL)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Prevent double loading
if ( defined( 'RG_DELHIVERY_VERSION' ) ) {
    return;
}

// Version
define( 'RG_DELHIVERY_VERSION', '2.1.0' );

// Plugin paths
define( 'RG_DELHIVERY_PATH', dirname( __FILE__ ) . '/' );
define( 'RG_DELHIVERY_URL', get_stylesheet_directory_uri() . '/inc/delhivery/' );

/**
 * Check if WooCommerce is active.
 */
function rg_delhivery_check_woocommerce(): bool {
    return class_exists( 'WooCommerce' );
}

/**
 * Check if Delhivery is properly configured.
 */
function rg_delhivery_is_configured(): bool {
    // Support both constant names for API token
    $has_token = ( defined( 'DELHIVERY_API_KEY' ) && ! empty( DELHIVERY_API_KEY ) )
              || ( defined( 'DELHIVERY_API_TOKEN' ) && ! empty( DELHIVERY_API_TOKEN ) );
    
    // Support multiple constant names for warehouse
    $has_warehouse = ( defined( 'DELHIVERY_PICKUP_LOCATION_NAME' ) && ! empty( DELHIVERY_PICKUP_LOCATION_NAME ) )
                  || ( defined( 'DELHIVERY_WAREHOUSE_NAME' ) && ! empty( DELHIVERY_WAREHOUSE_NAME ) )
                  || ( defined( 'DELHIVERY_WAREHOUSE' ) && ! empty( DELHIVERY_WAREHOUSE ) );
    
    return $has_token && $has_warehouse;
}

/**
 * Get the warehouse name from any defined constant.
 */
function rg_delhivery_get_warehouse_name(): string {
    if ( defined( 'DELHIVERY_PICKUP_LOCATION_NAME' ) && ! empty( DELHIVERY_PICKUP_LOCATION_NAME ) ) {
        return DELHIVERY_PICKUP_LOCATION_NAME;
    }
    if ( defined( 'DELHIVERY_WAREHOUSE_NAME' ) && ! empty( DELHIVERY_WAREHOUSE_NAME ) ) {
        return DELHIVERY_WAREHOUSE_NAME;
    }
    if ( defined( 'DELHIVERY_WAREHOUSE' ) && ! empty( DELHIVERY_WAREHOUSE ) ) {
        return DELHIVERY_WAREHOUSE;
    }
    return '';
}

/**
 * Initialize Delhivery integration.
 */
function rg_delhivery_init(): void {
    // Bail if WooCommerce not active
    if ( ! rg_delhivery_check_woocommerce() ) {
        add_action( 'admin_notices', 'rg_delhivery_woocommerce_missing_notice' );
        return;
    }

    // Bail if not configured
    if ( ! rg_delhivery_is_configured() ) {
        add_action( 'admin_notices', 'rg_delhivery_config_missing_notice' );
        return;
    }

    // Load core files - check existence first to prevent fatal errors
    $core_files = array(
        'api-client.php',
        'order-actions.php', 
        'pickup.php',
        'admin-metabox.php',
    );
    
    foreach ( $core_files as $file ) {
        $file_path = RG_DELHIVERY_PATH . $file;
        if ( file_exists( $file_path ) ) {
            require_once $file_path;
        } else {
            add_action( 'admin_notices', function() use ( $file ) {
                echo '<div class="notice notice-error"><p>Delhivery: Missing file - ' . esc_html( $file ) . '</p></div>';
            });
            return; // Stop loading if core file missing
        }
    }
    
    // Load optional enhancements (customer emails, dashboard widget, etc.)
    if ( file_exists( RG_DELHIVERY_PATH . 'enhancements.php' ) ) {
        require_once RG_DELHIVERY_PATH . 'enhancements.php';
    }

    // Load CLI commands (optional)
    if ( defined( 'WP_CLI' ) && WP_CLI && file_exists( RG_DELHIVERY_PATH . 'cli.php' ) ) {
        require_once RG_DELHIVERY_PATH . 'cli.php';
    }

    // Load shipping method
    add_action( 'woocommerce_shipping_init', 'rg_delhivery_shipping_method_init' );
    add_filter( 'woocommerce_shipping_methods', 'rg_delhivery_add_shipping_method' );

    // Admin hooks
    add_action( 'admin_enqueue_scripts', 'rg_delhivery_admin_scripts' );

    // Frontend hooks
    add_action( 'wp_enqueue_scripts', 'rg_delhivery_frontend_scripts' );

    // Cron for tracking updates - register the callback
    add_action( 'rg_delhivery_update_tracking_cron', 'rg_delhivery_cron_update_tracking' );

    // Schedule using Action Scheduler (appears in WooCommerce → Status → Scheduled Actions)
    add_action( 'init', 'rg_delhivery_ensure_cron_scheduled', 20 );

    // Tracking page shortcode
    add_shortcode( 'delhivery_tracking', 'rg_delhivery_tracking_shortcode' );

    // Add settings link to plugins page
    add_filter( 'plugin_action_links', 'rg_delhivery_plugin_links', 10, 2 );
}

/**
 * Ensure cron is scheduled using WooCommerce Action Scheduler.
 * This will appear in WooCommerce → Status → Scheduled Actions.
 */
function rg_delhivery_ensure_cron_scheduled(): void {
    // Check if Action Scheduler is available (WooCommerce must be active)
    if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
        // Fallback to WordPress native cron if Action Scheduler not available
        if ( ! wp_next_scheduled( 'rg_delhivery_update_tracking_cron' ) ) {
            wp_schedule_event( time(), 'hourly', 'rg_delhivery_update_tracking_cron' );
        }
        return;
    }
    
    // Check if already scheduled with Action Scheduler
    if ( as_has_scheduled_action( 'rg_delhivery_update_tracking_cron', array(), 'delhivery' ) ) {
        return; // Already scheduled, nothing to do
    }
    
    // Schedule recurring action every hour (3600 seconds)
    as_schedule_recurring_action(
        time(),                                  // Start time (now)
        HOUR_IN_SECONDS,                         // Interval: 3600 seconds (1 hour)
        'rg_delhivery_update_tracking_cron',     // Hook name
        array(),                                 // Arguments
        'delhivery',                             // Group name (for organization in admin)
        true                                     // Unique - prevents duplicate scheduling
    );
}

// Initialize - support both plugin and theme loading
if ( did_action( 'plugins_loaded' ) ) {
    // Theme is loading this after plugins_loaded already fired
    rg_delhivery_init();
} else {
    // Being loaded as a plugin or mu-plugin
    add_action( 'plugins_loaded', 'rg_delhivery_init', 20 );
}

/**
 * Display WooCommerce missing notice.
 */
function rg_delhivery_woocommerce_missing_notice(): void {
    echo '<div class="notice notice-error"><p>';
    echo esc_html__( 'Delhivery Integration requires WooCommerce to be active.', 'ratna-gems' );
    echo '</p></div>';
}

/**
 * Display configuration missing notice.
 */
function rg_delhivery_config_missing_notice(): void {
    $has_token = ( defined( 'DELHIVERY_API_KEY' ) && ! empty( DELHIVERY_API_KEY ) )
              || ( defined( 'DELHIVERY_API_TOKEN' ) && ! empty( DELHIVERY_API_TOKEN ) );
    
    $has_warehouse = ( defined( 'DELHIVERY_PICKUP_LOCATION_NAME' ) && ! empty( DELHIVERY_PICKUP_LOCATION_NAME ) )
                  || ( defined( 'DELHIVERY_WAREHOUSE_NAME' ) && ! empty( DELHIVERY_WAREHOUSE_NAME ) )
                  || ( defined( 'DELHIVERY_WAREHOUSE' ) && ! empty( DELHIVERY_WAREHOUSE ) );
    
    $missing = array();
    if ( ! $has_token ) {
        $missing[] = '<code>DELHIVERY_API_TOKEN</code>';
    }
    if ( ! $has_warehouse ) {
        $missing[] = '<code>DELHIVERY_WAREHOUSE_NAME</code>';
    }
    
    echo '<div class="notice notice-warning"><p>';
    echo '<strong>Delhivery Integration:</strong> ';
    if ( ! empty( $missing ) ) {
        echo wp_kses_post( sprintf(
            __( 'Missing configuration: %s. Add these constants to your wp-config.php file.', 'ratna-gems' ),
            implode( ' and ', $missing )
        ) );
        echo '<br><br><code style="display:block; background:#f5f5f5; padding:10px; margin-top:5px;">';
        if ( ! $has_token ) {
            echo "define( 'DELHIVERY_API_TOKEN', 'your-api-token-here' );<br>";
        }
        if ( ! $has_warehouse ) {
            echo "define( 'DELHIVERY_WAREHOUSE_NAME', 'Your Warehouse Name' );";
        }
        echo '</code>';
    }
    echo '</p></div>';
}

/**
 * Get singleton instance of API client.
 */
if ( ! function_exists( 'rg_delhivery_client' ) ) {
function rg_delhivery_client(): ?Delhivery_API_Client {
    static $client = null;
    
    if ( null === $client && class_exists( 'Delhivery_API_Client' ) ) {
        $client = new Delhivery_API_Client();
    }
    
    return $client;
}
}

/**
 * Initialize shipping method.
 */
function rg_delhivery_shipping_method_init(): void {
    if ( ! class_exists( 'WC_Shipping_Delhivery' ) ) {
        $shipping_file = RG_DELHIVERY_PATH . 'shipping-method.php';
        if ( file_exists( $shipping_file ) ) {
            require_once $shipping_file;
        }
    }
}

/**
 * Add Delhivery to WooCommerce shipping methods.
 */
function rg_delhivery_add_shipping_method( array $methods ): array {
    $methods['delhivery'] = 'WC_Shipping_Delhivery';
    return $methods;
}

/**
 * Enqueue admin scripts.
 */
function rg_delhivery_admin_scripts( string $hook ): void {
    $screen = get_current_screen();
    
    // Only on order screens
    if ( ! $screen ) return;
    
    $is_order_screen = in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true );
    if ( ! $is_order_screen && strpos( $screen->id, 'edit-shop_order' ) === false ) {
        return;
    }

    wp_enqueue_script(
        'rg-delhivery-admin',
        RG_DELHIVERY_URL . 'assets/admin.js',
        array( 'jquery' ),
        RG_DELHIVERY_VERSION,
        true
    );

    wp_localize_script( 'rg-delhivery-admin', 'rgDelhivery', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'rg_delhivery_nonce' ),
        'i18n'    => array(
            'loading'   => __( 'Loading...', 'ratna-gems' ),
            'error'     => __( 'An error occurred', 'ratna-gems' ),
            'success'   => __( 'Success!', 'ratna-gems' ),
            'confirm'   => __( 'Are you sure?', 'ratna-gems' ),
        ),
    ) );
}

/**
 * Enqueue frontend scripts.
 */
function rg_delhivery_frontend_scripts(): void {
    // Only on tracking page or checkout
    if ( ! is_checkout() && ! has_shortcode( get_post()->post_content ?? '', 'delhivery_tracking' ) ) {
        return;
    }

    wp_enqueue_script(
        'rg-delhivery-frontend',
        RG_DELHIVERY_URL . 'assets/frontend.js',
        array( 'jquery' ),
        RG_DELHIVERY_VERSION,
        true
    );

    wp_localize_script( 'rg-delhivery-frontend', 'rgDelhiveryFrontend', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
    ) );
}

/**
 * Cron job to update tracking for active shipments.
 */
function rg_delhivery_cron_update_tracking(): void {
    $client = rg_delhivery_client();
    if ( ! $client ) return;

    // Get orders with active shipments (manifested but not delivered)
    $orders = wc_get_orders( array(
        'limit'      => 50,
        'date_after' => date( 'Y-m-d', strtotime( '-30 days' ) ),
        'meta_query' => array(
            array(
                'key'     => '_delhivery_awb',
                'compare' => 'EXISTS',
            ),
            array(
                'key'     => '_delhivery_status',
                'value'   => array( 'Delivered', 'DELIVERED', 'DL', 'Success', 'SUCCESS', 'RTO', 'Returned', 'Cancelled', 'CN' ),
                'compare' => 'NOT IN',
            ),
        ),
    ) );

    if ( empty( $orders ) ) return;

    // Collect AWBs
    $awb_map = array();
    foreach ( $orders as $order ) {
        $awb = $order->get_meta( '_delhivery_awb' );
        if ( $awb ) {
            $awb_map[ $awb ] = $order;
        }
    }

    // Track in batches
    $awbs = array_keys( $awb_map );
    $batches = array_chunk( $awbs, 50 );

    foreach ( $batches as $batch ) {
        $results = $client->track_multiple_shipments( $batch );
        
        if ( is_wp_error( $results ) ) continue;

        foreach ( $results as $awb => $data ) {
            if ( ! isset( $awb_map[ $awb ] ) ) continue;

            $order = $awb_map[ $awb ];
            $status = $data['Status']['Status'] ?? $data['status'] ?? '';
            $location = $data['Status']['StatusLocation'] ?? '';
            $datetime = $data['Status']['StatusDateTime'] ?? '';

            if ( $status ) {
                $old_status = $order->get_meta( '_delhivery_status' );
                if ( $status !== $old_status ) {
                    $order->update_meta_data( '_delhivery_status', $status );
                    $order->update_meta_data( '_delhivery_last_update', $datetime ?: current_time( 'mysql' ) );
                    $order->update_meta_data( '_delhivery_last_location', $location );
                    $order->save();

                    // Auto-update WooCommerce order status
                    rg_delhivery_maybe_update_order_status( $order, $status );
                }
            }
        }
    }
}

/**
 * Auto-update WooCommerce order status based on Delhivery status.
 * Note: Primary implementation is in order-actions.php with better status matching.
 */
if ( ! function_exists( 'rg_delhivery_maybe_update_order_status' ) ) {
function rg_delhivery_maybe_update_order_status( WC_Order $order, string $delhivery_status ): void {
    if ( ! apply_filters( 'rg_delhivery_auto_update_order_status', true, $order, $delhivery_status ) ) {
        return;
    }

    $status_map = apply_filters( 'rg_delhivery_status_mapping', array(
        'Delivered'     => 'completed',
        'DELIVERED'     => 'completed',
        'DL'            => 'completed',
        'Success'       => 'completed',
        'SUCCESS'       => 'completed',
        'RTO'           => 'cancelled',
        'RTO-Delivered' => 'cancelled',
        'RTO-OC'        => 'cancelled',
        'Returned'      => 'refunded',
    ) );

    $new_status = $status_map[ $delhivery_status ] ?? null;
    
    if ( $new_status && $order->get_status() !== $new_status ) {
        $order->update_status( $new_status, sprintf( __( 'Auto-updated: Delhivery status changed to %s', 'ratna-gems' ), $delhivery_status ) );
    }
}
}

/**
 * Tracking shortcode for frontend.
 */
function rg_delhivery_tracking_shortcode( array $atts ): string {
    $atts = shortcode_atts( array(
        'title' => __( 'Track Your Order', 'ratna-gems' ),
    ), $atts, 'delhivery_tracking' );

    ob_start();
    ?>
    <div class="rg-delhivery-tracking-widget">
        <h3><?php echo esc_html( $atts['title'] ); ?></h3>
        <form id="rg-delhivery-tracking-form" class="rg-tracking-form">
            <div class="rg-tracking-input-group">
                <label for="rg-tracking-awb"><?php esc_html_e( 'Enter AWB Number or Order ID', 'ratna-gems' ); ?></label>
                <input type="text" id="rg-tracking-awb" name="awb" required placeholder="<?php esc_attr_e( 'e.g., 1234567890123', 'ratna-gems' ); ?>">
            </div>
            <button type="submit" class="button"><?php esc_html_e( 'Track', 'ratna-gems' ); ?></button>
        </form>
        <div id="rg-delhivery-tracking-result" class="rg-tracking-result" style="display:none;"></div>
    </div>
    <script>
    jQuery(function($) {
        $('#rg-delhivery-tracking-form').on('submit', function(e) {
            e.preventDefault();
            var awb = $('#rg-tracking-awb').val().trim();
            var $result = $('#rg-delhivery-tracking-result');
            
            if (!awb) return;
            
            $result.html('<p><?php esc_html_e( 'Loading...', 'ratna-gems' ); ?></p>').show();
            
            $.post(rgDelhiveryFrontend.ajaxUrl, {
                action: 'rg_delhivery_track_shipment',
                awb: awb
            }, function(res) {
                if (res.success) {
                    var d = res.data;
                    var html = '<div class="rg-tracking-info">';
                    html += '<p><strong><?php esc_html_e( 'AWB:', 'ratna-gems' ); ?></strong> ' + d.awb + '</p>';
                    html += '<p><strong><?php esc_html_e( 'Status:', 'ratna-gems' ); ?></strong> <span class="rg-status">' + d.status + '</span></p>';
                    if (d.status_location) {
                        html += '<p><strong><?php esc_html_e( 'Location:', 'ratna-gems' ); ?></strong> ' + d.status_location + '</p>';
                    }
                    if (d.status_datetime) {
                        html += '<p><strong><?php esc_html_e( 'Updated:', 'ratna-gems' ); ?></strong> ' + d.status_datetime + '</p>';
                    }
                    if (d.expected_date) {
                        html += '<p><strong><?php esc_html_e( 'Expected:', 'ratna-gems' ); ?></strong> ' + d.expected_date + '</p>';
                    }
                    html += '</div>';
                    
                    if (d.scans && d.scans.length) {
                        html += '<div class="rg-tracking-history"><h4><?php esc_html_e( 'Tracking History', 'ratna-gems' ); ?></h4><ul>';
                        d.scans.forEach(function(scan) {
                            html += '<li><span class="rg-scan-date">' + (scan.datetime || '') + '</span> - ';
                            html += '<span class="rg-scan-status">' + (scan.status || '') + '</span>';
                            if (scan.location) html += ' (' + scan.location + ')';
                            html += '</li>';
                        });
                        html += '</ul></div>';
                    }
                    
                    $result.html(html);
                } else {
                    $result.html('<p class="rg-error">' + (res.data.message || '<?php esc_html_e( 'Unable to track shipment', 'ratna-gems' ); ?>') + '</p>');
                }
            }).fail(function() {
                $result.html('<p class="rg-error"><?php esc_html_e( 'Error connecting to server', 'ratna-gems' ); ?></p>');
            });
        });
    });
    </script>
    <style>
    .rg-delhivery-tracking-widget { max-width: 500px; margin: 20px 0; }
    .rg-tracking-form { display: flex; gap: 10px; flex-wrap: wrap; }
    .rg-tracking-input-group { flex: 1; min-width: 200px; }
    .rg-tracking-input-group label { display: block; margin-bottom: 5px; font-size: 14px; }
    .rg-tracking-input-group input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
    .rg-tracking-result { margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 4px; }
    .rg-tracking-info p { margin: 8px 0; }
    .rg-status { padding: 3px 8px; background: #e0f0ff; border-radius: 3px; font-weight: bold; }
    .rg-tracking-history { margin-top: 15px; }
    .rg-tracking-history ul { list-style: none; padding: 0; margin: 10px 0; }
    .rg-tracking-history li { padding: 8px 0; border-bottom: 1px solid #eee; font-size: 13px; }
    .rg-scan-date { color: #666; }
    .rg-error { color: #c00; }
    </style>
    <?php
    return ob_get_clean();
}

/**
 * Add settings link.
 */
function rg_delhivery_plugin_links( array $links, string $file ): array {
    if ( strpos( $file, 'delhivery' ) !== false ) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'admin.php?page=wc-settings&tab=shipping&section=delhivery' ),
            __( 'Settings', 'ratna-gems' )
        );
        array_unshift( $links, $settings_link );
    }
    return $links;
}

/**
 * Activation hook - create necessary options.
 */
function rg_delhivery_activate(): void {
    // Initialize waybill pool
    if ( ! get_option( 'rg_delhivery_waybill_pool' ) ) {
        add_option( 'rg_delhivery_waybill_pool', array() );
    }
    
    // Schedule cron using Action Scheduler if available
    if ( function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
        if ( ! as_has_scheduled_action( 'rg_delhivery_update_tracking_cron', array(), 'delhivery' ) ) {
            as_schedule_recurring_action(
                time(),
                HOUR_IN_SECONDS,
                'rg_delhivery_update_tracking_cron',
                array(),
                'delhivery',
                true
            );
        }
    } elseif ( ! wp_next_scheduled( 'rg_delhivery_update_tracking_cron' ) ) {
        // Fallback to WordPress native cron
        wp_schedule_event( time(), 'hourly', 'rg_delhivery_update_tracking_cron' );
    }
    
    // Flush rewrite rules for REST API
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'rg_delhivery_activate' );

/**
 * Deactivation hook - cleanup.
 */
function rg_delhivery_deactivate(): void {
    // Clear Action Scheduler jobs
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( 'rg_delhivery_update_tracking_cron' );
    }
    
    // Also clear WordPress native cron (fallback)
    wp_clear_scheduled_hook( 'rg_delhivery_update_tracking_cron' );
}
register_deactivation_hook( __FILE__, 'rg_delhivery_deactivate' );

/**
 * Display admin notice for high-value orders requiring e-waybill.
 */
add_action( 'admin_notices', function() {
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
        return;
    }

    // Get order ID from URL
    $order_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0 );
    if ( ! $order_id ) return;

    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    // Check if order total > 50,000 and no e-waybill
    if ( (float) $order->get_total() > 50000 && $order->get_meta( '_delhivery_awb' ) && ! $order->get_meta( '_delhivery_ewaybill_number' ) ) {
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>' . esc_html__( 'Delhivery:', 'ratna-gems' ) . '</strong> ';
        echo esc_html__( 'This order exceeds ₹50,000 and requires an E-Waybill as per Indian law. Please update the E-Waybill in the Delhivery metabox.', 'ratna-gems' );
        echo '</p></div>';
    }
} );

/**
 * Add Delhivery status column to orders list.
 */
add_filter( 'manage_edit-shop_order_columns', 'rg_delhivery_order_columns' );
add_filter( 'woocommerce_shop_order_list_table_columns', 'rg_delhivery_order_columns' );

function rg_delhivery_order_columns( array $columns ): array {
    $new_columns = array();
    foreach ( $columns as $key => $value ) {
        $new_columns[ $key ] = $value;
        if ( 'order_status' === $key ) {
            $new_columns['delhivery_status'] = __( 'Delhivery', 'ratna-gems' );
        }
    }
    return $new_columns;
}

add_action( 'manage_shop_order_posts_custom_column', 'rg_delhivery_order_column_content', 10, 2 );
add_action( 'woocommerce_shop_order_list_table_custom_column', 'rg_delhivery_order_column_content', 10, 2 );

function rg_delhivery_order_column_content( string $column, $order ): void {
    if ( 'delhivery_status' !== $column ) return;
    
    if ( ! $order instanceof WC_Order ) {
        $order = wc_get_order( $order );
    }
    if ( ! $order ) return;

    $awb = $order->get_meta( '_delhivery_awb' );
    $status = $order->get_meta( '_delhivery_status' );

    if ( $awb ) {
        // Has AWB - show tracking status
        $status_class = 'rg-dlv-status-pending';
        $status_lower = strtolower( $status );
        
        if ( strpos( $status_lower, 'delivered' ) !== false || $status_lower === 'dl' ) {
            $status_class = 'rg-dlv-status-delivered';
        } elseif ( strpos( $status_lower, 'out for delivery' ) !== false || strpos( $status_lower, 'ofd' ) !== false ) {
            $status_class = 'rg-dlv-status-ofd';
        } elseif ( strpos( $status_lower, 'transit' ) !== false || strpos( $status_lower, 'dispatch' ) !== false || strpos( $status_lower, 'reached' ) !== false ) {
            $status_class = 'rg-dlv-status-transit';
        } elseif ( strpos( $status_lower, 'rto' ) !== false || strpos( $status_lower, 'return' ) !== false ) {
            $status_class = 'rg-dlv-status-rto';
        } elseif ( strpos( $status_lower, 'ndr' ) !== false || strpos( $status_lower, 'undelivered' ) !== false ) {
            $status_class = 'rg-dlv-status-ndr';
        } elseif ( strpos( $status_lower, 'success' ) !== false || strpos( $status_lower, 'manifest' ) !== false || strpos( $status_lower, 'pending' ) !== false ) {
            $status_class = 'rg-dlv-status-success';
        }

        printf(
            '<a href="https://www.delhivery.com/track/package/%1$s" target="_blank" class="rg-dlv-col-status %2$s" title="%3$s">%4$s</a>',
            esc_attr( $awb ),
            esc_attr( $status_class ),
            esc_attr( 'AWB: ' . $awb ),
            esc_html( $status ?: 'Manifested' )
        );
    } else {
        // No AWB - check if order is ready for manifest
        $order_status = $order->get_status();
        
        if ( in_array( $order_status, array( 'processing', 'on-hold' ), true ) ) {
            // Check serviceability
            $pincode = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
            $serviceable = rg_delhivery_check_pincode_serviceable( $pincode );
            
            if ( $serviceable ) {
                printf(
                    '<span class="rg-dlv-col-status rg-dlv-status-ready" title="%s">✓ %s</span>',
                    esc_attr__( 'Ready to manifest with Delhivery', 'ratna-gems' ),
                    esc_html__( 'Ready', 'ratna-gems' )
                );
            } else {
                printf(
                    '<span class="rg-dlv-col-status rg-dlv-status-not-serviceable" title="%s">✗ %s</span>',
                    esc_attr( sprintf( __( 'Pincode %s not serviceable by Delhivery. Use another courier.', 'ratna-gems' ), $pincode ) ),
                    esc_html__( 'N/A', 'ratna-gems' )
                );
            }
        } elseif ( in_array( $order_status, array( 'completed', 'cancelled', 'refunded', 'failed' ), true ) ) {
            echo '<span class="rg-dlv-col-status rg-dlv-status-none">—</span>';
        } else {
            echo '<span class="rg-dlv-col-status rg-dlv-status-none">—</span>';
        }
    }
}

/**
 * Check if pincode is serviceable by Delhivery (cached).
 *
 * @param string $pincode The pincode to check.
 * @return bool True if serviceable, false otherwise.
 */
function rg_delhivery_check_pincode_serviceable( string $pincode ): bool {
    if ( empty( $pincode ) ) return false;
    
    $pincode = preg_replace( '/\D/', '', $pincode );
    if ( strlen( $pincode ) < 6 ) return false;
    
    $cache_key = 'rg_dlv_svc_' . $pincode;
    $cached = get_transient( $cache_key );
    
    if ( false !== $cached ) {
        return 'yes' === $cached;
    }
    
    $client = rg_delhivery_client();
    if ( ! $client ) return true; // Assume serviceable if client unavailable
    
    $result = $client->pincode_serviceability( $pincode );
    
    if ( is_wp_error( $result ) ) {
        return true; // Assume serviceable on error to not block
    }
    
    $is_serviceable = ! empty( $result['is_serviceable'] ) && empty( $result['has_embargo'] );
    
    // Cache for 6 hours
    set_transient( $cache_key, $is_serviceable ? 'yes' : 'no', 6 * HOUR_IN_SECONDS );
    
    return $is_serviceable;
}

// Add inline styles for order column
add_action( 'admin_head', function() {
    $screen = get_current_screen();
    if ( ! $screen || ( strpos( $screen->id, 'shop_order' ) === false && strpos( $screen->id, 'wc-orders' ) === false ) ) return;
    ?>
    <style>
    .rg-dlv-col-status { display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 11px; text-decoration: none; font-weight: 500; transition: opacity 0.2s; }
    .rg-dlv-col-status:hover { opacity: 0.8; }
    .rg-dlv-status-delivered { background: #d4edda; color: #155724; }
    .rg-dlv-status-ofd { background: #d1ecf1; color: #0c5460; }
    .rg-dlv-status-transit { background: #fff3cd; color: #856404; }
    .rg-dlv-status-pending { background: #e2e3e5; color: #383d41; }
    .rg-dlv-status-success { background: #cce5ff; color: #004085; }
    .rg-dlv-status-rto { background: #f8d7da; color: #721c24; }
    .rg-dlv-status-ndr { background: #ffeeba; color: #856404; border: 1px solid #ffc107; }
    .rg-dlv-status-ready { background: #d4edda; color: #155724; cursor: default; }
    .rg-dlv-status-not-serviceable { background: #f8d7da; color: #721c24; cursor: help; }
    .rg-dlv-status-none { color: #999; }
    .column-delhivery_status { width: 110px; }
    </style>
    <?php
} );
