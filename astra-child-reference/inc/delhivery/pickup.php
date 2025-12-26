<?php
/**
 * Enhanced AJAX handlers for Delhivery operations.
 * Includes: pickup scheduling, tracking, NDR, shipping cost, waybills, warehouses, webhooks.
 *
 * @package Ratna Gems
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =============================================================================
// PICKUP SCHEDULING
// =============================================================================

add_action( 'wp_ajax_rg_schedule_delhivery_pickup', 'rg_delhivery_ajax_schedule_pickup' );
/**
 * AJAX: Schedule pickup for order(s).
 */
function rg_delhivery_ajax_schedule_pickup(): void {
    check_ajax_referer( 'rg_delhivery_nonce', 'security' );

    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ratna-gems' ) ) );
    }

    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    $pickup_date = isset( $_POST['pickup_date'] ) ? sanitize_text_field( wp_unslash( $_POST['pickup_date'] ) ) : '';
    $pickup_time = isset( $_POST['pickup_time'] ) ? sanitize_text_field( wp_unslash( $_POST['pickup_time'] ) ) : '16:00:00';
    $package_count = isset( $_POST['package_count'] ) ? absint( $_POST['package_count'] ) : 1;

    if ( ! $order_id ) {
        wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'ratna-gems' ) ) );
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_send_json_error( array( 'message' => __( 'Order not found.', 'ratna-gems' ) ) );
    }

    // Validate pickup date - default to today or next weekday
    if ( empty( $pickup_date ) ) {
        $pickup_date = wp_date( 'Y-m-d' );
    }

    $client = rg_delhivery_client();
    if ( ! $client || ! $client->is_configured() ) {
        wp_send_json_error( array( 'message' => __( 'Delhivery credentials not configured.', 'ratna-gems' ) ) );
    }

    // Call schedule_pickup with correct parameters
    $result = $client->schedule_pickup( 
        array( $order ), 
        array(
            'pickup_date'            => $pickup_date,
            'pickup_time'            => $pickup_time,
            'expected_package_count' => $package_count,
        )
    );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    // Store pickup info on order
    $order->update_meta_data( '_delhivery_pickup_id', $result['pickup_id'] ?? '' );
    $order->update_meta_data( '_delhivery_pickup_date', $result['pickup_date'] ?? $pickup_date );
    $order->update_meta_data( '_delhivery_pickup_time', $result['pickup_time'] ?? $pickup_time );
    $order->update_meta_data( '_delhivery_pickup_status', 'Scheduled' );
    $order->save();

    $order->add_order_note( sprintf(
        __( '📦 Delhivery pickup scheduled for %s at %s. Pickup ID: %s', 'ratna-gems' ),
        $result['pickup_date'] ?? $pickup_date,
        $result['pickup_time'] ?? $pickup_time,
        $result['pickup_id'] ?? 'N/A'
    ) );

    wp_send_json_success( array(
        'message'   => __( 'Pickup scheduled successfully.', 'ratna-gems' ),
        'pickup_id' => $result['pickup_id'] ?? '',
        'date'      => $result['pickup_date'] ?? $pickup_date,
        'time'      => $result['pickup_time'] ?? $pickup_time,
    ) );
}

// =============================================================================
// AUTO-PICKUP AFTER MANIFEST
// =============================================================================
// Enable by adding to wp-config.php: define( 'DELHIVERY_AUTO_PICKUP', true );

add_action( 'rg_delhivery_order_manifested', 'rg_delhivery_auto_schedule_pickup', 10, 2 );
/**
 * Automatically schedule pickup after successful shipment creation.
 * 
 * Tries today first, if fails tries next days (up to 3 attempts).
 * Never breaks shipment creation.
 * 
 * Enable by adding to wp-config.php:
 *   define( 'DELHIVERY_AUTO_PICKUP', true );
 */
function rg_delhivery_auto_schedule_pickup( WC_Order $order, array $manifest_result ): void {
    // Check if auto-pickup is enabled
    $auto_pickup = defined( 'DELHIVERY_AUTO_PICKUP' ) && DELHIVERY_AUTO_PICKUP;
    if ( ! $auto_pickup ) {
        return;
    }
    
    // Skip if pickup already scheduled
    if ( $order->get_meta( '_delhivery_pickup_id' ) ) {
        return;
    }
    
    $client = rg_delhivery_client();
    if ( ! $client || ! $client->is_configured() ) {
        return;
    }
    
    // Try today, tomorrow, day after (3 attempts)
    $pickup_time = '18:00:00';
    $result = null;
    $pickup_date = null;
    
    for ( $day = 0; $day < 3; $day++ ) {
        $pickup_date = wp_date( 'Y-m-d', strtotime( "+{$day} day" ) );
        
        $result = $client->schedule_pickup(
            array( $order ),
            array(
                'pickup_date'            => $pickup_date,
                'pickup_time'            => $pickup_time,
                'expected_package_count' => 1,
            )
        );
        
        // Success - break out
        if ( ! is_wp_error( $result ) && ! empty( $result['pickup_id'] ) ) {
            break;
        }
    }
    
    // Handle final result
    if ( is_wp_error( $result ) || empty( $result['pickup_id'] ) ) {
        $error_msg = is_wp_error( $result ) ? $result->get_error_message() : 'No pickup ID returned';
        $order->add_order_note( sprintf(
            __( '⚠️ Auto-pickup failed: %s. Schedule manually if needed.', 'ratna-gems' ),
            $error_msg
        ) );
        $order->save();
        return;
    }
    
    // Success
    $order->update_meta_data( '_delhivery_pickup_id', $result['pickup_id'] );
    $order->update_meta_data( '_delhivery_pickup_date', $result['pickup_date'] ?? $pickup_date );
    $order->update_meta_data( '_delhivery_pickup_time', $result['pickup_time'] ?? $pickup_time );
    $order->update_meta_data( '_delhivery_pickup_status', 'Scheduled' );
    
    $order->add_order_note( sprintf(
        __( '📦 Pickup scheduled: %s (ID: %s)', 'ratna-gems' ),
        $result['pickup_date'] ?? $pickup_date,
        $result['pickup_id']
    ) );
    
    $order->save();
}

// =============================================================================
// TRACKING
// =============================================================================

