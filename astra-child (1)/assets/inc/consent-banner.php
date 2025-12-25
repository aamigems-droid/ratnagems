<?php
/**
 * Cookie consent banner and Consent Mode helpers.
 *
 * @package Ratna Gems
 * @version 3.5.0
 *
 * CHANGELOG v3.5.0:
 * - FIX: Removed fbclid lowercase transformation per Meta CAPI spec
 * - FIX: Added analytics_storage to Meta Pixel consent check for consistency
 * - FIX: Removed duplicate dataLayer.push wrapper that caused double event processing
 * - FIX: Added content_name to ViewContent events for better Meta matching
 * - ENHANCEMENT: Added region parameter support for Consent Mode v2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Retrieve the stored consent cookie (JSON payload) if available.
 */
function ratna_gems_get_consent_cookie(): ?array {
    if ( empty( $_COOKIE['rg_consent_status'] ) ) {
        return null;
    }

    $raw     = wp_unslash( (string) $_COOKIE['rg_consent_status'] );
    $decoded = json_decode( rawurldecode( $raw ), true );

    return is_array( $decoded ) ? $decoded : null;
}

/**
 * Whether the saved consent state allows analytics (and therefore GTM).
 */
function ratna_gems_consent_allows_gtm( ?array $state = null ): bool {
    $state = $state ?? ratna_gems_get_consent_cookie();
    if ( ! $state ) {
        return false;
    }

    $analytics = isset( $state['analytics_storage'] ) ? strtolower( (string) $state['analytics_storage'] ) : 'denied';

    return 'granted' === $analytics;
}

/**
 * Whether the saved consent state allows sending hashed user data.
 */
function ratna_gems_consent_allows_ad_user_data( ?array $state = null ): bool {
    $state = $state ?? ratna_gems_get_consent_cookie();
    if ( ! $state ) {
        return false;
    }

    $ad_user_data = isset( $state['ad_user_data'] ) ? strtolower( (string) $state['ad_user_data'] ) : 'denied';

    return 'granted' === $ad_user_data;
}

/**
 * Whether the saved consent state has full grants for advertising and analytics.
 */
function ratna_gems_consent_is_fully_granted( ?array $state = null ): bool {
    $state = $state ?? ratna_gems_get_consent_cookie();
    if ( ! $state ) {
        return false;
    }

    $required = array( 'ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization' );

    foreach ( $required as $key ) {
        if ( ! isset( $state[ $key ] ) || 'granted' !== strtolower( (string) $state[ $key ] ) ) {
            return false;
        }
    }

    return true;
}

/**
 * Enqueue consent banner assets.
 */
add_action( 'wp_enqueue_scripts', 'ratnagems_consent_banner_assets' );
function ratnagems_consent_banner_assets(): void {
    $dir        = get_stylesheet_directory();
    $uri        = get_stylesheet_directory_uri();
    $style_path = $dir . '/assets/css/consent-banner.css';
    $script_path = $dir . '/assets/js/consent-banner.js';

    if ( file_exists( $style_path ) ) {
        wp_register_style( 'ratnagems-consent-banner-css', $uri . '/assets/css/consent-banner.css', array(), (string) filemtime( $style_path ) );
        wp_enqueue_style( 'ratnagems-consent-banner-css' );
    }

    if ( file_exists( $script_path ) ) {
        wp_register_script( 'ratnagems-consent-banner-js', $uri . '/assets/js/consent-banner.js', array(), (string) filemtime( $script_path ), true );
        wp_script_add_data( 'ratnagems-consent-banner-js', 'strategy', 'defer' );
        wp_enqueue_script( 'ratnagems-consent-banner-js' );
    }
}

/**
 * Output the consent banner HTML on the frontend.
 */
