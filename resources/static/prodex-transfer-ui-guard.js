(function () {
  'use strict';

  // Hotfix — los widgets globales de Traslados (logística / incidencias / workflow)
  // NO deben vivir sobre páginas de error o públicas. Este guard compartido:
  //   1. expone window.__pxTransferUiSuppressed() para que cada script se
  //      auto-silencie en esos contextos;
  //   2. hace teardown de cualquier botón/panel/overlay/modal de Traslados que
  //      haya quedado montado al navegar a not_authorize / NotFound / login.
  //
  // No intercepta ningún endpoint. No hace polling. Sólo observa el DOM/ruta.

  if (window.__pxTransferUiGuardInstalled) return;
  window.__pxTransferUiGuardInstalled = true;

  // IDs / selectores que crean los tres scripts de Traslados.
  var WIDGET_IDS = [
    'px-transfer-logistics-btn',
    'px-transfer-logistics-panel',
    'px-tl-overlay',
    'px-tl-toast',
    'px-transfer-issues-btn',
    'px-ti-overlay',
    'px-transfer-workflow-overlay',
    'px-transfer-workflow-hint'
  ];
  var WIDGET_SELECTORS = [
    '.px-ti-btn',
    '.px-ti-overlay',
    '.px-twf-overlay',
    '.px-tl-panel',
    '.px-tl-overlay',
    '.px-tl-toast'
  ];

  function pathIsPublicOrError() {
    var p = (window.location && window.location.pathname) || '';
    // not_authorize es una ruta SPA top-level (/not_authorize). Login/logout,
    // recuperación de contraseña y setup son páginas Blade públicas.
    // NB: no se marca "/" ni transiciones — sólo rutas de error/públicas
    // conocidas, para no ocultar el widget durante el redirect inicial.
    if (/^\/not_authorize\/?$/i.test(p)) return true;
    if (/^\/(login|logout|password|register|setup)(\/|$)/i.test(p)) return true;
    return false;
  }

  function domIsErrorPage() {
    // NotAuthorize.vue y notFound.vue renderizan .not-found-wrap con <h1 class="text-60">.
    // NotFound usa path "*" (no cambia la URL), así que hace falta el marcador DOM.
    return !!document.querySelector('.not-found-wrap');
  }

  function suppressed() {
    return pathIsPublicOrError() || domIsErrorPage();
  }

  window.__pxTransferUiSuppressed = suppressed;

  function teardown() {
    var i;
    for (i = 0; i < WIDGET_IDS.length; i += 1) {
      var byId = document.getElementById(WIDGET_IDS[i]);
      if (byId) byId.remove();
    }
    for (i = 0; i < WIDGET_SELECTORS.length; i += 1) {
      var nodes = document.querySelectorAll(WIDGET_SELECTORS[i]);
      for (var j = 0; j < nodes.length; j += 1) {
        if (nodes[j] && nodes[j].parentNode) nodes[j].parentNode.removeChild(nodes[j]);
      }
    }
  }

  function tick() {
    if (suppressed()) teardown();
  }

  function boot() {
    tick();
    try {
      var observer = new MutationObserver(tick);
      observer.observe(document.documentElement, { childList: true, subtree: true });
    } catch (e) { /* noop */ }
    // Respaldo ligero por si el observer no captura una transición SPA.
    setInterval(tick, 800);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
