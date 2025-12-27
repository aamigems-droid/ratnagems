<?php
/**
 * Ratna Gems – Product Video Shortcode
 * Files: shortcode-video.php (this), shortcode-video.js, shortcode-video.css
 * Shortcode: [product_video id="YOUTUBE_ID" start="0" lcp="0|1"]
 *
 * - AMP-safe: outputs <amp-youtube> on AMP endpoints
 * - Privacy: youtube-nocookie.com
 * - Perf: facade + conditional enqueue + resource hints
 * - SEO: VideoObject schema (includes Multi-Thumbnail fallback for GSC)
 * - LCP: Strict exclusion for LiteSpeed Cache via data-skip-lazy
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'RG_SCV_HANDLE', 'rg-shortcode-video' );

// --------------------------------------------------
// Conditional enqueue (perf) + resource hints flags
// --------------------------------------------------
add_action( 'wp_enqueue_scripts', 'rg_scv_maybe_enqueue_assets' );
function rg_scv_maybe_enqueue_assets() {
	global $post, $rg_scv_active;
	if ( is_archive() || ! is_singular() || ! $post instanceof WP_Post ) { return; }
	if ( has_shortcode( $post->post_content, 'product_video' ) ) {
		$rg_scv_active = true;
		rg_scv_enqueue_assets();
	}
}

/**
 * Enqueue CSS/JS (also callable from the shortcode as a safety net)
 */
