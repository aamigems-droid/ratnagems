<?php
/**
 * Customer Review Request Email (Plain Text)
 * 
 * @package RatnaGems
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$customer_name = $order->get_billing_first_name();
$order_number = $order->get_order_number();

$products = array();
foreach ( $order->get_items() as $item ) {
    $products[] = $item->get_name();
}
$product_list = implode( ', ', $products );

echo "RATNA GEMS\n";
echo "Certified Gemstones You Can Trust\n";
echo "==================================\n\n";

echo "Hello " . esc_html( $customer_name ) . "!\n\n";

echo "Your order has been delivered! We hope you are happy with your gemstone.\n\n";

echo "ORDER DETAILS\n";
echo "-------------\n";
echo "Order Number: #" . esc_html( $order_number ) . "\n";
echo "Product: " . esc_html( $product_list ) . "\n\n";

echo "PLEASE SHARE YOUR FEEDBACK\n";
echo "==========================\n\n";

echo "Your review helps other customers choose the right gemstone.\n";
echo "It takes only 30 seconds!\n\n";

echo "Tell us about:\n";
echo "- Product quality - Is the gemstone good?\n";
echo "- Photos match - Same as shown on website?\n";
echo "- Packaging - Was it packed safely?\n";
echo "- Delivery - Was it on time?\n";
echo "- Value for money - Fair price?\n\n";

echo "WRITE YOUR REVIEW HERE:\n";
echo esc_html( $review_link ) . "\n\n";

echo "-----------------------------------\n\n";

echo "NEED HELP?\n";
echo "Have questions? We are here to help!\n\n";

echo "Phone: 7067939337\n";
echo "WhatsApp: wa.me/917067939337\n";
echo "Website: www.ratnagems.com\n\n";

echo "-----------------------------------\n";
echo "Ratna Gems\n";
echo "Certified Gemstones & Rudraksha\n";
echo "Dhoptala Colony, Rajura, Chandrapur\n";
echo "Maharashtra - 442905\n";
