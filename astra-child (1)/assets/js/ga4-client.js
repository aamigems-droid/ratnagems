/**
 * GA4 Client-Side Event Handler for Ratna Gems
 *
 * @version 3.5.0
 *
 * This script handles:
 * - add_to_cart event tracking for both AJAX and standard add-to-cart
 * - Meta click ID (fbc/fbp) collection and forwarding
 * - Event deduplication via event_id markers
 *
 * CHANGELOG v3.5.0:
 * - Verified filterMetaId preserves case (correct per Meta CAPI spec)
 * - Added JSDoc comments for better maintainability
 * - Improved error handling
 */
(function(){
  'use strict';

  if ( typeof window === 'undefined' || typeof window.ratnaGemsGa4Config === 'undefined' ) {
    return;
  }

  var config = window.ratnaGemsGa4Config;
  var cache = Object.assign({}, config.products || {});

  /**
   * Read a cookie value by name.
   * @param {string} name - Cookie name
   * @returns {string} Cookie value or empty string
   */
  function getCookie(name){
    var escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + escaped + '=([^;]*)'));
    return match && match[1] ? decodeURIComponent(match[1]) : '';
  }

  /**
   * Parse the consent cookie.
   * @returns {Object|null} Parsed consent state or null
   */
  function parseConsent(){
    try {
      var raw = getCookie('rg_consent_status');
      if (!raw) {
        return null;
      }
      return JSON.parse(raw);
    } catch (error) {
      console.error('Consent parsing failed', error);
      return null;
    }
  }

  /**
   * Check if ad_user_data consent is granted.
   * This determines whether we can share Meta click IDs.
   * @returns {boolean}
   */
  function canShareMetaIds(){
    var state = parseConsent();
    if (!state) {
      return false;
    }
    var required = ['ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization'];
    return required.every(function(key){
      return String(state[key] || '').toLowerCase() === 'granted';
    });
  }

  /**
   * Filter Meta ID values for safety.
   * IMPORTANT: Preserves original case per Meta CAPI specification.
   * The fbclid is case-sensitive and must not be lowercased.
   *
   * @param {string} value - The value to filter
   * @returns {string} Sanitized value with original case preserved
   */
  function filterMetaId(value){
    if (!value) {
      return '';
    }
    // Remove dangerous characters but preserve case per Meta CAPI spec
    var sanitized = value.toString().replace(/[^a-zA-Z0-9._-]/g, '');
    return sanitized || '';
  }

  /**
   * Build Meta click IDs (fbc and fbp).
   * These are critical for Meta event matching and attribution.
   *
   * @returns {Object} Object containing fbc and/or fbp if available
   */
  function buildMetaIds(){
    var ids = {};

    if (!canShareMetaIds()) {
      return ids;
    }

    // Try to get _fbc from cookie first
    var fbc = filterMetaId(getCookie('_fbc'));
    if (!fbc) {
      // If no _fbc cookie, build from fbclid URL parameter
      try {
        var params = new URLSearchParams(window.location.search);
        var fbclid = params.get('fbclid');
        if (fbclid) {
          // Format: fb.1.{creation_time_millis}.{fbclid}
          // Use Date.now() for milliseconds per Meta CAPI spec
          // CRITICAL: Preserve fbclid case
          var cleanFbclid = filterMetaId(fbclid);
          if (cleanFbclid) {
            fbc = 'fb.1.' + Date.now() + '.' + cleanFbclid;
          }
        }
      } catch (e) {
        // URLSearchParams not supported in older browsers
      }
    }
    if (fbc) {
      ids.fbc = fbc;
    }

    // Get _fbp from cookie
    var fbp = filterMetaId(getCookie('_fbp'));
    if (fbp) {
      ids.fbp = fbp;
    }

    return ids;
  }

  /**
   * Send a beacon to mark an event as emitted (for deduplication).
   * @param {string} eventId - The event ID to mark
   */
  function enqueueBeacon(eventId){
    if (!eventId) {
      return;
    }
    try {
      var form = new FormData();
      form.append('action', 'ratna_gems_ga4_mark_emitted');
      form.append('event_id', eventId);
      form.append('nonce', config.nonces.eventMarker);

      if ( navigator.sendBeacon ) {
        navigator.sendBeacon(config.ajaxUrl, form);
      } else {
        fetch(config.ajaxUrl, { 
          method: 'POST', 
          body: form, 
          credentials: 'same-origin' 
        }).catch(function(){});
      }
    } catch (error) {
      console.error('GA4 marker failed', error);
    }
  }

  /**
   * Push a payload to the dataLayer.
   * @param {Object} payload - The dataLayer payload
   */
  function pushPayload(payload){
    if (!payload) {
      return;
    }
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push(payload);
  }

  /**
   * Get current page URL for event_source_url.
   * Required by Meta CAPI for website events.
   * @returns {string}
   */
  function getCurrentUrl() {
    return window.location.href;
  }

  /**
   * Handle successful product info response and push add_to_cart event.
   * @param {Object} json - The AJAX response
   */
  function handleProductResponse(json){
    if (!json || !json.success || !json.data || !json.data.item) {
      return;
    }

    var cacheKey = String(json.data.productId || json.data.item.item_id);
    cache[cacheKey] = json.data;

    // Generate unique event ID for deduplication
    var eventId = 'rg_add_to_cart_' + (
      window.crypto && window.crypto.randomUUID 
        ? window.crypto.randomUUID() 
        : Math.random().toString(16).slice(2)
    );

    // Mark event as emitted for server-side deduplication
    enqueueBeacon(eventId);

    var payload = {
      event: 'add_to_cart',
      event_id: eventId,
      // Include event_time (epoch seconds) so server-side tags receive accurate timestamp
      // This mirrors the server-side PHP dataLayer events and ensures consistency across
      // all GA4 ecommerce events【244156286699136†L216-L231】.
      event_time: Math.floor(Date.now() / 1000),
      event_source_url: getCurrentUrl(),
      ecommerce: {
        currency: json.data.currency,
        value: json.data.value,
        items: [ json.data.item ]
      }
    };

    // Always include meta object for Meta CAPI compatibility
    var metaIds = buildMetaIds();
    payload.meta = metaIds;

    pushPayload(payload);
  }

  /**
   * Request product info via AJAX for add_to_cart tracking.
   * Uses cache to avoid duplicate requests.
   *
   * @param {string|number} productId - WooCommerce product ID
   * @param {number} quantity - Quantity being added
   */
  function requestProduct(productId, quantity){
    var cacheKey = String(productId);
    var cached = cache[cacheKey];

    if (cached) {
      // Use cached product data
      handleProductResponse({ success: true, data: cached });
      return;
    }

    var params = new URLSearchParams();
    params.append('action', 'ratna_gems_ga4_product_info');
    params.append('product_id', productId);
    params.append('quantity', quantity || 1);
    params.append('nonce', config.nonces.productInfo);

    fetch(config.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: params.toString()
    })
    .then(function(response){ 
      return response.json(); 
    })
    .then(function(result){
      if (result && result.success && result.data) {
        cache[String(result.data.productId || productId)] = result.data;
      }
      handleProductResponse(result);
    })
    .catch(function(error){ 
      console.error('GA4 product lookup failed', error); 
    });
  }

  // ---------------------------------------------------------------------------
  // Event Listeners
  // ---------------------------------------------------------------------------

  /**
   * Handle standard add-to-cart button clicks (single product pages).
   * Uses event capturing to intercept before form submission.
   */
  document.addEventListener('click', function(event){
    var target = event.target;

    while ( target && target !== document ) {
      if ( target.tagName && target.tagName.toLowerCase() === 'button' ) {
        var name = target.getAttribute('name');
        var className = target.className || '';

        if ( name === 'add-to-cart' || className.indexOf('single_add_to_cart_button') !== -1 ) {
          var productId = target.getAttribute('value') || target.getAttribute('data-product_id');

          if ( productId ) {
            var form = target.closest('form');
            var quantity = 1;

            if ( form ) {
              var quantityField = form.querySelector('input[name="quantity"], input.qty');
              if ( quantityField && quantityField.value ) {
                quantity = quantityField.value;
              }
            }

            requestProduct(productId, quantity);
          }
          return;
        }
      }
      target = target.parentNode;
    }
  }, true);

  /**
   * Handle AJAX add-to-cart (archive/shop pages with AJAX enabled).
   * Listens for WooCommerce's added_to_cart event.
   */
  if ( typeof jQuery !== 'undefined' ) {
    jQuery(function($){
      $(document.body).on('added_to_cart', function(_event, _fragments, _hash, $button){
        var productId = $button && ($button.data('product_id') || $button.attr('data-product_id'));
        var quantity = $button && ($button.data('quantity') || $button.attr('data-quantity'));

        if ( productId ) {
          requestProduct(productId, quantity || 1);
        }
      });
    });
  }
})();
