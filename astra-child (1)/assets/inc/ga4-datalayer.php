<?php
/**
 * GA4 data layer helpers for Ratna Gems.
 *
 * @package Ratna Gems
 * @version 3.5.0
 *
 * CHANGELOG v3.5.0:
 * - Added new_customer parameter to purchase events (Google Ads optimization)
 * - Added item_brand parameter extraction
 * - Added affiliation parameter for store identification
 * - Improved coupon handling consistency
 * - Enhanced user data handling
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -----------------------------------------------------------------------------
// Helpers.
// -----------------------------------------------------------------------------

function ratna_gems_ga4_round( $value ): float {
    return round( (float) $value, 2 );
}

function ratna_gems_ga4_normalize_freeform( string $value ): string {
    if ( '' === $value ) {
        return '';
    }

    $value = sanitize_text_field( wp_unslash( $value ) );
    $value = strtolower( trim( preg_replace( '/\s+/', ' ', $value ) ) );

    return $value;
}

function ratna_gems_ga4_hash_freeform( string $value ): string {
    $normalized = ratna_gems_ga4_normalize_freeform( $value );

    return '' === $normalized ? '' : hash( 'sha256', $normalized );
}

/**
 * Normalize email address per Google Enhanced Conversions spec.
 *
 * Gmail period removal only applies to gmail.com and googlemail.com domains.
 * Per Google's specification, periods in the local part are significant for other domains.
 *
 * @param string $email The email address to normalize.
 * @return string Normalized email address.
 */
function ratna_gems_ga4_normalize_email( string $email ): string {
    $email = strtolower( trim( sanitize_email( $email ) ) );

    if ( ! $email || ! is_email( $email ) ) {
        return '';
    }

    list( $local_part, $domain ) = array_pad( explode( '@', $email, 2 ), 2, '' );

    if ( '' === $local_part || '' === $domain ) {
        return '';
    }

    // Remove anything after + (subaddressing)
    $plus_position = strpos( $local_part, '+' );
    if ( false !== $plus_position ) {
        $local_part = substr( $local_part, 0, $plus_position );
    }

    // Only remove periods for Gmail/Googlemail domains per Google Enhanced Conversions spec.
    $gmail_domains = array( 'gmail.com', 'googlemail.com' );
    if ( in_array( $domain, $gmail_domains, true ) ) {
        $local_part = str_replace( '.', '', $local_part );
    }

    return $local_part . '@' . $domain;
}

function ratna_gems_ga4_hash_email( string $email ): string {
    $normalized = ratna_gems_ga4_normalize_email( $email );

    return '' === $normalized ? '' : hash( 'sha256', $normalized );
}

function ratna_gems_ga4_normalize_phone( string $phone, string $country_code ): string {
    $phone = preg_replace( '/[^\d+]/', '', $phone );

    if ( '' === $phone ) {
        return '';
    }

    if ( 0 === strpos( $phone, '+' ) ) {
        return '+' . preg_replace( '/\D+/', '', substr( $phone, 1 ) );
    }

    $country_code = strtoupper( sanitize_key( $country_code ) );
    $calling_code = '';

    if ( function_exists( 'WC' ) && WC()->countries ) {
        $calling_code = (string) WC()->countries->get_country_calling_code( $country_code );
    }

    if ( '' !== $calling_code ) {
        $calling_code = preg_replace( '/\D+/', '', $calling_code );
    }

    $digits = preg_replace( '/\D+/', '', $phone );
    $digits = ltrim( $digits, '0' );

    if ( '' === $digits ) {
        return '';
    }

    if ( '' === $calling_code ) {
        return '+' . $digits;
    }

    return '+' . $calling_code . $digits;
}

function ratna_gems_ga4_hash_phone( string $phone, string $country_code ): string {
    $normalized = ratna_gems_ga4_normalize_phone( $phone, $country_code );

    return '' === $normalized ? '' : hash( 'sha256', $normalized );
}

function ratna_gems_ga4_get_client_ip_address(): string {
    $ip = '';

    if ( class_exists( 'WC_Geolocation' ) ) {
        $ip = WC_Geolocation::get_ip_address();
    } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
        $ip = wp_unslash( (string) $_SERVER['REMOTE_ADDR'] );
    }

    if ( ! is_string( $ip ) ) {
        return '';
    }

    $ip = trim( $ip );

    return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

function ratna_gems_ga4_get_client_user_agent(): string {
    if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
        return '';
    }

    $agent = substr( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ), 0, 512 );

    return sanitize_text_field( $agent );
}

