<?php
/**
 * Ratna Gems theme bootstrap.
 *
 * @package Ratna Gems
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CHILD_THEME_RATNA_GEMS_VERSION', '3.4.2' );
define( 'RATNA_GEMS_THEME_DIR', get_stylesheet_directory() );
define( 'RATNA_GEMS_THEME_URI', get_stylesheet_directory_uri() );
define( 'RATNA_GEMS_BUILD_VERSION', (string) CHILD_THEME_RATNA_GEMS_VERSION );

$ratna_gems_includes = array(
    '/inc/woocommerce.php',
);

foreach ( $ratna_gems_includes as $relative_path ) {
    $absolute_path = RATNA_GEMS_THEME_DIR . $relative_path;

    if ( file_exists( $absolute_path ) ) {
        require_once $absolute_path;
    }
}

require_once RATNA_GEMS_THEME_DIR . '/assets/inc/shortcode-video.php';
require_once RATNA_GEMS_THEME_DIR . '/assets/inc/subscriber-functions.php';
require_once RATNA_GEMS_THEME_DIR . '/assets/inc/consent-banner.php';
//require_once RATNA_GEMS_THEME_DIR . '/assets/inc/promo-popup.php';

if ( class_exists( 'WooCommerce' ) ) {
    require_once RATNA_GEMS_THEME_DIR . '/assets/inc/product-video-in-gallery.php';
    require_once RATNA_GEMS_THEME_DIR . '/assets/inc/product-filters.php';
    require_once RATNA_GEMS_THEME_DIR . '/assets/inc/buy-now-button.php';
    require_once RATNA_GEMS_THEME_DIR . '/assets/inc/ga4-datalayer.php';
    
    // Delhivery - single loader handles everything
    $delhivery_loader = RATNA_GEMS_THEME_DIR . '/inc/delhivery/delhivery-loader.php';
    if ( file_exists( $delhivery_loader ) ) {
        require_once $delhivery_loader;
    }
}
add_action( 'after_setup_theme', 'ratna_gems_setup_theme' );
/**
 * Register theme supports and translations.
 */
function ratna_gems_setup_theme(): void {
    load_child_theme_textdomain( 'ratna-gems', RATNA_GEMS_THEME_DIR . '/languages' );
    add_theme_support( 'woocommerce' );
}


if ( class_exists( '\\LiteSpeed\\Cloud' ) ) {
    add_action(
        'admin_init',
        static function (): void {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            \LiteSpeed\Cloud::save_summary(
                array(
                    'server.ccss' => 'https://node19.quic.cloud',
                    'server.ucss' => 'https://node19.quic.cloud',
                    'server.vpi'  => 'https://node19.quic.cloud',
                )
            );
        }
    );
}

add_action( 'wp_enqueue_scripts', 'ratna_gems_enqueue_assets', 20 );
/**
 * Register and enqueue theme assets.
 */
