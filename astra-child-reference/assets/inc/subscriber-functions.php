<?php
/**
 * Subscriber utilities (CPT, AJAX, REST) for Ratna Gems.
 *
 * @package Ratna Gems
 * @version 3.4.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -----------------------------------------------------------------------------
// Subscriber custom post type.
// -----------------------------------------------------------------------------

add_action( 'init', 'ratna_gems_register_subscriber_cpt' );
/**
 * Register the Subscriber custom post type.
 *
 * @since 1.0.0
 * @return void
 */
function ratna_gems_register_subscriber_cpt(): void {
    $labels = array(
        'name'               => _x( 'Subscribers', 'post type general name', 'ratna-gems' ),
        'singular_name'      => _x( 'Subscriber', 'post type singular name', 'ratna-gems' ),
        'menu_name'          => _x( 'Subscribers', 'admin menu', 'ratna-gems' ),
        'all_items'          => __( 'All Subscribers', 'ratna-gems' ),
        'add_new_item'       => __( 'Add New Subscriber', 'ratna-gems' ),
        'edit_item'          => __( 'Edit Subscriber', 'ratna-gems' ),
        'view_item'          => __( 'View Subscriber', 'ratna-gems' ),
        'not_found'          => __( 'No subscribers found.', 'ratna-gems' ),
        'not_found_in_trash' => __( 'No subscribers found in Trash.', 'ratna-gems' ),
    );

    register_post_type(
        'subscriber',
        array(
            'labels'             => $labels,
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'menu_icon'          => 'dashicons-groups',
            'supports'           => array( 'title' ),
        )
    );
}

// -----------------------------------------------------------------------------
// AJAX subscriber handler.
// -----------------------------------------------------------------------------

add_action( 'wp_ajax_add_new_subscriber', 'ratna_gems_handle_subscriber_ajax' );
add_action( 'wp_ajax_nopriv_add_new_subscriber', 'ratna_gems_handle_subscriber_ajax' );
/**
 * Handle AJAX subscription requests.
 *
 * @since 1.0.0
 * @return void
 */
function ratna_gems_handle_subscriber_ajax(): void {
    check_ajax_referer( 'add_new_subscriber_nonce', 'security' );

    $email = isset( $_POST['subscriber_email'] ) ? sanitize_email( wp_unslash( $_POST['subscriber_email'] ) ) : '';
    $name  = isset( $_POST['subscriber_name'] ) ? sanitize_text_field( wp_unslash( $_POST['subscriber_name'] ) ) : '';

    if ( ! is_email( $email ) ) {
        wp_send_json_error( esc_html__( 'Please provide a valid email address.', 'ratna-gems' ) );
    }

    // FIX: Replace deprecated get_page_by_title() with WP_Query
    // get_page_by_title() was deprecated in WordPress 6.2
    $existing_query = new WP_Query(
        array(
            'post_type'              => 'subscriber',
            'title'                  => $email,
            'posts_per_page'         => 1,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'fields'                 => 'ids',
        )
    );

    if ( $existing_query->have_posts() ) {
        wp_send_json_error( esc_html__( 'This email is already subscribed.', 'ratna-gems' ) );
    }

    $post_id = wp_insert_post(
        array(
            'post_title'  => $email,
            'post_status' => 'publish',
            'post_type'   => 'subscriber',
        )
    );

    if ( ! $post_id || is_wp_error( $post_id ) ) {
        wp_send_json_error( esc_html__( 'Could not save subscription. Please try again.', 'ratna-gems' ) );
    }

    if ( '' !== $name ) {
        add_post_meta( $post_id, 'subscriber_name', $name, true );
    }

    $admin_email = get_option( 'admin_email' );
    $subject     = sprintf(
        /* translators: %s: Site name */
        __( 'New Subscriber from %s', 'ratna-gems' ),
        wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
    );
    $message = sprintf(
        /* translators: 1: Subscriber name, 2: Subscriber email */
        "Name: %1\$s\nEmail: %2\$s\n",
        $name ? $name : __( 'Unknown', 'ratna-gems' ),
        $email
    );

    $headers = array( 'Content-Type: text/plain; charset=UTF-8' );
    if ( $email ) {
        $headers[] = sprintf( 'Reply-To: %s', $name ? sprintf( '%s <%s>', $name, $email ) : $email );
    }

    wp_mail( $admin_email, $subject, $message, $headers );

    wp_send_json_success( esc_html__( 'Thank you for subscribing!', 'ratna-gems' ) );
}