function ratna_gems_ga4_get_current_url(): string {
    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';

    if ( '' === $request_uri ) {
        return esc_url_raw( home_url( '/' ) );
    }

    if ( '/' !== substr( $request_uri, 0, 1 ) ) {
        $request_uri = '/' . ltrim( $request_uri, '/' );
    }

    return esc_url_raw( home_url( $request_uri ) );
}

function ratna_gems_ga4_get_order_event_time( WC_Order $order ): int {
    $created = $order->get_date_created();

    if ( $created instanceof WC_DateTime ) {
        $timestamp = $created->getTimestamp();

        if ( $timestamp > 0 ) {
            return $timestamp;
        }
    }

    return time();
}

function ratna_gems_ga4_get_order_event_source_url( WC_Order $order ): string {
    $url = $order->get_checkout_order_received_url();

    if ( ! $url ) {
        $url = ratna_gems_ga4_get_current_url();
    }

    return esc_url_raw( $url );
}

function ratna_gems_ga4_hash_postal_code( string $postal_code ): string {
    $postal_code = strtolower( preg_replace( '/[^a-z0-9]/i', '', $postal_code ) );

    return '' === $postal_code ? '' : hash( 'sha256', $postal_code );
}

function ratna_gems_ga4_normalize_postal_code( string $postal_code ): string {
    $postal_code = strtoupper( preg_replace( '/[^a-z0-9]/i', '', $postal_code ) );

    return $postal_code;
}

function ratna_gems_ga4_hash_country( string $country_code ): string {
    $country_code = strtolower( sanitize_text_field( $country_code ) );

    return '' === $country_code ? '' : hash( 'sha256', $country_code );
}

function ratna_gems_ga4_normalize_country( string $country_code ): string {
    $country_code = strtoupper( sanitize_text_field( $country_code ) );

    return $country_code;
}

function ratna_gems_ga4_normalize_region( string $region, string $country ): string {
    $region  = ratna_gems_ga4_normalize_freeform( $region );
    $country = strtoupper( sanitize_text_field( $country ) );

    if ( '' === $region ) {
        return '';
    }

    if ( function_exists( 'WC' ) && WC()->countries ) {
        $countries = WC()->countries->get_states( $country );
        if ( is_array( $countries ) && isset( $countries[ strtoupper( $region ) ] ) ) {
            return strtoupper( $region );
        }
    }

    return $region;
}

function ratna_gems_ga4_is_bot(): bool {
    $agent = ratna_gems_ga4_get_client_user_agent();

    if ( '' === $agent ) {
        return false;
    }

    $bots = array(
        'bot',
        'crawl',
        'spider',
        'slurp',
        'facebookexternalhit',
        'pinterest',
        'yandex',
        'bingpreview',
    );

    foreach ( $bots as $bot ) {
        if ( false !== stripos( $agent, $bot ) ) {
            return true;
        }
    }

    return false;
}

function ratna_gems_ga4_maybe_mask_ip(): string {
    return ratna_gems_ga4_get_client_ip_address();
}

function ratna_gems_ga4_get_ga_client_id(): string {
    if ( empty( $_COOKIE['_ga'] ) ) {
        return '';
    }

    $parts = explode( '.', (string) $_COOKIE['_ga'] );

    if ( count( $parts ) < 4 ) {
        return '';
    }

    return $parts[2] . '.' . $parts[3];
}

/**
 * Get the fbc (Facebook Click ID) value.
 *
 * Per Meta Conversions API documentation, the fbc format is:
 * fb.1.{creation_time_millis}.{fbclid}
 *
 * The timestamp MUST be in MILLISECONDS, not seconds.
 *
 * @return string The fbc value or empty string.
 */
function ratna_gems_ga4_get_fbclid(): string {
    $fbc = '';

    if ( ! empty( $_COOKIE['_fbc'] ) ) {
        $fbc = (string) $_COOKIE['_fbc'];
    } elseif ( ! empty( $_GET['fbclid'] ) ) {
        // Use microtime(true) * 1000 for milliseconds per Meta CAPI spec.
        // Format: fb.1.{creation_time_millis}.{fbclid}
        $fbc = 'fb.1.' . round( microtime( true ) * 1000 ) . '.' . sanitize_text_field( (string) $_GET['fbclid'] );
    }

    return $fbc;
}

function ratna_gems_ga4_get_fbp(): string {
    if ( empty( $_COOKIE['_fbp'] ) ) {
        return '';
    }

    return sanitize_text_field( (string) $_COOKIE['_fbp'] );
}

/**
 * Build basic user_data for non-purchase events.
 * Contains only IP address and user agent for Meta CAPI.
 *
 * @return array User data array with client info.
 */
