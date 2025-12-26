/**
 * RatnaGems Delhivery Admin JS
 * - Pickup scheduling (existing)
 * - Tracking + NDR actions (new)
 */
jQuery(document).ready(function($) {
    // ---------- Modal styles ----------
    const modalCSS = `
        .rg-delhivery-modal-backdrop {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.6); z-index: 99998; display: flex;
            align-items: center; justify-content: center;
        }
        .rg-delhivery-modal-content {
            background: #fff; padding: 25px; border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3); width: 90%;
            max-width: 520px; text-align: center; z-index: 99999;
        }
        .rg-delhivery-modal-title { font-size: 1.2em; font-weight: 600; margin: 0 0 15px; color: #333; }
        .rg-delhivery-modal-message { margin: 0 0 25px; color: #555; line-height: 1.6; white-space: pre-wrap; word-break: break-word; }
        .rg-delhivery-modal-actions { display: flex; justify-content: center; gap: 15px; }
        .rg-delhivery-modal-button { padding: 10px 20px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer; font-size: 1em; }
        .rg-delhivery-modal-button.confirm { background-color: #2271b1; color: #fff; border-color: #2271b1; }
        .rg-delhivery-modal-button.cancel { background-color: #f0f0f1; color: #50575e; }
    `;
    $('head').append('<style>' + modalCSS + '</style>');

    function showDelhiveryModal(title, message, isConfirm = false) {
        return new Promise((resolve) => {
            $('.rg-delhivery-modal-backdrop').remove();
            const buttonsHTML = isConfirm
                ? `<button class="rg-delhivery-modal-button cancel">Cancel</button>
                   <button class="rg-delhivery-modal-button confirm">Confirm</button>`
                : `<button class="rg-delhivery-modal-button confirm">OK</button>`;

            const modalHTML = `
                <div class="rg-delhivery-modal-backdrop">
                    <div class="rg-delhivery-modal-content">
                        <h3 class="rg-delhivery-modal-title">${title}</h3>
                        <div class="rg-delhivery-modal-message">${message}</div>
                        <div class="rg-delhivery-modal-actions">${buttonsHTML}</div>
                    </div>
                </div>`;
            $('body').append(modalHTML);

            $('.rg-delhivery-modal-button.confirm').on('click', function() {
                $('.rg-delhivery-modal-backdrop').remove(); resolve(true);
            });
            $('.rg-delhivery-modal-button.cancel').on('click', function() {
                $('.rg-delhivery-modal-backdrop').remove(); resolve(false);
            });
        });
    }

    // ---------- Add "Schedule Delhivery Pickup" button to the orders list ----------
    function addButton() {
        var $toolbar = $('.wp-list-table').siblings('.tablenav.top');
        if ($toolbar.length && $('#rg-schedule-delhivery-pickup').length === 0) {
            var $buttonContainer = $toolbar.find('.bulkactions');
            if ($buttonContainer.length) {
                var buttonHTML = '<div class="alignleft actions"><button type="button" id="rg-schedule-delhivery-pickup" class="button">Schedule Delhivery Pickup</button></div>';
                $buttonContainer.after(buttonHTML);
            }
        }
    }
    addButton();

    // ---------- Pickup scheduling ----------
    $('body').on('click', '#rg-schedule-delhivery-pickup', async function(e) {
        e.preventDefault();
        var $button = $(this);
        if ($button.is(':disabled')) return;

        const confirmed = await showDelhiveryModal('Confirm Pickup', 'Are you sure you want to schedule a pickup for all manifested orders?', true);
        if (!confirmed) return;

        $button.text('Scheduling...').prop('disabled', true);

        $.ajax({
            url: rgDelhiveryAjax.url,
            type: 'POST',
            data: { action: 'rg_schedule_delhivery_pickup', nonce: rgDelhiveryAjax.nonce },
            success: function(response) {
                if (response.success) {
                    showDelhiveryModal('Pickup Scheduled',
                        'Pickup scheduled successfully!\nPickup ID: ' + response.data.pickup_id + '\nPackages included: ' + response.data.package_count
                    );
                } else {
                    showDelhiveryModal('Error', response.data.message || 'An unexpected error occurred.');
                }
                $button.text('Schedule Delhivery Pickup').prop('disabled', false);
            },
            error: function() {
                showDelhiveryModal('Error', 'An unexpected error occurred. Please try again.');
                $button.text('Schedule Delhivery Pickup').prop('disabled', false);
            }
        });
    });

    // ---------- Tracking ----------
    $('body').on('click', '#rg-delhivery-track-now', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const awb = $btn.data('awb');
        const orderId = $btn.data('order-id') || '';

        $.ajax({
            url: rgDelhiveryAjax.url,
            type: 'POST',
            data: { action: 'rg_delhivery_track', nonce: rgDelhiveryAjax.nonce, awb: awb, order_id: orderId },
            success: function(resp) {
                if (resp.success) {
                    const summary = resp.data.summary || 'Latest tracking data loaded.';
                    showDelhiveryModal('Latest Tracking', summary + '\n\nAWB: ' + awb);
                } else {
                    showDelhiveryModal('Error', resp.data.message || 'Could not fetch tracking at this time.');
                }
            },
            error: function() {
                showDelhiveryModal('Error', 'Network error while fetching tracking.');
            }
        });
    });

    // ---------- NDR: Re-attempt ----------
    $('body').on('click', '#rg-delhivery-ndr-reattempt', async function(e) {
        e.preventDefault();
        const awb = $(this).data('awb');
        const ok = await showDelhiveryModal('Confirm RE-ATTEMPT', 'Apply RE-ATTEMPT on AWB ' + awb + '?', true);
        if (!ok) return;

        $.ajax({
            url: rgDelhiveryAjax.url,
            type: 'POST',
            data: { action: 'rg_delhivery_ndr', nonce: rgDelhiveryAjax.nonce, awb: awb, act: 'RE-ATTEMPT' },
            success: function(resp) {
                if (resp.success) {
                    const msg = 'NDR RE-ATTEMPT submitted.' + (resp.data.upl_id ? '\nUPL: ' + resp.data.upl_id : '');
                    showDelhiveryModal('NDR', msg);
                } else {
                    showDelhiveryModal('Error', resp.data.message || 'Could not submit NDR RE-ATTEMPT.');
                }
            },
            error: function() {
                showDelhiveryModal('Error', 'Network error while submitting NDR.');
            }
        });
    });

    // ---------- NDR: Reschedule (simple date prompt) ----------
    $('body').on('click', '#rg-delhivery-ndr-reschedule', async function(e) {
        e.preventDefault();
        const awb = $(this).data('awb');
        const date = window.prompt('Enter new pickup date (YYYY-MM-DD):', '');
        if (!date) return;
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
            showDelhiveryModal('Error', 'Invalid date format. Use YYYY-MM-DD.');
            return;
        }

        $.ajax({
            url: rgDelhiveryAjax.url,
            type: 'POST',
            data: { action: 'rg_delhivery_ndr', nonce: rgDelhiveryAjax.nonce, awb: awb, act: 'PICKUP_RESCHEDULE', pickup_date: date },
            success: function(resp) {
                if (resp.success) {
                    const msg = 'NDR PICKUP_RESCHEDULE submitted for ' + date + (resp.data.upl_id ? '\nUPL: ' + resp.data.upl_id : '');
                    showDelhiveryModal('NDR', msg);
                } else {
                    showDelhiveryModal('Error', resp.data.message || 'Could not submit NDR reschedule.');
                }
            },
            error: function() {
                showDelhiveryModal('Error', 'Network error while submitting NDR.');
            }
        });
    });
});
