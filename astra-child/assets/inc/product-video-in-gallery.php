<?php
/**
 * Adds a shortcode-powered video slide to the WooCommerce product gallery.
 *
 * Mirrors the implementation used in the reference child theme so that
 * product videos (such as YouTube embeds) appear as the first gallery item
 * with a matching thumbnail.
 *
 * @package Ratna Gems
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'body_class', 'rg_video_product_body_class' );
/**
 * Adds a body class so product gallery styles can adapt when a video exists.
 *
 * @param array $classes Existing body classes.
 * @return array
 */
function rg_video_product_body_class( array $classes ): array {
    if ( is_product() ) {
        global $product;

        if ( $product instanceof WC_Product && $product->get_meta( 'rg_product_video_shortcode' ) ) {
            $classes[] = 'has-rg-product-video';
        }
    }

    return $classes;
}

// --- 1. ADMIN UI FIELD ----------------------------------------------------

add_action( 'woocommerce_product_options_general_product_data', 'rg_video_admin_field' );
/**
 * Adds a textarea field that accepts a shortcode for the product video.
 */
function rg_video_admin_field(): void {
    woocommerce_wp_textarea_input(
        array(
            'id'          => 'rg_product_video_shortcode',
            'label'       => __( 'Product Video Shortcode', 'ratna-gems' ),
            'placeholder' => __( '[your_video_shortcode]', 'ratna-gems' ),
            'desc_tip'    => true,
            'description' => __( 'Paste the video shortcode to display as the first item in the product gallery.', 'ratna-gems' ),
            'rows'        => 2,
        )
    );
}

add_action( 'woocommerce_admin_process_product_object', 'rg_video_save_field' );
/**
 * Persists the product video shortcode when the product is saved.
 *
 * @param WC_Product $product WooCommerce product being saved.
 */