add_action( 'wp_ajax_rg_delhivery_track_shipment', 'rg_delhivery_ajax_track_shipment' );
add_action( 'wp_ajax_nopriv_rg_delhivery_track_shipment', 'rg_delhivery_ajax_track_shipment_public' );

/**
 * AJAX: Track shipment (admin).
 */
function rg_delhivery_ajax_track_shipment(): void {
    check_ajax_referer( 'rg_delhivery_nonce', 'security' );

    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ratna-gems' ) ) );
    }

    $awb = isset( $_POST['awb'] ) ? sanitize_text_field( wp_unslash( $_POST['awb'] ) ) : '';
    if ( empty( $awb ) ) {
        wp_send_json_error( array( 'message' => __( 'AWB number required.', 'ratna-gems' ) ) );
    }

    $client = rg_delhivery_client();
    $summary = $client->get_tracking_summary( $awb );

    if ( is_wp_error( $summary ) ) {
        wp_send_json_error( array( 'message' => $summary->get_error_message() ) );
    }

    // Also update order meta if order_id provided
    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    if ( $order_id > 0 ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $new_status = $summary['status'] ?: $summary['status_type'];
            $order->update_meta_data( '_delhivery_status', $new_status );
            $order->update_meta_data( '_delhivery_status_type', $summary['status_type'] );
            $order->update_meta_data( '_delhivery_last_location', $summary['status_location'] );
            $order->update_meta_data( '_delhivery_last_update', $summary['status_datetime'] );
            if ( ! empty( $summary['expected_date'] ) ) {
                $order->update_meta_data( '_delhivery_expected_delivery', $summary['expected_date'] );
            }
            $order->save();
        }
    }

    wp_send_json_success( $summary );
}

/**
 * AJAX: Track shipment (public/customer).
 */
function rg_delhivery_ajax_track_shipment_public(): void {
    // Rate limiting for public endpoint
    $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    $transient_key = 'rg_dlv_track_' . md5( $ip );
    $count = (int) get_transient( $transient_key );
    
    if ( $count > 10 ) {
        wp_send_json_error( array( 'message' => __( 'Too many requests. Please try again later.', 'ratna-gems' ) ) );
    }
    set_transient( $transient_key, $count + 1, MINUTE_IN_SECONDS );

    $awb = isset( $_POST['awb'] ) ? sanitize_text_field( wp_unslash( $_POST['awb'] ) ) : '';
    if ( empty( $awb ) || strlen( $awb ) < 8 ) {
        wp_send_json_error( array( 'message' => __( 'Valid AWB number required.', 'ratna-gems' ) ) );
    }

    $client = rg_delhivery_client();
    $summary = $client->get_tracking_summary( $awb );

    if ( is_wp_error( $summary ) ) {
        wp_send_json_error( array( 'message' => __( 'Unable to track shipment. Please check the AWB number.', 'ratna-gems' ) ) );
    }

    // Filter sensitive data for public response
    $public_data = array(
        'awb'             => $summary['awb'],
        'status'          => $summary['status'],
        'status_type'     => $summary['status_type'],
        'status_location' => $summary['status_location'],
        'status_datetime' => $summary['status_datetime'],
        'expected_date'   => $summary['expected_date'],
        'scans'           => array_slice( $summary['scans'] ?? array(), 0, 10 ), // Limit scan history
    );

    wp_send_json_success( $public_data );
}

add_action( 'wp_ajax_rg_delhivery_track_multiple', 'rg_delhivery_ajax_track_multiple' );
/**
 * AJAX: Track multiple shipments at once.
 */
function rg_delhivery_ajax_track_multiple(): void {
    check_ajax_referer( 'rg_delhivery_nonce', 'security' );

    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ratna-gems' ) ) );
    }

    $awbs = isset( $_POST['awbs'] ) ? array_map( 'sanitize_text_field', (array) $_POST['awbs'] ) : array();
    if ( empty( $awbs ) ) {
        wp_send_json_error( array( 'message' => __( 'No AWB numbers provided.', 'ratna-gems' ) ) );
    }

    // Limit to 50 per API
    $awbs = array_slice( $awbs, 0, 50 );

    $client = rg_delhivery_client();
    $results = $client->track_multiple_shipments( $awbs );

    if ( is_wp_error( $results ) ) {
        wp_send_json_error( array( 'message' => $results->get_error_message() ) );
    }

    wp_send_json_success( array( 'shipments' => $results ) );
}

// =============================================================================
// NDR ACTIONS
// =============================================================================

add_action( 'wp_ajax_rg_delhivery_ndr_action', 'rg_delhivery_ajax_ndr_action' );
/**
 * AJAX: Perform NDR action on shipment.
 * 
 * Official NDR Actions (per Delhivery B2C API):
 * - RE-ATTEMPT: Schedule another delivery attempt
 * - DEFER_DLV: Schedule delivery for specific future date (max 6 days)
 * - EDIT_DETAILS: Update consignee name, phone, or address
 */
