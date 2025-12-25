<?php
/**
 * Enhanced WooCommerce order actions that trigger Delhivery workflows.
 * Now includes: shipping cost estimation, return shipments, e-waybill, and more.
 *
 * @package Ratna Gems
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'woocommerce_order_actions', 'rg_delhivery_register_order_actions', 20, 2 );
/**
 * Add Delhivery actions to the order actions dropdown.
 */
function rg_delhivery_register_order_actions( array $actions, $order ): array {
    if ( ! $order instanceof WC_Order ) {
        return $actions;
    }

    // Always add invoice action (works for all orders)
    $actions['rg_download_invoice'] = __( 'Download Invoice (GST)', 'ratna-gems' );

    $client = rg_delhivery_client();
    if ( ! $client || ! $client->is_configured() ) {
        return $actions;
    }

    $has_awb = (bool) $order->get_meta( '_delhivery_awb' );
    $order_total = (float) $order->get_total();
    
    // Get Delhivery status and StatusType (official terminology from documentation lines 1567-1601)
    $status = $order->get_meta( '_delhivery_status' );
    $status_type = $order->get_meta( '_delhivery_status_type' );
    $status_upper = strtoupper( trim( $status ) );
    $status_type_upper = strtoupper( trim( $status_type ) );
    
    // =========================================================================
    // STATUS DETECTION - Based on Official Delhivery B2C API Documentation
    // =========================================================================
    // StatusType codes (from Webhook docs):
    //   UD = Undelivered (forward journey active)
    //   DL = Delivered (terminal: Delivered/RTO/DTO)
    //   RT = Return (RTO journey in progress)
    //   PP = Pickup Pending (RVP before pickup)
    //   PU = Picked Up (RVP in transit)
    //   CN = Canceled (RVP cancelled)
    // =========================================================================
    
    // Classify by StatusType (PRIMARY)
    $is_type_forward   = 'UD' === $status_type_upper;
    $is_type_terminal  = 'DL' === $status_type_upper;
    $is_type_rto       = 'RT' === $status_type_upper;
    $is_type_rvp_pre   = 'PP' === $status_type_upper;
    $is_type_rvp_post  = 'PU' === $status_type_upper;
    $is_type_cancelled = 'CN' === $status_type_upper;
    $is_type_unknown   = empty( $status_type_upper );
    
    // Terminal states (StatusType = DL)
    $is_delivered = $is_type_terminal && ! in_array( $status_upper, array( 'RTO', 'DTO' ), true );
    $is_rto       = $is_type_terminal && 'RTO' === $status_upper;
    $is_dto       = $is_type_terminal && 'DTO' === $status_upper;
    
    // RTO journey in progress (StatusType = RT)
    if ( $is_type_rto ) {
        $is_rto = true;
    }
    
    // Cancelled (StatusType CN for RVP, or Status text)
    $is_cancelled = $is_type_cancelled 
                 || in_array( $status_upper, array( 'CANCELLED', 'CANCELED', 'CLOSED' ), true );
    
    // NDR: Check order meta flag (set by webhook when delivery attempt fails)
    $is_ndr = 'yes' === $order->get_meta( '_delhivery_is_ndr' );
    
    // Forward active states
    $is_forward_active = $is_type_forward || ( $is_type_unknown && ! $is_delivered && ! $is_rto && ! $is_cancelled );
    $is_dispatched = $is_forward_active && ( 'DISPATCHED' === $status_upper || false !== strpos( $status_upper, 'OUT FOR' ) );
    
    // Fallback for legacy orders without StatusType
    if ( $is_type_unknown && ! empty( $status_upper ) ) {
        if ( 'DELIVERED' === $status_upper ) $is_delivered = true;
        if ( 'RTO' === $status_upper || false !== strpos( $status_upper, 'RTO' ) || 'RETURNED' === $status_upper ) $is_rto = true;
        if ( 'DTO' === $status_upper ) $is_dto = true;
    }
    
    // Final states
    $is_final = $is_delivered || $is_rto || $is_dto || $is_cancelled || 'LOST' === $status_upper;
    
    // Cancellable: NOT final, NOT dispatched, NOT in RTO journey
    $can_cancel = ! $is_final && ! $is_dispatched && ! $is_type_rto;

    if ( $has_awb ) {
        // Always show refresh status
        $actions['rg_delhivery_refresh_status'] = __( 'Delhivery: Refresh Tracking Status', 'ratna-gems' );
        
        // Cancel only when allowed per official Delhivery rules
        if ( $can_cancel ) {
            $actions['rg_cancel_delhivery_shipment'] = __( 'Delhivery: Cancel Shipment', 'ratna-gems' );
        }
        
        // NDR actions only when NDR flag is set (from webhook)
        if ( $is_ndr && ! $is_final ) {
            $actions['rg_delhivery_ndr_reattempt']  = __( 'Delhivery: Request Re‑attempt (NDR)', 'ratna-gems' );
            $actions['rg_delhivery_ndr_reschedule'] = __( 'Delhivery: Reschedule Pickup (NDR)', 'ratna-gems' );
        }
        
        // E-waybill option for high-value orders (>50k INR) before dispatch
        if ( $order_total > 50000 && ! $is_final ) {
            $actions['rg_delhivery_update_ewaybill'] = __( 'Delhivery: Update E-Waybill', 'ratna-gems' );
        }
        
        // Return shipment option only after delivery
        if ( $is_delivered ) {
            $actions['rg_delhivery_create_return'] = __( 'Delhivery: Create Return Shipment (RVP)', 'ratna-gems' );
        }
        
    } else {
        // When no AWB yet, show manifest action.
        $actions['rg_manifest_with_delhivery'] = __( 'Delhivery: Manifest Shipment', 'ratna-gems' );
        $actions['rg_delhivery_check_serviceability'] = __( 'Delhivery: Check Pincode Serviceability', 'ratna-gems' );
        $actions['rg_delhivery_estimate_cost'] = __( 'Delhivery: Estimate Shipping Cost', 'ratna-gems' );
    }

    return $actions;
}

