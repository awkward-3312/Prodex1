(function () {
  'use strict';

  if (window.__prodexNavigationV3Installed) return;
  window.__prodexNavigationV3Installed = true;

  var STYLE_ID = 'prodex-navigation-v3-style';
  var SEARCH_CLASS = 'prodex-nav-v3-search-hidden';
  var FIRST_CLASS = 'prodex-nav-v3-section-first';
  var observer = null;
  var scheduled = null;

  var groups = [
    { key: 'principal', title: 'Principal', order: 10, modules: ['dashboard'] },
    { key: 'commercial', title: 'Operación del negocio', order: 20, modules: ['sales', 'products', 'people', 'store'] },
    { key: 'team', title: 'Equipo', order: 30, modules: ['hrm', 'recruit', 'meeting'] },
    { key: 'work', title: 'Gestión', order: 40, modules: ['projects', 'tasks', 'contracts', 'service', 'assets', 'bookings'] },
    { key: 'finance', title: 'Finanzas', order: 50, modules: ['accounting', 'commissions', 'subscription_product'] },
    { key: 'growth', title: 'Crecimiento e integraciones', order: 60, modules: ['marketing', 'whatsapp', 'woocommerce', 'shopify'] },
    { key: 'insights', title: 'Análisis', order: 70, modules: ['reports', 'ai_reports'] },
    { key: 'admin', title: 'Administración', order: 80, modules: ['user_management', 'settings', 'billing', 'support', 'knowledge_base'] }
  ];

  var moduleRank = {
    dashboard: 1,
    sales: 1, products: 2, people: 3, store: 4,
    hrm: 1, recruit: 2, meeting: 3,
    projects: 1, tasks: 2, contracts: 3, service: 4, assets: 5, bookings: 6,
    accounting: 1, commissions: 2, subscription_product: 3,
    marketing: 1, whatsapp: 2, woocommerce: 3, shopify: 4,
    reports: 1, ai_reports: 2,
    user_management: 1, settings: 2, billing: 3, support: 4, knowledge_base: 5
  };

  var aliases = {
    dashboard: 'tablero dashboard inicio principal',
    sales: 'ventas sales operaciones operations pos punto de venta compras cotizaciones devoluciones promociones',
    products: 'inventario inventory productos products stock existencias ajustes transferencias traslados daños',
    people: 'clientes proveedores customers suppliers contactos personas people gente',
    store: 'tienda tienda en linea online store ecommerce pedidos en linea',
    hrm: 'gestion de personal recursos humanos human resources empleados asistencia planilla nomina vacaciones hrm',
    recruit: 'reclutamiento recruitment recruit candidatos empleos entrevistas aplicaciones',
    meeting: 'reuniones meetings meeting calendario',
    service: 'servicio mantenimiento service maintenance tecnicos reparacion',
    assets: 'activos assets',
    projects: 'proyectos projects',
    contracts: 'contratos contracts',
    tasks: 'tareas tasks',
    bookings: 'reservas reserva citas cita bookings booking appointments calendario',
    accounting: 'contabilidad accounting finanzas gastos depositos cuentas transferencias diario balance',
    commissions: 'comisiones commissions agentes ventas',
    subscription_product: 'suscripciones subscriptions subscription producto de suscripcion subscription product',
    marketing: 'marketing campanas segmentos plantillas',
    whatsapp: 'whatsapp mensajes plantillas',
    woocommerce: 'woocommerce comercio electronico',
    shopify: 'shopify comercio electronico',
    reports: 'reportes reports informes analisis estadisticas',
    ai_reports: 'reportes con ia reportes ia ai reports inteligencia artificial',
    user_management: 'usuarios acceso permisos roles user management gestion de usuarios',
    settings: 'configuracion configuraciones settings ajustes sistema almacenes cajas moneda backup webhooks',
    billing: 'plan facturacion billing suscripcion pago',
    support: 'soporte support ayuda tickets',
    knowledge_base: 'base de conocimientos knowledge base manual ayuda documentacion'
  };

  var labelModuleMap = {
    'tablero': 'dashboard', 'dashboard': 'dashboard',
    'operaciones': 'sales', 'ventas': 'sales', 'sales': 'sales', 'operations': 'sales',
    'inventario': 'products', 'productos': 'products', 'products': 'products', 'inventory': 'products',
    'clientes y proveedores': 'people', 'gente': 'people', 'people': 'people', 'customers and suppliers': 'people',
    'tienda en linea': 'store', 'tienda': 'store', 'store': 'store', 'online store': 'store',
    'gestion de personal': 'hrm', 'recursos humanos': 'hrm', 'hrm': 'hrm', 'human resources': 'hrm',
    'reclutamiento': 'recruit', 'recruit': 'recruit', 'recruitment': 'recruit',
    'reuniones': 'meeting', 'meeting': 'meeting', 'meetings': 'meeting',
    'proyectos': 'projects', 'projects': 'projects',
    'tareas': 'tasks', 'tasks': 'tasks',
    'contratos': 'contracts', 'contracts': 'contracts',
    'servicio y mantenimiento': 'service', 'servicios y mantenimiento': 'service', 'service & maintenance': 'service', 'service and maintenance': 'service',
    'activos': 'assets', 'assets': 'assets',
    'reservas': 'bookings', 'reserva': 'bookings', 'citas': 'bookings', 'cita': 'bookings', 'bookings': 'bookings', 'booking': 'bookings', 'appointments': 'bookings',
    'contabilidad': 'accounting', 'accounting': 'accounting',
    'comisiones': 'commissions', 'commissions': 'commissions',
    'suscripciones': 'subscription_product', 'producto de suscripcion': 'subscription_product', 'subscription product': 'subscription_product', 'subscriptions': 'subscription_product',
    'marketing': 'marketing',
    'whatsapp': 'whatsapp',
    'configuracion de woocommerce': 'woocommerce', 'woocommerce': 'woocommerce', 'woocommerce settings': 'woocommerce',
    'configuracion de shopify': 'shopify', 'shopify': 'shopify', 'shopify settings': 'shopify',
    'reportes': 'reports', 'reports': 'reports',
    'reportes con ia': 'ai_reports', 'reportes ia': 'ai_reports', 'ai reports': 'ai_reports',
    'usuarios y acceso': 'user_management', 'gestion de usuarios': 'user_management', 'user management': 'user_management',
    'configuracion': 'settings', 'configuraciones': 'settings', 'settings': 'settings',
    'plan y facturacion': 'billing', 'facturacion': 'billing', 'billing': 'billing',
    'soporte': 'support', 'support': 'support', 'support center': 'support',
    'base de conocimientos': 'knowledge_base', 'knowledge base': 'knowledge_base'
  };

  function norm(value) {
    return String(value || '')
      .trim()
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '');
  }

  function installStyles() {
    if (document.getElementById(STYLE_ID)) return;
    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = [
      '.vertical-sidebar-wrapper .vertical-nav-menu>.nav-list{display:flex;flex-direction:column;}',
      '.vertical-sidebar-wrapper .nav-item[data-prodex-v3-order]{order:var(--prodex-v3-order);}',
      '.vertical-sidebar-wrapper .nav-item.' + SEARCH_CLASS + '{display:none!important;}',
      '.vertical-sidebar-wrapper .nav-item.' + FIRST_CLASS + '::before{content:attr(data-prodex-v3-section-title);display:block;margin:18px 5px 7px;padding:0 4px;color:#8a94a6;font-size:10px;line-height:1.2;font-weight:700;letter-spacing:.08em;text-transform:uppercase;}',
      '.vertical-sidebar-wrapper .nav-item.' + FIRST_CLASS + '[data-prodex-v3-section="principal"]::before{margin-top:5px;}',
      '.vertical-sidebar-wrapper .prodex-nav-v3-search-wrap{padding:4px 14px 10px;position:relative;}',
      '.vertical-sidebar-wrapper .prodex-nav-v3-search{width:100%;height:38px;padding:0 34px 0 34px;border:1px solid #e2e8f0;border-radius:9px;background:#f8fafc;color:#334155;font-size:12.5px;outline:none;transition:border-color .15s ease,box-shadow .15s ease,background .15s ease;}',
      '.vertical-sidebar-wrapper .prodex-nav-v3-search::placeholder{color:#94a3b8;}',
      '.vertical-sidebar-wrapper .prodex-nav-v3-search:focus{background:#fff;border-color:var(--primary-color,#38bfd3);box-shadow:0 0 0 3px rgba(56,191,211,.12);}',
      '.vertical-sidebar-wrapper .prodex-nav-v3-search-icon{position:absolute;left:25px;top:15px;width:14px;height:14px;color:#94a3b8;pointer-events:none;}',
      '.vertical-sidebar-wrapper .prodex-nav-v3-search-clear{position:absolute;right:21px;top:9px;width:28px;height:28px;border:0;background:transparent;color:#94a3b8;font-size:18px;line-height:28px;cursor:pointer;display:none;}',
      '.vertical-sidebar-wrapper .prodex-nav-v3-search-wrap.has-value .prodex-nav-v3-search-clear{display:block;}',
      '.vertical-sidebar-wrapper .nav-link{min-height:44px;}',
      '.vertical-sidebar-wrapper .nav-item.active>.nav-link{font-weight:600;}',
      '.vertical-sidebar-wrapper .nav-item.active>.nav-link::after{content:"";position:absolute;left:0;top:9px;bottom:9px;width:3px;border-radius:0 3px 3px 0;background:var(--primary-color,#38bfd3);}',
      '.vertical-sidebar-wrapper.collapsed{width:76px!important;transform:translateX(0)!important;}',
      '.vertical-sidebar-wrapper.collapsed .vertical-sidebar-header{padding-left:15px;padding-right:15px;}',
      '.vertical-sidebar-wrapper.collapsed .header-brand{justify-content:center;}',
      '.vertical-sidebar-wrapper.collapsed .sidebar-logo{width:42px!important;height:42px!important;}',
      '.vertical-sidebar-wrapper.collapsed .prodex-nav-v3-search-wrap{display:none;}',
      '.vertical-sidebar-wrapper.collapsed .vertical-nav-menu{padding-top:8px;}',
      '.vertical-sidebar-wrapper.collapsed .nav-item{margin:4px 9px;}',
      '.vertical-sidebar-wrapper.collapsed .nav-item.' + FIRST_CLASS + '::before{display:none;}',
      '.vertical-sidebar-wrapper.collapsed .nav-link{justify-content:center;width:58px;min-height:46px;padding:11px!important;}',
      '.vertical-sidebar-wrapper.collapsed .nav-icon{margin:0!important;min-width:22px!important;width:22px!important;height:22px!important;}',
      '.vertical-sidebar-wrapper.collapsed .submenu,.vertical-sidebar-wrapper.collapsed .submenu-arrow{display:none!important;}',
      '.vertical-layout.vertical-collapsed main.with-vertical-sidebar{margin-left:76px!important;}',
      'body.dark-theme .vertical-sidebar-wrapper .prodex-nav-v3-search{background:#23233a;border-color:#363650;color:#e5e7eb;}',
      'body.dark-theme .vertical-sidebar-wrapper .prodex-nav-v3-search:focus{background:#1f1f34;}',
      'body.dark-theme .vertical-sidebar-wrapper .nav-item.' + FIRST_CLASS + '::before{color:#7f8aa3;}',
      '@media(max-width:1024px){.vertical-sidebar-wrapper.collapsed{width:260px!important;transform:translateX(-100%)!important;}.vertical-layout.vertical-collapsed main.with-vertical-sidebar{margin-left:0!important;}.vertical-sidebar-wrapper .prodex-nav-v3-search-wrap{padding-top:2px;}}'
    ].join('');
    document.head.appendChild(style);
  }

  function root() {
    return document.querySelector('.vertical-sidebar-wrapper');
  }

  function navList(rootEl) {
    return rootEl && rootEl.querySelector('.vertical-nav-menu > .nav-list');
  }

  function topItems(list) {
    return list ? Array.prototype.slice.call(list.children).filter(function (el) {
      return el && el.classList && el.classList.contains('nav-item') && !el.classList.contains('prodex-nav-clone');
    }) : [];
  }

  function hrefs(li) {
    return Array.prototype.slice.call(li.querySelectorAll('a[href]')).map(function (a) {
      return String(a.getAttribute('href') || '').toLowerCase();
    });
  }

  function text(li) {
    var node = li.querySelector(':scope > .nav-link .nav-text');
    return norm(node ? node.textContent : li.textContent);
  }

  function hasHref(li, prefix) {
    var hs = hrefs(li);
    prefix = prefix.toLowerCase();
    return hs.some(function (h) { return h.indexOf(prefix) === 0; });
  }

  function detectModule(li) {
    var label = text(li);
    var stableIdentity = norm(li.getAttribute('data-prodex-module-identity'));
    if (stableIdentity && moduleRank[stableIdentity]) return stableIdentity;

    // The visible top-level label is deliberately checked before descendant
    // links. Vue renders submenu links lazily; relying on them made a module
    // change identity (and therefore position) whenever it was opened.
    if (labelModuleMap[label]) return labelModuleMap[label];

    if (hasHref(li, '/app/dashboard')) return 'dashboard';
    if (hasHref(li, '/app/billing/')) return 'billing';
    if (hasHref(li, '/app/support/')) return 'support';
    if (hasHref(li, '/app/store/') || hasHref(li, '/online_store')) return 'store';
    if (hasHref(li, '/app/people/')) return 'people';
    if (hasHref(li, '/app/user_management/')) return 'user_management';
    if (hasHref(li, '/app/products/')) return 'products';
    if (hasHref(li, '/app/sales/') || hasHref(li, '/app/pos')) return 'sales';
    if (hasHref(li, '/app/hrm/')) return 'hrm';
    if (hasHref(li, '/app/recruit/')) return 'recruit';
    if (hasHref(li, '/app/meeting/')) return 'meeting';
    if (hasHref(li, '/app/marketing/')) return 'marketing';
    if (hasHref(li, '/app/accounting/') || hasHref(li, '/app/accounting-v2/') || hasHref(li, '/app/accounts')) return 'accounting';
    if (hasHref(li, '/app/subscription_product/')) return 'subscription_product';
    if (hasHref(li, '/app/service/')) return 'service';
    if (hasHref(li, '/app/assets/')) return 'assets';
    if (hasHref(li, '/app/projects')) return 'projects';
    if (hasHref(li, '/app/contracts')) return 'contracts';
    if (hasHref(li, '/app/tasks')) return 'tasks';
    if (hasHref(li, '/app/bookings/')) return 'bookings';
    if (hasHref(li, '/app/commissions/')) return 'commissions';
    if (hasHref(li, '/app/woocommerce')) return 'woocommerce';
    if (hasHref(li, '/app/shopify')) return 'shopify';
    if (hasHref(li, '/app/knowledge-base')) return 'knowledge_base';
    if (hasHref(li, '/app/whatsapp/')) return 'whatsapp';
    if (hasHref(li, '/app/reports/ai_reports')) return 'ai_reports';
    if (hasHref(li, '/app/reports/')) return 'reports';
    if (hasHref(li, '/app/settings/')) return 'settings';
    return null;
  }

  function groupFor(moduleKey) {
    for (var i = 0; i < groups.length; i++) {
      if (groups[i].modules.indexOf(moduleKey) !== -1) return groups[i];
    }
    return null;
  }

  function friendlyName(moduleKey, fallback) {
    var names = {
      billing: 'Plan y facturación',
      people: 'Clientes y proveedores',
      user_management: 'Usuarios y acceso',
      products: 'Inventario',
      sales: 'Operaciones',
      store: 'Tienda en línea',
      hrm: 'Gestión de personal',
      recruit: 'Reclutamiento',
      meeting: 'Reuniones',
      accounting: 'Contabilidad',
      service: 'Servicio y mantenimiento',
      subscription_product: 'Suscripciones',
      knowledge_base: 'Base de conocimientos',
      reports: 'Reportes',
      ai_reports: 'Reportes con IA'
    };
    return names[moduleKey] || fallback || '';
  }

  function setFriendlyLabel(li, moduleKey) {
    var node = li && li.querySelector(':scope > .nav-link .nav-text');
    if (!node) return;
    var value = friendlyName(moduleKey, node.textContent);
    if (value && node.textContent !== value) node.textContent = value;
  }

  function isActuallyVisible(li) {
    if (!li || li.classList.contains('prodex-sidebar2-hidden') || li.classList.contains('prodex-nav-absorbed')) return false;
    if (li.classList.contains(SEARCH_CLASS)) return false;
    return window.getComputedStyle(li).display !== 'none';
  }

  function visualSort(a, b) {
    var ao = parseInt(a.dataset.prodexV3Order || '0', 10);
    var bo = parseInt(b.dataset.prodexV3Order || '0', 10);
    return ao - bo;
  }

  function assignGroups(list) {
    var items = topItems(list);

    items.forEach(function (li, index) {
      li.classList.remove(FIRST_CLASS);
      li.removeAttribute('data-prodex-v3-section-title');

      var moduleKey = detectModule(li);
      li.dataset.prodexV3Module = moduleKey || 'other';

      var group = groupFor(moduleKey);
      var groupOrder = group ? group.order : 65;
      var rank = moduleRank[moduleKey] || (index + 20);
      var order = groupOrder * 100 + rank;

      li.dataset.prodexV3Order = String(order);
      li.style.setProperty('--prodex-v3-order', String(order));
      li.dataset.prodexV3Section = group ? group.key : 'other';
      setFriendlyLabel(li, moduleKey);

      var link = li.querySelector(':scope > .nav-link');
      var labelNode = li.querySelector(':scope > .nav-link .nav-text');
      var tooltip = friendlyName(moduleKey, labelNode ? labelNode.textContent : '');
      if (link && tooltip) link.setAttribute('title', tooltip);
    });

    // Section headings must follow the CSS visual order, not Vue's original
    // DOM order. Otherwise a heading can appear in the middle of its group.
    var visibleInVisualOrder = items.filter(isActuallyVisible).sort(visualSort);
    groups.concat([{ key: 'other', title: 'Más herramientas', order: 65 }]).forEach(function (group) {
      var first = visibleInVisualOrder.find(function (li) {
        return li.dataset.prodexV3Section === group.key;
      });
      if (first) {
        first.classList.add(FIRST_CLASS);
        first.dataset.prodexV3SectionTitle = group.title;
      }
    });
  }

  function searchText(li) {
    var moduleKey = li.dataset.prodexV3Module || detectModule(li) || '';
    return norm(li.textContent + ' ' + hrefs(li).join(' ') + ' ' + (aliases[moduleKey] || ''));
  }

  function applySearch(list, query) {
    query = norm(query);
    var items = topItems(list);
    items.forEach(function (li) {
      if (!query) {
        li.classList.remove(SEARCH_CLASS);
        return;
      }
      li.classList.toggle(SEARCH_CLASS, searchText(li).indexOf(query) === -1);
    });
    assignGroups(list);
  }

  function ensureSearch(rootEl, list) {
    if (!rootEl || !list) return;
    var scroller = rootEl.querySelector('.vertical-sidebar');
    var header = rootEl.querySelector('.vertical-sidebar-header');
    if (!scroller || !header) return;

    var existing = scroller.querySelector('.prodex-nav-v3-search-wrap');
    if (existing) return;

    var wrap = document.createElement('div');
    wrap.className = 'prodex-nav-v3-search-wrap';
    wrap.innerHTML = '<svg class="prodex-nav-v3-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg><input class="prodex-nav-v3-search" type="search" autocomplete="off" placeholder="Buscar en PRODEX"><button class="prodex-nav-v3-search-clear" type="button" aria-label="Limpiar búsqueda">×</button>';
    header.insertAdjacentElement('afterend', wrap);

    var input = wrap.querySelector('.prodex-nav-v3-search');
    var clear = wrap.querySelector('.prodex-nav-v3-search-clear');
    input.addEventListener('input', function () {
      wrap.classList.toggle('has-value', !!input.value);
      applySearch(list, input.value);
    });
    clear.addEventListener('click', function () {
      input.value = '';
      wrap.classList.remove('has-value');
      applySearch(list, '');
      input.focus();
    });
  }

  function neutralizeLegacyVertical(rootEl) {
    if (rootEl) rootEl.dataset.prodexFriendlyOrganized = '2';
  }

  function organize() {
    installStyles();
    var rootEl = root();
    if (!rootEl) return;
    var list = navList(rootEl);
    if (!list) return;
    neutralizeLegacyVertical(rootEl);
    ensureSearch(rootEl, list);
    assignGroups(list);
  }

  function schedule() {
    if (scheduled) window.clearTimeout(scheduled);
    scheduled = window.setTimeout(organize, 30);
  }

  function installObserver() {
    var rootEl = root();
    if (!rootEl || observer) return;
    observer = new MutationObserver(function (mutations) {
      var relevant = mutations.some(function (m) { return m.type === 'childList'; });
      if (relevant) schedule();
    });
    observer.observe(rootEl, { childList: true, subtree: true });
  }

  document.addEventListener('click', function (event) {
    if (event.target && event.target.closest && event.target.closest('.vertical-sidebar-wrapper')) {
      window.setTimeout(schedule, 20);
    }
  }, false);

  document.addEventListener('keydown', function (event) {
    if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey) return;
    var active = document.activeElement;
    if (active && /input|textarea|select/i.test(active.tagName)) return;
    var input = document.querySelector('.vertical-sidebar-wrapper .prodex-nav-v3-search');
    if (!input) return;
    event.preventDefault();
    input.focus();
  });

  window.addEventListener('load', function () { organize(); installObserver(); }, { once: true });
  document.addEventListener('DOMContentLoaded', function () { organize(); installObserver(); }, { once: true });
  [0, 80, 250, 700, 1400].forEach(function (delay) {
    window.setTimeout(function () { organize(); installObserver(); }, delay);
  });
})();