function rg_delhivery_ajax_ndr_action(): void {
    check_ajax_referer( 'rg_delhivery_nonce', 'security' );

    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ratna-gems' ) ) );
    }

    $awb = isset( $_POST['awb'] ) ? sanitize_text_field( wp_unslash( $_POST['awb'] ) ) : '';
    $action = isset( $_POST['ndr_action'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['ndr_action'] ) ) ) : '';
    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

    if ( empty( $awb ) || empty( $action ) ) {
        wp_send_json_error( array( 'message' => __( 'AWB and action required.', 'ratna-gems' ) ) );
    }

    // Validate action - official Delhivery NDR actions
    $allowed_actions = array( 'RE-ATTEMPT', 'DEFER_DLV', 'EDIT_DETAILS' );
    if ( ! in_array( $action, $allowed_actions, true ) ) {
        wp_send_json_error( array( 
            'message' => sprintf( __( 'Invalid NDR action. Allowed: %s', 'ratna-gems' ), implode( ', ', $allowed_actions ) ) 
        ) );
    }

    $client = rg_delhivery_client();
    
    // Check NDR status first
    $ndr_status = $client->get_ndr_status( $awb );
    if ( ! is_wp_error( $ndr_status ) ) {
        // Validate action is available for current status
        if ( $action === 'RE-ATTEMPT' && ! $ndr_status['can_reattempt'] ) {
            wp_send_json_error( array( 
                'message' => sprintf( __( 'RE-ATTEMPT not available. Status code: %s', 'ratna-gems' ), $ndr_status['status_code'] ),
                'note' => $ndr_status['note'] ?? ''
            ) );
        }
        if ( $action === 'DEFER_DLV' && ! $ndr_status['can_defer'] ) {
            wp_send_json_error( array( 
                'message' => sprintf( __( 'DEFER_DLV not available. Status code: %s', 'ratna-gems' ), $ndr_status['status_code'] ) 
            ) );
        }
        if ( $action === 'EDIT_DETAILS' && ! $ndr_status['can_edit'] ) {
            wp_send_json_error( array( 
                'message' => sprintf( __( 'EDIT_DETAILS not available. Status code: %s', 'ratna-gems' ), $ndr_status['status_code'] ) 
            ) );
        }
    }

    // Build options based on action type
    $options = array();
    
    if ( $action === 'DEFER_DLV' ) {
        $deferred_date = isset( $_POST['deferred_date'] ) 
            ? sanitize_text_field( wp_unslash( $_POST['deferred_date'] ) ) 
            : '';
        
        if ( empty( $deferred_date ) ) {
            wp_send_json_error( array( 'message' => __( 'Deferred date is required for DEFER_DLV action.', 'ratna-gems' ) ) );
        }
        
        $options['deferred_date'] = $deferred_date;
    }
    
    if ( $action === 'EDIT_DETAILS' ) {
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
        $address = isset( $_POST['add'] ) ? sanitize_text_field( wp_unslash( $_POST['add'] ) ) : '';
        
        if ( empty( $name ) && empty( $phone ) && empty( $address ) ) {
            wp_send_json_error( array( 'message' => __( 'At least one of name, phone, or address is required.', 'ratna-gems' ) ) );
        }
        
        if ( ! empty( $name ) ) $options['name'] = $name;
        if ( ! empty( $phone ) ) $options['phone'] = $phone;
        if ( ! empty( $address ) ) $options['add'] = $address;
    }

    $result = $client->ndr_action( $awb, $action, $options );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    // Update order if provided
    if ( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->update_meta_data( '_delhivery_ndr_action', $action );
            $order->update_meta_data( '_delhivery_ndr_time', current_time( 'mysql' ) );
            $order->save();
            
            $action_details = $action;
            if ( $action === 'DEFER_DLV' ) {
                $action_details .= ' to ' . ( $options['deferred_date'] ?? '' );
            } elseif ( $action === 'EDIT_DETAILS' ) {
                $action_details .= ' - updated: ' . implode( ', ', array_keys( $options ) );
            }
            
            $order->add_order_note( sprintf( 
                __( '📋 NDR Action: %s applied successfully for AWB %s', 'ratna-gems' ), 
                $action_details,
                $awb
            ) );
        }
    }

    wp_send_json_success( array(
        'message' => sprintf( __( 'NDR action %s applied successfully.', 'ratna-gems' ), $action ),
        'action'  => $action,
        'result'  => $result,
    ) );
}

// =============================================================================
// SHIPPING COST CALCULATION
// =============================================================================

add_action( 'wp_ajax_rg_delhivery_calculate_cost', 'rg_delhivery_ajax_calculate_cost' );
add_action( 'wp_ajax_nopriv_rg_delhivery_calculate_cost', 'rg_delhivery_ajax_calculate_cost' );
/**
 * AJAX: Calculate shipping cost.
 */