// Handle Download Invoice action from dropdown
add_action( 'woocommerce_order_action_rg_download_invoice', 'rg_handle_download_invoice_action' );
/**
 * Handle the Download Invoice order action.
 * This redirects to the invoice page in a new tab via JS.
 */
function rg_handle_download_invoice_action( WC_Order $order ): void {
    // Add a transient to trigger redirect on next page load
    set_transient( 'rg_invoice_redirect_' . $order->get_id(), true, 60 );
    
    // Add admin notice
    $order->add_order_note( __( 'Invoice downloaded.', 'ratna-gems' ), false, true );
}

// Add script to open invoice in new tab when action is triggered
add_action( 'admin_footer', 'rg_invoice_redirect_script' );
/**
 * Output script to open invoice in new tab.
 */
function rg_invoice_redirect_script(): void {
    global $post, $theorder;
    
    $screen = get_current_screen();
    if ( ! $screen || ( strpos( $screen->id, 'shop_order' ) === false && strpos( $screen->id, 'wc-orders' ) === false ) ) {
        return;
    }
    
    // Get order ID
    $order_id = 0;
    if ( isset( $theorder ) && $theorder instanceof WC_Order ) {
        $order_id = $theorder->get_id();
    } elseif ( isset( $post ) && $post->post_type === 'shop_order' ) {
        $order_id = $post->ID;
    } elseif ( isset( $_GET['id'] ) ) {
        $order_id = absint( $_GET['id'] );
    }
    
    if ( ! $order_id ) {
        return;
    }
    
    // Check if we should redirect
    if ( get_transient( 'rg_invoice_redirect_' . $order_id ) ) {
        delete_transient( 'rg_invoice_redirect_' . $order_id );
        $invoice_url = admin_url( 'admin-ajax.php?action=rg_print_invoice&order_id=' . $order_id . '&security=' . wp_create_nonce( 'rg_delhivery_nonce' ) );
        ?>
        <script>
        window.open('<?php echo esc_url( $invoice_url ); ?>', '_blank');
        </script>
        <?php
    }
}

// =============================================================================
// MANIFEST SHIPMENT
// =============================================================================

add_action( 'woocommerce_order_action_rg_manifest_with_delhivery', 'rg_delhivery_handle_manifest_action' );
/**
 * Manifest the order with Delhivery and persist AWB/status.
 */
