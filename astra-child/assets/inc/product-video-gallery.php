<?php
/**
 * Sarfaraz Gems — WooCommerce Product Gallery YouTube Video
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Get the YouTube video ID for a product.
 */
function sg_get_product_youtube_video_id( $product_id ) {
    $meta_keys = [
        'sg_youtube_video_id',
        '_sg_youtube_video_id',
        'product_video_id',
        '_product_video_id',
        'youtube_video_id',
        '_youtube_video_id',
    ];

    foreach ( $meta_keys as $meta_key ) {
        $value = get_post_meta( $product_id, $meta_key, true );
        if ( ! empty( $value ) ) {
            return sanitize_text_field( $value );
        }
    }

    return '';
}

/**
 * Render the YouTube video inside the WooCommerce product gallery.
 */
function sg_output_product_gallery_video() {
    if ( ! is_product() ) {
        return;
    }

    global $product;
    if ( ! $product instanceof WC_Product ) {
        return;
    }

    $video_id = sg_get_product_youtube_video_id( $product->get_id() );
    if ( empty( $video_id ) ) {
        return;
    }

    echo '<div class="woocommerce-product-gallery__image sg-product-video-gallery">';
    echo do_shortcode( '[product_video id="' . esc_attr( $video_id ) . '"]' );
    echo '</div>';
}
add_action( 'woocommerce_product_thumbnails', 'sg_output_product_gallery_video', 25 );