function rg_delhivery_ajax_calculate_cost(): void {
    // Basic rate limiting
    $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    $transient_key = 'rg_dlv_cost_' . md5( $ip );
    $count = (int) get_transient( $transient_key );
    
    if ( $count > 30 ) {
        wp_send_json_error( array( 'message' => __( 'Too many requests. Please try again later.', 'ratna-gems' ) ) );
    }
    set_transient( $transient_key, $count + 1, MINUTE_IN_SECONDS );

    $origin_pin = isset( $_POST['origin_pin'] ) ? sanitize_text_field( wp_unslash( $_POST['origin_pin'] ) ) : '';
    $dest_pin = isset( $_POST['dest_pin'] ) ? sanitize_text_field( wp_unslash( $_POST['dest_pin'] ) ) : '';
    $weight = isset( $_POST['weight'] ) ? absint( $_POST['weight'] ) : 500; // grams
    $mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'E';
    $payment_type = isset( $_POST['payment_type'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_type'] ) ) : 'Pre-paid';
    
    // COD amount for calculating actual COD charge
    $cod_amount = isset( $_POST['cod_amount'] ) ? (float) $_POST['cod_amount'] : 0;

    // Use default origin if not provided
    if ( empty( $origin_pin ) ) {
        $origin_pin = defined( 'DELHIVERY_ORIGIN_PINCODE' ) ? DELHIVERY_ORIGIN_PINCODE : '';
    }

    if ( empty( $origin_pin ) || empty( $dest_pin ) ) {
        wp_send_json_error( array( 'message' => __( 'Origin and destination pincodes required.', 'ratna-gems' ) ) );
    }

    // Validate pincodes (6 digits)
    if ( ! preg_match( '/^\d{6}$/', $origin_pin ) || ! preg_match( '/^\d{6}$/', $dest_pin ) ) {
        wp_send_json_error( array( 'message' => __( 'Invalid pincode format. Must be 6 digits.', 'ratna-gems' ) ) );
    }

    $client = rg_delhivery_client();
    $result = $client->calculate_shipping_cost( array(
        'o_pin'      => $origin_pin,
        'd_pin'      => $dest_pin,
        'cgm'        => $weight,
        'md'         => $mode,
        'pt'         => $payment_type,
        'ss'         => 'Delivered',
        'cod_amount' => $cod_amount,  // Pass COD collection amount
    ) );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    // Return cost data with COD calculation
    $cost = $result['cost'] ?? $result['raw'] ?? array();
    $cod_calculation = $result['cod_calculation'] ?? null;
    
    // If we have COD calculation, adjust the displayed totals
    $response = array(
        'cost'            => $cost,
        'raw'             => $result['raw'] ?? $cost,
        'params_sent'     => $result['params'] ?? array(),
        'origin'          => $origin_pin,
        'destination'     => $dest_pin,
        'weight'          => $weight,
        'mode'            => $mode,
        'payment_type'    => $payment_type,
        'cod_amount'      => $cod_amount,
        'cod_calculation' => $cod_calculation,
    );
    
    // Calculate adjusted total if COD charge was recalculated
    if ( $cod_calculation && $cod_calculation['difference'] > 0 ) {
        $cost_array = is_array( $cost ) && isset( $cost[0] ) ? $cost[0] : $cost;
        $original_total = (float) ( $cost_array['total_amount'] ?? 0 );
        $response['adjusted_total'] = round( $original_total + $cod_calculation['difference'], 2 );
        $response['adjustment_note'] = $cod_calculation['note'];
    }
    
    wp_send_json_success( $response );
}

// =============================================================================
// PINCODE SERVICEABILITY
// =============================================================================

add_action( 'wp_ajax_rg_delhivery_check_pincode', 'rg_delhivery_ajax_check_pincode' );
add_action( 'wp_ajax_nopriv_rg_delhivery_check_pincode', 'rg_delhivery_ajax_check_pincode' );
/**
 * AJAX: Check pincode serviceability.
 */
function rg_delhivery_ajax_check_pincode(): void {
    // Rate limiting
    $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    $transient_key = 'rg_dlv_pin_' . md5( $ip );
    $count = (int) get_transient( $transient_key );
    
    if ( $count > 50 ) {
        wp_send_json_error( array( 'message' => __( 'Too many requests.', 'ratna-gems' ) ) );
    }
    set_transient( $transient_key, $count + 1, MINUTE_IN_SECONDS );

    $pincode = isset( $_POST['pincode'] ) ? sanitize_text_field( wp_unslash( $_POST['pincode'] ) ) : '';
    $product_type = isset( $_POST['product_type'] ) ? sanitize_text_field( wp_unslash( $_POST['product_type'] ) ) : 'standard';

    if ( empty( $pincode ) || ! preg_match( '/^\d{6}$/', $pincode ) ) {
        wp_send_json_error( array( 'message' => __( 'Valid 6-digit pincode required.', 'ratna-gems' ) ) );
    }

    // Check cache first
    $cache_key = 'rg_dlv_svc_' . $pincode . '_' . $product_type;
    $cached = get_transient( $cache_key );
    if ( false !== $cached ) {
        wp_send_json_success( $cached );
    }

    $client = rg_delhivery_client();
    
    if ( $product_type === 'heavy' ) {
        $result = $client->heavy_product_serviceability( $pincode );
    } else {
        $result = $client->pincode_serviceability( $pincode );
    }

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    // Cache for 1 hour
    set_transient( $cache_key, $result, HOUR_IN_SECONDS );

    wp_send_json_success( $result );
}

// =============================================================================
// WAYBILL MANAGEMENT
// =============================================================================

add_action( 'wp_ajax_rg_delhivery_fetch_waybills', 'rg_delhivery_ajax_fetch_waybills' );
/**
 * AJAX: Fetch waybills in bulk.
 */
function rg_delhivery_ajax_fetch_waybills(): void {
    check_ajax_referer( 'rg_delhivery_nonce', 'security' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ratna-gems' ) ) );
    }

    $count = isset( $_POST['count'] ) ? absint( $_POST['count'] ) : 100;
    $count = min( $count, 10000 ); // API max

    $client = rg_delhivery_client();
    $result = $client->prefetch_and_store_waybills( $count );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    wp_send_json_success( array(
        'message'  => sprintf( __( 'Fetched %d waybills.', 'ratna-gems' ), $result['stored'] ?? 0 ),
        'stored'   => $result['stored'] ?? 0,
        'pool_size' => $result['pool_size'] ?? 0,
    ) );
}

add_action( 'wp_ajax_rg_delhivery_waybill_pool_status', 'rg_delhivery_ajax_waybill_pool_status' );
/**
 * AJAX: Get waybill pool status.
 */
function rg_delhivery_ajax_waybill_pool_status(): void {
    check_ajax_referer( 'rg_delhivery_nonce', 'security' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ratna-gems' ) ) );
    }

    $pool = get_option( 'rg_delhivery_waybill_pool', array() );
    
    wp_send_json_success( array(
        'count'       => count( $pool ),
        'last_fetch'  => get_option( 'rg_delhivery_waybill_last_fetch', '' ),
        'low_warning' => count( $pool ) < 50,
    ) );
}

// =============================================================================
// WAREHOUSE MANAGEMENT
// =============================================================================

add_action( 'wp_ajax_rg_delhivery_create_warehouse', 'rg_delhivery_ajax_create_warehouse' );
/**
 * AJAX: Create warehouse/pickup location.
 */
function rg_delhivery_ajax_create_warehouse(): void {
    check_ajax_referer( 'rg_delhivery_nonce', 'security' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ratna-gems' ) ) );
    }

    $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
    $pin = isset( $_POST['pin'] ) ? sanitize_text_field( wp_unslash( $_POST['pin'] ) ) : '';
    $address = isset( $_POST['address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) : '';
    $city = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';

    if ( empty( $name ) || empty( $phone ) || empty( $pin ) ) {
        wp_send_json_error( array( 'message' => __( 'Name, phone, and pincode are required.', 'ratna-gems' ) ) );
    }

    $client = rg_delhivery_client();
    $result = $client->create_warehouse( array(
        'name'    => $name,
        'phone'   => $phone,
        'pin'     => $pin,
        'address' => $address,
        'city'    => $city,
        'country' => 'India',
        // Return address same as warehouse by default
        'return_name'    => $name,
        'return_phone'   => $phone,
        'return_pin'     => $pin,
        'return_address' => $address,
        'return_city'    => $city,
        'return_country' => 'India',
    ) );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    wp_send_json_success( array(
        'message' => __( 'Warehouse created successfully.', 'ratna-gems' ),
        'result'  => $result,
    ) );
}

add_action( 'wp_ajax_rg_delhivery_update_warehouse', 'rg_delhivery_ajax_update_warehouse' );
/**
 * AJAX: Update warehouse.
 */
function rg_delhivery_ajax_update_warehouse(): void {
    check_ajax_referer( 'rg_delhivery_nonce', 'security' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ratna-gems' ) ) );
    }

    $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    if ( empty( $name ) ) {
        wp_send_json_error( array( 'message' => __( 'Warehouse name required.', 'ratna-gems' ) ) );
    }

    $updates = array();
    
    // Only include fields that were provided
    $allowed_fields = array( 'phone', 'pin', 'address' );
    foreach ( $allowed_fields as $field ) {
        if ( isset( $_POST[ $field ] ) && '' !== $_POST[ $field ] ) {
            $updates[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
        }
    }

    if ( empty( $updates ) ) {
        wp_send_json_error( array( 'message' => __( 'No updates provided.', 'ratna-gems' ) ) );
    }

    $client = rg_delhivery_client();
    $result = $client->update_warehouse( $name, $updates );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    wp_send_json_success( array(
        'message' => __( 'Warehouse updated successfully.', 'ratna-gems' ),
        'result'  => $result,
    ) );
}

// =============================================================================
// SHIPMENT UPDATES
// =============================================================================

add_action( 'wp_ajax_rg_delhivery_update_shipment', 'rg_delhivery_ajax_update_shipment' );
/**
 * AJAX: Update shipment details.
 */
function rg_delhivery_ajax_update_shipment(): void {
    check_ajax_referer( 'rg_delhivery_nonce', 'security' );

    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ratna-gems' ) ) );
    }

    $awb = isset( $_POST['awb'] ) ? sanitize_text_field( wp_unslash( $_POST['awb'] ) ) : '';
    if ( empty( $awb ) ) {
        wp_send_json_error( array( 'message' => __( 'AWB required.', 'ratna-gems' ) ) );
    }

    $updates = array();
    
    // Allowed fields per documentation
    $allowed_fields = array(
        'name', 'add', 'pin', 'phone',
        'weight', 'shipment_height',
        'cod', 'pt', // payment mode
        'gst_number',
        'return_name', 'return_add', 'return_pin', 'return_phone',
    );

    foreach ( $allowed_fields as $field ) {
        if ( isset( $_POST[ $field ] ) && '' !== $_POST[ $field ] ) {
            $updates[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
        }
    }

    if ( empty( $updates ) ) {
        wp_send_json_error( array( 'message' => __( 'No updates provided.', 'ratna-gems' ) ) );
    }

    $client = rg_delhivery_client();
    $result = $client->update_shipment( $awb, $updates );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    // Update order if provided
    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    if ( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->add_order_note( sprintf( __( 'Shipment %s updated: %s', 'ratna-gems' ), $awb, wp_json_encode( $updates ) ) );
        }
    }

    wp_send_json_success( array(
        'message' => __( 'Shipment updated successfully.', 'ratna-gems' ),
        'result'  => $result,
    ) );
}

add_action( 'wp_ajax_rg_delhivery_convert_payment', 'rg_delhivery_ajax_convert_payment' );
/**
 * AJAX: Convert payment mode (COD <-> Prepaid).
 */
function rg_delhivery_ajax_convert_payment(): void {
    check_ajax_referer( 'rg_delhivery_nonce', 'security' );

    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ratna-gems' ) ) );
    }

    $awb = isset( $_POST['awb'] ) ? sanitize_text_field( wp_unslash( $_POST['awb'] ) ) : '';
    $new_mode = isset( $_POST['new_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['new_mode'] ) ) : '';
    $cod_amount = isset( $_POST['cod_amount'] ) ? floatval( $_POST['cod_amount'] ) : 0;

    if ( empty( $awb ) || empty( $new_mode ) ) {
        wp_send_json_error( array( 'message' => __( 'AWB and new payment mode required.', 'ratna-gems' ) ) );
    }

    // Validate mode
    if ( ! in_array( $new_mode, array( 'COD', 'Pre-paid' ), true ) ) {
        wp_send_json_error( array( 'message' => __( 'Invalid payment mode. Use COD or Pre-paid.', 'ratna-gems' ) ) );
    }

    // COD requires amount
    if ( $new_mode === 'COD' && $cod_amount <= 0 ) {
        wp_send_json_error( array( 'message' => __( 'COD amount required when converting to COD.', 'ratna-gems' ) ) );
    }

    $client = rg_delhivery_client();
    $result = $client->convert_payment_mode( $awb, $new_mode, $cod_amount );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    wp_send_json_success( array(
        'message' => sprintf( __( 'Payment mode converted to %s.', 'ratna-gems' ), $new_mode ),
        'result'  => $result,
    ) );
}

// =============================================================================
// E-WAYBILL
// =============================================================================

add_action( 'wp_ajax_rg_delhivery_update_ewaybill', 'rg_delhivery_ajax_update_ewaybill' );
/**
 * AJAX: Update e-waybill for shipment.
 */
function rg_delhivery_ajax_update_ewaybill(): void {
    check_ajax_referer( 'rg_delhivery_nonce', 'security' );

    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ratna-gems' ) ) );
    }

    $awb = isset( $_POST['awb'] ) ? sanitize_text_field( wp_unslash( $_POST['awb'] ) ) : '';
    $ewbn = isset( $_POST['ewbn'] ) ? sanitize_text_field( wp_unslash( $_POST['ewbn'] ) ) : '';

    if ( empty( $awb ) || empty( $ewbn ) ) {
        wp_send_json_error( array( 'message' => __( 'AWB and e-waybill number required.', 'ratna-gems' ) ) );
    }

    $client = rg_delhivery_client();
    $result = $client->update_ewaybill( $awb, $ewbn );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    // Update order if provided
    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    if ( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->update_meta_data( '_delhivery_ewaybill_number', $ewbn );
            $order->update_meta_data( '_delhivery_ewaybill_updated', current_time( 'mysql' ) );
            $order->save();
            $order->add_order_note( sprintf( __( 'E-waybill %s linked to shipment %s.', 'ratna-gems' ), $ewbn, $awb ) );
        }
    }

    wp_send_json_success( array(
        'message' => __( 'E-waybill updated successfully.', 'ratna-gems' ),
        'result'  => $result,
    ) );
}

// =============================================================================
// DOCUMENT DOWNLOADS
// =============================================================================

add_action( 'wp_ajax_rg_delhivery_download_document', 'rg_delhivery_ajax_download_document' );
/**
 * AJAX: Download document (EPOD, signature, QC image, etc.).
 */
function rg_delhivery_ajax_download_document(): void {
    check_ajax_referer( 'rg_delhivery_nonce', 'security' );

    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ratna-gems' ) ) );
    }

    $awb = isset( $_POST['awb'] ) ? sanitize_text_field( wp_unslash( $_POST['awb'] ) ) : '';
    $doc_type = isset( $_POST['doc_type'] ) ? sanitize_text_field( wp_unslash( $_POST['doc_type'] ) ) : 'EPOD';

    if ( empty( $awb ) ) {
        wp_send_json_error( array( 'message' => __( 'AWB required.', 'ratna-gems' ) ) );
    }

    $client = rg_delhivery_client();
    $result = $client->download_document( $awb, $doc_type );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    wp_send_json_success( array(
        'document' => $result,
        'awb'      => $awb,
        'type'     => $doc_type,
    ) );
}

// =============================================================================
// WEBHOOK ENDPOINT
// =============================================================================

add_action( 'rest_api_init', 'rg_delhivery_register_webhook_endpoint' );
/**
 * Register REST API endpoint for Delhivery webhooks.
 */
function rg_delhivery_register_webhook_endpoint(): void {
    register_rest_route( 'rg-delhivery/v1', '/webhook', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rg_delhivery_handle_webhook',
        'permission_callback' => '__return_true', // Webhook validation done in callback
    ) );
    
    // POD webhook endpoint
    register_rest_route( 'rg-delhivery/v1', '/webhook/pod', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'rg_delhivery_handle_pod_webhook',
        'permission_callback' => '__return_true',
    ) );
}

