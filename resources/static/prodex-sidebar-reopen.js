(function () {
  'use strict';

  if (window.__prodexSidebarReopenInstalled) return;
  window.__prodexSidebarReopenInstalled = true;

  var STYLE_ID = 'prodex-sidebar-reopen-style';
  var BUTTON_CLASS = 'prodex-sidebar-reopen';

  function installStyles() {
    if (document.getElementById(STYLE_ID)) return;

    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = [
      '.vertical-sidebar-wrapper .' + BUTTON_CLASS + '{display:none;}',
      '@media(min-width:1025px){',
      '.vertical-sidebar-wrapper.collapsed .' + BUTTON_CLASS + '{',
      'display:flex;position:fixed;left:58px;top:58px;width:30px;height:30px;',
      'align-items:center;justify-content:center;border:1px solid #dbe3ec;border-radius:999px;',
      'background:#fff;color:#64748b;box-shadow:0 4px 12px rgba(15,23,42,.12);',
      'cursor:pointer;z-index:1105;transition:background .15s ease,color .15s ease,border-color .15s ease,transform .15s ease;',
      '}',
      '.vertical-sidebar-wrapper.collapsed .' + BUTTON_CLASS + ':hover{background:#f8fafc;color:var(--primary-color,#38bfd3);border-color:var(--primary-color,#38bfd3);transform:translateX(1px);}',
      '.vertical-sidebar-wrapper.collapsed .' + BUTTON_CLASS + ':focus-visible{outline:3px solid rgba(56,191,211,.2);outline-offset:2px;}',
      '.vertical-sidebar-wrapper.collapsed .' + BUTTON_CLASS + ' svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}',
      'body.dark-theme .vertical-sidebar-wrapper.collapsed .' + BUTTON_CLASS + '{background:#1f1f34;border-color:#363650;color:#cbd5e1;}',
      '}',
    ].join('');
    document.head.appendChild(style);
  }

  function ensureButton() {
    var sidebar = document.querySelector('.vertical-sidebar-wrapper');
    if (!sidebar || sidebar.querySelector('.' + BUTTON_CLASS)) return;

    var button = document.createElement('button');
    button.type = 'button';
    button.className = BUTTON_CLASS;
    button.setAttribute('aria-label', 'Expandir menú');
    button.setAttribute('title', 'Expandir menú');
    button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>';

    button.addEventListener('click', function () {
      var toggle = document.querySelector('.vertical-top-nav .menu-toggle');
      if (toggle) toggle.click();
    });

    sidebar.appendChild(button);
  }

  function schedule() {
    [0, 80, 250, 600, 1200].forEach(function (delay) {
      window.setTimeout(function () {
        installStyles();
        ensureButton();
      }, delay);
    });
  }

  document.addEventListener('DOMContentLoaded', schedule, { once: true });
  window.addEventListener('load', schedule, { once: true });
  schedule();
})();
