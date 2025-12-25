<?php
/**
 * Delhivery Enhancements
 * Additional features: Customer email tracking, WhatsApp sharing, Dashboard widget,
 * Order tracking page, Thank you page tracking, and more.
 *
 * @package Ratna Gems
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =============================================================================
// 1. ADD TRACKING INFO TO CUSTOMER EMAILS
// =============================================================================

add_action( 'woocommerce_email_order_meta', 'rg_delhivery_add_tracking_to_email', 20, 3 );
/**
 * Add Delhivery tracking info to WooCommerce emails.
 */
function rg_delhivery_add_tracking_to_email( $order, $sent_to_admin, $plain_text ) {
    $awb = $order->get_meta( '_delhivery_awb' );
    if ( empty( $awb ) ) {
        return;
    }

    $status = $order->get_meta( '_delhivery_status' ) ?: 'Shipped';
    $tracking_url = 'https://www.delhivery.com/track/package/' . $awb;
    $expected = $order->get_meta( '_delhivery_expected_delivery' );

    if ( $plain_text ) {
        echo "\n\n";
        echo "==========\n";
        echo __( 'SHIPMENT TRACKING', 'ratna-gems' ) . "\n";
        echo "==========\n";
        echo __( 'AWB Number:', 'ratna-gems' ) . ' ' . $awb . "\n";
        echo __( 'Status:', 'ratna-gems' ) . ' ' . $status . "\n";
        if ( $expected ) {
            echo __( 'Expected Delivery:', 'ratna-gems' ) . ' ' . $expected . "\n";
        }
        echo __( 'Track your order:', 'ratna-gems' ) . ' ' . $tracking_url . "\n";
    } else {
        ?>
        <div style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #2271b1; border-radius: 4px;">
            <h3 style="margin: 0 0 10px; color: #1d2327; font-size: 16px;">
                📦 <?php esc_html_e( 'Shipment Tracking', 'ratna-gems' ); ?>
            </h3>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 5px 0; color: #646970; width: 120px;"><?php esc_html_e( 'AWB Number:', 'ratna-gems' ); ?></td>
                    <td style="padding: 5px 0; font-weight: bold; font-family: monospace;"><?php echo esc_html( $awb ); ?></td>
                </tr>
                <tr>
                    <td style="padding: 5px 0; color: #646970;"><?php esc_html_e( 'Status:', 'ratna-gems' ); ?></td>
                    <td style="padding: 5px 0;">
                        <span style="display: inline-block; padding: 3px 8px; background: #d4edda; color: #155724; border-radius: 3px; font-size: 12px;">
                            <?php echo esc_html( $status ); ?>
                        </span>
                    </td>
                </tr>
                <?php if ( $expected ) : ?>
                <tr>
                    <td style="padding: 5px 0; color: #646970;"><?php esc_html_e( 'Expected:', 'ratna-gems' ); ?></td>
                    <td style="padding: 5px 0;"><?php echo esc_html( $expected ); ?></td>
                </tr>
                <?php endif; ?>
            </table>
            <p style="margin: 15px 0 0;">
                <a href="<?php echo esc_url( $tracking_url ); ?>" 
                   style="display: inline-block; padding: 10px 20px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 4px; font-weight: bold;">
                    🔍 <?php esc_html_e( 'Track Your Order', 'ratna-gems' ); ?>
                </a>
            </p>
        </div>
        <?php
    }
}

// =============================================================================
// 2. TRACKING INFO ON THANK YOU PAGE
// =============================================================================

add_action( 'woocommerce_thankyou', 'rg_delhivery_thankyou_tracking', 15 );
/**
 * Display tracking info on thank you page if available.
 */