function ratna_gems_ga4_build_basic_user_data(): array {
    $data = array();

    $ip = ratna_gems_ga4_get_client_ip_address();
    if ( $ip ) {
        $data['client_ip_address'] = $ip;
    }

    $ua = ratna_gems_ga4_get_client_user_agent();
    if ( $ua ) {
        $data['client_user_agent'] = $ua;
    }

    return $data;
}

function ratna_gems_ga4_build_cookie_event(): array {
    $client_id   = ratna_gems_ga4_get_ga_client_id();
    $fbp         = ratna_gems_ga4_get_fbp();
    $fbclid      = ratna_gems_ga4_get_fbclid();
    $user_agent  = ratna_gems_ga4_get_client_user_agent();
    $ip_address  = ratna_gems_ga4_maybe_mask_ip();
    $event_id    = ratna_gems_ga4_event_id( 'cookie_state' );
    $consent     = ratna_gems_get_consent_cookie();
    $consent_map = array();

    if ( $consent ) {
        foreach ( $consent as $key => $value ) {
            $consent_map[ $key ] = strtolower( (string) $value );
        }
    }

    $meta = array();

    if ( ratna_gems_consent_allows_ad_user_data( $consent ) ) {
        $meta = array(
            'fbc' => $fbclid,
            'fbp' => $fbp,
        );
    }

    return array(
        'event' => 'cookie_state',
        'event_id' => $event_id,
        'consent_state' => $consent_map,
        'client_id' => $client_id,
        'meta' => $meta,
        'user_data' => array(
            'client_user_agent' => $user_agent,
            'client_ip_address' => $ip_address,
        ),
    );
}

function ratna_gems_ga4_can_share_ads_user_data(): bool {
    $state = ratna_gems_get_consent_cookie();

    if ( ! $state ) {
        return false;
    }

    $required = array( 'ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization' );

    foreach ( $required as $key ) {
        if ( ! isset( $state[ $key ] ) || 'granted' !== strtolower( (string) $state[ $key ] ) ) {
            return false;
        }
    }

    return true;
}

function ratna_gems_ga4_get_session(): ?WC_Session_Handler {
    if ( ! function_exists( 'WC' ) || ! WC() ) {
        return null;
    }

    if ( ! class_exists( 'WC_Session_Handler' ) ) {
        return null;
    }

    $session = WC()->session;

    if ( ! $session instanceof WC_Session_Handler ) {
        return null;
    }

    return $session;
}

function ratna_gems_ga4_set_session_data( string $key, $value ): void {
    $session = ratna_gems_ga4_get_session();
    if ( $session ) {
        $session->set( $key, $value );
    }
}

function ratna_gems_ga4_get_session_data( string $key, $default = null ) {
    $session = ratna_gems_ga4_get_session();
    if ( $session ) {
        $value = $session->get( $key );
        if ( null !== $value ) {
            return $value;
        }
    }

    return $default;
}

function ratna_gems_ga4_build_enhanced_user_data( WC_Order $order ): array {
    $data            = array();
    $has_hashed_data = false;

    $email_hash = ratna_gems_ga4_hash_email( (string) $order->get_billing_email() );
    if ( $email_hash ) {
        $data['email']   = $email_hash;
        $has_hashed_data = true;
    }

    $phone_hash = ratna_gems_ga4_hash_phone( (string) $order->get_billing_phone(), (string) $order->get_billing_country() );
    if ( $phone_hash ) {
        $data['phone_number'] = $phone_hash;
        $has_hashed_data      = true;
    }

    $address = array();

    $first_name_hash = ratna_gems_ga4_hash_freeform( (string) $order->get_billing_first_name() );
    if ( $first_name_hash ) {
        $address['first_name'] = $first_name_hash;
        $has_hashed_data       = true;
    }

    $last_name_hash = ratna_gems_ga4_hash_freeform( (string) $order->get_billing_last_name() );
    if ( $last_name_hash ) {
        $address['last_name'] = $last_name_hash;
        $has_hashed_data      = true;
    }

    $street_value = ratna_gems_ga4_hash_freeform( trim( (string) $order->get_billing_address_1() . ' ' . (string) $order->get_billing_address_2() ) );
    if ( '' !== $street_value ) {
        $address['street'] = $street_value;
        $has_hashed_data   = true;
    }

    $city_value = ratna_gems_ga4_hash_freeform( (string) $order->get_billing_city() );
    if ( '' !== $city_value ) {
        $address['city']   = $city_value;
        $has_hashed_data   = true;
    }

    $region_value = ratna_gems_ga4_hash_freeform( ratna_gems_ga4_normalize_region( (string) $order->get_billing_state(), (string) $order->get_billing_country() ) );
    if ( '' !== $region_value ) {
        $address['region'] = $region_value;
        $has_hashed_data   = true;
    }

    $postal_value = ratna_gems_ga4_hash_postal_code( (string) $order->get_billing_postcode() );
    if ( '' !== $postal_value ) {
        $address['postal_code'] = $postal_value;
        $has_hashed_data        = true;
    }

    $country_value = ratna_gems_ga4_hash_country( (string) $order->get_billing_country() );
    if ( '' !== $country_value ) {
        $address['country'] = $country_value;
        $has_hashed_data    = true;
    }

    if ( ! empty( $address ) ) {
        $data['address'] = $address;

        // Maintain compatibility with Server GTM variable expecting ads_user_data.address.street.
        if ( isset( $address['street'] ) ) {
            $data['ads_user_data'] = array(
                'address' => array(
                    'street' => $address['street'],
                ),
            );
        }
    }

    $customer_id = (int) $order->get_customer_id();
    if ( $customer_id > 0 ) {
        $external_id = ratna_gems_ga4_hash_freeform( (string) $customer_id );
        if ( '' !== $external_id ) {
            $data['external_id'] = $external_id;
            $has_hashed_data     = true;
        }
    }

    return $data;
}

