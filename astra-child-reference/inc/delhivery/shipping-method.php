<?php
/**
 * WooCommerce Shipping Method for Delhivery.
 * Provides real-time shipping rate calculation and pincode serviceability checking.
 *
 * @package Ratna Gems
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Delhivery Shipping Method Class.
 */
class WC_Shipping_Delhivery extends WC_Shipping_Method {

    /** @var string Express shipping enabled */
    public $express_enabled;
    
    /** @var string Surface shipping enabled */
    public $surface_enabled;
    
    /** @var string Free shipping threshold */
    public $free_shipping;
    
    /** @var string Fallback cost */
    public $fallback_cost;
    
    /** @var string Handling fee */
    public $handling_fee;
    
    /** @var string COD extra charge */
    public $cod_extra;
    
    /** @var string Origin pincode */
    public $origin_pincode;

    /**
     * Constructor.
     *
     * @param int $instance_id Shipping method instance ID.
     */
    public function __construct( int $instance_id = 0 ) {
        $this->id                 = 'delhivery';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'Delhivery', 'ratna-gems' );
        $this->method_description = __( 'Delhivery B2C shipping with real-time rates and pincode serviceability.', 'ratna-gems' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        );

        $this->init();
    }

    /**
     * Initialize settings.
     */
    private function init(): void {
        $this->init_form_fields();
        $this->init_settings();

        $this->title             = $this->get_option( 'title', __( 'Delhivery Shipping', 'ratna-gems' ) );
        $this->enabled           = $this->get_option( 'enabled', 'yes' );
        $this->express_enabled   = $this->get_option( 'express_enabled', 'yes' );
        $this->surface_enabled   = $this->get_option( 'surface_enabled', 'yes' );
        $this->free_shipping     = $this->get_option( 'free_shipping', '' );
        $this->fallback_cost     = $this->get_option( 'fallback_cost', '100' );
        $this->handling_fee      = $this->get_option( 'handling_fee', '0' );
        $this->cod_extra         = $this->get_option( 'cod_extra', '0' );
        $this->origin_pincode    = $this->get_option( 'origin_pincode', defined( 'DELHIVERY_ORIGIN_PINCODE' ) ? DELHIVERY_ORIGIN_PINCODE : '' );

        add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    /**
     * Define settings fields.
     */
    public function init_form_fields(): void {
        $this->instance_form_fields = array(
            'title' => array(
                'title'       => __( 'Method Title', 'ratna-gems' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'ratna-gems' ),
                'default'     => __( 'Delhivery Shipping', 'ratna-gems' ),
                'desc_tip'    => true,
            ),
            'express_enabled' => array(
                'title'       => __( 'Express Shipping', 'ratna-gems' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable Express (Air) shipping', 'ratna-gems' ),
                'default'     => 'yes',
                'description' => __( 'Faster delivery, typically 1-3 days.', 'ratna-gems' ),
            ),
            'surface_enabled' => array(
                'title'       => __( 'Surface Shipping', 'ratna-gems' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable Surface (Ground) shipping', 'ratna-gems' ),
                'default'     => 'yes',
                'description' => __( 'Economical option, typically 4-7 days.', 'ratna-gems' ),
            ),
            'origin_pincode' => array(
                'title'       => __( 'Origin Pincode', 'ratna-gems' ),
                'type'        => 'text',
                'description' => __( 'Your warehouse/pickup pincode for rate calculation.', 'ratna-gems' ),
                'default'     => defined( 'DELHIVERY_RETURN_PIN' ) ? DELHIVERY_RETURN_PIN : ( defined( 'DELHIVERY_ORIGIN_PINCODE' ) ? DELHIVERY_ORIGIN_PINCODE : '' ),
                'desc_tip'    => true,
            ),
            'free_shipping' => array(
                'title'       => __( 'Free Shipping Above', 'ratna-gems' ),
                'type'        => 'price',
                'description' => __( 'Order amount above which shipping is free. Leave empty to disable.', 'ratna-gems' ),
                'default'     => '',
                'desc_tip'    => true,
                'placeholder' => __( 'e.g., 1000', 'ratna-gems' ),
            ),
            'handling_fee' => array(
                'title'       => __( 'Handling Fee', 'ratna-gems' ),
                'type'        => 'price',
                'description' => __( 'Additional fee added to shipping cost.', 'ratna-gems' ),
                'default'     => '0',
                'desc_tip'    => true,
            ),
            'cod_extra' => array(
                'title'       => __( 'COD Extra Charge', 'ratna-gems' ),
                'type'        => 'price',
                'description' => __( 'Extra charge for Cash on Delivery orders.', 'ratna-gems' ),
                'default'     => '0',
                'desc_tip'    => true,
            ),
            'fallback_cost' => array(
                'title'       => __( 'Fallback Cost', 'ratna-gems' ),
                'type'        => 'price',
                'description' => __( 'Default shipping cost when API rate calculation fails.', 'ratna-gems' ),
                'default'     => '100',
                'desc_tip'    => true,
            ),
            'show_eta' => array(
                'title'       => __( 'Show ETA', 'ratna-gems' ),
                'type'        => 'checkbox',
                'label'       => __( 'Display estimated delivery time', 'ratna-gems' ),
                'default'     => 'yes',
            ),
            'check_serviceability' => array(
                'title'       => __( 'Serviceability Check', 'ratna-gems' ),
                'type'        => 'checkbox',
                'label'       => __( 'Check pincode serviceability before showing rates', 'ratna-gems' ),
                'default'     => 'yes',
                'description' => __( 'Hides Delhivery option for non-serviceable pincodes.', 'ratna-gems' ),
            ),
        );
    }

    /**
     * Check if this method is available.
     *
     * @param array $package Shipping package.
     * @return bool
     */
    public function is_available( $package ): bool {
        if ( 'no' === $this->enabled ) {
            return false;
        }

        // Check API configuration
        if ( ! rg_delhivery_is_configured() ) {
            return false;
        }

        // Get destination pincode
        $dest_pincode = $package['destination']['postcode'] ?? '';
        if ( empty( $dest_pincode ) ) {
            return false;
        }

        // Only for India
        $country = $package['destination']['country'] ?? '';
        if ( ! empty( $country ) && $country !== 'IN' ) {
            return false;
        }

        // Check serviceability if enabled
        if ( 'yes' === $this->get_option( 'check_serviceability', 'yes' ) ) {
            $serviceable = $this->check_serviceability( $dest_pincode );
            if ( ! $serviceable ) {
                return false;
            }
        }

        return apply_filters( 'woocommerce_shipping_' . $this->id . '_is_available', true, $package, $this );
    }

    /**
     * Check pincode serviceability (cached).
     *
     * @param string $pincode Destination pincode.
     * @return bool
     */
    private function check_serviceability( string $pincode ): bool {
        $cache_key = 'delhivery_svc_' . $pincode;
        $cached = get_transient( $cache_key );
        
        if ( false !== $cached ) {
            return 'yes' === $cached;
        }

        $client = rg_delhivery_client();
        if ( ! $client ) {
            return true; // Allow if client not available
        }

        $result = $client->pincode_serviceability( $pincode );
        
        if ( is_wp_error( $result ) ) {
            return true; // Allow on error
        }

        $is_serviceable = ! empty( $result['is_serviceable'] ) && empty( $result['has_embargo'] );
        
        // Cache for 1 hour
        set_transient( $cache_key, $is_serviceable ? 'yes' : 'no', HOUR_IN_SECONDS );
        
        return $is_serviceable;
    }

    /**
     * Calculate shipping rates.
     *
     * @param array $package Shipping package.
     */
    public function calculate_shipping( $package = array() ): void {
        $dest_pincode = $package['destination']['postcode'] ?? '';
        
        if ( empty( $dest_pincode ) || empty( $this->origin_pincode ) ) {
            $this->add_fallback_rate( $package );
            return;
        }

        // Check for free shipping
        $cart_total = WC()->cart ? WC()->cart->get_subtotal() : 0;
        if ( ! empty( $this->free_shipping ) && $cart_total >= (float) $this->free_shipping ) {
            $this->add_rate( array(
                'id'       => $this->get_rate_id( 'free' ),
                'label'    => $this->title . ' - ' . __( 'Free Shipping', 'ratna-gems' ),
                'cost'     => 0,
                'package'  => $package,
            ) );
            return;
        }

        // Calculate total weight
        $weight = $this->get_package_weight( $package );
        
        // Check if COD
        $is_cod = WC()->session && WC()->session->get( 'chosen_payment_method' ) === 'cod';

        // Get rates from API
        $this->calculate_api_rates( $dest_pincode, $weight, $is_cod, $package );
    }

    /**
     * Calculate rates from Delhivery API.
     *
     * @param string $dest_pincode Destination pincode.
     * @param int    $weight       Weight in grams.
     * @param bool   $is_cod       Is COD order.
     * @param array  $package      Shipping package.
     */
    private function calculate_api_rates( string $dest_pincode, int $weight, bool $is_cod, array $package ): void {
        $client = rg_delhivery_client();
        
        if ( ! $client ) {
            $this->add_fallback_rate( $package );
            return;
        }

        $payment_type = $is_cod ? 'COD' : 'Pre-paid';
        $handling_fee = (float) $this->handling_fee;
        $cod_extra = $is_cod ? (float) $this->cod_extra : 0;
        $show_eta = 'yes' === $this->get_option( 'show_eta', 'yes' );

        // Express rate
        if ( 'yes' === $this->express_enabled ) {
            $express_result = $client->calculate_shipping_cost( array(
                'o_pin' => $this->origin_pincode,
                'd_pin' => $dest_pincode,
                'cgm'   => $weight,
                'md'    => 'E', // Express
                'pt'    => $payment_type,
                'ss'    => 'Delivered',
            ) );

            if ( ! is_wp_error( $express_result ) && isset( $express_result['cost'] ) ) {
                $cost = (float) ( $express_result['cost']['total_amount'] ?? $express_result['cost']['total'] ?? 0 );
                
                if ( $cost > 0 ) {
                    $cost += $handling_fee + $cod_extra;
                    $label = $this->title . ' - ' . __( 'Express', 'ratna-gems' );
                    
                    if ( $show_eta ) {
                        $label .= ' (' . __( '1-3 days', 'ratna-gems' ) . ')';
                    }

                    $this->add_rate( array(
                        'id'       => $this->get_rate_id( 'express' ),
                        'label'    => $label,
                        'cost'     => $cost,
                        'package'  => $package,
                        'meta_data' => array(
                            'delhivery_mode' => 'E',
                        ),
                    ) );
                }
            }
        }

        // Surface rate
        if ( 'yes' === $this->surface_enabled ) {
            $surface_result = $client->calculate_shipping_cost( array(
                'o_pin' => $this->origin_pincode,
                'd_pin' => $dest_pincode,
                'cgm'   => $weight,
                'md'    => 'S', // Surface
                'pt'    => $payment_type,
                'ss'    => 'Delivered',
            ) );

            if ( ! is_wp_error( $surface_result ) && isset( $surface_result['cost'] ) ) {
                $cost = (float) ( $surface_result['cost']['total_amount'] ?? $surface_result['cost']['total'] ?? 0 );
                
                if ( $cost > 0 ) {
                    $cost += $handling_fee + $cod_extra;
                    $label = $this->title . ' - ' . __( 'Surface', 'ratna-gems' );
                    
                    if ( $show_eta ) {
                        $label .= ' (' . __( '4-7 days', 'ratna-gems' ) . ')';
                    }

                    $this->add_rate( array(
                        'id'       => $this->get_rate_id( 'surface' ),
                        'label'    => $label,
                        'cost'     => $cost,
                        'package'  => $package,
                        'meta_data' => array(
                            'delhivery_mode' => 'S',
                        ),
                    ) );
                }
            }
        }

        // If no rates were added, use fallback
        if ( empty( $this->rates ) ) {
            $this->add_fallback_rate( $package );
        }
    }

    /**
     * Add fallback rate when API fails.
     *
     * @param array $package Shipping package.
     */
    private function add_fallback_rate( array $package ): void {
        $cost = (float) $this->fallback_cost;
        
        if ( $cost <= 0 ) {
            return;
        }

        $this->add_rate( array(
            'id'       => $this->get_rate_id( 'flat' ),
            'label'    => $this->title,
            'cost'     => $cost + (float) $this->handling_fee,
            'package'  => $package,
        ) );
    }

    /**
     * Get total package weight in grams.
     * For gemstones, we use package profiles (70g per item) instead of WooCommerce product weight
     * because the WC weight field contains CARAT value, not shipping weight.
     *
     * @param array $package Shipping package.
     * @return int
     */
    private function get_package_weight( array $package ): int {
        // Count total quantity of items
        $total_quantity = 0;
        foreach ( $package['contents'] as $item ) {
            $total_quantity += (int) $item['quantity'];
        }
        
        // Use package profiles for gemstones (70g per item with packaging)
        // These match the profiles in api-client.php resolve_package_profile()
        $profiles = array(
            1 => 70, 2 => 140, 3 => 210, 4 => 280, 5 => 350,
            6 => 420, 7 => 490, 8 => 560, 9 => 630, 10 => 700,
        );
        
        if ( isset( $profiles[ $total_quantity ] ) ) {
            $weight = $profiles[ $total_quantity ];
        } elseif ( $total_quantity > 10 ) {
            // For quantities beyond defined profiles: base 700g + 70g per extra item
            $weight = 700 + ( ( $total_quantity - 10 ) * 70 );
        } else {
            // Fallback: 70g per item
            $weight = max( 70 * $total_quantity, 70 );
        }

        // No conversion needed - weight is already in grams
        // Minimum weight 70g (single gemstone in packaging)
        return max( 70, (int) $weight );
    }
}

/**
 * Add pincode serviceability check on checkout.
 */
add_action( 'woocommerce_after_checkout_validation', function( $data, $errors ) {
    if ( ! rg_delhivery_is_configured() ) return;
    
    $pincode = $data['shipping_postcode'] ?: $data['billing_postcode'];
    if ( empty( $pincode ) ) return;

    // Only check for Delhivery shipping method
    $chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );
    $is_delhivery = false;
    foreach ( $chosen_methods as $method ) {
        if ( strpos( $method, 'delhivery' ) !== false ) {
            $is_delhivery = true;
            break;
        }
    }
    
    if ( ! $is_delhivery ) return;

    // Check serviceability
    $client = rg_delhivery_client();
    if ( ! $client ) return;

    $result = $client->pincode_serviceability( $pincode );
    
    if ( ! is_wp_error( $result ) ) {
        if ( empty( $result['is_serviceable'] ) ) {
            $errors->add( 'shipping', __( 'Sorry, Delhivery shipping is not available for your pincode.', 'ratna-gems' ) );
        } elseif ( ! empty( $result['has_embargo'] ) ) {
            $errors->add( 'shipping', __( 'Sorry, delivery to your pincode is temporarily suspended.', 'ratna-gems' ) );
        }
    }
}, 10, 2 );

/**
 * Save selected Delhivery mode to order meta.
 */
add_action( 'woocommerce_checkout_create_order', function( $order, $data ) {
    $chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );
    
    foreach ( $chosen_methods as $method ) {
        if ( strpos( $method, 'delhivery' ) !== false ) {
            // Determine mode from method ID
            $mode = 'E'; // Default to Express
            if ( strpos( $method, 'surface' ) !== false ) {
                $mode = 'S';
            }
            $order->update_meta_data( '_delhivery_shipping_mode', $mode );
            break;
        }
    }
}, 10, 2 );

/**
 * Display shipping mode on order details.
 */
add_action( 'woocommerce_admin_order_data_after_shipping_address', function( $order ) {
    $mode = $order->get_meta( '_delhivery_shipping_mode' );
    if ( $mode ) {
        $mode_label = $mode === 'E' ? __( 'Express', 'ratna-gems' ) : __( 'Surface', 'ratna-gems' );
        printf( '<p><strong>%s:</strong> %s</p>', esc_html__( 'Delhivery Mode', 'ratna-gems' ), esc_html( $mode_label ) );
    }
} );