// -----------------------------------------------------------------------------
// Admin column tweaks.
// -----------------------------------------------------------------------------

add_filter( 'manage_edit-subscriber_columns', 'ratna_gems_subscriber_columns' );
/**
 * Customize subscriber list columns.
 *
 * @since 1.0.0
 * @param array $columns Existing columns.
 * @return array Modified columns.
 */
function ratna_gems_subscriber_columns( array $columns ): array {
    unset( $columns['title'], $columns['date'] );
    $columns['subscriber_email'] = __( 'Email', 'ratna-gems' );
    $columns['subscriber_name']  = __( 'Name', 'ratna-gems' );
    $columns['date']             = __( 'Date Subscribed', 'ratna-gems' );
    return $columns;
}

add_action( 'manage_subscriber_posts_custom_column', 'ratna_gems_render_subscriber_column', 10, 2 );
/**
 * Render custom subscriber column content.
 *
 * @since 1.0.0
 * @param string $column  Column name.
 * @param int    $post_id Post ID.
 * @return void
 */
function ratna_gems_render_subscriber_column( string $column, int $post_id ): void {
    switch ( $column ) {
        case 'subscriber_email':
            echo esc_html( get_the_title( $post_id ) );
            break;
        case 'subscriber_name':
            echo esc_html( get_post_meta( $post_id, 'subscriber_name', true ) );
            break;
    }
}

// -----------------------------------------------------------------------------
// Export button and handler.
// -----------------------------------------------------------------------------

add_action( 'manage_posts_extra_tablenav', 'ratna_gems_subscriber_export_button', 10, 1 );
/**
 * Add export button to subscriber list.
 *
 * @since 1.0.0
 * @param string $which Position (top or bottom).
 * @return void
 */
function ratna_gems_subscriber_export_button( string $which ): void {
    if ( 'top' !== $which || 'subscriber' !== get_post_type() ) {
        return;
    }
    $export_url = wp_nonce_url( add_query_arg( 'export_subscribers', 'true' ), 'ratna-gems-export-subscribers' );
    echo '<div class="alignleft actions"><a class="button button-primary" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Download as CSV', 'ratna-gems' ) . '</a></div>';
}

add_action( 'admin_init', 'ratna_gems_export_subscribers_to_csv' );
/**
 * Handle CSV export of subscribers.
 *
 * @since 1.0.0
 * @return void
 */
