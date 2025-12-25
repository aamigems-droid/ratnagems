<?php
/**
 * Enhanced WP-CLI commands for Delhivery API testing and management.
 *
 * @package Ratna Gems
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
    return;
}

/**
 * Delhivery API management commands.
 */
class RG_Delhivery_CLI {

    /**
     * Test API connectivity and credentials.
     *
     * ## OPTIONS
     *
     * [--allow-live]
     * : Allow tests against live API (otherwise only validates config)
     *
     * ## EXAMPLES
     *
     *     wp delhivery test
     *     wp delhivery test --allow-live
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function test( $args, $assoc_args ) {
        $client = rg_delhivery_client();
        
        if ( ! $client || ! $client->is_configured() ) {
            WP_CLI::error( 'Delhivery API not configured. Set DELHIVERY_API_TOKEN constant.' );
        }
        
        WP_CLI::success( 'API credentials are configured.' );
        
        $allow_live = isset( $assoc_args['allow-live'] );
        
        if ( $allow_live ) {
            WP_CLI::log( 'Testing live API...' );
            
            // Test pincode serviceability
            $result = $client->pincode_serviceability( '110001' );
            if ( is_wp_error( $result ) ) {
                WP_CLI::warning( 'Pincode check failed: ' . $result->get_error_message() );
            } else {
                WP_CLI::success( 'Pincode API working. Delhi 110001 serviceable: ' . ( $result['is_serviceable'] ? 'Yes' : 'No' ) );
            }
            
            // Test shipping cost
            $cost_result = $client->calculate_shipping_cost( array(
                'o_pin' => '110001',
                'd_pin' => '400001',
                'cgm'   => 500,
                'md'    => 'E',
                'pt'    => 'Pre-paid',
                'ss'    => 'Delivered',
            ) );
            
            if ( is_wp_error( $cost_result ) ) {
                WP_CLI::warning( 'Shipping cost API failed: ' . $cost_result->get_error_message() );
            } else {
                $total = $cost_result['cost']['total_amount'] ?? $cost_result['cost']['total'] ?? 'N/A';
                WP_CLI::success( 'Shipping cost API working. Delhi→Mumbai 500g: ₹' . $total );
            }
        } else {
            WP_CLI::log( 'Use --allow-live to test against live API.' );
        }
    }

    /**
     * Check pincode serviceability.
     *
     * ## OPTIONS
     *
     * <pincode>
     * : The 6-digit pincode to check
     *
     * [--heavy]
     * : Check for heavy product serviceability
     *
     * ## EXAMPLES
     *
     *     wp delhivery pincode 110001
     *     wp delhivery pincode 110001 --heavy
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function pincode( $args, $assoc_args ) {
        $pincode = $args[0];
        
        if ( ! preg_match( '/^\d{6}$/', $pincode ) ) {
            WP_CLI::error( 'Invalid pincode format. Must be 6 digits.' );
        }
        
        $client = rg_delhivery_client();
        $heavy = isset( $assoc_args['heavy'] );
        
        if ( $heavy ) {
            $result = $client->heavy_product_serviceability( $pincode );
        } else {
            $result = $client->pincode_serviceability( $pincode );
        }
        
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }
        
        WP_CLI::log( 'Pincode: ' . $pincode . ( $heavy ? ' (Heavy)' : '' ) );
        WP_CLI::log( 'Serviceable: ' . ( $result['is_serviceable'] ? 'Yes' : 'No' ) );
        WP_CLI::log( 'Embargo: ' . ( $result['has_embargo'] ? 'Yes' : 'No' ) );
        
        if ( ! empty( $result['remarks'] ) ) {
            WP_CLI::log( 'Remarks: ' . implode( ', ', $result['remarks'] ) );
        }
    }

    /**
     * Calculate shipping cost.
     *
     * ## OPTIONS
     *
     * <origin>
     * : Origin pincode
     *
     * <destination>
     * : Destination pincode
     *
     * [--weight=<grams>]
     * : Weight in grams (default: 500)
     *
     * [--mode=<mode>]
     * : Shipping mode E=Express, S=Surface (default: E)
     *
     * [--payment=<type>]
     * : Payment type Pre-paid or COD (default: Pre-paid)
     *
     * ## EXAMPLES
     *
     *     wp delhivery cost 110001 400001
     *     wp delhivery cost 110001 400001 --weight=1000 --mode=S
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function cost( $args, $assoc_args ) {
        $origin = $args[0];
        $destination = $args[1];
        $weight = isset( $assoc_args['weight'] ) ? absint( $assoc_args['weight'] ) : 500;
        $mode = isset( $assoc_args['mode'] ) ? strtoupper( $assoc_args['mode'] ) : 'E';
        $payment = isset( $assoc_args['payment'] ) ? $assoc_args['payment'] : 'Pre-paid';
        
        $client = rg_delhivery_client();
        $result = $client->calculate_shipping_cost( array(
            'o_pin' => $origin,
            'd_pin' => $destination,
            'cgm'   => $weight,
            'md'    => $mode,
            'pt'    => $payment,
            'ss'    => 'Delivered',
        ) );
        
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }
        
        $cost = $result['cost'];
        
        // Handle array response (Delhivery returns array of cost objects)
        if ( is_array( $cost ) && isset( $cost[0] ) && is_array( $cost[0] ) ) {
            $cost = $cost[0];
        }
        
        WP_CLI::log( sprintf( 'Route: %s → %s', $origin, $destination ) );
        WP_CLI::log( sprintf( 'Weight: %d grams | Mode: %s | Payment: %s', $weight, $mode === 'E' ? 'Express' : 'Surface', $payment ) );
        WP_CLI::log( '---' );
        
        if ( isset( $cost['total_amount'] ) ) {
            WP_CLI::log( 'Cost breakdown:' );
            
            // Show individual charges
            $charge_labels = array(
                'charge_DL'  => 'Delivery',
                'charge_LM'  => 'Last Mile',
                'charge_DPH' => 'Handling',
                'charge_COD' => 'COD',
                'charge_FSC' => 'Fuel Surcharge',
                'charge_RTO' => 'RTO Charge',
            );
            
            foreach ( $charge_labels as $key => $label ) {
                if ( isset( $cost[ $key ] ) && (float) $cost[ $key ] > 0 ) {
                    WP_CLI::log( sprintf( '  • %s: ₹%.2f', $label, (float) $cost[ $key ] ) );
                }
            }
            
            // Show totals
            if ( isset( $cost['gross_amount'] ) ) {
                WP_CLI::log( sprintf( '  ─────────────────' ) );
                WP_CLI::log( sprintf( '  Subtotal: ₹%.2f', (float) $cost['gross_amount'] ) );
            }
            
            // Tax breakdown
            if ( isset( $cost['tax_data'] ) && is_array( $cost['tax_data'] ) ) {
                $tax = $cost['tax_data'];
                if ( isset( $tax['CGST'] ) && (float) $tax['CGST'] > 0 ) {
                    WP_CLI::log( sprintf( '  CGST: ₹%.2f', (float) $tax['CGST'] ) );
                }
                if ( isset( $tax['SGST'] ) && (float) $tax['SGST'] > 0 ) {
                    WP_CLI::log( sprintf( '  SGST: ₹%.2f', (float) $tax['SGST'] ) );
                }
                if ( isset( $tax['IGST'] ) && (float) $tax['IGST'] > 0 ) {
                    WP_CLI::log( sprintf( '  IGST: ₹%.2f', (float) $tax['IGST'] ) );
                }
            }
            
            WP_CLI::log( sprintf( '  ─────────────────' ) );
            WP_CLI::success( sprintf( 'Total: ₹%.2f', (float) $cost['total_amount'] ) );
            
            // Show zone info
            if ( isset( $cost['zone'] ) ) {
                WP_CLI::log( sprintf( '📍 Zone: %s | Charged Weight: %sg', $cost['zone'], $cost['charged_weight'] ?? 'N/A' ) );
            }
        } elseif ( isset( $cost['total'] ) ) {
            WP_CLI::success( sprintf( 'Total Cost: ₹%s', $cost['total'] ) );
        } else {
            WP_CLI::log( 'Cost breakdown:' );
            foreach ( $cost as $key => $value ) {
                // Handle nested arrays
                if ( is_array( $value ) ) {
                    WP_CLI::log( sprintf( '  %s:', $key ) );
                    foreach ( $value as $k => $v ) {
                        if ( ! is_array( $v ) ) {
                            WP_CLI::log( sprintf( '    %s: %s', $k, $v ) );
                        }
                    }
                } else {
                    WP_CLI::log( sprintf( '  %s: %s', $key, $value ) );
                }
            }
        }
    }

    /**
     * Track a shipment by AWB.
     *
     * ## OPTIONS
     *
     * <awb>
     * : The waybill number to track
     *
     * [--detailed]
     * : Show full scan history
     *
     * ## EXAMPLES
     *
     *     wp delhivery track 1234567890123
     *     wp delhivery track 1234567890123 --detailed
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function track( $args, $assoc_args ) {
        $awb = $args[0];
        $detailed = isset( $assoc_args['detailed'] );
        
        $client = rg_delhivery_client();
        $result = $client->get_tracking_summary( $awb );
        
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }
        
        WP_CLI::log( 'AWB: ' . $result['awb'] );
        WP_CLI::log( 'Status: ' . $result['status'] );
        WP_CLI::log( 'Status Type: ' . $result['status_type'] );
        WP_CLI::log( 'Location: ' . ( $result['status_location'] ?: 'N/A' ) );
        WP_CLI::log( 'Updated: ' . ( $result['status_datetime'] ?: 'N/A' ) );
        
        if ( $result['expected_date'] ) {
            WP_CLI::log( 'Expected Delivery: ' . $result['expected_date'] );
        }
        
        if ( $detailed && ! empty( $result['scans'] ) ) {
            WP_CLI::log( "\n--- Scan History ---" );
            foreach ( $result['scans'] as $scan ) {
                WP_CLI::log( sprintf(
                    '[%s] %s - %s',
                    $scan['datetime'] ?? 'N/A',
                    $scan['status'] ?? 'N/A',
                    $scan['location'] ?? 'N/A'
                ) );
            }
        }
    }

    /**
     * Fetch waybills and store in pool.
     *
     * NOTE: Delhivery B2C API generates AWB numbers on-the-fly during manifestation.
     * Pre-fetching waybills is NOT required for B2C. AWBs are automatically
     * assigned when you click "Create Shipment" on an order.
     *
     * ## OPTIONS
     *
     * [--count=<number>]
     * : Number of waybills to fetch (default: 100, max: 10000)
     *
     * ## EXAMPLES
     *
     *     wp delhivery fetch_waybills
     *     wp delhivery fetch_waybills --count=500
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function fetch_waybills( $args, $assoc_args ) {
        $count = isset( $assoc_args['count'] ) ? min( absint( $assoc_args['count'] ), 10000 ) : 100;
        
        $client = rg_delhivery_client();
        WP_CLI::log( sprintf( 'Fetching %d waybills...', $count ) );
        
        $result = $client->prefetch_and_store_waybills( $count );
        
        if ( is_wp_error( $result ) ) {
            // B2C API may not support pre-fetching
            WP_CLI::warning( 'Waybill pre-fetching may not be available for B2C API.' );
            WP_CLI::warning( $result->get_error_message() );
            WP_CLI::log( '' );
            WP_CLI::log( '📝 NOTE: Delhivery B2C generates AWB numbers automatically when you create a shipment.' );
            WP_CLI::log( '   You do NOT need to pre-fetch waybills. Simply click "Create Shipment" on any order.' );
            return;
        }
        
        // Handle both old and new response formats
        $fetched = $result['fetched'] ?? $result['stored'] ?? 0;
        $pool_size = $result['total_pool'] ?? $result['pool_size'] ?? 0;
        
        if ( $fetched > 0 ) {
            WP_CLI::success( sprintf( 'Stored %d waybills. Pool size: %d', $fetched, $pool_size ) );
        } else {
            WP_CLI::warning( 'No waybills were fetched. This is normal for B2C API.' );
            WP_CLI::log( '' );
            WP_CLI::log( '📝 NOTE: Delhivery B2C generates AWB numbers automatically when you create a shipment.' );
            WP_CLI::log( '   You do NOT need to pre-fetch waybills. Simply click "Create Shipment" on any order.' );
        }
    }

    /**
     * Show waybill pool status.
     *
     * ## EXAMPLES
     *
     *     wp delhivery pool-status
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function pool_status( $args, $assoc_args ) {
        $pool = get_option( 'rg_delhivery_waybill_pool', array() );
        $last_fetch = get_option( 'rg_delhivery_waybill_last_fetch', 'Never' );
        
        WP_CLI::log( 'Waybill Pool Status' );
        WP_CLI::log( '---' );
        WP_CLI::log( sprintf( 'Available: %d waybills', count( $pool ) ) );
        WP_CLI::log( sprintf( 'Last Fetch: %s', $last_fetch ) );
        
        if ( count( $pool ) < 50 ) {
            WP_CLI::warning( 'Pool is running low! Consider fetching more waybills.' );
        } else {
            WP_CLI::success( 'Pool is healthy.' );
        }
    }

    /**
     * Create a warehouse/pickup location.
     *
     * ## OPTIONS
     *
     * <name>
     * : Warehouse name (case-sensitive, cannot be changed later)
     *
     * --phone=<phone>
     * : Contact phone number
     *
     * --pin=<pincode>
     * : Warehouse pincode
     *
     * [--address=<address>]
     * : Full address
     *
     * [--city=<city>]
     * : City name
     *
     * ## EXAMPLES
     *
     *     wp delhivery create-warehouse "Main Warehouse" --phone=9876543210 --pin=110001
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function create_warehouse( $args, $assoc_args ) {
        $name = $args[0];
        $phone = $assoc_args['phone'] ?? '';
        $pin = $assoc_args['pin'] ?? '';
        
        if ( empty( $phone ) || empty( $pin ) ) {
            WP_CLI::error( 'Phone and pin are required.' );
        }
        
        $client = rg_delhivery_client();
        $result = $client->create_warehouse( array(
            'name'           => $name,
            'phone'          => $phone,
            'pin'            => $pin,
            'address'        => $assoc_args['address'] ?? '',
            'city'           => $assoc_args['city'] ?? '',
            'country'        => 'India',
            'return_name'    => $name,
            'return_phone'   => $phone,
            'return_pin'     => $pin,
            'return_address' => $assoc_args['address'] ?? '',
            'return_city'    => $assoc_args['city'] ?? '',
            'return_country' => 'India',
        ) );
        
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }
        
        WP_CLI::success( sprintf( 'Warehouse "%s" created successfully!', $name ) );
    }

    /**
     * Manifest an order with Delhivery.
     *
     * ## OPTIONS
     *
     * <order_id>
     * : WooCommerce order ID
     *
     * ## EXAMPLES
     *
     *     wp delhivery manifest 123
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function manifest( $args, $assoc_args ) {
        $order_id = absint( $args[0] );
        $order = wc_get_order( $order_id );
        
        if ( ! $order ) {
            WP_CLI::error( 'Order not found.' );
        }
        
        if ( $order->get_meta( '_delhivery_awb' ) ) {
            WP_CLI::error( sprintf( 'Order already has AWB: %s', $order->get_meta( '_delhivery_awb' ) ) );
        }
        
        $client = rg_delhivery_client();
        $result = $client->manifest_order( $order );
        
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }
        
        $order->update_meta_data( '_delhivery_awb', $result['awb'] );
        $order->update_meta_data( '_delhivery_status', $result['status'] ?? 'Manifested' );
        $order->update_meta_data( '_delhivery_manifest_time', current_time( 'mysql' ) );
        $order->save();
        
        $order->add_order_note( sprintf( 'Manifested via CLI. AWB: %s', $result['awb'] ) );
        
        WP_CLI::success( sprintf( 'Order %d manifested! AWB: %s', $order_id, $result['awb'] ) );
    }

    /**
     * Bulk manifest multiple orders.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Order status to filter (default: processing)
     *
     * [--limit=<number>]
     * : Maximum orders to manifest (default: 10)
     *
     * [--dry-run]
     * : Show what would be manifested without actually doing it
     *
     * ## EXAMPLES
     *
     *     wp delhivery bulk-manifest
     *     wp delhivery bulk-manifest --status=on-hold --limit=50
     *     wp delhivery bulk-manifest --dry-run
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function bulk_manifest( $args, $assoc_args ) {
        $status = $assoc_args['status'] ?? 'processing';
        $limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 10;
        $dry_run = isset( $assoc_args['dry-run'] );
        
        $orders = wc_get_orders( array(
            'status'     => $status,
            'limit'      => $limit,
            'meta_query' => array(
                array(
                    'key'     => '_delhivery_awb',
                    'compare' => 'NOT EXISTS',
                ),
            ),
        ) );
        
        if ( empty( $orders ) ) {
            WP_CLI::warning( 'No orders found to manifest.' );
            return;
        }
        
        WP_CLI::log( sprintf( 'Found %d orders to manifest.', count( $orders ) ) );
        
        if ( $dry_run ) {
            WP_CLI::log( 'DRY RUN - No changes will be made.' );
            foreach ( $orders as $order ) {
                WP_CLI::log( sprintf( '  Would manifest: Order #%d - %s', $order->get_id(), $order->get_formatted_billing_full_name() ) );
            }
            return;
        }
        
        $client = rg_delhivery_client();
        $success = 0;
        $failed = 0;
        
        foreach ( $orders as $order ) {
            $result = $client->manifest_order( $order );
            
            if ( is_wp_error( $result ) ) {
                WP_CLI::warning( sprintf( 'Order #%d failed: %s', $order->get_id(), $result->get_error_message() ) );
                $failed++;
                continue;
            }
            
            $order->update_meta_data( '_delhivery_awb', $result['awb'] );
            $order->update_meta_data( '_delhivery_status', $result['status'] ?? 'Manifested' );
            $order->update_meta_data( '_delhivery_manifest_time', current_time( 'mysql' ) );
            $order->save();
            
            $order->add_order_note( sprintf( 'Bulk manifested via CLI. AWB: %s', $result['awb'] ) );
            
            WP_CLI::log( sprintf( '✓ Order #%d: AWB %s', $order->get_id(), $result['awb'] ) );
            $success++;
        }
        
        WP_CLI::success( sprintf( 'Manifested %d orders. Failed: %d', $success, $failed ) );
    }

    /**
     * Refresh tracking status for orders.
     *
     * ## OPTIONS
     *
     * [--days=<days>]
     * : Refresh orders manifested within N days (default: 7)
     *
     * [--limit=<number>]
     * : Maximum orders to refresh (default: 50)
     *
     * ## EXAMPLES
     *
     *     wp delhivery refresh-tracking
     *     wp delhivery refresh-tracking --days=30 --limit=100
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function refresh_tracking( $args, $assoc_args ) {
        $days = isset( $assoc_args['days'] ) ? absint( $assoc_args['days'] ) : 7;
        $limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 50;
        
        $orders = wc_get_orders( array(
            'limit'      => $limit,
            'date_after' => date( 'Y-m-d', strtotime( "-{$days} days" ) ),
            'meta_query' => array(
                array(
                    'key'     => '_delhivery_awb',
                    'compare' => 'EXISTS',
                ),
                array(
                    'key'     => '_delhivery_status',
                    'value'   => array( 'Delivered', 'DELIVERED', 'DL', 'RTO', 'Returned', 'Cancelled' ),
                    'compare' => 'NOT IN',
                ),
            ),
        ) );
        
        if ( empty( $orders ) ) {
            WP_CLI::warning( 'No orders found to refresh.' );
            return;
        }
        
        // Collect AWBs for batch tracking
        $awb_map = array();
        foreach ( $orders as $order ) {
            $awb = $order->get_meta( '_delhivery_awb' );
            if ( $awb ) {
                $awb_map[ $awb ] = $order;
            }
        }
        
        $awbs = array_keys( $awb_map );
        WP_CLI::log( sprintf( 'Refreshing %d shipments...', count( $awbs ) ) );
        
        $client = rg_delhivery_client();
        
        // Track in batches of 50
        $batches = array_chunk( $awbs, 50 );
        $updated = 0;
        
        foreach ( $batches as $batch ) {
            $results = $client->track_multiple_shipments( $batch );
            
            if ( is_wp_error( $results ) ) {
                WP_CLI::warning( 'Batch tracking failed: ' . $results->get_error_message() );
                continue;
            }
            
            foreach ( $results as $awb => $data ) {
                if ( ! isset( $awb_map[ $awb ] ) ) continue;
                
                $order = $awb_map[ $awb ];
                $old_status = $order->get_meta( '_delhivery_status' );
                $new_status = $data['status'] ?? $data['Status']['Status'] ?? '';
                
                if ( $new_status && $new_status !== $old_status ) {
                    $order->update_meta_data( '_delhivery_status', $new_status );
                    $order->update_meta_data( '_delhivery_last_update', current_time( 'mysql' ) );
                    $order->save();
                    
                    WP_CLI::log( sprintf( 'Order #%d: %s → %s', $order->get_id(), $old_status, $new_status ) );
                    $updated++;
                }
            }
        }
        
        WP_CLI::success( sprintf( 'Updated %d orders.', $updated ) );
    }

    /**
     * Show Delhivery configuration status.
     *
     * ## EXAMPLES
     *
     *     wp delhivery config
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function config( $args, $assoc_args ) {
        WP_CLI::log( 'Delhivery Configuration' );
        WP_CLI::log( '---' );
        
        // API Key/Token
        $api_key = defined( 'DELHIVERY_API_KEY' ) ? DELHIVERY_API_KEY : ( defined( 'DELHIVERY_API_TOKEN' ) ? DELHIVERY_API_TOKEN : '' );
        WP_CLI::log( sprintf( 'API Key: %s', $api_key ? 'Set (' . strlen( $api_key ) . ' chars)' : 'NOT SET' ) );
        
        WP_CLI::log( sprintf( 'API Secret: %s', defined( 'DELHIVERY_API_SECRET' ) ? 'Set' : 'Not set' ) );
        WP_CLI::log( sprintf( 'Pickup Location: %s', defined( 'DELHIVERY_PICKUP_LOCATION_NAME' ) ? DELHIVERY_PICKUP_LOCATION_NAME : 'Not set' ) );
        WP_CLI::log( sprintf( 'Client Code: %s', defined( 'DELHIVERY_CLIENT_CODE' ) ? DELHIVERY_CLIENT_CODE : 'Not set' ) );
        WP_CLI::log( sprintf( 'Return Pin: %s', defined( 'DELHIVERY_RETURN_PIN' ) ? DELHIVERY_RETURN_PIN : 'Not set' ) );
        WP_CLI::log( sprintf( 'Label Size: %s', defined( 'DELHIVERY_LABEL_SIZE' ) ? DELHIVERY_LABEL_SIZE : 'A4 (default)' ) );
        WP_CLI::log( sprintf( 'Environment: %s', ( defined( 'DELHIVERY_STAGING_MODE' ) && DELHIVERY_STAGING_MODE ) ? 'Staging' : 'Production' ) );
        
        $pool = get_option( 'rg_delhivery_waybill_pool', array() );
        WP_CLI::log( sprintf( 'Waybill Pool: %d available', count( $pool ) ) );
        
        // Webhook endpoint
        $webhook_url = rest_url( 'rg-delhivery/v1/webhook' );
        WP_CLI::log( sprintf( 'Webhook URL: %s', $webhook_url ) );
        WP_CLI::log( sprintf( 'POD Webhook: %s', rest_url( 'rg-delhivery/v1/webhook/pod' ) ) );
        
        // Return details
        WP_CLI::log( '' );
        WP_CLI::log( 'Return Address:' );
        WP_CLI::log( sprintf( '  Name: %s', defined( 'DELHIVERY_RETURN_NAME' ) ? DELHIVERY_RETURN_NAME : 'Not set' ) );
        WP_CLI::log( sprintf( '  Address: %s', defined( 'DELHIVERY_RETURN_ADDRESS' ) ? DELHIVERY_RETURN_ADDRESS : 'Not set' ) );
        WP_CLI::log( sprintf( '  City: %s', defined( 'DELHIVERY_RETURN_CITY' ) ? DELHIVERY_RETURN_CITY : 'Not set' ) );
        WP_CLI::log( sprintf( '  State: %s', defined( 'DELHIVERY_RETURN_STATE' ) ? DELHIVERY_RETURN_STATE : 'Not set' ) );
        WP_CLI::log( sprintf( '  Phone: %s', defined( 'DELHIVERY_RETURN_PHONE' ) ? DELHIVERY_RETURN_PHONE : 'Not set' ) );
    }
}

WP_CLI::add_command( 'delhivery', 'RG_Delhivery_CLI' );
