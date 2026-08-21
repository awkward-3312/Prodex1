(function () {
  'use strict';

  if (window.__prodexNavigationIdentityInstalled) return;
  window.__prodexNavigationIdentityInstalled = true;

  var observer = null;
  var scheduled = null;

  // navigation-v3 detects modules from descendant hrefs. Some Vue submenus are
  // rendered lazily, so their hrefs do not exist until the user expands them.
  // Add one invisible, permanent identity href to each top-level module based
  // on its visible label. navigation-v3 can then classify it consistently
  // before and after a submenu is opened, without this script changing order.
  var identityHrefByLabel = {
    'tablero': '/app/dashboard',
    'operaciones': '/app/sales/__prodex_module',
    'ventas': '/app/sales/__prodex_module',
    'inventario': '/app/products/__prodex_module',
    'productos': '/app/products/__prodex_module',
    'clientes y proveedores': '/app/people/__prodex_module',
    'gente': '/app/people/__prodex_module',
    'tienda en linea': '/app/store/__prodex_module',
    'tienda': '/app/store/__prodex_module',
    'gestion de personal': '/app/hrm/__prodex_module',
    'hrm': '/app/hrm/__prodex_module',
    'reclutamiento': '/app/recruit/__prodex_module',
    'reuniones': '/app/meeting/__prodex_module',
    'marketing': '/app/marketing/__prodex_module',
    'contabilidad': '/app/accounting/__prodex_module',
    'suscripciones': '/app/subscription_product/__prodex_module',
    'producto de suscripcion': '/app/subscription_product/__prodex_module',
    'servicio y mantenimiento': '/app/service/__prodex_module',
    'servicios y mantenimiento': '/app/service/__prodex_module',
    'activos': '/app/assets/__prodex_module',
    'proyectos': '/app/projects/__prodex_module',
    'contratos': '/app/contracts/__prodex_module',
    'tareas': '/app/tasks/__prodex_module',
    'reservas': '/app/bookings/__prodex_module',
    'citas': '/app/bookings/__prodex_module',
    'comisiones': '/app/commissions/__prodex_module',
    'configuracion de woocommerce': '/app/woocommerce/__prodex_module',
    'woocommerce': '/app/woocommerce/__prodex_module',
    'configuracion de shopify': '/app/shopify/__prodex_module',
    'shopify': '/app/shopify/__prodex_module',
    'base de conocimientos': '/app/knowledge-base/__prodex_module',
    'whatsapp': '/app/whatsapp/__prodex_module',
    'reportes con ia': '/app/reports/ai_reports',
    'reportes': '/app/reports/__prodex_module',
    'usuarios y acceso': '/app/user_management/__prodex_module',
    'gestion de usuarios': '/app/user_management/__prodex_module',
    'configuraciones': '/app/settings/__prodex_module',
    'configuracion': '/app/settings/__prodex_module',
    'plan y facturacion': '/app/billing/__prodex_module',
    'facturacion': '/app/billing/__prodex_module',
    'soporte': '/app/support/__prodex_module'
  };

  function norm(value) {
    return String(value || '')
      .trim()
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '');
  }

  function topItems() {
    var list = document.querySelector('.vertical-sidebar-wrapper .vertical-nav-menu > .nav-list');
    if (!list) return [];
    return Array.prototype.slice.call(list.children).filter(function (el) {
      return el && el.classList && el.classList.contains('nav-item') && !el.classList.contains('prodex-nav-clone');
    });
  }

  function labelFor(li) {
    var node = li.querySelector(':scope > .nav-link .nav-text');
    return norm(node ? node.textContent : '');
  }

  function ensureIdentityAnchors() {
    topItems().forEach(function (li) {
      var href = identityHrefByLabel[labelFor(li)];
      if (!href) return;

      var existing = li.querySelector(':scope > a[data-prodex-module-identity]');
      if (existing) {
        if (existing.getAttribute('href') !== href) existing.setAttribute('href', href);
        return;
      }

      var anchor = document.createElement('a');
      anchor.setAttribute('data-prodex-module-identity', '1');
      anchor.setAttribute('href', href);
      anchor.setAttribute('aria-hidden', 'true');
      anchor.setAttribute('tabindex', '-1');
      anchor.style.display = 'none';
      li.appendChild(anchor);
    });
  }

  function schedule() {
    if (scheduled) window.clearTimeout(scheduled);
    scheduled = window.setTimeout(ensureIdentityAnchors, 0);
  }

  function installObserver() {
    if (observer || !document.body) return;
    observer = new MutationObserver(function (mutations) {
      if (mutations.some(function (m) { return m.type === 'childList'; })) schedule();
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  document.addEventListener('DOMContentLoaded', function () {
    ensureIdentityAnchors();
    installObserver();
  }, { once: true });

  [0, 20, 60, 120, 250, 500, 1000, 1800].forEach(function (delay) {
    window.setTimeout(function () {
      ensureIdentityAnchors();
      installObserver();
    }, delay);
  });
})();
