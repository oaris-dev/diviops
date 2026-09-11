/* Selected static native FAQ controls; Divi retains activation and animation. */
(function () {
  'use strict';

  const selector = '.et_pb_toggle.et_pb_toggle_item.ddl-faq-a11y';
  const marker = 'data-ddl-faq-a11y-control';
  const interactive = 'a,button,input,select,textarea,summary,details,iframe,object,embed,' +
    'audio[controls],video[controls],[tabindex],[role],[contenteditable]:not([contenteditable="false"])';
  let initialized = false;
  let nextId = 1;

  function setIfChanged(node, name, value) {
    if (node.getAttribute(name) !== value) node.setAttribute(name, value);
  }

  function removeIfPresent(node, name) {
    if (node.hasAttribute(name)) node.removeAttribute(name);
  }

  function semantics(toggle, title, content) {
    if (toggle.hasAttribute('role') || toggle.hasAttribute('aria-expanded') ||
        toggle.hasAttribute('aria-controls') || title.hasAttribute('tabindex') || title.isContentEditable) return null;
    const role = title.getAttribute('role');
    const expanded = title.getAttribute('aria-expanded');
    const controls = title.getAttribute('aria-controls');
    const tabindex = toggle.getAttribute('tabindex');
    if (role === null && expanded === null && controls === null && tabindex === null) return 'native';
    if (role === 'button' && (expanded === 'true' || expanded === 'false') &&
        tabindex === '0' && /^et_pb_toggle_content_\d+$/.test(content.id) && controls === content.id) return 'addon';
    return null;
  }

  function contentId(content) {
    const occupied = new Set();
    document.querySelectorAll('[id]').forEach(function (node) {
      if (node !== content) occupied.add(node.id);
    });
    if (content.id && !/\s/.test(content.id) && !occupied.has(content.id)) return content.id;
    // Bound allocation even on pages with conflicting author-assigned IDs.
    for (let attempt = 0; attempt < 1000; attempt++) {
      const candidate = 'ddl-faq-content-' + nextId++;
      if (!occupied.has(candidate)) return candidate;
    }
    return null;
  }

  function initialize() {
    if (initialized) return;
    initialized = true;
    if (document.querySelector('.et-fb, #et-fb-app') ||
        typeof window.et_pb_toggle_expand !== 'function' ||
        typeof window.et_pb_toggle_collapse !== 'function' ||
        typeof MutationObserver !== 'function') return;

    document.querySelectorAll(selector).forEach(function (toggle) {
      if (toggle.closest('.et_pb_accordion') || toggle.hasAttribute('data-id') ||
          toggle.classList.contains('et_pb_accordion_item')) return;
      const titles = toggle.querySelectorAll(':scope > .et_pb_toggle_title');
      const contents = toggle.querySelectorAll(':scope > .et_pb_toggle_content');
      if (titles.length !== 1 || contents.length !== 1) return;
      const title = titles[0];
      const content = contents[0];
      if (!/^H[1-6]$/.test(title.tagName) || title.querySelector(interactive) ||
          !semantics(toggle, title, content) ||
          toggle.classList.contains('et_pb_toggle_open') === toggle.classList.contains('et_pb_toggle_close')) return;
      const initialId = contentId(content);
      if (!initialId) return;

      const button = document.createElement('button');
      button.type = 'button';
      button.setAttribute(marker, '');
      while (title.firstChild) button.appendChild(title.firstChild);
      title.appendChild(button);

      function normalize(preparedId) {
        const owner = semantics(toggle, title, content);
        const id = preparedId || contentId(content);
        // Do not erase an unrecognized third-party control takeover.
        if (!owner || !id) {
          observer.disconnect();
          return;
        }
        if (owner === 'addon') {
          removeIfPresent(toggle, 'tabindex');
          ['role', 'aria-expanded', 'aria-controls'].forEach(function (name) {
            removeIfPresent(title, name);
          });
        }
        setIfChanged(content, 'id', id);
        setIfChanged(button, 'aria-controls', id);
        setIfChanged(button, 'aria-expanded', String(toggle.classList.contains('et_pb_toggle_open')));
      }

      // Addon initialization writes these attributes together. Observing only
      // these nodes and conditionally writing makes our own notification a no-op.
      const observer = new MutationObserver(function () { normalize(); });
      observer.observe(toggle, { attributes: true, attributeFilter: ['class', 'tabindex', 'role', 'aria-expanded', 'aria-controls'] });
      observer.observe(title, { attributes: true, attributeFilter: ['role', 'tabindex', 'aria-expanded', 'aria-controls'] });
      observer.observe(content, { attributes: true, attributeFilter: ['id'] });
      normalize(initialId);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
  } else {
    initialize();
  }
})();
