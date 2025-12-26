<?php
/**
 * WooCommerce-specific tweaks.
 *
 * @package Ratna Gems
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'woocommerce_products_general_settings', 'ratna_gems_add_carat_weight_unit' );
/**
 * Add "carat" as a WooCommerce weight unit.
 */
function ratna_gems_add_carat_weight_unit( array $settings ): array {
    foreach ( $settings as &$setting ) {
        if ( isset( $setting['id'] ) && 'woocommerce_weight_unit' === $setting['id'] ) {
            $setting['options']['carat'] = esc_html__( 'carat', 'ratna-gems' );
        }
    }

    return $settings;
}

add_filter( 'woocommerce_ship_to_different_address_checked', '__return_false' );

add_filter( 'woocommerce_quantity_input_args', 'ratna_gems_normalize_quantity_input_id', 10, 2 );
/**
 * Ensure quantity input IDs are valid HTML identifiers.
 */
function ratna_gems_normalize_quantity_input_id( array $args, $product ): array {
    $raw_id = isset( $args['input_id'] ) ? (string) $args['input_id'] : '';

    if ( '' === trim( $raw_id ) ) {
        $raw_id = wp_unique_id( 'rg-qty-' );
    }

    $clean_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', $raw_id );
    if ( '' === $clean_id ) {
        $clean_id = wp_unique_id( 'rg-qty-' );
    }

    $args['input_id'] = $clean_id;

    return $args;
}
