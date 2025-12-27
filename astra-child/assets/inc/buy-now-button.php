<?php
/**
 * Buy Now button helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_enqueue_scripts', 'sg_enqueue_buy_now_button_styles', 20 );
function sg_enqueue_buy_now_button_styles(): void {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) {
        return;
    }

    $css_path = get_stylesheet_directory() . '/assets/css/buy-now-button.css';
    if ( ! file_exists( $css_path ) ) {
        return;
    }

    wp_enqueue_style(
        'sg-buy-now-button',
        get_stylesheet_directory_uri() . '/assets/css/buy-now-button.css',
        array( 'child-style' ),
        CHILD_THEME_VERSION
    );
}

add_action( 'wp_loaded', 'sg_handle_buy_now_request' );
function sg_handle_buy_now_request(): void {
    if ( is_admin() || ! function_exists( 'WC' ) ) {
        return;
    }

    $raw_buy_now = isset( $_GET['buy_now'] ) ? wp_unslash( $_GET['buy_now'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( '' === $raw_buy_now ) {
        return;
    }

    $product_id = absint( $raw_buy_now );
    if ( $product_id <= 0 ) {
        return;
    }

    $nonce             = isset( $_GET['_bnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_bnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $product           = wc_get_product( $product_id );
    $redirect_fallback = $product ? get_permalink( $product_id ) : wc_get_cart_url();

    if ( ! $nonce || ! wp_verify_nonce( $nonce, 'ratna-gems-buy-now-' . $product_id ) ) {
        wc_add_notice( esc_html__( 'Your session expired. Please try again.', 'ratna-gems' ), 'error' );
        $redirect_url = $redirect_fallback ? $redirect_fallback : home_url();
        wp_safe_redirect( esc_url_raw( $redirect_url ) );
        exit;
    }

    if ( ! class_exists( 'WC_Product' ) || ! $product instanceof WC_Product || ! $product->is_purchasable() ) {
        wc_add_notice( esc_html__( 'This product is currently unavailable.', 'ratna-gems' ), 'error' );
        $redirect_url = $redirect_fallback ? $redirect_fallback : wc_get_cart_url();
        wp_safe_redirect( esc_url_raw( $redirect_url ) );
        exit;
    }

    $quantity = isset( $_GET['quantity'] ) ? absint( wp_unslash( $_GET['quantity'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $quantity = max( 1, min( $quantity, 10 ) );

    $cart = WC()->cart;
    if ( ! $cart ) {
        wc_add_notice( esc_html__( 'Your cart is unavailable. Please try again.', 'ratna-gems' ), 'error' );
        $redirect_url = $redirect_fallback ? $redirect_fallback : home_url();
        wp_safe_redirect( esc_url_raw( $redirect_url ) );
        exit;
    }

    $existing_key = null;

    foreach ( $cart->get_cart() as $key => $item ) {
        $product_matches   = (int) $item['product_id'] === $product_id;
        $variation_matches = isset( $item['variation_id'] ) && (int) $item['variation_id'] === $product_id;

        if ( $product_matches || $variation_matches ) {
            $existing_key = $key;
            break;
        }
    }

    if ( $existing_key ) {
        $cart_item_key = $existing_key;
        $new_quantity  = $product->is_sold_individually() ? 1 : $quantity;
        $cart->set_quantity( $cart_item_key, $new_quantity );
    } else {
        $cart_item_key = $cart->add_to_cart( $product_id, $quantity );
        if ( ! $cart_item_key ) {
            wc_add_notice( esc_html__( 'We could not add this product to your cart. Please try again.', 'ratna-gems' ), 'error' );
            $redirect_url = $redirect_fallback ? $redirect_fallback : wc_get_cart_url();
            wp_safe_redirect( esc_url_raw( $redirect_url ) );
            exit;
        }
    }

    foreach ( $cart->get_cart() as $key => $item ) {
        if ( $key !== $cart_item_key ) {
            $cart->remove_cart_item( $key );
        }
    }

    $cart->calculate_totals();

    wp_safe_redirect( esc_url_raw( wc_get_checkout_url() ) );
    exit;
}

add_shortcode( 'sg_buy_now_button', 'sg_buy_now_shortcode' );
function sg_buy_now_shortcode(): string {
    global $product;

    if ( ! class_exists( 'WC_Product' ) || ! $product instanceof WC_Product ) {
        return '';
    }

    $url = add_query_arg(
        array(
            'buy_now' => $product->get_id(),
            'quantity' => 1,
            '_bnonce' => wp_create_nonce( 'ratna-gems-buy-now-' . $product->get_id() ),
        ),
        wc_get_checkout_url()
    );

    $label = esc_html__( 'Buy Now', 'ratna-gems' );

    return sprintf(
        '<a class="buy-now-button button alt" href="%1$s">%2$s</a>',
        esc_url( $url ),
        esc_html( $label )
    );
}

add_action( 'woocommerce_after_add_to_cart_button', 'sg_render_buy_now_button', 15 );
function sg_render_buy_now_button(): void {
    global $product;

    if ( ! class_exists( 'WC_Product' ) || ! $product instanceof WC_Product ) {
        return;
    }

    $should_render = apply_filters( 'sg_show_buy_now_button', true, $product );
    if ( ! $should_render ) {
        return;
    }

    echo do_shortcode( '[sg_buy_now_button]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
