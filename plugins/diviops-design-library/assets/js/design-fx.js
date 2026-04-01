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
