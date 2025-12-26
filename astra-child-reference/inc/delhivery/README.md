# Delhivery B2C API Integration for WooCommerce

**Version:** 2.1.0  
**Tested with:** WooCommerce 8.x, WordPress 6.x

A comprehensive Delhivery B2C shipping integration for WooCommerce with full API support including manifest creation, tracking, NDR management, returns (RVP), real-time rate calculation, and webhook processing.

---

## Features

### Core Features
- ✅ **Shipment Manifestation** - Create shipments directly from orders
- ✅ **Real-time Tracking** - Track shipments with scan history
- ✅ **Bulk Tracking** - Track up to 50 AWBs at once
- ✅ **Shipping Labels** - Download A4 or 4R size labels
- ✅ **Shipment Cancellation** - Cancel manifested shipments
- ✅ **GST Invoice** - Download order invoice

### Advanced Features
- ✅ **Shipping Cost Calculator** - Real-time rate calculation
- ✅ **Pincode Serviceability** - Check delivery availability
- ✅ **Heavy Product Support** - Separate serviceability check
- ✅ **NDR Management** - Re-attempt and reschedule deliveries
- ✅ **E-Waybill Support** - Required for orders >₹50,000
- ✅ **Return Shipments (RVP)** - Create reverse pickups
- ✅ **RVP QC 3.0** - Quality check on returns
- ✅ **Warehouse Management** - Create/update pickup locations
- ✅ **Waybill Pool** - Pre-fetch AWBs for faster manifest
- ✅ **Webhooks** - Real-time status updates
- ✅ **Document Download** - EPOD, signatures, QC images

### WooCommerce Integration
- ✅ **Shipping Method** - Real-time rates at checkout
- ✅ **Order Actions** - Quick actions in order view
- ✅ **Admin Metabox** - Comprehensive shipment controls
- ✅ **Order Status Column** - Color-coded Delhivery status in orders list
- ✅ **Auto Status Update** - Sync order status with delivery
- ✅ **Tracking Shortcode** - Customer-facing tracking widget
- ✅ **WP-CLI Commands** - Command line management

### Customer Experience (NEW!)
- ✅ **Email Tracking** - AWB and tracking link in order emails
- ✅ **Thank You Page** - Tracking info on order confirmation
- ✅ **My Account Tracking** - Track button in customer orders
- ✅ **WhatsApp Sharing** - Share tracking link via WhatsApp
- ✅ **Order Details** - Full tracking info with status, location, ETA

### Admin Dashboard (NEW!)
- ✅ **Dashboard Widget** - Quick stats: Pending Manifest, In Transit, OFD, RTO
- ✅ **Waybill Pool Monitor** - Low waybill warning
- ✅ **Quick Manifest Button** - One-click manifest from orders list
- ✅ **Bulk Refresh Tracking** - Update multiple orders at once
- ✅ **Settings Page Info** - API status and webhook URL display

### Serviceability & Multi-Courier Support (NEW!)
- ✅ **Serviceability Column** - Shows ✓Ready or ✗N/A in orders list
- ✅ **Smart Bulk Manifest** - Auto-skips non-serviceable pincodes
- ✅ **Pre-Manifest Check** - Warning before manifesting non-serviceable areas
- ✅ **Checkout Always Works** - Customers can buy even if Delhivery unavailable
- ✅ **Multi-Courier Ready** - Non-serviceable orders can use other couriers

### Package Profiles (Auto Weight/Dimensions)
- ✅ **Quantity-Based Profiles** - Auto-calculate weight based on item count
- ✅ **Extended Profiles** - Support for 1-10+ items
- ✅ **Dynamic Calculation** - Auto-scale for large orders
- ✅ **Volumetric Weight** - Uses higher of actual vs volumetric
- ✅ **Package Preview** - Shows dimensions before manifest

---

## Installation

### Option 1: Theme Integration (Recommended)

1. Create directory: `wp-content/themes/your-theme/inc/delhivery/`
2. Copy all files from this package to that directory
3. Add to your theme's `functions.php`:

```php
// Delhivery Integration
require_once get_template_directory() . '/inc/delhivery/delhivery-loader.php';
```