function ratna_gems_ga4_event_id( string $event_name, string $suffix = '' ): string {
    $base = 'rg_' . $event_name . '_';

    if ( '' !== $suffix ) {
        return $base . $suffix . '_' . wp_generate_uuid4();
    }

    return $base . wp_generate_uuid4();
}

function ratna_gems_ga4_queue_event( array $payload ): void {
    add_action(
        'wp_footer',
        function () use ( $payload ) {
            // Use JSON_HEX_TAG | JSON_HEX_AMP for safe output in script tags.
            $json = wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP );
            if ( ! $json ) {
                return;
            }
            echo wp_print_inline_script_tag( "window.dataLayer=window.dataLayer||[];window.dataLayer.push({$json});" );
        },
        99
    );
}

// -----------------------------------------------------------------------------
// WooCommerce product item builder.
// -----------------------------------------------------------------------------

/**
 * Get product brand from attributes or taxonomy.
 *
 * @param WC_Product $product The product object.
 * @return string Product brand or empty string.
 */
function ratna_gems_ga4_get_product_brand( WC_Product $product ): string {
    // Try 'brand' attribute first
    $brand = $product->get_attribute( 'brand' );
    if ( ! empty( $brand ) ) {
        return sanitize_text_field( $brand );
    }

    // Try 'pa_brand' taxonomy (common WooCommerce pattern)
    $terms = get_the_terms( $product->get_id(), 'pa_brand' );
    if ( is_array( $terms ) && ! empty( $terms ) ) {
        return sanitize_text_field( $terms[0]->name );
    }

    // Try Perfect Brands for WooCommerce plugin taxonomy
    $terms = get_the_terms( $product->get_id(), 'pwb-brand' );
    if ( is_array( $terms ) && ! empty( $terms ) ) {
        return sanitize_text_field( $terms[0]->name );
    }

    // Default brand for Ratna Gems products
    return 'Ratna Gems';
}

/**
 * Build a GA4 ecommerce item from a WooCommerce product.
 *
 * @param WC_Product              $product    The product object.
 * @param int                     $quantity   Item quantity.
 * @param WC_Order_Item_Product|null $order_item Order item for price calculation.
 * @param array                   $context    Additional context (item_list_name, etc.).
 * @return array GA4 item array.
 */
function ratna_gems_ga4_build_item( WC_Product $product, int $quantity = 1, $order_item = null, array $context = array() ): array {
    $price = (float) $product->get_price();

    if ( $order_item instanceof WC_Order_Item_Product ) {
        $line_subtotal = (float) $order_item->get_subtotal();
        $line_tax      = (float) $order_item->get_subtotal_tax();
        $qty           = max( 1, (int) $order_item->get_quantity() );
        $price         = ( $line_subtotal + $line_tax ) / $qty;
    }

    $item = array(
        'item_id'       => (string) $product->get_sku() ?: (string) $product->get_id(),
        'item_name'     => $product->get_name(),
        'price'         => ratna_gems_ga4_round( $price ),
        'quantity'      => $quantity,
        'item_category' => '',
        'item_brand'    => ratna_gems_ga4_get_product_brand( $product ), // NEW: Added item_brand
    );

    // Get primary category
    $categories = get_the_terms( $product->get_id(), 'product_cat' );
    if ( is_array( $categories ) && ! empty( $categories ) ) {
        $sorted = array();
        foreach ( $categories as $cat ) {
            $sorted[ $cat->parent ] = $cat;
        }
        $main_cat = reset( $sorted );
        if ( $main_cat instanceof WP_Term ) {
            $item['item_category'] = $main_cat->name;
        }

        // Add category hierarchy (item_category2, etc.)
        $cat_index = 2;
        foreach ( $categories as $cat ) {
            if ( $cat_index > 5 ) {
                break; // GA4 supports up to item_category5
            }
            if ( $main_cat instanceof WP_Term && $cat->term_id !== $main_cat->term_id ) {
                $item[ 'item_category' . $cat_index ] = $cat->name;
                $cat_index++;
            }
        }
    }

    // Add context parameters
    if ( isset( $context['item_list_name'] ) ) {
        $item['item_list_name'] = $context['item_list_name'];
    }
    if ( isset( $context['item_list_id'] ) ) {
        $item['item_list_id'] = $context['item_list_id'];
    }
    if ( isset( $context['index'] ) ) {
        $item['index'] = $context['index'];
    }

    return $item;
}