add_action( 'wp_body_open', 'ratnagems_render_consent_banner', 20 );
add_action( 'wp_footer', 'ratnagems_render_consent_banner', 20 );
function ratnagems_render_consent_banner(): void {
    static $rendered = false;

    if ( $rendered ) {
        return;
    }

    if ( is_admin() ) {
        return;
    }

    if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
        return;
    }

    if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
        return;
    }

    $rendered = true;

    $privacy_policy_url = function_exists( 'get_privacy_policy_url' ) && get_privacy_policy_url() ? get_privacy_policy_url() : '/privacy-policy';
    $consent_state      = ratna_gems_get_consent_cookie();
    $fully_granted      = ratna_gems_consent_is_fully_granted( $consent_state );
    $banner_classes     = 'rg-consent-banner' . ( $fully_granted ? '' : ' rg-consent-banner--visible' );
    $aria_hidden        = $fully_granted ? 'true' : 'false';

    $message = sprintf(
        /* translators: %s: Privacy Policy URL. */
        __( 'We use cookies for analytics and ads. Scroll or keep browsing to consent. <a href="%s">Privacy Policy</a>.', 'ratna-gems' ),
        esc_url( $privacy_policy_url )
    );

    ?>
    <div
        id="rg-consent-banner"
        class="<?php echo esc_attr( $banner_classes ); ?>"
        role="region"
        aria-live="polite"
        aria-label="<?php echo esc_attr__( 'Cookie consent notice', 'ratna-gems' ); ?>"
        aria-hidden="<?php echo esc_attr( $aria_hidden ); ?>"
        <?php echo $fully_granted ? 'hidden' : ''; ?>
    >
        <p id="rg-consent-message" class="rg-consent-message">
            <?php echo wp_kses( $message, array( 'a' => array( 'href' => array() ) ) ); ?>
        </p>
    </div>
    <?php
}

// -----------------------------------------------------------------------------
// Consent & Tag Manager bootstrap helpers.
// -----------------------------------------------------------------------------

add_action( 'wp_head', 'ratna_gems_print_consent_bootstrap', 1 );
function ratna_gems_print_consent_bootstrap(): void {
    $script = <<<'JS'
window.dataLayer = window.dataLayer || [];
function gtag(){ dataLayer.push(arguments); }

// Consent Mode v2 default state - all denied until user action
gtag('consent', 'default', {
  ad_storage: 'denied',
  analytics_storage: 'denied',
  ad_user_data: 'denied',
  ad_personalization: 'denied',
  functionality_storage: 'granted',
  security_storage: 'granted',
  wait_for_update: 500
});

// Privacy-preserving defaults
gtag('set', 'ads_data_redaction', true);
gtag('set', 'url_passthrough', true);

// Restore consent state from cookie if available
try {
  var match = document.cookie.match(/(?:^|\s*)rg_consent_status=([^;]+)/);
  if (match && match[1]) {
    var state = JSON.parse(decodeURIComponent(match[1]));

    // Build update payload - exclude non-consent keys like timestamp
    var updatePayload = {};
    var consentKeys = ['ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization', 'functionality_storage', 'security_storage'];
    for (var i = 0; i < consentKeys.length; i++) {
      var key = consentKeys[i];
      if (state[key]) {
        updatePayload[key] = state[key];
      }
    }
    
    gtag('consent', 'update', updatePayload);

    // Update ads_data_redaction based on full consent
    var fullGrant = ['ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization']
      .every(function(key) { return String(state[key] || '').toLowerCase() === 'granted'; });
    gtag('set', 'ads_data_redaction', !fullGrant);
    
    // Push consent update event for GTM triggers
    dataLayer.push({ 
      event: 'gtm_consent_update', 
      consentState: state, 
      consentUpdateTime: state.timestamp || Date.now() 
    });
  }
} catch (error) {
  console.error('Consent restoration failed', error);
}
JS;

    echo wp_print_inline_script_tag( $script );
}