/**
 * Handle incoming Delhivery webhook.
 */
function rg_delhivery_handle_webhook( WP_REST_Request $request ): WP_REST_Response {
    $client = rg_delhivery_client();
    
    // Get raw payload for signature validation
    $raw_payload = $request->get_body();
    $signature = $request->get_header( 'X-Delhivery-Signature' );

    // Validate signature if secret is configured
    if ( defined( 'DELHIVERY_API_SECRET' ) && DELHIVERY_API_SECRET ) {
        if ( ! $client->validate_webhook_signature( $raw_payload, $signature ) ) {
            rg_delhivery_log( 'Webhook signature validation failed', 'error' );
            return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 401 );
        }
    }

    $payload = $request->get_json_params();
    if ( empty( $payload ) ) {
        return new WP_REST_Response( array( 'error' => 'Empty payload' ), 400 );
    }

    // Parse and process webhook
    $webhook_data = $client->parse_webhook_payload( $payload );
    
    if ( is_wp_error( $webhook_data ) ) {
        rg_delhivery_log( 'Webhook parse error: ' . $webhook_data->get_error_message(), 'error' );
        return new WP_REST_Response( array( 'error' => $webhook_data->get_error_message() ), 400 );
    }

    // Process the webhook (update orders, etc.)
    $result = $client->process_webhook( $webhook_data );

    if ( is_wp_error( $result ) ) {
        rg_delhivery_log( 'Webhook processing error: ' . $result->get_error_message(), 'error' );
        return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
    }

    rg_delhivery_log( 'Webhook processed successfully for AWB: ' . ( $webhook_data['awb'] ?? 'unknown' ) );

    return new WP_REST_Response( array( 'success' => true, 'processed' => $result ), 200 );
}

