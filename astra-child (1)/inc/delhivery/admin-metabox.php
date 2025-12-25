<?php
/**
 * Enhanced Admin Metabox for Delhivery Shipment Management.
 * Displays all shipment info and provides comprehensive controls.
 *
 * @package Ratna Gems
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'add_meta_boxes', 'rg_delhivery_register_metabox' );
/**
 * Register the Delhivery metabox on order edit screens.
 */
function rg_delhivery_register_metabox(): void {
    $screen = class_exists( 'Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
        && wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
        ? wc_get_page_screen_id( 'shop-order' )
        : 'shop_order';

    add_meta_box(
        'rg_delhivery_shipment',
        __( '🚚 Delhivery Shipment', 'ratna-gems' ),
        'rg_delhivery_render_metabox',
        $screen,
        'side',
        'high'
    );
}

/**
 * Render the Delhivery metabox content.
 */
function rg_delhivery_render_metabox( $post_or_order ): void {
    $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
    if ( ! $order ) {
        echo '<p>' . esc_html__( 'Order not found.', 'ratna-gems' ) . '</p>';
        return;
    }

    $client = rg_delhivery_client();
    if ( ! $client || ! $client->is_configured() ) {
        echo '<div class="notice notice-warning inline"><p>';
        echo esc_html__( 'Delhivery API credentials not configured.', 'ratna-gems' );
        echo '</p></div>';
        return;
    }

    // Get all Delhivery meta
    $awb = $order->get_meta( '_delhivery_awb' );
    $status = $order->get_meta( '_delhivery_status' );
    $pickup_id = $order->get_meta( '_delhivery_pickup_id' );
    $pickup_date = $order->get_meta( '_delhivery_pickup_date' );
    $manifest_time = $order->get_meta( '_delhivery_manifest_time' );
    $last_update = $order->get_meta( '_delhivery_last_update' );
    $last_location = $order->get_meta( '_delhivery_last_location' );
    $expected_delivery = $order->get_meta( '_delhivery_expected_delivery' );
    $return_awb = $order->get_meta( '_delhivery_return_awb' );
    $return_status = $order->get_meta( '_delhivery_return_status' );
    $ewaybill = $order->get_meta( '_delhivery_ewaybill_number' );
    $pod_url = $order->get_meta( '_delhivery_pod_url' );
    $signature_url = $order->get_meta( '_delhivery_signature_url' );
    
    // Package dimensions
    $pkg_weight = $order->get_meta( '_delhivery_package_weight' );
    $pkg_length = $order->get_meta( '_delhivery_package_length' );
    $pkg_width = $order->get_meta( '_delhivery_package_width' );
    $pkg_height = $order->get_meta( '_delhivery_package_height' );
    $pkg_qty = $order->get_meta( '_delhivery_package_qty' );
    
    // Order payment info (needed for estimate cost)
    $order_payment_method = strtolower( $order->get_payment_method() );
    $is_cod_order = in_array( $order_payment_method, array( 'cod', 'cashondelivery' ), true );
    $pincode = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
    
    // Get StatusType from order meta (official Delhivery field)
    $status_type = $order->get_meta( '_delhivery_status_type' );
    $status_type_upper = strtoupper( trim( $status_type ) );
    
    // =========================================================================
    // STATUS DETECTION - Based on Official Delhivery B2C API Documentation
    // =========================================================================
    // StatusType Reference (from Webhook documentation):
    //
    // FORWARD SHIPMENT (warehouse → customer):
    //   UD = Undelivered    → Manifested, Not Picked, In Transit, Pending, Dispatched
    //   DL = Delivered      → Delivered (terminal)
    //
    // RETURN SHIPMENT (RTO - undelivered returns):
    //   RT = Return         → In Transit, Pending, Dispatched (RTO journey)
    //   DL = Delivered      → RTO (returned to origin, terminal)
    //
    // REVERSE SHIPMENT (RVP - pickup from customer):
    //   PP = Pickup Pending → Open, Scheduled, Dispatched (before pickup)
    //   PU = Picked Up      → In Transit, Pending, Dispatched (after pickup)
    //   DL = Delivered      → DTO (delivered to origin, terminal)
    //   CN = Canceled       → Canceled (reverse pickup cancelled)
    //
    // Status values within each StatusType:
    //   Manifested, Not Picked, In Transit, Pending, Dispatched, 
    //   Delivered, RTO, DTO, Open, Scheduled, Canceled
    // =========================================================================
    $status_upper = strtoupper( trim( $status ) );
    
    // STEP 1: Classify by StatusType (PRIMARY indicator)
    $is_type_forward   = 'UD' === $status_type_upper;  // Forward journey active
    $is_type_terminal  = 'DL' === $status_type_upper;  // Terminal: Delivered/RTO/DTO
    $is_type_rto       = 'RT' === $status_type_upper;  // RTO journey active
    $is_type_rvp_pre   = 'PP' === $status_type_upper;  // Reverse pickup pending
    $is_type_rvp_post  = 'PU' === $status_type_upper;  // Reverse picked up
    $is_type_cancelled = 'CN' === $status_type_upper;  // Cancelled (RVP only)
    $is_type_unknown   = empty( $status_type_upper );  // Legacy/no StatusType
    
    // STEP 2: Detect terminal states (StatusType = DL)
    // DL + Delivered = Forward delivered
    // DL + RTO = Return completed
    // DL + DTO = Reverse pickup completed
    $is_delivered = $is_type_terminal && ! in_array( $status_upper, array( 'RTO', 'DTO' ), true );
    $is_rto       = $is_type_terminal && 'RTO' === $status_upper;
    $is_dto       = $is_type_terminal && 'DTO' === $status_upper;
    
    // RTO journey in progress (StatusType = RT)
    $is_rto_in_progress = $is_type_rto;
    if ( $is_rto_in_progress ) {
        $is_rto = true; // Treat RT as RTO for display purposes
    }
    
    // Cancelled detection (StatusType CN for RVP, or Status text for forward)
    $is_cancelled = $is_type_cancelled 
                 || in_array( $status_upper, array( 'CANCELLED', 'CANCELED', 'CLOSED' ), true );
    
    // NDR detection - check order meta flag set by webhook
    $is_ndr = 'yes' === $order->get_meta( '_delhivery_is_ndr' );
    
    // STEP 3: Forward journey states (StatusType = UD)
    // These states are only valid when StatusType is UD (forward journey)
    $is_forward_active = $is_type_forward || ( $is_type_unknown && ! $is_delivered && ! $is_rto && ! $is_cancelled );
    
    // Dispatched = Out for delivery (cancellation NOT allowed per docs)
    $is_dispatched = $is_forward_active && ( 
                      'DISPATCHED' === $status_upper 
                   || false !== strpos( $status_upper, 'OUT FOR' ) 
                   );
    
    // In Transit = Moving between hubs
    $is_in_transit = $is_forward_active && (
                      'IN TRANSIT' === $status_upper 
                   || ( false !== strpos( $status_upper, 'TRANSIT' ) && 'NOT PICKED' !== $status_upper )
                   );
    
    // Pending = At destination hub, awaiting dispatch
    $is_pending = $is_forward_active && 'PENDING' === $status_upper;
    
    // Manifested = AWB created, not yet picked up (before pickup = full refund on cancel)
    // Per docs: Manifested, Not Picked are before-pickup states
    $before_pickup_statuses = array( 'MANIFESTED', 'NOT PICKED', 'PICKUP SCHEDULED', 'OPEN', 'SCHEDULED', '' );
    $is_manifested = $is_forward_active && (
                      in_array( $status_upper, $before_pickup_statuses, true )
                   || ( empty( $status_upper ) && ! empty( $awb ) )
                   );
    
    // STEP 4: Reverse pickup states (StatusType = PP or PU)
    $is_rvp = $is_type_rvp_pre || $is_type_rvp_post;
    $is_rvp_scheduled = $is_type_rvp_pre && in_array( $status_upper, array( 'OPEN', 'SCHEDULED' ), true );
    
    // STEP 5: Fallback for legacy orders without StatusType
    if ( $is_type_unknown && ! empty( $status_upper ) ) {
        if ( 'DELIVERED' === $status_upper ) { 
            $is_delivered = true; 
            $is_manifested = false; 
        }
        if ( 'RTO' === $status_upper || false !== strpos( $status_upper, 'RTO' ) || 'RETURNED' === $status_upper ) { 
            $is_rto = true; 
            $is_manifested = false; 
        }
        if ( 'DTO' === $status_upper ) { 
            $is_dto = true; 
            $is_manifested = false; 
        }
    }
    
    // Final/terminal states - no further forward actions possible
    $is_final = $is_delivered || $is_rto || $is_dto || $is_cancelled || 'LOST' === $status_upper;
    
    // Cancellable states per official docs:
    // - Forward: Manifested, In Transit, Pending (NOT Dispatched)
    // - RVP: Scheduled (StatusType PP with Status Scheduled)
    // NOT cancellable: Dispatched, Delivered, DTO, RTO, LOST, Closed
    $can_cancel = ! $is_final && ! $is_dispatched && ! $is_rto_in_progress;

    // Output nonce
    wp_nonce_field( 'rg_delhivery_metabox', 'rg_delhivery_metabox_nonce' );
    ?>
    <style>
        #rg_delhivery_shipment .inside { padding: 0; }
        .rg-dlv-section { padding: 12px; border-bottom: 1px solid #ddd; }
        .rg-dlv-section:last-child { border-bottom: none; }
        .rg-dlv-section h4 { margin: 0 0 10px; font-size: 13px; color: #1d2327; }
        .rg-dlv-row { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 12px; }
        .rg-dlv-row label { color: #646970; }
        .rg-dlv-row span { font-weight: 500; color: #1d2327; word-break: break-all; }
        .rg-dlv-awb { font-family: monospace; font-size: 14px; background: #f0f0f1; padding: 8px; border-radius: 4px; text-align: center; margin-bottom: 10px; }
        .rg-dlv-status { display: inline-block; padding: 4px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .rg-dlv-status.delivered { background: #d4edda; color: #155724; }
        .rg-dlv-status.in-transit { background: #fff3cd; color: #856404; }
        .rg-dlv-status.manifested { background: #cce5ff; color: #004085; }
        .rg-dlv-status.rto { background: #f8d7da; color: #721c24; }
        .rg-dlv-status.pending { background: #e2e3e5; color: #383d41; }
        .rg-dlv-status.ndr { background: #dc3545; color: #fff; }
        .rg-dlv-status.dispatched { background: #17a2b8; color: #fff; }
        .rg-dlv-status.cancelled { background: #6c757d; color: #fff; }
        .rg-dlv-status.warning { background: #ff8c00; color: #fff; }
        .rg-dlv-btn { width: 100%; margin-bottom: 6px; }
        .rg-dlv-btn-group { display: flex; gap: 6px; margin-bottom: 6px; }
        .rg-dlv-btn-group .button { flex: 1; }
        .rg-dlv-input-group { margin-bottom: 8px; }
        .rg-dlv-input-group label { display: block; font-size: 11px; margin-bottom: 3px; color: #646970; }
        .rg-dlv-input-group input, .rg-dlv-input-group select { width: 100%; }
        .rg-dlv-tabs { display: flex; border-bottom: 1px solid #ddd; }
        .rg-dlv-tab { flex: 1; padding: 8px; text-align: center; cursor: pointer; font-size: 11px; background: #f6f7f7; border: none; }
        .rg-dlv-tab.active { background: #fff; border-bottom: 2px solid #2271b1; font-weight: 600; }
        .rg-dlv-tab-content { display: none; }
        .rg-dlv-tab-content.active { display: block; }
        .rg-dlv-notice { padding: 8px; margin-bottom: 10px; border-radius: 3px; font-size: 12px; }
        .rg-dlv-notice.info { background: #d1ecf1; color: #0c5460; }
        .rg-dlv-notice.warning { background: #fff3cd; color: #856404; }
        .rg-dlv-notice.success { background: #d4edda; color: #155724; }
        .rg-dlv-loading { opacity: 0.5; pointer-events: none; }
        .rg-dlv-links { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px; }
        .rg-dlv-links a { font-size: 11px; }
    </style>

    <?php if ( $awb ) : ?>
        <!-- SHIPMENT EXISTS -->
        <div class="rg-dlv-section">
            <div class="rg-dlv-awb">
                <a href="https://www.delhivery.com/track/package/<?php echo esc_attr( $awb ); ?>" target="_blank" title="<?php esc_attr_e( 'Track on Delhivery', 'ratna-gems' ); ?>">
                    <?php echo esc_html( $awb ); ?> 🔗
                </a>
            </div>
            
            <?php
            // Determine status display class based on official Delhivery status flags
            $status_class = 'pending';
            if ( $is_ndr ) {
                $status_class = 'ndr';
            } elseif ( $is_delivered ) {
                $status_class = 'delivered';
            } elseif ( $is_dto ) {
                $status_class = 'delivered';
            } elseif ( $is_rto ) {
                $status_class = 'rto';
            } elseif ( $is_cancelled ) {
                $status_class = 'cancelled';
            } elseif ( $is_dispatched ) {
                $status_class = 'dispatched';
            } elseif ( $is_in_transit ) {
                $status_class = 'in-transit';
            } elseif ( $is_manifested ) {
                $status_class = 'manifested';
            } elseif ( $is_pending ) {
                $status_class = 'pending';
            }
            
            // Check for unusual status values that may be from API error response
            $unusual_statuses = array( 'fail', 'error', 'success', 'true', 'false', '' );
            $is_unusual_status = in_array( strtolower( trim( $status ) ), $unusual_statuses, true );
            if ( $is_unusual_status ) {
                $status_class = 'warning';
            }
            
            // Build display status with StatusType if available
            $display_status = $status ?: 'Unknown';
            if ( $status_type ) {
                $display_status .= ' (' . $status_type . ')';
            }
            ?>
            <div style="text-align: center; margin-bottom: 10px;">
                <span class="rg-dlv-status <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $display_status ); ?></span>
                <?php if ( $is_ndr ) : ?>
                    <div style="margin-top: 5px; font-size: 11px; color: #dc3545;">⚠️ <?php esc_html_e( 'NDR - Action Required', 'ratna-gems' ); ?></div>
                <?php endif; ?>
                <?php if ( $is_unusual_status ) : ?>
                    <div style="margin-top: 5px; font-size: 11px; color: #ff8c00;">
                        ⚠️ <?php esc_html_e( 'Status may be outdated. Click "Refresh Status" below to update.', 'ratna-gems' ); ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ( $last_location ) : ?>
            <div class="rg-dlv-row">
                <label><?php esc_html_e( 'Location:', 'ratna-gems' ); ?></label>
                <span><?php echo esc_html( $last_location ); ?></span>
            </div>
            <?php endif; ?>

            <?php if ( $last_update ) : ?>
            <div class="rg-dlv-row">
                <label><?php esc_html_e( 'Updated:', 'ratna-gems' ); ?></label>
                <span><?php echo esc_html( $last_update ); ?></span>
            </div>
            <?php endif; ?>

            <?php if ( $expected_delivery ) : ?>
            <div class="rg-dlv-row">
                <label><?php esc_html_e( 'ETA:', 'ratna-gems' ); ?></label>
                <span><?php echo esc_html( $expected_delivery ); ?></span>
            </div>
            <?php endif; ?>

            <?php if ( $manifest_time ) : ?>
            <div class="rg-dlv-row">
                <label><?php esc_html_e( 'Manifested:', 'ratna-gems' ); ?></label>
                <span><?php echo esc_html( $manifest_time ); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ( $pkg_weight || $pkg_length ) : ?>
            <div class="rg-dlv-row" style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed #ddd;">
                <label><?php esc_html_e( 'Package:', 'ratna-gems' ); ?></label>
                <span title="<?php esc_attr_e( 'Weight (g) × L × W × H (cm)', 'ratna-gems' ); ?>">
                    <?php 
                    echo esc_html( sprintf( 
                        '%dg • %d×%d×%dcm', 
                        (int) $pkg_weight, 
                        (int) $pkg_length, 
                        (int) $pkg_width, 
                        (int) $pkg_height 
                    ) ); 
                    ?>
                    <?php if ( $pkg_qty ) : ?>
                        <small style="color: #646970;">(<?php echo esc_html( $pkg_qty ); ?> <?php esc_html_e( 'items', 'ratna-gems' ); ?>)</small>
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
        </div>

        <!-- TABS -->
        <div class="rg-dlv-tabs">
            <button type="button" class="rg-dlv-tab active" data-tab="actions"><?php esc_html_e( 'Actions', 'ratna-gems' ); ?></button>
            <button type="button" class="rg-dlv-tab" data-tab="update"><?php esc_html_e( 'Update', 'ratna-gems' ); ?></button>
            <button type="button" class="rg-dlv-tab" data-tab="docs"><?php esc_html_e( 'Docs', 'ratna-gems' ); ?></button>
        </div>

        <!-- ACTIONS TAB -->
        <div class="rg-dlv-section rg-dlv-tab-content active" data-tab="actions">
            <button type="button" class="button rg-dlv-btn" id="rg-dlv-refresh-status">
                🔄 <?php esc_html_e( 'Refresh Status', 'ratna-gems' ); ?>
            </button>
            
            <div class="rg-dlv-btn-group">
                <button type="button" class="button" id="rg-dlv-print-label" title="<?php esc_attr_e( 'Download shipping label', 'ratna-gems' ); ?>" style="width: 100%;">
                    🏷️ <?php esc_html_e( 'Download Label', 'ratna-gems' ); ?>
                </button>
            </div>
            
            <div class="rg-dlv-btn-group">
                <button type="button" class="button" id="rg-dlv-print-invoice" title="<?php esc_attr_e( 'Download GST Invoice', 'ratna-gems' ); ?>" style="width: 100%;">
                    🧾 <?php esc_html_e( 'Download Invoice', 'ratna-gems' ); ?>
                </button>
            </div>
            
            <button type="button" class="button rg-dlv-btn" id="rg-dlv-estimate-cost-awb"
                    data-weight="<?php echo esc_attr( (int) $pkg_weight ?: 70 ); ?>"
                    data-payment="<?php 
                        $order_pm = strtolower( $order->get_payment_method() );
                        echo esc_attr( in_array( $order_pm, array( 'cod', 'cashondelivery' ), true ) ? 'COD' : 'Pre-paid' ); 
                    ?>"
                    data-order-total="<?php echo esc_attr( $order->get_total() ); ?>"
                    data-mode="E">
                💰 <?php esc_html_e( 'Estimate Cost', 'ratna-gems' ); ?>
            </button>
            <div id="rg-dlv-cost-result-awb"></div>

            <?php if ( $is_ndr && ! $is_final ) : ?>
            <h4 style="margin-top: 12px;"><?php esc_html_e( 'NDR Actions', 'ratna-gems' ); ?></h4>
            <div class="rg-dlv-notice warning" style="font-size: 11px; margin-bottom: 8px;">
                ⏰ <?php esc_html_e( 'Max 3 delivery attempts. Actions must be taken within 6 days.', 'ratna-gems' ); ?>
            </div>
            
            <div class="rg-dlv-btn-group" style="margin-bottom: 8px;">
                <button type="button" class="button" id="rg-dlv-ndr-reattempt" title="<?php esc_attr_e( 'Request another delivery attempt', 'ratna-gems' ); ?>">
                    🔁 <?php esc_html_e( 'Re-attempt', 'ratna-gems' ); ?>
                </button>
            </div>
            
            <div class="rg-dlv-input-group">
                <label><?php esc_html_e( 'Defer Delivery To', 'ratna-gems' ); ?></label>
                <input type="date" id="rg-dlv-defer-date" 
                       min="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( '+1 day' ) ) ); ?>" 
                       max="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( '+6 days' ) ) ); ?>"
                       value="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( '+2 days' ) ) ); ?>">
            </div>
            <button type="button" class="button rg-dlv-btn" id="rg-dlv-ndr-defer" title="<?php esc_attr_e( 'Schedule delivery for specific date', 'ratna-gems' ); ?>">
                📅 <?php esc_html_e( 'Defer Delivery', 'ratna-gems' ); ?>
            </button>
            
            <hr style="margin: 10px 0;">
            <h5 style="margin: 8px 0; font-size: 11px;"><?php esc_html_e( 'Update Consignee Details', 'ratna-gems' ); ?></h5>
            <div class="rg-dlv-input-group">
                <label><?php esc_html_e( 'Phone', 'ratna-gems' ); ?></label>
                <input type="text" id="rg-dlv-ndr-phone" placeholder="<?php echo esc_attr( $order->get_billing_phone() ); ?>">
            </div>
            <div class="rg-dlv-input-group">
                <label><?php esc_html_e( 'Address', 'ratna-gems' ); ?></label>
                <input type="text" id="rg-dlv-ndr-address" placeholder="<?php esc_attr_e( 'Updated address (same pincode only)', 'ratna-gems' ); ?>">
            </div>
            <button type="button" class="button rg-dlv-btn" id="rg-dlv-ndr-edit" title="<?php esc_attr_e( 'Update consignee phone or address', 'ratna-gems' ); ?>">
                ✏️ <?php esc_html_e( 'Update Details', 'ratna-gems' ); ?>
            </button>
            <div id="rg-dlv-ndr-result"></div>
            <?php endif; ?>

            <?php if ( ! $is_final && ! $is_dispatched ) : ?>
            <h4 style="margin-top: 12px;"><?php esc_html_e( 'Pickup', 'ratna-gems' ); ?></h4>
            <?php if ( $pickup_id ) : ?>
                <div class="rg-dlv-notice info">
                    <?php echo esc_html( sprintf( __( 'Pickup scheduled: %s', 'ratna-gems' ), $pickup_date ?: $pickup_id ) ); ?>
                </div>
            <?php else : ?>
                <div class="rg-dlv-input-group">
                    <label><?php esc_html_e( 'Pickup Date', 'ratna-gems' ); ?></label>
                    <input type="date" id="rg-dlv-pickup-date" value="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( '+1 weekday' ) ) ); ?>" min="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>">
                </div>
                <button type="button" class="button rg-dlv-btn" id="rg-dlv-schedule-pickup">
                    📦 <?php esc_html_e( 'Schedule Pickup', 'ratna-gems' ); ?>
                </button>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ( $is_delivered ) : ?>
            <h4 style="margin-top: 12px;"><?php esc_html_e( 'Return (RVP)', 'ratna-gems' ); ?></h4>
            <?php if ( $return_awb ) : ?>
                <div class="rg-dlv-notice info">
                    <?php echo esc_html( sprintf( __( 'Return AWB: %s (%s)', 'ratna-gems' ), $return_awb, $return_status ?: 'Created' ) ); ?>
                </div>
            <?php else : ?>
                <button type="button" class="button rg-dlv-btn" id="rg-dlv-create-return">
                    ↩️ <?php esc_html_e( 'Create Return Shipment', 'ratna-gems' ); ?>
                </button>
            <?php endif; ?>
            <?php endif; ?>

            <?php 
            /**
             * Cancel Shipment Section
             * 
             * Official Delhivery Cancellation Rules (from documentation lines 828-834):
             * - Forward (COD/Prepaid): Can cancel when Manifested, In Transit, Pending
             * - NOT cancellable when: Dispatched, Delivered, DTO, RTO, LOST, Closed
             * 
             * Refund behavior:
             * - Before pickup (Manifested): Full refund to wallet
             * - After pickup (In Transit/Pending): No refund, triggers RTO
             */
            if ( $can_cancel ) : 
                // Determine refund eligibility based on official statuses
                $before_pickup = $is_manifested || 'NOT PICKED' === $status_upper || empty( $status_upper );
                $after_pickup = $is_in_transit || $is_pending;
            ?>
            <hr style="margin: 12px 0;">
            <h4 style="margin-bottom: 8px;"><?php esc_html_e( 'Cancel Shipment', 'ratna-gems' ); ?></h4>
            
            <?php if ( $before_pickup ) : ?>
                <div class="rg-dlv-notice success" style="margin-bottom: 8px; font-size: 11px;">
                    ✅ <?php esc_html_e( 'Status: Manifested (not picked up). Full refund will be credited to Delhivery wallet.', 'ratna-gems' ); ?>
                </div>
            <?php elseif ( $after_pickup ) : ?>
                <div class="rg-dlv-notice warning" style="margin-bottom: 8px; font-size: 11px;">
                    ⚠️ <?php esc_html_e( 'Status: In Transit/Pending. Cancellation will trigger RTO. NO REFUND - RTO charges will apply.', 'ratna-gems' ); ?>
                </div>
            <?php endif; ?>
            
            <button type="button" class="button rg-dlv-btn" id="rg-dlv-cancel" 
                    style="color: #fff; background: #dc3232; border-color: #dc3232;"
                    data-before-pickup="<?php echo $before_pickup ? '1' : '0'; ?>"
                    data-status="<?php echo esc_attr( $status ); ?>"
                    data-status-type="<?php echo esc_attr( $status_type ); ?>">
                ❌ <?php esc_html_e( 'Cancel Shipment', 'ratna-gems' ); ?>
            </button>
            <?php elseif ( $is_dispatched ) : ?>
            <hr style="margin: 12px 0;">
            <div class="rg-dlv-notice warning" style="font-size: 11px;">
                🚫 <?php esc_html_e( 'Cancellation not available. Shipment is Dispatched (out for delivery).', 'ratna-gems' ); ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- UPDATE TAB -->
        <div class="rg-dlv-section rg-dlv-tab-content" data-tab="update">
            <h4><?php esc_html_e( 'Update Shipment', 'ratna-gems' ); ?></h4>
            
            <div class="rg-dlv-input-group">
                <label><?php esc_html_e( 'Weight (grams)', 'ratna-gems' ); ?></label>
                <input type="number" id="rg-dlv-update-weight" placeholder="500">
            </div>

            <div class="rg-dlv-input-group">
                <label><?php esc_html_e( 'Phone', 'ratna-gems' ); ?></label>
                <input type="text" id="rg-dlv-update-phone" placeholder="9876543210">
            </div>

            <div class="rg-dlv-input-group">
                <label><?php esc_html_e( 'Address', 'ratna-gems' ); ?></label>
                <input type="text" id="rg-dlv-update-address" placeholder="New address">
            </div>

            <button type="button" class="button button-primary rg-dlv-btn" id="rg-dlv-update-shipment">
                💾 <?php esc_html_e( 'Update Shipment', 'ratna-gems' ); ?>
            </button>

            <hr style="margin: 12px 0;">

            <h4><?php esc_html_e( 'Payment Mode', 'ratna-gems' ); ?></h4>
            <div class="rg-dlv-input-group">
                <label><?php esc_html_e( 'Convert To', 'ratna-gems' ); ?></label>
                <select id="rg-dlv-payment-mode">
                    <option value="Pre-paid"><?php esc_html_e( 'Prepaid', 'ratna-gems' ); ?></option>
                    <option value="COD"><?php esc_html_e( 'COD', 'ratna-gems' ); ?></option>
                </select>
            </div>
            <div class="rg-dlv-input-group" id="rg-dlv-cod-amount-wrap" style="display: none;">
                <label><?php esc_html_e( 'COD Amount', 'ratna-gems' ); ?></label>
                <input type="number" id="rg-dlv-cod-amount" step="0.01" placeholder="<?php echo esc_attr( $order->get_total() ); ?>">
            </div>
            <button type="button" class="button rg-dlv-btn" id="rg-dlv-convert-payment">
                💰 <?php esc_html_e( 'Convert Payment', 'ratna-gems' ); ?>
            </button>

            <?php if ( (float) $order->get_total() > 50000 ) : ?>
            <hr style="margin: 12px 0;">
            <h4><?php esc_html_e( 'E-Waybill', 'ratna-gems' ); ?></h4>
            <?php if ( $ewaybill ) : ?>
                <div class="rg-dlv-notice success">
                    <?php echo esc_html( sprintf( __( 'E-Waybill: %s', 'ratna-gems' ), $ewaybill ) ); ?>
                </div>
            <?php else : ?>
                <div class="rg-dlv-notice warning">
                    <?php esc_html_e( 'E-Waybill required for orders >₹50,000', 'ratna-gems' ); ?>
                </div>
            <?php endif; ?>
            <div class="rg-dlv-input-group">
                <label><?php esc_html_e( 'E-Waybill Number', 'ratna-gems' ); ?></label>
                <input type="text" id="rg-dlv-ewaybill" value="<?php echo esc_attr( $ewaybill ); ?>" placeholder="Enter e-waybill number">
            </div>
            <button type="button" class="button rg-dlv-btn" id="rg-dlv-update-ewaybill">
                📋 <?php esc_html_e( 'Update E-Waybill', 'ratna-gems' ); ?>
            </button>
            <?php endif; ?>
        </div>

        <!-- DOCS TAB -->
        <div class="rg-dlv-section rg-dlv-tab-content" data-tab="docs">
            <h4><?php esc_html_e( 'Documents', 'ratna-gems' ); ?></h4>
            
            <?php if ( $is_delivered ) : ?>
            <button type="button" class="button rg-dlv-btn" id="rg-dlv-get-epod">
                📄 <?php esc_html_e( 'Get EPOD', 'ratna-gems' ); ?>
            </button>
            
            <button type="button" class="button rg-dlv-btn" id="rg-dlv-get-signature">
                ✍️ <?php esc_html_e( 'Get Signature', 'ratna-gems' ); ?>
            </button>

            <?php if ( $pod_url ) : ?>
            <div class="rg-dlv-notice success">
                <a href="<?php echo esc_url( $pod_url ); ?>" target="_blank"><?php esc_html_e( 'View POD', 'ratna-gems' ); ?></a>
            </div>
            <?php endif; ?>

            <?php if ( $signature_url ) : ?>
            <div class="rg-dlv-notice success">
                <a href="<?php echo esc_url( $signature_url ); ?>" target="_blank"><?php esc_html_e( 'View Signature', 'ratna-gems' ); ?></a>
            </div>
            <?php endif; ?>
            
            <p class="description" style="margin-top: 8px; font-size: 11px; color: #646970;">
                <?php esc_html_e( 'Note: EPOD and Signature may not be available for all deliveries.', 'ratna-gems' ); ?>
            </p>
            <?php else : ?>
            <p class="description" style="color: #646970;">
                <?php esc_html_e( 'EPOD and Signature documents are only available after delivery is completed.', 'ratna-gems' ); ?>
            </p>
            <?php endif; ?>

            <hr style="margin: 12px 0;">
            
            <div class="rg-dlv-links">
                <a href="https://www.delhivery.com/track/package/<?php echo esc_attr( $awb ); ?>" target="_blank">
                    🔗 <?php esc_html_e( 'Track Online', 'ratna-gems' ); ?>
                </a>
            </div>
        </div>

    <?php else : ?>
        <!-- NO SHIPMENT YET -->
        <div class="rg-dlv-section">
            <?php 
            // Calculate package preview based on order items
            $total_qty = 0;
            foreach ( $order->get_items() as $item ) {
                $total_qty += max( 1, (int) $item->get_quantity() );
            }
            
            // Get package profile preview
            $preview_profile = $client->resolve_package_profile( $total_qty );
            if ( ! $preview_profile ) {
                // Fallback for unknown quantities
                $preview_profile = array( 
                    'weight' => max( 500, $total_qty * 70 ), 
                    'length' => 24, 
                    'width' => 18, 
                    'height' => max( 3, min( 15, $total_qty * 2 ) ) 
                );
            }
            
            // Pre-check serviceability
            $is_serviceable = true;
            if ( function_exists( 'rg_delhivery_check_pincode_serviceable' ) && ! empty( $pincode ) ) {
                $is_serviceable = rg_delhivery_check_pincode_serviceable( $pincode );
            }
            ?>
            
            <?php if ( ! $is_serviceable ) : ?>
            <div class="rg-dlv-notice warning" style="margin-bottom: 12px;">
                ⚠️ <strong><?php esc_html_e( 'Not Serviceable', 'ratna-gems' ); ?></strong><br>
                <?php echo esc_html( sprintf( __( 'Pincode %s is not serviceable by Delhivery. Use another courier for this order.', 'ratna-gems' ), $pincode ) ); ?>
            </div>
            <?php else : ?>
            <div class="rg-dlv-notice info">
                <?php esc_html_e( 'No shipment created yet.', 'ratna-gems' ); ?>
            </div>
            <?php endif; ?>
            
            <!-- Package Preview -->
            <div style="background: #e8f4fc; padding: 10px; border-radius: 4px; margin-bottom: 12px; border-left: 3px solid #2271b1;">
                <div style="font-size: 11px; color: #646970; margin-bottom: 4px;">
                    📦 <?php esc_html_e( 'Package Profile', 'ratna-gems' ); ?> (<?php echo esc_html( $total_qty ); ?> <?php echo esc_html( _n( 'item', 'items', $total_qty, 'ratna-gems' ) ); ?>)
                </div>
                <div style="font-family: monospace; font-size: 13px;">
                    <strong><?php echo esc_html( (int) $preview_profile['weight'] ); ?>g</strong>
                    &nbsp;•&nbsp;
                    <?php echo esc_html( sprintf( '%d × %d × %d cm', (int) $preview_profile['length'], (int) $preview_profile['width'], (int) $preview_profile['height'] ) ); ?>
                </div>
            </div>

            <h4><?php esc_html_e( 'Check Serviceability', 'ratna-gems' ); ?></h4>
            <div class="rg-dlv-input-group">
                <label><?php esc_html_e( 'Destination Pincode', 'ratna-gems' ); ?></label>
                <input type="text" id="rg-dlv-check-pincode" value="<?php echo esc_attr( $pincode ); ?>" maxlength="6" pattern="\d{6}">
            </div>
            <button type="button" class="button rg-dlv-btn" id="rg-dlv-serviceability">
                ✅ <?php esc_html_e( 'Check Serviceability', 'ratna-gems' ); ?>
            </button>
            <div id="rg-dlv-serviceability-result"></div>

            <hr style="margin: 12px 0;">

            <?php
            // Calculate cost estimation weight from package profile
            $est_weight = (int) ( $preview_profile['weight'] ?? 70 );
            $est_payment_type = $is_cod_order ? 'COD' : 'Pre-paid';
            // Get order total for COD charge calculation
            $order_total = $order->get_total();
            ?>
            <button type="button" class="button rg-dlv-btn" id="rg-dlv-estimate-cost"
                    data-weight="<?php echo esc_attr( $est_weight ); ?>"
                    data-payment="<?php echo esc_attr( $est_payment_type ); ?>"
                    data-order-total="<?php echo esc_attr( $order_total ); ?>"
                    data-mode="E">
                💰 <?php esc_html_e( 'Estimate Cost', 'ratna-gems' ); ?>
            </button>
            <div id="rg-dlv-cost-result"></div>

            <hr style="margin: 12px 0;">

            <button type="button" class="button button-primary rg-dlv-btn" id="rg-dlv-manifest">
                🚀 <?php esc_html_e( 'Create Shipment', 'ratna-gems' ); ?>
            </button>
            
            <hr style="margin: 12px 0;">
            
            <button type="button" class="button rg-dlv-btn" id="rg-dlv-print-invoice-noawb" title="<?php esc_attr_e( 'Download GST Invoice', 'ratna-gems' ); ?>">
                🧾 <?php esc_html_e( 'Download Invoice', 'ratna-gems' ); ?>
            </button>
        </div>
    <?php endif; ?>

    <script>
    jQuery(function($) {
        var orderId = <?php echo absint( $order->get_id() ); ?>;
        var awb = '<?php echo esc_js( $awb ); ?>';
        var nonce = '<?php echo esc_js( wp_create_nonce( 'rg_delhivery_nonce' ) ); ?>';

        // Tab switching
        $('.rg-dlv-tab').on('click', function() {
            var tab = $(this).data('tab');
            $('.rg-dlv-tab').removeClass('active');
            $(this).addClass('active');
            $('.rg-dlv-tab-content').removeClass('active');
            $('.rg-dlv-tab-content[data-tab="' + tab + '"]').addClass('active');
        });

        // COD amount toggle
        $('#rg-dlv-payment-mode').on('change', function() {
            $('#rg-dlv-cod-amount-wrap').toggle($(this).val() === 'COD');
        });

        // Helper function for AJAX
        function dlvAjax(action, data, btn) {
            var $btn = $(btn);
            var originalText = $btn.html();
            $btn.prop('disabled', true).html('⏳ Loading...');
            
            return $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: $.extend({ action: action, security: nonce, order_id: orderId, awb: awb }, data)
            }).always(function() {
                $btn.prop('disabled', false).html(originalText);
            });
        }

        // Refresh status
        $('#rg-dlv-refresh-status').on('click', function() {
            dlvAjax('rg_delhivery_track_shipment', {}, this).done(function(res) {
                if (res.success) {
                    alert('Status: ' + res.data.status + '\nLocation: ' + (res.data.status_location || 'N/A'));
                    location.reload();
                } else {
                    alert('Error: ' + res.data.message);
                }
            });
        });

        // Print label
        $('#rg-dlv-print-label').on('click', function() {
            window.open('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>?action=rg_delhivery_print_label&awb=' + awb + '&security=' + nonce, '_blank');
        });

        // Print invoice (GST Invoice)
        $('#rg-dlv-print-invoice').on('click', function() {
            window.open('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>?action=rg_print_invoice&order_id=' + orderId + '&security=' + nonce, '_blank');
        });

        // NDR Re-attempt
        $('#rg-dlv-ndr-reattempt').on('click', function() {
            if (!confirm('Request delivery re-attempt?')) return;
            dlvAjax('rg_delhivery_ndr_action', { ndr_action: 'RE-ATTEMPT' }, this).done(function(res) {
                alert(res.success ? 'Re-attempt requested!' : 'Error: ' + res.data.message);
                if (res.success) location.reload();
            });
        });

        // NDR Defer Delivery (official action: DEFER_DLV)
        $('#rg-dlv-ndr-defer').on('click', function() {
            var date = $('#rg-dlv-defer-date').val();
            if (!date) {
                alert('Please select a date');
                return;
            }
            var $result = $('#rg-dlv-ndr-result');
            $result.html('<div class="rg-dlv-notice">Processing...</div>');
            
            dlvAjax('rg_delhivery_ndr_action', { 
                ndr_action: 'DEFER_DLV', 
                deferred_date: date 
            }, this).done(function(res) {
                if (res.success) {
                    $result.html('<div class="rg-dlv-notice success">Delivery deferred to ' + date + '</div>');
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    $result.html('<div class="rg-dlv-notice warning">Error: ' + (res.data?.message || 'Unknown error') + '</div>');
                }
            });
        });

        // NDR Edit Details (official action: EDIT_DETAILS)
        $('#rg-dlv-ndr-edit').on('click', function() {
            var phone = $('#rg-dlv-ndr-phone').val();
            var address = $('#rg-dlv-ndr-address').val();
            
            if (!phone && !address) {
                alert('Please enter phone or address to update');
                return;
            }
            
            var $result = $('#rg-dlv-ndr-result');
            $result.html('<div class="rg-dlv-notice">Updating...</div>');
            
            dlvAjax('rg_delhivery_ndr_action', { 
                ndr_action: 'EDIT_DETAILS', 
                phone: phone,
                add: address
            }, this).done(function(res) {
                if (res.success) {
                    $result.html('<div class="rg-dlv-notice success">Details updated successfully!</div>');
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    $result.html('<div class="rg-dlv-notice warning">Error: ' + (res.data?.message || 'Unknown error') + '</div>');
                }
            });
        });

        // Schedule pickup
        $('#rg-dlv-schedule-pickup').on('click', function() {
            var date = $('#rg-dlv-pickup-date').val();
            if (!date) {
                alert('Please select a pickup date');
                return;
            }
            dlvAjax('rg_schedule_delhivery_pickup', { pickup_date: date }, this).done(function(res) {
                if (res.success) {
                    alert('Pickup scheduled for ' + res.data.date);
                    location.reload();
                } else {
                    alert('Error: ' + res.data.message);
                }
            });
        });

        // Create return
        $('#rg-dlv-create-return').on('click', function() {
            if (!confirm('Create return shipment (RVP)?')) return;
            dlvAjax('rg_delhivery_create_return', {}, this).done(function(res) {
                if (res.success) {
                    alert('Return created! AWB: ' + res.data.return_awb);
                    location.reload();
                } else {
                    alert('Error: ' + res.data.message);
                }
            });
        });

        // Cancel shipment via AJAX
        // Official Delhivery Cancellation Rules:
        // - Cancellable: Manifested, In Transit, Pending (Forward), Scheduled (RVP)
        // - NOT cancellable: Dispatched, Delivered, DTO, RTO, LOST, Closed
        // - Before pickup = Full refund | After pickup = No refund, triggers RTO
        $('#rg-dlv-cancel').on('click', function() {
            var $btn = $(this);
            var awb = '<?php echo esc_js( $awb ); ?>';
            var currentStatus = $btn.data('status') || '<?php echo esc_js( $status ); ?>';
            var statusType = $btn.data('status-type') || '<?php echo esc_js( $status_type ); ?>';
            var beforePickup = $btn.data('before-pickup') === 1 || $btn.data('before-pickup') === '1';
            
            // Build confirmation message based on official Delhivery terminology
            var confirmMsg = '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n';
            confirmMsg += '       CANCEL SHIPMENT CONFIRMATION\n';
            confirmMsg += '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n';
            confirmMsg += 'AWB: ' + awb + '\n';
            confirmMsg += 'Status: ' + (currentStatus || 'Manifested');
            if (statusType) confirmMsg += ' (StatusType: ' + statusType + ')';
            confirmMsg += '\n\n';
            
            if (beforePickup) {
                confirmMsg += '✅ BEFORE PICKUP - FULL REFUND\n';
                confirmMsg += '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n';
                confirmMsg += '• Shipment has NOT been picked up yet\n';
                confirmMsg += '• Shipping charges will be FULLY REFUNDED\n';
                confirmMsg += '• Refund credited to Delhivery wallet immediately\n';
                confirmMsg += '• You can re-manifest this order after cancellation\n';
            } else {
                confirmMsg += '⚠️ AFTER PICKUP - NO REFUND (RTO)\n';
                confirmMsg += '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n';
                confirmMsg += '• Shipment has ALREADY been picked up\n';
                confirmMsg += '• Cancellation will trigger RTO (Return to Origin)\n';
                confirmMsg += '• ❌ NO REFUND - RTO charges will apply\n';
                confirmMsg += '• StatusType will change to RT\n';
                confirmMsg += '\n⚠️ Are you sure you want to proceed?\n';
            }
            
            confirmMsg += '\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n';
            confirmMsg += 'Click OK to cancel, Cancel to abort';
            
            if (!confirm(confirmMsg)) return;
            
            $btn.prop('disabled', true).text('Cancelling...');
            
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'rg_delhivery_cancel_shipment',
                    security: '<?php echo esc_js( wp_create_nonce( 'rg_delhivery_nonce' ) ); ?>',
                    order_id: <?php echo esc_js( $order->get_id() ); ?>
                },
                success: function(res) {
                    if (res.success) {
                        var successMsg = '✅ ' + res.data.message + '\n\n';
                        successMsg += '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n';
                        successMsg += res.data.refund_note + '\n';
                        if (res.data.can_remanifest) {
                            successMsg += '\n✅ You can now create a new shipment for this order.';
                        }
                        alert(successMsg);
                        location.reload();
                    } else {
                        alert('❌ Cancellation failed:\n\n' + res.data.message + '\n\nStatus: ' + (res.data.status || 'Unknown'));
                        $btn.prop('disabled', false).html('❌ <?php esc_html_e( 'Cancel Shipment', 'ratna-gems' ); ?>');
                    }
                },
                error: function(xhr, status, error) {
                    alert('❌ Network error. Please try again.\n\nError: ' + error);
                    $btn.prop('disabled', false).html('❌ <?php esc_html_e( 'Cancel Shipment', 'ratna-gems' ); ?>');
                }
            });
        });

        // Update shipment
        $('#rg-dlv-update-shipment').on('click', function() {
            var data = {};
            var weight = $('#rg-dlv-update-weight').val();
            var phone = $('#rg-dlv-update-phone').val();
            var address = $('#rg-dlv-update-address').val();
            
            if (weight) data.weight = weight;
            if (phone) data.phone = phone;
            if (address) data.add = address;
            
            if ($.isEmptyObject(data)) {
                alert('Please enter at least one field to update');
                return;
            }
            
            dlvAjax('rg_delhivery_update_shipment', data, this).done(function(res) {
                alert(res.success ? 'Shipment updated!' : 'Error: ' + res.data.message);
            });
        });

        // Convert payment
        $('#rg-dlv-convert-payment').on('click', function() {
            var mode = $('#rg-dlv-payment-mode').val();
            var cod = $('#rg-dlv-cod-amount').val();
            
            if (mode === 'COD' && !cod) {
                alert('Please enter COD amount');
                return;
            }
            
            dlvAjax('rg_delhivery_convert_payment', { new_mode: mode, cod_amount: cod }, this).done(function(res) {
                alert(res.success ? 'Payment mode converted!' : 'Error: ' + res.data.message);
            });
        });

        // Update e-waybill
        $('#rg-dlv-update-ewaybill').on('click', function() {
            var ewbn = $('#rg-dlv-ewaybill').val();
            if (!ewbn) {
                alert('Please enter e-waybill number');
                return;
            }
            dlvAjax('rg_delhivery_update_ewaybill', { ewbn: ewbn }, this).done(function(res) {
                if (res.success) {
                    alert('E-waybill updated!');
                    location.reload();
                } else {
                    alert('Error: ' + res.data.message);
                }
            });
        });

        // Get EPOD
        $('#rg-dlv-get-epod').on('click', function() {
            dlvAjax('rg_delhivery_download_document', { doc_type: 'EPOD' }, this).done(function(res) {
                if (res.success && res.data.document) {
                    if (res.data.document.url) {
                        window.open(res.data.document.url, '_blank');
                    } else {
                        alert('EPOD not available yet');
                    }
                } else {
                    alert('Error: ' + (res.data.message || 'EPOD not available'));
                }
            });
        });

        // Get Signature
        $('#rg-dlv-get-signature').on('click', function() {
            dlvAjax('rg_delhivery_download_document', { doc_type: 'SIGNATURE_URL' }, this).done(function(res) {
                if (res.success && res.data.document) {
                    if (res.data.document.url) {
                        window.open(res.data.document.url, '_blank');
                    } else {
                        alert('Signature not available yet');
                    }
                } else {
                    alert('Error: ' + (res.data.message || 'Signature not available'));
                }
            });
        });

        // Check serviceability
        $('#rg-dlv-serviceability').on('click', function() {
            var pin = $('#rg-dlv-check-pincode').val();
            if (!pin || pin.length !== 6) {
                alert('Please enter a valid 6-digit pincode');
                return;
            }
            dlvAjax('rg_delhivery_check_pincode', { pincode: pin }, this).done(function(res) {
                var $result = $('#rg-dlv-serviceability-result');
                if (res.success) {
                    var msg = res.data.is_serviceable ? '✅ Serviceable' : '❌ Not Serviceable';
                    if (res.data.has_embargo) msg += ' (Embargo)';
                    $result.html('<div class="rg-dlv-notice ' + (res.data.is_serviceable ? 'success' : 'warning') + '">' + msg + '</div>');
                } else {
                    $result.html('<div class="rg-dlv-notice warning">' + res.data.message + '</div>');
                }
            });
        });

        // Common function to display cost breakdown
        // Now includes orderTotal parameter to calculate actual COD charge
        function displayCostBreakdown($result, res, paymentType, orderTotal) {
            if (res.success && res.data) {
                var cost = res.data.cost || res.data.raw || res.data;
                var codCalc = res.data.cod_calculation || null;
                var adjustedTotal = res.data.adjusted_total || null;
                
                // Check if API returned an error
                if (cost.error || cost.message || cost.status === 'error') {
                    $result.html('<div class="rg-dlv-notice warning">API Error: ' + (cost.error || cost.message || 'Unknown error') + '</div>');
                    return;
                }
                
                // Check if cost is empty array
                if (Array.isArray(cost) && cost.length === 0) {
                    $result.html('<div class="rg-dlv-notice warning">No pricing data returned. Route may not be supported.</div>');
                    return;
                }
                
                // If cost is an array, get first element (Delhivery returns array)
                if (Array.isArray(cost) && cost.length > 0) {
                    cost = cost[0];
                }
                
                // Build breakdown HTML
                var html = '<div class="rg-dlv-notice success">';
                html += '<strong>Cost Breakdown (' + paymentType + '):</strong><br>';
                
                // Get API-returned COD charge
                var apiCodCharge = parseFloat(cost.charge_COD || 0);
                
                // Calculate actual COD charge if COD order
                // Formula: MAX(₹40, OrderTotal × 2%)
                var actualCodCharge = apiCodCharge;
                var codDifference = 0;
                if (paymentType === 'COD' && orderTotal > 0) {
                    var codFixed = 40;  // Your rate card fixed minimum
                    var codPercent = 2; // Your rate card percentage
                    var percentCharge = (orderTotal * codPercent) / 100;
                    actualCodCharge = Math.max(codFixed, percentCharge);
                    codDifference = actualCodCharge - apiCodCharge;
                }
                
                // Parse ALL Delhivery charge fields (except COD which we handle separately)
                var charges = {
                    'Delivery (DL)': parseFloat(cost.charge_DL || 0),
                    'Last Mile (LM)': parseFloat(cost.charge_LM || 0),
                    'Handling (DPH)': parseFloat(cost.charge_DPH || 0),
                    'Fuel Surcharge (FSC)': parseFloat(cost.charge_FSC || cost.charge_FS || 0),
                    'RTO Charge': parseFloat(cost.charge_RTO || 0),
                    'Pickup': parseFloat(cost.charge_pickup || 0),
                    'Air Charge': parseFloat(cost.charge_AIR || 0),
                    'Peak Surcharge': parseFloat(cost.charge_PEAK || 0),
                    'Insurance': parseFloat(cost.charge_INS || 0),
                };
                
                var grossAmount = parseFloat(cost.gross_amount || 0);
                var totalAmount = parseFloat(cost.total_amount || 0);
                
                // Tax breakdown
                var taxData = cost.tax_data || {};
                var sgst = parseFloat(taxData.SGST || 0);
                var cgst = parseFloat(taxData.CGST || 0);
                var igst = parseFloat(taxData.IGST || 0);
                var totalTax = sgst + cgst + igst;
                
                // Zone info
                var zone = cost.zone || '';
                var chargedWeight = cost.charged_weight || '';
                
                // Show all non-zero charges (except COD)
                for (var chargeName in charges) {
                    if (charges[chargeName] > 0) {
                        html += '• ' + chargeName + ': ₹' + charges[chargeName].toFixed(2) + '<br>';
                    }
                }
                
                // Show COD charge with calculation details
                if (paymentType === 'COD') {
                    if (codDifference > 0) {
                        // Show calculated COD charge (higher than API returned)
                        html += '• <strong>COD Charge: ₹' + actualCodCharge.toFixed(2) + '</strong>';
                        html += ' <small style="color:#856404;">(2% of ₹' + orderTotal.toFixed(0) + ')</small><br>';
                    } else if (apiCodCharge > 0) {
                        // Show API COD charge (minimum)
                        html += '• COD Charge: ₹' + apiCodCharge.toFixed(2);
                        html += ' <small style="color:#666;">(min. ₹40)</small><br>';
                    }
                }
                
                // Tax
                if (totalTax > 0) {
                    html += '<hr style="margin:4px 0;border-color:#ddd;">';
                    if (codDifference > 0) {
                        // Recalculate tax on difference (18% GST)
                        var additionalTax = codDifference * 0.18;
                        var adjustedGross = grossAmount + codDifference;
                        html += '• Subtotal: ₹' + adjustedGross.toFixed(2) + '<br>';
                        var adjustedTax = totalTax + additionalTax;
                        if (igst > 0) {
                            html += '• IGST: ₹' + (igst + additionalTax).toFixed(2) + '<br>';
                        } else {
                            html += '• CGST: ₹' + (cgst + additionalTax/2).toFixed(2) + '<br>';
                            html += '• SGST: ₹' + (sgst + additionalTax/2).toFixed(2) + '<br>';
                        }
                    } else {
                        html += '• Subtotal: ₹' + grossAmount.toFixed(2) + '<br>';
                        if (igst > 0) {
                            html += '• IGST: ₹' + igst.toFixed(2) + '<br>';
                        } else {
                            if (cgst > 0) html += '• CGST: ₹' + cgst.toFixed(2) + '<br>';
                            if (sgst > 0) html += '• SGST: ₹' + sgst.toFixed(2) + '<br>';
                        }
                    }
                }
                
                // Calculate final total
                var finalTotal = totalAmount;
                if (codDifference > 0) {
                    var additionalTax = codDifference * 0.18;
                    finalTotal = totalAmount + codDifference + additionalTax;
                }
                
                // Total
                html += '<hr style="margin:4px 0;border-color:#ccc;">';
                if (finalTotal > 0) {
                    html += '<strong style="font-size:14px;color:#28a745;">Total: ₹' + finalTotal.toFixed(2) + '</strong>';
                    if (codDifference > 0) {
                        html += '<br><small style="color:#856404;">⚠️ COD increased by ₹' + (codDifference + codDifference*0.18).toFixed(2) + ' (incl. GST)</small>';
                    }
                } else if (grossAmount > 0) {
                    html += '<strong style="font-size:14px;color:#28a745;">Total: ₹' + grossAmount.toFixed(2) + '</strong>';
                } else {
                    html += '<strong style="font-size:14px;color:#dc3545;">Total: N/A</strong>';
                }
                
                // Zone and weight info
                if (zone || chargedWeight) {
                    html += '<br><small style="color:#666;margin-top:4px;display:block;">';
                    if (zone) html += '📍 Zone: ' + zone;
                    if (zone && chargedWeight) html += ' | ';
                    if (chargedWeight) html += '⚖️ Charged: ' + chargedWeight + 'g';
                    html += '</small>';
                }
                
                // Order value info for COD
                if (paymentType === 'COD' && orderTotal > 0) {
                    html += '<small style="color:#666;display:block;">💵 Order Value: ₹' + orderTotal.toFixed(2) + '</small>';
                }
                
                html += '</div>';
                $result.html(html);
            } else {
                $result.html('<div class="rg-dlv-notice warning">' + (res.data?.message || 'Could not calculate') + '</div>');
            }
        }

        // Estimate cost function
        function estimateCost($btn, $result) {
            var pin = $('#rg-dlv-check-pincode').val() || '<?php echo esc_js( $pincode ); ?>';
            var weight = $btn.data('weight') || <?php echo absint( $pkg_weight ?: 70 ); ?>;
            var mode = $btn.data('mode') || 'E';
            var paymentType = $btn.data('payment') || '<?php echo esc_js( $is_cod_order ? 'COD' : 'Pre-paid' ); ?>';
            var orderTotal = parseFloat($btn.data('order-total')) || <?php echo (float) $order->get_total(); ?>;
            var originPin = '<?php echo esc_js( defined( "DELHIVERY_ORIGIN_PINCODE" ) ? DELHIVERY_ORIGIN_PINCODE : "" ); ?>';
            
            if (!pin || pin.length !== 6) {
                alert('Please enter a valid destination pincode');
                return;
            }
            
            if (!originPin || originPin.length !== 6) {
                alert('Origin pincode not configured. Please define DELHIVERY_ORIGIN_PINCODE in wp-config.php');
                return;
            }
            
            var requestData = { 
                dest_pin: pin, 
                origin_pin: originPin, 
                weight: weight, 
                mode: mode,
                payment_type: paymentType,
                cod_amount: paymentType === 'COD' ? orderTotal : 0  // Pass order total for COD calculation
            };
            
            $result.html('<div class="rg-dlv-notice">Calculating...</div>');
            
            dlvAjax('rg_delhivery_calculate_cost', requestData, $btn[0]).done(function(res) {
                displayCostBreakdown($result, res, paymentType, orderTotal);
            }).fail(function(xhr, status, error) {
                $result.html('<div class="rg-dlv-notice warning">Request failed: ' + error + '</div>');
            });
        }

        // Estimate cost button (no AWB section)
        $('#rg-dlv-estimate-cost').on('click', function() {
            estimateCost($(this), $('#rg-dlv-cost-result'));
        });
        
        // Estimate cost button (AWB exists section)
        $('#rg-dlv-estimate-cost-awb').on('click', function() {
            estimateCost($(this), $('#rg-dlv-cost-result-awb'));
        });

        // Create shipment (manifest)
        $('#rg-dlv-manifest').on('click', function() {
            var $btn = $(this);
            var pincode = $('#rg-dlv-check-pincode').val() || '';
            
            // First check serviceability
            if (pincode) {
                $btn.prop('disabled', true).text('Checking...');
                
                $.post(ajaxurl, {
                    action: 'rg_delhivery_check_pincode',
                    security: nonce,
                    pincode: pincode
                }, function(response) {
                    $btn.prop('disabled', false).html('🚀 <?php echo esc_js( __( 'Create Shipment', 'ratna-gems' ) ); ?>');
                    
                    if (response.success && response.data) {
                        // FIX: Use is_serviceable (not serviceable) - matches API response
                        if (!response.data.is_serviceable) {
                            // Not serviceable - warn user
                            var remarks = response.data.remarks || [];
                            var remarksText = Array.isArray(remarks) ? remarks.join(', ') : remarks;
                            var msg = '⚠️ WARNING: Pincode ' + pincode + ' is NOT serviceable by Delhivery!\n\n';
                            msg += 'Remarks: ' + (remarksText || 'Area not covered') + '\n\n';
                            msg += 'Are you sure you want to try manifesting anyway? The request will likely fail.';
                            
                            if (!confirm(msg)) {
                                return;
                            }
                        }
                    }
                    
                    // Proceed with manifest
                    if (confirm('Create Delhivery shipment for this order?')) {
                        $('select[name="wc_order_action"]').val('rg_manifest_with_delhivery');
                        $('#actions .button.wc-reload').click();
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).html('🚀 <?php echo esc_js( __( 'Create Shipment', 'ratna-gems' ) ); ?>');
                    // On error, just proceed with confirmation
                    if (confirm('Create Delhivery shipment for this order?')) {
                        $('select[name="wc_order_action"]').val('rg_manifest_with_delhivery');
                        $('#actions .button.wc-reload').click();
                    }
                });
            } else {
                // No pincode, just confirm
                if (confirm('Create Delhivery shipment for this order?')) {
                    $('select[name="wc_order_action"]').val('rg_manifest_with_delhivery');
                    $('#actions .button.wc-reload').click();
                }
            }
        });
        
        // Print invoice (for orders without AWB)
        $('#rg-dlv-print-invoice-noawb').on('click', function() {
            window.open('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>?action=rg_print_invoice&order_id=' + orderId + '&security=' + nonce, '_blank');
        });
    });
    </script>
    <?php
}

// =============================================================================
// PRINT LABEL & SLIP HANDLERS
// =============================================================================

add_action( 'wp_ajax_rg_delhivery_print_label', 'rg_delhivery_ajax_print_label' );
/**
 * Handle label download.
 */
function rg_delhivery_ajax_print_label(): void {
    check_ajax_referer( 'rg_delhivery_nonce', 'security' );

    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'ratna-gems' ) );
    }

    $awb = isset( $_GET['awb'] ) ? sanitize_text_field( wp_unslash( $_GET['awb'] ) ) : '';
    if ( empty( $awb ) ) {
        wp_die( esc_html__( 'AWB required.', 'ratna-gems' ) );
    }

    $client = rg_delhivery_client();
    if ( ! $client ) {
        wp_die( esc_html__( 'Delhivery client not configured.', 'ratna-gems' ) );
    }

    // Use the correct method: download_label
    $result = $client->download_label( $awb, array(
        'pdf'      => true,
        'pdf_size' => defined( 'DELHIVERY_LABEL_SIZE' ) ? DELHIVERY_LABEL_SIZE : '4R',
    ) );

    if ( is_wp_error( $result ) ) {
        wp_die( esc_html( $result->get_error_message() ) );
    }

    // If we got a redirect URL
    if ( ! empty( $result['redirect_url'] ) ) {
        wp_redirect( esc_url_raw( $result['redirect_url'] ) );
        exit;
    }

    // If we got PDF content directly
    if ( ! empty( $result['body'] ) ) {
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: inline; filename="label-' . esc_attr( $awb ) . '.pdf"' );
        header( 'Content-Length: ' . strlen( $result['body'] ) );
        echo $result['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    wp_die( esc_html__( 'Could not generate label. Please try from Delhivery One portal.', 'ratna-gems' ) );
}

// =============================================================================
// INVOICE GENERATION
// =============================================================================

add_action( 'wp_ajax_rg_print_invoice', 'rg_print_invoice_handler' );
/**
 * Handle invoice generation and display.
 */
function rg_print_invoice_handler(): void {
    check_ajax_referer( 'rg_delhivery_nonce', 'security' );

    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'ratna-gems' ) );
    }

    $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
    if ( ! $order_id ) {
        wp_die( esc_html__( 'Order ID required.', 'ratna-gems' ) );
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_die( esc_html__( 'Order not found.', 'ratna-gems' ) );
    }

    // Look for invoice template in various locations
    $template_paths = array(
        get_stylesheet_directory() . '/ratnagems-invoice-template.php',
        get_stylesheet_directory() . '/inc/invoice-template.php',
        get_stylesheet_directory() . '/woocommerce/invoice-template.php',
        get_template_directory() . '/ratnagems-invoice-template.php',
        get_template_directory() . '/inc/invoice-template.php',
    );

    $template_file = '';
    foreach ( $template_paths as $path ) {
        if ( file_exists( $path ) ) {
            $template_file = $path;
            break;
        }
    }

    // Allow filter to override template path
    $template_file = apply_filters( 'rg_invoice_template_path', $template_file, $order );

    if ( empty( $template_file ) || ! file_exists( $template_file ) ) {
        wp_die( 
            esc_html__( 'Invoice template not found. Please create ratnagems-invoice-template.php in your child theme.', 'ratna-gems' ) . 
            '<br><br>' .
            esc_html__( 'Expected locations:', 'ratna-gems' ) . 
            '<ul><li>' . esc_html( get_stylesheet_directory() . '/ratnagems-invoice-template.php' ) . '</li></ul>'
        );
    }

    // Include the template (it expects $order to be set)
    include $template_file;
    exit;
}