function rg_delhivery_handle_manifest_action( WC_Order $order ): void {
    $client = rg_delhivery_client();

    if ( ! $client || ! $client->is_configured() ) {
        $order->add_order_note( __( 'Delhivery manifest failed: credentials are missing.', 'ratna-gems' ) );
        return;
    }

    if ( $order->get_meta( '_delhivery_awb' ) ) {
        $order->add_order_note( __( 'Delhivery manifest skipped because an AWB already exists for this order.', 'ratna-gems' ) );
        return;
    }

    // Check serviceability first
    $pincode = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
    $serviceability = $client->pincode_serviceability( $pincode );
    
    if ( is_wp_error( $serviceability ) ) {
        $order->add_order_note( sprintf( __( 'Delhivery serviceability check failed: %s', 'ratna-gems' ), $serviceability->get_error_message() ) );
        // Continue with manifest anyway - the main API will reject if truly not serviceable
    } elseif ( ! $serviceability['is_serviceable'] ) {
        $remarks = ! empty( $serviceability['remarks'] ) ? ' Remarks: ' . implode( ', ', $serviceability['remarks'] ) : '';
        $order->add_order_note( sprintf( __( 'Warning: Pincode %s may not be serviceable.%s', 'ratna-gems' ), $pincode, $remarks ) );
    }

    $result = $client->manifest_order( $order );

    if ( is_wp_error( $result ) ) {
        $order->add_order_note( sprintf( __( 'Delhivery manifest failed: %s', 'ratna-gems' ), $result->get_error_message() ) );
        return;
    }

    $order->update_meta_data( '_delhivery_awb', $result['awb'] );
    
    // Set initial status to "Manifested" - the API's "status" field is upload status, not shipment status
    // Shipment status will be updated when tracking is refreshed or via webhook
    $order->update_meta_data( '_delhivery_status', 'Manifested' );
    
    // Set initial status type to UD (Undelivered/Manifested - not yet picked up)
    $order->update_meta_data( '_delhivery_status_type', 'UD' );
    $order->update_meta_data( '_delhivery_manifest_time', current_time( 'mysql' ) );
    
    // Clear any stale data from previous shipments
    $order->delete_meta_data( '_delhivery_pickup_id' );
    $order->delete_meta_data( '_delhivery_is_ndr' );
    $order->delete_meta_data( '_delhivery_cancelled_awb' );

    $order->add_order_note(
        sprintf(
            /* translators: 1: AWB number */
            __( '✅ Delhivery shipment created successfully. AWB: %s | Initial Status: Manifested', 'ratna-gems' ),
            $result['awb']
        )
    );

    // Trigger action for other integrations
    do_action( 'rg_delhivery_order_manifested', $order, $result );

    $order->save();
}

// =============================================================================
// CANCEL SHIPMENT
// =============================================================================

add_action( 'woocommerce_order_action_rg_cancel_delhivery_shipment', 'rg_delhivery_handle_cancellation_action' );
/**
 * Cancel a Delhivery shipment and clear order metadata.
 */
function rg_delhivery_handle_cancellation_action( WC_Order $order ): void {
    $client = rg_delhivery_client();

    if ( ! $client || ! $client->is_configured() ) {
        $order->add_order_note( __( 'Delhivery cancellation failed: credentials are missing.', 'ratna-gems' ) );
        return;
    }

    $awb = (string) $order->get_meta( '_delhivery_awb' );
    if ( '' === $awb ) {
        $order->add_order_note( __( 'Delhivery cancellation skipped because no AWB is attached to this order.', 'ratna-gems' ) );
        return;
    }

    $result = $client->cancel_shipment( $awb );

    if ( is_wp_error( $result ) ) {
        $order->add_order_note( sprintf( __( 'Delhivery cancellation failed: %s', 'ratna-gems' ), $result->get_error_message() ) );
        return;
    }

    // Store old AWB for reference before clearing
    $order->update_meta_data( '_delhivery_cancelled_awb', $awb );
    $order->update_meta_data( '_delhivery_cancelled_time', current_time( 'mysql' ) );
    
    // Clear ALL Delhivery related metadata for clean re-manifest
    $order->delete_meta_data( '_delhivery_awb' );
    $order->delete_meta_data( '_delhivery_status' );
    $order->delete_meta_data( '_delhivery_status_type' );
    $order->delete_meta_data( '_delhivery_status_code' );
    $order->delete_meta_data( '_delhivery_pickup_id' );
    $order->delete_meta_data( '_delhivery_manifest_time' );
    $order->delete_meta_data( '_delhivery_last_update' );
    $order->delete_meta_data( '_delhivery_last_location' );
    $order->delete_meta_data( '_delhivery_expected_delivery' );
    $order->delete_meta_data( '_delhivery_is_ndr' );

    $order->add_order_note( sprintf( __( '❌ Delhivery shipment %s cancelled successfully. Order can now be re-manifested.', 'ratna-gems' ), $awb ) );

    do_action( 'rg_delhivery_order_cancelled', $order, $awb );

    $order->save();
}