function rg_delhivery_thankyou_tracking( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $awb = $order->get_meta( '_delhivery_awb' );
    if ( empty( $awb ) ) return;

    $tracking_url = 'https://www.delhivery.com/track/package/' . $awb;
    $status = $order->get_meta( '_delhivery_status' ) ?: 'Processing';
    ?>
    <section class="woocommerce-delhivery-tracking">
        <h2><?php esc_html_e( 'Shipment Tracking', 'ratna-gems' ); ?></h2>
        <table class="woocommerce-table shop_table">
            <tr>
                <th><?php esc_html_e( 'AWB Number', 'ratna-gems' ); ?></th>
                <td><code><?php echo esc_html( $awb ); ?></code></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Status', 'ratna-gems' ); ?></th>
                <td><mark class="order-status"><?php echo esc_html( $status ); ?></mark></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Track', 'ratna-gems' ); ?></th>
                <td>
                    <a href="<?php echo esc_url( $tracking_url ); ?>" target="_blank" class="button">
                        <?php esc_html_e( 'Track on Delhivery', 'ratna-gems' ); ?>
                    </a>
                </td>
            </tr>
        </table>
    </section>
    <?php
}

// =============================================================================
// 3. TRACKING INFO IN MY ACCOUNT > ORDERS
// =============================================================================

add_action( 'woocommerce_my_account_my_orders_column_order-actions', 'rg_delhivery_myaccount_tracking_button', 15 );
/**
 * Add tracking button to My Account orders table.
 */
function rg_delhivery_myaccount_tracking_button( $order ) {
    $awb = $order->get_meta( '_delhivery_awb' );
    if ( empty( $awb ) ) return;

    $tracking_url = 'https://www.delhivery.com/track/package/' . $awb;
    printf(
        '<a href="%s" target="_blank" class="woocommerce-button button track" title="%s">%s</a>',
        esc_url( $tracking_url ),
        esc_attr__( 'Track Shipment', 'ratna-gems' ),
        esc_html__( 'Track', 'ratna-gems' )
    );
}

// Also add tracking info to order details page
add_action( 'woocommerce_order_details_after_order_table', 'rg_delhivery_order_details_tracking', 10 );
/**
 * Show tracking info on order details page in My Account.
 */
function rg_delhivery_order_details_tracking( $order ) {
    $awb = $order->get_meta( '_delhivery_awb' );
    if ( empty( $awb ) ) return;

    $status = $order->get_meta( '_delhivery_status' ) ?: 'Shipped';
    $tracking_url = 'https://www.delhivery.com/track/package/' . $awb;
    $expected = $order->get_meta( '_delhivery_expected_delivery' );
    $last_location = $order->get_meta( '_delhivery_last_location' );
    $last_update = $order->get_meta( '_delhivery_last_update' );
    
    // WhatsApp share message
    $whatsapp_msg = sprintf(
        __( 'Track my order from %s: AWB %s - %s', 'ratna-gems' ),
        get_bloginfo( 'name' ),
        $awb,
        $tracking_url
    );
    $whatsapp_url = 'https://wa.me/?text=' . rawurlencode( $whatsapp_msg );
    ?>
    <section class="woocommerce-delhivery-tracking" style="margin-top: 30px;">
        <h2><?php esc_html_e( 'Shipment Tracking', 'ratna-gems' ); ?></h2>
        <div style="padding: 20px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e2e4e7;">
            <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                <span style="font-size: 24px;">📦</span>
                <div>
                    <div style="font-family: monospace; font-size: 18px; font-weight: bold;"><?php echo esc_html( $awb ); ?></div>
                    <div style="display: inline-block; padding: 4px 10px; background: <?php echo rg_delhivery_get_status_color( $status ); ?>; color: #fff; border-radius: 4px; font-size: 12px; font-weight: 600; margin-top: 5px;">
                        <?php echo esc_html( $status ); ?>
                    </div>
                </div>
            </div>
            
            <?php if ( $last_location || $expected ) : ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 15px; padding-top: 15px; border-top: 1px solid #e2e4e7;">
                <?php if ( $last_location ) : ?>
                <div>
                    <div style="font-size: 11px; color: #646970; margin-bottom: 3px;"><?php esc_html_e( 'Current Location', 'ratna-gems' ); ?></div>
                    <div style="font-weight: 500;"><?php echo esc_html( $last_location ); ?></div>
                </div>
                <?php endif; ?>
                <?php if ( $expected ) : ?>
                <div>
                    <div style="font-size: 11px; color: #646970; margin-bottom: 3px;"><?php esc_html_e( 'Expected Delivery', 'ratna-gems' ); ?></div>
                    <div style="font-weight: 500;"><?php echo esc_html( $expected ); ?></div>
                </div>
                <?php endif; ?>
                <?php if ( $last_update ) : ?>
                <div>
                    <div style="font-size: 11px; color: #646970; margin-bottom: 3px;"><?php esc_html_e( 'Last Updated', 'ratna-gems' ); ?></div>
                    <div style="font-weight: 500;"><?php echo esc_html( $last_update ); ?></div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="<?php echo esc_url( $tracking_url ); ?>" target="_blank" 
                   style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 5px; font-weight: 500;">
                    🔍 <?php esc_html_e( 'Track on Delhivery', 'ratna-gems' ); ?>
                </a>
                <a href="<?php echo esc_url( $whatsapp_url ); ?>" target="_blank" 
                   style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; background: #25D366; color: #fff; text-decoration: none; border-radius: 5px; font-weight: 500;">
                    📱 <?php esc_html_e( 'Share on WhatsApp', 'ratna-gems' ); ?>
                </a>
            </div>
        </div>
    </section>
    <?php
}

