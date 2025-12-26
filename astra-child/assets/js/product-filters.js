/**
 * Sarfaraz Gems — AJAX Product Filters JS
 */
jQuery(function ($) {
    'use strict';

    const form = $('#sg-filters-form');
    if (!form.length) return;

    const productArchive = $('#sg-product-archive');
    const activeFiltersWrapper = $('#sg-active-filters-wrapper');
    let filterRequest = null;

    const debounce = (fn, wait = 500) => {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => fn.apply(this, args), wait);
        };
    };

    const performAjaxFilter = debounce(() => {
        if (filterRequest && filterRequest.readyState !== 4) filterRequest.abort();
        
        productArchive.addClass('loading');

        const formData = new FormData(form[0]);
        const params = new URLSearchParams();
        const checkboxValues = {};

        formData.forEach((value, key) => {
            if (value === '') return;
            const checkbox = form[0].querySelector(`input[type="checkbox"][name="${key}"]`);
            if (checkbox) {
                if (!checkboxValues[key]) checkboxValues[key] = [];
                checkboxValues[key].push(value);
            } else {
                params.set(key, value);
            }
        });

        for (const key in checkboxValues) {
            if (checkboxValues[key].length > 0) {
                params.set(key, checkboxValues[key].join(','));
            }
        }

        const sortedParams = new URLSearchParams();
        Array.from(params.keys()).sort().forEach(key => sortedParams.set(key, params.get(key)));
        
        let ajaxFormData = sortedParams.toString();
        const baseUrl = form.attr('action');
        
        if (sg_filter_params.archive_slug) {
            const contextKey = sg_filter_params.is_cat ? 'product_cat' : 'product_tag';
            ajaxFormData += `&${contextKey}=${encodeURIComponent(sg_filter_params.archive_slug)}`;
        }
        
        const newUrl = baseUrl + (sortedParams.toString() ? '?' + sortedParams.toString() : '');
        window.history.pushState({ path: newUrl }, '', newUrl);

        filterRequest = $.ajax({
            type: 'POST',
            url: sg_filter_params.ajax_url,
            data: { 
                action: 'sg_filter_products', 
                nonce: sg_filter_params.nonce, 
                form_data: ajaxFormData,
                base_url: baseUrl
            },
            success: function(response) {
                if (response.success) {
                    productArchive.find('ul.products').html(response.data.products);
                    activeFiltersWrapper.html(response.data.active_filters);

                    $(document.body).trigger('updated_wc_div');
                    $(document.body).trigger('wc_fragment_refresh');
                    $(window).trigger('resize');
                }
            },
            complete: function() {
                productArchive.removeClass('loading');
            }
        });
    });

    function initializeSlider(sliderId, minInputId, maxInputId, minTextId, maxTextId, prefix, suffix, step) {
        const slider = $(sliderId);
        if (!slider.length) return;
        const minInput = $(minInputId), maxInput = $(maxInputId);
        const minText = $(minTextId), maxText = $(maxTextId);
        const minVal = parseFloat(slider.data('min')) || 0, maxVal = parseFloat(slider.data('max')) || 0;
        let currentMin = parseFloat(slider.data('current-min')), currentMax = parseFloat(slider.data('current-max'));
        if (!Number.isFinite(currentMin) || currentMin < minVal) currentMin = minVal;
        if (!Number.isFinite(currentMax) || currentMax > maxVal) currentMax = maxVal;
        if (maxVal <= minVal) { slider.closest('.filter-group').hide(); return; }

        const formatValue = (v) => (step < 1 ? parseFloat(v).toFixed(2) : Math.round(v));
        const updateText = (vals) => {
            minText.text(prefix + formatValue(vals[0]) + suffix);
            maxText.text(prefix + formatValue(vals[1]) + suffix);
        };
        
        slider.slider({
            range: true,
            min: minVal,
            max: maxVal,
            step: step,
            values: [currentMin, currentMax],
            create: () => updateText([currentMin, currentMax]),
            slide: (e, ui) => updateText(ui.values),
            stop: (e, ui) => {
                minInput.val(ui.values[0]);
                maxInput.val(ui.values[1]);
                performAjaxFilter();
            }
        });
    }

    // --- Event Handlers & Initialization ---
    $('body')
        .on('click', '.sg-filter-toggle', function(e) {
            e.preventDefault();
            $(this).closest('.sg-filter-wrapper').find('.sg-filters-collapsible').slideToggle(200);
            $(this).toggleClass('active');
        })
        .on('click', '.remove-filter', function(e) {
            e.preventDefault();
            const filterPill = $(this).closest('li');
            const filterType = filterPill.data('filter');
            const filterValue = filterPill.data('value');

            if (filterType === 'price' || filterType === 'carat') {
                $(`#min_${filterType}`).val('');
                $(`#max_${filterType}`).val('');
            } else {
                $(`input[name="${filterType}"][value="${filterValue}"]`).prop('checked', false);
            }
            performAjaxFilter();
        });

    form.on('change', 'input[type="checkbox"]', performAjaxFilter);

    initializeSlider('#price-range-slider', '#min_price', '#max_price', '#price-range-min-text', '#price-range-max-text', sg_filter_params.currency_symbol || '', '', 1);
    initializeSlider('#carat-range-slider', '#min_carat', '#max_carat', '#carat-range-min-text', '#carat-range-max-text', '', ' ct', 0.01);
});