// =============================================================================
// NDR RE-ATTEMPT
// =============================================================================

add_action( 'woocommerce_order_action_rg_delhivery_ndr_reattempt', 'rg_delhivery_handle_ndr_reattempt_action' );
/**
 * Request an NDR re-attempt with Delhivery.
 */
function rg_delhivery_handle_ndr_reattempt_action( WC_Order $order ): void {
    $client = rg_delhivery_client();

    if ( ! $client || ! $client->is_configured() ) {
        $order->add_order_note( __( 'Delhivery NDR failed: credentials are missing.', 'ratna-gems' ) );
        return;
    }

    $awb = (string) $order->get_meta( '_delhivery_awb' );
    if ( '' === $awb ) {
        $order->add_order_note( __( 'Delhivery NDR re-attempt skipped because no AWB is attached to this order.', 'ratna-gems' ) );
        return;
    }

    // Check if NDR action is applicable
    $ndr_status = $client->get_ndr_status( $awb );
    if ( ! is_wp_error( $ndr_status ) && ! $ndr_status['can_reattempt'] ) {
        $order->add_order_note( sprintf( 
            __( 'Delhivery NDR re-attempt not applicable. Current status code: %s', 'ratna-gems' ), 
            $ndr_status['nsl_code'] 
        ) );
        return;
    }

    $res = $client->ndr_action( $awb, 'RE-ATTEMPT' );
    if ( is_wp_error( $res ) ) {
        $order->add_order_note( sprintf( __( 'Delhivery NDR re-attempt failed: %s', 'ratna-gems' ), $res->get_error_message() ) );
        return;
    }

    $upl_id = rg_delhivery_extract_upl_id( $res );
    $status = rg_delhivery_extract_status( $res, 'RE-ATTEMPT' );

    $order->update_meta_data( '_delhivery_ndr_status', $status );
    $order->update_meta_data( '_delhivery_ndr_action', 'RE-ATTEMPT' );
    $order->update_meta_data( '_delhivery_ndr_time', current_time( 'mysql' ) );
    
    if ( $upl_id ) {
        $order->update_meta_data( '_delhivery_ndr_upl_id', $upl_id );
    }
    $order->save();

    $note = __( 'Delhivery NDR re-attempt requested successfully.', 'ratna-gems' );
    if ( $upl_id ) {
        $note .= ' ' . sprintf( __( 'UPL ID: %s', 'ratna-gems' ), $upl_id );
    }

    $order->add_order_note( $note );
}

// =============================================================================
// NDR RESCHEDULE
// =============================================================================

add_action( 'woocommerce_order_action_rg_delhivery_ndr_reschedule', 'rg_delhivery_handle_ndr_reschedule_action' );
/**
 * Request NDR pickup reschedule.
 */
function rg_delhivery_handle_ndr_reschedule_action( WC_Order $order ): void {
    $client = rg_delhivery_client();

    if ( ! $client || ! $client->is_configured() ) {
        $order->add_order_note( __( 'Delhivery NDR reschedule failed: credentials are missing.', 'ratna-gems' ) );
        return;
    }

    $awb = (string) $order->get_meta( '_delhivery_awb' );
    if ( '' === $awb ) {
        $order->add_order_note( __( 'Delhivery NDR reschedule skipped because no AWB is attached.', 'ratna-gems' ) );
        return;
    }

    // Schedule for next business day
    $pickup_date = wp_date( 'Y-m-d', strtotime( '+1 weekday' ) );

    $res = $client->ndr_action( $awb, 'PICKUP_RESCHEDULE', array( 'pickup_date' => $pickup_date ) );
    if ( is_wp_error( $res ) ) {
        $order->add_order_note( sprintf( __( 'Delhivery NDR reschedule failed: %s', 'ratna-gems' ), $res->get_error_message() ) );
        return;
    }

    $upl_id = rg_delhivery_extract_upl_id( $res );

    $order->update_meta_data( '_delhivery_ndr_status', 'PICKUP_RESCHEDULE' );
    $order->update_meta_data( '_delhivery_ndr_action', 'PICKUP_RESCHEDULE' );
    $order->update_meta_data( '_delhivery_ndr_pickup_date', $pickup_date );
    $order->update_meta_data( '_delhivery_ndr_time', current_time( 'mysql' ) );
    
    if ( $upl_id ) {
        $order->update_meta_data( '_delhivery_ndr_upl_id', $upl_id );
    }
    $order->save();

    $order->add_order_note( sprintf( 
        __( 'Delhivery pickup rescheduled for %s. UPL ID: %s', 'ratna-gems' ), 
        $pickup_date, 
        $upl_id ?: 'N/A' 
    ) );
}

