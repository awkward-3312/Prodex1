(function () {
  'use strict';

  if (window.__prodexOrganizationNavigationInstalled) return;
  window.__prodexOrganizationNavigationInstalled = true;

  var permissions = { branches: false, employeeAccess: false, stockIntake: false };

  function apiGet(url) {
    if (!window.axios) return Promise.reject(new Error('Axios no disponible'));
    return window.axios.get(url, {
      baseURL: '',
      meta: { skipErrorRedirect: true, skipInitialLoader: true }
    });
  }

  function discover() {
    return Promise.all([
      apiGet('/api/organization/branches').then(function () { permissions.branches = true; }).catch(function () {}),
      apiGet('/api/organization/employee-access').then(function () { permissions.employeeAccess = true; }).catch(function () {}),
      apiGet('/api/transfer-logistics/incoming').then(function () { permissions.stockIntake = true; }).catch(function () {})
    ]);
  }

  function navList() {
    return document.querySelector('.vertical-sidebar-wrapper .vertical-nav-menu > .nav-list');
  }

  function iconSvg(kind) {
    if (kind === 'users') return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
    if (kind === 'stock') return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m21 8-9-5-9 5 9 5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 16 9 5 9-5"/></svg>';
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/><path d="M8 9h.01"/><path d="M12 9h.01"/><path d="M16 9h.01"/></svg>';
  }

  function makeItem(id, label, href, icon, section, order, moduleKey) {
    var li = document.createElement('li');
    li.id = id;
    // prodex-nav-clone intentionally keeps these explicit, capability-gated links
    // out of the legacy module classifier so their order never changes after clicks.
    li.className = 'nav-item prodex-nav-clone';
    li.dataset.prodexV3ModuleFixed = moduleKey;
    li.dataset.prodexV3Module = moduleKey;
    li.dataset.prodexV3Section = section;
    li.dataset.prodexV3Order = String(order);
    li.style.setProperty('--prodex-v3-order', String(order));
    li.innerHTML = '<a class="nav-link" href="' + href + '" title="' + label + '"><span class="nav-icon" aria-hidden="true">' + iconSvg(icon) + '</span><span class="nav-text">' + label + '</span></a>';
    return li;
  }

  function ensureOne(config) {
    if (!config.allowed) {
      var old = document.getElementById(config.id);
      if (old) old.remove();
      return;
    }
    var list = navList();
    if (!list) return;
    var item = document.getElementById(config.id);
    if (!item) {
      item = makeItem(config.id, config.label, config.href, config.icon, config.section, config.order, config.module);
      list.appendChild(item);
    }
    item.classList.add('prodex-nav-clone');
    item.dataset.prodexV3ModuleFixed = config.module;
    item.dataset.prodexV3Section = config.section;
    item.dataset.prodexV3Order = String(config.order);
    item.style.setProperty('--prodex-v3-order', String(config.order));
  }

  function ensure() {
    ensureOne({
      allowed: permissions.stockIntake,
      id: 'prodex-stock-intake-nav', label: 'Ingreso de stock', href: '/app/operations/stock-intake', icon: 'stock',
      section: 'commercial', order: 2001.5, module: 'stock_intake'
    });
    ensureOne({
      allowed: permissions.branches,
      id: 'prodex-organization-branches-nav', label: 'Sucursales', href: '/app/organization/branches', icon: 'branch',
      section: 'admin', order: 8001.5, module: 'organization'
    });
    ensureOne({
      allowed: permissions.employeeAccess,
      id: 'prodex-organization-access-nav', label: 'Acceso de empleados', href: '/app/organization/employee-access', icon: 'users',
      section: 'admin', order: 8001.7, module: 'employee_access'
    });
  }

  function init() {
    discover().then(function () {
      ensure();
      [100, 350, 900, 1800].forEach(function (delay) { window.setTimeout(ensure, delay); });
      var root = document.querySelector('.vertical-sidebar-wrapper');
      if (root) {
        new MutationObserver(function () { window.setTimeout(ensure, 20); })
          .observe(root, { childList: true, subtree: true });
      }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