/**
 * Handle POD (Proof of Delivery) webhook.
 */
function rg_delhivery_handle_pod_webhook( WP_REST_Request $request ): WP_REST_Response {
    $payload = $request->get_json_params();
    
    if ( empty( $payload ) ) {
        return new WP_REST_Response( array( 'error' => 'Empty payload' ), 400 );
    }

    $awb = $payload['waybill'] ?? $payload['awb'] ?? '';
    if ( empty( $awb ) ) {
        return new WP_REST_Response( array( 'error' => 'Missing AWB' ), 400 );
    }

    // Find order by AWB
    $orders = wc_get_orders( array(
        'meta_key'   => '_delhivery_awb',
        'meta_value' => $awb,
        'limit'      => 1,
    ) );

    if ( empty( $orders ) ) {
        rg_delhivery_log( 'POD webhook: Order not found for AWB ' . $awb, 'warning' );
        return new WP_REST_Response( array( 'success' => true, 'message' => 'Order not found' ), 200 );
    }

    $order = $orders[0];

    // Store POD data
    if ( ! empty( $payload['pod_url'] ) ) {
        $order->update_meta_data( '_delhivery_pod_url', esc_url_raw( $payload['pod_url'] ) );
    }
    if ( ! empty( $payload['signature_url'] ) ) {
        $order->update_meta_data( '_delhivery_signature_url', esc_url_raw( $payload['signature_url'] ) );
    }
    
    $order->update_meta_data( '_delhivery_pod_received', current_time( 'mysql' ) );
    $order->save();

    $order->add_order_note( __( 'Proof of Delivery received from Delhivery.', 'ratna-gems' ) );

    return new WP_REST_Response( array( 'success' => true ), 200 );
}

// =============================================================================
// RETURN SHIPMENT (RVP)
// =============================================================================

add_action( 'wp_ajax_rg_delhivery_create_return', 'rg_delhivery_ajax_create_return' );
/**
 * AJAX: Create return shipment (RVP).
 */
function rg_delhivery_ajax_create_return(): void {
    check_ajax_referer( 'rg_delhivery_nonce', 'security' );

    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ratna-gems' ) ) );
    }

    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    if ( ! $order_id ) {
        wp_send_json_error( array( 'message' => __( 'Order ID required.', 'ratna-gems' ) ) );
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_send_json_error( array( 'message' => __( 'Order not found.', 'ratna-gems' ) ) );
    }

    // Check if return already exists
    if ( $order->get_meta( '_delhivery_return_awb' ) ) {
        wp_send_json_error( array( 'message' => __( 'Return shipment already exists for this order.', 'ratna-gems' ) ) );
    }

    $client = rg_delhivery_client();
    
    // Optional: include QC parameters
    $with_qc = isset( $_POST['with_qc'] ) && $_POST['with_qc'] === 'true';
    
    $result = $client->create_return_shipment( $order, array( 'with_qc' => $with_qc ) );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    $packages = $result['packages'] ?? array();
    if ( empty( $packages[0]['waybill'] ) ) {
        wp_send_json_error( array( 'message' => __( 'No waybill returned for return shipment.', 'ratna-gems' ) ) );
    }

    $return_awb = $packages[0]['waybill'];
    $order->update_meta_data( '_delhivery_return_awb', $return_awb );
    $order->update_meta_data( '_delhivery_return_status', $packages[0]['status'] ?? 'Created' );
    $order->update_meta_data( '_delhivery_return_created', current_time( 'mysql' ) );
    $order->save();

    $order->add_order_note( sprintf( __( 'Return shipment created. Return AWB: %s', 'ratna-gems' ), $return_awb ) );

    wp_send_json_success( array(
        'message'    => __( 'Return shipment created successfully.', 'ratna-gems' ),
        'return_awb' => $return_awb,
        'result'     => $result,
    ) );
}