/**
 * Get status color for badge.
 */
function rg_delhivery_get_status_color( $status ) {
    $status_lower = strtolower( $status );
    
    if ( strpos( $status_lower, 'delivered' ) !== false ) {
        return '#28a745';
    } elseif ( strpos( $status_lower, 'out for delivery' ) !== false || strpos( $status_lower, 'ofd' ) !== false ) {
        return '#17a2b8';
    } elseif ( strpos( $status_lower, 'transit' ) !== false || strpos( $status_lower, 'dispatch' ) !== false ) {
        return '#ffc107';
    } elseif ( strpos( $status_lower, 'rto' ) !== false || strpos( $status_lower, 'return' ) !== false ) {
        return '#dc3545';
    } elseif ( strpos( $status_lower, 'success' ) !== false || strpos( $status_lower, 'manifest' ) !== false ) {
        return '#007bff';
    } else {
        return '#6c757d';
    }
}

// =============================================================================
// 4. ADMIN DASHBOARD WIDGET
// =============================================================================

add_action( 'wp_dashboard_setup', 'rg_delhivery_add_dashboard_widget' );
/**
 * Add Delhivery shipments widget to admin dashboard.
 */
function rg_delhivery_add_dashboard_widget() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    
    wp_add_dashboard_widget(
        'rg_delhivery_dashboard_widget',
        '🚚 ' . __( 'Delhivery Shipments', 'ratna-gems' ),
        'rg_delhivery_dashboard_widget_content'
    );
}

/**
 * Dashboard widget content.
 */
