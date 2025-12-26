<?php
/**
 * Enhanced Delhivery API client for Ratna Gems child theme.
 * Complete implementation based on official Delhivery B2C API documentation.
 * WordPress HTTP API only; HPOS-safe; robust logging; official endpoints.
 *
 * @package RatnaGems\Delhivery
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Delhivery_API_Client' ) ) {

class Delhivery_API_Client {

    /**
     * Production base URL
     */
    const PRODUCTION_BASE_URL = 'https://track.delhivery.com';
    
    /**
     * Staging/Test base URL
     */
    const STAGING_BASE_URL = 'https://staging-express.delhivery.com';

    /**
     * API Endpoints based on official documentation
     */
    const ENDPOINTS = array(
        // Shipment Management
        'manifest'              => '/api/cmu/create.json',
        'edit_shipment'         => '/api/p/edit',
        'cancel_shipment'       => '/api/p/edit',
        
        // Tracking
        'track'                 => '/api/v1/packages/json/',
        
        // Waybill
        'fetch_waybill'         => '/waybill/api/bulk/json/',
        'fetch_single_waybill'  => '/waybill/api/fetch/json/',
        
        // Label & Documents
        'packing_slip'          => '/api/p/packing_slip',
        'download_document'     => '/api/rest/fetch/pkg/document/',
        
        // Pincode & Cost
        'pincode_serviceability'=> '/c/api/pin-codes/json/',
        'calculate_cost'        => '/api/kinko/v1/invoice/charges/.json',
        
        // Pickup
        'pickup_request'        => '/fm/request/new/',
        
        // Warehouse
        'warehouse_create'      => '/api/backend/clientwarehouse/create/',
        'warehouse_edit'        => '/api/backend/clientwarehouse/edit/',
        
        // NDR
        'ndr_update'            => '/api/p/update',
        
        // E-waybill
        'ewaybill_update'       => '/api/rest/ewaybill/{waybill}/',
        
        // RVP QC
        'rvp_qc_create'         => '/api/cmu/create.json',
    );

    /**
     * Rate limits per endpoint (requests per 5 minutes per IP) from documentation
     */
    const RATE_LIMITS = array(
        'pincode_serviceability' => 4500,
        'track'                  => 750,
        'fetch_waybill'          => 5,
        'warehouse_edit'         => null, // NA
        'calculate_cost'         => null, // Not specified
    );

    /** @var string */
    protected $api_key = '';
    /** @var string */
    protected $api_secret = '';
    /** @var string */
    protected $pickup_location = '';
    /** @var string */
    protected $client_code = '';
    /** @var string */
    protected $base_url = '';
    /** @var int */
    protected $timeout = 30;
    /** @var int */
    protected $max_retries = 3;
    /** @var array<string,string> */
    protected $return_details = array();
    /** @var array<string,string> */
    protected $seller_details = array();
    /** @var \WC_Logger|null */
    protected $logger = null;
    /** @var array Rate limit tracking */
    protected $rate_limit_tracker = array();

    /**
     * Constructor
     */
    public function __construct( array $args = array() ) {
        $defaults = array(
            'api_key'         => defined( 'DELHIVERY_API_KEY' ) ? (string) DELHIVERY_API_KEY : ( defined( 'DELHIVERY_API_TOKEN' ) ? (string) DELHIVERY_API_TOKEN : '' ),
            'api_secret'      => defined( 'DELHIVERY_API_SECRET' ) ? (string) DELHIVERY_API_SECRET : '',
            'pickup_location' => defined( 'DELHIVERY_PICKUP_LOCATION_NAME' ) ? (string) DELHIVERY_PICKUP_LOCATION_NAME : '',
            'client_code'     => defined( 'DELHIVERY_CLIENT_CODE' ) ? (string) DELHIVERY_CLIENT_CODE : '',
            'base_url'        => defined( 'DELHIVERY_API_BASE_URL' ) ? (string) DELHIVERY_API_BASE_URL : '',
            'timeout'         => 30,
            'max_retries'     => 3,
        );
        $args = wp_parse_args( $args, $defaults );

        $this->api_key         = $args['api_key'] ?: $this->get_option_fallback( array( 'rg_delhivery_api_key', 'rg_delhivery_api_token' ) );
        $this->api_secret      = $args['api_secret'] ?: $this->get_option_fallback( array( 'rg_delhivery_api_secret' ) );
        $this->pickup_location = $args['pickup_location'] ?: $this->get_option_fallback( array( 'rg_delhivery_pickup_location' ) );
        $this->pickup_location = $this->normalize_pickup_location( $this->pickup_location );
        $this->client_code     = $args['client_code'] ?: $this->get_option_fallback( array( 'rg_delhivery_client_code' ) );
        $this->timeout         = absint( $args['timeout'] ) > 0 ? absint( $args['timeout'] ) : 30;
        $this->max_retries     = max( 0, absint( $args['max_retries'] ) );
        
        if ( $args['base_url'] ) {
            $this->base_url = untrailingslashit( $args['base_url'] );
        } elseif ( defined( 'DELHIVERY_STAGING_MODE' ) && DELHIVERY_STAGING_MODE ) {
            $this->base_url = self::STAGING_BASE_URL;
        } elseif ( defined( 'DELHIVERY_STAGING' ) && DELHIVERY_STAGING ) {
            $this->base_url = self::STAGING_BASE_URL;
        } else {
            $this->base_url = self::PRODUCTION_BASE_URL;
        }

        $this->return_details = array(
            'return_add'     => defined( 'DELHIVERY_RETURN_ADDRESS' ) ? (string) DELHIVERY_RETURN_ADDRESS : $this->get_option_fallback( array( 'rg_delhivery_return_address' ) ),
            'return_city'    => defined( 'DELHIVERY_RETURN_CITY' ) ? (string) DELHIVERY_RETURN_CITY : $this->get_option_fallback( array( 'rg_delhivery_return_city' ) ),
            'return_state'   => defined( 'DELHIVERY_RETURN_STATE' ) ? (string) DELHIVERY_RETURN_STATE : $this->get_option_fallback( array( 'rg_delhivery_return_state' ) ),
            'return_country' => defined( 'DELHIVERY_RETURN_COUNTRY' ) ? (string) DELHIVERY_RETURN_COUNTRY : $this->get_option_fallback( array( 'rg_delhivery_return_country' ) ),
            'return_pin'     => defined( 'DELHIVERY_RETURN_PIN' ) ? (string) DELHIVERY_RETURN_PIN : $this->get_option_fallback( array( 'rg_delhivery_return_pin' ) ),
            'return_phone'   => defined( 'DELHIVERY_RETURN_PHONE' ) ? (string) DELHIVERY_RETURN_PHONE : $this->get_option_fallback( array( 'rg_delhivery_return_phone' ) ),
        );
        $this->seller_details = array(
            'seller_add'  => defined( 'DELHIVERY_SELLER_ADDRESS' ) ? (string) DELHIVERY_SELLER_ADDRESS : $this->get_option_fallback( array( 'rg_delhivery_seller_address' ) ),
            'seller_name' => defined( 'DELHIVERY_SELLER_NAME' ) ? (string) DELHIVERY_SELLER_NAME : ( get_bloginfo( 'name' ) ?: '' ),
            'seller_inv'  => $this->get_option_fallback( array( 'rg_delhivery_default_invoice' ) ),
        );
    }

    // =========================================================================
    // Configuration & Status Methods
    // =========================================================================

    public function is_configured(): bool {
        return '' !== $this->api_key && '' !== $this->pickup_location;
    }

    public function get_pickup_location(): string { 
        return $this->pickup_location; 
    }

    public function get_base_url(): string { 
        return $this->base_url ?: self::PRODUCTION_BASE_URL; 
    }

    public function is_test_environment(): bool {
        $u = $this->get_base_url();
        return str_contains( $u, 'staging' ) || str_contains( $u, 'sandbox' ) || str_contains( $u, 'test' );
    }

    public function get_staging_url(): string {
        return self::STAGING_BASE_URL;
    }

    public function get_production_url(): string {
        return self::PRODUCTION_BASE_URL;
    }

    // =========================================================================
    // Logging Methods
    // =========================================================================

    protected function get_logger() {
        if ( null !== $this->logger ) return $this->logger;
        if ( function_exists( 'wc_get_logger' ) ) $this->logger = wc_get_logger();
        return $this->logger;
    }

    protected function log( string $level, string $message, array $context = array() ): void {
        $logger = $this->get_logger();
        if ( ! $logger ) return;
        if ( isset( $context['request'] ) ) {
            $context['request'] = $this->redact_sensitive_request_data( $context['request'] );
        }
        $logger->log( $level, $message, array( 'source' => 'rg-delhivery', 'context' => $context ) );
    }

    protected function redact_sensitive_request_data( array $request ): array {
        if ( isset( $request['headers'] ) && is_array( $request['headers'] ) ) {
            foreach ( array( 'Authorization','authorization','X-Delhivery-Client','x-delhivery-client','X-Delhivery-Secret','x-delhivery-secret' ) as $h ) {
                if ( isset( $request['headers'][ $h ] ) ) $request['headers'][ $h ] = '********';
            }
        }
        if ( isset( $request['body'] ) && is_string( $request['body'] ) && '' !== $request['body'] ) {
            $request['body'] = '[redacted]';
        }
        return $request;
    }

    // =========================================================================
    // HTTP Request Infrastructure
    // =========================================================================

    protected function build_headers( array $extra = array(), bool $expect_json = true ): array {
        $headers = array(
            'Authorization' => 'Token ' . $this->api_key,
            'Accept'        => $expect_json ? 'application/json' : 'application/pdf,application/json;q=0.5',
            'User-Agent'    => 'RatnaGems-Delhivery-Client/2.0; WordPress',
        );
        if ( $expect_json ) $headers['Content-Type'] = 'application/json';
        if ( '' !== $this->api_secret ) $headers['X-Delhivery-Secret'] = $this->api_secret;
        if ( '' !== $this->client_code )  $headers['X-Delhivery-Client']  = $this->client_code;
        return array_merge( $headers, $extra );
    }

    protected function build_url( string $path, array $query = array() ): string {
        $url = untrailingslashit( $this->get_base_url() ) . '/' . ltrim( $path, '/' );
        if ( ! empty( $query ) ) {
            $san = array();
            foreach ( $query as $k => $v ) {
                $san[ $k ] = ( is_scalar( $v ) || null === $v ) ? (string) $v : wp_json_encode( $v );
            }
            $url = add_query_arg( $san, $url );
        }
        return $url;
    }

    protected function is_retriable_error( $response ): bool {
        if ( is_wp_error( $response ) ) {
            $code = $response->get_error_code();
            return in_array( $code, array( 'http_request_failed','connect_timeout','request_timeout','tcp_connect_timeout' ), true );
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        return $status >= 500; // retry only server errors
    }

    /**
     * Core HTTP request method with retry logic
     * @return array|WP_Error
     */
    protected function request( string $method, string $path, array $args = array() ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'delhivery_not_configured', __( 'Delhivery API credentials or pickup location not configured.', 'ratna-gems' ) );
        }

        $expect_json = $args['expect_json'] ?? true;
        $headers     = $this->build_headers( $args['headers'] ?? array(), $expect_json );
        $url         = $this->build_url( $path, $args['query'] ?? array() );
        $timeout     = isset( $args['timeout'] ) ? max( 5, (int) $args['timeout'] ) : $this->timeout;
        $body        = $args['body'] ?? null;
        $body_format = $args['body_format'] ?? ( $expect_json ? 'json' : 'raw' );

        if ( is_array( $body ) ) {
            if ( 'form' === $body_format ) {
                $body = http_build_query( $body, '', '&' );
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            } else {
                $body = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                $headers['Content-Type'] = 'application/json';
            }
        }

        $req = array( 'method' => strtoupper( $method ), 'timeout' => $timeout, 'headers' => $headers, 'body' => $body, 'data_format' => 'body' );

        $attempt  = 0;
        $response = null;
        do {
            $attempt++;
            $response = wp_remote_request( $url, $req );
            if ( ! $this->is_retriable_error( $response ) || $attempt > $this->max_retries + 1 ) break;
            $this->log( 'warning', 'Retrying Delhivery request due to recoverable error.', array( 'attempt' => $attempt, 'request' => array( 'method' => $req['method'], 'url' => $url ) ) );
            usleep( (int) ( min( 8, pow( 2, max( 0, $attempt - 1 ) ) * 0.5 ) * 1_000_000 ) );
        } while ( true );

        if ( is_wp_error( $response ) ) {
            $this->log( 'error', 'Delhivery request failed.', array( 'request' => array( 'method' => $req['method'], 'url' => $url ), 'error' => $response->get_error_message() ) );
            return $response;
        }

        $code    = (int) wp_remote_retrieve_response_code( $response );
        $headers_resp = wp_remote_retrieve_headers( $response );
        $body_resp    = wp_remote_retrieve_body( $response );

        $result = array(
            'code'    => $code,
            'body'    => $body_resp,
            'headers' => is_object( $headers_resp ) ? $headers_resp->getAll() : (array) $headers_resp,
        );

        if ( $expect_json ) {
            $decoded = json_decode( $body_resp, true );
            if ( JSON_ERROR_NONE !== json_last_error() ) {
                $this->log( 'error', 'Invalid JSON returned by Delhivery.', array( 'response_code' => $code, 'body' => wp_trim_words( (string) $body_resp, 40 ) ) );
                return new WP_Error( 'delhivery_invalid_json', __( 'Delhivery returned an unreadable response.', 'ratna-gems' ) );
            }
            $result['json'] = $decoded;
        }

        return $result;
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    protected function get_option_fallback( array $keys ): string {
        foreach ( $keys as $key ) {
            $val = get_option( $key );
            if ( is_string( $val ) && '' !== $val ) return $val;
        }
        return '';
    }

    protected function normalize_order( $order ) {
        if ( $order instanceof WC_Order ) return $order;
        $resolved = wc_get_order( $order );
        if ( ! $resolved ) return new WP_Error( 'delhivery_invalid_order', __( 'Unable to load the WooCommerce order for Delhivery.', 'ratna-gems' ) );
        return $resolved;
    }

    protected function sanitize_reference( string $reference ): string {
        $san = preg_replace( '/[^A-Za-z0-9\-]/', '', $reference );
        return substr( (string) $san, 0, 20 );
    }

    protected function sanitize_phone( string $phone ): string {
        return substr( preg_replace( '/\D+/', '', $phone ), 0, 15 );
    }

    protected function sanitize_awb( string $awb ): string {
        return substr( preg_replace( '/[^A-Za-z0-9]/', '', $awb ), 0, 20 );
    }

    protected function format_decimal( $v, int $precision = 2 ): string {
        $v = (float) $v;
        return function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $v, $precision ) : number_format( $v, $precision, '.', '' );
    }

    protected function wc_weight_to_grams( $value ): float {
        $value = (float) $value;
        $unit  = get_option( 'woocommerce_weight_unit', 'kg' );
        switch ( strtolower( $unit ) ) {
            case 'g':  return $value;
            case 'kg': return $value * 1000.0;
            case 'lbs':
            case 'lb': return $value * 453.59237;
            case 'oz': return $value * 28.349523125;
            default:   return $value * 1000.0;
        }
    }

    public function resolve_package_profile( int $total_quantity ): ?array {
        // Base profiles for gemstone packaging (weight in grams, dimensions in cm)
        // These are optimized for Ratna Gems products
        $profiles = array(
            1 => array( 'weight' => 70,  'length' => 16, 'width' => 12, 'height' => 3 ),
            2 => array( 'weight' => 140, 'length' => 24, 'width' => 18, 'height' => 3 ),
            3 => array( 'weight' => 210, 'length' => 24, 'width' => 18, 'height' => 5 ),
            4 => array( 'weight' => 280, 'length' => 24, 'width' => 18, 'height' => 6 ),
            5 => array( 'weight' => 350, 'length' => 30, 'width' => 20, 'height' => 6 ),
            6 => array( 'weight' => 420, 'length' => 30, 'width' => 20, 'height' => 8 ),
            7 => array( 'weight' => 490, 'length' => 30, 'width' => 24, 'height' => 8 ),
            8 => array( 'weight' => 560, 'length' => 30, 'width' => 24, 'height' => 10 ),
            9 => array( 'weight' => 630, 'length' => 36, 'width' => 24, 'height' => 10 ),
            10 => array( 'weight' => 700, 'length' => 36, 'width' => 24, 'height' => 12 ),
        );
        
        // Allow customization via filter
        $profiles = apply_filters( 'rg_delhivery_package_profiles', $profiles, $this );
        
        // Get profile for exact quantity or calculate for higher quantities
        if ( isset( $profiles[ $total_quantity ] ) ) {
            $profile = $profiles[ $total_quantity ];
        } elseif ( $total_quantity > 0 ) {
            // For quantities beyond defined profiles, calculate dynamically
            // Base: ~70g per item, dimensions scale with quantity
            $max_defined = max( array_keys( $profiles ) );
            $base_profile = $profiles[ $max_defined ];
            
            if ( $total_quantity > $max_defined ) {
                // Calculate based on incremental items beyond max defined
                $extra_items = $total_quantity - $max_defined;
                $weight_per_item = 70; // grams per additional item
                
                $profile = array(
                    'weight' => $base_profile['weight'] + ( $extra_items * $weight_per_item ),
                    'length' => min( 60, $base_profile['length'] + ( floor( $extra_items / 5 ) * 6 ) ), // Max 60cm
                    'width'  => min( 40, $base_profile['width'] + ( floor( $extra_items / 5 ) * 4 ) ),  // Max 40cm
                    'height' => min( 30, $base_profile['height'] + ( floor( $extra_items / 3 ) * 2 ) ), // Max 30cm
                );
            } else {
                // Find the nearest defined profile (shouldn't normally happen)
                $nearest = 1;
                foreach ( array_keys( $profiles ) as $qty ) {
                    if ( $qty <= $total_quantity ) $nearest = $qty;
                }
                $profile = $profiles[ $nearest ];
            }
        } else {
            return null;
        }
        
        // Allow per-order customization
        $profile = apply_filters( 'rg_delhivery_package_profile', $profile, $total_quantity, $profiles, $this );
        
        if ( ! is_array( $profile ) ) return null;
        
        // Validate all required fields
        foreach ( array( 'weight', 'length', 'width', 'height' ) as $key ) {
            if ( ! isset( $profile[ $key ] ) ) return null;
            $profile[ $key ] = max( 0.0, (float) $profile[ $key ] );
        }
        
        // Ensure minimum viable dimensions
        if ( $profile['weight'] <= 0 || $profile['length'] <= 0 || $profile['width'] <= 0 || $profile['height'] <= 0 ) {
            return null;
        }
        
        return $profile;
    }
    
    /**
     * Get volumetric weight from package dimensions.
     * Formula: (L × W × H) / 5000 for courier (or /4000 for some carriers)
     *
     * @param array $profile Package profile with length, width, height in cm.
     * @return float Volumetric weight in grams.
     */
    protected function get_volumetric_weight( array $profile ): float {
        $divisor = apply_filters( 'rg_delhivery_volumetric_divisor', 5000 );
        $length = (float) ( $profile['length'] ?? 0 );
        $width  = (float) ( $profile['width'] ?? 0 );
        $height = (float) ( $profile['height'] ?? 0 );
        
        if ( $length <= 0 || $width <= 0 || $height <= 0 ) {
            return 0.0;
        }
        
        // Volumetric weight in kg, convert to grams
        return ( $length * $width * $height ) / $divisor * 1000;
    }
    
    /**
     * Get chargeable weight (higher of actual vs volumetric).
     *
     * @param float $actual_weight Actual weight in grams.
     * @param array $profile       Package profile with dimensions.
     * @return float Chargeable weight in grams.
     */
    protected function get_chargeable_weight( float $actual_weight, array $profile ): float {
        $volumetric = $this->get_volumetric_weight( $profile );
        return max( $actual_weight, $volumetric );
    }

    protected function resolve_state_name( string $country, string $state ): string {
        if ( '' === $state ) return '';
        if ( function_exists( 'WC' ) && WC()->countries ) {
            $states = WC()->countries->get_states( strtoupper( $country ?: 'IN' ) );
            if ( is_array( $states ) && isset( $states[ $state ] ) ) return (string) $states[ $state ];
        }
        return $state;
    }

    protected function truncate_field( string $v, int $limit = 200 ): string {
        if ( function_exists( 'wc_trim_string' ) ) return wc_trim_string( $v, $limit );
        if ( function_exists( 'mb_substr' ) ) return mb_substr( $v, 0, $limit );
        return substr( $v, 0, $limit );
    }

    protected function normalize_pickup_location( string $location ): string {
        $location = trim( (string) $location );
        if ( '' === $location ) return '';
        if ( function_exists( 'wc_clean' ) ) {
            $location = wc_clean( $location );
        } else {
            $location = sanitize_text_field( $location );
        }
        $location = preg_replace( '/\s+/', ' ', (string) $location );
        return $this->truncate_field( (string) $location, 100 );
    }

    protected function normalize_pickup_date( string $date ): string {
        $date = trim( (string) $date );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return $date;
        }
        $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : ( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : date_default_timezone_get() );
        $timezone_obj = null;
        if ( $timezone instanceof \DateTimeZone ) {
            $timezone_obj = $timezone;
        } elseif ( $timezone ) {
            try { $timezone_obj = new \DateTimeZone( (string) $timezone ); } catch ( \Exception $e ) { $timezone_obj = null; }
        }
        if ( '' !== $date ) {
            try {
                $candidate = $timezone_obj ? new \DateTimeImmutable( $date, $timezone_obj ) : new \DateTimeImmutable( $date );
                return $candidate->format( 'Y-m-d' );
            } catch ( \Exception $e ) { /* fall through */ }
        }
        return current_time( 'Y-m-d' );
    }

    protected function normalize_pickup_time( string $time ): string {
        $time = trim( (string) $time );
        if ( preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $time ) ) {
            return $time;
        }
        $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : ( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : date_default_timezone_get() );
        $timezone_obj = null;
        if ( $timezone instanceof \DateTimeZone ) {
            $timezone_obj = $timezone;
        } elseif ( $timezone ) {
            try { $timezone_obj = new \DateTimeZone( (string) $timezone ); } catch ( \Exception $e ) { $timezone_obj = null; }
        }
        if ( '' !== $time ) {
            try {
                $candidate = $timezone_obj ? new \DateTimeImmutable( $time, $timezone_obj ) : new \DateTimeImmutable( $time );
                return $candidate->format( 'H:i:s' );
            } catch ( \Exception $e ) { /* fall through */ }
        }
        return '16:00:00';
    }

    protected function get_payment_mode( WC_Order $order ): string {
        $method = strtolower( (string) $order->get_payment_method() );
        $mode   = in_array( $method, array( 'cod', 'cashondelivery', 'cod_for_dokan', 'cod_for_woo' ), true ) ? 'COD' : 'Prepaid';
        return apply_filters( 'rg_delhivery_payment_mode', $mode, $order );
    }

    // =========================================================================
    // PINCODE SERVICEABILITY API
    // Based on: debug_pincode_serviceability.png
    // Endpoint: GET /c/api/pin-codes/json/?filter_codes=pin_code
    // Rate Limit: 4500 requests/5 min/IP
    // =========================================================================

    /**
     * Check pincode serviceability
     * 
     * @param string $pincode 6-digit pincode to check
     * @return array|WP_Error Response with delivery_codes array or error
     */
    public function pincode_serviceability( string $pincode ) {
        $pincode = preg_replace( '/\D/', '', $pincode );
        if ( strlen( $pincode ) !== 6 ) {
            return new WP_Error( 'delhivery_invalid_pincode', __( 'Please provide a valid 6-digit pincode.', 'ratna-gems' ) );
        }

        $res = $this->request( 'GET', '/c/api/pin-codes/json/', array( 
            'query' => array( 'filter_codes' => $pincode ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $res ) ) return $res;
        if ( 200 !== (int) $res['code'] ) {
            return new WP_Error( 'delhivery_pin_http', sprintf( __( 'Serviceability API error: HTTP %d', 'ratna-gems' ), $res['code'] ) );
        }

        $data = $res['json'] ?? array();
        
        // Parse response - check if pincode is serviceable
        $delivery_codes = $data['delivery_codes'] ?? array();
        $is_serviceable = ! empty( $delivery_codes );
        
        // Check for embargo status
        $has_embargo = false;
        $remarks = array();
        foreach ( $delivery_codes as $dc ) {
            if ( isset( $dc['postal_code']['repl'] ) && 'Embargo' === $dc['postal_code']['repl'] ) {
                $has_embargo = true;
            }
            if ( ! empty( $dc['postal_code']['remarks'] ) ) {
                $remarks[] = $dc['postal_code']['remarks'];
            }
        }

        return array(
            'pincode'        => $pincode,
            'is_serviceable' => $is_serviceable && ! $has_embargo,
            'has_embargo'    => $has_embargo,
            'remarks'        => $remarks,
            'delivery_codes' => $delivery_codes,
            'raw'            => $data,
        );
    }

    /**
     * Check serviceability for heavy product type
     * 
     * @param string $pincode 6-digit pincode
     * @return array|WP_Error
     */
    public function heavy_product_serviceability( string $pincode ) {
        $pincode = preg_replace( '/\D/', '', $pincode );
        if ( strlen( $pincode ) !== 6 ) {
            return new WP_Error( 'delhivery_invalid_pincode', __( 'Please provide a valid 6-digit pincode.', 'ratna-gems' ) );
        }

        // Heavy product serviceability uses a different endpoint parameter
        $res = $this->request( 'GET', '/c/api/pin-codes/json/', array( 
            'query' => array( 
                'filter_codes' => $pincode,
                'product_type' => 'heavy'
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $res ) ) return $res;
        if ( 200 !== (int) $res['code'] ) {
            return new WP_Error( 'delhivery_pin_http', sprintf( __( 'Heavy product serviceability API error: HTTP %d', 'ratna-gems' ), $res['code'] ) );
        }

        return $res['json'] ?? array();
    }

    // =========================================================================
    // CALCULATE SHIPPING COST API
    // Based on: debug_calculate_shipping_cost.png
    // Endpoint: GET /api/kinko/v1/invoice/charges/.json
    // Parameters: md (E/S), cgm (weight grams), o_pin, d_pin, ss, pt
    // =========================================================================

    /**
     * Calculate estimated shipping cost
     * 
     * @param array $params {
     *     @type string $md      Billing mode - 'E' for Express, 'S' for Surface (required)
     *     @type int    $cgm     Chargeable weight in grams (required, default 0)
     *     @type string $o_pin   Origin pincode - 6 digit (required)
     *     @type string $d_pin   Destination pincode - 6 digit (required)
     *     @type string $ss      Status - 'Delivered', 'RTO', 'DTO' (required)
     *     @type string $pt      Payment type - 'Pre-paid', 'COD' (required)
     * }
     * @return array|WP_Error Shipping cost details or error
     */
    public function calculate_shipping_cost( array $params ) {
        $required = array( 'md', 'cgm', 'o_pin', 'd_pin', 'ss', 'pt' );
        foreach ( $required as $field ) {
            if ( ! isset( $params[ $field ] ) || '' === $params[ $field ] ) {
                return new WP_Error( 'delhivery_missing_param', sprintf( __( 'Missing required parameter: %s', 'ratna-gems' ), $field ) );
            }
        }

        // Validate parameters based on official documentation
        $md = strtoupper( substr( $params['md'], 0, 1 ) );
        if ( ! in_array( $md, array( 'E', 'S' ), true ) ) {
            return new WP_Error( 'delhivery_invalid_md', __( 'Billing mode (md) must be E (Express) or S (Surface).', 'ratna-gems' ) );
        }

        $cgm = absint( $params['cgm'] );
        $o_pin = preg_replace( '/\D/', '', $params['o_pin'] );
        $d_pin = preg_replace( '/\D/', '', $params['d_pin'] );

        if ( strlen( $o_pin ) !== 6 || strlen( $d_pin ) !== 6 ) {
            return new WP_Error( 'delhivery_invalid_pincode', __( 'Origin and destination pincodes must be 6 digits.', 'ratna-gems' ) );
        }

        // Payment type: Must be exactly "Pre-paid" or "COD" per official docs
        $pt = $params['pt'];
        // Normalize payment type to match API expectations
        if ( strtoupper( $pt ) === 'COD' ) {
            $pt = 'COD';
        } elseif ( stripos( $pt, 'prepaid' ) !== false || stripos( $pt, 'pre-paid' ) !== false ) {
            $pt = 'Pre-paid';
        }

        // Status: Must be "Delivered", "RTO", or "DTO"
        $ss = $params['ss'];
        
        // COD collection amount (optional, for calculating actual COD charge)
        $cod_amount = isset( $params['cod_amount'] ) ? (float) $params['cod_amount'] : 0;

        $query = array(
            'md'    => $md,
            'cgm'   => $cgm,
            'o_pin' => $o_pin,
            'd_pin' => $d_pin,
            'ss'    => $ss,
            'pt'    => $pt,
        );
        
        // Try adding COD amount parameter (undocumented but might work)
        // Delhivery might accept: cod, cv (collection value), or cod_amount
        if ( 'COD' === $pt && $cod_amount > 0 ) {
            $query['cod'] = $cod_amount;
        }

        // Log request for debugging
        $this->log( 'info', 'Calculate shipping cost request', array( 'params' => $query, 'cod_amount' => $cod_amount ) );

        $res = $this->request( 'GET', '/api/kinko/v1/invoice/charges/.json', array(
            'query'   => $query,
            'timeout' => 20,
        ) );

        if ( is_wp_error( $res ) ) return $res;
        if ( 200 !== (int) $res['code'] ) {
            return new WP_Error( 'delhivery_cost_http', sprintf( __( 'Shipping cost API error: HTTP %d', 'ratna-gems' ), $res['code'] ) );
        }

        // Log response for debugging
        $this->log( 'info', 'Calculate shipping cost response', array( 'response' => $res['json'] ) );
        
        $result = array(
            'params' => $query,
            'cost'   => $res['json'] ?? array(),
            'raw'    => $res['json'],
        );
        
        // Calculate actual COD charge if API returned minimum/fixed charge
        // COD formula: MAX(Fixed Minimum, COD Amount × Percentage)
        if ( 'COD' === $pt && $cod_amount > 0 ) {
            $result['cod_amount'] = $cod_amount;
            $result['cod_calculation'] = $this->calculate_actual_cod_charge( $result['cost'], $cod_amount );
        }

        return $result;
    }
    
    /**
     * Calculate actual COD charge based on collection amount
     * 
     * Delhivery COD charging model (typical):
     * - Fixed minimum: ₹40 (varies by client agreement)
     * - Percentage: 1.5% - 2% of COD collection amount
     * - Actual charge: MAX(Fixed, Amount × Percentage)
     * 
     * @param array $api_cost API response cost array
     * @param float $cod_amount COD collection amount
     * @return array COD calculation details
     */
    protected function calculate_actual_cod_charge( $api_cost, float $cod_amount ): array {
        // Get COD settings from options or use defaults
        // These should match your Delhivery rate card
        $cod_fixed = (float) apply_filters( 'rg_delhivery_cod_fixed_charge', 40.0 );  // Minimum fixed charge
        $cod_percent = (float) apply_filters( 'rg_delhivery_cod_percentage', 2.0 );   // Percentage (2%)
        
        // Allow override via constants
        if ( defined( 'DELHIVERY_COD_FIXED' ) ) {
            $cod_fixed = (float) DELHIVERY_COD_FIXED;
        }
        if ( defined( 'DELHIVERY_COD_PERCENT' ) ) {
            $cod_percent = (float) DELHIVERY_COD_PERCENT;
        }
        
        // Calculate percentage-based charge
        $percent_charge = ( $cod_amount * $cod_percent ) / 100;
        
        // Actual COD charge = MAX(Fixed, Percentage)
        $actual_cod_charge = max( $cod_fixed, $percent_charge );
        
        // Get API-returned COD charge for comparison
        $cost_array = is_array( $api_cost ) && isset( $api_cost[0] ) ? $api_cost[0] : $api_cost;
        $api_cod_charge = (float) ( $cost_array['charge_COD'] ?? 0 );
        
        // Use the higher of API charge or calculated charge
        // (API might return a higher minimum based on client agreement)
        $final_cod_charge = max( $api_cod_charge, $actual_cod_charge );
        
        // Calculate difference from API
        $difference = $final_cod_charge - $api_cod_charge;
        
        return array(
            'cod_amount'        => $cod_amount,
            'cod_fixed'         => $cod_fixed,
            'cod_percent'       => $cod_percent,
            'percent_charge'    => round( $percent_charge, 2 ),
            'api_cod_charge'    => $api_cod_charge,
            'calculated_charge' => round( $actual_cod_charge, 2 ),
            'final_cod_charge'  => round( $final_cod_charge, 2 ),
            'difference'        => round( $difference, 2 ),
            'note'              => $difference > 0 
                ? sprintf( __( 'COD charge increased by ₹%.2f based on %.1f%% of ₹%.2f', 'ratna-gems' ), $difference, $cod_percent, $cod_amount )
                : __( 'Using API-returned COD charge', 'ratna-gems' ),
        );
    }

    /**
     * Convenience method to get shipping cost for an order
     * 
     * @param WC_Order|int $order Order object or ID
     * @param string $mode 'E' for Express, 'S' for Surface
     * @return array|WP_Error
     */
    public function calculate_order_shipping_cost( $order, string $mode = 'S' ) {
        $order = $this->normalize_order( $order );
        if ( is_wp_error( $order ) ) return $order;

        // Get origin pincode from store settings
        $o_pin = get_option( 'woocommerce_store_postcode', '' );
        if ( defined( 'DELHIVERY_ORIGIN_PINCODE' ) ) {
            $o_pin = DELHIVERY_ORIGIN_PINCODE;
        }
        $o_pin = $this->get_option_fallback( array( 'rg_delhivery_origin_pincode' ) ) ?: $o_pin;

        // Get destination pincode from order
        $d_pin = $order->get_shipping_postcode() ?: $order->get_billing_postcode();

        // Calculate weight using package profiles (NOT WooCommerce product weight)
        // WooCommerce weight field contains CARAT value for gemstones, not shipping weight
        $total_quantity = 0;
        foreach ( $order->get_items() as $item ) {
            $total_quantity += max( 1, (int) $item->get_quantity() );
        }
        
        // Use package profile for weight (70g per gemstone item)
        $package_profile = $this->resolve_package_profile( $total_quantity );
        if ( $package_profile ) {
            $total_weight_g = (int) $package_profile['weight'];
        } else {
            $total_weight_g = max( 70 * $total_quantity, 70 ); // 70g per item fallback
        }

        // Determine payment type
        $payment_mode = $this->get_payment_mode( $order );
        $pt = 'COD' === $payment_mode ? 'COD' : 'Pre-paid';

        return $this->calculate_shipping_cost( array(
            'md'    => strtoupper( $mode ),
            'cgm'   => (int) $total_weight_g,
            'o_pin' => $o_pin,
            'd_pin' => $d_pin,
            'ss'    => 'Delivered',
            'pt'    => $pt,
        ) );
    }

    // =========================================================================
    // FETCH WAYBILL API
    // Based on: debug_fetch_waybill.png
    // Endpoint: GET /waybill/api/bulk/json/?count=N
    // Rate Limit: 5 requests/5 min/IP, Max 10,000 per request, 50,000 per 5-min window
    // =========================================================================

    /**
     * Fetch waybills in bulk for later use
     * 
     * @param int $count Number of waybills to fetch (max 10,000)
     * @return array|WP_Error Array of waybill numbers or error
     */
    public function fetch_waybills( int $count = 10 ) {
        $count = max( 1, min( 10000, $count ) );

        $res = $this->request( 'GET', '/waybill/api/bulk/json/', array(
            'query' => array( 'count' => $count ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $res ) ) return $res;
        if ( 200 !== (int) $res['code'] ) {
            return new WP_Error( 'delhivery_waybill_http', sprintf( __( 'Fetch waybill API error: HTTP %d', 'ratna-gems' ), $res['code'] ) );
        }

        $data = $res['json'] ?? array();
        $waybills = array();

        // Extract waybills from response
        if ( isset( $data['waybill'] ) ) {
            $waybills = is_array( $data['waybill'] ) ? $data['waybill'] : array( $data['waybill'] );
        } elseif ( isset( $data['waybills'] ) ) {
            $waybills = $data['waybills'];
        }

        return array(
            'waybills' => array_filter( array_map( 'trim', $waybills ) ),
            'count'    => count( $waybills ),
            'raw'      => $data,
        );
    }

    /**
     * Fetch a single waybill
     * 
     * @return array|WP_Error Single waybill or error
     */
    public function fetch_single_waybill() {
        $result = $this->fetch_waybills( 1 );
        if ( is_wp_error( $result ) ) return $result;

        if ( empty( $result['waybills'] ) ) {
            return new WP_Error( 'delhivery_no_waybill', __( 'No waybill returned from Delhivery.', 'ratna-gems' ) );
        }

        return array(
            'waybill' => $result['waybills'][0],
            'raw'     => $result['raw'],
        );
    }

    /**
     * Store pre-fetched waybills for later use
     * 
     * @param int $count Number to fetch and store
     * @return array|WP_Error
     */
    public function prefetch_and_store_waybills( int $count = 100 ) {
        $result = $this->fetch_waybills( $count );
        if ( is_wp_error( $result ) ) return $result;

        $existing = get_option( 'rg_delhivery_waybill_pool', array() );
        if ( ! is_array( $existing ) ) $existing = array();

        $merged = array_unique( array_merge( $existing, $result['waybills'] ) );
        update_option( 'rg_delhivery_waybill_pool', $merged, false );

        return array(
            'fetched'    => count( $result['waybills'] ),
            'total_pool' => count( $merged ),
        );
    }

    /**
     * Get a waybill from the pre-fetched pool
     * 
     * @return string|WP_Error Waybill or error if pool is empty
     */
    public function get_waybill_from_pool() {
        $pool = get_option( 'rg_delhivery_waybill_pool', array() );
        if ( ! is_array( $pool ) || empty( $pool ) ) {
            // Try to fetch more
            $fetch = $this->prefetch_and_store_waybills( 50 );
            if ( is_wp_error( $fetch ) ) return $fetch;
            $pool = get_option( 'rg_delhivery_waybill_pool', array() );
        }

        if ( empty( $pool ) ) {
            return new WP_Error( 'delhivery_pool_empty', __( 'Waybill pool is empty and could not be refilled.', 'ratna-gems' ) );
        }

        $waybill = array_shift( $pool );
        update_option( 'rg_delhivery_waybill_pool', $pool, false );

        return $waybill;
    }

    // =========================================================================
    // SHIPMENT TRACKING API
    // Based on: debug_shipment_tracking_api.png
    // Endpoint: GET /api/v1/packages/json/?waybill=X&ref_ids=Y
    // Rate Limit: 750 requests/5 min/IP
    // =========================================================================

    /**
     * Track shipment by AWB
     * 
     * @param string $awb Waybill number
     * @param string $ref_id Optional order reference ID
     * @return array|WP_Error Tracking data or error
     */
    public function track_shipment( string $awb, string $ref_id = '' ) {
        $awb = $this->sanitize_awb( $awb );
        if ( '' === $awb ) {
            return new WP_Error( 'delhivery_invalid_awb', __( 'A valid AWB is required for tracking.', 'ratna-gems' ) );
        }

        $query = array( 'waybill' => $awb );
        if ( '' !== $ref_id ) {
            $query['ref_ids'] = $this->sanitize_reference( $ref_id );
        }

        $res = $this->request( 'GET', '/api/v1/packages/json/', array(
            'query'       => $query,
            'expect_json' => true,
            'timeout'     => 25,
        ) );

        if ( is_wp_error( $res ) ) return $res;
        if ( 200 !== (int) $res['code'] || empty( $res['json'] ) ) {
            return new WP_Error( 'delhivery_track_http_error', sprintf( __( 'Delhivery returned HTTP %d while tracking.', 'ratna-gems' ), (int) $res['code'] ) );
        }

        return $res['json'];
    }

    /**
     * Track multiple shipments at once (up to 50)
     * 
     * @param array $awbs Array of waybill numbers (max 50)
     * @return array|WP_Error Tracking data or error
     */
    public function track_multiple_shipments( array $awbs ) {
        $awbs = array_slice( array_filter( array_map( array( $this, 'sanitize_awb' ), $awbs ) ), 0, 50 );
        if ( empty( $awbs ) ) {
            return new WP_Error( 'delhivery_no_awbs', __( 'At least one valid AWB is required.', 'ratna-gems' ) );
        }

        $res = $this->request( 'GET', '/api/v1/packages/json/', array(
            'query'       => array( 'waybill' => implode( ',', $awbs ) ),
            'expect_json' => true,
            'timeout'     => 30,
        ) );

        if ( is_wp_error( $res ) ) return $res;
        if ( 200 !== (int) $res['code'] ) {
            return new WP_Error( 'delhivery_track_http_error', sprintf( __( 'Delhivery returned HTTP %d while tracking.', 'ratna-gems' ), (int) $res['code'] ) );
        }

        return $res['json'] ?? array();
    }

    /**
     * Get formatted tracking summary
     * 
     * @param string $awb Waybill number
     * @return array|WP_Error Summary array or error
     */
    public function get_tracking_summary( string $awb ) {
        $data = $this->track_shipment( $awb );
        if ( is_wp_error( $data ) ) return $data;

        $summary = array(
            'awb'              => $awb,
            'status'           => '',
            'status_type'      => '',
            'status_datetime'  => '',
            'status_location'  => '',
            'expected_date'    => '',
            'scans'            => array(),
        );

        // Extract from ShipmentData structure
        if ( isset( $data['ShipmentData'][0]['Shipment'] ) ) {
            $shipment = $data['ShipmentData'][0]['Shipment'];
            
            if ( isset( $shipment['Status'] ) ) {
                $st = $shipment['Status'];
                $summary['status']          = $st['Status'] ?? '';
                $summary['status_type']     = $st['StatusType'] ?? '';
                $summary['status_datetime'] = $st['StatusDateTime'] ?? '';
                $summary['status_location'] = $st['StatusLocation'] ?? '';
            }

            if ( isset( $shipment['ExpectedDeliveryDate'] ) ) {
                $summary['expected_date'] = $shipment['ExpectedDeliveryDate'];
            }

            if ( isset( $shipment['Scans'] ) && is_array( $shipment['Scans'] ) ) {
                foreach ( $shipment['Scans'] as $scan ) {
                    if ( isset( $scan['ScanDetail'] ) ) {
                        $summary['scans'][] = array(
                            'status'    => $scan['ScanDetail']['Scan'] ?? '',
                            'datetime'  => $scan['ScanDetail']['ScanDateTime'] ?? '',
                            'location'  => $scan['ScanDetail']['ScannedLocation'] ?? '',
                            'remarks'   => $scan['ScanDetail']['Instructions'] ?? '',
                        );
                    }
                }
            }
        }

        $summary['raw'] = $data;
        return $summary;
    }

    // =========================================================================
    // SHIPMENT MANIFESTATION API (Create Shipment)
    // Based on: debug_shipment_manifestation_api.png
    // Endpoint: POST /api/cmu/create.json
    // =========================================================================

    /**
     * Build shipment data from WooCommerce order
     * 
     * IMPORTANT: Per Delhivery official documentation:
     * - Order ID must be unique for each manifestation when Delhivery generates waybills
     * - Cancelled AWBs are permanently consumed and cannot be reused
     * - Best practice: Use timestamp-based format for re-manifests
     */
    protected function build_shipment_from_order( WC_Order $order ): array {
        // Generate unique reference ID for Delhivery
        $base_reference = $this->sanitize_reference( $order->get_order_number() );
        $cancelled_awb = $order->get_meta( '_delhivery_cancelled_awb' );
        $manifest_count = (int) $order->get_meta( '_delhivery_manifest_count' );
        
        // Check if this is a re-manifest (previous AWB was cancelled)
        if ( ! empty( $cancelled_awb ) ) {
            // Increment manifest counter for this order
            $manifest_count++;
            $order->update_meta_data( '_delhivery_manifest_count', $manifest_count );
            
            // Use timestamp-based unique reference: ORDER-YYYYMMDD-HHMMSS
            // This ensures Delhivery creates a NEW AWB (cancelled AWBs cannot be reused)
            $timestamp = current_time( 'YmdHis' );
            $reference_id = $base_reference . '-' . $timestamp;
            
            $this->log( 'info', sprintf( 
                'Re-manifesting order %s with new reference %s (cancelled AWB: %s, attempt #%d)', 
                $base_reference, 
                $reference_id, 
                $cancelled_awb,
                $manifest_count
            ) );
            
            // Add order note about re-manifest
            $order->add_order_note( sprintf(
                __( '🔄 Re-manifesting with new reference: %s (Previous AWB %s was cancelled)', 'ratna-gems' ),
                $reference_id,
                $cancelled_awb
            ) );
            
            $order->save();
        } else {
            // First manifest - use order number directly
            $reference_id = $base_reference;
        }
        
        $shipping_name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
        if ( '' === $shipping_name ) {
            $shipping_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        }
        $address_1 = $order->get_shipping_address_1() ?: $order->get_billing_address_1();
        $address_2 = $order->get_shipping_address_2() ?: $order->get_billing_address_2();
        $city      = $order->get_shipping_city()     ?: $order->get_billing_city();
        $state     = $order->get_shipping_state()    ?: $order->get_billing_state();
        $country   = $order->get_shipping_country()  ?: $order->get_billing_country() ?: 'IN';
        $pin       = $order->get_shipping_postcode() ?: $order->get_billing_postcode();

        $items          = array();
        $total_quantity = 0;
        $total_weight_g = 0.0;

        // For gemstones, we DO NOT use WooCommerce product weight field
        // because it contains CARAT value, not shipping weight.
        // Instead, we rely entirely on package profiles (70g per item).
        foreach ( $order->get_items() as $item ) {
            $product  = $item->get_product();
            $quantity = max( 1, (int) $item->get_quantity() );
            $total_quantity += $quantity;
            // NOTE: Removed product weight calculation - gemstone weight field contains carats, not shipping weight
            if ( count( $items ) < 4 ) {
                $items[] = array(
                    'name'  => $product ? $product->get_name() : $item->get_name(),
                    'qty'   => $quantity,
                    'price' => (float) $item->get_total(),
                );
            }
        }

        $package_profile = $this->resolve_package_profile( $total_quantity );
        if ( $package_profile ) {
            // Use package profile weight as base (70g per gemstone item)
            $profile_weight = (float) $package_profile['weight'];
            
            // Calculate chargeable weight (higher of profile vs volumetric)
            // NOTE: We use ONLY profile_weight, not max(total_weight_g, profile_weight)
            // because gemstone products have carat weight in WC weight field, not shipping weight
            $chargeable_weight = $this->get_chargeable_weight( 
                $profile_weight,  // FIXED: Use only profile weight for gemstones
                $package_profile 
            );
            
            // Use the higher of profile weight and volumetric weight
            $total_weight_g = max( $profile_weight, $chargeable_weight );
            
            // Store package profile on order for reference
            $order->update_meta_data( '_delhivery_package_weight', $total_weight_g );
            $order->update_meta_data( '_delhivery_package_length', $package_profile['length'] );
            $order->update_meta_data( '_delhivery_package_width', $package_profile['width'] );
            $order->update_meta_data( '_delhivery_package_height', $package_profile['height'] );
            $order->update_meta_data( '_delhivery_package_qty', $total_quantity );
        } else {
            // Fallback: 70g per item if no profile found
            $total_weight_g = max( 70.0 * $total_quantity, 70.0 );
        }

        // Minimum weight 70g (single gemstone in packaging)
        if ( $total_weight_g <= 0 ) $total_weight_g = 70.0;

        $payment_mode = $this->get_payment_mode( $order );
        $cod_amount   = ( 'COD' === $payment_mode ) ? (float) $order->get_total() : 0.0;

        $products_desc = implode( ', ', wp_list_pluck( $items, 'name' ) );
        $products_desc = $this->truncate_field( $products_desc, 200 );

        $order_date = $order->get_date_created();
        $order_date = $order_date ? gmdate( 'Y-m-d H:i:s', $order_date->getTimestamp() ) : gmdate( 'Y-m-d H:i:s' );

        $address = trim( preg_replace( '/\s+/', ' ', implode( ' ', array_filter( array( $address_1, $address_2 ) ) ) ) );
        $state_name = $this->resolve_state_name( (string) $country, (string) $state );

        $shipment = array(
            'order'         => $reference_id,
            'name'          => $this->truncate_field( $shipping_name ?: $order->get_formatted_billing_full_name(), 100 ),
            'add'           => $this->truncate_field( $address, 200 ),
            'city'          => $this->truncate_field( (string) $city, 50 ),
            'state'         => $this->truncate_field( (string) $state_name, 50 ),
            'country'       => $this->truncate_field( (string) ($country ?: 'India'), 50 ),
            'pin'           => substr( preg_replace( '/\D+/', '', (string) $pin ), 0, 10 ),
            'phone'         => $this->sanitize_phone( $order->get_billing_phone() ?: $order->get_shipping_phone() ),
            'payment_mode'  => $payment_mode,
            'products_desc' => $products_desc,
            'total_amount'  => $this->format_decimal( $order->get_total() ),
            'cod_amount'    => $this->format_decimal( $cod_amount ),
            'quantity'      => (string) max( 1, $total_quantity ),
            'weight'        => $this->format_decimal( $total_weight_g, 0 ),
            'order_date'    => $order_date,
        );

        if ( $package_profile ) {
            $shipment['shipment_length'] = $this->format_decimal( $package_profile['length'], 0 );
            $shipment['shipment_width']  = $this->format_decimal( $package_profile['width'], 0 );
            $shipment['shipment_height'] = $this->format_decimal( $package_profile['height'], 0 );
        }

        if ( $order->get_billing_email() ) $shipment['email'] = sanitize_email( $order->get_billing_email() );

        $shipping_mode = apply_filters( 'rg_delhivery_shipping_mode', 'Surface', $order );
        if ( $shipping_mode ) $shipment['shipping_mode'] = $this->truncate_field( (string) $shipping_mode, 20 );

        foreach ( $this->return_details as $key => $value ) {
            if ( '' === $value ) continue;
            if ( 'return_phone' === $key ) {
                $shipment[ $key ] = $this->sanitize_phone( $value );
            } elseif ( 'return_pin' === $key ) {
                $shipment[ $key ] = substr( preg_replace( '/\D+/', '', $value ), 0, 10 );
            } else {
                $shipment[ $key ] = $this->truncate_field( (string) $value, 200 );
            }
        }
        foreach ( $this->seller_details as $key => $value ) {
            if ( '' !== $value ) $shipment[ $key ] = $this->truncate_field( (string) $value, 200 );
        }

        return apply_filters( 'rg_delhivery_shipment_payload', $shipment, $order, $this );
    }

    /**
     * Create shipments (manifest)
     */
    public function create_shipments( array $shipments, array $overrides = array() ) {
        $shipments = array_values( array_filter( $shipments, 'is_array' ) );
        if ( empty( $shipments ) ) {
            return new WP_Error( 'delhivery_no_shipments', __( 'No shipment data was provided for Delhivery.', 'ratna-gems' ) );
        }

        $payload = array( 'shipments' => $shipments );

        $default_pickup = $this->truncate_field( $this->pickup_location, 100 );
        if ( '' !== $default_pickup ) {
            $payload['pickup_location'] = array( 'name' => $default_pickup );
        }
        if ( isset( $overrides['pickup_location'] ) ) {
            if ( is_array( $overrides['pickup_location'] ) ) {
                $payload['pickup_location'] = array();
                foreach ( $overrides['pickup_location'] as $k => $v ) {
                    if ( '' === $v || ! is_scalar( $v ) ) continue;
                    $payload['pickup_location'][ $k ] = $this->truncate_field( (string) $v, 100 );
                }
            } elseif ( is_string( $overrides['pickup_location'] ) && '' !== $overrides['pickup_location'] ) {
                $payload['pickup_location']['name'] = $this->truncate_field( $overrides['pickup_location'], 100 );
            }
        }
        if ( isset( $payload['pickup_location'] ) ) {
            $payload['pickup_location'] = array_filter( $payload['pickup_location'], 'strlen' );
            if ( empty( $payload['pickup_location'] ) ) unset( $payload['pickup_location'] );
        }

        $payload = apply_filters( 'rg_delhivery_manifest_payload', $payload, $shipments, $this );

        $form_body = array(
            'format' => 'json',
            'data'   => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
        );
        if ( '' !== $this->client_code ) $form_body['client'] = $this->client_code;

        $response = $this->request( 'POST', '/api/cmu/create.json', array(
            'body'        => $form_body,
            'body_format' => 'form',
            'expect_json' => true,
        ) );
        if ( is_wp_error( $response ) ) return $response;

        if ( 200 !== (int) $response['code'] ) {
            $why = $this->extract_error_message( $response );
            $this->log( 'error', 'Delhivery manifest error: ' . $why, array( 'response_code' => $response['code'] ) );
            return new WP_Error( 'delhivery_http_error', sprintf( __( 'Delhivery rejected manifest: %s', 'ratna-gems' ), $why ) );
        }

        $data = $response['json'];
        $packages = $this->normalize_manifest_packages( $data );
        if ( empty( $packages ) ) {
            $errors = $this->extract_manifest_errors( $data );
            if ( ! empty( $errors ) ) {
                return new WP_Error( 'delhivery_manifest_failed', sprintf( __( 'Delhivery rejected the shipment: %s', 'ratna-gems' ), implode( '; ', $errors ) ) );
            }
            return new WP_Error( 'delhivery_invalid_response', __( 'Delhivery response did not include package information.', 'ratna-gems' ) );
        }

        return array( 'packages' => $packages, 'raw' => $data );
    }

    protected function extract_error_message( $response ): string {
        $why = '';
        if ( isset( $response['json'] ) && is_array( $response['json'] ) ) {
            $why = $response['json']['message']
                ?? $response['json']['error']
                ?? ( is_array( $response['json']['errors'] ?? null ) ? reset( $response['json']['errors'] ) : '' )
                ?? ($response['json']['remark'] ?? '');
        }
        if ( ! $why ) $why = wp_trim_words( (string) ($response['body'] ?? ''), 40 );
        return $why;
    }

    protected function normalize_manifest_packages( array $data ): array {
        $candidates = array();
        foreach ( array( 'packages', 'package', 'upload_wbn' ) as $key ) {
            if ( empty( $data[ $key ] ) ) continue;
            $value = $data[ $key ];
            if ( is_array( $value ) ) {
                $is_list = function_exists( 'wp_is_numeric_array' )
                    ? wp_is_numeric_array( $value )
                    : ( array_keys( $value ) === range( 0, count( $value ) - 1 ) );
                if ( $is_list ) $candidates = array_merge( $candidates, $value );
                else $candidates[] = $value;
            }
        }
        $packages = array();
        foreach ( $candidates as $c ) {
            if ( ! is_array( $c ) ) continue;
            $c = array_change_key_case( $c, CASE_LOWER );
            if ( empty( $c['waybill'] ) && ! empty( $c['awb'] ) ) { $c['waybill'] = $c['awb']; unset( $c['awb'] ); }
            if ( ! empty( $c['waybill'] ) ) {
                $packages[] = array(
                    'waybill' => sanitize_text_field( $c['waybill'] ),
                    'status'  => isset( $c['status'] ) ? sanitize_text_field( (string) $c['status'] ) : '',
                    'remarks' => isset( $c['remarks'] ) ? sanitize_text_field( (string) $c['remarks'] ) : '',
                );
            }
        }
        return array_values( $packages );
    }

    protected function extract_manifest_errors( array $data ): array {
        $messages   = array();
        $normalized = array();
        foreach ( $data as $k => $v ) $normalized[ strtolower( (string) $k ) ] = $v;

        foreach ( array( 'error','errors','message','messages','remarks','remark','status_text','statusmessage','status_message' ) as $k ) {
            if ( empty( $normalized[ $k ] ) ) continue;
            $val = $normalized[ $k ];
            if ( is_string( $val ) ) $messages[] = $val;
            elseif ( is_array( $val ) ) {
                array_walk_recursive( $val, function( $item ) use ( &$messages ) {
                    if ( is_string( $item ) ) $messages[] = $item;
                } );
            }
        }
        if ( isset( $normalized['success'] ) && false === filter_var( $normalized['success'], FILTER_VALIDATE_BOOLEAN ) && empty( $messages ) ) {
            $messages[] = __( 'Delhivery marked the request as unsuccessful.', 'ratna-gems' );
        }
        $messages = array_map( static function( $m ) { return trim( wp_strip_all_tags( (string) $m ) ); }, $messages );
        $messages = array_filter( array_unique( $messages ) );
        return array_values( $messages );
    }

    /**
     * Manifest a single order
     */
    public function manifest_order( $order ) {
        $order = $this->normalize_order( $order );
        if ( is_wp_error( $order ) ) return $order;
        $shipment = $this->build_shipment_from_order( $order );
        $result   = $this->create_shipments( array( $shipment ) );
        if ( is_wp_error( $result ) ) return $result;

        $packages = $result['packages'];
        if ( empty( $packages[0]['waybill'] ) ) {
            $this->log( 'error', 'Delhivery manifest response missing waybill.', array( 'response' => $result['raw'] ) );
            return new WP_Error( 'delhivery_no_awb', __( 'Delhivery did not return an AWB number.', 'ratna-gems' ) );
        }
        return array(
            'awb'    => sanitize_text_field( $packages[0]['waybill'] ),
            'status' => isset( $packages[0]['status'] ) ? sanitize_text_field( $packages[0]['status'] ) : '',
            'raw'    => $result['raw'],
        );
    }

    // =========================================================================
    // SHIPMENT UPDATION/EDIT API
    // Based on: Official Delhivery B2C API Documentation
    // Endpoint: POST /api/p/edit
    //
    // Edit Allowed Status (Forward COD/Prepaid):
    //   - Manifested
    //   - In Transit
    //   - Pending
    //
    // Edit Allowed Status (RVP/Pickup):
    //   - Scheduled
    //
    // Edit NOT Allowed:
    //   - Dispatched (Out for Delivery)
    //   - Delivered, DTO, RTO, LOST, Closed (Terminal states)
    //
    // Payment Mode Conversion Rules:
    //   ✅ COD → Prepaid: Allowed
    //   ✅ Prepaid → COD: Allowed (COD amount required)
    //   ❌ Prepaid → Prepaid: Not allowed
    //   ❌ COD → COD: Not allowed
    //   ❌ Pickup ↔ COD/Prepaid: Not allowed
    //   ❌ REPL ↔ COD/Prepaid: Not allowed
    // =========================================================================

    /**
     * Update shipment details
     * 
     * @param string $awb Waybill number
     * @param array $updates Fields to update:
     *   - name: Consignee name
     *   - phone: Consignee phone
     *   - add: Consignee address
     *   - products_desc: Product description
     *   - weight: Weight in grams
     *   - shipment_height/width/length: Dimensions in cm
     *   - pt: Payment type (for conversion)
     *   - cod: COD amount (required when converting to COD)
     * @return array|WP_Error
     */
    public function update_shipment( string $awb, array $updates ) {
        $awb = $this->sanitize_awb( $awb );
        if ( '' === $awb ) {
            return new WP_Error( 'delhivery_invalid_awb', __( 'A valid AWB is required to update the shipment.', 'ratna-gems' ) );
        }

        // Allowed fields for update per official documentation
        $allowed_fields = array(
            'name',              // Consignee name
            'phone',             // Consignee phone
            'add',               // Consignee address
            'products_desc',     // Product description
            'weight',            // Weight (grams)
            'shipment_height',   // Height (cm)
            'shipment_width',    // Width (cm)
            'shipment_length',   // Length (cm)
            'pt',                // Payment type
            'cod',               // COD amount
            'gst_number',        // GST number
            'return_add',        // Return address
            'return_pin',        // Return pincode
            'return_city',       // Return city
            'return_state',      // Return state
            'return_country',    // Return country
            'return_name',       // Return name
            'return_phone',      // Return phone
        );

        $payload = array( 'waybill' => $awb );
        foreach ( $updates as $key => $value ) {
            if ( in_array( $key, $allowed_fields, true ) && '' !== $value && null !== $value ) {
                // Sanitize phone numbers
                if ( in_array( $key, array( 'phone', 'return_phone' ), true ) ) {
                    $payload[ $key ] = $this->sanitize_phone( $value );
                } 
                // Sanitize numeric values
                elseif ( in_array( $key, array( 'weight', 'shipment_height', 'shipment_width', 'shipment_length', 'cod' ), true ) ) {
                    $payload[ $key ] = $this->format_decimal( (float) $value, $key === 'weight' ? 0 : 2 );
                }
                // Sanitize text fields
                else {
                    $payload[ $key ] = $this->truncate_field( sanitize_text_field( (string) $value ), 200 );
                }
            }
        }

        $this->log( 'info', sprintf( 'Updating shipment %s', $awb ), array( 'updates' => $payload ) );

        $res = $this->request( 'POST', '/api/p/edit', array(
            'body' => $payload,
            'expect_json' => true,
        ) );

        if ( is_wp_error( $res ) ) return $res;
        if ( 200 !== (int) $res['code'] ) {
            $error_msg = $this->extract_error_message( $res );
            return new WP_Error( 'delhivery_update_http', sprintf( __( 'Shipment update failed: %s', 'ratna-gems' ), $error_msg ) );
        }

        return array(
            'success' => true,
            'awb'     => $awb,
            'raw'     => $res['json'] ?? array(),
        );
    }

    /**
     * Convert payment mode (COD to Prepaid or vice versa)
     * 
     * Per official documentation:
     * ✅ COD → Prepaid: Allowed
     * ✅ Prepaid → COD: Allowed (COD amount required)
     * ❌ Same mode conversions: Not allowed
     * ❌ Pickup ↔ COD/Prepaid: Not allowed
     * 
     * @param string $awb Waybill number
     * @param string $new_mode 'COD' or 'Prepaid'
     * @param float $cod_amount Required when converting to COD
     * @return array|WP_Error
     */
    public function convert_payment_mode( string $awb, string $new_mode, float $cod_amount = 0.0 ) {
        $awb = $this->sanitize_awb( $awb );
        $new_mode = strtoupper( trim( $new_mode ) );

        // Normalize mode names
        if ( in_array( $new_mode, array( 'PREPAID', 'PRE-PAID' ), true ) ) {
            $new_mode = 'PREPAID';
        }

        if ( ! in_array( $new_mode, array( 'COD', 'PREPAID' ), true ) ) {
            return new WP_Error( 'delhivery_invalid_mode', __( 'Payment mode must be COD or Prepaid.', 'ratna-gems' ) );
        }

        // For COD conversion, amount is required
        if ( 'COD' === $new_mode && $cod_amount <= 0 ) {
            return new WP_Error( 'delhivery_cod_amount_required', __( 'COD amount is required when converting to COD.', 'ratna-gems' ) );
        }

        // Build update payload
        $updates = array(
            'pt' => 'COD' === $new_mode ? 'COD' : 'Pre-paid',
        );

        if ( 'COD' === $new_mode ) {
            $updates['cod'] = $this->format_decimal( $cod_amount );
        } else {
            // When converting to Prepaid, set COD to 0
            $updates['cod'] = '0';
        }

        $this->log( 'info', sprintf( 'Converting payment mode for %s to %s', $awb, $new_mode ) );

        return $this->update_shipment( $awb, $updates );
    }

    // =========================================================================
    // SHIPMENT CANCELLATION API
    // Based on: Official Delhivery B2C API Documentation
    // Endpoint: POST /api/p/edit
    //
    // Cancellation Allowed Status:
    //   Forward (COD/Prepaid): Manifested, In Transit, Pending
    //   RVP (Pickup): Scheduled
    //   REPL: Manifested, In Transit, Pending
    //
    // Cancellation NOT Allowed:
    //   - Dispatched (Out for Delivery)
    //   - Terminal: Delivered, DTO, RTO, LOST, Closed
    //
    // Post-Cancellation Status:
    //   - Manifested (before pickup) → Status: Manifested, StatusType: UD (full refund)
    //   - In Transit/Pending → Status: In Transit, StatusType: RT (RTO initiated)
    //   - Scheduled (RVP) → Status: Canceled, StatusType: CN
    //
    // ⚠️ IMPORTANT: Cancelled AWBs are PERMANENTLY CONSUMED and cannot be reused!
    //    Re-manifest requires new order reference to generate new AWB.
    // =========================================================================

    /**
     * Cancel a shipment
     * 
     * @param string $awb Waybill number
     * @return array|WP_Error Returns success with cancellation details or error
     * 
     * Note: After cancellation, the AWB is permanently consumed.
     * To re-manifest the same order, a new unique order reference must be used.
     */
    public function cancel_shipment( string $awb ) {
        $awb = $this->sanitize_awb( $awb );
        if ( '' === $awb ) {
            return new WP_Error( 'delhivery_invalid_awb', __( 'A valid AWB is required to cancel the shipment.', 'ratna-gems' ) );
        }

        $this->log( 'info', sprintf( 'Cancelling shipment AWB: %s', $awb ) );

        $payload = array( 
            'waybill'      => $awb, 
            'cancellation' => 'true' 
        );

        $response = $this->request( 'POST', '/api/p/edit', array( 'body' => $payload ) );
        if ( is_wp_error( $response ) ) return $response;

        if ( 200 !== (int) $response['code'] ) {
            $error_msg = $this->extract_error_message( $response );
            $this->log( 'error', sprintf( 'Cancellation failed for AWB %s: %s', $awb, $error_msg ) );
            return new WP_Error( 'delhivery_cancellation_http_error', sprintf( __( 'Cancellation failed: %s', 'ratna-gems' ), $error_msg ) );
        }

        $data   = $response['json'];
        $status = $data['status'] ?? $data['success'] ?? false;
        
        if ( empty( $status ) ) {
            $errors = $this->extract_manifest_errors( $data );
            if ( ! empty( $errors ) ) {
                $this->log( 'error', sprintf( 'Cancellation rejected for AWB %s: %s', $awb, implode( '; ', $errors ) ) );
                return new WP_Error( 'delhivery_cancellation_failed', implode( '; ', $errors ) );
            }
            return new WP_Error( 'delhivery_cancellation_failed', __( 'Delhivery did not confirm the cancellation.', 'ratna-gems' ) );
        }

        $this->log( 'info', sprintf( 'Successfully cancelled AWB: %s', $awb ) );

        return array( 
            'cancelled' => (bool) $status, 
            'awb'       => $awb,
            'raw'       => $data,
            'note'      => __( 'AWB is now permanently consumed. Use a new order reference to re-manifest.', 'ratna-gems' ),
        );
    }

    // =========================================================================
    // E-WAYBILL UPDATE API
    // Based on: debug_ewaybill_management.png
    // Endpoint: PUT /api/rest/ewaybill/{waybill}/
    // =========================================================================

    /**
     * Update e-waybill for a shipment
     * Required for shipments with value > 50k INR as per Indian law
     * 
     * @param string $awb Waybill number (invoice number in Delhivery)
     * @param string $ewbn E-waybill number to attach
     * @return array|WP_Error
     */
    public function update_ewaybill( string $awb, string $ewbn ) {
        $awb = $this->sanitize_awb( $awb );
        $ewbn = preg_replace( '/[^A-Za-z0-9]/', '', $ewbn );

        if ( '' === $awb ) {
            return new WP_Error( 'delhivery_invalid_awb', __( 'A valid invoice/waybill number is required.', 'ratna-gems' ) );
        }
        if ( '' === $ewbn ) {
            return new WP_Error( 'delhivery_invalid_ewbn', __( 'A valid e-waybill number is required.', 'ratna-gems' ) );
        }

        $payload = array(
            'data' => array(
                array(
                    'dcn'  => $awb,  // Invoice number
                    'ewbn' => $ewbn, // E-waybill number
                )
            )
        );

        // The endpoint uses {waybill} placeholder
        $res = $this->request( 'PUT', "/api/rest/ewaybill/{$awb}/", array(
            'body' => $payload,
            'expect_json' => true,
        ) );

        if ( is_wp_error( $res ) ) return $res;
        if ( ! in_array( (int) $res['code'], array( 200, 201 ), true ) ) {
            return new WP_Error( 'delhivery_ewaybill_http', sprintf( __( 'E-waybill update API error: HTTP %d', 'ratna-gems' ), $res['code'] ) );
        }

        return array(
            'success' => true,
            'awb'     => $awb,
            'ewbn'    => $ewbn,
            'raw'     => $res['json'] ?? array(),
        );
    }

    // =========================================================================
    // PICKUP REQUEST API
    // Based on: debug_pickup_request_creation.png
    // Endpoint: POST /fm/request/new/
    // =========================================================================

    /**
     * Schedule a pickup request
     */
    public function schedule_pickup( array $orders = array(), array $overrides = array() ) {
        $orders = array_filter( array_map( function( $order ) {
            if ( $order instanceof WC_Order ) return $order;
            $resolved = wc_get_order( $order );
            return $resolved instanceof WC_Order ? $resolved : null;
        }, $orders ) );

        $package_count = isset( $overrides['expected_package_count'] )
            ? max( 1, absint( $overrides['expected_package_count'] ) )
            : max( 1, count( $orders ) );

        $default_date = current_time( 'Y-m-d' );
        $pickup_date_raw = $overrides['pickup_date'] ?? $default_date;
        $pickup_time_raw = $overrides['pickup_time'] ?? '16:00:00';

        $pickup_date_raw = apply_filters( 'rg_delhivery_pickup_date', $pickup_date_raw, $orders );
        $pickup_time_raw = apply_filters( 'rg_delhivery_pickup_time', $pickup_time_raw, $orders );

        $pickup_date = $this->normalize_pickup_date( (string) $pickup_date_raw );
        $pickup_time = $this->normalize_pickup_time( (string) $pickup_time_raw );
        $pickup_location = $this->normalize_pickup_location( $this->pickup_location );

        if ( '' === $pickup_location ) {
            return new WP_Error( 'delhivery_pickup_location_missing', __( 'Delhivery pickup location is not configured.', 'ratna-gems' ) );
        }

        $payload = array(
            'pickup_time'            => $pickup_time,
            'pickup_date'            => $pickup_date,
            'pickup_location'        => $pickup_location,
            'expected_package_count' => $package_count,
        );

        $response = $this->request( 'POST', '/fm/request/new/', array( 
            'body' => $payload, 
            'body_format' => 'json' 
        ) );

        if ( is_wp_error( $response ) ) return $response;

        $code = (int) $response['code'];
        if ( $code < 200 || $code >= 300 ) {
            $why = $this->extract_error_message( $response );
            $msg = $why
                ? sprintf( __( 'Delhivery rejected pickup: %s', 'ratna-gems' ), $why )
                : sprintf( __( 'Delhivery returned HTTP %d while scheduling pickup.', 'ratna-gems' ), $code );
            $this->log( 'error', $msg, array( 'response_code' => $code ) );
            return new WP_Error( 'delhivery_pickup_http_error', $msg );
        }

        $data = $response['json'];
        if ( empty( $data['pickup_id'] ) ) {
            return new WP_Error( 'delhivery_pickup_failed', __( 'Delhivery did not confirm the pickup request.', 'ratna-gems' ) );
        }

        return array(
            'pickup_id'     => sanitize_text_field( (string) $data['pickup_id'] ),
            'package_count' => (int) $package_count,
            'pickup_date'   => $pickup_date,
            'pickup_time'   => $pickup_time,
            'raw'           => $data,
        );
    }

    /**
     * Smart pickup scheduling with retry logic
     */
    public function schedule_pickup_smart( array $orders = array(), array $overrides = array() ) {
        $now_wp      = current_time( 'timestamp' );
        $today       = wp_date( 'Y-m-d', $now_wp );
        $hour_local  = (int) wp_date( 'G', $now_wp );

        $first_try_date = $overrides['pickup_date'] ?? ( $hour_local >= 19 ? wp_date( 'Y-m-d', strtotime( '+1 day', $now_wp ) ) : $today );
        $first_try_time = $overrides['pickup_time'] ?? ( $hour_local >= 19 ? '11:00:00' : '16:00:00' );

        $result = $this->schedule_pickup( $orders, array(
            'pickup_date'            => $first_try_date,
            'pickup_time'            => $first_try_time,
            'expected_package_count' => $overrides['expected_package_count'] ?? max( 1, count( $orders ) ),
        ) );

        if ( ! is_wp_error( $result ) ) {
            $this->cache_pickup_id( $result['pickup_id'], $first_try_date );
            return $result;
        }

        $today_cached = $this->get_cached_pickup_id( $today );
        if ( $today_cached ) {
            return array( 
                'pickup_id' => $today_cached, 
                'package_count' => $overrides['expected_package_count'] ?? max( 1, count( $orders ) ), 
                'raw' => array( 'reused' => true ) 
            );
        }

        $tomorrow = wp_date( 'Y-m-d', strtotime( '+1 day', $now_wp ) );
        if ( $first_try_date !== $tomorrow ) {
            $retry = $this->schedule_pickup( $orders, array( 
                'pickup_date' => $tomorrow, 
                'pickup_time' => '11:00:00', 
                'expected_package_count' => $overrides['expected_package_count'] ?? max( 1, count( $orders ) ) 
            ) );
            if ( ! is_wp_error( $retry ) ) {
                $this->cache_pickup_id( $retry['pickup_id'], $tomorrow );
                return $retry;
            }
        }

        return $result;
    }

    protected function cache_pickup_id( string $pickup_id, string $pickup_date ): void {
        $key = 'rg_delhivery_pickup_' . sanitize_key( $pickup_date . '_' . $this->pickup_location );
        update_option( $key, $pickup_id, false );
    }

    protected function get_cached_pickup_id( string $pickup_date ): string {
        $key = 'rg_delhivery_pickup_' . sanitize_key( $pickup_date . '_' . $this->pickup_location );
        $val = get_option( $key, '' );
        return is_string( $val ) ? $val : '';
    }

    // =========================================================================
    // WAREHOUSE MANAGEMENT APIs
    // Based on: debug_client_warehouse_creation.png & debug_client_warehouse_updation.png
    // =========================================================================

    /**
     * Create a new warehouse/pickup location
     * 
     * @param array $warehouse_data {
     *     @type string $name           Warehouse name (required, case-sensitive)
     *     @type string $phone          POC phone number (required)
     *     @type string $pin            Pincode (required)
     *     @type string $address        Complete address (optional but recommended)
     *     @type string $city           City (optional)
     *     @type string $country        Country (optional)
     *     @type string $email          POC email (optional)
     *     @type string $registered_name Registered account name (optional)
     *     @type string $return_address Return address (optional)
     *     @type string $return_pin     Return pincode (optional)
     *     @type string $return_city    Return city (optional)
     *     @type string $return_state   Return state (optional)
     *     @type string $return_country Return country (optional)
     * }
     * @return array|WP_Error
     */
    public function create_warehouse( array $warehouse_data ) {
        $required = array( 'name', 'phone', 'pin' );
        foreach ( $required as $field ) {
            if ( empty( $warehouse_data[ $field ] ) ) {
                return new WP_Error( 'delhivery_missing_field', sprintf( __( 'Missing required field: %s', 'ratna-gems' ), $field ) );
            }
        }

        $payload = array(
            'name'  => sanitize_text_field( $warehouse_data['name'] ),
            'phone' => $this->sanitize_phone( $warehouse_data['phone'] ),
            'pin'   => preg_replace( '/\D/', '', $warehouse_data['pin'] ),
        );

        // Optional fields
        $optional_fields = array(
            'registered_name', 'email', 'address', 'city', 'country',
            'return_address', 'return_pin', 'return_city', 'return_state', 'return_country'
        );
        foreach ( $optional_fields as $field ) {
            if ( ! empty( $warehouse_data[ $field ] ) ) {
                $payload[ $field ] = sanitize_text_field( $warehouse_data[ $field ] );
            }
        }

        $res = $this->request( 'PUT', '/api/backend/clientwarehouse/create/', array(
            'body' => $payload,
            'expect_json' => true,
        ) );

        if ( is_wp_error( $res ) ) return $res;
        if ( ! in_array( (int) $res['code'], array( 200, 201 ), true ) ) {
            $why = $this->extract_error_message( $res );
            return new WP_Error( 'delhivery_warehouse_http', sprintf( __( 'Warehouse creation failed: %s', 'ratna-gems' ), $why ?: 'HTTP ' . $res['code'] ) );
        }

        return array(
            'success' => true,
            'warehouse_name' => $payload['name'],
            'raw' => $res['json'] ?? array(),
        );
    }

    /**
     * Update an existing warehouse
     * Note: Warehouse name cannot be changed
     * 
     * @param string $warehouse_name Existing warehouse name (required)
     * @param array $updates Fields to update
     * @return array|WP_Error
     */
    public function update_warehouse( string $warehouse_name, array $updates ) {
        if ( '' === $warehouse_name ) {
            return new WP_Error( 'delhivery_missing_name', __( 'Warehouse name is required.', 'ratna-gems' ) );
        }

        $payload = array( 'name' => sanitize_text_field( $warehouse_name ) );

        // Allowed update fields based on documentation
        $allowed = array( 'address', 'pin', 'phone' );
        foreach ( $allowed as $field ) {
            if ( isset( $updates[ $field ] ) && '' !== $updates[ $field ] ) {
                if ( 'phone' === $field ) {
                    $payload[ $field ] = $this->sanitize_phone( $updates[ $field ] );
                } elseif ( 'pin' === $field ) {
                    $payload[ $field ] = preg_replace( '/\D/', '', $updates[ $field ] );
                } else {
                    $payload[ $field ] = sanitize_text_field( $updates[ $field ] );
                }
            }
        }

        $res = $this->request( 'POST', '/api/backend/clientwarehouse/edit/', array(
            'body' => $payload,
            'expect_json' => true,
        ) );

        if ( is_wp_error( $res ) ) return $res;
        if ( 200 !== (int) $res['code'] ) {
            $why = $this->extract_error_message( $res );
            return new WP_Error( 'delhivery_warehouse_http', sprintf( __( 'Warehouse update failed: %s', 'ratna-gems' ), $why ?: 'HTTP ' . $res['code'] ) );
        }

        return array(
            'success' => true,
            'warehouse_name' => $warehouse_name,
            'raw' => $res['json'] ?? array(),
        );
    }

    // =========================================================================
    // =========================================================================
    // NDR (Non-Delivery Report) API
    // Based on: Official Delhivery B2C API Documentation
    // Endpoint: POST /api/p/update
    // 
    // Official Action Codes:
    // - RE-ATTEMPT: Schedule another delivery attempt
    // - DEFER_DLV: Schedule delivery for specific future date (max 6 days)
    // - EDIT_DETAILS: Update consignee name, phone, or address
    //
    // Time Limits:
    // - Maximum deferral: 6 days from first pending date
    // - Maximum delivery attempts: 3 attempts before auto-RTO
    // - Address updates: Must remain within same pincode
    // =========================================================================

    /**
     * Apply NDR action to a shipment
     * 
     * @param string $awb Waybill number
     * @param string $action 'RE-ATTEMPT', 'DEFER_DLV', or 'EDIT_DETAILS'
     * @param array $options Action-specific parameters:
     *   - For DEFER_DLV: 'deferred_date' => 'YYYY-MM-DD' (required, max 6 days ahead)
     *   - For EDIT_DETAILS: 'name', 'phone', 'add' (at least one required)
     * @return array|WP_Error
     */
    public function ndr_action( string $awb, string $action, array $options = array() ) {
        $awb = $this->sanitize_awb( $awb );
        $action = strtoupper( trim( $action ) );

        if ( '' === $awb ) {
            return new WP_Error( 'delhivery_invalid_awb', __( 'A valid AWB is required for NDR action.', 'ratna-gems' ) );
        }

        // Validate action type per official documentation
        $valid_actions = array( 'RE-ATTEMPT', 'DEFER_DLV', 'EDIT_DETAILS' );
        if ( ! in_array( $action, $valid_actions, true ) ) {
            return new WP_Error( 
                'delhivery_ndr_invalid', 
                sprintf( __( 'Invalid NDR action. Valid actions: %s', 'ratna-gems' ), implode( ', ', $valid_actions ) )
            );
        }

        // Build data payload per official API format
        $data_item = array(
            'waybill' => $awb,
            'act'     => $action,
        );

        // Handle action-specific data
        if ( 'DEFER_DLV' === $action ) {
            if ( empty( $options['deferred_date'] ) ) {
                return new WP_Error( 'delhivery_ndr_missing_date', __( 'DEFER_DLV requires deferred_date parameter (YYYY-MM-DD format).', 'ratna-gems' ) );
            }
            
            // Validate date is within 6 days (official limit)
            $deferred = strtotime( $options['deferred_date'] );
            $max_date = strtotime( '+6 days' );
            if ( $deferred > $max_date ) {
                return new WP_Error( 'delhivery_ndr_date_limit', __( 'Deferred date cannot be more than 6 days from today.', 'ratna-gems' ) );
            }
            if ( $deferred < strtotime( 'today' ) ) {
                return new WP_Error( 'delhivery_ndr_date_past', __( 'Deferred date cannot be in the past.', 'ratna-gems' ) );
            }
            
            $data_item['action_data'] = array(
                'deferred_date' => date( 'Y-m-d', $deferred ),
            );
        } elseif ( 'EDIT_DETAILS' === $action ) {
            $action_data = array();
            
            if ( ! empty( $options['name'] ) ) {
                $action_data['name'] = $this->truncate_field( sanitize_text_field( $options['name'] ), 100 );
            }
            if ( ! empty( $options['phone'] ) ) {
                $action_data['phone'] = $this->sanitize_phone( $options['phone'] );
            }
            if ( ! empty( $options['add'] ) ) {
                $action_data['add'] = $this->truncate_field( sanitize_text_field( $options['add'] ), 200 );
            }
            
            if ( empty( $action_data ) ) {
                return new WP_Error( 'delhivery_ndr_missing_details', __( 'EDIT_DETAILS requires at least one of: name, phone, add.', 'ratna-gems' ) );
            }
            
            $data_item['action_data'] = $action_data;
        }

        $payload = array( 'data' => array( $data_item ) );

        $this->log( 'info', sprintf( 'Sending NDR action %s for AWB %s', $action, $awb ), array( 'payload' => $payload ) );

        $res = $this->request( 'POST', '/api/p/update', array(
            'body'        => $payload,
            'expect_json' => true,
        ) );

        if ( is_wp_error( $res ) ) return $res;
        if ( 200 !== (int) $res['code'] ) {
            $error_msg = $this->extract_error_message( $res );
            return new WP_Error( 'delhivery_ndr_http_error', sprintf( __( 'NDR action failed: %s', 'ratna-gems' ), $error_msg ) );
        }

        return array(
            'success' => true,
            'action'  => $action,
            'awb'     => $awb,
            'raw'     => $res['json'],
        );
    }

    /**
     * Get NDR status for an AWB
     * 
     * Returns NDR info including which actions are available based on StatusCode
     */
    public function get_ndr_status( string $awb ) {
        // NDR status is typically part of tracking data
        $tracking = $this->track_shipment( $awb );
        if ( is_wp_error( $tracking ) ) return $tracking;

        $ndr_info = array(
            'awb'            => $awb,
            'is_ndr'         => false,
            'status_code'    => '',
            'status'         => '',
            'can_reattempt'  => false,
            'can_defer'      => false,
            'can_edit'       => false,
            'max_defer_date' => date( 'Y-m-d', strtotime( '+6 days' ) ),
            'attempts_count' => 0,
        );

        // Official StatusCodes that allow RE-ATTEMPT (from Delhivery documentation)
        $reattempt_codes = array( 
            'EOD-74',   // Customer not available
            'EOD-15',   // Address incomplete
            'EOD-104',  // Customer refused
            'EOD-43',   // Cash not available
            'EOD-86',   // Customer wants reschedule
            'EOD-11',   // Door locked
            'EOD-16',   // Wrong address
            'EOD-69',   // Customer out of station
            'EOD-6',    // Delivery not attempted
            'ST-108',   // Shipment in pending
        );
        
        // StatusCodes that allow DEFER_DLV (from documentation)
        $defer_codes = array( 
            'EOD-74', 'EOD-15', 'EOD-11', 'EOD-3', 'EOD-16', 'EOD-6', 'ST-108'
        );
        
        // StatusCodes that allow EDIT_DETAILS (pending status required)
        $edit_codes = array(
            'EOD-15',   // Address incomplete
            'EOD-16',   // Wrong address
            'ST-108',   // Shipment in pending
        );

        if ( isset( $tracking['ShipmentData'][0]['Shipment'] ) ) {
            $shipment = $tracking['ShipmentData'][0]['Shipment'];
            $status = $shipment['Status'] ?? array();
            $status_code = $status['StatusCode'] ?? '';
            
            $ndr_info['status_code'] = $status_code;
            $ndr_info['status'] = $status['Status'] ?? '';
            $ndr_info['is_ndr'] = in_array( $status_code, array_merge( $reattempt_codes, $defer_codes ), true );
            $ndr_info['can_reattempt'] = in_array( $status_code, $reattempt_codes, true );
            $ndr_info['can_defer'] = in_array( $status_code, $defer_codes, true );
            $ndr_info['can_edit'] = in_array( $status_code, $edit_codes, true );
            
            // Count delivery attempts from scans
            if ( isset( $shipment['Scans'] ) && is_array( $shipment['Scans'] ) ) {
                foreach ( $shipment['Scans'] as $scan ) {
                    if ( isset( $scan['ScanDetail']['ScanType'] ) && 'UD' === $scan['ScanDetail']['ScanType'] ) {
                        // Count undelivered scans as attempts
                        if ( strpos( $scan['ScanDetail']['StatusCode'] ?? '', 'EOD-' ) === 0 ) {
                            $ndr_info['attempts_count']++;
                        }
                    }
                }
            }
            
            // Max 3 attempts before auto-RTO
            if ( $ndr_info['attempts_count'] >= 3 ) {
                $ndr_info['can_reattempt'] = false;
                $ndr_info['can_defer'] = false;
                $ndr_info['note'] = __( 'Maximum delivery attempts (3) reached. Shipment will be marked for RTO.', 'ratna-gems' );
            }
        }

        $ndr_info['tracking'] = $tracking;
        return $ndr_info;
    }

    // =========================================================================
    // GENERATE SHIPPING LABEL API
    // Based on: debug_generate_shipping_label.png
    // Endpoint: GET /api/p/packing_slip
    // =========================================================================

    /**
     * Download shipping label
     */
    public function download_label( string $awb, array $args = array() ) {
        $awb = $this->sanitize_awb( $awb );
        if ( '' === $awb ) {
            return new WP_Error( 'delhivery_invalid_awb', __( 'A valid AWB is required to download the shipping label.', 'ratna-gems' ) );
        }

        $default_size = defined( 'DELHIVERY_LABEL_SIZE' ) ? strtoupper( DELHIVERY_LABEL_SIZE ) : '4R';
        $pdf_size = isset( $args['pdf_size'] ) ? strtoupper( sanitize_text_field( $args['pdf_size'] ) ) : $default_size;
        $as_pdf = isset( $args['pdf'] ) ? filter_var( $args['pdf'], FILTER_VALIDATE_BOOLEAN ) : true;

        $query = array(
            'wbns'     => $awb,
            'pdf'      => $as_pdf ? 'true' : 'false',
            'pdf_size' => $pdf_size,
        );

        if ( '' !== $this->client_code ) {
            $query['client'] = $this->client_code;
        }

        // Try multiple query variations
        $attempts = array(
            $query,
            array_merge( $query, array( 'format' => 'json' ) ),
            array_merge( array( 'waybill' => $awb ), array_diff_key( $query, array( 'wbns' => '' ) ) ),
        );

        $attempts = apply_filters( 'rg_delhivery_label_queries', $attempts, $awb, $this );

        foreach ( $attempts as $q ) {
            $response = $this->request( 'GET', '/api/p/packing_slip', array(
                'query'       => $q,
                'headers'     => array( 'Accept' => 'application/pdf,application/json;q=0.8,*/*;q=0.1' ),
                'expect_json' => false,
                'timeout'     => 30,
            ) );

            if ( is_wp_error( $response ) ) continue;

            $code  = (int) $response['code'];
            $body  = (string) $response['body'];
            $ctype = strtolower( $response['headers']['content-type'] ?? '' );

            // Direct PDF
            if ( ( 200 === $code || 206 === $code ) && false !== strpos( $ctype, 'pdf' ) && strlen( $body ) > 100 ) {
                return array(
                    'body'           => $body,
                    'content_type'   => 'application/pdf',
                    'content_length' => strlen( $body ),
                );
            }

            // Redirect URL
            $location = $response['headers']['location'] ?? $response['headers']['Location'] ?? null;
            if ( $location ) {
                return array( 'redirect_url' => esc_url_raw( (string) $location ) );
            }

            // Try to extract from body
            $parsed = $this->extract_label_from_body( $body );
            if ( is_array( $parsed ) ) return $parsed;
        }

        return new WP_Error( 'delhivery_label_unexpected', __( 'Delhivery did not return a PDF label. Please try again from Delhivery One.', 'ratna-gems' ) );
    }

    protected function extract_label_from_body( string $body ) {
        // Direct PDF
        if ( 0 === strpos( $body, '%PDF' ) && strlen( $body ) > 100 ) {
            return array(
                'body'           => $body,
                'content_type'   => 'application/pdf',
                'content_length' => strlen( $body ),
            );
        }

        // Try JSON with URL or base64
        $json = json_decode( $body, true );
        if ( is_array( $json ) ) {
            $stack = new RecursiveIteratorIterator( new RecursiveArrayIterator( $json ) );
            foreach ( $stack as $value ) {
                if ( ! is_string( $value ) ) continue;

                // Base64 data URL
                if ( preg_match( '/^data:application\\/pdf;base64,(.+)$/i', $value, $m ) ) {
                    $pdf = base64_decode( $m[1], true );
                    if ( $pdf && 0 === strpos( $pdf, '%PDF' ) ) {
                        return array(
                            'body'           => $pdf,
                            'content_type'   => 'application/pdf',
                            'content_length' => strlen( $pdf ),
                        );
                    }
                }

                // Raw base64
                if ( strlen( $value ) > 100 && preg_match( '/^[A-Za-z0-9+\\/=]{100,}$/', $value ) ) {
                    $decoded = base64_decode( $value, true );
                    if ( $decoded && 0 === strpos( $decoded, '%PDF' ) ) {
                        return array(
                            'body'           => $decoded,
                            'content_type'   => 'application/pdf',
                            'content_length' => strlen( $decoded ),
                        );
                    }
                }

                // URL
                if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
                    $pdf = wp_remote_get( $value, array( 'timeout' => 30 ) );
                    if ( ! is_wp_error( $pdf ) && 200 === (int) wp_remote_retrieve_response_code( $pdf ) ) {
                        $pdf_body = (string) wp_remote_retrieve_body( $pdf );
                        $pdf_ct   = strtolower( (string) wp_remote_retrieve_header( $pdf, 'content-type' ) );
                        if ( 0 === strpos( $pdf_body, '%PDF' ) || false !== strpos( $pdf_ct, 'pdf' ) ) {
                            return array(
                                'body'           => $pdf_body,
                                'content_type'   => 'application/pdf',
                                'content_length' => strlen( $pdf_body ),
                            );
                        }
                    }
                    return array( 'redirect_url' => esc_url_raw( $value ) );
                }
            }
        }

        // HTML/text with URL
        if ( preg_match( '/https?:\\/\\/[^\\s"\'<>]+/i', $body, $m ) ) {
            return array( 'redirect_url' => esc_url_raw( $m[0] ) );
        }

        return null;
    }

    // =========================================================================
    // DOWNLOAD DOCUMENT API
    // Based on: debug_download_document_api.png
    // Endpoint: GET /api/rest/fetch/pkg/document/
    // Document types: SIGNATURE_URL, RVP_QC_IMAGE, EPOD, SELLER_RETURN_IMAGE
    // =========================================================================

    /**
     * Download document associated with a shipment
     * 
     * @param string $awb Waybill number
     * @param string $doc_type Document type: SIGNATURE_URL, RVP_QC_IMAGE, EPOD, SELLER_RETURN_IMAGE
     * @return array|WP_Error Document URL/data or error
     */
    public function download_document( string $awb, string $doc_type ) {
        $awb = $this->sanitize_awb( $awb );
        $doc_type = strtoupper( trim( $doc_type ) );

        $valid_types = array( 'SIGNATURE_URL', 'RVP_QC_IMAGE', 'EPOD', 'SELLER_RETURN_IMAGE' );
        if ( ! in_array( $doc_type, $valid_types, true ) ) {
            return new WP_Error( 'delhivery_invalid_doc_type', sprintf( __( 'Invalid document type. Use one of: %s', 'ratna-gems' ), implode( ', ', $valid_types ) ) );
        }

        if ( '' === $awb ) {
            return new WP_Error( 'delhivery_invalid_awb', __( 'A valid waybill is required.', 'ratna-gems' ) );
        }

        $query = array(
            'doc_type' => $doc_type,
            'waybill'  => $awb,
        );

        // Add session cookie if available (some endpoints need it)
        $extra_headers = array();
        $session_id = get_option( 'rg_delhivery_session_id', '' );
        if ( $session_id ) {
            $extra_headers['Cookie'] = 'sessionid=' . $session_id;
        }

        $res = $this->request( 'GET', '/api/rest/fetch/pkg/document/', array(
            'query'       => $query,
            'headers'     => $extra_headers,
            'expect_json' => true,
            'timeout'     => 30,
        ) );

        if ( is_wp_error( $res ) ) return $res;
        if ( 200 !== (int) $res['code'] ) {
            return new WP_Error( 'delhivery_doc_http', sprintf( __( 'Document download API error: HTTP %d', 'ratna-gems' ), $res['code'] ) );
        }

        $data = $res['json'] ?? array();

        // Extract document URL from response
        $doc_url = $data['url'] ?? $data['document_url'] ?? $data['image_url'] ?? null;

        return array(
            'doc_type' => $doc_type,
            'awb'      => $awb,
            'url'      => $doc_url,
            'raw'      => $data,
        );
    }

    /**
     * Get EPOD (Electronic Proof of Delivery) for a shipment
     */
    public function get_epod( string $awb ) {
        return $this->download_document( $awb, 'EPOD' );
    }

    /**
     * Get signature image for a shipment
     */
    public function get_signature( string $awb ) {
        return $this->download_document( $awb, 'SIGNATURE_URL' );
    }

    // =========================================================================
    // RVP QC 3.0 API (Reverse Pickup with Quality Check)
    // Based on: debug_rvp_qc_3_0.png
    // Endpoint: POST /api/cmu/create.json
    // =========================================================================

    /**
     * Create a reverse pickup shipment with QC
     * 
     * @param array $shipment_data Shipment data including QC mapping
     * @return array|WP_Error
     */
    public function create_rvp_qc_shipment( array $shipment_data ) {
        // RVP QC requires specific fields
        $required = array( 'client', 'return_name', 'order', 'weight' );
        foreach ( $required as $field ) {
            if ( empty( $shipment_data[ $field ] ) ) {
                return new WP_Error( 'delhivery_missing_field', sprintf( __( 'RVP QC requires field: %s', 'ratna-gems' ), $field ) );
            }
        }

        // Set payment mode to Pickup for RVP
        $shipment_data['payment_mode'] = 'Pickup';

        // Build the RVP shipment payload
        $payload = array(
            'shipments' => array( $shipment_data ),
            'pickup_location' => array( 'name' => $this->pickup_location ),
        );

        // Add QC question mapping if provided
        if ( ! empty( $shipment_data['qc_mapping'] ) ) {
            $payload['qc_mapping'] = $shipment_data['qc_mapping'];
        }

        return $this->create_shipments( array( $shipment_data ) );
    }

    /**
     * Create RVP shipment from a WooCommerce order (for returns)
     */
    public function create_return_shipment( $order, array $options = array() ) {
        $order = $this->normalize_order( $order );
        if ( is_wp_error( $order ) ) return $order;

        // Build return shipment - customer becomes pickup, seller becomes delivery
        $shipment = array(
            'order'         => 'RVP-' . $this->sanitize_reference( $order->get_order_number() ),
            'payment_mode'  => 'Pickup',
            
            // Pickup from customer (original delivery address)
            'name'          => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) 
                              ?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'add'           => trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() ) 
                              ?: trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
            'city'          => $order->get_shipping_city() ?: $order->get_billing_city(),
            'state'         => $this->resolve_state_name( $order->get_shipping_country() ?: 'IN', $order->get_shipping_state() ?: $order->get_billing_state() ),
            'pin'           => preg_replace( '/\D/', '', $order->get_shipping_postcode() ?: $order->get_billing_postcode() ),
            'phone'         => $this->sanitize_phone( $order->get_billing_phone() ),
            'country'       => $order->get_shipping_country() ?: 'India',
            
            // Return to warehouse
            'return_name'    => $this->seller_details['seller_name'] ?: get_bloginfo( 'name' ),
            'return_add'     => $this->return_details['return_add'],
            'return_city'    => $this->return_details['return_city'],
            'return_state'   => $this->return_details['return_state'],
            'return_pin'     => $this->return_details['return_pin'],
            'return_country' => $this->return_details['return_country'] ?: 'India',
            'return_phone'   => $this->return_details['return_phone'],
        );

        // Calculate weight using package profiles (NOT WooCommerce product weight)
        // WooCommerce weight field contains CARAT value for gemstones, not shipping weight
        $total_quantity = 0;
        foreach ( $order->get_items() as $item ) {
            $total_quantity += max( 1, (int) $item->get_quantity() );
        }
        
        // Use package profile for weight (70g per gemstone item)
        $package_profile = $this->resolve_package_profile( $total_quantity );
        if ( $package_profile ) {
            $total_weight_g = (int) $package_profile['weight'];
        } else {
            $total_weight_g = max( 70 * $total_quantity, 70 ); // 70g per item fallback
        }
        $shipment['weight'] = $total_weight_g;

        // Apply QC options if provided
        if ( ! empty( $options['qc_enabled'] ) ) {
            $shipment['qc'] = 'Y';
            if ( ! empty( $options['qc_mapping'] ) ) {
                $shipment['qc_mapping'] = $options['qc_mapping'];
            }
        }

        $shipment = apply_filters( 'rg_delhivery_return_shipment_payload', $shipment, $order, $options, $this );

        return $this->create_shipments( array( $shipment ) );
    }

    // =========================================================================
    // WEBHOOK FUNCTIONALITY
    // Based on: debug_webhook_functionality.png
    // Note: Webhooks are configured via Delhivery portal, not API
    // =========================================================================

    /**
     * Validate incoming webhook signature
     * 
     * @param string $payload Raw webhook payload
     * @param string $signature Signature from X-Delhivery-Signature header
     * @return bool
     */
    public function validate_webhook_signature( string $payload, string $signature ): bool {
        if ( '' === $this->api_secret ) {
            return true; // No secret configured, skip validation
        }

        $expected = hash_hmac( 'sha256', $payload, $this->api_secret );
        return hash_equals( $expected, $signature );
    }

    /**
     * Parse webhook payload
     * 
     * Official Delhivery webhook format (from documentation lines 1567-1601):
     * {
     *     "Shipment": {
     *         "Status": {
     *             "Status": "Manifested",
     *             "StatusDateTime": "2019-01-09T17:10:42.767",
     *             "StatusType": "UD",
     *             "StatusLocation": "...",
     *             "Instructions": "..."
     *         },
     *         "NSLCode": "X-UCI",
     *         "AWB": "XXXXXXXXXXXX",
     *         "ReferenceNo": "28"
     *     }
     * }
     * 
     * @param string|array $payload Raw JSON payload or already decoded array
     * @return array|WP_Error Parsed data or error
     */
    public function parse_webhook_payload( $payload ) {
        // Handle both string and array input
        if ( is_string( $payload ) ) {
            $data = json_decode( $payload, true );
            if ( JSON_ERROR_NONE !== json_last_error() ) {
                return new WP_Error( 'delhivery_webhook_invalid', __( 'Invalid webhook payload.', 'ratna-gems' ) );
            }
        } else {
            $data = $payload;
        }

        // Extract data from official Delhivery format
        $shipment = $data['Shipment'] ?? $data;
        $status_obj = $shipment['Status'] ?? array();
        
        // Build normalized webhook data
        $webhook_data = array(
            // AWB - try multiple possible field names
            'awb'             => $shipment['AWB'] ?? $shipment['waybill'] ?? $shipment['wbn'] ?? $data['waybill'] ?? $data['awb'] ?? '',
            'reference_no'    => $shipment['ReferenceNo'] ?? $data['ref_id'] ?? '',
            
            // Status fields - Official format uses nested Status object
            'status'          => $status_obj['Status'] ?? $data['status'] ?? '',
            'status_type'     => $status_obj['StatusType'] ?? $data['status_type'] ?? '',
            'status_datetime' => $status_obj['StatusDateTime'] ?? $data['timestamp'] ?? '',
            'status_location' => $status_obj['StatusLocation'] ?? $data['location'] ?? '',
            'instructions'    => $status_obj['Instructions'] ?? $data['instructions'] ?? $data['remarks'] ?? '',
            
            // NSL Code for NDR detection
            'nsl_code'        => $shipment['NSLCode'] ?? $data['nsl_code'] ?? $data['status_code'] ?? '',
            
            // Additional fields
            'pickup_date'     => $shipment['PickUpDate'] ?? '',
            'sortcode'        => $shipment['Sortcode'] ?? '',
            
            // Raw data for debugging
            'raw'             => $data,
        );

        // Detect if this is an NDR situation
        $webhook_data['is_ndr'] = $this->detect_ndr( $webhook_data );

        return $webhook_data;
    }

    /**
     * Detect if webhook data indicates NDR (Non-Delivery Report)
     * 
     * NDR is triggered when:
     * - StatusType = UD (still undelivered)
     * - After a delivery attempt has failed
     * - Instructions contain failure reasons
     * - Or NSLCode starts with EOD (End of Day codes)
     * 
     * @param array $webhook_data Parsed webhook data
     * @return bool True if NDR detected
     */
    protected function detect_ndr( array $webhook_data ): bool {
        $status_type = strtoupper( $webhook_data['status_type'] ?? '' );
        $status = strtoupper( $webhook_data['status'] ?? '' );
        $nsl_code = strtoupper( $webhook_data['nsl_code'] ?? '' );
        $instructions = strtolower( $webhook_data['instructions'] ?? '' );

        // Must be undelivered status type
        if ( 'UD' !== $status_type ) {
            return false;
        }

        // Check for EOD codes (End of Day - indicates failed delivery)
        if ( 0 === strpos( $nsl_code, 'EOD' ) || 0 === strpos( $nsl_code, 'ST-' ) ) {
            return true;
        }

        // Check for NDR-indicating instructions
        $ndr_keywords = array(
            'not available', 'customer refused', 'refused delivery',
            'incomplete address', 'wrong address', 'address incorrect',
            'not contactable', 'phone not reachable', 'no response',
            'shop closed', 'office closed', 'premise closed',
            'delivery failed', 'undelivered', 'could not deliver',
            'reattempt', 're-attempt', 'reschedule',
            'consignee not available', 'door locked', 'nobody at home',
            'security denied', 'gated community', 'entry restricted',
            'cod not ready', 'payment not ready', 'cash not available'
        );

        foreach ( $ndr_keywords as $keyword ) {
            if ( false !== strpos( $instructions, $keyword ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process incoming webhook and update order status
     * 
     * Official Delhivery Status Reference:
     * - Forward: UD (Manifested/Not Picked/In Transit/Pending/Dispatched) -> DL (Delivered)
     * - RTO: RT (In Transit/Pending/Dispatched) -> DL (RTO)
     * - Reverse: PP (Open/Scheduled/Dispatched) -> PU (In Transit/Pending/Dispatched) -> DL (DTO)
     * - Cancelled: CN (Canceled/Closed)
     * 
     * @param array $webhook_data Parsed webhook data
     * @return array|WP_Error Processing result
     */
    public function process_webhook( array $webhook_data ) {
        $awb = $this->sanitize_awb( $webhook_data['awb'] ?? '' );
        if ( '' === $awb ) {
            return new WP_Error( 'delhivery_webhook_no_awb', __( 'Webhook missing AWB.', 'ratna-gems' ) );
        }

        // Find order by AWB
        $orders = wc_get_orders( array(
            'limit'      => 1,
            'meta_key'   => '_delhivery_awb',
            'meta_value' => $awb,
        ) );

        if ( empty( $orders ) ) {
            $this->log( 'info', 'Webhook received for unknown AWB: ' . $awb );
            return array( 'success' => true, 'message' => 'AWB not found in system' );
        }

        $order = $orders[0];
        $old_status = $order->get_meta( '_delhivery_status' );
        $old_status_type = $order->get_meta( '_delhivery_status_type' );
        
        $new_status = $webhook_data['status'] ?? '';
        $new_status_type = $webhook_data['status_type'] ?? '';
        $nsl_code = $webhook_data['nsl_code'] ?? '';
        $instructions = $webhook_data['instructions'] ?? '';
        $is_ndr = $webhook_data['is_ndr'] ?? false;

        // Update order meta with all status information
        $order->update_meta_data( '_delhivery_status', $new_status );
        $order->update_meta_data( '_delhivery_status_type', $new_status_type );
        $order->update_meta_data( '_delhivery_last_update', current_time( 'mysql' ) );
        $order->update_meta_data( '_delhivery_last_location', $webhook_data['status_location'] ?? '' );

        if ( ! empty( $nsl_code ) ) {
            $order->update_meta_data( '_delhivery_nsl_code', $nsl_code );
        }
        if ( ! empty( $instructions ) ) {
            $order->update_meta_data( '_delhivery_last_instructions', $instructions );
        }

        // Build order note with all available information
        $note_parts = array( sprintf( __( 'Delhivery: %s', 'ratna-gems' ), $new_status ) );
        if ( $new_status_type ) {
            $note_parts[0] .= sprintf( ' (StatusType: %s)', $new_status_type );
        }
        if ( $instructions ) {
            $note_parts[] = sprintf( __( 'Details: %s', 'ratna-gems' ), $instructions );
        }
        if ( $webhook_data['status_location'] ?? '' ) {
            $note_parts[] = sprintf( __( 'Location: %s', 'ratna-gems' ), $webhook_data['status_location'] );
        }
        
        $order->add_order_note( implode( "\n", $note_parts ) );

        // Handle NDR - Send email notification immediately
        if ( $is_ndr ) {
            $order->update_meta_data( '_delhivery_is_ndr', 'yes' );
            $order->update_meta_data( '_delhivery_ndr_detected_at', current_time( 'mysql' ) );
            $order->update_meta_data( '_delhivery_ndr_reason', $instructions );
            
            // Send NDR email notification to admin
            $this->send_ndr_email_notification( $order, $webhook_data );
            
            $order->add_order_note( sprintf(
                __( '⚠️ NDR ALERT: Delivery attempt failed. Reason: %s', 'ratna-gems' ),
                $instructions ?: __( 'Not specified', 'ratna-gems' )
            ) );
        } else {
            // Clear NDR flag if shipment moves past NDR state
            if ( in_array( $new_status_type, array( 'DL', 'RT' ), true ) ) {
                $order->delete_meta_data( '_delhivery_is_ndr' );
            }
        }

        // Map Delhivery status to WooCommerce order status
        // =========================================================================
        // Official Delhivery StatusType + Status combinations (from Webhook docs):
        //   UD = Undelivered (forward journey)
        //   DL = Delivered (terminal: Delivered/RTO/DTO)
        //   RT = Return (RTO journey in progress)
        //   PP = Pickup Pending (RVP before pickup)
        //   PU = Picked Up (RVP in transit)
        //   CN = Canceled (RVP cancelled)
        // =========================================================================
        $status_mapping = apply_filters( 'rg_delhivery_webhook_status_mapping', array(
            // Forward shipment delivered (StatusType: DL, Status: Delivered or similar)
            'DL_Delivered'  => 'completed',
            'Delivered'     => 'completed',
            
            // RTO completed (StatusType: DL with Status: RTO)
            'DL_RTO'        => 'cancelled',
            'RTO'           => 'cancelled',
            'Returned'      => 'cancelled',
            'RTO-Returned'  => 'cancelled',
            
            // RTO journey in progress (StatusType: RT) - don't auto-change WC status yet
            // 'RT_In Transit' => keep as-is until RTO completed
            // 'RT_Pending'    => keep as-is until RTO completed
            // 'RT_Dispatched' => keep as-is until RTO completed
            
            // Reverse pickup completed (StatusType: DL, Status: DTO)
            'DL_DTO'        => 'completed',
            'DTO'           => 'completed',
            
            // Reverse pickup cancelled (StatusType: CN)
            'CN_Canceled'   => 'cancelled',
            'CN_Cancelled'  => 'cancelled',
            'Canceled'      => 'cancelled',
            'Cancelled'     => 'cancelled',
            'Closed'        => 'cancelled',
        ), $webhook_data, $order );

        // Try StatusType_Status combination first, then just Status
        $mapping_key = $new_status_type . '_' . $new_status;
        $new_wc_status = $status_mapping[ $mapping_key ] ?? $status_mapping[ $new_status ] ?? null;

        if ( $new_wc_status && $order->get_status() !== $new_wc_status ) {
            $order->update_status( $new_wc_status, __( 'Auto-updated via Delhivery webhook.', 'ratna-gems' ) );
        }

        $order->save();

        do_action( 'rg_delhivery_webhook_processed', $webhook_data, $order );
        
        // Fire specific action for NDR
        if ( $is_ndr ) {
            do_action( 'rg_delhivery_ndr_detected', $order, $webhook_data );
        }

        return array(
            'success'     => true,
            'order_id'    => $order->get_id(),
            'old_status'  => $old_status,
            'new_status'  => $new_status,
            'status_type' => $new_status_type,
            'is_ndr'      => $is_ndr,
        );
    }

    /**
     * Send NDR email notification to admin
     * 
     * @param WC_Order $order The order
     * @param array $webhook_data Parsed webhook data
     */
    protected function send_ndr_email_notification( WC_Order $order, array $webhook_data ): void {
        // Get admin email - use constant if defined, otherwise site admin
        $admin_email = defined( 'RG_DELHIVERY_NDR_EMAIL' ) ? RG_DELHIVERY_NDR_EMAIL : get_option( 'admin_email' );
        
        // Allow filtering the recipient email
        $admin_email = apply_filters( 'rg_delhivery_ndr_email_recipient', $admin_email, $order, $webhook_data );
        
        if ( empty( $admin_email ) || ! is_email( $admin_email ) ) {
            $this->log( 'error', 'NDR email not sent: Invalid admin email' );
            return;
        }

        $awb = $order->get_meta( '_delhivery_awb' );
        $order_id = $order->get_id();
        $customer_name = $order->get_formatted_billing_full_name();
        $customer_phone = $order->get_billing_phone();
        $customer_address = $order->get_formatted_billing_address();
        $ndr_reason = $webhook_data['instructions'] ?? __( 'Not specified', 'ratna-gems' );
        $last_location = $webhook_data['status_location'] ?? __( 'Not specified', 'ratna-gems' );
        $nsl_code = $webhook_data['nsl_code'] ?? '';
        $status_datetime = $webhook_data['status_datetime'] ?? current_time( 'mysql' );

        // Build email subject
        $subject = sprintf(
            __( '⚠️ NDR Alert: Order #%d - Delivery Failed [AWB: %s]', 'ratna-gems' ),
            $order_id,
            $awb
        );

        // Build email body
        $message = sprintf( __( 'NDR (Non-Delivery Report) Alert', 'ratna-gems' ) ) . "\n";
        $message .= str_repeat( '=', 50 ) . "\n\n";
        
        $message .= sprintf( __( 'Order ID: #%d', 'ratna-gems' ), $order_id ) . "\n";
        $message .= sprintf( __( 'AWB Number: %s', 'ratna-gems' ), $awb ) . "\n";
        $message .= sprintf( __( 'Timestamp: %s', 'ratna-gems' ), $status_datetime ) . "\n\n";
        
        $message .= __( 'Customer Information:', 'ratna-gems' ) . "\n";
        $message .= str_repeat( '-', 30 ) . "\n";
        $message .= sprintf( __( 'Name: %s', 'ratna-gems' ), $customer_name ) . "\n";
        $message .= sprintf( __( 'Phone: %s', 'ratna-gems' ), $customer_phone ) . "\n";
        $message .= sprintf( __( 'Address: %s', 'ratna-gems' ), str_replace( '<br/>', ', ', $customer_address ) ) . "\n\n";
        
        $message .= __( 'NDR Details:', 'ratna-gems' ) . "\n";
        $message .= str_repeat( '-', 30 ) . "\n";
        $message .= sprintf( __( 'Reason: %s', 'ratna-gems' ), $ndr_reason ) . "\n";
        $message .= sprintf( __( 'Last Location: %s', 'ratna-gems' ), $last_location ) . "\n";
        if ( $nsl_code ) {
            $message .= sprintf( __( 'NSL Code: %s', 'ratna-gems' ), $nsl_code ) . "\n";
        }
        $message .= "\n";
        
        $message .= __( 'Suggested Actions:', 'ratna-gems' ) . "\n";
        $message .= str_repeat( '-', 30 ) . "\n";
        $message .= "1. " . __( 'Contact customer to confirm availability and address', 'ratna-gems' ) . "\n";
        $message .= "2. " . __( 'Use NDR API to request RE-ATTEMPT or PICKUP_RESCHEDULE', 'ratna-gems' ) . "\n";
        $message .= "3. " . __( 'Update customer details via Shipment Edit API if needed', 'ratna-gems' ) . "\n";
        $message .= "4. " . __( 'If customer unreachable, consider initiating RTO', 'ratna-gems' ) . "\n\n";
        
        // Add direct links
        $order_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
        $message .= __( 'Quick Links:', 'ratna-gems' ) . "\n";
        $message .= str_repeat( '-', 30 ) . "\n";
        $message .= sprintf( __( 'View Order: %s', 'ratna-gems' ), $order_url ) . "\n";
        $message .= sprintf( __( 'Track on Delhivery: https://www.delhivery.com/track/package/%s', 'ratna-gems' ), $awb ) . "\n\n";
        
        $message .= str_repeat( '=', 50 ) . "\n";
        $message .= __( 'This is an automated notification from your Delhivery integration.', 'ratna-gems' ) . "\n";

        // Set email headers
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
        );

        // Send email
        $sent = wp_mail( $admin_email, $subject, $message, $headers );

        if ( $sent ) {
            $this->log( 'info', sprintf( 'NDR email sent to %s for Order #%d', $admin_email, $order_id ) );
            $order->add_order_note( sprintf(
                __( '📧 NDR alert email sent to %s', 'ratna-gems' ),
                $admin_email
            ) );
        } else {
            $this->log( 'error', sprintf( 'Failed to send NDR email for Order #%d', $order_id ) );
        }
    }

} // End class Delhivery_API_Client

} // class_exists

// Legacy alias for backward compatibility
if ( ! class_exists( 'RG_Delhivery_API_Client' ) ) { 
    class RG_Delhivery_API_Client extends Delhivery_API_Client {} 
}

/**
 * Singleton getter
 */
if ( ! function_exists( 'rg_delhivery_client' ) ) {
    function rg_delhivery_client(): Delhivery_API_Client {
        static $client = null;
        if ( null === $client ) $client = new Delhivery_API_Client();
        return $client;
    }
}

// Include CLI commands if WP-CLI is available
if ( defined( 'WP_CLI' ) && WP_CLI && file_exists( __DIR__ . '/cli.php' ) ) { 
    require_once __DIR__ . '/cli.php'; 
}
