<?php
/**
 * Sarfaraz Gems - Custom YouTube Video Shortcode with SEO Schema
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! shortcode_exists( 'product_video' ) ) {
    add_shortcode( 'product_video', 'sg_product_video_shortcode' );
}

function sg_product_video_shortcode( $atts ) {
    global $post, $product;

    $atts = shortcode_atts( array( 'id' => '' ), $atts, 'product_video' );
    $video_id = esc_attr( $atts['id'] );

    if ( empty( $video_id ) ) {
        return '';
    }

    // --- Dynamically generate SEO details based on context ---
    $video_name = get_the_title();
    $video_description = has_excerpt() ? get_the_excerpt() : 'A video presentation for ' . get_the_title();
    $upload_date = get_the_modified_date( 'c' );

    // If on a product page, use product details for better SEO
    if ( is_product() && is_a( $product, 'WC_Product' ) ) {
        $video_name = 'Product Video for: ' . $product->get_name();
        $video_description = wp_strip_all_tags( $product->get_short_description() ) ?: 'A video showcasing ' . $product->get_name();
        $upload_date = get_the_modified_date( 'c', $product->get_id() );
    }

    $embed_url = 'https://www.youtube.com/embed/' . $video_id;
    $thumbnail_url = 'https://i.ytimg.com/vi/' . $video_id . '/hqdefault.jpg';

    // Build the VideoObject Schema
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'VideoObject',
        'name' => esc_html( $video_name ),
        'description' => esc_html( $video_description ),
        'thumbnailUrl' => esc_url( $thumbnail_url ),
        'uploadDate' => esc_attr( $upload_date ),
        'embedUrl' => esc_url( $embed_url ),
    ];

    $unique_facade_id = 'sg-youtube-facade-' . uniqid();

    ob_start();
    ?>
    <script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ); ?></script>

    <figure class="sg-video-wrapper" data-video-id="<?php echo esc_attr( $video_id ); ?>" id="<?php echo esc_attr( $unique_facade_id ); ?>" role="button" tabindex="0" aria-label="Play Video: <?php echo esc_attr( $video_name ); ?>">
        <div class="sg-video-facade">
             <div class="sg-play-button" aria-hidden="true">
                <svg height="100%" viewBox="0 0 68 48" width="100%"><path class="sg-play-button-fill" d="M66.52 7.74c-.78-2.93-2.49-5.41-5.42-6.19C55.79.13 34 0 34 0S12.21.13 6.9 1.55C3.97 2.33 2.27 4.81 1.48 7.74.06 13.05 0 24 0 24s.06 10.95 1.48 16.26c.78 2.93 2.49 5.41 5.42 6.19C12.21 47.87 34 48 34 48s21.79-.13 27.1-1.55c2.93-.78 4.64-3.26 5.42-6.19C67.94 34.95 68 24 68 24s-.06-10.95-1.48-16.26z"></path><path d="M 45 24 27 14 27 34" fill="#fff"></path></svg>
             </div>
        </div>
        <figcaption class="sg-visually-hidden"><?php echo esc_html( $video_name ); ?></figcaption>
    </figure>
    <?php
    return ob_get_clean();
}