function rg_scv_enqueue_assets() {
	$base = get_stylesheet_directory_uri();
	$ver  = defined( 'CHILD_THEME_VERSION' )
		? CHILD_THEME_VERSION
		: ( defined( 'CHILD_THEME_RATNA_GEMS_VERSION' ) ? CHILD_THEME_RATNA_GEMS_VERSION : '1.2.0' );

	// CSS
	wp_enqueue_style(
		RG_SCV_HANDLE . '-css',
		$base . '/assets/css/shortcode-video.css',
		[],
		$ver
	);

    // JS (no jQuery)
    wp_enqueue_script(
            RG_SCV_HANDLE . '-js',
            $base . '/assets/js/shortcode-video.js',
            [],
            $ver,
            true
    );

    $localized = [
            'strings' => [
                    'iframeTitle' => esc_html__( 'Product Video', 'ratna-gems' ),
            ],
    ];

    wp_add_inline_script(
            RG_SCV_HANDLE . '-js',
            'window.rgShortcodeVideoConfig = Object.assign({}, window.rgShortcodeVideoConfig || {}, ' .
            wp_json_encode( $localized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ');',
            'before'
    );
}

/**
 * Resource hints (preconnect / dns-prefetch) only when shortcode is present
 */
add_filter( 'wp_resource_hints', function( $urls, $relation ) {
	global $rg_scv_active;
	if ( empty( $rg_scv_active ) ) { return $urls; }

	if ( 'preconnect' === $relation ) {
		$urls[] = [ 'href' => 'https://www.youtube-nocookie.com', 'crossorigin' => 'anonymous' ];
		$urls[] = [ 'href' => 'https://i.ytimg.com',             'crossorigin' => 'anonymous' ];
	}
	if ( 'dns-prefetch' === $relation ) {
		$urls[] = 'https://www.youtube-nocookie.com';
		$urls[] = 'https://i.ytimg.com';
	}
	return $urls;
}, 10, 2 );

/**
 * Returns the best-available publisher logo URL for schema output.
 */
function rg_scv_get_publisher_logo_url(): string {
	$logo_id = (int) get_theme_mod( 'custom_logo' );
	if ( $logo_id ) {
		$logo = wp_get_attachment_image_url( $logo_id, 'full' );
		if ( $logo ) {
			return $logo;
		}
	}

	$site_icon = get_site_icon_url( 512 );
	return $site_icon ? (string) $site_icon : '';
}

/**
 * Returns context-aware defaults for titles, descriptions, and page URL.
 */
function rg_scv_get_video_context(): array {
	$title    = sanitize_text_field( get_the_title() );
	$desc     = has_excerpt()
		? wp_strip_all_tags( get_the_excerpt() )
		: sprintf( __( 'A video presentation for %s', 'ratna-gems' ), $title );
	$datec    = get_the_modified_date( 'c' );
	$page_url = get_permalink();

	if ( function_exists( 'is_product' ) && is_product() ) {
		global $product;
		if ( $product instanceof WC_Product ) {
			$product_name = sanitize_text_field( $product->get_name() );
			$title        = sprintf( __( 'Product Video for: %s', 'ratna-gems' ), $product_name );
			$desc         = wp_strip_all_tags( $product->get_short_description() );
			if ( empty( $desc ) ) {
				$desc = sprintf( __( 'A video showcasing %s', 'ratna-gems' ), $product_name );
			}
			$datec    = get_the_modified_date( 'c', $product->get_id() );
			$page_url = get_permalink( $product->get_id() );
		}
	}

	$publisher = array(
		'@type' => 'Organization',
		'name'  => get_bloginfo( 'name' ),
	);

	$logo_url = rg_scv_get_publisher_logo_url();
	if ( $logo_url ) {
		$publisher['logo'] = array(
			'@type' => 'ImageObject',
			'url'   => $logo_url,
		);
	}

	return array(
		'title'       => $title,
		'description' => $desc,
		'uploadDate'  => $datec,
		'page_url'    => $page_url ? (string) $page_url : home_url( '/' ),
		'publisher'   => $publisher,
	);
}

/**
 * Builds a VideoObject schema array that satisfies Google Video guidelines.
 *
 * @param array $args Schema pieces.
 */
function rg_scv_build_video_schema( array $args ): array {
	$thumbnails = array_values( array_filter( array_unique( array_map( 'esc_url_raw', $args['thumbnails'] ?? array() ) ) ) );

	$schema = array(
		'@context'        => 'https://schema.org',
		'@type'           => 'VideoObject',
		'@id'             => $args['schema_id'] ?? '',
		'name'            => $args['title'] ?? '',
		'description'     => $args['description'] ?? '',
		'thumbnailUrl'    => $thumbnails,
		'uploadDate'      => $args['uploadDate'] ?? '',
		'embedUrl'        => $args['embedUrl'] ?? '',
		'contentUrl'      => $args['contentUrl'] ?? '',
		'url'             => $args['pageUrl'] ?? '',
		'mainEntityOfPage'=> $args['pageUrl'] ?? '',
		'potentialAction' => array(
			'@type'  => 'WatchAction',
			'target' => $args['contentUrl'] ?? '',
		),
	);

	if ( ! empty( $args['publisher'] ) ) {
		$schema['publisher'] = $args['publisher'];
	}

	return $schema;
}

/**
 * Extracts a sanitized product_video shortcode payload.
 */
function rg_scv_parse_video_shortcode( string $shortcode ): array {
	if ( ! preg_match( '/\[product_video([^\]]*)\]/i', $shortcode, $match ) ) {
		return array();
	}

	$atts = shortcode_parse_atts( $match[1] ?? '' );
	$id   = isset( $atts['id'] ) ? preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $atts['id'] ) : '';
	$start = isset( $atts['start'] ) ? max( 0, intval( $atts['start'] ) ) : 0;

	return array(
		'id'    => $id,
		'start' => $start,
	);
}

// --------------------------------------------------
// Shortcode
// --------------------------------------------------
add_shortcode( 'product_video', 'rg_shortcode_product_video' );

