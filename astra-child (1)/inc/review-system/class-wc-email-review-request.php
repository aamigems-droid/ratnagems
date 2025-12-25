<?php
/**
 * Review Request Email Class
 * 
 * @package RatnaGems
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WC_Email_Review_Request' ) ) :

class WC_Email_Review_Request extends WC_Email {

    public function __construct() {
        $this->id             = 'customer_review_request';
        $this->customer_email = true;
        $this->title          = 'Review Request';
        $this->description    = 'Sent to customer after delivery asking for review.';
        $this->template_html  = 'emails/customer-review-request.php';
        $this->template_plain = 'emails/plain/customer-review-request.php';
        $this->template_base  = get_stylesheet_directory() . '/woocommerce/';
        
        $this->placeholders = array(
            '{customer_name}' => '',
            '{order_number}'  => '',
        );

        parent::__construct();
    }

    public function get_default_subject() {
        return 'Your order is delivered! Please share your feedback ⭐';
    }

    public function get_default_heading() {
        return 'How was your experience?';
    }

    public function trigger( $order_id ) {
        $this->setup_locale();

        if ( $order_id ) {
            $this->object = wc_get_order( $order_id );
            
            if ( $this->object ) {
                $this->recipient = $this->object->get_billing_email();
                $this->placeholders['{customer_name}'] = $this->object->get_billing_first_name();
                $this->placeholders['{order_number}']  = $this->object->get_order_number();
            }
        }

        if ( $this->is_enabled() && $this->get_recipient() ) {
            $this->send( 
                $this->get_recipient(), 
                $this->get_subject(), 
                $this->get_content(), 
                $this->get_headers(), 
                $this->get_attachments() 
            );
        }

        $this->restore_locale();
    }

    public function get_content_html() {
        return wc_get_template_html(
            $this->template_html,
            array(
                'order'              => $this->object,
                'email_heading'      => $this->get_heading(),
                'sent_to_admin'      => false,
                'plain_text'         => false,
                'email'              => $this,
                'review_link'        => defined( 'RG_GOOGLE_REVIEW_LINK' ) ? RG_GOOGLE_REVIEW_LINK : '',
            ),
            '',
            $this->template_base
        );
    }

    public function get_content_plain() {
        return wc_get_template_html(
            $this->template_plain,
            array(
                'order'              => $this->object,
                'email_heading'      => $this->get_heading(),
                'sent_to_admin'      => false,
                'plain_text'         => true,
                'email'              => $this,
                'review_link'        => defined( 'RG_GOOGLE_REVIEW_LINK' ) ? RG_GOOGLE_REVIEW_LINK : '',
            ),
            '',
            $this->template_base
        );
    }
}

endif;