### Option 2: Must-Use Plugin

1. Create directory: `wp-content/mu-plugins/delhivery/`
2. Copy all files to that directory
3. Create `wp-content/mu-plugins/delhivery-loader.php`:

```php
<?php
require_once WPMU_PLUGIN_DIR . '/delhivery/delhivery-loader.php';
```

---

## Configuration

### Required Constants (wp-config.php)

Your existing configuration is already compatible! The integration supports these constants:

```php
// Required: Your Delhivery API Key
define( 'DELHIVERY_API_KEY', 'your-api-token-here' );

// Fallback: Also supports DELHIVERY_API_TOKEN
if (!defined('DELHIVERY_API_TOKEN')) {
    define('DELHIVERY_API_TOKEN', DELHIVERY_API_KEY);
}

// Required: Your registered warehouse/pickup location name (case-sensitive!)
define( 'DELHIVERY_PICKUP_LOCATION_NAME', 'Dhoptala Colony' );

// Optional but recommended: Client code
define( 'DELHIVERY_CLIENT_CODE', 'RATNA GEMS' );

// Return Address Details
define( 'DELHIVERY_SELLER_NAME', 'RATNA GEMS' );
define( 'DELHIVERY_RETURN_NAME', 'RATNA GEMS' );
define( 'DELHIVERY_RETURN_ADDRESS', 'B-74, Dhoptala Colony, Rajura' );
define( 'DELHIVERY_RETURN_CITY', 'Chandrapur' );
define( 'DELHIVERY_RETURN_STATE', 'Maharashtra' );
define( 'DELHIVERY_RETURN_COUNTRY', 'IN' );
define( 'DELHIVERY_RETURN_PHONE', '7067939337' );
define( 'DELHIVERY_RETURN_PIN', '442905' );

// Label Settings
define( 'DELHIVERY_LABEL_SIZE', '4R' );  // or 'A4'

// Environment: true for staging, false for production
define( 'DELHIVERY_STAGING_MODE', false );

// Optional: For webhook signature validation
define( 'DELHIVERY_API_SECRET', 'your-api-secret' );
```

### Getting API Credentials