/**
 * Determine if a customer is new based on their order history.
 *
 * @param WC_Order $order The order object.
 * @return bool True if this is a new customer.
 */
function ratna_gems_ga4_is_new_customer( WC_Order $order ): bool {
    $customer_id = $order->get_customer_id();
    $billing_email = $order->get_billing_email();

    if ( $customer_id > 0 ) {
        // Registered customer - check order count
        $order_count = wc_get_customer_order_count( $customer_id );
        return $order_count <= 1;
    }

    if ( ! empty( $billing_email ) ) {
        // Guest checkout - check if email has previous orders
        $previous_orders = wc_get_orders(
            array(
                'billing_email' => $billing_email,
                'status'        => array( 'wc-completed', 'wc-processing' ),
                'limit'         => 2,
                'exclude'       => array( $order->get_id() ),
                'return'        => 'ids',
            )
        );

        return empty( $previous_orders );
    }

    return true; // Default to new if we can't determine
}

/**
 * Get primary coupon from order (GA4 expects single coupon).
 *
 * @param WC_Order $order The order object.
 * @return string Primary coupon code or empty string.
 */
function ratna_gems_ga4_get_primary_coupon( WC_Order $order ): string {
    $coupons = $order->get_coupon_codes();
    if ( ! empty( $coupons ) ) {
        return sanitize_text_field( $coupons[0] );
    }
    return '';
}

/**
 * Get primary coupon from cart.
 *
 * @return string Primary coupon code or empty string.
 */
function ratna_gems_ga4_get_cart_primary_coupon(): string {
    if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
        return '';
    }

    $coupons = WC()->cart->get_applied_coupons();
    if ( ! empty( $coupons ) ) {
        return sanitize_text_field( $coupons[0] );
    }
    return '';
}

// -----------------------------------------------------------------------------
// GA4 ecommerce events.
// -----------------------------------------------------------------------------

add_action( 'wp_footer', 'ratna_gems_ga4_view_item', 5 );
function ratna_gems_ga4_view_item(): void {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) {
        return;
    }

    global $product;
    if ( ! $product instanceof WC_Product || ! $product->is_purchasable() ) {
        return;
    }

    $item    = ratna_gems_ga4_build_item( $product, 1 );
    // Build the payload for the view_item event.  Per GA4 and server-side
    // tagging best practices, include an event_time parameter (epoch seconds)
    // so downstream tags (e.g. Facebook CAPI) can use the timestamp when the
    // event actually occurred.  Without this,
    // server-side tags will use the time the server processed the request
    // instead of when it happened in the browser.
    $payload = array(
        'event'            => 'view_item',
        'event_id'         => ratna_gems_ga4_event_id( 'view_item', (string) $product->get_id() ),
        'event_time'       => time(),
        'event_source_url' => ratna_gems_ga4_get_current_url(),
        'ecommerce'        => array(
            'currency' => get_woocommerce_currency(),
            'value'    => $item['price'],
            'items'    => array( $item ),
        ),
    );

    // Include user_data and meta for Meta CAPI compatibility
    if ( ratna_gems_ga4_can_share_ads_user_data() ) {
        $user_data = ratna_gems_ga4_build_basic_user_data();
        if ( ! empty( $user_data ) ) {
            $payload['user_data'] = $user_data;
        }
        $payload['meta'] = ratna_gems_ga4_get_meta_click_ids();
    } else {
        $payload['meta'] = array();
    }

    ratna_gems_ga4_queue_event( $payload );
}

