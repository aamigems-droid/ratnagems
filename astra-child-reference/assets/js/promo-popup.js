(function () {
  'use strict';

  if (window.__rgPromoInit) return;
  window.__rgPromoInit = true;

  const defaults = {
    delayMs: 60000, 
    snoozeHours: 24,
    coupon: 'SPECIAL10',
    iconUrl: '',
    highlights: [
      'ISO-certified lab reports',
      'Lifetime Authenticity Guarantee',
      '7-Day Return Policy',
      'Original Rudraksha',
      'Since 1995'
    ],
    i18n: {}
  };

  const cfg = window.ratnaGemsPromoPopup || {};
  let coupon = String(cfg.coupon || defaults.coupon);
  if (coupon.length > 48) coupon = coupon.slice(0, 48);
  
  const delayMs = typeof cfg.delayMs === 'number' ? cfg.delayMs : defaults.delayMs;
  const snoozeHours = typeof cfg.snoozeHours === 'number' ? cfg.snoozeHours : defaults.snoozeHours;
  const i18n = cfg.i18n || defaults.i18n;
  const iconUrl = String(cfg.iconUrl || defaults.iconUrl || '');
  const highlightItems = Array.isArray(cfg.highlights) && cfg.highlights.length ? cfg.highlights : defaults.highlights;

  function isSearchLanding() {
    const r = document.referrer || "";
    return /(\.?google\.[a-z.]+|bing\.com|yahoo\.com|duckduckgo\.com)/i.test(r);
  }

  function isFirstPageOfSession() {
    try {
      if (!sessionStorage.getItem('rg_session_started')) {
        sessionStorage.setItem('rg_session_started', '1');
        return true;
      }
    } catch (e) {}
    return false;
  }

  function decodeHtml(html) {
    const txt = document.createElement("textarea");
    txt.innerHTML = html;
    return txt.value;
  }

  function t(key, fallback) {
    const value = i18n && typeof i18n[key] === 'string' ? i18n[key] : fallback;
    return value ? decodeHtml(value) : fallback;
  }

  function format(key, fallback, value) { 
    return t(key, fallback).replace('%s', value); 
  }

  const KEY = 'rg_promo_banner_snooze_until';
  const now = () => Date.now();

  function snoozed() {
    try { 
      return now() < (parseInt(localStorage.getItem(KEY), 10) || 0); 
    } catch (e) { return false; }
  }

  function setSnooze(hours) {
    try { 
      localStorage.setItem(KEY, String(now() + hours * 3600000)); 
    } catch (e) {}
  }

  function createEl(tag, attrs, text) {
    const el = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(key => {
        if ('style' === key && typeof attrs[key] === 'object') Object.assign(el.style, attrs[key]);
        else if (attrs[key] !== undefined && attrs[key] !== null) el.setAttribute(key, attrs[key]);
      });
    }
    if (typeof text === 'string') el.textContent = text;
    return el;
  }

  function createSvgEl(tag, attrs) {
    const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
    if (attrs) {
      Object.keys(attrs).forEach(key => {
        if (attrs[key] !== undefined && attrs[key] !== null) el.setAttribute(key, attrs[key]);
      });
    }
    return el;
  }

  function appendWithHighlight(target, text, highlight, color) {
    if (!text) return;
    const idx = text.indexOf(highlight);
    if (idx === -1) { 
      target.appendChild(document.createTextNode(text)); 
      return; 
    }
    
    if (idx > 0) target.appendChild(document.createTextNode(text.slice(0, idx)));
    
    const strong = createEl('strong');
    strong.style.color = color;
    strong.textContent = highlight;
    target.appendChild(strong);
    
    appendWithHighlight(target, text.slice(idx + highlight.length), highlight, color);
  }

  function buildDialog() {
    const overlay = createEl('div', { id: 'rgOverlay' });
    const dialog = createEl('div', {
      id: 'rgDialog',
      role: 'dialog',
      'aria-modal': 'true',
      'aria-labelledby': 'heading',
      'aria-describedby': 'promoDesc',
      tabindex: '-1'
    });
    
    const close = createEl('button', { id: 'rgClose', 'aria-label': t('close', 'Close promotion'), type: 'button' });
    close.textContent = '×';

    const contentWrapper = createEl('div', { id: 'contentWrapper' });
    const textSection = createEl('div', { id: 'textSection' });

    const mobileHeaderRow = createEl('div', { id: 'mobileHeaderRow', class: 'mobile-only' });
    if (iconUrl) {
        const mIcon = createEl('img', { id: 'mobileIcon', src: iconUrl, alt: 'Icon' });
        mobileHeaderRow.appendChild(mIcon);
    }

    const badge = createEl('span', { id: 'dealBadge' });
    const badgeIcon = createSvgEl('svg', { width: '16', height: '16', viewBox: '0 0 24 24', 'aria-hidden': 'true', focusable: 'false' });
    const badgePath = createSvgEl('path', { d: 'M12 2l2.9 6.26 6.9.6-5.2 4.73 1.55 6.81L12 16.9l-6.15 3.5 1.55-6.81-5.2-4.73 6.9-.6z', fill: 'currentColor' });
    badgeIcon.appendChild(badgePath);
    badge.appendChild(badgeIcon);
    badge.appendChild(document.createTextNode(t('badge', 'Limited Time Offer')));

    mobileHeaderRow.appendChild(badge);
    textSection.appendChild(mobileHeaderRow);

    const desktopBadgeWrapper = createEl('div', { class: 'desktop-only', style: 'width:100%; display:flex;' });
    const desktopBadge = badge.cloneNode(true); 
    desktopBadgeWrapper.appendChild(desktopBadge);
    textSection.appendChild(desktopBadgeWrapper);

    const heading = createEl('h2', { id: 'heading' }, t('heading', 'Save 10% Today'));
    const subheading = createEl('p', { id: 'subheading' }, t('subheading', 'On Lab-Certified Gemstones & Original Rudraksha'));

    const description = createEl('p', { id: 'promoDesc' });
    const noticeTemplate = t('coupon_notice', 'Get 10% OFF on all Gemstones & Rudraksha. Use code %s.');
    const parts = noticeTemplate.split('%s');
    
    appendWithHighlight(description, parts[0] || '', '10% OFF', '#b45309');
    
    const couponStrong = createEl('strong');
    couponStrong.style.color = '#b45309';
    couponStrong.textContent = coupon;
    description.appendChild(couponStrong);
    
    appendWithHighlight(description, parts[1] || '', '10% OFF', '#b45309');

    const copyButton = createEl('button', {
      id: 'couponCopyButton',
      'aria-describedby': 'promoDesc',
      'aria-label': format('coupon_label', 'Copy %s coupon code', coupon),
      type: 'button'
    }, t('copy', 'Copy Code'));

    const couponRow = createEl('div', { id: 'couponRow' });
    const couponInfo = createEl('div', { id: 'couponInfo' });
    
    const couponHintText = t('coupon_hint', 'Use code');
    if (couponHintText) {
      couponInfo.appendChild(createEl('span', { id: 'couponHint' }, couponHintText));
    }
    couponInfo.appendChild(createEl('span', { id: 'couponValue' }, coupon));
    
    const couponHelperText = t('coupon_helper', 'Tap to copy and apply at checkout');
    if (couponHelperText) {
      couponInfo.appendChild(createEl('span', { id: 'couponHelper' }, couponHelperText));
    }
    
    couponRow.appendChild(couponInfo);
    couponRow.appendChild(copyButton);

    textSection.appendChild(heading);
    textSection.appendChild(subheading);
    textSection.appendChild(description);
    textSection.appendChild(couponRow);

    if (highlightItems.length) {
      const mobileHighlightList = createEl('ul', { id: 'mobileHighlights', class: 'mobile-only' });
      highlightItems.slice(0, 3).forEach(item => {
        const li = createEl('li');
        const icon = createEl('span', { class: 'icon', 'aria-hidden': 'true' }, '✔');
        li.appendChild(icon);
        li.appendChild(document.createTextNode(item));
        mobileHighlightList.appendChild(li);
      });
      textSection.appendChild(mobileHighlightList);
    }

    const visualSection = createEl('div', { id: 'visualSection', class: 'desktop-only' });
    const visualCard = createEl('div', { id: 'visualCard' });

    if (iconUrl) {
      const img = createEl('img', { id: 'brandIcon', src: iconUrl, alt: 'Ratna Gems Icon', width: '160', height: '160', decoding: 'async', loading: 'lazy' });
      visualCard.appendChild(img);
    }
    visualSection.appendChild(visualCard);

    if (highlightItems.length) {
        const desktopHighlightList = createEl('ul', { id: 'desktopHighlights' });
        highlightItems.forEach(item => {
          const li = createEl('li');
          const icon = createEl('span', { class: 'icon', 'aria-hidden': 'true' }, '✓'); 
          li.appendChild(icon);
          li.appendChild(document.createTextNode(item));
          desktopHighlightList.appendChild(li);
        });
        visualSection.appendChild(desktopHighlightList);
    }

    contentWrapper.appendChild(visualSection);
    contentWrapper.appendChild(textSection);

    dialog.appendChild(close);
    dialog.appendChild(contentWrapper);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);

    const toast = createEl('div', { id: 'toast', role: 'status', 'aria-live': 'polite' });
    document.body.appendChild(toast);

    return { overlay, dialog, close, copyButton, toast };
  }

  function showToast(toast, message) {
    toast.textContent = message;
    toast.style.display = 'block';
    setTimeout(() => { toast.style.display = 'none'; }, 3000);
  }

  function showPopup() {
    if (document.getElementById('rgOverlay')) return;

    const nodes = buildDialog();
    const { overlay, close, copyButton, toast } = nodes;

    const previousHtmlOverflow = document.documentElement.style.overflow;
    const previousBodyOverflow = document.body.style.overflow;
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';

    function restoreScroll() {
      document.documentElement.style.overflow = previousHtmlOverflow;
      document.body.style.overflow = previousBodyOverflow;
    }

    const onMessage = (messageKey, fallback) => format(messageKey, fallback, coupon);

    let closed = false;
    function closeAll() {
      if (closed) return;
      closed = true;
      setSnooze(snoozeHours);
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
      if (toast.parentNode) toast.parentNode.removeChild(toast);
      restoreScroll();
    }

    close.addEventListener('click', (e) => { e.preventDefault(); closeAll(); });
    overlay.addEventListener('click', (e) => { if (e.target === overlay) closeAll(); });

    copyButton.addEventListener('click', (e) => {
      e.preventDefault();
      navigator.clipboard.writeText(coupon).then(() => {
        showToast(toast, onMessage('copy_success', 'Copied!'));
        copyButton.textContent = t('copied', 'Copied!');
        setTimeout(() => { copyButton.textContent = t('copy', 'Copy Code'); }, 2000);
      }).catch(() => {
        showToast(toast, t('copy_fail', 'Failed to copy'));
      });
    });
  }

  function start() {
    if (isSearchLanding() && isFirstPageOfSession()) return;
    if (snoozed()) return;
    setTimeout(() => { if (document.body) showPopup(); }, delayMs);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();