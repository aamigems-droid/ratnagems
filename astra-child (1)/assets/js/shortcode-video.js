/**
 * Ratna Gems – High-Performance YouTube Loader (no jQuery)
 * - Click/Enter/Space activates video
 * - Uses youtube-nocookie.com
 * - Honors optional data-start attr
 * - FIXED: Added 'origin' parameter to prevent Error 153
 * - FIXED: Re-indexes gallery thumbnails to fix slide navigation issues (Duplicate data-slide-number="0")
 */

(function () {
  'use strict';

  var config = window.rgShortcodeVideoConfig || {};
  var strings = config.strings || {};

  function t(key, fallback) {
    if (strings && typeof strings[key] === 'string') {
      return strings[key];
    }
    return fallback;
  }

  function activate(wrapper) {
    var vid   = wrapper.getAttribute('data-video-id');
    if (!vid) return;
    var start = parseInt(wrapper.getAttribute('data-start') || '0', 10);
    if (isNaN(start) || start < 0) start = 0;

    // Prevent duplicate iframes
    if (wrapper.querySelector('iframe')) return;

    var params = [
      'autoplay=1',
      'rel=0',
      'modestbranding=1',
      'playsinline=1',
      // CRITICAL FIX: Add origin to satisfy YouTube security (Fixes Error 153)
      'origin=' + window.location.origin
    ];
    
    if (start) params.push('start=' + start);

    var iframe = document.createElement('iframe');
    iframe.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(vid) + '?' + params.join('&');
    iframe.title = t('iframeTitle', 'Product Video');
    iframe.setAttribute('frameborder', '0');
    iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
    iframe.setAttribute('allowfullscreen', '');
    iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');

    // Clean up internal elements (poster, play button, etc.)
    while (wrapper.firstChild) {
      wrapper.removeChild(wrapper.firstChild);
    }
    wrapper.appendChild(iframe);
    wrapper.classList.add('video-activated');
  }

  function handler(e) {
    var wrapper = e.target.closest('.rg-video-wrapper');
    if (!wrapper) return;
    if (e.type === 'click') {
      e.preventDefault();
      activate(wrapper);
    } else if (e.type === 'keydown') {
      var k = e.key;
      if (k === 'Enter' || k === ' ') {
        e.preventDefault();
        activate(wrapper);
      }
    }
  }

  function setFacadeThumb(wrapper) {
    if (wrapper.querySelector('.rg-video-poster')) return;

    var vid = wrapper.getAttribute('data-video-id');
    if (!vid) return;
    var facade = wrapper.querySelector('.rg-video-facade');
    if (!facade) return;

    // Standard fallback only if no LCP poster exists
    var thumb = 'https://i.ytimg.com/vi/' + encodeURIComponent(vid) + '/hqdefault.jpg';
    facade.style.backgroundImage = "url('" + thumb + "')";
  }

  function schedulePosterUpgrade(wrapper) {
    var poster = wrapper.querySelector('.rg-video-poster[data-rg-hires]');
    if (!poster) return;

    var hires = poster.getAttribute('data-rg-hires');
    if (!hires) return;

    var finalSizes = poster.getAttribute('data-rg-final-sizes');

    var upgrade = function () {
      if (!poster || poster.dataset.rgUpgraded === '1') {
        return;
      }

      var srcset = poster.getAttribute('srcset') || '';
      var candidate = hires + ' 1280w';
      if (srcset.indexOf(hires) === -1) {
        srcset = srcset ? srcset + ', ' + candidate : candidate;
        poster.setAttribute('srcset', srcset);
      }

      if (finalSizes) {
        poster.setAttribute('sizes', finalSizes);
      }

      poster.dataset.rgUpgraded = '1';
    };

    var queueUpgrade = function () {
      if (typeof requestIdleCallback === 'function') {
        requestIdleCallback(function () {
          upgrade();
        }, { timeout: 2000 });
      } else {
        setTimeout(upgrade, 1500);
      }
    };

    if (document.readyState === 'complete') {
      queueUpgrade();
    } else {
      window.addEventListener('load', queueUpgrade, { once: true });
    }
  }

  /**
   * Fixes the "Cannot change slide" issue.
   * Based on the provided HTML, the thumbnail wrapper class is '.woocommerce-product-gallery-thumbnails__wrapper'.
   * We target the direct children divs and force sequential numbering to resolve the conflict
   * where both the video and the first image have data-slide-number="0".
   */
  function fixGalleryIndices() {
    var wrapper = document.querySelector('.woocommerce-product-gallery-thumbnails__wrapper');
    if (!wrapper) return;

    // Select direct children divs (thumbnails)
    var thumbs = wrapper.children;
    if (!thumbs.length) return;

    // Convert HTMLCollection to Array to use forEach safely
    Array.prototype.slice.call(thumbs).forEach(function(thumb, index) {
      // Force sequential numbering (0, 1, 2...)
      // This ensures Video=0, Image1=1, Image2=2, etc.
      thumb.setAttribute('data-slide-number', index);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    // 1. Run the Gallery Fix immediately on load
    fixGalleryIndices();

    // 2. Setup Video Players
    var wrappers = document.querySelectorAll('.rg-video-wrapper');
    if (wrappers.length) {
      wrappers.forEach(function (wrapper) {
        setFacadeThumb(wrapper);
        schedulePosterUpgrade(wrapper);
      });
      document.addEventListener('click', handler);
      document.addEventListener('keydown', handler);
    }
  });
})();