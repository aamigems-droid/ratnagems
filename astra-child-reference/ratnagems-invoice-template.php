<?php
/**
 * RatnaGems Invoice – v24 (GST-ready for ≤ ₹5 Cr AATO)
 *
 * Drop this file in your child theme as: ratnagems-invoice-template.php
 * It is included by: inc/delhivery/print-slip.php
 *
 * Notes:
 * - Shows PoS + GST state numeric code, reverse charge flag, HSN(≥4), UQC, tax breakup, IGST vs. CGST/SGST.
 * - Uses WooCommerce order APIs only (HPOS-safe).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! isset( $order ) || ! $order instanceof WC_Order ) {
    wp_die( esc_html__( 'Order context missing for invoice.', 'ratna-gems' ) );
}

/* ---------------------------- CONFIG ---------------------------- */

$brand_logo_url     = 'https://ratnagems.com/wp-content/uploads/2025/09/Ratna-Gems-New-Logo.svg';
$logo_url           = $brand_logo_url;
$watermark_logo_url = 'https://ratnagems.com/wp-content/uploads/2025/09/Ratna-Gems-Icon-512.png';

$business = array(
    'name'    => __( 'Ratna Gems', 'ratna-gems' ),
    'address' => __( 'B-74, Dhoptala Colony, Rajura, Chandrapur - 442905, Maharashtra, IN', 'ratna-gems' ),
    'gstin'   => '27MZAPS4789R1ZV',
    'email'   => 'admin@ratna-gems.com',
    'phone'   => '+91 7067939337',
    'website' => 'https://ratnagems.com',
);

// Two-letter code for your registration state (supplier)
$issuer_state_alpha  = 'MH';
$reverse_charge_flag = __( 'No', 'ratna-gems' );

$footer_note = __( 'This is a computer-generated invoice; no signature required.', 'ratna-gems' );
$terms_conditions = array(
    __( 'All gemstones and Rudraksha beads are tested by accredited laboratories; treatment disclosures are provided where applicable.', 'ratna-gems' ),
    __( 'Returns are accepted within 7 days if unused and in original condition. Unboxing video is mandatory for claims.', 'ratna-gems' ),
    __( 'Subject to Rajura jurisdiction.', 'ratna-gems' ),
    __( 'See ratnagems.com for full policy details.', 'ratna-gems' ),
);

/* ---------------------------- HELPERS ---------------------------- */

if ( ! function_exists( 'rgx_format_price' ) ) {
    function rgx_format_price( $amount, $currency = '' ) {
        $currency = $currency ?: ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'INR' );
        if ( function_exists( 'wc_price' ) ) {
            $args = array( 'currency' => $currency );
            if ( function_exists( 'wc_get_price_decimal_separator' ) ) { $args['decimal_separator']  = wc_get_price_decimal_separator(); }
            if ( function_exists( 'wc_get_price_thousand_separator' ) ) { $args['thousand_separator'] = wc_get_price_thousand_separator(); }
            if ( function_exists( 'wc_get_price_decimals' ) )        { $args['decimals']            = wc_get_price_decimals(); }
            $formatted = wp_strip_all_tags( wc_price( (float) $amount, $args ) );
            $charset   = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'charset' ) : 'UTF-8';
            return trim( html_entity_decode( $formatted, ENT_QUOTES, $charset ) );
        }
        $symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $currency ) : '₹';
        return $symbol . number_format( (float) $amount, 2, '.', ',' );
    }
}

if ( ! function_exists( 'rgx_state_numeric_code' ) ) {
    function rgx_state_numeric_code( $alpha ) {
        $alpha = strtoupper( trim( (string) $alpha ) );
        $map   = array(
            'JK'=>'01','HP'=>'02','PB'=>'03','CH'=>'04','UT'=>'05','UK'=>'05','HR'=>'06','DL'=>'07','RJ'=>'08','UP'=>'09','BR'=>'10',
            'SK'=>'11','AR'=>'12','NL'=>'13','MN'=>'14','MZ'=>'15','TR'=>'16','ME'=>'17','AS'=>'18','WB'=>'19','JH'=>'20','OR'=>'21',
            'CT'=>'22','MP'=>'23','GJ'=>'24','DD'=>'25','DN'=>'26','MH'=>'27','AP'=>'37','TL'=>'36','TS'=>'36','KA'=>'29','KL'=>'32',
            'TN'=>'33','GA'=>'30','PY'=>'34','AN'=>'35','LA'=>'38'
        );
        return $map[ $alpha ] ?? '';
    }
}

