/*! Ratna Gems – AJAX forms handler */
(function(){
  "use strict";

  var config = window.rgForms || {};
  var ajaxUrl = typeof config.ajaxUrl === "string" ? config.ajaxUrl : "";
  var restUrl = typeof config.restUrl === "string" ? config.restUrl : "";
  var nonces = config.nonces || {};
  var actions = config.actions || {};
  var messages = config.messages || {};

  var SUBSCRIBE_FORM_SELECTORS = ["form#rg-subscribe-form", "form[data-rg-subscribe]"];
  var SUBSCRIBE_CLICK_SELECTORS = [
    "a[data-rg-subscribe]",
    "a#rg-subscribe-btn",
    "a.rg-subscribe-btn",
    ".newsletter a[href=\"#\"]",
    ".subscribe a[href=\"#\"]"
  ];
  var CONTACT_FORM_SELECTORS = ["form.rg-contact-form", "form[data-rg-contact]"];
  var CONTACT_CLICK_SELECTORS = [
    "a[data-rg-contact]",
    "a#rg-contact-submit",
    "a.rg-contact-submit",
    ".contact a[href^=\"mailto:\"]",
    ".contact-form a[href^=\"mailto:\"]"
  ];

  function onReady(cb){
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", function handler(){
        document.removeEventListener("DOMContentLoaded", handler);
        cb();
      });
    } else {
      cb();
    }
  }

  function getMatchesFn(element){
    return element.matches || element.msMatchesSelector || element.webkitMatchesSelector || null;
  }

  function matchesSelector(element, selector){
    if (!element || element.nodeType !== 1 || !selector) {
      return false;
    }
    var fn = getMatchesFn(element);
    if (!fn) {
      return false;
    }
    try {
      return fn.call(element, selector);
    } catch (err) {
      return false;
    }
  }

  function matchesAny(element, selectors){
    if (!element || !selectors || !selectors.length) {
      return false;
    }
    for (var i = 0; i < selectors.length; i++) {
      if (matchesSelector(element, selectors[i])) {
        return true;
      }
    }
    return false;
  }

  function closestElement(element, selectors){
    var el = element;
    while (el && el !== document) {
      if (matchesAny(el, selectors)) {
        return el;
      }
      el = el.parentElement;
    }
    return null;
  }

  function getMessage(key, fallback){
    var value = messages && typeof messages[key] === "string" ? messages[key] : null;
    if (value && value.trim) {
      var trimmed = value.trim();
      if (trimmed) {
        return trimmed;
      }
    }
    return fallback || "";
  }

  function ensureStatusElement(form){
    if (!form) {
      return null;
    }
    var existing = form.querySelector("[data-rg-status]");
    if (existing) {
      return existing;
    }
    var el = document.createElement("p");
    el.setAttribute("data-rg-status", "1");
    el.setAttribute("aria-live", "polite");
    el.className = "rg-form-status";
    el.textContent = "";
    form.appendChild(el);
    return el;
  }

  function setStatus(form, message, isError){
    var statusEl = ensureStatusElement(form);
    if (!statusEl) {
      return;
    }
    if (!message) {
      statusEl.removeAttribute("data-rg-state");
      statusEl.textContent = "";
      return;
    }
    statusEl.setAttribute("data-rg-state", isError ? "error" : "success");
    statusEl.textContent = message;
  }

  function setLoading(form, trigger, loading){
    if (!form) {
      return;
    }
    var fields = form.querySelectorAll("input, button, select, textarea");
    for (var i = 0; i < fields.length; i++) {
      var field = fields[i];
      if (loading) {
        if (!field.hasAttribute("data-rg-disabled")) {
          field.setAttribute("data-rg-disabled", field.disabled ? "1" : "0");
        }
        field.disabled = true;
      } else {
        var wasDisabled = field.getAttribute("data-rg-disabled");
        if (wasDisabled === "0") {
          field.disabled = false;
        }
        field.removeAttribute("data-rg-disabled");
      }
    }
    if (trigger && trigger.tagName && trigger.tagName.toLowerCase() === "a") {
      if (loading) {
        trigger.setAttribute("aria-disabled", "true");
      } else {
        trigger.removeAttribute("aria-disabled");
      }
    }
  }

  function getFieldValue(form, selector){
    if (!form) {
      return "";
    }
    var field = form.querySelector(selector);
    if (!field) {
      return "";
    }
    var value = typeof field.value === "string" ? field.value : "";
    return value.trim ? value.trim() : value;
  }

  function collectSubscribePayload(form){
    var payload = new FormData();
    payload.append("action", actions.subscribe || "add_new_subscriber");
    payload.append("security", (nonces && nonces.subscribe) || "");
    payload.append("subscriber_email", getFieldValue(form, "[name='subscriber_email']") || getFieldValue(form, "[name='email']"));
    payload.append("subscriber_name", getFieldValue(form, "[name='subscriber_name']") || getFieldValue(form, "[name='name']"));
    return payload;
  }

  function collectContactPayload(form){
    return {
      name: getFieldValue(form, "[name='name']"),
      email: getFieldValue(form, "[name='email']"),
      subject: getFieldValue(form, "[name='subject']"),
      message: getFieldValue(form, "[name='message']"),
      rg_hp: getFieldValue(form, "[name='rg_hp']")
    };
  }

  function request(url, options){
    var opts = options || {};
    if (typeof window.fetch === "function") {
      return window.fetch(url, opts);
    }
    return new Promise(function(resolve, reject){
      var xhr = new XMLHttpRequest();
      xhr.open(opts.method || "GET", url, true);
      if (opts.credentials === "include" || opts.credentials === "same-origin") {
        xhr.withCredentials = true;
      }
      var headers = opts.headers || {};
      for (var key in headers) {
        if (Object.prototype.hasOwnProperty.call(headers, key)) {
          xhr.setRequestHeader(key, headers[key]);
        }
      }
      xhr.onreadystatechange = function(){
        if (xhr.readyState === 4) {
          var response = {
            ok: xhr.status >= 200 && xhr.status < 300,
            status: xhr.status,
            statusText: xhr.statusText,
            url: url,
            headers: {
              get: function(name){
                return xhr.getResponseHeader(name);
              }
            },
            text: function(){
              return Promise.resolve(xhr.responseText);
            },
            json: function(){
              if (!xhr.responseText) {
                return Promise.resolve({});
              }
              try {
                return Promise.resolve(JSON.parse(xhr.responseText));
              } catch (error) {
                return Promise.reject(error);
              }
            }
          };
          resolve(response);
        }
      };
      xhr.onerror = function(){
        reject(new Error("Network Error"));
      };
      xhr.send(opts.body || null);
    });
  }

  function findRelevantForm(element, type){
    if (!element) {
      return null;
    }
    if (element.tagName && element.tagName.toLowerCase() === "form") {
      return element;
    }
    var form = closestElement(element, ["form"]);
    if (form) {
      return form;
    }
    var extraSelectors = type === "contact" ? ["[data-rg-contact]", ".contact", ".contact-form"] : ["[data-rg-subscribe]", ".newsletter", ".subscribe"];
    var container = closestElement(element, extraSelectors);
    if (container) {
      var candidate = container.querySelector("form");
      if (candidate) {
        return candidate;
      }
    }
    if (type === "contact") {
      return document.querySelector("form.rg-contact-form");
    }
    return document.querySelector("form#rg-subscribe-form");
  }

  function handleSubscribe(form, trigger){
    if (!ajaxUrl) {
      setStatus(form, getMessage("subscribeError", "Subscription is currently unavailable."), true);
      return;
    }
    var loadingMessage = getMessage("loading", "Sending…");
    if (loadingMessage) {
      setStatus(form, loadingMessage, false);
    }
    setLoading(form, trigger, true);
    var payload = collectSubscribePayload(form);
    request(ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: payload
    })
      .then(function(response){
        return response.json().then(function(data){
          return { response: response, data: data };
        }, function(){
          return { response: response, data: null };
        });
      })
      .then(function(result){
        var data = result ? result.data : null;
        var success = false;
        var hasExplicit = false;
        var message = "";
        if (data && typeof data === "object") {
          if (typeof data.success === "boolean") {
            success = data.success;
            hasExplicit = true;
          }
          if (typeof data.data === "string" && data.data) {
            message = data.data;
          } else if (typeof data.message === "string" && data.message) {
            message = data.message;
          }
        }
        if (!hasExplicit && result && result.response && result.response.ok) {
          success = true;
        }
        if (success) {
          setStatus(form, message || getMessage("subscribeSuccess", "Thank you for subscribing!"), false);
          if (typeof form.reset === "function") {
            form.reset();
          }
          // --- FIX START: Push event to DataLayer ---
          window.dataLayer = window.dataLayer || [];
          window.dataLayer.push({
            'event': 'newsletter_subscribe',
            'event_id': 'rg_sub_' + (new Date().getTime()),
            'newsletter.location': form.id === 'rg-subscribe-form' ? 'footer' : 'popup',
            'newsletter.method': 'form',
            'newsletter.marketing_opt_in': true
          });
          // --- FIX END ---
        } else {
          setStatus(form, message || getMessage("subscribeError", "Could not process your subscription."), true);
        }
      })
      .catch(function(){
        setStatus(form, getMessage("networkError", "A network error occurred. Please try again."), true);
      })
      .then(function(){
        setLoading(form, trigger, false);
      });
  }

  function handleContact(form, trigger){
    if (!restUrl) {
      setStatus(form, getMessage("contactError", "Contact form is currently unavailable."), true);
      return;
    }
    var loadingMessage = getMessage("loading", "Sending…");
    if (loadingMessage) {
      setStatus(form, loadingMessage, false);
    }
    setLoading(form, trigger, true);
    var payload = collectContactPayload(form);
    var headers = {
      "Content-Type": "application/json"
    };
    if (nonces && nonces.contact) {
      headers["X-RG-Nonce"] = nonces.contact;
    }
    request(restUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: headers,
      body: JSON.stringify(payload)
    })
      .then(function(response){
        return response.json().then(function(data){
          return { response: response, data: data };
        }, function(){
          return { response: response, data: null };
        });
      })
      .then(function(result){
        var data = result ? result.data : null;
        var success = false;
        var hasExplicit = false;
        var message = "";
        if (data && typeof data === "object") {
          if (typeof data.success === "boolean") {
            success = data.success;
            hasExplicit = true;
          }
          if (typeof data.message === "string" && data.message) {
            message = data.message;
          }
        }
        if (!hasExplicit && result && result.response) {
          success = !!(result.response.ok);
        }
        if (success) {
          setStatus(form, message || getMessage("contactSuccess", "Thank you! Your message has been sent."), false);
          if (typeof form.reset === "function") {
            form.reset();
          }
        } else {
          setStatus(form, message || getMessage("contactError", "We could not send your message. Please try again."), true);
        }
      })
      .catch(function(){
        setStatus(form, getMessage("networkError", "A network error occurred. Please try again."), true);
      })
      .then(function(){
        setLoading(form, trigger, false);
      });
  }

  function handleInteraction(type, element, event){
    if (!type) {
      return;
    }
    if (event) {
      event.preventDefault();
    }
    var form = findRelevantForm(element, type);
    if (!form) {
      return;
    }
    if (type === "subscribe") {
      handleSubscribe(form, element);
    } else if (type === "contact") {
      handleContact(form, element);
    }
  }

  function cleanQuantityPlaceholders(scope){
    var root = scope && scope.querySelectorAll ? scope : document;
    var containers = root.querySelectorAll ? root.querySelectorAll(".quantity") : [];
    for (var i = 0; i < containers.length; i++) {
      var container = containers[i];
      var qtyInput = container.querySelector("input.qty");
      if (!qtyInput) {
        continue;
      }
      var attrType = qtyInput.getAttribute("type");
      var inputType = typeof qtyInput.type === "string" ? qtyInput.type.toLowerCase() : "";
      if (typeof attrType === "string" && attrType) {
        attrType = attrType.toLowerCase();
      }
      var isHidden = inputType === "hidden" || attrType === "hidden" || qtyInput.hasAttribute("hidden");
      if (!isHidden) {
        continue;
      }
      var placeholders = container.querySelectorAll(".ast-qty-placeholder");
      for (var j = 0; j < placeholders.length; j++) {
        var placeholder = placeholders[j];
        if (placeholder && placeholder.parentNode === container) {
          container.removeChild(placeholder);
        }
      }
    }
  }

  onReady(function(){
    document.addEventListener("submit", function(event){
      var target = event.target;
      if (!(target && target.tagName && target.tagName.toLowerCase() === "form")) {
        return;
      }
      var type = "";
      if (matchesAny(target, SUBSCRIBE_FORM_SELECTORS) || closestElement(target, ["[data-rg-subscribe]"])) {
        type = "subscribe";
      } else if (matchesAny(target, CONTACT_FORM_SELECTORS) || closestElement(target, ["[data-rg-contact]"])) {
        type = "contact";
      }
      if (!type) {
        return;
      }
      handleInteraction(type, target, event);
    });

    document.addEventListener("click", function(event){
      var target = event.target;
      if (!target) {
        return;
      }
      var trigger = closestElement(target, SUBSCRIBE_CLICK_SELECTORS);
      if (trigger) {
        handleInteraction("subscribe", trigger, event);
        return;
      }
      trigger = closestElement(target, CONTACT_CLICK_SELECTORS);
      if (trigger) {
        handleInteraction("contact", trigger, event);
      }
    });

    cleanQuantityPlaceholders(document);
    setTimeout(function(){ cleanQuantityPlaceholders(document); }, 250);

    var cartForm = document.querySelector(".woocommerce div.product form.cart");
    if (cartForm && typeof MutationObserver !== "undefined") {
      var observer = new MutationObserver(function(mutations){
        var needsUpdate = false;
        for (var i = 0; i < mutations.length; i++) {
          var mutation = mutations[i];
          if (mutation.type === "attributes") {
            needsUpdate = true;
            break;
          }
          if (mutation.type === "childList") {
            if (mutation.addedNodes && mutation.addedNodes.length) {
              needsUpdate = true;
              break;
            }
            if (mutation.removedNodes && mutation.removedNodes.length) {
              needsUpdate = true;
              break;
            }
          }
        }
        if (needsUpdate) {
          cleanQuantityPlaceholders(cartForm);
        }
      });
      observer.observe(cartForm, { childList: true, subtree: true, attributes: true, attributeFilter: ["type", "class", "hidden"] });
    }
  });
})();