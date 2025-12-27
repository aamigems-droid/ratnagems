<?php
/**
 * Product gallery layout fixes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_enqueue_scripts', 'sg_enqueue_product_gallery_layout', 20 );
function sg_enqueue_product_gallery_layout(): void {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) {
        return;
    }

    $css_path = get_stylesheet_directory() . '/assets/css/product-gallery.css';
    if ( ! file_exists( $css_path ) ) {
        return;
    }

    wp_enqueue_style(
        'sg-product-gallery-layout',
        get_stylesheet_directory_uri() . '/assets/css/product-gallery.css',
        array( 'child-style' ),
        CHILD_THEME_VERSION
    );
}
