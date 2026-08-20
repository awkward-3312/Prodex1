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
    { key: 'work', title: 'Gestión', order: 40, modules: ['service', 'assets', 'projects', 'contracts', 'tasks', 'bookings'] },
    { key: 'finance', title: 'Finanzas', order: 50, modules: ['accounting', 'commissions', 'subscription_product'] },
    { key: 'growth', title: 'Crecimiento e integraciones', order: 60, modules: ['marketing', 'woocommerce', 'shopify', 'whatsapp'] },
    { key: 'insights', title: 'Análisis', order: 70, modules: ['ai_reports', 'reports'] },
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
    dashboard: 'tablero inicio principal',
    sales: 'ventas operaciones pos punto de venta compras cotizaciones devoluciones promociones',
    products: 'inventario productos stock existencias ajustes transferencias traslados daños',
    people: 'clientes proveedores contactos personas',
    store: 'tienda tienda en línea ecommerce pedidos en línea',
    hrm: 'gestión de personal recursos humanos empleados asistencia planilla nómina vacaciones',
    recruit: 'reclutamiento candidatos empleos entrevistas aplicaciones',
    meeting: 'reuniones calendario',
    service: 'servicio mantenimiento técnicos reparación',
    assets: 'activos',
    projects: 'proyectos',
    contracts: 'contratos',
    tasks: 'tareas',
    bookings: 'reservas citas calendario',
    accounting: 'contabilidad finanzas gastos depósitos cuentas transferencias diario balance',
    commissions: 'comisiones agentes ventas',
    subscription_product: 'suscripciones producto de suscripción',
    marketing: 'marketing campañas segmentos plantillas',
    whatsapp: 'whatsapp mensajes plantillas',
    woocommerce: 'woocommerce comercio electrónico',
    shopify: 'shopify comercio electrónico',
    reports: 'reportes informes análisis estadísticas',
    ai_reports: 'reportes ia inteligencia artificial',
    user_management: 'usuarios acceso permisos roles',
    settings: 'configuración ajustes sistema almacenes cajas moneda backup webhooks',
    billing: 'plan facturación suscripción pago',
    support: 'soporte ayuda tickets',
    knowledge_base: 'base de conocimientos manual ayuda documentación'
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

    if (hasHref(li, '/app/dashboard')) return 'dashboard';
    if (hasHref(li, '/app/billing/')) return 'billing';
    if (hasHref(li, '/app/support/')) return 'support';
    if (hasHref(li, '/app/store/') || hasHref(li, '/online_store')) return 'store';
    if (hasHref(li, '/app/people/')) return 'people';
    if (hasHref(li, '/app/user_management/')) return 'user_management';
    if (hasHref(li, '/app/products/') || label === 'inventario') return 'products';
    if (hasHref(li, '/app/sales/') || hasHref(li, '/app/pos') || label === 'operaciones') return 'sales';
    if (hasHref(li, '/app/hrm/')) return 'hrm';
    if (hasHref(li, '/app/recruit/')) return 'recruit';
    if (hasHref(li, '/app/meeting/')) return 'meeting';
    if (hasHref(li, '/app/marketing/')) return 'marketing';
    if (hasHref(li, '/app/accounting/') || hasHref(li, '/app/accounts') || label === 'contabilidad') return 'accounting';
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

    if (label === 'gestion de personal' || label === 'hrm') return 'hrm';
    if (label === 'reclutamiento') return 'recruit';
    if (label === 'reuniones') return 'meeting';
    if (label === 'marketing') return 'marketing';
    if (label === 'contabilidad') return 'accounting';
    if (label === 'activos') return 'assets';
    if (label === 'proyectos') return 'projects';
    if (label === 'contratos') return 'contracts';
    if (label === 'tareas') return 'tasks';
    if (label === 'reservas') return 'bookings';
    if (label === 'comisiones') return 'commissions';
    if (label.indexOf('woocommerce') !== -1) return 'woocommerce';
    if (label.indexOf('shopify') !== -1) return 'shopify';
    if (label.indexOf('base de conocimientos') !== -1) return 'knowledge_base';
    if (label === 'whatsapp') return 'whatsapp';
    if (label === 'configuraciones' || label === 'configuracion') return 'settings';
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
    if (value) node.textContent = value;
  }

  function isActuallyVisible(li) {
    if (!li || li.classList.contains('prodex-sidebar2-hidden') || li.classList.contains('prodex-nav-absorbed')) return false;
    if (li.classList.contains(SEARCH_CLASS)) return false;
    return window.getComputedStyle(li).display !== 'none';
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

    groups.concat([{ key: 'other', title: 'Más herramientas', order: 65 }]).forEach(function (group) {
      var first = items.find(function (li) {
        return li.dataset.prodexV3Section === group.key && isActuallyVisible(li);
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
      var matched = searchText(li).indexOf(query) !== -1;
      li.classList.toggle(SEARCH_CLASS, !matched);
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
    if (!rootEl) return;
    rootEl.dataset.prodexFriendlyOrganized = '2';
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
    scheduled = window.setTimeout(organize, 40);
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
