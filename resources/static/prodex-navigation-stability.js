(function () {
  'use strict';

  if (window.__prodexNavigationStabilityInstalled) return;
  window.__prodexNavigationStabilityInstalled = true;

  var scheduled = null;
  var observer = null;

  var stableMap = {
    'tablero': { module: 'dashboard', section: 'principal', order: 1001 },
    'operaciones': { module: 'sales', section: 'commercial', order: 2001 },
    'inventario': { module: 'products', section: 'commercial', order: 2002 },
    'clientes y proveedores': { module: 'people', section: 'commercial', order: 2003 },
    'tienda en linea': { module: 'store', section: 'commercial', order: 2004 },
    'gestion de personal': { module: 'hrm', section: 'team', order: 3001 },
    'hrm': { module: 'hrm', section: 'team', order: 3001 },
    'reclutamiento': { module: 'recruit', section: 'team', order: 3002 },
    'reuniones': { module: 'meeting', section: 'team', order: 3003 },
    'proyectos': { module: 'projects', section: 'work', order: 4001 },
    'tareas': { module: 'tasks', section: 'work', order: 4002 },
    'contratos': { module: 'contracts', section: 'work', order: 4003 },
    'servicio y mantenimiento': { module: 'service', section: 'work', order: 4004 },
    'servicios y mantenimiento': { module: 'service', section: 'work', order: 4004 },
    'activos': { module: 'assets', section: 'work', order: 4005 },
    'reservas': { module: 'bookings', section: 'work', order: 4006 },
    'citas': { module: 'bookings', section: 'work', order: 4006 },
    'contabilidad': { module: 'accounting', section: 'finance', order: 5001 },
    'comisiones': { module: 'commissions', section: 'finance', order: 5002 },
    'suscripciones': { module: 'subscription_product', section: 'finance', order: 5003 },
    'producto de suscripcion': { module: 'subscription_product', section: 'finance', order: 5003 },
    'marketing': { module: 'marketing', section: 'growth', order: 6001 },
    'whatsapp': { module: 'whatsapp', section: 'growth', order: 6002 },
    'configuracion de woocommerce': { module: 'woocommerce', section: 'growth', order: 6003 },
    'woocommerce': { module: 'woocommerce', section: 'growth', order: 6003 },
    'configuracion de shopify': { module: 'shopify', section: 'growth', order: 6004 },
    'shopify': { module: 'shopify', section: 'growth', order: 6004 },
    'reportes': { module: 'reports', section: 'insights', order: 7001 },
    'reportes con ia': { module: 'ai_reports', section: 'insights', order: 7002 },
    'usuarios y acceso': { module: 'user_management', section: 'admin', order: 8001 },
    'gestion de usuarios': { module: 'user_management', section: 'admin', order: 8001 },
    'configuraciones': { module: 'settings', section: 'admin', order: 8002 },
    'configuracion': { module: 'settings', section: 'admin', order: 8002 },
    'plan y facturacion': { module: 'billing', section: 'admin', order: 8003 },
    'facturacion': { module: 'billing', section: 'admin', order: 8003 },
    'soporte': { module: 'support', section: 'admin', order: 8004 },
    'base de conocimientos': { module: 'knowledge_base', section: 'admin', order: 8005 }
  };

  var sectionTitles = {
    principal: 'Principal',
    commercial: 'Operación del negocio',
    team: 'Equipo',
    work: 'Gestión',
    finance: 'Finanzas',
    growth: 'Crecimiento e integraciones',
    other: 'Más herramientas',
    insights: 'Análisis',
    admin: 'Administración'
  };

  function norm(value) {
    return String(value || '')
      .trim()
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '');
  }

  function getList() {
    return document.querySelector('.vertical-sidebar-wrapper .vertical-nav-menu > .nav-list');
  }

  function items(list) {
    return list ? Array.prototype.slice.call(list.children).filter(function (el) {
      return el && el.classList && el.classList.contains('nav-item') && !el.classList.contains('prodex-nav-clone');
    }) : [];
  }

  function label(li) {
    var node = li.querySelector(':scope > .nav-link .nav-text');
    return norm(node ? node.textContent : '');
  }

  function visible(li) {
    if (!li || li.classList.contains('prodex-sidebar2-hidden') || li.classList.contains('prodex-nav-absorbed') || li.classList.contains('prodex-nav-v3-search-hidden')) return false;
    return window.getComputedStyle(li).display !== 'none';
  }

  function stabilize() {
    var list = getList();
    if (!list) return;
    var all = items(list);

    all.forEach(function (li, index) {
      var key = label(li);
      var stable = stableMap[key];

      if (stable) {
        li.dataset.prodexStableModule = stable.module;
        li.dataset.prodexV3Module = stable.module;
        li.dataset.prodexV3Section = stable.section;
        li.dataset.prodexV3Order = String(stable.order);
        li.style.setProperty('--prodex-v3-order', String(stable.order));
      } else if (!li.dataset.prodexStableOrder) {
        li.dataset.prodexStableOrder = li.dataset.prodexV3Order || String(6500 + index + 20);
        li.dataset.prodexStableSection = li.dataset.prodexV3Section || 'other';
      } else {
        li.dataset.prodexV3Order = li.dataset.prodexStableOrder;
        li.dataset.prodexV3Section = li.dataset.prodexStableSection || 'other';
        li.style.setProperty('--prodex-v3-order', li.dataset.prodexStableOrder);
      }

      li.classList.remove('prodex-nav-v3-section-first');
      li.removeAttribute('data-prodex-v3-section-title');
    });

    Object.keys(sectionTitles).forEach(function (section) {
      var candidates = all.filter(function (li) {
        return li.dataset.prodexV3Section === section && visible(li);
      }).sort(function (a, b) {
        return Number(a.dataset.prodexV3Order || 99999) - Number(b.dataset.prodexV3Order || 99999);
      });
      if (!candidates.length) return;
      candidates[0].classList.add('prodex-nav-v3-section-first');
      candidates[0].dataset.prodexV3SectionTitle = sectionTitles[section];
    });
  }

  function schedule(delay) {
    if (scheduled) window.clearTimeout(scheduled);
    scheduled = window.setTimeout(stabilize, delay || 90);
  }

  function installObserver() {
    var root = document.querySelector('.vertical-sidebar-wrapper');
    if (!root || observer) return;
    observer = new MutationObserver(function (mutations) {
      if (mutations.some(function (m) { return m.type === 'childList'; })) schedule(110);
    });
    observer.observe(root, { childList: true, subtree: true });
  }

  document.addEventListener('click', function (event) {
    if (event.target && event.target.closest && event.target.closest('.vertical-sidebar-wrapper')) {
      schedule(120);
    }
  }, false);

  document.addEventListener('DOMContentLoaded', function () {
    schedule(180);
    window.setTimeout(installObserver, 220);
  });

  window.setTimeout(function () {
    stabilize();
    installObserver();
  }, 500);
})();
