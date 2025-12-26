<?php
/**
 * Customer Review Request Email (HTML)
 * Professional, simple English, mobile-friendly
 * 
 * @package RatnaGems
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$customer_name = $order->get_billing_first_name();
$order_number = $order->get_order_number();
$order_date = wc_format_datetime( $order->get_date_created() );

// Get products
$products = array();
foreach ( $order->get_items() as $item ) {
    $product = $item->get_product();
    $products[] = array(
        'name'  => $item->get_name(),
        'image' => $product ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '',
    );
}
$product_names = array_column( $products, 'name' );
$product_display = implode( ', ', array_slice( $product_names, 0, 2 ) );
if ( count( $product_names ) > 2 ) {
    $product_display .= ' +' . ( count( $product_names ) - 2 ) . ' more';
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Share Your Feedback</title>
</head>
<body style="margin:0; padding:0; font-family:Arial,Helvetica,sans-serif; background-color:#f4f4f4;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f4;">
<tr>
<td align="center" style="padding:20px 10px;">

<table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.08);">

<!-- Header -->
<tr>
<td style="background:linear-gradient(135deg,#8B4513 0%,#CD853F 100%); padding:30px 20px; text-align:center;">
<h1 style="margin:0; color:#ffffff; font-size:26px; font-weight:bold;">Ratna Gems</h1>
<p style="margin:8px 0 0; color:#ffffff; opacity:0.9; font-size:13px;">Certified Gemstones You Can Trust</p>
</td>
</tr>

<!-- Greeting -->
<tr>
<td style="padding:30px 30px 20px;">
<h2 style="margin:0 0 10px; color:#333333; font-size:22px; font-weight:normal;">Hello <?php echo esc_html( $customer_name ); ?>! 🙏</h2>
<p style="margin:0; color:#555555; font-size:16px; line-height:1.6;">
Your order has been delivered! We hope you are happy with your gemstone.
</p>
</td>
</tr>

<!-- Order Box -->
<tr>
<td style="padding:0 30px 25px;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FFFBF0; border-radius:8px; border:1px solid #F5E6C8;">
<tr>
<td style="padding:20px;">

<?php if ( ! empty( $products[0]['image'] ) ) : ?>
<table width="100%" cellpadding="0" cellspacing="0" border="0">
<tr>
<td align="center" style="padding-bottom:15px;">
<?php foreach ( array_slice( $products, 0, 2 ) as $p ) : ?>
<?php if ( $p['image'] ) : ?>
<img src="<?php echo esc_url( $p['image'] ); ?>" alt="Product" width="65" height="65" style="border-radius:8px; border:2px solid #DAA520; margin:0 5px; object-fit:cover;">
<?php endif; ?>
<?php endforeach; ?>
</td>
</tr>
</table>
<?php endif; ?>

<table width="100%" cellpadding="0" cellspacing="0" border="0">
<tr>
<td width="50%" style="padding:5px 10px 5px 0;">
<span style="color:#888888; font-size:11px; text-transform:uppercase;">Order</span><br>
<span style="color:#333333; font-size:15px; font-weight:bold;">#<?php echo esc_html( $order_number ); ?></span>
</td>
<td width="50%" style="padding:5px 0 5px 10px;">
<span style="color:#888888; font-size:11px; text-transform:uppercase;">Date</span><br>
<span style="color:#333333; font-size:14px;"><?php echo esc_html( $order_date ); ?></span>
</td>
</tr>
<tr>
<td colspan="2" style="padding:8px 0 0;">
<span style="color:#888888; font-size:11px; text-transform:uppercase;">Product</span><br>
<span style="color:#333333; font-size:14px;"><?php echo esc_html( $product_display ); ?></span>
</td>
</tr>
</table>

</td>
</tr>
</table>
</td>
</tr>

<!-- Stars -->
<tr>
<td align="center" style="padding:5px 30px 15px;">
<span style="font-size:32px;">⭐⭐⭐⭐⭐</span>
</td>
</tr>

<!-- Request -->
<tr>
<td style="padding:10px 30px 20px; text-align:center;">
<h3 style="margin:0 0 10px; color:#333333; font-size:20px; font-weight:600;">Please Share Your Feedback</h3>
<p style="margin:0; color:#555555; font-size:15px; line-height:1.6;">
Your review helps other customers choose the right gemstone.<br>
It takes only 30 seconds!
</p>
</td>
</tr>

<!-- What to Review -->
<tr>
<td style="padding:0 30px 25px;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8f9fa; border-radius:8px;">
<tr>
<td style="padding:18px 20px;">
<p style="margin:0 0 10px; color:#333333; font-size:14px; font-weight:bold;">Tell us about:</p>
<table width="100%" cellpadding="0" cellspacing="0" border="0">
<tr><td style="padding:4px 0; color:#555555; font-size:14px;">✓ Product quality - Is the gemstone good?</td></tr>
<tr><td style="padding:4px 0; color:#555555; font-size:14px;">✓ Photos match - Same as shown on website?</td></tr>
<tr><td style="padding:4px 0; color:#555555; font-size:14px;">✓ Packaging - Was it packed safely?</td></tr>
<tr><td style="padding:4px 0; color:#555555; font-size:14px;">✓ Delivery - Was it on time?</td></tr>
<tr><td style="padding:4px 0; color:#555555; font-size:14px;">✓ Value for money - Fair price?</td></tr>
</table>
</td>
</tr>
</table>
</td>
</tr>

<!-- CTA Button -->
<tr>
<td align="center" style="padding:5px 30px 30px;">
<!--[if mso]>
<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="<?php echo esc_url( $review_link ); ?>" style="height:52px;v-text-anchor:middle;width:260px;" arcsize="50%" stroke="f" fillcolor="#1a73e8">
<w:anchorlock/>
<center>
<![endif]-->
<a href="<?php echo esc_url( $review_link ); ?>" style="display:inline-block; background:#1a73e8; color:#ffffff; text-decoration:none; padding:15px 40px; border-radius:30px; font-size:17px; font-weight:bold;">
⭐ Write Your Review
</a>
<!--[if mso]>
</center>
</v:roundrect>
<![endif]-->
<p style="margin:12px 0 0; color:#888888; font-size:13px;">Takes only 30 seconds!</p>
</td>
</tr>

<!-- Alternative Link -->
<tr>
<td style="padding:0 30px 20px; text-align:center;">
<p style="margin:0; color:#999999; font-size:12px;">
If button not working, copy this link:<br>
<a href="<?php echo esc_url( $review_link ); ?>" style="color:#1a73e8; word-break:break-all; font-size:11px;"><?php echo esc_html( $review_link ); ?></a>
</p>
</td>
</tr>

<!-- Divider -->
<tr>
<td style="padding:0 30px;">
<table width="100%" cellpadding="0" cellspacing="0" border="0">
<tr><td style="border-top:1px dashed #dddddd; height:1px;"></td></tr>
</table>
</td>
</tr>

<!-- Need Help -->
<tr>
<td style="padding:25px 30px; text-align:center;">
<p style="margin:0 0 5px; color:#333333; font-size:15px; font-weight:bold;">Need Help?</p>
<p style="margin:0 0 12px; color:#666666; font-size:14px;">
Have questions about your order? We are here to help!
</p>
<p style="margin:0;">
<a href="tel:+917067939337" style="color:#1a73e8; text-decoration:none; font-size:16px; font-weight:bold;">📞 7067939337</a>
<span style="color:#ccc; margin:0 10px;">|</span>
<a href="https://wa.me/917067939337" style="color:#25D366; text-decoration:none; font-size:14px; font-weight:bold;">💬 WhatsApp</a>
</p>
</td>
</tr>

<!-- Footer -->
<tr>
<td style="background:#f8f9fa; padding:20px 30px; text-align:center; border-top:1px solid #eeeeee;">
<p style="margin:0 0 5px; color:#333333; font-size:14px; font-weight:bold;">Ratna Gems</p>
<p style="margin:0 0 5px; color:#888888; font-size:12px;">Certified Gemstones & Rudraksha</p>
<p style="margin:0 0 10px; color:#999999; font-size:11px;">
Dhoptala Colony, Rajura, Chandrapur<br>Maharashtra - 442905
</p>
<p style="margin:0;">
<a href="https://ratnagems.com" style="color:#1a73e8; text-decoration:none; font-size:13px;">www.ratnagems.com</a>
</p>
</td>
</tr>

</table>

</td>
</tr>
</table>

</body>
</html>
<?php