1. Log in to [Delhivery One](https://one.delhivery.com)
2. Navigate to Settings → API Integration
3. Generate/copy your API Token
4. Note your registered warehouse name exactly as shown

### Multi-Courier Setup (For Non-Serviceable Areas)

To ensure customers can always checkout even if Delhivery doesn't service their area:

1. **Add a Fallback Shipping Method**
   - Go to: WooCommerce → Settings → Shipping → Shipping Zones
   - Select your "India" zone (or create one)
   - Add another shipping method like "Flat Rate" or "Free Shipping"
   
2. **How It Works**
   - Delhivery shipping shows only for serviceable pincodes
   - Flat Rate/Free Shipping shows for ALL pincodes
   - Customer always sees at least one shipping option
   
3. **Identifying Non-Serviceable Orders**
   - In Orders list, check the "Delhivery" column:
     - ✓ Ready = Can manifest with Delhivery
     - ✗ N/A = Use another courier (India Post, DTDC, etc.)
   
4. **Bulk Manifest Behavior**
   - Select all Processing orders → "Delhivery: Manifest Selected"
   - Only serviceable orders are manifested
   - Non-serviceable orders are skipped with a note added

---

## Usage

### Admin Order Actions

From the WooCommerce order edit screen, you can:

1. **Create Shipment** - Manifest the order with Delhivery
2. **Refresh Status** - Update tracking information
3. **Download Label** - Download shipping label (A4/4R)
4. **Download Invoice** - Download GST invoice
5. **Schedule Pickup** - Request package collection
6. **NDR Actions** - Request re-attempt or reschedule
7. **Create Return** - Generate return shipment (RVP)
8. **Update Shipment** - Change weight, address, phone
9. **Convert Payment** - Switch between COD/Prepaid
10. **Update E-Waybill** - Link e-waybill for high-value orders
11. **Get Documents** - Download EPOD, signature, etc.
12. **Cancel Shipment** - Cancel before dispatch

### Bulk Actions

Select multiple orders in the orders list and use:
- **Bulk Manifest** - Create shipments for all selected
- **Bulk Refresh** - Update tracking for all selected

### WP-CLI Commands

```bash
# Test API connection
wp delhivery test --allow-live

# Check pincode
wp delhivery pincode 110001
wp delhivery pincode 110001 --heavy

# Calculate shipping cost
wp delhivery cost 110001 400001 --weight=1000 --mode=E

# Track shipment
wp delhivery track 1234567890123 --detailed

# Fetch waybills to pool
wp delhivery fetch-waybills --count=100

# Check waybill pool
wp delhivery pool-status

# Create warehouse
wp delhivery create-warehouse "My Warehouse" --phone=9876543210 --pin=110001

# Manifest single order
wp delhivery manifest 123

# Bulk manifest
wp delhivery bulk-manifest --status=processing --limit=50

# Refresh tracking for active orders
wp delhivery refresh-tracking --days=7

# Show configuration
wp delhivery config
```

### Tracking Shortcode

Add to any page:

```
[delhivery_tracking title="Track Your Order"]
```

### Shipping Method Setup

1. Go to WooCommerce → Settings → Shipping
2. Add a shipping zone for India
3. Add shipping method "Delhivery"
4. Configure options:
   - Enable Express/Surface shipping
   - Set origin pincode
   - Set free shipping threshold
   - Set handling fee
   - Set fallback cost

### Package Profiles (Auto Weight/Dimensions)

The integration automatically calculates package weight and dimensions based on item count:

| Items | Weight | Length | Width | Height |
|-------|--------|--------|-------|--------|
| 1 | 70g | 16cm | 12cm | 3cm |
| 2 | 140g | 24cm | 18cm | 3cm |
| 3 | 210g | 24cm | 18cm | 5cm |
| 4 | 280g | 24cm | 18cm | 6cm |
| 5 | 350g | 30cm | 20cm | 6cm |
| 6 | 420g | 30cm | 20cm | 8cm |
| 7 | 490g | 30cm | 24cm | 8cm |
| 8 | 560g | 30cm | 24cm | 10cm |
| 9 | 630g | 36cm | 24cm | 10cm |
| 10 | 700g | 36cm | 24cm | 12cm |
| 11+ | Calculated dynamically |

**Customize Profiles:**

```php
// Override all profiles
add_filter( 'rg_delhivery_package_profiles', function( $profiles ) {
    $profiles[5] = array( 'weight' => 400, 'length' => 32, 'width' => 22, 'height' => 8 );
    return $profiles;
});

// Override for specific order
add_filter( 'rg_delhivery_package_profile', function( $profile, $qty, $profiles, $client ) {
    // Custom logic
    return $profile;
}, 10, 4 );

// Change volumetric divisor (default 5000)
add_filter( 'rg_delhivery_volumetric_divisor', function( $divisor ) {
    return 4000; // For some carriers
});
```

---

## Webhook Setup

To receive real-time updates:

1. Email `lastmile-integration@delhivery.com` with:
   - Your Delhivery account name
   - Webhook URL: `https://yoursite.com/wp-json/rg-delhivery/v1/webhook`
   - POD Webhook: `https://yoursite.com/wp-json/rg-delhivery/v1/webhook/pod`
   - Authorization details (if required)

2. Set `DELHIVERY_API_SECRET` for signature validation

---

## API Rate Limits

Be aware of Delhivery's rate limits:

| API | Limit | Window |
|-----|-------|--------|
| Pincode Serviceability | 4,500 | 5 min |
| Tracking | 750 | 5 min |
| Fetch Waybill | 5 | 5 min |
| Bulk Waybill | 10,000/request | 50,000/5 min |

---

## Payment Mode Conversion Rules

| From | To | Allowed |
|------|-----|---------|
| COD | Prepaid | ✅ Yes |
| Prepaid | COD | ✅ Yes (provide amount) |
| Prepaid | Pickup | ❌ No |
| Pickup | Prepaid | ❌ No |
| COD | REPL | ❌ No |
| Prepaid | REPL | ❌ No |

---

## Delhivery Status Values

The integration recognizes these Delhivery status values and auto-updates WooCommerce order status accordingly:

| Delhivery Status | WooCommerce Status | Description |
|------------------|-------------------|-------------|
| `Delivered` / `DELIVERED` / `DL` | Completed | Successfully delivered |
| `Success` / `SUCCESS` | Completed | Successfully delivered (alternate) |
| `RTO` / `RTO-OC` / `RTO-Delivered` | Cancelled | Return to Origin |
| `Returned` / `RETURNED` | Refunded | Returned to seller |

**Status Flags Used:**
- `$is_delivered` - True for: DELIVERED, DL, SUCCESS, or any status containing "DELIVERED" or "SUCCESS"
- `$is_rto` - True for: any status containing "RTO" or "RETURNED"
- `$is_cancelled` - True for: any status containing "CANCEL"
- `$is_ndr` - True for: any status containing "NDR", "UNDELIVER", or "FAILED"
- `$is_dispatched` - True for: any status containing "DISPATCH", "TRANSIT", or "OUT FOR"
- `$is_final` - True if delivered, RTO, or cancelled (no more actions available)

**Note:** Delhivery API may return either "Delivered" or "Success" for successful deliveries depending on the tracking context. Both are handled identically.

---

## NDR Action Rules

### RE-ATTEMPT
Applicable NSL codes: EOD-74, EOD-15, EOD-104, EOD-43, EOD-86, EOD-11, EOD-69, EOD-6

### PICKUP_RESCHEDULE
Applicable NSL codes: EOD-777, EOD-21
Note: Shipment must be Non-OTP Cancelled

**Important:** Apply NDR actions after 9 PM to ensure all dispatches are closed.

---

## Shipment Edit Restrictions

Edits allowed only for these statuses:

**Forward (COD/Prepaid):**
- Manifested
- In Transit
- Pending

**RVP (Pickup):**
- Scheduled

**NOT allowed for:**
- Dispatched
- Delivered
- RTO
- LOST
- Closed

---

## Troubleshooting

### "API credentials not configured"
Ensure `DELHIVERY_API_TOKEN` is defined in wp-config.php.

### "Shipment creation failed"
- Check warehouse name matches exactly (case-sensitive)
- Verify destination pincode is serviceable
- Ensure all required fields are present

### "Rate calculation not working"
- Set `DELHIVERY_ORIGIN_PINCODE` in wp-config.php
- Verify both origin and destination are serviceable
- Check you haven't exceeded rate limits

### "Webhook not receiving updates"
- Verify webhook URL is accessible
- Check SSL certificate is valid
- Confirm webhook is registered with Delhivery

### "E-waybill required" notice
Orders above ₹50,000 require e-waybill by Indian law. Generate on the GST portal and update in the order.

---

## Files Structure

```
delhivery/
├── delhivery-loader.php    # Main loader (include this in functions.php)
├── api-client.php          # Complete API client class
├── order-actions.php       # WooCommerce order actions
├── pickup.php              # AJAX handlers
├── admin-metabox.php       # Admin UI metabox
├── shipping-method.php     # WooCommerce shipping method
├── enhancements.php        # Customer emails, dashboard widget, WhatsApp, etc.
├── cli.php                 # WP-CLI commands
├── assets/
│   ├── admin.js            # Admin JavaScript
│   └── frontend.js         # Frontend JavaScript
└── README.md               # This file
```

---

## Changelog

### 2.0.0
- Complete rewrite with full B2C API coverage
- Added shipping cost calculation
- Added heavy product serviceability
- Added warehouse management
- Added e-waybill support
- Added RVP QC 3.0 support
- Added webhook processing
- Added document download API
- Added WP-CLI commands
- Added WooCommerce shipping method
- Enhanced admin metabox with tabs
- Added tracking shortcode
- Added order status column
- Added auto status sync
- Improved error handling
- Added rate limit awareness

---

## Support

For Delhivery API issues, contact:
- Email: `lastmile-integration@delhivery.com`
- Documentation: [Delhivery API Platform](https://dlv-api.delhivery.com/)

---

## License

This integration is proprietary software for Ratna Gems.

---

*Built with ❤️ for seamless Delhivery integration*