add_action( 'woocommerce_after_checkout_form', 'ratna_gems_ga4_begin_checkout', 10 );
function ratna_gems_ga4_begin_checkout(): void {
    if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
        return;
    }

    $items = array();
    $total = 0.0;

    foreach ( WC()->cart->get_cart() as $cart_item ) {
        $product  = $cart_item['data'] ?? null;
        $quantity = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;

        if ( $product instanceof WC_Product && $product->is_purchasable() ) {
            $item    = ratna_gems_ga4_build_item( $product, $quantity );
            $items[] = $item;
            $total  += $item['price'] * $item['quantity'];
        }
    }

    if ( empty( $items ) ) {
        return;
    }

    $payload = array(
        'event'            => 'begin_checkout',
        'event_id'         => ratna_gems_ga4_event_id( 'begin_checkout' ),
        // Include event_time so downstream tags have an accurate timestamp.
        'event_time'       => time(),
        'event_source_url' => ratna_gems_ga4_get_current_url(),
        'ecommerce'        => array(
            'currency' => get_woocommerce_currency(),
            'value'    => ratna_gems_ga4_round( $total ),
            'coupon'   => ratna_gems_ga4_get_cart_primary_coupon(), // FIXED: Use single coupon
            'items'    => $items,
        ),
    );

    // Include user_data and meta for Meta CAPI compatibility
    if ( ratna_gems_ga4_can_share_ads_user_data() ) {
        $user_data = ratna_gems_ga4_build_basic_user_data();
        if ( ! empty( $user_data ) ) {
            $payload['user_data'] = $user_data;
        }
        $payload['meta'] = ratna_gems_ga4_get_meta_click_ids();
    } else {
        $payload['meta'] = array();
    }

    ratna_gems_ga4_queue_event( $payload );
}

add_action( 'woocommerce_thankyou', 'ratna_gems_ga4_purchase', 5 );
function ratna_gems_ga4_purchase( $order_id ): void {
    $order = wc_get_order( $order_id );
    if ( ! $order instanceof WC_Order ) {
        return;
    }

    // Check if we've already processed this order
    if ( $order->get_meta( '_ratna_gems_ga4_purchase_tracked' ) === 'yes' ) {
        return;
    }

    $items    = array();
    $subtotal = 0.0;
    $total    = (float) $order->get_total();
    $tax      = (float) $order->get_total_tax();
    $shipping = (float) $order->get_shipping_total();

    foreach ( $order->get_items() as $order_item ) {
        if ( ! $order_item instanceof WC_Order_Item_Product ) {
            continue;
        }
        $product  = $order_item->get_product();
        $quantity = $order_item->get_quantity();
        if ( $product instanceof WC_Product && $product->is_purchasable() ) {
            $item     = ratna_gems_ga4_build_item( $product, $quantity, $order_item );
            $items[]  = $item;
            $subtotal += (float) $order_item->get_subtotal() + (float) $order_item->get_subtotal_tax();
        }
    }

    $transaction_id = $order->get_order_number();
    $is_new_customer = ratna_gems_ga4_is_new_customer( $order );

    $payload = array(
        'event'            => 'purchase',
        'event_id'         => ratna_gems_ga4_event_id( 'purchase', (string) $transaction_id ),
        'event_time'       => ratna_gems_ga4_get_order_event_time( $order ),
        'event_source_url' => ratna_gems_ga4_get_order_event_source_url( $order ),
        'ecommerce'        => array(
            'transaction_id' => (string) $transaction_id,
            'affiliation'    => 'Ratna Gems Online Store', // NEW: Added affiliation
            'value'          => ratna_gems_ga4_round( $total ),
            'tax'            => ratna_gems_ga4_round( $tax ),
            'shipping'       => ratna_gems_ga4_round( $shipping ),
            'currency'       => $order->get_currency(),
            'coupon'         => ratna_gems_ga4_get_primary_coupon( $order ), // FIXED: Use single coupon
            'items'          => $items,
            'items_subtotal' => ratna_gems_ga4_round( $subtotal ),
        ),
        // The `new_customer` boolean is included for Google Ads enhanced
        // conversions.  However, GA4’s ecommerce specification uses
        // `customer_type` with values `new` or `returning`.  Send both
        // parameters so downstream tools can choose the appropriate one.
        'new_customer'     => $is_new_customer,
        'customer_type'    => $is_new_customer ? 'new' : 'returning',
    );

    if ( ratna_gems_ga4_can_share_ads_user_data() ) {
        $user_data = ratna_gems_ga4_build_enhanced_user_data( $order );
        $network_ip = ratna_gems_ga4_get_client_ip_address();
        $network_ua = ratna_gems_ga4_get_client_user_agent();

        if ( $network_ip ) {
            $user_data['client_ip_address'] = $network_ip;
        }

        if ( $network_ua ) {
            $user_data['client_user_agent'] = $network_ua;
        }

        if ( ! empty( $user_data ) ) {
            $payload['user_data'] = $user_data;
        }

        // Always include meta for Meta CAPI compatibility
        $payload['meta'] = ratna_gems_ga4_get_meta_click_ids();
    } else {
        $payload['meta'] = array();
    }

    // Queue for browser dispatch via the dataLayer.
    $session = ratna_gems_ga4_get_session();
    if ( $session ) {
        $session->set( 'ratna_gems_ga4_purchase_payload', $payload );
    }

    // Mark order as tracked to prevent duplicates
    $order->update_meta_data( '_ratna_gems_ga4_purchase_tracked', 'yes' );
    $order->save();
}

