<?php
/**
 * Adds a custom sticky footer bar for mobile devices.
 * Version 2: Home, Shop, Cart, Account
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Hook the function to the WordPress footer action.
add_action( 'wp_footer', 'sg_add_sticky_footer_bar' );

function sg_add_sticky_footer_bar() {
	// Get URLs for key pages.
	$home_url = home_url( '/' );
	$shop_url = wc_get_page_permalink( 'shop' );
	$cart_url = wc_get_cart_url(); // Correct function for the Cart URL
	$account_url = wc_get_page_permalink( 'myaccount' );
	?>
	<div class="sg-sticky-footer-bar">
		<nav class="sg-sticky-nav">
			<a href="<?php echo esc_url( $home_url ); ?>" class="sg-nav-item">
				<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
				<span>Home</span>
			</a>
			<a href="<?php echo esc_url( $shop_url ); ?>" class="sg-nav-item">
				<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
				<span>Shop</span>
			</a>
			<a href="<?php echo esc_url( $cart_url ); ?>" class="sg-nav-item">
				<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-2z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
				<span>Cart</span>
			</a>
			<a href="<?php echo esc_url( $account_url ); ?>" class="sg-nav-item">
				<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
				<span>Account</span>
			</a>
		</nav>
	</div>
	<?php
}