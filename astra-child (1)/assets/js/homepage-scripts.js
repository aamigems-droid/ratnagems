/*! Ratna Gems – Homepage interactions (no jQuery) */
(function(){
  "use strict";

  var homepageConfig = window.ratnaGemsHomepageConfig || {};
  var strings = homepageConfig.strings || {};

  function getString(key, fallback){
    var value = strings && typeof strings[key] === "string" ? strings[key] : fallback;
    return value || fallback;
  }

  function formatString(key, fallback, replacement){
    var template = getString(key, fallback);
    var value = String(replacement);
    return template.replace(/%d/g, value).replace(/%s/g, value);
  }

  function getNewsletterTracker(){
    var tracker = window.RatnaGemsNewsletter && window.RatnaGemsNewsletter.track;
    return typeof tracker === 'function' ? tracker : null;
  }

  function trackNewsletterSubmission(form, email, name, location){
    if (!email) {
      return;
    }
    var tracker = getNewsletterTracker();
    if (!tracker) {
      return;
    }
    tracker({
      email: email,
      name: name || '',
      location: location || (form && form.getAttribute('data-rg-source')) || 'homepage_subscription',
      method: 'ajax_form',
      marketingOptIn: true
    });
  }

  // ---------- tiny helpers ----------
  var qs = function(s,c){return (c||document).querySelector(s)};
  var qsa = function(s,c){return Array.prototype.slice.call((c||document).querySelectorAll(s))};
  var clamp = function(v,min,max){return Math.max(min,Math.min(max,v))};
  var now = function(){return (window.performance&&performance.now)?performance.now():Date.now()};
  var clearChildren = function(el){
    if(!el) return;
    while(el.firstChild){
      el.removeChild(el.firstChild);
    }
  };

  // Compute current translateX (robust)
  function getTX(el){
    var tr=(getComputedStyle(el).transform||"matrix(1,0,0,1,0,0)");
    try{
      var M=window.DOMMatrix||window.WebKitCSSMatrix;
      if(!M) return 0;
      var m=new M(tr);
      return m.m41||0;
    } catch(_){
      var m=tr.match(/matrix\(([^)]+)\)/);
      if(!m) return 0;
      var p=m[1].split(",");
      var tx=p.length>=5?parseFloat(p[4]):0;
      return isNaN(tx)?0:tx;
    }
  }

  // Momentum animation
  function animateMomentum(opts){
    var from=opts.from, v=opts.velocity, min=opts.min, max=opts.max;
    var dur = typeof opts.duration==="number" ? opts.duration : 350;
    var ease = opts.easing || function(t){ return 1 - Math.pow(1 - t, 3); }; // easeOutCubic
    var target = clamp(from + v*dur, min, max);
    var start = now(), id=0;
    function frame(){
      var t = clamp((now()-start)/dur, 0, 1);
      var x = from + (target-from)*ease(t);
      opts.onUpdate(x);
      if(t<1){ id=requestAnimationFrame(frame) } else { opts.onEnd && opts.onEnd(target) }
    }
    id=requestAnimationFrame(frame);
    return function(){ cancelAnimationFrame(id) };
  }

  // Observe visibility (pause autoplay when off-screen)
  function makeInViewportObserver(el, cb){
    if(!('IntersectionObserver' in window)){ cb(true); return {disconnect:function(){}}; }
    var io = new IntersectionObserver(function(entries){
      entries.forEach(function(ent){ cb(ent.isIntersecting); });
    }, {threshold:0.15});
    io.observe(el);
    return io;
  }

  // ---------- HERO SLIDER ----------
  function initHeroSlider(){
    var slider = qs(".final-slider");
    if(!slider || slider.getAttribute("data-rg-init")==="1") return;
    slider.setAttribute("data-rg-init","1");

    var track = qs(".final-slider__track", slider);
    var prev  = qs(".final-slider__arrow--prev", slider);
    var next  = qs(".final-slider__arrow--next", slider);
    var dotsC = qs(".final-slider__dots", slider);
    if(!track || !prev || !next || !dotsC) return;

    // Provide explicit landmark roles for dots per WP accessibility guidance. @link https://developer.wordpress.org/themes/functionality/accessibility/
    dotsC.setAttribute("role","tablist");

    var realSlides = qsa(".final-slider__slide", track);
    var N = realSlides.length;
    if(N<=1){
      prev.style.display="none";
      next.style.display="none";
      dotsC.style.display="none";
      return;
    }

    // dots
    clearChildren(dotsC);
    var dots=[];
    for(var i=0;i<N;i++){
      var b=document.createElement("button");
      b.type="button";
      b.className="final-slider__dot";
      // Make each dot operable via assistive tech. @link https://developer.wordpress.org/themes/functionality/accessibility/
      b.setAttribute("role","tab");
      b.setAttribute("aria-label", formatString("goToSlide", "Go to slide %d", i+1));
      dotsC.appendChild(b);
      dots.push(b);
    }

    // clones for looping
    var firstClone = realSlides[0].cloneNode(true);
    var lastClone  = realSlides[N-1].cloneNode(true);
    firstClone.setAttribute("data-clone","1");
    lastClone.setAttribute("data-clone","1");
    track.appendChild(firstClone);
    track.insertBefore(lastClone, track.firstChild);

    var slides = qsa(".final-slider__slide", track);
    var index = 1; // first real
    var width = slider.getBoundingClientRect().width;
    var x = -index*width;
    track.style.transform = "translate3d("+x+"px,0,0)";
    var dragging=false, moved=false, startX=0, startT=0, base=0, cancelMo=null;
    var DRAG_THRESHOLD=16, playing=false, timer=0;

    function setByIndex(withTr){
      x = -index*width;
      track.style.transition = withTr? "transform .45s ease":"none";
      track.style.transform  = "translate3d("+x+"px,0,0)";
      updateDots();
    }
    function jumpIfClone(){
      if(index===0){ track.style.transition="none"; index=N; setByIndex(false); }
      else if(index===slides.length-1){ track.style.transition="none"; index=1; setByIndex(false); }
    }
    // Prevent aria-hidden slides from trapping focus, satisfying WP accessibility guidance. @link https://developer.wordpress.org/themes/functionality/accessibility/
    function setSlideFocusState(slide, active){
      if(!slide) return;
      if(active){
        slide.removeAttribute("inert");
      } else {
        slide.setAttribute("inert", "");
      }
      var focusables = slide.querySelectorAll("a, button, input, textarea, select, [tabindex]");
      focusables.forEach(function(node){
        if(active){
          if(node.dataset && node.dataset.rgPrevTab){
            node.setAttribute("tabindex", node.dataset.rgPrevTab);
            delete node.dataset.rgPrevTab;
          } else {
            node.removeAttribute("tabindex");
          }
          node.removeAttribute("aria-hidden");
        } else {
          if(node.hasAttribute("tabindex")){
            if(node.dataset){ node.dataset.rgPrevTab = node.getAttribute("tabindex") || ""; }
          }
          node.setAttribute("tabindex","-1");
          node.setAttribute("aria-hidden","true");
        }
      });
    }

    realSlides.forEach(function(slide, idx){
      if(!slide.id){ slide.id = "rg-hero-slide-" + (idx+1); }
    });

    function updateDots(){
      var realIdx = (index-1+N)%N;
      dots.forEach(function(d,k){
        if(k===realIdx){ d.classList.add("active"); d.setAttribute("aria-current","true"); }
        else { d.classList.remove("active"); d.removeAttribute("aria-current"); }
        if(realSlides[k]){
          d.setAttribute("aria-controls", realSlides[k].id);
        }
      });
      slides.forEach(function(s,k){
        var active=(k===index);
        s.setAttribute("aria-hidden", active?"false":"true");
        if(active) s.removeAttribute("tabindex"); else s.setAttribute("tabindex","-1");
        setSlideFocusState(s, active);
      });
    }
    function prevCmd(){ if(dragging) return; index--; setByIndex(true); }
    function nextCmd(){ if(dragging) return; index++; setByIndex(true); }
    track.addEventListener("transitionend", function(){ jumpIfClone(); updateDots(); });

    // autoplay robust
    function canPlay(){ return slider.offsetParent!==null; }
    function play(){ if(playing || !canPlay()) return; playing=true; timer=setInterval(nextCmd, 5000); }
    function pause(){ playing=false; if(timer){ clearInterval(timer); timer=0; } }
    document.addEventListener("visibilitychange", function(){ document.hidden?pause():play(); });
    makeInViewportObserver(slider, function(v){ v?play():pause(); });

    // drag
    function down(e){
      if(e.pointerType==="mouse" && e.button!==0) return;
      if(cancelMo) cancelMo();
      dragging=true; moved=false; startX=e.clientX; startT=now(); base=getTX(track);
      track.style.transition="none"; pause();
      slider.classList.add("is-dragging");
      if(typeof e.pointerId === "number" && track.setPointerCapture){
        try{ track.setPointerCapture(e.pointerId); }catch(err){}
      }
    }
    function move(e){
      if(!dragging) return;
      var dx=e.clientX-startX;
      if(!moved && Math.abs(dx)>DRAG_THRESHOLD) moved=true;
      x=base+dx;
      track.style.transform="translate3d("+x+"px,0,0)";
    }
    function up(e){
      if(!dragging) return;
      dragging=false;
      slider.classList.remove("is-dragging");
      if(e && typeof e.pointerId === "number" && track.releasePointerCapture){
        try{ track.releasePointerCapture(e.pointerId); }catch(err){}
      }
      var dx=x-base, dt=Math.max(1,now()-startT), v=dx/dt;
      var threshold=width*0.18, flick=Math.abs(v)>0.5;
      if(dx<-threshold || (flick&&v<0)) index++; else if(dx>threshold || (flick&&v>0)) index--;
      var minX = -(slides.length-1)*width, maxX = 0;
      if(cancelMo) cancelMo();
      cancelMo = animateMomentum({
        from:x, velocity:v, min:minX, max:maxX, duration:300,
        onUpdate:function(val){ x=val; track.style.transform="translate3d("+val+"px,0,0)"; },
        onEnd:function(){ index = Math.round(-x/width); setByIndex(true); play(); }
      });
    }

    var hasPointer = !!window.PointerEvent;
    if(hasPointer){
      track.addEventListener("pointerdown",down,{passive:true});
      window.addEventListener("pointermove",move,{passive:true});
      window.addEventListener("pointerup",up,{passive:true});
      window.addEventListener("pointercancel",up,{passive:true});
    }else{
      track.addEventListener("touchstart",function(e){down(e.touches[0])},{passive:true});
      track.addEventListener("touchmove",function(e){move(e.touches[0])},{passive:true});
      track.addEventListener("touchend",up,{passive:true});
      track.addEventListener("mousedown",function(e){down(e)},{passive:true});
      window.addEventListener("mousemove",move);
      window.addEventListener("mouseup",up);
    }

    prev.addEventListener("click",prevCmd);
    next.addEventListener("click",nextCmd);
    dots.forEach(function(d,i2){ d.addEventListener("click",function(){ index=i2+1; setByIndex(true); }); });

    function onResize(){ width=slider.getBoundingClientRect().width; track.style.transition="none"; setByIndex(false); }
    window.addEventListener("resize", onResize);

    // Wait for layout then start
    (function wait(i){
      if(slider.getBoundingClientRect().width>0){ setByIndex(false); updateDots(); play(); }
      else if(i<40){ requestAnimationFrame(function(){ wait(i+1); }); }
      else { setTimeout(function(){ setByIndex(false); updateDots(); play(); }, 300); }
    })(0);
  }

  // ---------- RUDRAKSHA TOGGLE ----------
  function initRudrakshaToggle(){
    var btn = qs("#rudraksha-toggle-btn");
    var more = qs("#rudraksha-more");
    if(!btn || !more) return;
    btn.addEventListener("click", function(){
      var open = btn.getAttribute("aria-expanded")==="true";
      if(open){
        more.setAttribute("hidden",""); btn.setAttribute("aria-expanded","false");
      btn.querySelector("span").textContent=getString("showMoreRudraksha","Show More Rudraksha");
      }else{
        more.removeAttribute("hidden"); btn.setAttribute("aria-expanded","true");
        btn.querySelector("span").textContent=getString("showLess","Show Less");
      }
    });
  }


  function pointerX(event){
    if(!event) return 0;
    if(typeof event.clientX === 'number') return event.clientX;
    if(event.touches && event.touches[0]) return event.touches[0].clientX;
    if(event.changedTouches && event.changedTouches[0]) return event.changedTouches[0].clientX;
    return 0;
  }

  function pointerY(event){
    if(!event) return 0;
    if(typeof event.clientY === 'number') return event.clientY;
    if(event.touches && event.touches[0]) return event.touches[0].clientY;
    if(event.changedTouches && event.changedTouches[0]) return event.changedTouches[0].clientY;
    return 0;
  }

  function initBraceletCarousel(){
    var wrapper = qs('.bracelet-slider-wrapper');
    if(!wrapper || wrapper.getAttribute('data-rg-init') === '1'){
      return;
    }
    wrapper.setAttribute('data-rg-init','1');

    var track = qs('.bracelet-slider__track', wrapper);
    if(!track){
      return;
    }

    var slides = qsa('.bracelet-slider__slide', track);
    if(!slides.length){
      return;
    }

    var prev = qs('.bracelet-slider__arrow--prev', wrapper);
    var next = qs('.bracelet-slider__arrow--next', wrapper);
    var toggleBtn = document.getElementById('bracelet-toggle-btn');
    var fullGrid = document.getElementById('bracelet-full-grid');

    function perView(){ return window.innerWidth >= 1024 ? 3 : window.innerWidth >= 768 ? 2 : 1; }
    function slideW(){ if(!slides[0]) return 0; var rect = slides[0].getBoundingClientRect(); return (rect.width || 0) + 10; }
    function centerOffset(){ if(perView() === 1 && slides[0]){ var wrapRect = wrapper.getBoundingClientRect(); var slideRect = slides[0].getBoundingClientRect(); return (wrapRect.width - slideRect.width) / 2; } return 0; }
    function maxIdx(){ return Math.max(0, slides.length - perView()); }
    function sliderVisible(){ return !fullGrid || fullGrid.hasAttribute('hidden'); }

    var idx = 0;
    var x = centerOffset();
    var dragging = false;
    var moved = false;
    var dragMoved = false;
    var startX = 0;
    var startY = 0;
    var startT = 0;
    var base = 0;
    var cancelMo = null;
    var playing = false;
    var timer = 0;
    var activePointerId = null;
    var DRAG_THRESHOLD = 16;

    function updateNav(){
      if(prev){ prev.disabled = idx <= 0; }
      if(next){ next.disabled = idx >= maxIdx(); }
    }

    function setSlideFocusState(slide, active){
      if(!slide) return;
      if(active){ slide.removeAttribute('inert'); }
      else { slide.setAttribute('inert',''); }
      var focusables = slide.querySelectorAll('a, button, input, textarea, select, [tabindex]');
      focusables.forEach(function(node){
        if(active){
          if(node.dataset && node.dataset.rgPrevTab){
            node.setAttribute('tabindex', node.dataset.rgPrevTab);
            delete node.dataset.rgPrevTab;
          } else {
            node.removeAttribute('tabindex');
          }
          node.removeAttribute('aria-hidden');
        } else {
          if(node.hasAttribute('tabindex')){
            if(node.dataset){ node.dataset.rgPrevTab = node.getAttribute('tabindex') || ''; }
          }
          node.setAttribute('tabindex','-1');
          node.setAttribute('aria-hidden','true');
        }
      });
    }

    function updateAria(){
      var start = idx;
      var end = idx + perView() - 1;
      slides.forEach(function(slide, i){
        var active = i >= start && i <= end;
        slide.setAttribute('aria-hidden', active ? 'false' : 'true');
        if(active){ slide.removeAttribute('tabindex'); }
        else { slide.setAttribute('tabindex','-1'); }
        setSlideFocusState(slide, active);
      });
    }

    function setPosition(value, withTransition){
      x = value;
      track.style.transition = withTransition ? 'transform .45s ease' : 'none';
      track.style.transform = 'translate3d(' + value + 'px,0,0)';
    }

    function moveTo(i, withTransition){
      idx = clamp(i, 0, maxIdx());
      var offset = -(idx * slideW()) + centerOffset();
      setPosition(offset, withTransition);
      updateNav();
      updateAria();
    }

    function canPlay(){
      return !dragging && sliderVisible() && wrapper.offsetParent !== null && slideW() > 0;
    }

    function play(){
      if(playing || !canPlay()) return;
      playing = true;
      timer = window.setInterval(function(){
        var nextIdx = idx + 1;
        if(nextIdx > maxIdx()) nextIdx = 0;
        moveTo(nextIdx, true);
      }, 4000);
    }

    function pause(){
      playing = false;
      if(timer){
        window.clearInterval(timer);
        timer = 0;
      }
    }

    document.addEventListener('visibilitychange', function(){ document.hidden ? pause() : play(); });
    makeInViewportObserver(wrapper, function(inView){ inView ? play() : pause(); });

    function handleDown(event){
      if(event && event.pointerType === 'mouse' && event.button !== 0){
        return;
      }
      if(cancelMo){ cancelMo(); }
      dragging = true;
      moved = false;
      dragMoved = false;
      startX = pointerX(event);
      startY = pointerY(event);
      startT = now();
      base = getTX(track);
      x = base;
      track.style.transition = 'none';
      pause();
      wrapper.classList.add('is-dragging');
      activePointerId = (event && typeof event.pointerId === 'number') ? event.pointerId : null;
      if(activePointerId !== null && track.setPointerCapture){
        try { track.setPointerCapture(activePointerId); } catch (error) {}
      }
    }

    function handleMove(event){
      if(!dragging){ return; }
      var dx = pointerX(event) - startX;
      var dy = pointerY(event) - startY;
      if(!moved && Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > DRAG_THRESHOLD){
        dragMoved = true;
        dragging = false;
        wrapper.classList.remove('is-dragging');
        releasePointer(event);
        play();
        window.setTimeout(function(){ dragMoved = false; }, 120);
        return;
      }
      if(!moved && Math.abs(dx) > DRAG_THRESHOLD){
        moved = true;
        dragMoved = true;
      }
      x = base + dx;
      track.style.transform = 'translate3d(' + x + 'px,0,0)';
    }

    function releasePointer(event){
      var pointerId = null;
      if(event && typeof event.pointerId === 'number'){ pointerId = event.pointerId; }
      else if(activePointerId !== null){ pointerId = activePointerId; }
      if(pointerId !== null && track.releasePointerCapture){
        try { track.releasePointerCapture(pointerId); } catch (error) {}
      }
      activePointerId = null;
    }

    function handleUp(event){
      if(!dragging){ return; }
      dragging = false;
      wrapper.classList.remove('is-dragging');
      releasePointer(event);
      var dx = x - base;
      var dt = Math.max(1, now() - startT);
      var velocity = dx / dt;
      var threshold = slideW() * 0.18;
      var flick = Math.abs(velocity) > 0.5;
      if(dx < -threshold || (flick && velocity < 0)){ idx++; }
      else if(dx > threshold || (flick && velocity > 0)){ idx--; }
      var minX = -(maxIdx() * slideW()) + centerOffset();
      var maxX = 0 + centerOffset();
      if(cancelMo){ cancelMo(); }
      cancelMo = animateMomentum({
        from: x,
        velocity: velocity,
        min: minX,
        max: maxX,
        duration: 320,
        onUpdate: function(val){
          x = val;
          track.style.transform = 'translate3d(' + val + 'px,0,0)';
        },
        onEnd: function(){
          var projected = -(x - centerOffset());
          moveTo(clamp(Math.round(projected / slideW()), 0, maxIdx()), true);
          play();
        }
      });
      moved = false;
      window.setTimeout(function(){ dragMoved = false; }, 80);
    }

    var hasPointer = !!window.PointerEvent;
    if(hasPointer){
      track.addEventListener('pointerdown', handleDown, { passive: true });
      window.addEventListener('pointermove', handleMove, { passive: true });
      window.addEventListener('pointerup', handleUp, { passive: true });
      window.addEventListener('pointercancel', handleUp, { passive: true });
    } else {
      track.addEventListener('touchstart', handleDown, { passive: true });
      track.addEventListener('touchmove', handleMove, { passive: true });
      track.addEventListener('touchend', handleUp, { passive: true });
      track.addEventListener('mousedown', handleDown, { passive: true });
      window.addEventListener('mousemove', handleMove);
      window.addEventListener('mouseup', handleUp);
    }

    track.addEventListener('dragstart', function(e){ e.preventDefault(); });
    track.addEventListener('click', function(e){ if(dragMoved){ e.preventDefault(); e.stopPropagation(); } }, true);

    if(prev){ prev.addEventListener('click', function(){ moveTo(idx - 1, true); }); }
    if(next){ next.addEventListener('click', function(){ moveTo(idx + 1, true); }); }

    if(toggleBtn && fullGrid){
      toggleBtn.addEventListener('click', function(){
        var expanded = toggleBtn.getAttribute('aria-expanded') === 'true';
        var labelNode = toggleBtn.querySelector('span');
        if(expanded){
          fullGrid.setAttribute('hidden','');
          wrapper.style.display = 'block';
          toggleBtn.setAttribute('aria-expanded','false');
          if(labelNode){ labelNode.textContent = getString('showAllBracelets','Show All Bracelets'); }
          requestAnimationFrame(function(){ moveTo(idx, false); play(); });
        } else {
          pause();
          wrapper.style.display = 'none';
          fullGrid.removeAttribute('hidden');
          toggleBtn.setAttribute('aria-expanded','true');
          if(labelNode){ labelNode.textContent = getString('showLess','Show Less'); }
        }
      });
    } else if(fullGrid){
      fullGrid.setAttribute('hidden','');
    }

    window.addEventListener('resize', function(){
      track.style.transition = 'none';
      moveTo(idx, false);
    });

    (function wait(i){
      if(slideW() > 0){
        moveTo(0, false);
        play();
      } else if(i < 40){
        requestAnimationFrame(function(){ wait(i + 1); });
      } else {
        setTimeout(function(){ moveTo(0, false); play(); }, 300);
      }
    })(0);
  }


  // ---------- Newsletter AJAX (WP localized object expected) ----------
  function initSubscriptionForm(){
    var form = qs(".subscription-form"), wrap = qs(".subscription-form-wrapper");
    if(!form || !wrap) return;
    form.setAttribute('data-rg-source','homepage_subscription');
    function flash(msg,type){
      var old = wrap.querySelector(".form-message"); if(old) old.remove();
      var el = document.createElement("div"); el.className="form-message "+type; el.textContent=msg;
      form.parentNode.insertBefore(el, form.nextSibling);
      setTimeout(function(){ el.style.opacity="0"; setTimeout(function(){ el.remove(); }, 500); }, 4000);
    }
    form.addEventListener("submit", function(e){
      e.preventDefault();
      var btn=form.querySelector("button"); var txt=btn.textContent; btn.disabled=true; btn.textContent=getString("subscriptionSubmitting","Submitting...");
      var emailField = form.querySelector("input[name='subscriber_email']");
      var nameField = form.querySelector("input[name='subscriber_name']");
      var emailValue = emailField && typeof emailField.value === 'string' ? emailField.value.trim() : '';
      var nameValue = nameField && typeof nameField.value === 'string' ? nameField.value.trim() : '';
      var fd=new FormData(form);
      if(!homepageConfig.nonce || !homepageConfig.ajaxUrl){
        flash(getString("subscriptionUnavailable","Subscription is currently unavailable."), "error");
        btn.disabled=false; btn.textContent=txt; return;
      }
      fd.append("action","add_new_subscriber"); fd.append("security", homepageConfig.nonce);
      fetch(homepageConfig.ajaxUrl, {method:"POST", body:fd})
        .then(function(r){return r.json()})
        .then(function(r){
          if(r && r.success){ flash(getString("subscriptionSuccess","Success! You are now subscribed."),"success"); form.reset(); }
          else { var msg=(r&&r.data&&typeof r.data==='string')?r.data:getString("subscriptionError","An error occurred. Please try again."); flash(msg,"error"); }
          if(r && r.success){
            trackNewsletterSubmission(form, emailValue, nameValue, 'homepage_subscription');
          }
        })
        .catch(function(){ flash(getString("networkError","A network error occurred. Please check your connection."),"error"); })
        .finally(function(){ setTimeout(function(){ btn.disabled=false; btn.textContent=txt; }, 800); });
    });
  }

  // ---------- Boot (Homepage-only inits) ----------
  function initAll(){
    initHeroSlider();
    initRudrakshaToggle();
    initBraceletCarousel();
    initSubscriptionForm();
    // Contact form logic removed from this bundle — loaded only on Contact page inline.
  }

  if(document.readyState==="loading"){
    document.addEventListener("DOMContentLoaded", initAll, {once:true});
  } else {
    initAll();
  }
})();