function rg_shortcode_product_video( $atts ) {
    // Safety: make sure assets are enqueued even if detection missed
    rg_scv_enqueue_assets();
    global $rg_scv_active; $rg_scv_active = true;

    $atts  = shortcode_atts( [
		'id'    => '',
		'start' => '',
		'lcp'   => '',
    ], $atts, 'product_video' );

    // Sanitize
    $video_id = preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $atts['id'] );
    $start    = ( $atts['start'] !== '' ) ? max( 0, intval( $atts['start'] ) ) : 0;
    $lcp      = (string) $atts['lcp'] === '1';

    if ( empty( $video_id ) ) {
            return sprintf( '<!-- %s -->', esc_html__( 'product_video: missing id', 'ratna-gems' ) );
    }

    $context = rg_scv_get_video_context();

	$embed_base   = 'https://www.youtube-nocookie.com/embed/' . $video_id;
	$watch_url    = 'https://www.youtube.com/watch?v=' . $video_id . ( $start ? '&t=' . $start . 's' : '' );
        
    // Define multiple thumbnail sizes for Schema Fallback
    $thumb_small  = 'https://i.ytimg.com/vi/' . $video_id . '/mqdefault.jpg';
    $thumb_hq     = 'https://i.ytimg.com/vi/' . $video_id . '/hqdefault.jpg';
    $thumb_sd     = 'https://i.ytimg.com/vi/' . $video_id . '/sddefault.jpg';
    $thumb_max    = 'https://i.ytimg.com/vi/' . $video_id . '/maxresdefault.jpg';

    $unique_id    = 'rg-youtube-facade-' . wp_generate_uuid4();

	// Schema: FIX for "Thumbnail URL not available" & "Video isn't on a watch page"
	$schema = rg_scv_build_video_schema( array(
		'schema_id'   => trailingslashit( $context['page_url'] ) . '#product-video-' . $video_id,
		'title'       => $context['title'],
		'description' => $context['description'],
		'uploadDate'  => $context['uploadDate'],
		'embedUrl'    => $embed_base,
		'contentUrl'  => $watch_url,
		'pageUrl'     => $context['page_url'],
		'thumbnails'  => array(
			$thumb_max, // Priority 1: HD (1280x720)
			$thumb_sd,  // Priority 2: SD (640x480)
			$thumb_hq,  // Priority 3: HQ (480x360) - Always exists
		),
		'publisher'   => $context['publisher'],
	) );

	// AMP endpoint? Use <amp-youtube>
    if ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) {
            $out  = '';
            if ( apply_filters( 'rg_scv_output_schema', true, $video_id ) ) {
                    $out .= wp_print_inline_script_tag(
                            wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
                            [ 'type' => 'application/ld+json' ]
                    );
            }
            $start_attr = $start ? ' data-param-start="' . esc_attr( $start ) . '"' : '';
            $out       .= '<amp-youtube data-videoid="' . esc_attr( $video_id ) . '"' . $start_attr .
                    ' layout="responsive" width="16" height="9"></amp-youtube>';
            return $out;
    }

    // Non-AMP facade markup
    ob_start();

    if ( apply_filters( 'rg_scv_output_schema', true, $video_id ) ) {
		echo wp_print_inline_script_tag(
				wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				[ 'type' => 'application/ld+json' ]
		);
    }
    ?>
    <figure
            class="rg-video-wrapper"
            id="<?php echo esc_attr( $unique_id ); ?>"
            data-video-id="<?php echo esc_attr( $video_id ); ?>"
            <?php if ( $start ) : ?>data-start="<?php echo esc_attr( $start ); ?>"<?php endif; ?>
            role="button" tabindex="0"
            aria-label="<?php echo esc_attr( sprintf( __( 'Play Video: %s', 'ratna-gems' ), $title ) ); ?>"
    >
        <div class="rg-video-facade" aria-hidden="true"></div>

        <?php
        $should_output_poster = apply_filters( 'rg_scv_output_poster_image', true, $video_id, $lcp );
        if ( $should_output_poster ) :
                $poster_srcset = array_filter(
                        array(
                                esc_url( $thumb_small ) . ' 320w',
                                esc_url( $thumb_hq ) . ' 480w',
                                esc_url( $thumb_sd ) . ' 640w',
                        )
                );

                $poster_sizes_lazy = '(max-width: 768px) 100vw, 560px';
                $poster_sizes_lcp  = '(max-width: 768px) 100vw, 700px';

                $poster_attributes = array(
                        'class'        => 'rg-video-poster',
                        'alt'          => $title,
                        'src'          => $thumb_hq,
                        'srcset'       => implode( ', ', $poster_srcset ),
                        'sizes'        => $lcp ? $poster_sizes_lcp : $poster_sizes_lazy,
                        'decoding'     => 'async',
                        'loading'      => $lcp ? 'eager' : 'lazy',
                        'fetchpriority'=> $lcp ? 'high' : 'auto',
                        'width'        => '1280',
                        'height'       => '720',
                );

                if ( ! $lcp ) {
                        $poster_attributes['data-rg-hires']       = $thumb_max;
                        $poster_attributes['data-rg-final-sizes'] = $poster_sizes_lcp;
                } else {
                        // CRITICAL: LiteSpeed / General Lazy Load Exclusion
                        $poster_attributes['data-no-lazy']   = '1';
                        $poster_attributes['data-skip-lazy'] = '1';
                }

                /**
                 * Allow final modification of the poster attributes.
                 */
                $poster_attributes = apply_filters( 'rg_scv_poster_attributes', $poster_attributes, $video_id, $lcp );

                if ( isset( $poster_attributes['loading'] ) && 'eager' === $poster_attributes['loading'] ) {
                        if ( empty( $poster_attributes['fetchpriority'] ) || 'high' !== $poster_attributes['fetchpriority'] ) {
                                $poster_attributes['fetchpriority'] = 'high';
                        }

                        if ( empty( $poster_attributes['data-no-lazy'] ) ) {
                                $poster_attributes['data-no-lazy']   = '1';
                                $poster_attributes['data-skip-lazy'] = '1';
                        }
                }

                $attributes_html = '';
                foreach ( $poster_attributes as $attr => $value ) {
                        if ( '' === $value ) {
                                continue;
                        }

                        $attributes_html .= sprintf( ' %1$s="%2$s"', esc_attr( $attr ), esc_attr( $value ) );
                }
                ?>
                <img<?php echo $attributes_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
        <?php endif; ?>

    <div class="rg-play-button" aria-hidden="true">
        <svg height="100%" viewBox="0 0 68 48" width="100%" focusable="false" aria-hidden="true">
            <path class="rg-play-button-fill" d="M66.52 7.74c-.78-2.93-2.49-5.41-5.42-6.19C55.79.13 34 0 34 0S12.21.13 6.9 1.55C3.97 2.33 2.27 4.81 1.48 7.74.06 13.05 0 24 0 24s.06 10.95 1.48 16.26c.78 2.93 2.49 5.41 5.42 6.19C12.21 47.87 34 48 34 48s21.79-.13 27.1-1.55c2.93-.78 4.64-3.26 5.42-6.19C67.94 34.95 68 24 68 24s-.06-10.95-1.48-16.26z"></path>
            <path d="M45 24 27 14v20" fill="#fff"></path>
        </svg>
    </div>

    <figcaption class="rg-visually-hidden"><?php echo esc_html( $title ); ?></figcaption>