if ( ! function_exists( 'rgx_state_name' ) ) {
    function rgx_state_name( $alpha ) {
        $alpha = strtoupper( (string) $alpha );
        if ( function_exists( 'WC' ) && WC()->countries ) {
            $states = WC()->countries->get_states( 'IN' );
            if ( isset( $states[ $alpha ] ) ) {
                return (string) $states[ $alpha ];
            }
        }
        return $alpha;
    }
}

if ( ! function_exists( 'rgx_words_indian' ) ) {
    // Simple Indian numbering words
    function rgx_words_indian( $number ) {
        $number  = round( (float) $number, 2 );
        $integer = (int) floor( $number );
        $paise   = (int) round( ( $number - $integer ) * 100 );
        $words   = array(
            0=>'zero',1=>'one',2=>'two',3=>'three',4=>'four',5=>'five',6=>'six',7=>'seven',8=>'eight',9=>'nine',10=>'ten',11=>'eleven',
            12=>'twelve',13=>'thirteen',14=>'fourteen',15=>'fifteen',16=>'sixteen',17=>'seventeen',18=>'eighteen',19=>'nineteen',
            20=>'twenty',30=>'thirty',40=>'forty',50=>'fifty',60=>'sixty',70=>'seventy',80=>'eighty',90=>'ninety'
        );
        $to_words = function( $n ) use ( &$to_words, $words ) {
            if ( $n < 20 ) return $words[ $n ];
            if ( $n < 100 ) return $words[ floor($n/10)*10 ] . ( $n%10 ? ' ' . $words[$n%10] : '' );
            if ( $n < 1000 ) return $words[ floor($n/100) ] . ' hundred' . ( $n%100 ? ' and ' . $to_words( $n%100 ) : '' );
            if ( $n < 100000 ) return $to_words( floor($n/1000) ) . ' thousand' . ( $n%1000 ? ' ' . $to_words( $n%1000 ) : '' );
            if ( $n < 10000000 ) return $to_words( floor($n/100000) ) . ' lakh' . ( $n%100000 ? ' ' . $to_words( $n%100000 ) : '' );
            return $to_words( floor($n/10000000) ) . ' crore' . ( $n%10000000 ? ' ' . $to_words( $n%10000000 ) : '' );
        };
        $out = ucwords( $to_words( $integer ) ) . ' ' . ( $integer === 1 ? 'Rupee' : 'Rupees' );
        if ( $paise > 0 ) $out .= ' and ' . ucwords( $to_words( $paise ) ) . ' ' . ( $paise === 1 ? 'Paisa' : 'Paise' );
        return $out . ' Only';
    }
}

if ( ! function_exists( 'rgx_item_uqc' ) ) {
    function rgx_item_uqc( WC_Order_Item_Product $item ) {
        $product = $item->get_product();
        if ( $product ) {
            $uqc = $product->get_meta( '_uqc', true );
            if ( $uqc ) return strtoupper( (string) $uqc );
        }
        return 'PCS';
    }
}

