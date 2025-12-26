/**
 * RatnaGems Delhivery Admin JS v3.0.1
 *
 * Handles AJAX for pickup requests, adds the button to the DOM,
 * and implements a custom modal for notifications.
 */
jQuery(document).ready(function($) {

    // --- Inject Modal CSS into the document head ---
    // This keeps the styles self-contained within this script.
    const modalCSS = `
        .rg-delhivery-modal-backdrop {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.6); z-index: 99998; display: flex;
            align-items: center; justify-content: center;
        }
        .rg-delhivery-modal-content {
            background: #fff; padding: 25px; border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3); width: 90%;
            max-width: 450px; text-align: center; z-index: 99999;
        }
        .rg-delhivery-modal-title {
            font-size: 1.2em; font-weight: 600; margin: 0 0 15px;
            color: #333;
        }
        .rg-delhivery-modal-message {
            margin: 0 0 25px; color: #555; line-height: 1.6;
            white-space: pre-wrap; /* Allows line breaks like \n */
        }
        .rg-delhivery-modal-actions {
            display: flex; justify-content: center; gap: 15px;
        }
        .rg-delhivery-modal-button {
            padding: 10px 20px; border: 1px solid #ccc; border-radius: 4px;
            cursor: pointer; font-size: 1em;
        }
        .rg-delhivery-modal-button.confirm {
            background-color: #2271b1; color: #fff; border-color: #2271b1;
        }
        .rg-delhivery-modal-button.cancel {
            background-color: #f0f0f1; color: #50575e;
        }
    `;
    $('head').append('<style>' + modalCSS + '</style>');

    /**
     * --- Custom Modal Function ---
     * Replaces native confirm() and alert() for a better UX.
     * @param {string} title - The title of the modal.
     * @param {string} message - The message to display.
     * @param {boolean} isConfirm - If true, shows Confirm/Cancel buttons. If false, shows OK.
     * @returns {Promise<boolean>} - Resolves true for confirm, false for cancel/ok.
     */
    function showDelhiveryModal(title, message, isConfirm = false) {
        return new Promise((resolve) => {
            // Remove any existing modal first
            $('.rg-delhivery-modal-backdrop').remove();

            let buttonsHTML = isConfirm ?
                `<button class="rg-delhivery-modal-button cancel">Cancel</button>
                 <button class="rg-delhivery-modal-button confirm">Confirm</button>` :
                `<button class="rg-delhivery-modal-button confirm">OK</button>`;

            const modalHTML = `
                <div class="rg-delhivery-modal-backdrop">
                    <div class="rg-delhivery-modal-content">
                        <h3 class="rg-delhivery-modal-title">${title}</h3>
                        <p class="rg-delhivery-modal-message">${message}</p>
                        <div class="rg-delhivery-modal-actions">
                            ${buttonsHTML}
                        </div>
                    </div>
                </div>`;

            $('body').append(modalHTML);

            $('.rg-delhivery-modal-button.confirm').on('click', function() {
                $('.rg-delhivery-modal-backdrop').remove();
                resolve(true);
            });

            $('.rg-delhivery-modal-button.cancel').on('click', function() {
                $('.rg-delhivery-modal-backdrop').remove();
                resolve(false);
            });
        });
    }

    // --- Add the "Schedule Delhivery Pickup" button ---
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

    // --- Handle the AJAX request when the button is clicked ---
    $('body').on('click', '#rg-schedule-delhivery-pickup', async function(e) {
        e.preventDefault();
        var $button = $(this);

        if ($button.is(':disabled')) {
            return;
        }

        // **IMPROVEMENT:** Replaced native confirm() with custom modal.
        const confirmed = await showDelhiveryModal(
            'Confirm Pickup',
            'Are you sure you want to schedule a pickup for all manifested orders?',
            true
        );

        if (!confirmed) {
            return;
        }

        $button.text('Scheduling...').prop('disabled', true);

        $.ajax({
            url: rgDelhiveryAjax.url,
            type: 'POST',
            data: {
                action: 'rg_schedule_delhivery_pickup',
                nonce: rgDelhiveryAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // **IMPROVEMENT:** Replaced native alert() with custom modal.
                    showDelhiveryModal(
                        'Pickup Scheduled',
                        'Pickup scheduled successfully!\nPickup ID: ' + response.data.pickup_id + '\nPackages included: ' + response.data.package_count
                    );
                } else {
                    // **IMPROVEMENT:** Replaced native alert() with custom modal.
                    showDelhiveryModal('Error', response.data.message);
                }
                $button.text('Schedule Delhivery Pickup').prop('disabled', false);
            },
            error: function() {
                // **IMPROVEMENT:** Replaced native alert() with custom modal.
                showDelhiveryModal('Error', 'An unexpected error occurred. Please try again.');
                $button.text('Schedule Delhivery Pickup').prop('disabled', false);
            }
        });
    });
});
