<?php
/**
 * Sarfaraz Gems Child Theme functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 * @package SarfarazGemsChildTheme
 * @version 1.0.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Define Constants
 */
define( 'CHILD_THEME_VERSION', '1.0.8' ); // Final version number


// =============================================================================
// 1. INCLUDE REQUIRED FEATURE FILES
// =============================================================================

$inc_path = get_stylesheet_directory() . '/assets/inc/';

require_once $inc_path . 'subscriber-functions.php';
require_once $inc_path . 'mega-menu.php';
require_once $inc_path . 'whatsapp-button.php';
require_once $inc_path . 'sticky-footer-bar.php';
require_once $inc_path . 'product-filters.php';
require_once $inc_path . 'video-shortcode.php';
// Note: Google Reviews functionality is included directly below in Section 3.


// =============================================================================
// 2. ENQUEUE STYLES AND SCRIPTS
// =============================================================================

add_action( 'wp_enqueue_scripts', 'sg_enqueue_assets' );
/**
 * Enqueue all theme styles and scripts in one organized function.
 */
function sg_enqueue_assets() {

    $css_path = get_stylesheet_directory_uri() . '/assets/css/';
    $pages_css_path = $css_path . 'pages/';
    $js_path  = get_stylesheet_directory_uri() . '/assets/js/';

	// --- Enqueue Stylesheets ---
	wp_enqueue_style( 'parent-style', get_template_directory_uri() . '/style.css' );
	wp_enqueue_style( 'child-style', get_stylesheet_uri(), [ 'parent-style' ], CHILD_THEME_VERSION );

    // Feature-specific stylesheets
    wp_enqueue_style( 'sg-mega-menu-style', $css_path . 'mega-menu.css', [ 'child-style' ], CHILD_THEME_VERSION );
    wp_enqueue_style( 'sg-whatsapp-button-style', $css_path . 'whatsapp-button.css', [ 'child-style' ], CHILD_THEME_VERSION );
    wp_enqueue_style( 'sg-sticky-footer-bar-style', $css_path . 'sticky-footer-bar.css', [ 'child-style' ], CHILD_THEME_VERSION );
    wp_enqueue_style( 'sg-product-filters-style', $css_path . 'product-filters.css', [ 'child-style' ], CHILD_THEME_VERSION );

    if ( is_front_page() ) {
        wp_enqueue_style( 'sg-homepage-page-style', $pages_css_path . 'home.css', [ 'child-style' ], CHILD_THEME_VERSION );
    }

    if ( is_page( [ 'terms-and-conditions', 'terms-conditions', 'termsandconditions' ] ) ) {
        wp_enqueue_style( 'sg-terms-conditions-style', $pages_css_path . 'terms-and-conditions.css', [ 'child-style' ], CHILD_THEME_VERSION );
    }

    if ( is_page( [ 'shipping-and-delivery', 'shipping-delivery-policy', 'shippinganddelivery' ] ) ) {
        wp_enqueue_style( 'sg-shipping-delivery-style', $pages_css_path . 'shipping-delivery-policy.css', [ 'child-style' ], CHILD_THEME_VERSION );
    }

    if ( is_page( [ 'return-and-refund', 'return-refund-policy', 'returnandrefund' ] ) ) {
        wp_enqueue_style( 'sg-return-refund-style', $pages_css_path . 'return-refund-policy.css', [ 'child-style' ], CHILD_THEME_VERSION );
    }

    if ( is_page( [ 'privacy-policy', 'privacypolicy' ] ) ) {
        wp_enqueue_style( 'sg-privacy-policy-style', $pages_css_path . 'privacy-policy.css', [ 'child-style' ], CHILD_THEME_VERSION );
    }

    if ( is_page( [ 'faq', 'faqs' ] ) ) {
        wp_enqueue_style( 'sg-faq-style', $pages_css_path . 'faq.css', [ 'child-style' ], CHILD_THEME_VERSION );
    }

    if ( is_page( [ 'contact-us', 'contactus' ] ) ) {
        wp_enqueue_style( 'sg-contact-us-style', $pages_css_path . 'contact-us.css', [ 'child-style' ], CHILD_THEME_VERSION );
    }

    if ( is_page( [ 'about-us', 'aboutus' ] ) ) {
        wp_enqueue_style( 'sg-about-us-style', $pages_css_path . 'about-us.css', [ 'child-style' ], CHILD_THEME_VERSION );
    }

    if ( is_shop() ) {
        wp_enqueue_style( 'sg-shop-style', $pages_css_path . 'shop.css', [ 'child-style' ], CHILD_THEME_VERSION );
    }

	// --- Enqueue Scripts ---
	wp_enqueue_script( 'sg-homepage-scripts', $js_path . 'homepage-scripts.js', [], CHILD_THEME_VERSION, true );
    wp_enqueue_script( 'sg-whatsapp-button-script', $js_path . 'whatsapp-button.js', [], CHILD_THEME_VERSION, true );
    
    // Product Filter scripts (with jQuery UI dependency)
    wp_enqueue_script('jquery-ui-slider');
    wp_enqueue_script( 'sg-product-filters-script', $js_path . 'product-filters.js', ['jquery', 'jquery-ui-slider'], CHILD_THEME_VERSION, true );

    // --- Localize Scripts (Pass PHP data to JS) ---
    wp_localize_script( 'sg-homepage-scripts', 'sg_ajax_obj', [ 'ajax_url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'add_new_subscriber_nonce' ) ] );

    $filter_params = [ 'ajax_url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'sg_filter_nonce' ), 'currency_symbol' => get_woocommerce_currency_symbol(), 'archive_slug' => '', 'is_cat' => false ];
    if ( is_product_category() ) { $filter_params['archive_slug'] = get_queried_object()->slug; $filter_params['is_cat'] = true; } elseif ( is_product_tag() ) { $filter_params['archive_slug'] = get_queried_object()->slug; }
    wp_localize_script( 'sg-product-filters-script', 'sg_filter_params', $filter_params );

    // --- Conditionally Load Video Assets for Performance ---
    global $post;
    if ( is_singular() && is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'product_video' ) ) {
        wp_enqueue_style( 'sg-video-facade-style', $css_path . 'video-facade.css', [], CHILD_THEME_VERSION );
        wp_enqueue_script( 'sg-video-loader-script', $js_path . 'video-loader.js', [], CHILD_THEME_VERSION, true );
    }
}


// =============================================================================
// 3. THEME HOOKS & MODIFICATIONS
// =============================================================================

/**
 * Displays the Trustindex shortcode at the bottom of standard pages/archives
 * and integrates it into the WooCommerce reviews tab on product pages.
 */
add_action( 'astra_content_after', 'sg_display_google_reviews_widget' );
function sg_display_google_reviews_widget() {
    if ( is_admin() ) { return; }
    // This runs on pages and archives, but NOT on single product pages.
    if ( is_page() || is_shop() || is_product_category() || is_product_tag() ) {
        echo '<div class="sg-google-reviews-container" style="max-width: 1200px; margin: 2rem auto; padding: 0 20px;">';
        echo '<h3>Our Customer Reviews</h3>';
        echo do_shortcode('[trustindex no-registration=google]');
        echo '</div>';
    }
}

add_action( 'woocommerce_reviews_before_comments', 'sg_display_google_reviews_in_wc_tab' );
function sg_display_google_reviews_in_wc_tab() {
    // This hook only runs inside the product page reviews tab.
    echo '<div class="sg-google-reviews-wc-tab">';
    echo '<h3 class="reviews-title">Our Google Reviews</h3>';
    echo do_shortcode('[trustindex no-registration=google]');
    echo '<hr class="sg-reviews-divider" style="margin: 2em 0;">';
    echo '</div>';
}