function ratna_gems_enqueue_assets(): void {
    $version    = RATNA_GEMS_BUILD_VERSION;
    $dir        = RATNA_GEMS_THEME_DIR;
    $uri        = RATNA_GEMS_THEME_URI;
    $style_path = $dir . '/style.css';

    wp_register_style(
        'ratna-gems-theme-css',
        get_stylesheet_uri(),
        array( 'astra-theme-css' ),
        file_exists( $style_path ) ? (string) filemtime( $style_path ) : $version
    );
    wp_enqueue_style( 'ratna-gems-theme-css' );

    $page_map = array(
        'home'                         => '/assets/css/pages/home.css',
        'about-us'                     => '/assets/css/pages/about.css',
        'contact-us'                   => '/assets/css/pages/contact.css',
        //'faqs'                         => '/assets/css/pages/faqs.css',
        'shipping-and-delivery-policy' => '/assets/css/pages/shipping.css',
        'terms-and-conditions'         => '/assets/css/pages/terms.css',
        'privacy-policy'               => '/assets/css/pages/privacy.css',
        'return-and-refund-policy'     => '/assets/css/pages/return.css',
    );

    foreach ( $page_map as $slug => $rel ) {
        $path = $dir . $rel;
        if ( is_page( $slug ) && file_exists( $path ) ) {
            wp_register_style( "rg-page-{$slug}", $uri . $rel, array( 'ratna-gems-theme-css' ), (string) filemtime( $path ) );
            wp_enqueue_style( "rg-page-{$slug}" );
            break;
        }
    }

    $blog_rel  = '/assets/css/blog.css';
    $blog_path = $dir . $blog_rel;
    if ( is_singular( 'post' ) && file_exists( $blog_path ) ) {
        wp_register_style( 'rg-blog', $uri . $blog_rel, array( 'ratna-gems-theme-css' ), (string) filemtime( $blog_path ) );
        wp_enqueue_style( 'rg-blog' );
    }

    $forms_script = $dir . '/assets/js/forms.js';
    if ( file_exists( $forms_script ) ) {
        wp_register_script( 'ratna-gems-forms', $uri . '/assets/js/forms.js', array(), (string) filemtime( $forms_script ), true );
        wp_script_add_data( 'ratna-gems-forms', 'strategy', 'defer' );
        wp_enqueue_script( 'ratna-gems-forms' );
        wp_localize_script( 'ratna-gems-forms', 'rgForms', array(
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'restUrl'  => esc_url_raw( rest_url( 'rg/v1/contact' ) ),
            'nonces'   => array(
                'subscribe' => wp_create_nonce( 'add_new_subscriber_nonce' ),
                'contact'   => wp_create_nonce( 'rg_public_contact' ),
            ),
            'actions'  => array( 'subscribe' => 'add_new_subscriber' ),
            'messages' => array(
                'loading'          => esc_html__( 'Sending…', 'ratna-gems' ),
                'subscribeSuccess' => esc_html__( 'Thank you for subscribing!', 'ratna-gems' ),
                'subscribeError'   => esc_html__( 'Subscription failed. Please try again.', 'ratna-gems' ),
                'contactSuccess'   => esc_html__( 'Thank you! Your message has been sent.', 'ratna-gems' ),
                'contactError'     => esc_html__( 'Sorry, we could not send your message right now.', 'ratna-gems' ),
                'networkError'     => esc_html__( 'A network error occurred. Please check your connection and try again.', 'ratna-gems' ),
            ),
        ) );
    }

    if ( is_front_page() ) {
        $homepage_script = $dir . '/assets/js/homepage-scripts.js';
        if ( file_exists( $homepage_script ) ) {
            wp_register_script( 'ratna-gems-homepage', $uri . '/assets/js/homepage-scripts.js', array(), (string) filemtime( $homepage_script ), true );
            wp_script_add_data( 'ratna-gems-homepage', 'strategy', 'defer' );
            wp_enqueue_script( 'ratna-gems-homepage' );
            wp_localize_script( 'ratna-gems-homepage', 'ratnaGemsHomepageConfig', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'add_new_subscriber_nonce' ),
                'strings' => array(
                    'goToSlide'               => __( 'Go to slide %d', 'ratna-gems' ),
                    'showMoreRudraksha'       => __( 'Show More Rudraksha', 'ratna-gems' ),
                    'showLess'                => __( 'Show Less', 'ratna-gems' ),
                    'showAllBracelets'        => __( 'Show All Bracelets', 'ratna-gems' ),
                    'subscriptionSubmitting'  => __( 'Submitting...', 'ratna-gems' ),
                    'subscriptionUnavailable' => __( 'Subscription is currently unavailable.', 'ratna-gems' ),
                    'subscriptionSuccess'     => __( 'Success! You are now subscribed.', 'ratna-gems' ),
                    'subscriptionError'       => __( 'An error occurred. Please try again.', 'ratna-gems' ),
                    'networkError'            => __( 'A network error occurred. Please check your connection.', 'ratna-gems' ),
                ),
            ) );
        }
    }
}

add_filter( 'wp_resource_hints', 'ratna_gems_resource_hints', 10, 2 );
/**
 * Add additional resource hints for performance.
 */
