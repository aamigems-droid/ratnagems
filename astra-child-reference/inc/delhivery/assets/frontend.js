/**
 * Delhivery Frontend JavaScript
 * Handles tracking widget and pincode checking on frontend
 *
 * @package Ratna Gems
 * @version 2.0.0
 */

(function($) {
    'use strict';

    /**
     * Check pincode serviceability on checkout
     */
    function checkPincodeOnCheckout() {
        var $pincode = $('#shipping_postcode, #billing_postcode');
        var $message = $('#rg-dlv-pincode-message');
        
        if (!$message.length) {
            $pincode.closest('.form-row').after('<div id="rg-dlv-pincode-message"></div>');
            $message = $('#rg-dlv-pincode-message');
        }

        var pincode = $pincode.val();
        if (!pincode || pincode.length < 6) {
            $message.html('');
            return;
        }

        $message.html('<small style="color:#666;">Checking delivery availability...</small>');

        $.post(rgDelhiveryFrontend.ajaxUrl, {
            action: 'rg_delhivery_check_pincode',
            pincode: pincode
        }, function(res) {
            if (res.success) {
                if (res.data.is_serviceable) {
                    if (res.data.has_embargo) {
                        $message.html('<small style="color:#c00;">⚠️ Delivery temporarily suspended for this pincode</small>');
                    } else {
                        $message.html('<small style="color:#090;">✓ Delivery available</small>');
                    }
                } else {
                    $message.html('<small style="color:#c00;">✗ Delivery not available for this pincode</small>');
                }
            } else {
                $message.html('');
            }
        }).fail(function() {
            $message.html('');
        });
    }

    // Initialize on checkout page
    $(document).ready(function() {
        // Pincode check on checkout
        if ($('body').hasClass('woocommerce-checkout')) {
            var checkTimeout;
            $('#shipping_postcode, #billing_postcode').on('change keyup', function() {
                clearTimeout(checkTimeout);
                checkTimeout = setTimeout(checkPincodeOnCheckout, 500);
            });
        }
    });

})(jQuery);