if ( ! function_exists( 'rgx_item_hsn' ) ) {
    function rgx_item_hsn( WC_Order_Item_Product $item ) {
        $product = $item->get_product();
        if ( ! $product ) return 'N/A';

        // 1) Direct product meta
        $hsn = $product->get_meta( '_hsn', true );
        // 2) Based on product tax class slug (tune if you use tax classes to drive rates)
        if ( ! $hsn ) {
            $map_by_tax = array(
                'pearls-3'              => '710121',
                'precious-stones-0-25'  => '710391',
                'semi-precious-0-25'    => '710399',
                'coral-5'               => '960190',
                'coral-unworked-5'      => '050800',
                'rudraksha-0'           => 'N/A',
            );
            $tax_class = sanitize_title( $item->get_tax_class() );
            if ( $tax_class && isset( $map_by_tax[ $tax_class ] ) ) $hsn = $map_by_tax[ $tax_class ];
        }
        // 3) Category slugs fallback
        if ( ! $hsn ) {
            $terms = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'slugs' ) );
            if ( ! is_wp_error( $terms ) ) {
                $map_by_cat = array(
                    'pearls'                  => '710121',
                    'precious-gemstones'      => '710391',
                    'semi-precious-gemstones' => '710399',
                    'coral-worked'            => '960190',
                    'coral-unworked'          => '050800',
                    'coral'                   => '960190',
                    'rudraksha'               => 'N/A',
                );
                foreach ( (array) $terms as $slug ) {
                    if ( isset( $map_by_cat[ $slug ] ) ) { $hsn = $map_by_cat[ $slug ]; break; }
                }
            }
        }
        if ( ! $hsn || 'N/A' === $hsn ) return 'N/A';
        $hsn = preg_replace( '/\D/', '', (string) $hsn );
        if ( strlen( $hsn ) >= 4 ) return substr( $hsn, 0, 8 );
        return $hsn ?: 'N/A';
    }
}

if ( ! function_exists( 'rgx_item_tax_breakup' ) ) {
    function rgx_item_tax_breakup( WC_Order $order, WC_Order_Item_Product $item ) {
        $out       = array();
        $tax_items = $order->get_items( 'tax' );
        $labels_by_rate = array();
        foreach ( $tax_items as $t ) {
            $labels_by_rate[ $t->get_rate_id() ] = $t->get_label();
        }
        $item_taxes = $item->get_taxes(); // ['total' => [rate_id => amount]]
        $base       = (float) $item->get_subtotal(); // pre-discount base
        if ( $base <= 0 ) $base = (float) $item->get_total();
        if ( $base <= 0 || empty( $item_taxes['total'] ) ) return '';

        foreach ( $item_taxes['total'] as $rate_id => $tax_amount ) {
            $tax_amount = (float) $tax_amount;
            if ( $tax_amount <= 0 ) continue;
            $pct   = $base > 0 ? round( ( $tax_amount / $base ) * 100, 3 ) : 0.0;
            $label = $labels_by_rate[ $rate_id ] ?? __( 'Tax', 'ratna-gems' );
            $pct   = rtrim( rtrim( number_format( $pct, 3, '.', '' ), '0' ), '.' );
            $out[] = sprintf( '%s @ %s%%', esc_html( $label ), esc_html( $pct ) );
        }
        return implode( ' + ', $out );
    }
}

/* ---------------------------- DATA ---------------------------- */

$order_id         = $order->get_id();
$order_currency   = $order->get_currency() ?: 'INR';
$order_date       = wc_format_datetime( $order->get_date_created(), 'F j, Y' );

// Invoice number: RG/YYYY/MM/<order-number> (customize freely)
$invoice_number   = sprintf( 'RG/%s/%s', $order->get_date_created()->format( 'Y/m' ), $order->get_order_number() );

// Customer GSTIN (optional)
$buyer_gstin      = (string) $order->get_meta( '_billing_gstin' );

// PoS / Supply type
$pos_state_alpha  = $order->get_shipping_state() ?: $order->get_billing_state();
$pos_state_name   = rgx_state_name( $pos_state_alpha );
$pos_state_num    = rgx_state_numeric_code( $pos_state_alpha );
$is_inter_state   = ( strtoupper( $pos_state_alpha ) !== strtoupper( $issuer_state_alpha ) );

// Totals
$discount_total   = method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : ( method_exists( $order, 'get_total_discount' ) ? (float) $order->get_total_discount() : 0.0 );
$shipping_total   = (float) $order->get_shipping_total();
$tax_totals       = $order->get_tax_totals();
$grand_total      = (float) $order->get_total();
$grand_words      = rgx_words_indian( $grand_total );
$line_items       = $order->get_items();
$has_line_items   = ! empty( $line_items );

