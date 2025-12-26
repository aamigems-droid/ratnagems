<?php
/**
 * Subscriber functionality for Sarfaraz Gems.
 *
 * Handles AJAX form submission, creates a 'subscriber' custom post type,
 * and provides an admin interface to view and export subscribers.
 *
 * @package Sarfaraz_Gems_Child
 */

if (! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers the 'subscriber' custom post type.
 */
function create_sg_subscriber_post_type() {
	$labels = array(
		'name'               => _x( 'Subscribers', 'post type general name', 'sarfaraz-gems-child' ),
		'singular_name'      => _x( 'Subscriber', 'post type singular name', 'sarfaraz-gems-child' ),
		'menu_name'          => _x( 'Subscribers', 'admin menu', 'sarfaraz-gems-child' ),
		'all_items'          => __( 'All Subscribers', 'sarfaraz-gems-child' ),
		'search_items'       => __( 'Search Subscribers', 'sarfaraz-gems-child' ),
		'not_found'          => __( 'No subscribers found.', 'sarfaraz-gems-child' ),
	);
	$args   = array(
		'labels'             => $labels,
		'public'             => false,
		'publicly_queryable' => false,
		'show_ui'            => true,
		'show_in_menu'       => true,
		'menu_position'      => 20,
		'menu_icon'          => 'dashicons-groups',
		'supports'           => array( 'title' ),
		'capability_type'    => 'post',
		'map_meta_cap'       => true,
	);
	register_post_type( 'subscriber', $args );
}
add_action( 'init', 'create_sg_subscriber_post_type' );

/**
 * Handles the subscriber form submission via AJAX.
 */
function handle_ajax_sg_subscriber_submission() {
	// 1. Verify the nonce for security.
	if (! check_ajax_referer( 'add_new_subscriber_nonce', 'security', false ) ) {
		wp_send_json_error( 'Invalid security token.', 403 );
		wp_die();
	}

	// 2. Sanitize and validate email.
	$email = isset( $_POST['subscriber_email'] )? sanitize_email( wp_unslash( $_POST['subscriber_email'] ) ) : '';
	if (! is_email( $email ) ) {
		wp_send_json_error( 'Please provide a valid email address.', 400 );
		wp_die();
	}

	// 3. Sanitize name.
	$name = isset( $_POST['subscriber_name'] )? sanitize_text_field( wp_unslash( $_POST['subscriber_name'] ) ) : '';

	// 4. Check if the email is already subscribed to prevent duplicates.
	$existing_subscriber = get_page_by_title( $email, OBJECT, 'subscriber' );
	if ( null!== $existing_subscriber ) {
		wp_send_json_error( 'This email is already subscribed.', 409 );
		wp_die();
	}

	// 5. Create a new subscriber post.
	$subscriber_data = array(
		'post_title'  => $email,
		'post_status' => 'publish',
		'post_type'   => 'subscriber',
	);
	$post_id         = wp_insert_post( $subscriber_data );

	if ( $post_id &&! is_wp_error( $post_id ) ) {
		// Add the name as post meta if provided.
		if (! empty( $name ) ) {
			add_post_meta( $post_id, 'subscriber_name', $name, true );
		}

		// Send a notification email to the site admin.
		$admin_email = get_option( 'admin_email' );
		$subject     = 'New Subscriber from SarfarazGems.com';
		$message     = "You have a new subscriber:\n\nName: ". $name. "\nEmail: ". $email. "\n";
		$headers     = array( 'Content-Type: text/plain; charset=UTF-8' );
		wp_mail( $admin_email, $subject, $message, $headers );

		wp_send_json_success( 'Thank you for subscribing!' );
	} else {
		wp_send_json_error( 'Could not save subscription. Please try again.', 500 );
	}

	wp_die();
}
add_action( 'wp_ajax_add_new_subscriber', 'handle_ajax_sg_subscriber_submission' );
add_action( 'wp_ajax_nopriv_add_new_subscriber', 'handle_ajax_sg_subscriber_submission' );


/**
 * Adds custom columns to the subscriber list view in the admin area.
 *
 * @param array $columns The existing columns.
 * @return array The modified columns.
 */
function set_custom_edit_sg_subscriber_columns( $columns ) {
	unset( $columns['title'], $columns['date'] );
	$columns['subscriber_email'] = __( 'Email', 'sarfaraz-gems-child' );
	$columns['subscriber_name']  = __( 'Name', 'sarfaraz-gems-child' );
	$columns['date']             = __( 'Date Subscribed', 'sarfaraz-gems-child' );
	return $columns;
}
add_filter( 'manage_edit-subscriber_columns', 'set_custom_edit_sg_subscriber_columns' );

/**
 * Populates the custom columns with data.
 *
 * @param string $column The column name.
 * @param int    $post_id The post ID.
 */
function custom_sg_subscriber_column( $column, $post_id ) {
	switch ( $column ) {
		case 'subscriber_email':
			echo esc_html( get_the_title( $post_id ) );
			break;
		case 'subscriber_name':
			echo esc_html( get_post_meta( $post_id, 'subscriber_name', true ) );
			break;
	}
}
add_action( 'manage_subscriber_posts_custom_column', 'custom_sg_subscriber_column', 10, 2 );

/**
 * Adds a "Download as CSV" button to the admin screen.
 *
 * @param string $which The location of the button.
 */
function add_sg_subscriber_export_button( $which ) {
	if ( 'subscriber' === get_post_type() && 'top' === $which ) {
		$export_url = add_query_arg( 'export_subscribers', 'true' );
		echo '<div class="alignleft actions"><a href="'. esc_url( $export_url ). '" class="button button-primary">Download as CSV</a></div>';
	}
}
add_action( 'manage_posts_extra_tablenav', 'add_sg_subscriber_export_button' );

/**
 * Handles the CSV export logic.
 */
function export_sg_subscribers_to_csv() {
	if (! isset( $_GET['export_subscribers'] ) ||! current_user_can( 'manage_options' ) ) {
		return;
	}

	$filename = 'sarfarazgems-subscribers-'. gmdate( 'Y-m-d' ). '.csv';
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="'. $filename. '"' );

	$args = array(
		'post_type'      => 'subscriber',
		'posts_per_page' => -1,
		'orderby'        => 'post_date',
		'order'          => 'DESC',
	);
	$subscribers = get_posts( $args );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	$file = fopen( 'php://output', 'w' );
	fputcsv( $file, array( 'Email', 'Name', 'Date Subscribed' ) );

	foreach ( $subscribers as $subscriber ) {
		fputcsv(
			$file,
			array(
				$subscriber->post_title,
				get_post_meta( $subscriber->ID, 'subscriber_name', true ),
				get_the_date( 'Y-m-d H:i:s', $subscriber->ID ),
			)
		);
	}

	fclose( $file );
	exit();
}
add_action( 'admin_init', 'export_sg_subscribers_to_csv' );