// =============================================================================
// REFRESH TRACKING STATUS
// =============================================================================

add_action( 'woocommerce_order_action_rg_delhivery_refresh_status', 'rg_delhivery_handle_refresh_status_action' );
/**
 * Refresh tracking status from Delhivery API.
 */
function rg_delhivery_handle_refresh_status_action( WC_Order $order ): void {
    $client = rg_delhivery_client();

    if ( ! $client || ! $client->is_configured() ) {
        $order->add_order_note( __( 'Delhivery status refresh failed: credentials are missing.', 'ratna-gems' ) );
        return;
    }

    $awb = (string) $order->get_meta( '_delhivery_awb' );
    if ( '' === $awb ) {
        $order->add_order_note( __( 'No AWB found to track.', 'ratna-gems' ) );
        return;
    }

    $summary = $client->get_tracking_summary( $awb );
    if ( is_wp_error( $summary ) ) {
        $order->add_order_note( sprintf( __( 'Delhivery tracking failed: %s', 'ratna-gems' ), $summary->get_error_message() ) );
        return;
    }

    $old_status = $order->get_meta( '_delhivery_status' );
    $new_status = $summary['status'] ?: $summary['status_type'];

    $order->update_meta_data( '_delhivery_status', $new_status );
    $order->update_meta_data( '_delhivery_status_type', $summary['status_type'] );
    $order->update_meta_data( '_delhivery_last_location', $summary['status_location'] );
    $order->update_meta_data( '_delhivery_last_update', $summary['status_datetime'] );
    
    if ( $summary['expected_date'] ) {
        $order->update_meta_data( '_delhivery_expected_delivery', $summary['expected_date'] );
    }

    $order->save();

    $note = sprintf(
        __( 'Delhivery status updated: %s at %s (%s)', 'ratna-gems' ),
        $new_status,
        $summary['status_location'] ?: 'Unknown location',
        $summary['status_datetime'] ?: 'Unknown time'
    );

    if ( $old_status !== $new_status ) {
        $note .= sprintf( __( ' [Changed from: %s]', 'ratna-gems' ), $old_status ?: 'None' );
    }

    $order->add_order_note( $note );

    // Auto-update WooCommerce order status for delivered/RTO
    rg_delhivery_maybe_update_order_status( $order, $new_status );
}

// =============================================================================
// CHECK SERVICEABILITY
// =============================================================================

add_action( 'woocommerce_order_action_rg_delhivery_check_serviceability', 'rg_delhivery_handle_serviceability_action' );
/**
 * Check pincode serviceability for the order.
 */
function rg_delhivery_handle_serviceability_action( WC_Order $order ): void {
    $client = rg_delhivery_client();

    if ( ! $client || ! $client->is_configured() ) {
        $order->add_order_note( __( 'Delhivery serviceability check failed: credentials are missing.', 'ratna-gems' ) );
        return;
    }

    $pincode = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
    if ( ! $pincode ) {
        $order->add_order_note( __( 'No pincode available for serviceability check.', 'ratna-gems' ) );
        return;
    }

    $result = $client->pincode_serviceability( $pincode );

    if ( is_wp_error( $result ) ) {
        $order->add_order_note( sprintf( __( 'Delhivery serviceability check failed: %s', 'ratna-gems' ), $result->get_error_message() ) );
        return;
    }

    $status = $result['is_serviceable'] ? __( 'SERVICEABLE', 'ratna-gems' ) : __( 'NOT SERVICEABLE', 'ratna-gems' );
    $embargo = $result['has_embargo'] ? __( ' (Embargo in effect)', 'ratna-gems' ) : '';
    $remarks = ! empty( $result['remarks'] ) ? ' Remarks: ' . implode( ', ', $result['remarks'] ) : '';

    $order->add_order_note( sprintf(
        __( 'Delhivery Pincode %s: %s%s%s', 'ratna-gems' ),
        $pincode,
        $status,
        $embargo,
        $remarks
    ) );
}

// =============================================================================
// ESTIMATE SHIPPING COST
// =============================================================================