/* Optional rounding line (show only if a tiny drift exists) */
$components_sum = (float) $order->get_subtotal() - $discount_total + $shipping_total;
foreach ( $tax_totals as $t ) { $components_sum += (float) $t->amount; }
$rounding = $grand_total - $components_sum;
$show_rounding = abs( $rounding ) > 0.01 && abs( $rounding ) <= 0.05;

/* ---------------------------- HTML ---------------------------- */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php printf( esc_html__( 'Tax Invoice #%s', 'ratna-gems' ), esc_html( $order->get_order_number() ) ); ?></title>
<style>
:root{--primary:#0a5bd1;--primary-alt:#0b6be6;--ink:#14223a;--muted:#5b6b7f;--line:#e6ecf5;--paper:#ffffff;--soft:#f5f7fb;--accent:#d4af37}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:#edf1f8;color:var(--ink);font:12.4px/1.5 "Inter","Segoe UI",system-ui,-apple-system,Roboto,sans-serif}
.invoice{width:210mm;margin:0 auto;background:var(--paper);box-shadow:0 10px 32px rgba(12,48,114,.08);position:relative;display:flex;flex-direction:column;min-height:297mm;border-radius:12px;overflow:hidden}
.header{display:flex;justify-content:space-between;gap:18px;padding:16px 20px 12px;border-bottom:3px solid var(--primary);background:linear-gradient(140deg,#f9fbff 0%,#ffffff 68%)}
.logo{max-width:210px;max-height:72px;height:auto}
.title{min-width:220px;text-align:right}
.title h1{margin:.1rem 0 .2rem;font-size:22px;text-transform:uppercase;letter-spacing:.6px;color:var(--primary)}
.title .meta{margin-top:6px;color:var(--ink);display:grid;gap:4px;font-size:12.4px}
.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:12px 20px 0}
.card{flex:1 1 32%;min-width:245px;background:#fff;border:0;border-radius:10px;padding:10px 14px;box-shadow:0 4px 12px rgba(14,72,165,.05)}
.card h3{margin:0 0 6px;font-size:11.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.card p,.card div{margin:0 0 3px;font-size:12.4px}
.badge{display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border:1px solid rgba(12,72,178,.18);background:#eef4ff;color:#18407d;border-radius:999px;font-weight:600;font-size:11px;text-transform:uppercase}
.table-wrap{padding:10px 20px 6px}
table{width:100%;border-collapse:collapse;font-size:12.2px}
th,td{padding:5px 7px;border-bottom:1px solid var(--line);vertical-align:top}
thead th{background:linear-gradient(120deg,var(--primary) 0%,var(--primary-alt) 100%);color:#fff;text-transform:uppercase;letter-spacing:.35px;font-weight:600;padding:6px 7px}
tbody tr:nth-child(even) td{background:#f9fbff}
.text-right{text-align:right;white-space:nowrap}
.item-name{font-weight:600;color:#153a73;font-size:12.6px}
.item-sub{color:#5b6b7f;font-size:11.6px;margin-top:2px}
.hsn, .sku{font-size:11.4px;color:#466}
.empty-row td{border-bottom:1px solid transparent;padding:18px 12px}
.empty-copy{display:flex;flex-direction:column;gap:4px;align-items:center;justify-content:center;color:var(--muted);font-size:12.4px}
.empty-copy strong{color:var(--ink);letter-spacing:.3px}
.totals{display:flex;flex-wrap:wrap;gap:12px;padding:2px 20px 14px}
.col{flex:1 1 48%;min-width:270px}
.panel{background:#fff;border:0;border-radius:10px;padding:12px 14px;box-shadow:0 4px 12px rgba(14,72,165,.05)}
.panel h4{margin:0 0 6px;font-size:11.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.amount-words{font-size:12.8px;color:#102952}
.sum table{width:100%;font-size:12.2px}
.sum td{padding:6px 8px}
.sum tr+tr td{border-top:1px solid rgba(10,45,120,.12)}
.sum .grand td{border-bottom:0;background:linear-gradient(120deg,var(--primary) 0%,var(--primary-alt) 100%);color:#fff;font-weight:700;font-size:13px}
.sum tr.grand td{border-top:0}
.footer{margin-top:auto;background:#fff}
.footer-inner{padding:12px 20px 16px;display:grid;gap:8px;border-top:1px dotted rgba(91,107,127,.35);margin-top:8px}
.footer h4{margin:0;font-size:11.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.footer ul{margin:0;padding-left:0;columns:1;font-size:12px;color:#324765;list-style:none;display:flex;flex-direction:column;gap:4px}
.footer li{margin:0;break-inside:avoid}
.footer-note{font-size:12.2px;color:#0f2f60;font-weight:600}
.watermark{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;opacity:.05;pointer-events:none}
.watermark img{max-width:58%;max-height:58%;filter:grayscale(15%)}
.print-btn{position:fixed;top:12px;right:14px;z-index:9;padding:6px 12px;border:1px solid rgba(10,45,120,.14);background:#fff;border-radius:7px;box-shadow:0 4px 12px rgba(20,56,130,.14);cursor:pointer;font-weight:600;color:var(--primary)}

@media (max-width: 980px){
  html,body{background:#fff;font-size:12.8px}
  .invoice{width:100%;border-radius:0; margin: 0; box-shadow: none; min-height: 100vh;}
  .header,.grid,.table-wrap,.totals,.footer-inner{padding-left:16px;padding-right:16px}
  .title{text-align:left;margin-top:8px}
  .header{flex-direction:column;align-items:flex-start}
  .title .meta{text-align:left}
  .grid{grid-template-columns:1fr}
  .col{min-width:100%}
  .badge{font-size:11.5px}
  table{font-size:12.5px}
}

@media print {
  @page{
    size:A4;
    margin: 10mm; /* This margin is where browser headers/footers can appear. */
  }
  
  html, body {
    width: 100%;
    height: 100%; /* Change: Explicitly set height */
    margin: 0;
    padding: 0;
    background: #fff !important;
    color: #000 !important; /* Force black text */
    font-size: 12px; /* Set a consistent base font size */
    -webkit-print-color-adjust: exact; /* Chrome/Safari */
    color-adjust: exact; /* Standard */
  }
  
  .invoice {
    width: 100%;
    max-width: 100%;
    /* Change: This forces the invoice to fill the A4 page height, pushing the footer down */
    min-height: 100% !important; /* Use 100% of the body height */
    margin: 0;
    padding: 0;
    box-shadow: none !important;
    border-radius: 0 !important;
    border: none;
    page-break-inside: avoid;
    page-break-after: auto;
    /* Ensure flex context is maintained for footer positioning */
    display: flex !important;
    flex-direction: column !important;
    position: relative;
  }
  
  .print-btn {
    display: none !important;
  }
  
  .watermark {
    opacity: 0.04; /* Slightly lighter for print */
  }

  /* --- LAYOUT FIXES --- */
  /* Force desktop layouts for print, overriding mobile styles */
  
  .header {
    display: flex !important;
    flex-direction: row !important; /* Force side-by-side */
    justify-content: space-between !important;
    align-items: flex-start !important;
    padding: 0 0 6mm 0;
    border-bottom: 2mm solid var(--primary);
  }
  
  .title {
    text-align: right !important; /* Force original alignment */
    margin-top: 0 !important;
  }
  .title .meta {
    text-align: right !important; /* Force original alignment */
  }

  .grid {
    display: grid !important;
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important; /* Force 3-column grid */
    gap: 10px !important; /* Use a fixed gap */
    padding: 6mm 0 0 0;
  }
  
  .totals {
    display: flex !important;
    flex-direction: row !important; /* Force side-by-side */
    flex-wrap: nowrap !important; /* Prevent wrapping */
    gap: 10px !important;
    padding: 5mm 0 5mm 0;
  }
  
  .col {
    flex: 1 1 48% !important; /* Force 2-column flex */
    min-width: 0 !important; /* Reset mobile min-width */
    width: 48%; /* Be explicit */
  }

  /* --- PADDING & SPACING --- */
  .table-wrap {
    padding: 5mm 0 0 0;
  }

  th, td {
    padding: 2.5mm 2.5mm; /* Consistent table padding */
    font-size: 11.5px; /* Slightly smaller table text */
  }
  
  .item-sub {
    font-size: 10.5px;
  }

  .panel, .card {
    box-shadow: none !important;
    border: 1px solid var(--line); /* Add a light border for definition */
    page-break-inside: avoid;
    padding: 8px 10px; /* Adjust card padding */
  }
  
  .footer {
    margin-top: auto;
    background: #fff !important;
  }

  .footer-inner {
    padding: 6mm 0 0 0; /* No bottom padding, let page margin handle it */
    border-top: 0.3mm dotted rgba(91,107,127,.35);
    margin-top: 6mm;
  }
  
  .footer ul {
    columns: 1; /* Ensure single column for terms */
    gap: 3px;
    font-size: 11.5px;
  }

  /* Ensure sections don't break across pages */
  section, table, tr, .panel, .card, .header, .footer {
    page-break-inside: avoid;
  }
}
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()"><?php echo esc_html__( 'Print', 'ratna-gems' ); ?></button>

<div class="invoice">
  <div class="watermark"><img src="<?php echo esc_url( $watermark_logo_url ); ?>" alt=""></div>

  <header class="header">
    <div>
      <?php if ( $logo_url ) : ?>
        <img class="logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $business['name'] ); ?>">
      <?php endif; ?>
      <div style="margin-top:6px">
        <div><strong><?php esc_html_e( 'GSTIN:', 'ratna-gems' ); ?></strong> <?php echo esc_html( $business['gstin'] ); ?></div>
        <div><strong><?php esc_html_e( 'Reverse Charge:', 'ratna-gems' ); ?></strong> <span class="badge"><?php echo esc_html( $reverse_charge_flag ); ?></span></div>
      </div>
    </div>
    <div class="title">
      <h1><?php esc_html_e( 'Tax Invoice', 'ratna-gems' ); ?></h1>
      <div class="meta">
        <div><strong><?php esc_html_e( 'Date:', 'ratna-gems' ); ?></strong> <?php echo esc_html( $order_date ); ?></div>
        <div><strong><?php esc_html_e( 'Invoice #:', 'ratna-gems' ); ?></strong> <?php echo esc_html( $invoice_number ); ?></div>
        <div><strong><?php esc_html_e( 'Order ID:', 'ratna-gems' ); ?></strong> <?php echo esc_html( $order->get_order_number() ); ?></div>
      </div>
    </div>
  </header>

  <section class="grid">
    <div class="card">
      <h3><?php esc_html_e( 'Supplier', 'ratna-gems' ); ?></h3>
      <p><strong><?php echo esc_html( $business['name'] ); ?></strong></p>
      <p><?php echo nl2br( esc_html( $business['address'] ) ); ?></p>
      <p><strong><?php esc_html_e( 'Website:', 'ratna-gems' ); ?></strong> <?php echo esc_html( $business['website'] ); ?></p>
      <p><strong><?php esc_html_e( 'Email:', 'ratna-gems' ); ?></strong> <?php echo esc_html( $business['email'] ); ?></p>
      <p><strong><?php esc_html_e( 'Phone:', 'ratna-gems' ); ?></strong> <?php echo esc_html( $business['phone'] ); ?></p>
    </div>

    <div class="card">
      <h3><?php esc_html_e( 'Bill To', 'ratna-gems' ); ?></h3>
      <p><strong><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></strong></p>
      <div><strong><?php esc_html_e( 'Address:', 'ratna-gems' ); ?></strong><br><?php echo wp_kses_post( $order->get_formatted_billing_address() ?: esc_html__( 'N/A', 'ratna-gems' ) ); ?></div>
      <p><strong><?php esc_html_e( 'Email:', 'ratna-gems' ); ?></strong> <?php echo esc_html( $order->get_billing_email() ); ?></p>
      <p><strong><?php esc_html_e( 'Phone:', 'ratna-gems' ); ?></strong> <?php echo esc_html( $order->get_billing_phone() ); ?></p>
      <p><strong><?php esc_html_e( 'GSTIN:', 'ratna-gems' ); ?></strong> <?php echo esc_html( $buyer_gstin ?: __( 'N/A', 'ratna-gems' ) ); ?></p>
    </div>

    <div class="card">
      <h3><?php esc_html_e( 'Supply', 'ratna-gems' ); ?></h3>
      <p><strong><?php esc_html_e( 'Place of Supply:', 'ratna-gems' ); ?></strong>
        <?php echo esc_html( $pos_state_name ); ?>
        <?php echo $pos_state_num ? ' (' . esc_html( $pos_state_num ) . ')' : ''; ?>
      </p>
      <p><strong><?php esc_html_e( 'Type:', 'ratna-gems' ); ?></strong>
        <span class="badge"><?php echo $is_inter_state ? esc_html__( 'Inter‑State (IGST)', 'ratna-gems' ) : esc_html__( 'Intra‑State (CGST+SGST)', 'ratna-gems' ); ?></span>
      </p>
      <p><strong><?php esc_html_e( 'Payment:', 'ratna-gems' ); ?></strong> <?php echo esc_html( $order->get_payment_method_title() ); ?></p>
      <p><strong><?php esc_html_e( 'Txn ID:', 'ratna-gems' ); ?></strong> <?php echo esc_html( $order->get_transaction_id() ?: __( 'N/A', 'ratna-gems' ) ); ?></p>
    </div>
  </section>

  <section class="table-wrap">
    <table>
      <thead>
        <tr>
          <th style="width:36%">&nbsp;<?php esc_html_e( 'Product', 'ratna-gems' ); ?></th>
          <th class="text-right" style="width:12%"><?php esc_html_e( 'HSN', 'ratna-gems' ); ?></th>
          <th class="text-right" style="width:8%"><?php esc_html_e( 'Qty', 'ratna-gems' ); ?></th>
          <th class="text-right" style="width:10%"><?php esc_html_e( 'Unit (UQC)', 'ratna-gems' ); ?></th>
          <th class="text-right" style="width:10%"><?php esc_html_e( 'Price', 'ratna-gems' ); ?></th>
          <th class="text-right" style="width:12%"><?php esc_html_e( 'Tax', 'ratna-gems' ); ?></th>
          <th class="text-right" style="width:12%"><?php esc_html_e( 'Line Total', 'ratna-gems' ); ?></th>
        </tr>
      </thead>
      <tbody>
      <?php if ( $has_line_items ) : ?>
      <?php foreach ( $line_items as $item_id => $item ) :
          if ( ! $item instanceof WC_Order_Item_Product ) continue;
          $product          = $item->get_product();
          $hsn              = rgx_item_hsn( $item );
          $qty              = (float) $item->get_quantity();
          $price_excl_tax   = $qty ? ( (float) $item->get_subtotal() / $qty ) : 0.0; // base price pre-discount
          $item_tax_total   = (float) $item->get_total_tax();
          $line_total_incl  = (float) $item->get_total() + $item_tax_total;
          $uqc              = rgx_item_uqc( $item );
          $tax_breakup      = rgx_item_tax_breakup( $order, $item );
      ?>
        <tr>
          <td>
            <div class="item-name"><?php echo esc_html( $item->get_name() ); ?></div>
            <?php
            $meta = $item->get_formatted_meta_data( '_', true );
            if ( ! empty( $meta ) ) {
                echo '<div class="item-sub">';
                foreach ( $meta as $m ) {
                    if ( in_array( $m->key, array( '_reduced_stock','_qty' ), true ) ) continue;
                    echo wp_kses_post( $m->display_key . ': ' . wpautop( $m->display_value ) );
                }
                echo '</div>';
            }
            if ( $product && $product->get_sku() ) {
                echo '<div class="sku">'. esc_html__( 'SKU:', 'ratna-gems' ) .' '. esc_html( $product->get_sku() ) .'</div>';
            }
            ?>
          </td>
          <td class="text-right"><span class="hsn"><?php echo esc_html( $hsn ); ?></span></td>
          <td class="text-right"><?php echo esc_html( wc_format_decimal( $qty, 2 ) ); ?></td>
          <td class="text-right"><?php echo esc_html( $uqc ); ?></td>
          <td class="text-right"><?php echo rgx_format_price( $price_excl_tax, $order_currency ); ?></td>
          <td class="text-right">
            <?php echo $tax_breakup ? esc_html( $tax_breakup ) : esc_html__( 'Exempt / 0%', 'ratna-gems' ); ?>
            <div class="item-sub"><?php echo rgx_format_price( $item_tax_total, $order_currency ); ?></div>
          </td>
          <td class="text-right"><?php echo rgx_format_price( $line_total_incl, $order_currency ); ?></td>
        </tr>
      <?php endforeach; ?>
      <?php else : ?>
        <tr class="empty-row">
          <td colspan="7">
            <div class="empty-copy">
              <strong><?php esc_html_e( 'No line items found for this order.', 'ratna-gems' ); ?></strong>
              <span><?php esc_html_e( 'This invoice remains valid for advance payments, adjustments, or service-only orders.', 'ratna-gems' ); ?></span>
            </div>
          </td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="totals">
    <div class="col">
      <div class="panel">
        <h4><?php esc_html_e( 'Amount in Words', 'ratna-gems' ); ?></h4>
        <div class="amount-words"><?php echo esc_html( $grand_words ); ?></div>
      </div>
    </div>

    <div class="col sum">
      <div class="panel">
        <table>
          <tbody>
            <tr>
              <td><?php esc_html_e( 'Subtotal', 'ratna-gems' ); ?></td>
              <td class="text-right"><?php echo rgx_format_price( $order->get_subtotal(), $order_currency ); ?></td>
            </tr>
            <?php if ( $discount_total > 0 ) : ?>
            <tr>
              <td><?php esc_html_e( 'Discount', 'ratna-gems' ); ?></td>
              <td class="text-right">-<?php echo rgx_format_price( $discount_total, $order_currency ); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ( $shipping_total > 0 ) : ?>
            <tr>
              <td><?php esc_html_e( 'Shipping', 'ratna-gems' ); ?></td>
              <td class="text-right"><?php echo rgx_format_price( $shipping_total, $order_currency ); ?></td>
            </tr>
            <?php endif; ?>
            <?php foreach ( $tax_totals as $tax ) : ?>
            <tr>
              <td><?php echo esc_html( $tax->label ); ?></td>
              <td class="text-right"><?php echo rgx_format_price( $tax->amount, $order_currency ); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ( $show_rounding ) : ?>
            <tr>
              <td><?php esc_html_e( 'Rounding Adjustment', 'ratna-gems' ); ?></td>
              <td class="text-right"><?php echo rgx_format_price( $rounding, $order_currency ); ?></td>
            </tr>
            <?php endif; ?>
            <tr class="grand">
              <td><strong><?php esc_html_e( 'Grand Total', 'ratna-gems' ); ?></strong></td>
              <td class="text-right"><strong><?php echo rgx_format_price( $grand_total, $order_currency ); ?></strong></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <footer class="footer">
    <div class="footer-inner">
      <h4><?php esc_html_e( 'Terms & Conditions', 'ratna-gems' ); ?></h4>
      <ul>
        <?php foreach ( $terms_conditions as $t ) : ?>
          <li><?php echo esc_html( $t ); ?></li>
        <?php endforeach; ?>
      </ul>
      <div class="footer-note"><?php echo esc_html( $footer_note ); ?></div>
    </div>
  </footer>
</div>
</body>
</html>