function rg_video_save_field( WC_Product $product ): void {
    if ( isset( $_POST['rg_product_video_shortcode'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $shortcode = sanitize_textarea_field( wp_unslash( $_POST['rg_product_video_shortcode'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( ! empty( $shortcode ) ) {
            $product->update_meta_data( 'rg_product_video_shortcode', $shortcode );
        } else {
            $product->delete_meta_data( 'rg_product_video_shortcode' );
        }
    }
}

// --- 2. CORE HELPER -------------------------------------------------------

/**
 * Extracts a thumbnail URL from rendered shortcode HTML using fallback methods.
 *
 * @param string $html Rendered shortcode HTML.
 * @return string Thumbnail URL if found, otherwise empty string.
 */
function rg_video_extract_thumbnail_url( string $html ): string {
    // Priority 0: Parse any JSON-LD block for structured thumbnail data.
    if ( preg_match( '#<script[^>]+type=[\"\']application/ld\+json[\"\'][^>]*>(.*?)</script>#is', $html, $script_match ) ) {
        $decoded = json_decode( html_entity_decode( $script_match[1] ), true );

        if ( is_array( $decoded ) && ! empty( $decoded['thumbnailUrl'] ) ) {
            if ( is_array( $decoded['thumbnailUrl'] ) ) {
                $first_thumb = reset( $decoded['thumbnailUrl'] );
                if ( $first_thumb ) {
                    return esc_url_raw( $first_thumb );
                }
            } elseif ( is_string( $decoded['thumbnailUrl'] ) ) {
                return esc_url_raw( $decoded['thumbnailUrl'] );
            }
        }
    }

    // Priority 0.5: Handle inline JSON that stores thumbnailUrl as an array.
    if ( preg_match( '/"thumbnailUrl"\s*:\s*\[\s*"([^"]+)"/i', $html, $matches ) ) {
        return esc_url_raw( $matches[1] );
    }

    // Priority 1: Check for a "thumbnailUrl" inside JSON-LD metadata.
    if ( preg_match( '/"thumbnailUrl"\s*:\s*"([^"]+)"/i', $html, $matches ) ) {
        return esc_url_raw( $matches[1] );
    }

    // Priority 2: Look for a standard <img> tag.
    if ( preg_match( '/<img[^>]+src=[\'\"]([^\'\"]+)[\'\"]/i', $html, $matches ) ) {
        return esc_url_raw( $matches[1] );
    }

    // Priority 3: Derive a thumbnail from any YouTube URL.
    $youtube_pattern = '/(?:https?:\/\/)?(?:www\.)?(?:m\.)?(?:youtube(?:-nocookie)?\.com|youtu\.be)\/(?:watch\?v=|embed\/|v\/|)([\w-]{11})/i';
    if ( preg_match( $youtube_pattern, $html, $matches ) && ! empty( $matches[1] ) ) {
        return 'https://i.ytimg.com/vi/' . $matches[1] . '/hqdefault.jpg';
    }

    return '';
}

/**
 * Returns allowed HTML tags for video gallery output.
 * 
 * FIX: wp_kses_post() strips SVG elements which removes the play button.
 * This function extends allowed tags to include SVG for proper rendering.
 *
 * @return array Allowed HTML tags and attributes.
 */
function rg_video_get_allowed_html(): array {
    $allowed = wp_kses_allowed_html( 'post' );

    // Add SVG support for play button
    $allowed['svg'] = array(
        'class'        => true,
        'id'           => true,
        'width'        => true,
        'height'       => true,
        'viewbox'      => true,
        'fill'         => true,
        'xmlns'        => true,
        'aria-hidden'  => true,
        'focusable'    => true,
        'role'         => true,
        'style'        => true,
    );

    $allowed['path'] = array(
        'd'            => true,
        'fill'         => true,
        'stroke'       => true,
        'stroke-width' => true,
        'class'        => true,
        'id'           => true,
        'style'        => true,
    );

    $allowed['g'] = array(
        'fill'         => true,
        'stroke'       => true,
        'class'        => true,
        'id'           => true,
        'transform'    => true,
    );

    $allowed['circle'] = array(
        'cx'           => true,
        'cy'           => true,
        'r'            => true,
        'fill'         => true,
        'stroke'       => true,
        'class'        => true,
    );

    $allowed['rect'] = array(
        'x'            => true,
        'y'            => true,
        'width'        => true,
        'height'       => true,
        'fill'         => true,
        'stroke'       => true,
        'class'        => true,
        'rx'           => true,
        'ry'           => true,
    );

    // Add figure and figcaption
    $allowed['figure'] = array(
        'class'        => true,
        'id'           => true,
        'data-video-id'=> true,
        'data-start'   => true,
        'role'         => true,
        'tabindex'     => true,
        'aria-label'   => true,
        'style'        => true,
    );

    $allowed['figcaption'] = array(
        'class'        => true,
        'id'           => true,
    );

    // Extend div attributes for video wrapper
    if ( isset( $allowed['div'] ) ) {
        $allowed['div']['data-video-id']   = true;
        $allowed['div']['data-start']      = true;
        $allowed['div']['data-thumb']      = true;
        $allowed['div']['data-thumb-alt']  = true;
        $allowed['div']['data-slide-number'] = true;
        $allowed['div']['role']            = true;
        $allowed['div']['tabindex']        = true;
        $allowed['div']['aria-label']      = true;
        $allowed['div']['aria-hidden']     = true;
    }

    // Extend img attributes
    if ( isset( $allowed['img'] ) ) {
        $allowed['img']['data-no-lazy']    = true;
        $allowed['img']['data-skip-lazy']  = true;
        $allowed['img']['fetchpriority']   = true;
        $allowed['img']['decoding']        = true;
        $allowed['img']['draggable']       = true;
    }

    return $allowed;
}

// --- 3. WOOCOMMERCE GALLERY INTEGRATION ----------------------------------

/**
 * Step 1: Start the output buffer right before the gallery is printed.
 *
 * WooCommerce prints the gallery at priority 20; we hook in immediately before
 * to capture it.
 */
add_action( 'woocommerce_before_single_product_summary', 'rg_video_buffer_start', 19 );
function rg_video_buffer_start(): void {
    global $product;

    if ( is_product() && $product && $product->get_meta( 'rg_product_video_shortcode' ) ) {
        ob_start();
    }
}

/**
 * Step 2: End the buffer, inject the video slide, and output the gallery.
 */
add_action( 'woocommerce_before_single_product_summary', 'rg_video_buffer_end', 21 );
function rg_video_buffer_end(): void {
    global $product;

    // Ensure the buffer was started and the product has a shortcode.
    if ( ! is_product() || ! $product || ! $product->get_meta( 'rg_product_video_shortcode' ) || 0 === ob_get_level() ) {
        return;
    }

    $gallery_html = ob_get_clean();
    $shortcode    = $product->get_meta( 'rg_product_video_shortcode' );

    // Flag the gallery markup so CSS can adapt its aspect ratio for video.
    $gallery_html = preg_replace(
        '/class="woocommerce-product-gallery/',
        'class="woocommerce-product-gallery has-rg-product-video',
        $gallery_html,
        1
    );
    
    /**
     * Force the shortcode poster image to bypass lazy-loading so the gallery
     * thumbnail and main facade appear immediately when the page renders.
     */
    $force_poster = static function ( $attrs ) {
        $attrs['loading']       = 'eager';
        $attrs['fetchpriority'] = 'high';
        $attrs['data-no-lazy']  = '1';
        $attrs['data-skip-lazy']= '1'; // LiteSpeed compatible

        if ( isset( $attrs['data-rg-hires'] ) ) {
            unset( $attrs['data-rg-hires'] );
        }

        if ( isset( $attrs['data-rg-final-sizes'] ) ) {
            unset( $attrs['data-rg-final-sizes'] );
        }

        if ( empty( $attrs['sizes'] ) ) {
            $attrs['sizes'] = '(max-width: 768px) 100vw, 700px';
        }

        return $attrs;
    };

    add_filter( 'rg_scv_poster_attributes', $force_poster, 20 );

    $force_lcp = static function ( $out, $pairs, $atts, $shortcode_name ) {
        if ( 'product_video' !== $shortcode_name ) {
            return $out;
        }

        // Force the shortcode into "LCP" mode so the poster renders eagerly.
        if ( empty( $out['lcp'] ) || '0' === (string) $out['lcp'] ) {
            $out['lcp'] = '1';
        }

        return $out;
    };

    add_filter( 'shortcode_atts_product_video', $force_lcp, 10, 4 );

    $rendered = do_shortcode( $shortcode );

    // Capture any VideoObject schema emitted by the shortcode so we can keep
    // it on the page (outside of the gallery markup) for Google's video
    // indexing. Without it, Search Console reports "No thumbnail URL
    // provided" because the JSON-LD gets stripped from the injected slide.
    $schema_blocks = [];
    if ( preg_match_all( '#<script[^>]+type=[\"\']application/ld\+json[\"\'][^>]*>(.*?)</script>#is', $rendered, $matches ) ) {
        $schema_blocks = array_map( 'trim', $matches[1] );
    }

    remove_filter( 'shortcode_atts_product_video', $force_lcp, 10 );
    remove_filter( 'rg_scv_poster_attributes', $force_poster, 20 );

    if ( ! trim( $rendered ) ) {
        // FIX: Use wp_kses with extended allowed HTML instead of wp_kses_post
        echo wp_kses( $gallery_html, rg_video_get_allowed_html() );
        return;
    }

    $thumb_url = rg_video_extract_thumbnail_url( $rendered );
    if ( empty( $thumb_url ) ) {
        // FIX: Use wp_kses with extended allowed HTML instead of wp_kses_post
        echo wp_kses( $gallery_html, rg_video_get_allowed_html() );
        return;
    }

    $cleaned_shortcode = preg_replace( "#<script[^>]+type=[\"\']application/ld\+json[\"\'][^>]*>.*?</script>#is", '', $rendered );

    $thumb_alt = __( 'Product Video Thumbnail', 'ratna-gems' );
    if ( $product instanceof WC_Product ) {
        $product_name = $product->get_name();
        if ( $product_name ) {
            $thumb_alt = sprintf( __( 'Product Video for: %s', 'ratna-gems' ), $product_name );
        }
    }

    // Create the main video slide for the large gallery area.
    $main_video_slide = sprintf(
        '<div class="woocommerce-product-gallery__image rg-shortcode-slide" data-thumb="%s">%s</div>',
        esc_url( $thumb_url ),
        trim( $cleaned_shortcode )
    );

    // Create the thumbnail slide that matches the Astra gallery markup.
    // Explicitly add data-skip-lazy="1" for LiteSpeed.
    // FIX: Added rg-video-thumb-img class for proper CSS targeting
    $thumbnail_slide = sprintf(
        '<div data-slide-number="0" data-thumb="%1$s" data-thumb-alt="%2$s" class="ast-woocommerce-product-gallery__image rg-video-thumbnail"><img class="rg-video-thumb-img" src="%1$s" alt="%2$s" loading="eager" decoding="async" data-no-lazy="1" data-skip-lazy="1" fetchpriority="high" width="160" height="90" draggable="false" /></div>',
        esc_url( $thumb_url ),
        esc_attr( $thumb_alt )
    );

    // Inject the main video slide.
    $main_wrapper_tag = '<figure class="woocommerce-product-gallery__wrapper">';
    if ( false !== strpos( $gallery_html, $main_wrapper_tag ) ) {
        $gallery_html = str_replace( $main_wrapper_tag, $main_wrapper_tag . $main_video_slide, $gallery_html );
    }

    // Inject the thumbnail slide.
    $thumb_wrapper_tag = '<div class="woocommerce-product-gallery-thumbnails__wrapper">';
    if ( false !== strpos( $gallery_html, $thumb_wrapper_tag ) ) {
        $gallery_html = str_replace( $thumb_wrapper_tag, $thumb_wrapper_tag . $thumbnail_slide, $gallery_html );
    }

    if ( $schema_blocks ) {
        foreach ( $schema_blocks as $schema_json ) {
            $decoded = json_decode( $schema_json, true );

            if ( is_array( $decoded ) ) {
                if ( empty( $decoded['thumbnailUrl'] ) && $thumb_url ) {
                    $decoded['thumbnailUrl'] = $thumb_url;
                }

                $schema_json = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            }

            echo wp_print_inline_script_tag( $schema_json, [ 'type' => 'application/ld+json' ] );
        }
    }

    // FIX: Use wp_kses with extended allowed HTML to preserve SVG play button
    // wp_kses_post() strips SVG elements which removes the play button!
    echo wp_kses( $gallery_html, rg_video_get_allowed_html() );
}