add_action( 'woocommerce_order_action_rg_delhivery_estimate_cost', 'rg_delhivery_handle_estimate_cost_action' );
/**
 * Estimate shipping cost for the order.
 */
function rg_delhivery_handle_estimate_cost_action( WC_Order $order ): void {
    $client = rg_delhivery_client();

    if ( ! $client || ! $client->is_configured() ) {
        $order->add_order_note( __( 'Delhivery cost estimation failed: credentials are missing.', 'ratna-gems' ) );
        return;
    }

    // Try Surface mode first
    $result_surface = $client->calculate_order_shipping_cost( $order, 'S' );
    $result_express = $client->calculate_order_shipping_cost( $order, 'E' );

    $note = __( 'Delhivery Shipping Cost Estimate:', 'ratna-gems' ) . "\n";

    if ( ! is_wp_error( $result_surface ) && isset( $result_surface['cost'] ) ) {
        $cost_data = $result_surface['cost'];
        $total = $cost_data['total_amount'] ?? $cost_data['total'] ?? 'N/A';
        $note .= sprintf( __( '• Surface: ₹%s', 'ratna-gems' ), $total ) . "\n";
    } else {
        $note .= __( '• Surface: Unable to calculate', 'ratna-gems' ) . "\n";
    }

    if ( ! is_wp_error( $result_express ) && isset( $result_express['cost'] ) ) {
        $cost_data = $result_express['cost'];
        $total = $cost_data['total_amount'] ?? $cost_data['total'] ?? 'N/A';
        $note .= sprintf( __( '• Express: ₹%s', 'ratna-gems' ), $total ) . "\n";
    } else {
        $note .= __( '• Express: Unable to calculate', 'ratna-gems' ) . "\n";
    }

    $note .= __( '(Estimates may vary based on actual weight/dimensions)', 'ratna-gems' );

    $order->add_order_note( $note );
}

// =============================================================================
// UPDATE E-WAYBILL
// =============================================================================

add_action( 'woocommerce_order_action_rg_delhivery_update_ewaybill', 'rg_delhivery_handle_ewaybill_action' );
/**
 * Update e-waybill for high-value shipments.
 */
function rg_delhivery_handle_ewaybill_action( WC_Order $order ): void {
    $client = rg_delhivery_client();

    if ( ! $client || ! $client->is_configured() ) {
        $order->add_order_note( __( 'Delhivery e-waybill update failed: credentials are missing.', 'ratna-gems' ) );
        return;
    }

    $awb = (string) $order->get_meta( '_delhivery_awb' );
    if ( '' === $awb ) {
        $order->add_order_note( __( 'No AWB found for e-waybill update.', 'ratna-gems' ) );
        return;
    }

    // Check if e-waybill number is stored
    $ewbn = $order->get_meta( '_delhivery_ewaybill_number' );
    if ( ! $ewbn ) {
        $order->add_order_note( __( 'E-waybill number not found. Please add _delhivery_ewaybill_number to order meta first.', 'ratna-gems' ) );
        return;
    }

    $result = $client->update_ewaybill( $awb, $ewbn );

    if ( is_wp_error( $result ) ) {
        $order->add_order_note( sprintf( __( 'Delhivery e-waybill update failed: %s', 'ratna-gems' ), $result->get_error_message() ) );
        return;
    }

    $order->update_meta_data( '_delhivery_ewaybill_updated', current_time( 'mysql' ) );
    $order->save();

    $order->add_order_note( sprintf( __( 'Delhivery e-waybill %s linked to AWB %s successfully.', 'ratna-gems' ), $ewbn, $awb ) );
}

// =============================================================================
// CREATE RETURN SHIPMENT (RVP)
// =============================================================================

add_action( 'woocommerce_order_action_rg_delhivery_create_return', 'rg_delhivery_handle_return_action' );
/**
 * Create a return (RVP) shipment for the order.
 */