</figure>
<?php
return ob_get_clean();
}

/**
 * Output standalone VideoObject schema in the document head for video products.
 */
add_action( 'wp_head', 'rg_scv_output_product_video_schema', 9 );
function rg_scv_output_product_video_schema(): void {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$shortcode = $product->get_meta( 'rg_product_video_shortcode' );
	if ( ! $shortcode ) {
		return;
	}

	$parsed = rg_scv_parse_video_shortcode( $shortcode );
	if ( empty( $parsed['id'] ) ) {
		return;
	}

	$video_id  = $parsed['id'];
	$start     = $parsed['start'];
	$context   = rg_scv_get_video_context();
	$watch_url = 'https://www.youtube.com/watch?v=' . $video_id . ( $start ? '&t=' . $start . 's' : '' );
	$embed_url = 'https://www.youtube-nocookie.com/embed/' . $video_id;

	$schema = rg_scv_build_video_schema( array(
		'schema_id'   => trailingslashit( $context['page_url'] ) . '#product-video-' . $video_id,
		'title'       => $context['title'],
		'description' => $context['description'],
		'uploadDate'  => $context['uploadDate'],
		'embedUrl'    => $embed_url,
		'contentUrl'  => $watch_url,
		'pageUrl'     => $context['page_url'],
		'thumbnails'  => array(
			'https://i.ytimg.com/vi/' . $video_id . '/maxresdefault.jpg',
			'https://i.ytimg.com/vi/' . $video_id . '/sddefault.jpg',
			'https://i.ytimg.com/vi/' . $video_id . '/hqdefault.jpg',
		),
		'publisher'   => $context['publisher'],
	) );

	if ( ! apply_filters( 'rg_scv_output_schema', true, $video_id ) ) {
		return;
	}

	echo wp_print_inline_script_tag(
		wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		array( 'type' => 'application/ld+json' )
	);
}
