<?php
/**
 * Ratna Gems - Review Request System (Final)
 * 
 * Sends review request via Email 3 days after delivery
 * Also sends reminder email to admin with WhatsApp message to copy
 * 
 * @package RatnaGems
 * @version 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =============================================================================
// CONFIGURATION
// =============================================================================

define( 'RG_GOOGLE_REVIEW_LINK', 'https://g.page/r/CRpBPoQkqNe1EBE/review' );
define( 'RG_GOOGLE_MERCHANT_ID', 5357502282 );
define( 'RG_REVIEW_DELAY_DAYS', 3 );
define( 'RG_STORE_NAME', 'Ratna Gems' );
define( 'RG_STORE_PHONE', '7067939337' );
if ( ! defined( 'RG_ADMIN_EMAIL' ) ) {
    define( 'RG_ADMIN_EMAIL', get_option( 'admin_email', 'admin@ratnagems.com' ) );
}

// =============================================================================
// PART 1: GOOGLE CUSTOMER REVIEWS OPT-IN (Thank You Page)
// =============================================================================

add_action( 'woocommerce_thankyou', 'rg_google_reviews_optin', 5 );
function rg_google_reviews_optin( $order_id ): void {
    if ( ! $order_id ) return;
    
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    
    $email = $order->get_billing_email();
    $country = $order->get_shipping_country() ?: $order->get_billing_country() ?: 'IN';
    $delivery_date = wp_date( 'Y-m-d', current_time( 'timestamp' ) + WEEK_IN_SECONDS );
    
    ?>
    <script src="https://apis.google.com/js/platform.js?onload=renderOptIn" async defer></script>
    <script>
    window.renderOptIn = function() {
        window.gapi.load('surveyoptin', function() {
            window.gapi.surveyoptin.render({
                "merchant_id": <?php echo absint( RG_GOOGLE_MERCHANT_ID ); ?>,
                "order_id": "<?php echo esc_js( $order_id ); ?>",
                "email": "<?php echo esc_js( $email ); ?>",
                "delivery_country": "<?php echo esc_js( $country ); ?>",
                "estimated_delivery_date": "<?php echo esc_js( $delivery_date ); ?>"
            });
        });
    }
    </script>
    <?php
}

// =============================================================================
// PART 2: REGISTER CUSTOM EMAIL CLASS
// =============================================================================

add_filter( 'woocommerce_email_classes', 'rg_register_review_email' );
function rg_register_review_email( $emails ) {
    $file = dirname( __FILE__ ) . '/class-wc-email-review-request.php';
    if ( file_exists( $file ) ) {
        require_once $file;
        $emails['WC_Email_Review_Request'] = new WC_Email_Review_Request();
    }
    return $emails;
}

// =============================================================================
// PART 3: TRIGGER ON DELIVERY (Delhivery Webhook)
// =============================================================================

add_action( 'rg_delhivery_webhook_processed', 'rg_on_delivery', 10, 3 );
function rg_on_delivery( $order, $status, $data ): void {
    if ( ! $order instanceof WC_Order ) return;
    
    $status_type = $order->get_meta( '_delhivery_status_type' );
    if ( 'DL' === $status_type ) {
        rg_schedule_review( $order );
    }
}

add_action( 'woocommerce_order_status_completed', 'rg_on_complete' );
function rg_on_complete( $order_id ): void {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    
    if ( 'DL' === $order->get_meta( '_delhivery_status_type' ) ) {
        rg_schedule_review( $order );
    }
}

// =============================================================================
// PART 4: SCHEDULE REVIEW REQUEST
// =============================================================================

function rg_schedule_review( $order ): bool {
    if ( ! $order instanceof WC_Order ) {
        $order = wc_get_order( $order );
    }
    if ( ! $order ) return false;
    
    $order_id = $order->get_id();
    
    if ( $order->get_meta( '_rg_review_scheduled' ) || $order->get_meta( '_rg_review_sent' ) ) {
        return false;
    }
    
    $now       = time();
    $send_time = $now + ( RG_REVIEW_DELAY_DAYS * DAY_IN_SECONDS );
    
    wp_schedule_single_event( $send_time, 'rg_send_review_request', array( $order_id ) );
    
    $order->update_meta_data( '_rg_review_scheduled', current_time( 'mysql' ) );
    $order->update_meta_data( '_rg_review_send_time', wp_date( 'Y-m-d H:i:s', $send_time ) );
    $order->update_meta_data( '_rg_delivery_date', current_time( 'mysql' ) );
    $order->save();
    
    $order->add_order_note( sprintf(
        '📧 Review request scheduled for %s',
        date_i18n( 'M j, Y g:i A', $send_time )
    ) );
    
    return true;
}

// =============================================================================
// PART 5: SEND REVIEW REQUEST
// =============================================================================

add_action( 'rg_send_review_request', 'rg_do_send_review' );
function rg_do_send_review( $order_id ): void {
    if ( ! function_exists( 'WC' ) || ! WC() ) {
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    
    if ( $order->get_meta( '_rg_review_sent' ) ) return;
    
    $results = array(
        'customer_email' => false,
        'admin_email'    => false,
    );
    
    // 1. Send EMAIL to customer
    $mailer = WC()->mailer();
    $emails = $mailer->get_emails();
    if ( isset( $emails['WC_Email_Review_Request'] ) ) {
        $emails['WC_Email_Review_Request']->trigger( $order_id );
        $results['customer_email'] = true;
    }
    
    // 2. Send reminder to ADMIN
    $results['admin_email'] = rg_send_admin_reminder( $order );
    
    // Mark as sent
    $order->update_meta_data( '_rg_review_sent', current_time( 'mysql' ) );
    $order->update_meta_data( '_rg_review_results', $results );
    $order->save();
    
    $status = $results['customer_email'] ? '✅' : '❌';
    $order->add_order_note( $status . ' Review request email sent to customer' );
}

// =============================================================================
// PART 6: ADMIN REMINDER EMAIL WITH WHATSAPP MESSAGE
// =============================================================================

function rg_send_admin_reminder( $order ): bool {
    if ( ! $order instanceof WC_Order ) return false;
    
    $order_number = $order->get_order_number();
    $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
    $customer_phone = $order->get_billing_phone();
    
    // Get products
    $products = array();
    foreach ( $order->get_items() as $item ) {
        $products[] = $item->get_name();
    }
    $product_list = implode( ', ', $products );
    
    // Generate WhatsApp message
    $wa_message = rg_get_whatsapp_message( $order );
    $wa_link = rg_get_whatsapp_link( $customer_phone, $wa_message );
    
    $subject = "📱 Send WhatsApp Review Request - Order #{$order_number} - {$customer_name}";
    
    $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; font-family:Arial,sans-serif; background:#f5f5f5;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5; padding:20px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; overflow:hidden;">

<!-- Header -->
<tr>
<td style="background:linear-gradient(135deg,#25D366,#128C7E); padding:25px; text-align:center;">
<h1 style="margin:0; color:#ffffff; font-size:22px;">📱 WhatsApp Review Request</h1>
<p style="margin:8px 0 0; color:#ffffff; opacity:0.9; font-size:14px;">Send this message to customer</p>
</td>
</tr>

<!-- Order Info -->
<tr>
<td style="padding:25px;">

<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:15px;">
<tr>
<td style="padding:12px 15px; background:#fff3cd; border-left:4px solid #ffc107; border-radius:4px;">
<p style="margin:0 0 2px; font-size:11px; color:#856404; text-transform:uppercase; font-weight:bold;">Order Number</p>
<p style="margin:0; font-size:20px; font-weight:bold; color:#333;">#' . esc_html( $order_number ) . '</p>
</td>
</tr>
</table>

<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:15px;">
<tr>
<td style="padding:12px 15px; background:#f8f9fa; border-left:4px solid #6c757d; border-radius:4px;">
<p style="margin:0 0 2px; font-size:11px; color:#666; text-transform:uppercase;">Product(s)</p>
<p style="margin:0; font-size:15px; color:#333;">' . esc_html( $product_list ) . '</p>
</td>
</tr>
</table>

<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:15px;">
<tr>
<td style="padding:12px 15px; background:#f8f9fa; border-left:4px solid #6c757d; border-radius:4px;">
<p style="margin:0 0 2px; font-size:11px; color:#666; text-transform:uppercase;">Customer Name</p>
<p style="margin:0; font-size:16px; color:#333;">' . esc_html( $customer_name ) . '</p>
</td>
</tr>
</table>

<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
<tr>
<td style="padding:12px 15px; background:#d4edda; border-left:4px solid #28a745; border-radius:4px;">
<p style="margin:0 0 2px; font-size:11px; color:#155724; text-transform:uppercase; font-weight:bold;">Customer Mobile Number</p>
<p style="margin:0; font-size:20px; font-weight:bold; color:#155724;">📞 ' . esc_html( $customer_phone ) . '</p>
</td>
</tr>
</table>

<!-- WhatsApp Message Box -->
<table width="100%" cellpadding="0" cellspacing="0" style="background:#e8f5e9; border-radius:8px; border:2px solid #25D366;">
<tr>
<td style="padding:20px;">
<h3 style="margin:0 0 15px; color:#128C7E; font-size:16px;">📋 Copy this WhatsApp message:</h3>

<table width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; border:1px solid #c8e6c9;">
<tr>
<td style="padding:15px; font-size:14px; color:#333; line-height:1.7; white-space:pre-wrap; font-family:Arial,sans-serif;">' . esc_html( $wa_message ) . '</td>
</tr>
</table>

<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;">
<tr>
<td align="center">
<a href="' . esc_url( $wa_link ) . '" style="display:inline-block; background:#25D366; color:#ffffff; text-decoration:none; padding:14px 35px; border-radius:30px; font-size:16px; font-weight:bold;">📱 Open WhatsApp & Send</a>
</td>
</tr>
</table>

<p style="margin:15px 0 0; font-size:13px; color:#555; text-align:center;">
Click button above to open WhatsApp with message ready to send
</p>

</td>
</tr>
</table>

</td>
</tr>

<!-- Footer -->
<tr>
<td style="padding:15px 20px; background:#f8f9fa; text-align:center; border-top:1px solid #eee;">
<p style="margin:0; font-size:12px; color:#999;">
Review Request System | Ratna Gems
</p>
</td>
</tr>

</table>
</td></tr>
</table>
</body>
</html>';
    
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . RG_STORE_NAME . ' <' . get_option( 'admin_email' ) . '>',
    );
    
    return wp_mail( RG_ADMIN_EMAIL, $subject, $html, $headers );
}

// =============================================================================
// PART 7: WHATSAPP MESSAGE GENERATOR
// =============================================================================

function rg_get_whatsapp_message( $order ) {
    $name = $order->get_billing_first_name();
    $order_num = $order->get_order_number();
    
    $products = array();
    foreach ( $order->get_items() as $item ) {
        $products[] = $item->get_name();
    }
    $product_list = implode( ', ', array_slice( $products, 0, 2 ) );
    if ( count( $products ) > 2 ) {
        $product_list .= ' +' . ( count( $products ) - 2 ) . ' more';
    }
    
    $message = "Hello {$name}! 🙏

Your order #{$order_num} ({$product_list}) has been delivered.

We hope you are happy with your purchase!

Please take 30 seconds to share your experience. Your feedback helps us improve and helps other customers.

👉 Write your review here:
" . RG_GOOGLE_REVIEW_LINK . "

Thank you for choosing Ratna Gems! 💎

Questions? Just reply to this message.
Team Ratna Gems
📞 " . RG_STORE_PHONE;
    
    return $message;
}

function rg_get_whatsapp_link( $phone, $message ) {
    $phone = preg_replace( '/[^0-9]/', '', $phone );
    if ( strlen( $phone ) === 10 ) {
        $phone = '91' . $phone;
    }
    return 'https://wa.me/' . $phone . '?text=' . rawurlencode( $message );
}

// =============================================================================
// PART 8: BACKFILL OLD DELIVERED ORDERS
// =============================================================================

function rg_backfill_orders( $limit = 100, $immediate = false ) {
    $orders = wc_get_orders( array(
        'limit'      => $limit,
        'status'     => array( 'completed', 'processing' ),
        'orderby'    => 'date',
        'order'      => 'DESC',
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key'     => '_delhivery_status_type',
                'value'   => 'DL',
            ),
            array(
                'key'     => '_rg_review_sent',
                'compare' => 'NOT EXISTS',
            ),
            array(
                'key'     => '_rg_review_scheduled',
                'compare' => 'NOT EXISTS',
            ),
        ),
    ) );
    
    $count = 0;
    $results = array();
    
    foreach ( $orders as $order ) {
        $id = $order->get_id();
        $name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        
        if ( $immediate ) {
            // Send immediately but stagger 2 minutes apart
            $send_time = time() + ( $count * 120 );
        } else {
            // Normal 3 day delay
            $send_time = time() + ( RG_REVIEW_DELAY_DAYS * DAY_IN_SECONDS ) + ( $count * 120 );
        }
        
        wp_schedule_single_event( $send_time, 'rg_send_review_request', array( $id ) );
        
        $order->update_meta_data( '_rg_review_scheduled', current_time( 'mysql' ) );
        $order->update_meta_data( '_rg_review_send_time', date( 'Y-m-d H:i:s', $send_time ) );
        $order->update_meta_data( '_rg_backfilled', true );
        $order->save();
        
        $results[] = array(
            'order_id' => $id,
            'customer' => $name,
            'send_at'  => date_i18n( 'M j, g:i A', $send_time ),
        );
        $count++;
    }
    
    return array(
        'found'     => count( $orders ),
        'scheduled' => $count,
        'results'   => $results,
    );
}

// =============================================================================
// PART 9: ADMIN PAGE
// =============================================================================

add_action( 'admin_menu', 'rg_add_review_admin_page' );
function rg_add_review_admin_page() {
    add_submenu_page(
        'woocommerce',
        'Review Requests',
        'Review Requests',
        'manage_woocommerce',
        'rg-review-requests',
        'rg_render_review_admin_page'
    );
}

function rg_render_review_admin_page() {
    $message = '';
    
    // Handle backfill
    if ( isset( $_POST['rg_backfill_submit'] ) && check_admin_referer( 'rg_backfill_action' ) ) {
        $limit = isset( $_POST['rg_limit'] ) ? absint( $_POST['rg_limit'] ) : 50;
        $immediate = isset( $_POST['rg_immediate'] ) && $_POST['rg_immediate'] === 'yes';
        $result = rg_backfill_orders( $limit, $immediate );
        $message = '<div class="notice notice-success"><p>✅ Scheduled <strong>' . $result['scheduled'] . '</strong> orders for review request!</p></div>';
    }
    
    // Handle test email
    if ( isset( $_POST['rg_test_submit'] ) && check_admin_referer( 'rg_test_action' ) ) {
        $order_id = isset( $_POST['rg_test_order'] ) ? absint( $_POST['rg_test_order'] ) : 0;
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                // Remove sent flag to allow resend
                $order->delete_meta_data( '_rg_review_sent' );
                $order->save();
                rg_do_send_review( $order_id );
                $message = '<div class="notice notice-success"><p>✅ Test email sent for Order #' . $order_id . '</p></div>';
            } else {
                $message = '<div class="notice notice-error"><p>❌ Order not found</p></div>';
            }
        }
    }
    
    // Count pending
    $pending = wc_get_orders( array(
        'limit'      => -1,
        'return'     => 'ids',
        'meta_query' => array(
            'relation' => 'AND',
            array( 'key' => '_delhivery_status_type', 'value' => 'DL' ),
            array( 'key' => '_rg_review_sent', 'compare' => 'NOT EXISTS' ),
            array( 'key' => '_rg_review_scheduled', 'compare' => 'NOT EXISTS' ),
        ),
    ) );
    $pending_count = count( $pending );
    
    ?>
    <div class="wrap">
        <h1>📧 Review Request System</h1>
        
        <?php echo $message; ?>
        
        <!-- Status Box -->
        <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:4px; margin:20px 0; max-width:700px;">
            <h2 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px;">📊 System Status</h2>
            <table style="width:100%;">
                <tr>
                    <td style="padding:8px 0;"><strong>Google Review Link:</strong></td>
                    <td style="padding:8px 0;"><?php echo RG_GOOGLE_REVIEW_LINK ? '✅ ' . RG_GOOGLE_REVIEW_LINK : '❌ Not set'; ?></td>
                </tr>
                <tr>
                    <td style="padding:8px 0;"><strong>Admin Email:</strong></td>
                    <td style="padding:8px 0;">✅ <?php echo RG_ADMIN_EMAIL; ?></td>
                </tr>
                <tr>
                    <td style="padding:8px 0;"><strong>Delay After Delivery:</strong></td>
                    <td style="padding:8px 0;"><?php echo RG_REVIEW_DELAY_DAYS; ?> days</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;"><strong>Pending Orders:</strong></td>
                    <td style="padding:8px 0;"><strong style="color:#d63638;"><?php echo $pending_count; ?></strong> delivered orders without review request</td>
                </tr>
            </table>
        </div>
        
        <!-- Backfill Box -->
        <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:4px; margin:20px 0; max-width:700px;">
            <h2 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px;">📤 Send Review Requests to Old Orders</h2>
            
            <?php if ( $pending_count > 0 ) : ?>
            <p>Found <strong style="color:#d63638;"><?php echo $pending_count; ?></strong> delivered orders that haven't received review request yet.</p>
            
            <form method="post">
                <?php wp_nonce_field( 'rg_backfill_action' ); ?>
                
                <table style="margin:15px 0;">
                    <tr>
                        <td style="padding:8px 15px 8px 0;"><label for="rg_limit">Number of orders to process:</label></td>
                        <td><input type="number" id="rg_limit" name="rg_limit" value="<?php echo min( 50, $pending_count ); ?>" min="1" max="200" style="width:80px;"></td>
                    </tr>
                    <tr>
                        <td style="padding:8px 15px 8px 0;"><label>When to send:</label></td>
                        <td>
                            <label style="margin-right:20px;">
                                <input type="radio" name="rg_immediate" value="yes" checked> 
                                <strong>Immediately</strong> (2 min apart)
                            </label>
                            <label>
                                <input type="radio" name="rg_immediate" value="no"> 
                                After 3 days
                            </label>
                        </td>
                    </tr>
                </table>
                
                <p>
                    <button type="submit" name="rg_backfill_submit" class="button button-primary button-large">
                        📧 Send Review Requests Now
                    </button>
                </p>
                <p style="color:#666; font-size:12px; margin-top:10px;">
                    Note: Emails will be sent 2 minutes apart to avoid spam filters.
                </p>
            </form>
            <?php else : ?>
            <p style="color:#00a32a; font-size:15px;">✅ All delivered orders have received review requests!</p>
            <?php endif; ?>
        </div>
        
        <!-- Test Box -->
        <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:4px; margin:20px 0; max-width:700px;">
            <h2 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px;">🧪 Test System</h2>
            <p>Send a test review request for any order:</p>
            
            <form method="post">
                <?php wp_nonce_field( 'rg_test_action' ); ?>
                <p>
                    <label for="rg_test_order">Order ID:</label>
                    <input type="number" id="rg_test_order" name="rg_test_order" placeholder="e.g. 12345" style="width:120px; margin:0 10px;">
                    <button type="submit" name="rg_test_submit" class="button">Send Test Email</button>
                </p>
                <p style="color:#666; font-size:12px;">
                    This will send both customer email and admin reminder email.
                </p>
            </form>
        </div>
        
        <!-- How It Works -->
        <div style="background:#f0f6fc; padding:20px; border:1px solid #c3c4c7; border-radius:4px; margin:20px 0; max-width:700px;">
            <h2 style="margin-top:0;">ℹ️ How It Works</h2>
            <ol style="margin:15px 0; padding-left:20px; line-height:1.8;">
                <li>When Delhivery marks order as <strong>Delivered (DL)</strong>, system schedules review request</li>
                <li>After <strong>3 days</strong>, customer receives review request email</li>
                <li>At same time, you receive email with <strong>WhatsApp message ready to copy</strong></li>
                <li>You open WhatsApp, paste message, and send manually</li>
            </ol>
        </div>
        
    </div>
    <?php
}

// =============================================================================
// PART 10: WP-CLI COMMANDS (Optional)
// =============================================================================

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    
    WP_CLI::add_command( 'review', 'RG_Review_CLI' );
    
    class RG_Review_CLI {
        
        /**
         * Backfill delivered orders
         * 
         * ## OPTIONS
         * [--limit=<num>]
         * : Number of orders. Default 100.
         * 
         * [--immediate]
         * : Send immediately instead of 3 day delay.
         * 
         * ## EXAMPLES
         *     wp review backfill --limit=50 --immediate
         */
        public function backfill( $args, $assoc ) {
            $limit = isset( $assoc['limit'] ) ? absint( $assoc['limit'] ) : 100;
            $immediate = isset( $assoc['immediate'] );
            
            $result = rg_backfill_orders( $limit, $immediate );
            
            WP_CLI::log( "Found: {$result['found']} orders" );
            WP_CLI::log( "Scheduled: {$result['scheduled']} orders" );
            
            foreach ( $result['results'] as $r ) {
                WP_CLI::log( "  - #{$r['order_id']} {$r['customer']} → {$r['send_at']}" );
            }
            
            WP_CLI::success( 'Done!' );
        }
        
        /**
         * Send review request for specific order
         * 
         * ## OPTIONS
         * <order_id>
         * : Order ID
         */
        public function send( $args ) {
            $order_id = absint( $args[0] );
            $order = wc_get_order( $order_id );
            
            if ( ! $order ) {
                WP_CLI::error( "Order #{$order_id} not found" );
            }
            
            $order->delete_meta_data( '_rg_review_sent' );
            $order->save();
            
            rg_do_send_review( $order_id );
            
            WP_CLI::success( "Sent for #{$order_id}" );
        }
    }
}
