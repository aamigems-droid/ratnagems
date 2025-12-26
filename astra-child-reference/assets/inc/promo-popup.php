<?php
/**
 * Ratna Gems (Child Theme) — Promo Popup
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action(
    'wp_enqueue_scripts',
    function () {
        if ( is_admin() ) {
            return;
        }

        $base_dir = get_stylesheet_directory();
        $base_uri = get_stylesheet_directory_uri();

        $css_rel  = '/assets/css/promo-popup.css';
        $css_uri  = $base_uri . $css_rel;
        $css_path = $base_dir . $css_rel;
        $css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : (string) time();

        wp_enqueue_style( 'rg-promo-popup-css', $css_uri, array(), $css_ver );

        $js_handle = 'rg-promo-popup-js';
        $js_rel    = '/assets/js/promo-popup.js';
        $js_uri    = $base_uri . $js_rel;
        $js_path   = $base_dir . $js_rel;
        $js_ver    = file_exists( $js_path ) ? (string) filemtime( $js_path ) : (string) time();

        wp_register_script( $js_handle, $js_uri, array(), $js_ver, true );

        $icon_url = '';
        if ( function_exists( 'get_site_icon_url' ) ) {
            $icon_candidate = get_site_icon_url( 256 );
            if ( $icon_candidate ) {
                $icon_url = $icon_candidate;
            }
        }
        if ( '' === $icon_url ) {
            $custom_logo_id = (int) get_theme_mod( 'custom_logo' );
            if ( $custom_logo_id > 0 ) {
                $logo = wp_get_attachment_image_url( $custom_logo_id, 'full' );
                if ( $logo ) {
                    $icon_url = $logo;
                }
            }
        }

        $config = array(
            'coupon'      => 'SPECIAL10',
            'delayMs'     => 36000,
            'snoozeHours' => 24,
            'iconUrl'     => $icon_url ? esc_url_raw( $icon_url ) : '',
            'highlights'  => array(
                esc_html__( 'ISO-certified lab reports', 'ratna-gems' ),
                esc_html__( 'Lifetime Authenticity Guarantee', 'ratna-gems' ),
                esc_html__( '7-Day Return Policy', 'ratna-gems' ),
                esc_html__( 'Original Rudraksha', 'ratna-gems' ),
                esc_html__( 'Since 1995', 'ratna-gems' ),
            ),
            'i18n'        => array(
                'badge'         => esc_html__( 'Limited Time Offer', 'ratna-gems' ),
                'heading'       => esc_html__( 'Save 10% Today', 'ratna-gems' ),
                'subheading'    => esc_html__( 'On Lab-Certified Gemstones & Original Rudraksha', 'ratna-gems' ),
                'coupon_notice' => esc_html__( 'Get 10% OFF on all Gemstones & Rudraksha. Use code %s.', 'ratna-gems' ),
                'coupon_label'  => esc_html__( 'Copy %s coupon code', 'ratna-gems' ),
                'coupon_hint'   => esc_html__( 'Use code', 'ratna-gems' ),
                'coupon_helper' => esc_html__( 'Tap to copy and apply at checkout', 'ratna-gems' ),
                'copy'          => esc_html__( 'Copy Code', 'ratna-gems' ),
                'copied'        => esc_html__( 'Copied! ✓', 'ratna-gems' ),
                'copy_success'  => esc_html__( 'Coupon code copied: %s', 'ratna-gems' ),
                'copy_fail'     => esc_html__( 'Failed to copy code. Please try again.', 'ratna-gems' ),
                'close'         => esc_html__( 'Close promotion', 'ratna-gems' ),
            ),
        );

        wp_add_inline_script( $js_handle, 'window.ratnaGemsPromoPopup = ' . wp_json_encode( $config ) . ';', 'before' );
        wp_enqueue_script( $js_handle );
    },
    20
);