// =============================================================================
// LABEL & SLIP GENERATION
// =============================================================================

add_action( 'wp_ajax_rg_delhivery_generate_label', 'rg_delhivery_ajax_generate_label' );
/**
 * AJAX: Generate shipping label.
 */
function rg_delhivery_ajax_generate_label(): void {
    check_ajax_referer( 'rg_delhivery_nonce', 'security' );

    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ratna-gems' ) ) );
    }

    $awb = isset( $_POST['awb'] ) ? sanitize_text_field( wp_unslash( $_POST['awb'] ) ) : '';
    $pdf = isset( $_POST['pdf'] ) && $_POST['pdf'] === 'true';
    $pdf_size = isset( $_POST['pdf_size'] ) ? sanitize_text_field( wp_unslash( $_POST['pdf_size'] ) ) : 'A4';

    if ( empty( $awb ) ) {
        wp_send_json_error( array( 'message' => __( 'AWB required.', 'ratna-gems' ) ) );
    }

    $client = rg_delhivery_client();
    $result = $client->generate_label( $awb, $pdf, $pdf_size );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    wp_send_json_success( $result );
}

// =============================================================================
// CANCEL SHIPMENT
// =============================================================================

add_action( 'wp_ajax_rg_delhivery_cancel_shipment', 'rg_delhivery_ajax_cancel_shipment' );
/**
 * AJAX: Cancel a Delhivery shipment.
 * 
 * Official Delhivery Cancellation Rules (from documentation lines 828-834):
 * 
 * Allowed Package Statuses for Cancellation:
 * - Forward Shipment (COD/Prepaid): Manifested, In Transit, Pending
 * - RVP Shipment (Pickup): Scheduled
 * - REPL Shipment (REPL): Manifested, In Transit, Pending
 * 
 * NOT allowed when status is: Dispatched, Delivered, DTO, RTO, LOST, Closed
 * 
 * Post-Cancellation Behavior:
 * - Before pickup (Manifested): Status stays "Manifested", StatusType stays "UD"
 * - After pickup (In Transit/Pending): Status stays "In Transit", StatusType changes to "RT" (RTO)
 * - Scheduled (RVP): Status changes to "Canceled", StatusType "CN"
 */