function rg_delhivery_handle_return_action( WC_Order $order ): void {
    $client = rg_delhivery_client();

    if ( ! $client || ! $client->is_configured() ) {
        $order->add_order_note( __( 'Delhivery return shipment failed: credentials are missing.', 'ratna-gems' ) );
        return;
    }

    // Check if return already exists
    if ( $order->get_meta( '_delhivery_return_awb' ) ) {
        $order->add_order_note( __( 'A return shipment already exists for this order.', 'ratna-gems' ) );
        return;
    }

    $result = $client->create_return_shipment( $order );

    if ( is_wp_error( $result ) ) {
        $order->add_order_note( sprintf( __( 'Delhivery return shipment failed: %s', 'ratna-gems' ), $result->get_error_message() ) );
        return;
    }

    $packages = $result['packages'] ?? array();
    if ( empty( $packages[0]['waybill'] ) ) {
        $order->add_order_note( __( 'Delhivery did not return a waybill for the return shipment.', 'ratna-gems' ) );
        return;
    }

    $return_awb = $packages[0]['waybill'];
    $order->update_meta_data( '_delhivery_return_awb', $return_awb );
    $order->update_meta_data( '_delhivery_return_status', $packages[0]['status'] ?? 'Created' );
    $order->update_meta_data( '_delhivery_return_created', current_time( 'mysql' ) );
    $order->save();

    $order->add_order_note( sprintf( __( 'Delhivery return shipment created. Return AWB: %s', 'ratna-gems' ), $return_awb ) );

    do_action( 'rg_delhivery_return_created', $order, $return_awb );
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Extract UPL ID from NDR response
 */
function rg_delhivery_extract_upl_id( $response ): string {
    if ( ! is_array( $response ) ) return '';
    
    foreach ( array( 'upl_id', 'UPL_ID', 'upl', 'uplId' ) as $key ) {
        if ( ! empty( $response[ $key ] ) ) {
            return sanitize_text_field( (string) $response[ $key ] );
        }
    }
    return '';
}

/**
 * Extract status from response
 */
function rg_delhivery_extract_status( $response, string $default = '' ): string {
    if ( ! is_array( $response ) ) return $default;
    
    foreach ( array( 'status', 'Status', 'statusmessage', 'StatusMessage' ) as $key ) {
        if ( ! empty( $response[ $key ] ) ) {
            return sanitize_text_field( (string) $response[ $key ] );
        }
    }
    return $default;
}

// Note: rg_delhivery_maybe_update_order_status() is defined in delhivery-loader.php
// We only define it here if it doesn't exist (for backwards compatibility)
if ( ! function_exists( 'rg_delhivery_maybe_update_order_status' ) ) {
/**
 * Auto-update WooCommerce order status based on Delhivery status
 * 
 * Official Delhivery Status Reference (from documentation lines 1567-1601):
 * - Forward: StatusType=DL, Status=Delivered -> completed
 * - RTO: StatusType=DL, Status=RTO -> cancelled
 * - Reverse: StatusType=DL, Status=DTO -> completed
 * - Cancelled: StatusType=CN, Status=Canceled/Closed -> cancelled
 */
function rg_delhivery_maybe_update_order_status( WC_Order $order, string $delhivery_status ): void {
    $auto_update = apply_filters( 'rg_delhivery_auto_update_order_status', true, $order, $delhivery_status );
    if ( ! $auto_update ) return;

    // Official Delhivery status to WooCommerce status mapping
    $status_mapping = apply_filters( 'rg_delhivery_status_mapping', array(
        // Forward shipment delivered (StatusType: DL, Status: Delivered)
        'Delivered'     => 'completed',
        
        // RTO completed (StatusType: DL, Status: RTO)
        'RTO'           => 'cancelled',
        
        // Reverse pickup completed (StatusType: DL, Status: DTO - Deliver to Origin)
        'DTO'           => 'completed',
        
        // Cancelled (StatusType: CN)
        'Canceled'      => 'cancelled',
        'Cancelled'     => 'cancelled',
        'Closed'        => 'cancelled',
    ), $order );

    $normalized = strtoupper( trim( $delhivery_status ) );
    $new_wc_status = null;

    foreach ( $status_mapping as $dlv_status => $wc_status ) {
        if ( strtoupper( $dlv_status ) === $normalized || false !== strpos( $normalized, strtoupper( $dlv_status ) ) ) {
            $new_wc_status = $wc_status;
            break;
        }
    }

    if ( $new_wc_status && $order->get_status() !== $new_wc_status ) {
        $order->update_status( 
            $new_wc_status, 
            sprintf( __( 'Auto-updated based on Delhivery status: %s', 'ratna-gems' ), $delhivery_status )
        );
    }
}
}

// =============================================================================
// BULK ACTIONS
// =============================================================================

add_filter( 'bulk_actions-edit-shop_order', 'rg_delhivery_register_bulk_actions' );
add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'rg_delhivery_register_bulk_actions' );
/**
 * Register bulk actions for Delhivery.
 */