add_action( 'wp_head', 'ratna_gems_print_gtm_snippet', 6 );
function ratna_gems_print_gtm_snippet(): void {
    $snippet = <<<'HTML'
<!-- Google Tag Manager -->
<script>
    (function(w,d,s,l,i){
        w[l]=w[l]||[];
        w[l].push({'gtm.start': new Date().getTime(),event:'gtm.js'});
        var f=d.getElementsByTagName(s)[0],
            j=d.createElement(s),
            dl=l!='dataLayer'?'&l='+l:'';
        j.async=true;
        j.src='https://load.tags.ratnagems.com/gtm.js?id='+i+dl;
        f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer','GTM-K7HJ629G');
</script>
<!-- End Google Tag Manager -->
HTML;

    echo $snippet;
}

add_action( 'wp_body_open', 'ratna_gems_print_gtm_noscript', 0 );
function ratna_gems_print_gtm_noscript(): void {
    $snippet = <<<'HTML'
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://load.tags.ratnagems.com/ns.html?id=GTM-K7HJ629G" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
HTML;

    echo $snippet;
}

add_action( 'wp_head', 'ratna_gems_print_meta_autoconfig_guard', 2 );
function ratna_gems_print_meta_autoconfig_guard(): void {
    $filtered_ids = apply_filters( 'ratnagems_meta_pixel_ids', array( '693068893177574' ) );
    $filtered_ids = array_values(
        array_filter(
            array_unique(
                array_map(
                    static function ( $value ): string {
                        $id = preg_replace( '/\D+/', '', (string) $value );

                        return $id;
                    },
                    (array) $filtered_ids
                )
            )
        )
    );

    if ( empty( $filtered_ids ) ) {
        $filtered_ids = array( '693068893177574' );
    }

    $ids_json = wp_json_encode( $filtered_ids );
    if ( ! $ids_json ) {
        return;
    }

    $script = <<<JS
window.fbq = window.fbq || function() {
  (window.fbq.q = window.fbq.q || []).push(arguments);
};
window._fbq = window._fbq || window.fbq;
try {
  var rgPixelIds = {$ids_json};
  if (Array.isArray(rgPixelIds)) {
    rgPixelIds.forEach(function(id) {
      if (id) {
        window.fbq('set', 'autoConfig', 'false', id);
      }
    });
  }
} catch (error) {}
JS;

    echo wp_print_inline_script_tag( $script );
}

add_action( 'wp_head', 'ratna_gems_print_meta_pixel', 12 );
function ratna_gems_print_meta_pixel(): void {
    $filtered_ids = apply_filters( 'ratnagems_meta_pixel_ids', array( '693068893177574' ) );
    $filtered_ids = array_values(
        array_filter(
            array_unique(
                array_map(
                    static function ( $value ): string {
                        $id = preg_replace( '/\D+/', '', (string) $value );

                        return $id;
                    },
                    (array) $filtered_ids
                )
            )
        )
    );

    if ( empty( $filtered_ids ) ) {
        $filtered_ids = array( '693068893177574' );
    }

    $pixel_id = $filtered_ids[0];

    $script = <<<HTML
<!-- Meta Pixel (consent-aware bootstrap) -->
<script>
  (function() {
    'use strict';
    
    var initializedPixels = {};
    var queuedEvents = [];
    var flushTimer = null;
    var dataLayerWrapped = false;

    /**
     * Check if user has granted ad-related consent.
     * FIX: Added analytics_storage to match GA4 consent check for consistency.
     */
    function hasAdConsent(state) {
      if (!state) { return false; }
      // FIX: Include analytics_storage for consistency with server-side checks
      return ['ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization'].every(function(key) {
        return String(state[key] || '').toLowerCase() === 'granted';
      });
    }

    function getConsentState() {
      try {
        var stored = readCookie('rg_consent_status');
        if (stored) {
          return JSON.parse(stored);
        }
      } catch (error) {
        console.error('Meta Pixel consent parse failed', error);
      }
      return null;
    }

    function readCookie(name) {
      var nameEq = name + '=';
      var parts = document.cookie ? document.cookie.split(';') : [];
      for (var i = 0; i < parts.length; i++) {
        var cookie = parts[i].trim();
        if (cookie.indexOf(nameEq) === 0) {
          try {
            return decodeURIComponent(cookie.substring(nameEq.length));
          } catch (error) {
            return cookie.substring(nameEq.length);
          }
        }
      }
      return '';
    }

    /**
     * Filter Meta ID values for safety.
     * FIX: Preserve original case per Meta CAPI spec.
     * The fbclid is case-sensitive and must not be lowercased.
     */
    function filterMetaId(value) {
      if (!value) { return ''; }
      // FIX: Remove only dangerous characters, DO NOT lowercase
      // Meta CAPI requires preserving original case of fbclid
      var sanitized = String(value).replace(/[^a-zA-Z0-9._-]/g, '');
      return sanitized || '';
    }

    /**
     * Build fbc and fbp identifiers for Meta attribution.
     */
    function buildMetaIds() {
      var ids = {};
      
      // Try to get _fbc from cookie first
      var fbc = filterMetaId(readCookie('_fbc'));
      if (!fbc) {
        // Build from URL parameter if no cookie
        try {
          var params = new URLSearchParams(window.location.search || '');
          var fbclid = params.get('fbclid');
          if (fbclid) {
            // FIX: Use Date.now() for milliseconds per Meta CAPI spec
            // Format: fb.1.{creation_time_millis}.{fbclid}
            // CRITICAL: Preserve fbclid case - do not call filterMetaId here
            // as it would sanitize valid characters
            var cleanFbclid = String(fbclid).replace(/[^a-zA-Z0-9._-]/g, '');
            if (cleanFbclid) {
              fbc = 'fb.1.' + Date.now() + '.' + cleanFbclid;
            }
          }
        } catch (e) {
          // URLSearchParams not supported
        }
      }
      if (fbc) { ids.fbc = fbc; }

      // Get _fbp from cookie
      var fbp = filterMetaId(readCookie('_fbp'));
      if (fbp) { ids.fbp = fbp; }

      return ids;
    }

    function sanitizePixelId(value) {
      var digits = String(value || '').replace(/\D+/g, '');
      return digits.length >= 5 ? digits : '';
    }

    function loadPixel() {
      var pixelId = sanitizePixelId('{$pixel_id}');
      if (!pixelId) { return; }

      // If another script has already created fbq (e.g. legacy snippet or a plugin),
      // do not overwrite it. Still disable automatic configuration and ensure our pixel is initialized.
      if (typeof window.fbq === 'function') {
        try {
          // Disable Meta's automatic configuration / auto-detected events (incl. microdata/button auto-events).
          // Use string 'false' per Meta's documented examples.
          fbq('set', 'autoConfig', 'false', pixelId);
          fbq('init', pixelId);

          var identifiers = buildMetaIds();
          if (identifiers.fbc) { fbq('set', 'fbc', identifiers.fbc); }
          if (identifiers.fbp) { fbq('set', 'fbp', identifiers.fbp); }
        } catch (e) {}
        return;
      }

      window.fbq = function(){
        var args = Array.prototype.slice.call(arguments || []);
        if (args[0] === 'init') {
          var cleanId = sanitizePixelId(args[1]);
          if (!cleanId) { return; }
          if (initializedPixels[cleanId]) { return; }
          initializedPixels[cleanId] = true;
          args[1] = cleanId;
        }
        return fbq.callMethod ? fbq.callMethod.apply(fbq, args) : fbq.queue.push(args);
      };
      if (!window._fbq) { window._fbq = fbq; }
      fbq.push = fbq;
      fbq.loaded = true;
      fbq.version = '2.0';
      fbq.queue = [];

      var script = document.createElement('script');
      script.async = true;
      script.src = 'https://connect.facebook.net/en_US/fbevents.js';
      var firstScript = document.getElementsByTagName('script')[0];
      firstScript.parentNode.insertBefore(script, firstScript);

      // Disable Meta automatic configuration / auto-detected events.
      fbq('set', 'autoConfig', 'false', pixelId);

      // Initialize pixel.
      fbq('init', pixelId);

      // Provide explicit fbc/fbp values for attribution.
      var identifiers = buildMetaIds();
      if (identifiers.fbc) { fbq('set', 'fbc', identifiers.fbc); }
      if (identifiers.fbp) { fbq('set', 'fbp', identifiers.fbp); }
    }


function maybeBootstrapPixel() {
      if (hasAdConsent(getConsentState())) {
        loadPixel();
        tryFlushQueue();
      }
    }

    function normalizeNumber(value) {
      var num = typeof value === 'number' ? value : parseFloat(value);
      return isFinite(num) ? num : undefined;
    }

    /**
     * Build contents array for Meta Pixel events.
     * FIX: Added content_name extraction for ViewContent events.
     */
    function buildContents(items, contentType) {
      var contents = [];
      var ids = [];
      var names = [];
      
      (items || []).forEach(function(item) {
        var id = item && (item.item_id || item.item_name || item.id);
        if (!id) { return; }
        
        var entry = {
          id: String(id),
          quantity: normalizeNumber(item.quantity) || 1
        };
        
        var price = normalizeNumber(item.price);
        if (price !== undefined) {
          entry.item_price = price;
        }
        
        contents.push(entry);
        ids.push(String(id));
        
        // FIX: Collect item names for content_name
        if (item.item_name) {
          names.push(String(item.item_name));
        }
      });

      var payload = {};
      if (contents.length) {
        payload.contents = contents;
        payload.content_ids = ids;
        payload.content_type = contentType || 'product';
        
        // FIX: Add content_name for better event matching
        if (names.length) {
          payload.content_name = names.join(', ');
        }
      }

      return payload;
    }

    function applyMetaIdentifiers(meta) {
      if (!meta || typeof fbq !== 'function') {
        return;
      }
      if (meta.fbc) {
        fbq('set', 'fbc', String(meta.fbc));
      }
      if (meta.fbp) {
        fbq('set', 'fbp', String(meta.fbp));
      }
    }

    function dispatchMetaEvent(eventName, payload) {
      if (typeof fbq !== 'function') {
        queuedEvents.push([eventName, payload]);
        return;
      }

      var params = {};
      if (payload && payload.ecommerce) {
        var ecommerce = payload.ecommerce;
        var contentType = payload.event === 'view_item_list' ? 'product_group' : 'product';
        Object.assign(params, buildContents(ecommerce.items, contentType));
        
        if (ecommerce.currency) {
          params.currency = ecommerce.currency;
        }
        if (typeof ecommerce.value !== 'undefined') {
          params.value = normalizeNumber(ecommerce.value);
        }
        
        // Add transaction_id for purchase deduplication
        if (ecommerce.transaction_id) {
          params.order_id = ecommerce.transaction_id;
        }
      }

      if (payload && payload.meta) {
        applyMetaIdentifiers(payload.meta);
      }

      var options = {};
      if (payload && payload.event_id) {
        options.eventID = payload.event_id;
      }

      fbq('track', eventName, params, options);
    }

    function tryFlushQueue() {
      if (flushTimer) {
        return;
      }
      flushTimer = setInterval(function() {
        if (typeof fbq !== 'function') {
          return;
        }
        clearInterval(flushTimer);
        flushTimer = null;
        if (!queuedEvents.length) {
          return;
        }
        var pending = queuedEvents.slice();
        queuedEvents.length = 0;
        pending.forEach(function(entry) {
          dispatchMetaEvent(entry[0], entry[1]);
        });
      }, 400);
    }

    function handleDataLayerEvent(payload) {
      if (!payload || typeof payload !== 'object') {
        return;
      }
      
      // Handle consent update events
      if (payload.event === 'rg_consent_update' || payload.event === 'gtm_consent_update') {
        maybeBootstrapPixel();
        return;
      }
      
      // Map GA4 events to Meta Pixel events
      switch (payload.event) {
        case 'view_item':
          dispatchMetaEvent('ViewContent', payload);
          break;
        case 'view_item_list':
          dispatchMetaEvent('ViewCategory', payload);
          break;
        case 'add_to_cart':
          dispatchMetaEvent('AddToCart', payload);
          break;
        case 'begin_checkout':
          dispatchMetaEvent('InitiateCheckout', payload);
          break;
        case 'purchase':
          dispatchMetaEvent('Purchase', payload);
          break;
        default:
          return;
      }
    }

    /**
     * Wrap dataLayer.push to intercept events for Meta Pixel.
     * FIX: Single wrapper function instead of double-wrapping.
     */
    function wrapDataLayer() {
      if (dataLayerWrapped) {
        return;
      }
      
      window.dataLayer = window.dataLayer || [];
      
      // Process any existing events
      for (var i = 0; i < window.dataLayer.length; i++) {
        handleDataLayerEvent(window.dataLayer[i]);
      }
      
      // Wrap push method
      var originalPush = window.dataLayer.push;
      if (typeof originalPush !== 'function') {
        return;
      }
      
      window.dataLayer.push = function() {
        var args = Array.prototype.slice.call(arguments || []);
        
        // Process each event for Meta Pixel
        for (var i = 0; i < args.length; i++) {
          handleDataLayerEvent(args[i]);
        }
        
        // Call original push
        return originalPush.apply(this, args);
      };
      
      dataLayerWrapped = true;
    }

    // Initialize
    maybeBootstrapPixel();
    wrapDataLayer();
    tryFlushQueue();
  })();
</script>
<!-- End Meta Pixel -->
HTML;

    echo $script;
}