add_action( 'wp_footer', 'ratna_gems_ga4_print_purchase_payload', 8 );
function ratna_gems_ga4_print_purchase_payload(): void {
    if ( ! is_wc_endpoint_url( 'order-received' ) ) {
        return;
    }

    if ( ratna_gems_ga4_is_bot() ) {
        return;
    }

    $session = ratna_gems_ga4_get_session();
    if ( ! $session ) {
        return;
    }

    $payload = $session->get( 'ratna_gems_ga4_purchase_payload' );
    if ( empty( $payload ) ) {
        return;
    }

    ratna_gems_ga4_queue_event( $payload );
    $session->set( 'ratna_gems_ga4_purchase_payload', null );
}

add_action( 'wp_footer', 'ratna_gems_ga4_view_item_list', 5 );
function ratna_gems_ga4_view_item_list(): void {
    if ( ! function_exists( 'is_shop' ) || ( ! is_shop() && ! is_product_category() ) ) {
        return;
    }

    global $wp_query;
    if ( ! $wp_query->have_posts() ) {
        return;
    }

    $list_name = 'Shop';
    $list_id   = 'shop';

    if ( is_product_category() ) {
        $category = get_queried_object();
        if ( $category instanceof WP_Term ) {
            $list_name = $category->name;
            $list_id   = $category->slug;
        }
    }

    $items = array();
    $index = 1;

    while ( $wp_query->have_posts() ) {
        $wp_query->the_post();
        $product = wc_get_product( get_the_ID() );

        if ( $product instanceof WC_Product && $product->is_purchasable() ) {
            $context = array(
                'item_list_name' => $list_name,
                'item_list_id'   => $list_id,
                'index'          => $index++,
            );
            $items[] = ratna_gems_ga4_build_item( $product, 1, null, $context );
        }
    }
    wp_reset_postdata();

    if ( empty( $items ) ) {
        return;
    }

    // FIX: Calculate total value for view_item_list (GA4 recommendation)
    $total_value = 0.0;
    foreach ( $items as $item ) {
        $total_value += (float) $item['price'];
    }

    $payload = array(
        'event'            => 'view_item_list',
        'event_id'         => ratna_gems_ga4_event_id( 'view_item_list', $list_id ),
        // Add event_time (seconds since epoch) for better temporal accuracy
        'event_time'       => time(),
        'event_source_url' => ratna_gems_ga4_get_current_url(),
        'ecommerce'        => array(
            'item_list_id'   => $list_id,
            'item_list_name' => $list_name,
            'currency'       => get_woocommerce_currency(), // FIX: Added currency
            'value'          => ratna_gems_ga4_round( $total_value ), // FIX: Added value
            'items'          => $items,
        ),
    );

    // Include user_data and meta for Meta CAPI compatibility
    if ( ratna_gems_ga4_can_share_ads_user_data() ) {
        $user_data = ratna_gems_ga4_build_basic_user_data();
        if ( ! empty( $user_data ) ) {
            $payload['user_data'] = $user_data;
        }
        $payload['meta'] = ratna_gems_ga4_get_meta_click_ids();
    } else {
        $payload['meta'] = array();
    }

    ratna_gems_ga4_queue_event( $payload );
}

add_action( 'wp_footer', 'ratna_gems_ga4_view_cart', 5 );
function ratna_gems_ga4_view_cart(): void {
    if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
        return;
    }

    if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
        return;
    }

    $items = array();
    $total = 0.0;

    foreach ( WC()->cart->get_cart() as $cart_item ) {
        $product  = $cart_item['data'] ?? null;
        $quantity = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;

        if ( $product instanceof WC_Product && $product->is_purchasable() ) {
            $item    = ratna_gems_ga4_build_item( $product, $quantity );
            $items[] = $item;
            $total  += $item['price'] * $item['quantity'];
        }
    }

    if ( empty( $items ) ) {
        return;
    }

    $payload = array(
        'event'            => 'view_cart',
        'event_id'         => ratna_gems_ga4_event_id( 'view_cart' ),
        'event_time'       => time(), // timestamp in seconds for server-side tagging
        'event_source_url' => ratna_gems_ga4_get_current_url(),
        'ecommerce'        => array(
            'currency' => get_woocommerce_currency(),
            'value'    => ratna_gems_ga4_round( $total ),
            'items'    => $items,
        ),
    );

    // Include user_data and meta for Meta CAPI compatibility
    if ( ratna_gems_ga4_can_share_ads_user_data() ) {
        $user_data = ratna_gems_ga4_build_basic_user_data();
        if ( ! empty( $user_data ) ) {
            $payload['user_data'] = $user_data;
        }
        $payload['meta'] = ratna_gems_ga4_get_meta_click_ids();
    } else {
        $payload['meta'] = array();
    }

    ratna_gems_ga4_queue_event( $payload );
}