function rg_delhivery_register_bulk_actions( array $actions ): array {
    $actions['rg_delhivery_bulk_manifest'] = __( 'Delhivery: Manifest Selected', 'ratna-gems' );
    $actions['rg_delhivery_bulk_refresh']  = __( 'Delhivery: Refresh Tracking', 'ratna-gems' );
    return $actions;
}

add_filter( 'handle_bulk_actions-edit-shop_order', 'rg_delhivery_handle_bulk_actions', 10, 3 );
add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', 'rg_delhivery_handle_bulk_actions', 10, 3 );
/**
 * Handle Delhivery bulk actions.
 */
function rg_delhivery_handle_bulk_actions( string $redirect_to, string $action, array $order_ids ): string {
    if ( 'rg_delhivery_bulk_manifest' === $action ) {
        $manifested = 0;
        $skipped = 0;
        $already_manifested = 0;
        
        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) continue;
            
            // Skip if already has AWB
            if ( $order->get_meta( '_delhivery_awb' ) ) {
                $already_manifested++;
                continue;
            }
            
            // Check serviceability
            $pincode = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
            if ( function_exists( 'rg_delhivery_check_pincode_serviceable' ) && ! rg_delhivery_check_pincode_serviceable( $pincode ) ) {
                $skipped++;
                $order->add_order_note( 
                    sprintf( __( 'Delhivery manifest skipped: Pincode %s is not serviceable. Use another courier.', 'ratna-gems' ), $pincode ),
                    false,
                    true
                );
                continue;
            }
            
            // Manifest the order
            rg_delhivery_handle_manifest_action( $order );
            if ( $order->get_meta( '_delhivery_awb' ) ) {
                $manifested++;
            }
        }
        
        $redirect_to = add_query_arg( array(
            'rg_delhivery_manifested' => $manifested,
            'rg_delhivery_skipped'    => $skipped,
            'rg_delhivery_already'    => $already_manifested,
        ), $redirect_to );
    }

    if ( 'rg_delhivery_bulk_refresh' === $action ) {
        $refreshed = 0;
        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order && $order->get_meta( '_delhivery_awb' ) ) {
                rg_delhivery_handle_refresh_status_action( $order );
                $refreshed++;
            }
        }
        $redirect_to = add_query_arg( 'rg_delhivery_refreshed', $refreshed, $redirect_to );
    }

    return $redirect_to;
}

add_action( 'admin_notices', 'rg_delhivery_bulk_action_notices' );
/**
 * Display notices after bulk actions.
 */
function rg_delhivery_bulk_action_notices(): void {
    if ( isset( $_GET['rg_delhivery_manifested'] ) ) {
        $manifested = absint( $_GET['rg_delhivery_manifested'] );
        $skipped = isset( $_GET['rg_delhivery_skipped'] ) ? absint( $_GET['rg_delhivery_skipped'] ) : 0;
        $already = isset( $_GET['rg_delhivery_already'] ) ? absint( $_GET['rg_delhivery_already'] ) : 0;
        
        $messages = array();
        
        if ( $manifested > 0 ) {
            $messages[] = sprintf( 
                _n( '%d order manifested with Delhivery.', '%d orders manifested with Delhivery.', $manifested, 'ratna-gems' ), 
                $manifested 
            );
        }
        
        if ( $skipped > 0 ) {
            $messages[] = sprintf( 
                _n( '%d order skipped (pincode not serviceable).', '%d orders skipped (pincode not serviceable).', $skipped, 'ratna-gems' ), 
                $skipped 
            );
        }
        
        if ( $already > 0 ) {
            $messages[] = sprintf( 
                _n( '%d order already manifested.', '%d orders already manifested.', $already, 'ratna-gems' ), 
                $already 
            );
        }
        
        if ( ! empty( $messages ) ) {
            $notice_class = $skipped > 0 && $manifested === 0 ? 'notice-warning' : 'notice-success';
            printf(
                '<div class="notice %s is-dismissible"><p>%s</p></div>',
                esc_attr( $notice_class ),
                esc_html( implode( ' ', $messages ) )
            );
        }
    }

    if ( ! empty( $_GET['rg_delhivery_refreshed'] ) ) {
        $count = absint( $_GET['rg_delhivery_refreshed'] );
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html( sprintf( _n( '%d order tracking refreshed.', '%d orders tracking refreshed.', $count, 'ratna-gems' ), $count ) )
        );
    }
}
