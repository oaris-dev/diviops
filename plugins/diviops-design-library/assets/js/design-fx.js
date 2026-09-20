/**
 * Divi Design Library — Frontend Effects
 * Lightweight IntersectionObserver-based entrance animations.
 */
(function () {
  'use strict';

  function forEachNode(nodes, callback) {
    Array.prototype.forEach.call(nodes, callback);
  }

  // Wait for DOM ready.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  function init() {
    setupEntranceAnimations();
    injectGooeyFilter();
    setupImageReveals();
  }

  /**
   * Optional native Image wipe. No hidden state before a loaded image is visible.
   * Observe after load so native lazy loading cannot consume the reveal early.
   */
  function setupImageReveals() {
    if (document.querySelector('#et-fb-app, .et-fb')) return;
    if (!('IntersectionObserver' in window) || !window.matchMedia ||
        !window.CSS || !window.CSS.supports ||
        !window.CSS.supports('clip-path', 'inset(0 0 0 0)')) return;

    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    if (reducedMotion.matches) return;

    forEachNode(document.querySelectorAll('.et_pb_image.ddl-image-reveal'), function (module) {
      var img = module.querySelector('.et_pb_image_wrap img');
      if (!img || img.closest('.et_pb_image') !== module) return;

      var observer;
      var timer;
      var started = false;
      var finished = false;

      function cleanup() {
        finished = true;
        if (observer) observer.disconnect();
        window.clearTimeout(timer);
        img.removeEventListener('load', onLoad);
        img.removeEventListener('error', cleanup);
        img.removeEventListener('animationend', onAnimationDone);
        img.removeEventListener('animationcancel', onAnimationDone);
        img.classList.remove('ddl-image-reveal-active');
      }

      function onAnimationDone(event) {
        if (event.target === img && event.animationName === 'ddl-image-reveal') cleanup();
      }

      function onLoad() {
        if (finished || observer) return;
        img.removeEventListener('load', onLoad);
        if (!img.naturalWidth) {
          cleanup();
          return;
        }

        observer = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (finished || started || !entry.isIntersecting) return;
            if (reducedMotion.matches || document.querySelector('#et-fb-app, .et-fb')) {
              cleanup();
              return;
            }
            started = true;
            observer.disconnect();
            img.addEventListener('animationend', onAnimationDone);
            img.addEventListener('animationcancel', onAnimationDone);
            img.classList.add('ddl-image-reveal-active');
            // Also clean up if CSS is absent or no animation event is delivered.
            timer = window.setTimeout(cleanup, 750);
          });
        }, { threshold: 0 });
        observer.observe(img);
      }

      img.addEventListener('error', cleanup);
      if (img.complete) {
        onLoad();
      } else {
        img.addEventListener('load', onLoad);
      }
    });
  }

  /**
   * Entrance animations via IntersectionObserver.
   * Add class "ddl-animate ddl-fade-up" (or ddl-fade-in, ddl-scale-in, etc.)
   * to any Divi module via CSS Classes in the VB.
   * The element starts hidden (opacity:0) and animates in when scrolled into view.
   */
  function setupEntranceAnimations() {
    var elements = document.querySelectorAll('.ddl-animate');
    if (!elements.length) return;

    // Fallback for old browsers: just show everything.
    if (!('IntersectionObserver' in window)) {
      forEachNode(elements, function (el) {
        el.classList.add('ddl-visible');
      });
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('ddl-visible');
          observer.unobserve(entry.target);
        }
      });
    }, {
      threshold: 0.15,
      rootMargin: '0px 0px -40px 0px'
    });

    elements.forEach(function (el) {
      observer.observe(el);
    });
  }
  /**
   * Inject SVG filter for gooey text morph effect.
   * Only added when .ddl-gooey-wrap is present on the page.
   */
  function injectGooeyFilter() {
    if (!document.querySelector('.ddl-gooey-wrap')) return;
    if (document.getElementById('ddl-gooey-filter')) return;
    if (!document.body) return;

    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    var defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
    var filter = document.createElementNS('http://www.w3.org/2000/svg', 'filter');
    var colorMatrix = document.createElementNS('http://www.w3.org/2000/svg', 'feColorMatrix');

    svg.setAttribute('style', 'position:absolute;height:0;width:0');
    svg.setAttribute('aria-hidden', 'true');

    filter.setAttribute('id', 'ddl-gooey-filter');
    colorMatrix.setAttribute('in', 'SourceGraphic');
    colorMatrix.setAttribute('type', 'matrix');
    colorMatrix.setAttribute('values', '1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  0 0 0 255 -140');

    filter.appendChild(colorMatrix);
    defs.appendChild(filter);
    svg.appendChild(defs);
    document.body.appendChild(svg);
  }
})();