function ratna_gems_export_subscribers_to_csv(): void {
    if ( ! isset( $_GET['export_subscribers'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Sorry, you are not allowed to export subscribers.', 'ratna-gems' ) );
    }

    check_admin_referer( 'ratna-gems-export-subscribers' );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'ratnagems-subscribers-' . gmdate( 'Y-m-d' ) . '.csv' ) . '"' );

    $out = fopen( 'php://output', 'w' );
    if ( ! $out ) {
        exit;
    }

    fputcsv( $out, array( 'Email', 'Name', 'Date Subscribed' ) );

    $subscribers = get_posts(
        array(
            'post_type'      => 'subscriber',
            'posts_per_page' => -1,
            'orderby'        => 'post_date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        )
    );

    foreach ( $subscribers as $subscriber_id ) {
        fputcsv(
            $out,
            array(
                get_the_title( $subscriber_id ),
                get_post_meta( $subscriber_id, 'subscriber_name', true ),
                get_the_date( 'Y-m-d H:i:s', $subscriber_id ),
            )
        );
    }

    fclose( $out );
    exit;
}

// -----------------------------------------------------------------------------
// Contact message CPT & REST endpoint.
// -----------------------------------------------------------------------------

add_action( 'init', 'ratna_gems_register_contact_message_cpt' );
/**
 * Register the Contact Message custom post type.
 *
 * @since 1.0.0
 * @return void
 */
function ratna_gems_register_contact_message_cpt(): void {
    $labels = array(
        'name'          => _x( 'Messages', 'post type general name', 'ratna-gems' ),
        'singular_name' => _x( 'Message', 'post type singular name', 'ratna-gems' ),
        'menu_name'     => _x( 'Messages', 'admin menu', 'ratna-gems' ),
        'all_items'     => __( 'All Messages', 'ratna-gems' ),
        'not_found'     => __( 'No messages found.', 'ratna-gems' ),
    );

    register_post_type(
        'contact_message',
        array(
            'labels'             => $labels,
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'menu_icon'          => 'dashicons-email-alt',
            'supports'           => array( 'title', 'editor' ),
        )
    );
}

add_action( 'rest_api_init', 'ratna_gems_register_contact_endpoint' );
/**
 * Register REST API endpoint for contact form.
 *
 * @since 1.0.0
 * @return void
 */
function ratna_gems_register_contact_endpoint(): void {
    register_rest_route(
        'rg/v1',
        '/contact',
        array(
            'callback'            => 'ratna_gems_handle_contact_submission',
            'permission_callback' => '__return_true',
            'methods'             => 'POST',
            'args'                => array(
                'name'    => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'email'   => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_email',
                ),
                'subject' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'message' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ),
                'rg_hp'   => array(
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        )
    );
}

/**
 * Handle contact form submission via REST API.
 *
 * @since 1.0.0
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response Response object.
 */
function ratna_gems_handle_contact_submission( WP_REST_Request $request ): WP_REST_Response {
    $nonce = $request->get_header( 'X-RG-Nonce' );
    if ( ! $nonce || ! wp_verify_nonce( $nonce, 'rg_public_contact' ) ) {
        return new WP_REST_Response(
            array(
                'success' => false,
                'message' => __( 'Invalid security token.', 'ratna-gems' ),
            ),
            403
        );
    }

    // Honeypot check - if filled, it's a bot
    if ( $request->get_param( 'rg_hp' ) ) {
        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    $name    = $request->get_param( 'name' );
    $email   = $request->get_param( 'email' );
    $subject = $request->get_param( 'subject' );
    $message = $request->get_param( 'message' );

    if ( ! $name || ! $message || ! is_email( $email ) ) {
        return new WP_REST_Response(
            array(
                'success' => false,
                'message' => __( 'Please fill out all required fields.', 'ratna-gems' ),
            ),
            400
        );
    }

    if ( post_type_exists( 'contact_message' ) ) {
        wp_insert_post(
            array(
                'post_title'   => $subject,
                'post_content' => sprintf( "From: %s <%s>\n\n%s", $name, $email, $message ),
                'post_status'  => 'publish',
                'post_type'    => 'contact_message',
            )
        );
    }

    $admin_email = get_option( 'admin_email' );
    $headers     = array(
        'Content-Type: text/plain; charset=UTF-8',
        sprintf( 'Reply-To: %s <%s>', $name, $email ),
    );

    $mail_sent = wp_mail(
        $admin_email,
        sprintf(
            /* translators: %s: Message subject */
            __( 'New Contact Form Message: %s', 'ratna-gems' ),
            $subject
        ),
        sprintf( "From: %s <%s>\n\n%s", $name, $email, $message ),
        $headers
    );

    if ( ! $mail_sent ) {
        // Log failed email for debugging (optional)
        error_log( sprintf( 'Ratna Gems: Failed to send contact form email for %s', $email ) );
    }

    return new WP_REST_Response(
        array(
            'success' => true,
            'message' => __( 'Thank you! Your message has been sent.', 'ratna-gems' ),
        ),
        200
    );
}
