/**
 * Cookie Consent Banner for Ratna Gems
 *
 * @version 3.5.0
 *
 * Implements implicit consent via scroll/interaction detection.
 * Manages Google Consent Mode v2 state updates.
 *
 * CHANGELOG v3.5.0:
 * - Improved code documentation
 * - Minor performance optimizations
 */
(function () {
  'use strict';

  // Configuration constants
  var CONSENT_COOKIE = 'rg_consent_status';
  var STORAGE_KEY = 'rgConsentState';
  var COOKIE_TTL_DAYS = 180;
  var BANNER_ID = 'rg-consent-banner';
  var VISIBLE_CLASS = 'rg-consent-banner--visible';
  var ACTIVITY_SCROLL_THRESHOLD = 120; // pixels

  // State
  var cleanupTimer = null;
  var bannerNode = null;
  var activityStarted = false;
  var initialScrollY = null;
  var activityListeners = [];

  // Default denied state for Consent Mode v2
  var DENIED_STATE = {
    ad_storage: 'denied',
    analytics_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    functionality_storage: 'granted',
    security_storage: 'granted',
    wait_for_update: 500
  };

  /**
   * Get current timestamp in milliseconds.
   * @returns {number}
   */
  function now() {
    return Date.now();
  }

  /**
   * Read a cookie by name.
   * @param {string} name - Cookie name
   * @returns {string} Cookie value or empty string
   */
  function readCookie(name) {
    var nameEq = name + '=';
    var cookies;
    try {
      cookies = document.cookie.split(';');
    } catch (error) {
      return '';
    }

    for (var i = 0; i < cookies.length; i++) {
      var cookie = cookies[i].trim();
      if (cookie.indexOf(nameEq) === 0) {
        var value = cookie.substring(nameEq.length);
        try {
          return decodeURIComponent(value);
        } catch (error) {
          return value;
        }
      }
    }
    return '';
  }

  /**
   * Write consent state to cookie.
   * Falls back to localStorage if cookie fails.
   * @param {Object} state - Consent state object
   */
  function writeCookie(state) {
    var expires = new Date(now() + COOKIE_TTL_DAYS * 24 * 60 * 60 * 1000).toUTCString();
    var json = JSON.stringify(state);
    var encoded = encodeURIComponent(json);
    var secure = window.location && window.location.protocol === 'https:' ? ';Secure' : '';

    document.cookie = CONSENT_COOKIE + '=' + encoded + ';expires=' + expires + ';path=/;SameSite=Lax' + secure;

    // Verify cookie was set, use localStorage as fallback
    if (!readCookie(CONSENT_COOKIE)) {
      try {
        window.localStorage.setItem(STORAGE_KEY, json);
      } catch (error) {
        // localStorage unavailable
      }
    } else {
      // Cookie worked, remove any localStorage fallback
      try {
        window.localStorage.removeItem(STORAGE_KEY);
      } catch (error) {
        // localStorage unavailable
      }
    }
  }

  /**
   * Read stored consent state from cookie or localStorage.
   * @returns {Object|null} Consent state or null
   */
  function readStoredState() {
    var cookieValue = readCookie(CONSENT_COOKIE);
    if (cookieValue) {
      try {
        return normalizeState(JSON.parse(cookieValue));
      } catch (error) {
        // Parse failed
      }
    }

    // Try localStorage fallback
    try {
      var localValue = window.localStorage.getItem(STORAGE_KEY);
      if (localValue) {
        return normalizeState(JSON.parse(localValue));
      }
    } catch (error) {
      // localStorage unavailable or parse failed
    }

    return null;
  }

  /**
   * Normalize a consent value to 'granted' or 'denied'.
   * @param {*} value - The value to normalize
   * @param {string} fallback - Fallback value
   * @returns {string} 'granted' or 'denied'
   */
  function normalizeConsentValue(value, fallback) {
    var normalized = String(value || '').toLowerCase();
    if (normalized !== 'granted' && normalized !== 'denied') {
      return fallback || 'denied';
    }
    return normalized;
  }

  /**
   * Normalize a consent state object.
   * @param {Object} state - Raw consent state
   * @returns {Object|null} Normalized state or null
   */
  function normalizeState(state) {
    if (!state || typeof state !== 'object') {
      return null;
    }

    var normalized = {
      ad_storage: normalizeConsentValue(state.ad_storage, 'denied'),
      analytics_storage: normalizeConsentValue(state.analytics_storage, 'denied'),
      ad_user_data: normalizeConsentValue(state.ad_user_data, 'denied'),
      ad_personalization: normalizeConsentValue(state.ad_personalization, 'denied'),
      functionality_storage: normalizeConsentValue(state.functionality_storage, 'granted'),
      security_storage: normalizeConsentValue(state.security_storage, 'granted'),
      timestamp: typeof state.timestamp === 'number' ? state.timestamp : now()
    };

    if (state.wait_for_update) {
      normalized.wait_for_update = state.wait_for_update;
    }

    return normalized;
  }

  /**
   * Build a fully granted consent state.
   * @returns {Object} Consent state with all permissions granted
   */
  function buildGrantedState() {
    return {
      ad_storage: 'granted',
      analytics_storage: 'granted',
      ad_user_data: 'granted',
      ad_personalization: 'granted',
      functionality_storage: 'granted',
      security_storage: 'granted',
      timestamp: now()
    };
  }

  /**
   * Check if a consent value is granted.
   * @param {*} value - The value to check
   * @returns {boolean}
   */
  function isGranted(value) {
    return String(value || '').toLowerCase() === 'granted';
  }

  /**
   * Check if consent state allows tags to fire.
   * @param {Object} state - Consent state
   * @returns {boolean}
   */
  function shouldAllowTags(state) {
    if (!state) {
      return false;
    }

    return (
      isGranted(state.analytics_storage) &&
      isGranted(state.ad_storage) &&
      isGranted(state.ad_user_data) &&
      isGranted(state.ad_personalization)
    );
  }

  /**
   * Update global flag for GTM loader permission.
   * @param {boolean} allow - Whether to allow tags
   */
  function updateLoaderPermission(allow) {
    window.ratnaGemsGtm = window.ratnaGemsGtm || {};
    window.ratnaGemsGtm.forbidden = !allow;
  }

  /**
   * Push consent state update to gtag and dataLayer.
   * @param {Object} state - Consent state
   */
  function pushConsentToGtag(state) {
    if (!state) {
      return;
    }

    window.dataLayer = window.dataLayer || [];

    // Build consent payload with only consent-related keys
    var consentKeys = [
      'ad_storage',
      'analytics_storage',
      'ad_user_data',
      'ad_personalization',
      'functionality_storage',
      'security_storage',
      'wait_for_update'
    ];

    var consentPayload = {};
    for (var i = 0; i < consentKeys.length; i++) {
      var key = consentKeys[i];
      if (state[key] !== undefined) {
        consentPayload[key] = state[key];
      }
    }

    // Update gtag consent
    if (typeof window.gtag === 'function') {
      window.gtag('consent', 'update', consentPayload);

      // Update ads_data_redaction based on full grant status
      var fullyGranted = ['ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization']
        .every(function (key) { return isGranted(state[key]); });
      window.gtag('set', 'ads_data_redaction', !fullyGranted);
    }

    // Push consent events to dataLayer
    var consentUpdateTime = state.timestamp || now();
    var payload = { consentState: state, consentUpdateTime: consentUpdateTime };

    window.dataLayer.push(Object.assign({ event: 'gtm_consent_update' }, payload));
    window.dataLayer.push(Object.assign({ event: 'rg_consent_update' }, payload));
  }

  /**
   * Hide the consent banner.
   */
  function hideBanner() {
    if (!bannerNode) {
      return;
    }
    bannerNode.classList.remove(VISIBLE_CLASS);
    bannerNode.setAttribute('aria-hidden', 'true');
    bannerNode.setAttribute('hidden', 'true');
  }

  /**
   * Show the consent banner.
   */
  function showBanner() {
    if (!bannerNode) {
      return;
    }
    bannerNode.removeAttribute('hidden');
    bannerNode.setAttribute('aria-hidden', 'false');
    requestAnimationFrame(function () {
      bannerNode.classList.add(VISIBLE_CLASS);
    });
  }

  /**
   * Clean up Google's _gl cross-domain linking parameter from URL.
   * This improves URL aesthetics after consent is granted.
   */
  function cleanGlParam() {
    if (typeof window.history.replaceState !== 'function') {
      return;
    }

    try {
      var url = new URL(window.location.href);
      var updated = false;

      // Remove _gl from query string
      if (url.searchParams.has('_gl')) {
        url.searchParams.delete('_gl');
        updated = true;
      }

      // Remove _gl from hash/fragment
      if (url.hash && url.hash.indexOf('_gl=') > -1) {
        var fragment = url.hash.substring(1).split('&');
        var kept = [];
        for (var i = 0; i < fragment.length; i++) {
          if (fragment[i] && fragment[i].indexOf('_gl=') !== 0) {
            kept.push(fragment[i]);
          }
        }
        url.hash = kept.length ? '#' + kept.join('&') : '';
        updated = true;
      }

      if (!updated) {
        return;
      }

      var newUrl = url.pathname + url.search + url.hash;
      window.history.replaceState(null, document.title, newUrl);
    } catch (error) {
      // URL parsing failed
    }
  }

  /**
   * Schedule cleanup of _gl parameter.
   * @param {number} delay - Milliseconds to wait
   */
  function scheduleGlCleanup(delay) {
    if (cleanupTimer) {
      clearTimeout(cleanupTimer);
    }
    cleanupTimer = window.setTimeout(cleanGlParam, typeof delay === 'number' ? delay : 1500);
  }

  /**
   * Apply consent state and trigger updates.
   * @param {Object} state - Consent state
   * @param {Object} options - Options (persist, reason, cleanupDelay)
   */
  function applyState(state, options) {
    var normalized = normalizeState(state) || normalizeState(DENIED_STATE);
    if (!normalized) {
      return;
    }

    options = options || {};

    // Persist to cookie
    if (options.persist !== false) {
      writeCookie(normalized);
    }

    // Update gtag/dataLayer
    pushConsentToGtag(normalized);

    // Push consent method event
    if (options.reason) {
      window.dataLayer = window.dataLayer || [];
      window.dataLayer.push({
        event: 'rg_consent_method',
        consentMethod: options.reason,
        consentUpdateTime: normalized.timestamp
      });
    }

    // Update loader permission
    updateLoaderPermission(shouldAllowTags(normalized));

    // Schedule URL cleanup
    scheduleGlCleanup(options.cleanupDelay || 1200);
  }

  /**
   * Grant full consent (called on user activity).
   * @param {string} reason - Reason for granting (scroll, interaction, etc.)
   */
  
  /**
   * Replay key ecommerce events that may have been pushed to the dataLayer before consent was granted.
   *
   * Why this exists:
   * In a basic consent setup, GTM tags can be blocked until consent is granted. If page-load ecommerce
   * events (e.g., view_item) are pushed before the first consent update, those events would otherwise
   * never fire their GTM tags. We replay the most recent payload per event type once, right after
   * consent is granted.
   */
  function replayPreConsentEcommerceEvents() {
    if (window.__rgReplayDone) {
      return;
    }
    window.__rgReplayDone = true;

    var dl = window.dataLayer;
    if (!dl || !Array.isArray(dl)) {
      return;
    }

    var eventsToReplay = ['view_item', 'view_item_list', 'view_cart', 'begin_checkout', 'purchase'];
    var lastByEvent = {};

    for (var i = 0; i < dl.length; i++) {
      var entry = dl[i];
      if (!entry || typeof entry !== 'object') {
        continue;
      }

      var ev = entry.event;
      if (eventsToReplay.indexOf(ev) === -1) {
        continue;
      }

      // Skip already replayed payloads
      if (entry.rg_replay === true) {
        continue;
      }

      lastByEvent[ev] = entry;
    }

    for (var j = 0; j < eventsToReplay.length; j++) {
      var name = eventsToReplay[j];
      var payload = lastByEvent[name];
      if (!payload) {
        continue;
      }

      var clone;
      try {
        clone = JSON.parse(JSON.stringify(payload));
      } catch (e) {
        clone = Object.assign({}, payload);
      }

      clone.rg_replay = true;
      clone.event = payload.event;

      window.dataLayer.push(clone);
    }
  }

function grantConsent(reason) {
    activityStarted = true;
    removeActivityListeners();

    var granted = buildGrantedState();
    applyState(granted, { persist: true, cleanupDelay: 800, reason: reason || 'implicit_activity' });

    // Replay pre-consent ecommerce events (if any)
    replayPreConsentEcommerceEvents();
    hideBanner();
  }

  /**
   * Remove all activity event listeners.
   */
  function removeActivityListeners() {
    if (!activityListeners.length) {
      return;
    }
    for (var i = 0; i < activityListeners.length; i++) {
      var item = activityListeners[i];
      item.target.removeEventListener(item.type, item.handler, item.options);
    }
    activityListeners = [];
  }

  /**
   * Add an activity event listener with tracking.
   * @param {EventTarget} target - Event target
   * @param {string} type - Event type
   * @param {Function} handler - Event handler
   * @param {Object} options - Event options
   */
  function addActivityListener(target, type, handler, options) {
    target.addEventListener(type, handler, options);
    activityListeners.push({ target: target, type: type, handler: handler, options: options });
  }

  /**
   * Handle scroll activity for implicit consent.
   */
  function handleScrollActivity() {
    if (activityStarted) {
      return;
    }

    if (initialScrollY === null) {
      initialScrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
    }

    var currentY = window.pageYOffset || document.documentElement.scrollTop || 0;
    if (Math.abs(currentY - initialScrollY) >= ACTIVITY_SCROLL_THRESHOLD) {
      activityStarted = true;
      grantConsent('implicit_scroll');
    }
  }

  /**
   * Handle immediate interaction for implicit consent.
   */
  function handleImmediateActivity() {
    if (activityStarted) {
      return;
    }
    activityStarted = true;
    grantConsent('implicit_interaction');
  }

  /**
   * Initialize the consent banner.
   */
  function init() {
    bannerNode = document.getElementById(BANNER_ID);
    if (!bannerNode) {
      return;
    }

    var storedState = readStoredState();

    // If already granted, don't show banner
    if (storedState && shouldAllowTags(storedState)) {
      updateLoaderPermission(true);
      hideBanner();
      return;
    }

    // Show banner and listen for activity
    updateLoaderPermission(false);
    showBanner();

    // Add activity listeners for implicit consent
    addActivityListener(window, 'scroll', handleScrollActivity, { passive: true });
    addActivityListener(document, 'keydown', handleImmediateActivity, { passive: true });
    addActivityListener(document, 'pointerdown', handleImmediateActivity, { passive: true });
    addActivityListener(document, 'touchstart', handleImmediateActivity, { passive: true });
  }

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Schedule cleanup after page load
  if (document.readyState === 'complete') {
    scheduleGlCleanup(1800);
  } else {
    window.addEventListener('load', function () {
      scheduleGlCleanup(1800);
    });
  }
})();