function ratna_gems_ga4_get_meta_click_ids(): array {
    $ids = array();
    $fbp = ratna_gems_ga4_get_fbp();
    $fbc = ratna_gems_ga4_get_fbclid();

    if ( '' !== $fbp ) {
        $ids['fbp'] = $fbp;
    }

    if ( '' !== $fbc ) {
        $ids['fbc'] = $fbc;
    }

    return $ids;
}

function ratna_gems_ga4_prepare_client_item( WC_Product $product, int $quantity ): array {
    $item   = ratna_gems_ga4_build_item( $product, $quantity );
    $value  = ratna_gems_ga4_round( $item['price'] * $item['quantity'] );
    $currency = get_woocommerce_currency();

    return array(
        'item'      => $item,
        'value'     => $value,
        'currency'  => $currency,
        'productId' => $product->get_id(),
    );
}

// -----------------------------------------------------------------------------
// AJAX handlers for client-side product lookups and event markers.
// -----------------------------------------------------------------------------

add_action( 'wp_ajax_ratna_gems_ga4_product_info', 'ratna_gems_ga4_ajax_product_info' );
add_action( 'wp_ajax_nopriv_ratna_gems_ga4_product_info', 'ratna_gems_ga4_ajax_product_info' );
function ratna_gems_ga4_ajax_product_info(): void {
    check_ajax_referer( 'ratna_gems_ga4_product_info', 'nonce' );

    $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
    $quantity   = isset( $_POST['quantity'] ) ? max( 1, absint( $_POST['quantity'] ) ) : 1;

    if ( ! $product_id ) {
        wp_send_json_error( 'Missing product ID.' );
    }

    $product = wc_get_product( $product_id );
    if ( ! $product instanceof WC_Product || ! $product->is_purchasable() ) {
        wp_send_json_error( 'Invalid product.' );
    }

    wp_send_json_success( ratna_gems_ga4_prepare_client_item( $product, $quantity ) );
}

add_action( 'wp_ajax_ratna_gems_ga4_mark_emitted', 'ratna_gems_ga4_ajax_mark_emitted' );
add_action( 'wp_ajax_nopriv_ratna_gems_ga4_mark_emitted', 'ratna_gems_ga4_ajax_mark_emitted' );
function ratna_gems_ga4_ajax_mark_emitted(): void {
    check_ajax_referer( 'ratna_gems_ga4_event_marker', 'nonce' );

    $event_id = isset( $_POST['event_id'] ) ? sanitize_text_field( wp_unslash( $_POST['event_id'] ) ) : '';

    if ( '' === $event_id ) {
        wp_send_json_error( 'Missing event_id.' );
    }

    // Placeholder: Here you could store the event_id to prevent duplicate server-side dispatch.
    wp_send_json_success( array( 'marked' => $event_id ) );
}

// -----------------------------------------------------------------------------
// Enqueue client-side helpers.
// -----------------------------------------------------------------------------

add_action( 'wp_enqueue_scripts', 'ratna_gems_ga4_enqueue_client_scripts' );
function ratna_gems_ga4_enqueue_client_scripts(): void {
    $dir        = get_stylesheet_directory();
    $uri        = get_stylesheet_directory_uri();
    $script_path = $dir . '/assets/js/ga4-client.js';

    if ( ! file_exists( $script_path ) ) {
        return;
    }

    wp_register_script( 'ratna-gems-ga4-client', $uri . '/assets/js/ga4-client.js', array(), (string) filemtime( $script_path ), true );
    wp_script_add_data( 'ratna-gems-ga4-client', 'strategy', 'defer' );

    $config = array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonces'  => array(
            'productInfo' => wp_create_nonce( 'ratna_gems_ga4_product_info' ),
            'eventMarker' => wp_create_nonce( 'ratna_gems_ga4_event_marker' ),
        ),
        'products' => array(),
    );

    wp_localize_script( 'ratna-gems-ga4-client', 'ratnaGemsGa4Config', $config );
    wp_enqueue_script( 'ratna-gems-ga4-client' );
}