function rg_delhivery_ajax_cancel_shipment(): void {
    check_ajax_referer( 'rg_delhivery_nonce', 'security' );

    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ratna-gems' ) ) );
    }

    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    if ( ! $order_id ) {
        wp_send_json_error( array( 'message' => __( 'Order ID is required.', 'ratna-gems' ) ) );
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_send_json_error( array( 'message' => __( 'Order not found.', 'ratna-gems' ) ) );
    }

    $awb = (string) $order->get_meta( '_delhivery_awb' );
    if ( empty( $awb ) ) {
        wp_send_json_error( array( 'message' => __( 'No AWB found for this order.', 'ratna-gems' ) ) );
    }

    // Get current status from order meta
    $current_status = trim( $order->get_meta( '_delhivery_status' ) );
    $current_status_type = trim( $order->get_meta( '_delhivery_status_type' ) );
    $current_status_upper = strtoupper( $current_status );
    $current_status_type_upper = strtoupper( $current_status_type );

    // =========================================================================
    // CANCEL ELIGIBILITY - Based on Official Delhivery B2C API Documentation
    // =========================================================================
    // StatusType codes (from Webhook docs):
    //   UD = Undelivered (forward journey active)
    //   DL = Delivered (terminal: Delivered/RTO/DTO)
    //   RT = Return (RTO journey in progress)
    //   PP = Pickup Pending (RVP before pickup)
    //   PU = Picked Up (RVP in transit)
    //   CN = Canceled (RVP cancelled)
    //
    // Cancellation Rules (per official docs):
    // - Forward (UD): Manifested, In Transit, Pending = Allowed
    // - Forward (UD): Dispatched = NOT Allowed
    // - RVP (PP): Scheduled = Allowed
    // - Terminal (DL): Delivered, RTO, DTO = NOT Allowed
    // =========================================================================
    
    // Check StatusType (PRIMARY)
    $is_type_forward   = 'UD' === $current_status_type_upper;
    $is_type_terminal  = 'DL' === $current_status_type_upper;
    $is_type_rto       = 'RT' === $current_status_type_upper;
    $is_type_rvp_pre   = 'PP' === $current_status_type_upper;
    $is_type_rvp_post  = 'PU' === $current_status_type_upper;
    $is_type_cancelled = 'CN' === $current_status_type_upper;
    $is_type_unknown   = empty( $current_status_type_upper );
    
    // If StatusType is DL (Delivered/RTO/DTO completed), cannot cancel
    if ( $is_type_terminal ) {
        $terminal_desc = 'Delivered';
        if ( 'RTO' === $current_status_upper ) $terminal_desc = 'returned to origin (RTO)';
        if ( 'DTO' === $current_status_upper ) $terminal_desc = 'delivered to origin (DTO)';
        
        wp_send_json_error( array( 
            'message' => sprintf( __( 'Cannot cancel shipment. Shipment has already been %s (StatusType: DL).', 'ratna-gems' ), $terminal_desc ),
            'status' => $current_status,
            'status_type' => $current_status_type,
            'can_cancel' => false
        ) );
    }
    
    // If already cancelled (StatusType CN)
    if ( $is_type_cancelled ) {
        wp_send_json_error( array( 
            'message' => __( 'Shipment is already cancelled (StatusType: CN).', 'ratna-gems' ),
            'status' => $current_status,
            'status_type' => $current_status_type,
            'can_cancel' => false
        ) );
    }
    
    // If in RTO journey (StatusType = RT), shipment is returning - warn user
    // Note: RTO shipments typically cannot be cancelled but API will give final answer

    // Check Status text for non-cancellable states
    $non_cancellable_statuses = array( 
        'DISPATCHED',   // Out for delivery - too late
        'DELIVERED',    // Already delivered
        'DTO',          // Deliver to Origin completed
        'RTO',          // Return to Origin completed
        'RETURNED',     // RTO completed
        'LOST',         // Shipment lost
        'CLOSED',       // Request closed
        'CANCELED',     // Already cancelled
        'CANCELLED',    // Already cancelled (alternate spelling)
    );
    
    foreach ( $non_cancellable_statuses as $nc_status ) {
        if ( $current_status_upper === $nc_status || false !== strpos( $current_status_upper, $nc_status ) ) {
            wp_send_json_error( array( 
                'message' => sprintf( 
                    __( 'Cannot cancel shipment. Status "%s" does not allow cancellation. Allowed: Manifested, In Transit, Pending (Forward) or Scheduled (RVP).', 'ratna-gems' ), 
                    $current_status 
                ),
                'status' => $current_status,
                'status_type' => $current_status_type,
                'can_cancel' => false
            ) );
        }
    }

    // Determine refund eligibility based on official docs:
    // Before pickup (Manifested, Not Picked, Pickup Scheduled, Open, Scheduled) = Full refund
    // After pickup (In Transit, Pending) = No refund, triggers RTO
    $before_pickup_statuses = array( 'MANIFESTED', 'NOT PICKED', 'PICKUP SCHEDULED', 'OPEN', 'SCHEDULED', '' );
    $is_before_pickup = ( $is_type_forward || $is_type_rvp_pre || $is_type_unknown ) && (
                         in_array( $current_status_upper, $before_pickup_statuses, true )
                      || ( empty( $current_status_upper ) && ! empty( $awb ) )
                      );
    
    $after_pickup_statuses = array( 'IN TRANSIT', 'PENDING', 'PICKED UP' );
    $is_after_pickup = ( $is_type_forward || $is_type_rvp_post ) && (
                        in_array( $current_status_upper, $after_pickup_statuses, true ) 
                     || false !== strpos( $current_status_upper, 'TRANSIT' )
                     );
    
    // RTO journey (RT) = already past pickup, cancelling triggers return charges
    if ( $is_type_rto ) {
        $is_before_pickup = false;
        $is_after_pickup = true;
    }

    $client = rg_delhivery_client();
    if ( ! $client || ! $client->is_configured() ) {
        wp_send_json_error( array( 'message' => __( 'Delhivery API not configured.', 'ratna-gems' ) ) );
    }

    // Call the cancel API
    $result = $client->cancel_shipment( $awb );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 
            'message' => $result->get_error_message(),
            'awb' => $awb,
            'status' => $current_status
        ) );
    }

    // Success - update order metadata based on cancellation type
    $order->update_meta_data( '_delhivery_cancelled_awb', $awb );
    $order->update_meta_data( '_delhivery_cancelled_time', current_time( 'mysql' ) );
    $order->update_meta_data( '_delhivery_cancelled_from_status', $current_status );
    
    if ( $is_before_pickup ) {
        // Before pickup: Clear ALL Delhivery metadata for clean re-manifest
        $order->update_meta_data( '_delhivery_status', 'Cancelled' );
        $order->update_meta_data( '_delhivery_status_type', 'CN' );
        $order->delete_meta_data( '_delhivery_awb' );
        $order->delete_meta_data( '_delhivery_pickup_id' );
        $order->delete_meta_data( '_delhivery_pickup_date' );
        $order->delete_meta_data( '_delhivery_manifest_time' );
        $order->delete_meta_data( '_delhivery_last_update' );
        $order->delete_meta_data( '_delhivery_last_location' );
        $order->delete_meta_data( '_delhivery_expected_delivery' );
        $order->delete_meta_data( '_delhivery_is_ndr' );
        $order->delete_meta_data( '_delhivery_status_code' );
        
        $order->add_order_note( sprintf( 
            __( '✅ Delhivery shipment %s cancelled successfully (before pickup). Full refund will be credited to Delhivery wallet. Order can now be re-manifested.', 'ratna-gems' ), 
            $awb 
        ) );
        
        $refund_message = __( 'Shipping charges will be refunded to your Delhivery wallet immediately since the shipment was not yet picked up.', 'ratna-gems' );
    } else {
        // After pickup: Status stays In Transit, StatusType changes to RT (RTO initiated)
        $order->update_meta_data( '_delhivery_status', 'In Transit' );
        $order->update_meta_data( '_delhivery_status_type', 'RT' );
        // Keep AWB as shipment will return
        
        $order->add_order_note( sprintf( 
            __( '⚠️ Delhivery shipment %s cancellation initiated (after pickup). RTO (Return to Origin) will be triggered. No refund - RTO charges will apply.', 'ratna-gems' ), 
            $awb 
        ) );
        
        $refund_message = __( 'No refund - shipment was already picked up. RTO (Return to Origin) has been initiated. RTO charges will apply.', 'ratna-gems' );
    }
    
    $order->save();

    do_action( 'rg_delhivery_shipment_cancelled', $order, $awb, $result, $is_before_pickup );

    wp_send_json_success( array(
        'message' => sprintf( __( 'Shipment %s cancellation processed.', 'ratna-gems' ), $awb ),
        'awb' => $awb,
        'cancelled_at' => current_time( 'mysql' ),
        'was_before_pickup' => $is_before_pickup,
        'refund_note' => $refund_message,
        'can_remanifest' => $is_before_pickup
    ) );
}

// =============================================================================
// HELPER FUNCTION
// =============================================================================

if ( ! function_exists( 'rg_delhivery_log' ) ) {
    /**
     * Log helper for Delhivery operations.
     */
    function rg_delhivery_log( string $message, string $level = 'info' ): void {
        if ( ! function_exists( 'wc_get_logger' ) ) {
            return;
        }
        
        $logger = wc_get_logger();
        $context = array( 'source' => 'delhivery-api' );
        
        switch ( $level ) {
            case 'error':
                $logger->error( $message, $context );
                break;
            case 'warning':
                $logger->warning( $message, $context );
                break;
            case 'debug':
                $logger->debug( $message, $context );
                break;
            default:
                $logger->info( $message, $context );
        }
    }
}