function rg_delhivery_dashboard_widget_content() {
    global $wpdb;
    
    // Get shipment statistics
    $stats = array(
        'pending_manifest' => 0,
        'in_transit' => 0,
        'out_for_delivery' => 0,
        'delivered_today' => 0,
        'ndr_pending' => 0,
        'rto' => 0,
    );
    
    // Processing orders without AWB (pending manifest)
    $orders_table = $wpdb->prefix . 'wc_orders';
    $meta_table = $wpdb->prefix . 'wc_orders_meta';
    
    // Check if HPOS is enabled
    $hpos_enabled = class_exists( 'Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
        && wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled();
    
    if ( $hpos_enabled && $wpdb->get_var( "SHOW TABLES LIKE '{$orders_table}'" ) === $orders_table ) {
        // HPOS query
        $stats['pending_manifest'] = (int) $wpdb->get_var( "
            SELECT COUNT(*) FROM {$orders_table} o
            LEFT JOIN {$meta_table} m ON o.id = m.order_id AND m.meta_key = '_delhivery_awb'
            WHERE o.status = 'wc-processing' AND (m.meta_value IS NULL OR m.meta_value = '')
        " );
        
        // In transit
        $stats['in_transit'] = (int) $wpdb->get_var( "
            SELECT COUNT(*) FROM {$meta_table} 
            WHERE meta_key = '_delhivery_status' 
            AND (meta_value LIKE '%transit%' OR meta_value LIKE '%dispatch%' OR meta_value = 'Success')
        " );
        
        // Out for delivery
        $stats['out_for_delivery'] = (int) $wpdb->get_var( "
            SELECT COUNT(*) FROM {$meta_table}
            WHERE meta_key = '_delhivery_status' 
            AND (meta_value LIKE '%out for delivery%' OR meta_value LIKE '%OFD%')
        " );
        
        // RTO
        $stats['rto'] = (int) $wpdb->get_var( "
            SELECT COUNT(*) FROM {$meta_table}
            WHERE meta_key = '_delhivery_status' 
            AND meta_value LIKE '%RTO%'
        " );
    } else {
        // Legacy post meta query
        $stats['pending_manifest'] = (int) $wpdb->get_var( "
            SELECT COUNT(*) FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_delhivery_awb'
            WHERE p.post_type = 'shop_order' AND p.post_status = 'wc-processing' 
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        " );
        
        $stats['in_transit'] = (int) $wpdb->get_var( "
            SELECT COUNT(*) FROM {$wpdb->postmeta}
            WHERE meta_key = '_delhivery_status' 
            AND (meta_value LIKE '%transit%' OR meta_value LIKE '%dispatch%' OR meta_value = 'Success')
        " );
        
        $stats['out_for_delivery'] = (int) $wpdb->get_var( "
            SELECT COUNT(*) FROM {$wpdb->postmeta}
            WHERE meta_key = '_delhivery_status' 
            AND (meta_value LIKE '%out for delivery%' OR meta_value LIKE '%OFD%')
        " );
        
        $stats['rto'] = (int) $wpdb->get_var( "
            SELECT COUNT(*) FROM {$wpdb->postmeta}
            WHERE meta_key = '_delhivery_status' 
            AND meta_value LIKE '%RTO%'
        " );
    }
    
    // Auto pickup status
    $auto_pickup = defined( 'DELHIVERY_AUTO_PICKUP' ) && DELHIVERY_AUTO_PICKUP;
    
    ?>
    <style>
        .rg-dlv-widget-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 15px; }
        .rg-dlv-widget-stat { text-align: center; padding: 12px 8px; background: #f0f0f1; border-radius: 6px; }
        .rg-dlv-widget-stat-value { font-size: 24px; font-weight: 700; line-height: 1.2; }
        .rg-dlv-widget-stat-label { font-size: 11px; color: #646970; margin-top: 4px; }
        .rg-dlv-widget-stat.warning { background: #fff3cd; }
        .rg-dlv-widget-stat.danger { background: #f8d7da; }
        .rg-dlv-widget-stat.success { background: #d4edda; }
        .rg-dlv-widget-stat.info { background: #cce5ff; }
        .rg-dlv-widget-pool { display: flex; align-items: center; justify-content: space-between; padding: 10px; background: <?php echo $auto_pickup ? '#d4edda' : '#f0f0f1'; ?>; border-radius: 6px; margin-bottom: 15px; }
        .rg-dlv-widget-actions { display: flex; gap: 8px; }
        .rg-dlv-widget-actions .button { flex: 1; text-align: center; }
    </style>
    
    <div class="rg-dlv-widget-stats">
        <div class="rg-dlv-widget-stat warning">
            <div class="rg-dlv-widget-stat-value"><?php echo esc_html( $stats['pending_manifest'] ); ?></div>
            <div class="rg-dlv-widget-stat-label"><?php esc_html_e( 'Pending Manifest', 'ratna-gems' ); ?></div>
        </div>
        <div class="rg-dlv-widget-stat info">
            <div class="rg-dlv-widget-stat-value"><?php echo esc_html( $stats['in_transit'] ); ?></div>
            <div class="rg-dlv-widget-stat-label"><?php esc_html_e( 'In Transit', 'ratna-gems' ); ?></div>
        </div>
        <div class="rg-dlv-widget-stat success">
            <div class="rg-dlv-widget-stat-value"><?php echo esc_html( $stats['out_for_delivery'] ); ?></div>
            <div class="rg-dlv-widget-stat-label"><?php esc_html_e( 'Out for Delivery', 'ratna-gems' ); ?></div>
        </div>
    </div>
    
    <?php if ( $stats['rto'] > 0 ) : ?>
    <div class="rg-dlv-widget-stats">
        <div class="rg-dlv-widget-stat danger" style="grid-column: span 3;">
            <div class="rg-dlv-widget-stat-value"><?php echo esc_html( $stats['rto'] ); ?></div>
            <div class="rg-dlv-widget-stat-label"><?php esc_html_e( '⚠️ RTO / Return to Origin', 'ratna-gems' ); ?></div>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="rg-dlv-widget-pool">
        <div>
            <strong><?php esc_html_e( 'Auto Pickup:', 'ratna-gems' ); ?></strong>
            <?php if ( $auto_pickup ) : ?>
                <span style="color: #155724;">✓ <?php esc_html_e( 'Enabled', 'ratna-gems' ); ?></span>
            <?php else : ?>
                <span style="color: #646970;"><?php esc_html_e( 'Disabled', 'ratna-gems' ); ?></span>
            <?php endif; ?>
        </div>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping&section=delhivery' ) ); ?>" class="button button-small">
            <?php esc_html_e( 'Settings', 'ratna-gems' ); ?>
        </a>
    </div>
    
    <div class="rg-dlv-widget-actions">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&status=wc-processing' ) ); ?>" class="button">
            <?php esc_html_e( 'Processing Orders', 'ratna-gems' ); ?>
        </a>
        <a href="https://one.delhivery.com" target="_blank" class="button">
            <?php esc_html_e( 'Delhivery Portal', 'ratna-gems' ); ?>
        </a>
    </div>
    <?php
}

// =============================================================================
// 5. AUTO-SEND TRACKING EMAIL WHEN AWB IS ASSIGNED
// =============================================================================

add_action( 'updated_order_meta', 'rg_delhivery_send_tracking_email_on_awb', 10, 4 );
add_action( 'added_order_meta', 'rg_delhivery_send_tracking_email_on_awb', 10, 4 );
/**
 * Send tracking email when AWB is first assigned.
 */
function rg_delhivery_send_tracking_email_on_awb( $meta_id, $order_id, $meta_key, $meta_value ) {
    if ( '_delhivery_awb' !== $meta_key || empty( $meta_value ) ) {
        return;
    }
    
    // Check if we've already sent this notification
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    
    if ( $order->get_meta( '_delhivery_tracking_email_sent' ) ) {
        return;
    }
    
    // Only send if order status is processing or completed
    $status = $order->get_status();
    if ( ! in_array( $status, array( 'processing', 'completed', 'shipped' ), true ) ) {
        return;
    }
    
    // Trigger the standard processing email which will include tracking info
    WC()->mailer()->get_emails()['WC_Email_Customer_Processing_Order']->trigger( $order_id );
    
    // Mark as sent
    $order->update_meta_data( '_delhivery_tracking_email_sent', current_time( 'mysql' ) );
    $order->save();
    
    $order->add_order_note( __( 'Tracking email sent to customer with AWB: ', 'ratna-gems' ) . $meta_value );
}

// =============================================================================
// 6. ADD COPY AWB BUTTON AND IMPROVED STATUS DISPLAY
// =============================================================================

add_action( 'admin_footer', 'rg_delhivery_admin_footer_scripts' );
/**
 * Add JS for copy AWB and other enhancements.
 */
function rg_delhivery_admin_footer_scripts() {
    $screen = get_current_screen();
    if ( ! $screen ) return;
    
    if ( ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) && strpos( $screen->id, 'edit-shop_order' ) === false ) {
        return;
    }
    ?>
    <script>
    jQuery(function($) {
        // Copy AWB to clipboard
        $(document).on('click', '.rg-dlv-copy-awb', function(e) {
            e.preventDefault();
            var awb = $(this).data('awb');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(awb).then(function() {
                    alert('AWB copied: ' + awb);
                });
            } else {
                // Fallback
                var $temp = $('<input>');
                $('body').append($temp);
                $temp.val(awb).select();
                document.execCommand('copy');
                $temp.remove();
                alert('AWB copied: ' + awb);
            }
        });
        
        // Auto-refresh status every 5 minutes on order edit page
        if ($('#rg_delhivery_shipment').length && $('.rg-dlv-awb').length) {
            setInterval(function() {
                var $refreshBtn = $('.rg-dlv-refresh-status');
                if ($refreshBtn.length && !$refreshBtn.prop('disabled')) {
                    // Silent refresh - don't show loading state
                    // Auto-refresh status silently
                }
            }, 300000); // 5 minutes
        }
    });
    </script>
    <?php
}

// =============================================================================
// 7. ENHANCED ORDERS LIST STATUS COLUMN WITH MORE STATES
// =============================================================================

add_filter( 'manage_edit-shop_order_columns', 'rg_delhivery_enhance_status_column', 25 );
add_filter( 'woocommerce_shop_order_list_table_columns', 'rg_delhivery_enhance_status_column', 25 );
/**
 * Ensure Delhivery column is properly positioned.
 */
function rg_delhivery_enhance_status_column( $columns ) {
    // Column already added by delhivery-loader.php
    // This just ensures proper positioning after order_status
    return $columns;
}

// Enhanced status display with more colors
add_action( 'admin_head', 'rg_delhivery_enhanced_status_styles' );
/**
 * Add enhanced status colors for more states.
 */
function rg_delhivery_enhanced_status_styles() {
    $screen = get_current_screen();
    if ( ! $screen || ( strpos( $screen->id, 'shop_order' ) === false && strpos( $screen->id, 'wc-orders' ) === false ) ) {
        return;
    }
    ?>
    <style>
        .rg-dlv-col-status { 
            display: inline-block; 
            padding: 4px 10px; 
            border-radius: 4px; 
            font-size: 11px; 
            text-decoration: none; 
            font-weight: 500;
            transition: all 0.2s;
        }
        .rg-dlv-col-status:hover { opacity: 0.8; }
        .rg-dlv-status-delivered { background: #d4edda; color: #155724; }
        .rg-dlv-status-ofd { background: #d1ecf1; color: #0c5460; }
        .rg-dlv-status-transit { background: #fff3cd; color: #856404; }
        .rg-dlv-status-pending { background: #cce5ff; color: #004085; }
        .rg-dlv-status-success { background: #cce5ff; color: #004085; }
        .rg-dlv-status-rto { background: #f8d7da; color: #721c24; }
        .rg-dlv-status-ndr { background: #ffeeba; color: #856404; border: 1px solid #ffc107; }
        .rg-dlv-status-none { color: #999; }
        
        /* Column width */
        .column-delhivery_status { width: 100px; }
    </style>
    <?php
}

// =============================================================================
// 8. QUICK MANIFEST BUTTON ON ORDERS LIST
// =============================================================================

add_filter( 'woocommerce_admin_order_actions', 'rg_delhivery_add_quick_actions', 10, 2 );
/**
 * Add quick manifest button to orders list (only for serviceable pincodes).
 */
function rg_delhivery_add_quick_actions( $actions, $order ) {
    if ( ! $order->get_meta( '_delhivery_awb' ) && $order->get_status() === 'processing' ) {
        // Check serviceability before showing manifest button
        $pincode = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
        $is_serviceable = true;
        
        if ( function_exists( 'rg_delhivery_check_pincode_serviceable' ) && ! empty( $pincode ) ) {
            $is_serviceable = rg_delhivery_check_pincode_serviceable( $pincode );
        }
        
        if ( $is_serviceable ) {
            $actions['delhivery_manifest'] = array(
                'url'    => wp_nonce_url( admin_url( 'admin-ajax.php?action=rg_delhivery_quick_manifest&order_id=' . $order->get_id() ), 'rg_delhivery_quick_manifest' ),
                'name'   => __( 'Manifest', 'ratna-gems' ),
                'action' => 'delhivery_manifest',
            );
        }
    }
    return $actions;
}

// Add button styles
add_action( 'admin_head', 'rg_delhivery_quick_action_styles' );
function rg_delhivery_quick_action_styles() {
    ?>
    <style>
        .wc-action-button-delhivery_manifest::after { 
            font-family: dashicons !important;
            content: "\f318" !important; /* truck icon */
        }
        .wc-action-button-delhivery_manifest { 
            color: #2271b1 !important; 
        }
    </style>
    <?php
}

// Handle quick manifest AJAX
add_action( 'wp_ajax_rg_delhivery_quick_manifest', 'rg_delhivery_handle_quick_manifest' );
function rg_delhivery_handle_quick_manifest() {
    check_admin_referer( 'rg_delhivery_quick_manifest' );
    
    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'ratna-gems' ) );
    }
    
    $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
    $order = wc_get_order( $order_id );
    
    if ( ! $order instanceof WC_Order ) {
        wp_die( esc_html__( 'Order not found.', 'ratna-gems' ) );
    }
    
    // Trigger manifest action
    do_action( 'woocommerce_order_action_rg_manifest_with_delhivery', $order );
    
    // Redirect back to orders list
    wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wc-orders' ) );
    exit;
}

// =============================================================================
// 9. BULK REFRESH TRACKING STATUS
// =============================================================================

add_filter( 'bulk_actions-edit-shop_order', 'rg_delhivery_add_bulk_refresh', 25 );
add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'rg_delhivery_add_bulk_refresh', 25 );
/**
 * Add bulk refresh tracking action.
 */
function rg_delhivery_add_bulk_refresh( $actions ) {
    $actions['rg_delhivery_bulk_refresh'] = __( 'Delhivery: Refresh Tracking', 'ratna-gems' );
    return $actions;
}

add_filter( 'handle_bulk_actions-edit-shop_order', 'rg_delhivery_handle_bulk_refresh', 10, 3 );
add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', 'rg_delhivery_handle_bulk_refresh', 10, 3 );
/**
 * Handle bulk refresh tracking action.
 */
function rg_delhivery_handle_bulk_refresh( $redirect_to, $action, $order_ids ) {
    if ( 'rg_delhivery_bulk_refresh' !== $action ) {
        return $redirect_to;
    }
    
    $client = rg_delhivery_client();
    if ( ! $client ) {
        return $redirect_to;
    }
    
    // Collect AWBs
    $awb_map = array();
    foreach ( $order_ids as $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $awb = $order->get_meta( '_delhivery_awb' );
            if ( $awb ) {
                $awb_map[ $awb ] = $order;
            }
        }
    }
    
    if ( empty( $awb_map ) ) {
        return add_query_arg( 'rg_dlv_refreshed', 0, $redirect_to );
    }
    
    // Track in batches of 50
    $updated = 0;
    $batches = array_chunk( array_keys( $awb_map ), 50, true );
    
    foreach ( $batches as $batch ) {
        $results = $client->track_multiple_shipments( $batch );
        
        if ( is_wp_error( $results ) ) continue;
        
        foreach ( $results as $awb => $data ) {
            if ( ! isset( $awb_map[ $awb ] ) ) continue;
            
            $order = $awb_map[ $awb ];
            $status = $data['Status']['Status'] ?? $data['status'] ?? '';
            $location = $data['Status']['StatusLocation'] ?? '';
            $datetime = $data['Status']['StatusDateTime'] ?? '';
            
            if ( $status ) {
                $order->update_meta_data( '_delhivery_status', $status );
                $order->update_meta_data( '_delhivery_last_update', $datetime ?: current_time( 'mysql' ) );
                if ( $location ) {
                    $order->update_meta_data( '_delhivery_last_location', $location );
                }
                $order->save();
                $updated++;
            }
        }
    }
    
    return add_query_arg( 'rg_dlv_refreshed', $updated, $redirect_to );
}

// Show admin notice for bulk refresh
add_action( 'admin_notices', 'rg_delhivery_bulk_refresh_notice' );
function rg_delhivery_bulk_refresh_notice() {
    if ( ! isset( $_GET['rg_dlv_refreshed'] ) ) {
        return;
    }
    
    $count = absint( $_GET['rg_dlv_refreshed'] );
    
    if ( $count > 0 ) {
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            sprintf(
                esc_html( _n( 'Refreshed tracking status for %d order.', 'Refreshed tracking status for %d orders.', $count, 'ratna-gems' ) ),
                $count
            )
        );
    } else {
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'No orders with Delhivery AWB found to refresh.', 'ratna-gems' ) . '</p></div>';
    }
}

// =============================================================================
// 10. ORDER NOTES FOR ALL DELHIVERY ACTIONS
// =============================================================================

// This is already handled in individual action handlers

// =============================================================================
// 11. SETTINGS PAGE LINK
// =============================================================================

add_action( 'woocommerce_sections_shipping', 'rg_delhivery_settings_section' );
/**
 * Add Delhivery info to shipping settings.
 */
function rg_delhivery_settings_section() {
    global $current_section;
    
    if ( 'delhivery' !== $current_section ) {
        return;
    }
    
    $pool = get_option( 'rg_delhivery_waybill_pool', array() );
    $pool_count = count( $pool );
    $auto_pickup = defined( 'DELHIVERY_AUTO_PICKUP' ) && DELHIVERY_AUTO_PICKUP;
    ?>
    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-bottom: 20px; border-radius: 4px;">
        <h3 style="margin-top: 0;">🚚 <?php esc_html_e( 'Delhivery Integration Status', 'ratna-gems' ); ?></h3>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'API Status', 'ratna-gems' ); ?></th>
                <td>
                    <?php if ( rg_delhivery_is_configured() ) : ?>
                        <span style="color: #46b450;">✓ <?php esc_html_e( 'Configured', 'ratna-gems' ); ?></span>
                    <?php else : ?>
                        <span style="color: #dc3232;">✗ <?php esc_html_e( 'Not Configured', 'ratna-gems' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Pickup Location', 'ratna-gems' ); ?></th>
                <td><?php echo esc_html( defined( 'DELHIVERY_PICKUP_LOCATION' ) ? DELHIVERY_PICKUP_LOCATION : ( defined( 'DELHIVERY_PICKUP_LOCATION_NAME' ) ? DELHIVERY_PICKUP_LOCATION_NAME : 'Not set' ) ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Auto Pickup', 'ratna-gems' ); ?></th>
                <td>
                    <?php if ( $auto_pickup ) : ?>
                        <span style="color: #46b450;">✓ <?php esc_html_e( 'Enabled - Pickup auto-scheduled after shipment creation', 'ratna-gems' ); ?></span>
                    <?php else : ?>
                        <span style="color: #646970;"><?php esc_html_e( 'Disabled - Add DELHIVERY_AUTO_PICKUP to wp-config.php to enable', 'ratna-gems' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'AWB Generation', 'ratna-gems' ); ?></th>
                <td>
                    <span style="color: #46b450;">✓ <?php esc_html_e( 'On-the-fly (B2C API)', 'ratna-gems' ); ?></span>
                    <br><small style="color: #646970;"><?php esc_html_e( 'AWB numbers are automatically generated when you create a shipment. No pre-fetching required.', 'ratna-gems' ); ?></small>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Webhook URL', 'ratna-gems' ); ?></th>
                <td><code><?php echo esc_html( rest_url( 'rg-delhivery/v1/webhook' ) ); ?></code></td>
            </tr>
        </table>
    </div>
    <?php
}
