/**
 * Delhivery Admin JavaScript
 * Handles AJAX interactions in admin order screens
 *
 * @package Ratna Gems
 * @version 2.0.0
 */

(function($) {
    'use strict';

    // Global namespace
    window.RGDelhivery = window.RGDelhivery || {};

    /**
     * Initialize admin functionality
     */
    RGDelhivery.init = function() {
        this.bindEvents();
    };

    /**
     * Bind event handlers
     */
    RGDelhivery.bindEvents = function() {
        // Bulk actions on orders list
        $(document).on('click', '.rg-dlv-bulk-manifest', this.bulkManifest);
        $(document).on('click', '.rg-dlv-bulk-refresh', this.bulkRefresh);
    };

    /**
     * Show loading state
     */
    RGDelhivery.showLoading = function($btn) {
        $btn.data('original-text', $btn.html());
        $btn.prop('disabled', true).html(rgDelhivery.i18n.loading);
    };

    /**
     * Hide loading state
     */
    RGDelhivery.hideLoading = function($btn) {
        $btn.prop('disabled', false).html($btn.data('original-text'));
    };

    /**
     * Make AJAX request
     */
    RGDelhivery.ajax = function(action, data, $btn) {
        var self = this;
        
        if ($btn) {
            this.showLoading($btn);
        }

        return $.ajax({
            url: rgDelhivery.ajaxUrl,
            type: 'POST',
            data: $.extend({
                action: action,
                security: rgDelhivery.nonce
            }, data)
        }).always(function() {
            if ($btn) {
                self.hideLoading($btn);
            }
        });
    };

    /**
     * Bulk manifest selected orders
     */
    RGDelhivery.bulkManifest = function(e) {
        e.preventDefault();
        
        var orderIds = [];
        $('input[name="id[]"]:checked, input[name="post[]"]:checked').each(function() {
            orderIds.push($(this).val());
        });

        if (orderIds.length === 0) {
            alert('Please select at least one order.');
            return;
        }

        if (!confirm('Manifest ' + orderIds.length + ' order(s) with Delhivery?')) {
            return;
        }

        RGDelhivery.ajax('rg_delhivery_bulk_manifest', {
            order_ids: orderIds
        }, $(this)).done(function(res) {
            if (res.success) {
                alert(res.data.message);
                location.reload();
            } else {
                alert('Error: ' + res.data.message);
            }
        }).fail(function() {
            alert(rgDelhivery.i18n.error);
        });
    };

    /**
     * Bulk refresh tracking
     */
    RGDelhivery.bulkRefresh = function(e) {
        e.preventDefault();
        
        var orderIds = [];
        $('input[name="id[]"]:checked, input[name="post[]"]:checked').each(function() {
            orderIds.push($(this).val());
        });

        if (orderIds.length === 0) {
            alert('Please select at least one order.');
            return;
        }

        RGDelhivery.ajax('rg_delhivery_bulk_refresh', {
            order_ids: orderIds
        }, $(this)).done(function(res) {
            if (res.success) {
                alert(res.data.message);
                location.reload();
            } else {
                alert('Error: ' + res.data.message);
            }
        }).fail(function() {
            alert(rgDelhivery.i18n.error);
        });
    };

    // Initialize on document ready
    $(document).ready(function() {
        RGDelhivery.init();
    });

})(jQuery);