function ratna_gems_resource_hints( array $urls, string $relation ): array {
    if ( 'preconnect' !== $relation ) {
        return $urls;
    }

    $preconnect_hosts = array( 'https://cdn.trustindex.io' );

    foreach ( $preconnect_hosts as $host ) {
        if ( ! in_array( $host, $urls, true ) ) {
            $urls[] = $host;
        }
    }

    $gstatic = array(
        'href'        => 'https://fonts.gstatic.com',
        'crossorigin' => 'anonymous',
    );

    $has_gstatic = false;

    foreach ( $urls as $entry ) {
        if ( is_array( $entry ) && isset( $entry['href'] ) ) {
            if ( $gstatic['href'] === $entry['href'] ) {
                $has_gstatic = true;
                break;
            }
        } elseif ( is_string( $entry ) && $gstatic['href'] === $entry ) {
            $has_gstatic = true;
            break;
        }
    }

    if ( ! $has_gstatic ) {
        $urls[] = $gstatic;
    }

    return $urls;
}

add_action( 'admin_enqueue_scripts', 'ratna_gems_bootstrap_product_admin_script' );
/**
 * Auto-configure stock fields for new products.
 */
function ratna_gems_bootstrap_product_admin_script( string $hook ): void {
    if ( 'post-new.php' !== $hook && 'post.php' !== $hook ) {
        return;
    }

    $screen = get_current_screen();
    if ( ! $screen || 'product' !== $screen->post_type ) {
        return;
    }

    wp_register_script( 'ratna-gems-product-admin', false, array(), RATNA_GEMS_BUILD_VERSION, true );
    wp_enqueue_script( 'ratna-gems-product-admin' );

    $inline = <<<'JS'
(function(){
  var manageStock = document.getElementById('_manage_stock');
  if (!manageStock || !document.body || !document.body.classList.contains('post-new-php')) {
    return;
  }

  manageStock.checked = true;

  if (typeof manageStock.dispatchEvent === 'function') {
    var changeEvent;
    if (typeof window.Event === 'function') {
      try {
        changeEvent = new Event('change', { bubbles: true });
      } catch (error) {}
    }

    if (!changeEvent && typeof document.createEvent === 'function') {
      changeEvent = document.createEvent('HTMLEvents');
      changeEvent.initEvent('change', true, false);
    }

    if (changeEvent) {
      try {
        manageStock.dispatchEvent(changeEvent);
      } catch (error) {}
    }
  }

  var stockField = document.getElementById('_stock');
  if (stockField && !stockField.value) {
    stockField.value = '1';
  }

  var soldIndividually = document.getElementById('_sold_individually');
  if (soldIndividually) {
    soldIndividually.checked = true;
  }
})();
JS;

    wp_add_inline_script( 'ratna-gems-product-admin', $inline );
}

// Review Request System
require_once get_stylesheet_directory() . '/inc/review-system/review-request-system.php';

/**
 * Load FAQ accordion assets globally where needed
 */
add_action( 'wp_enqueue_scripts', 'ratna_gems_faq_accordion_assets', 25 );
function ratna_gems_faq_accordion_assets(): void {
    // Load on: FAQ page, all product pages
    $should_load = is_page( 'faqs' ) || is_product();
    
    if ( ! $should_load ) {
        return;
    }

    $dir = RATNA_GEMS_THEME_DIR;
    $uri = RATNA_GEMS_THEME_URI;

    // FAQ CSS
    $faq_css_path = $dir . '/assets/css/pages/faqs.css';
    if ( file_exists( $faq_css_path ) ) {
        wp_enqueue_style(
            'ratna-gems-faq',
            $uri . '/assets/css/pages/faqs.css',
            array( 'ratna-gems-theme-css' ),
            (string) filemtime( $faq_css_path )
        );
    }

    // FAQ Accordion JS
    $faq_js_path = $dir . '/assets/js/faq-accordion.js';
    if ( file_exists( $faq_js_path ) ) {
        wp_enqueue_script(
            'ratna-gems-faq-accordion',
            $uri . '/assets/js/faq-accordion.js',
            array(),
            (string) filemtime( $faq_js_path ),
            true
        );
        wp_script_add_data( 'ratna-gems-faq-accordion', 'strategy', 'defer' );
    }
}
