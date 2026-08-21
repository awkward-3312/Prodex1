(function () {
  'use strict';

  if (window.__prodexSidebar2OrganizerInstalled) return;
  window.__prodexSidebar2OrganizerInstalled = true;

  var STYLE_ID = 'prodex-sidebar2-organizer-style';

  function installStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = [
      '.vertical-sidebar-wrapper .prodex-sidebar2-hidden{display:none!important;}',
      '.vertical-sidebar-wrapper .prodex-sidebar2-section{padding:10px 12px 4px;margin:4px 8px 0;font-size:10px;line-height:1.2;font-weight:700;letter-spacing:.055em;text-transform:uppercase;color:#98a2b3;}',
      '.vertical-sidebar-wrapper .prodex-sidebar2-section-link{display:flex;align-items:center;padding:9px 12px;margin:0 8px;border-radius:6px;color:#666;text-decoration:none;font-size:13px;transition:background .15s ease,color .15s ease,padding-left .15s ease;}',
      '.vertical-sidebar-wrapper .prodex-sidebar2-section-link:hover{background:rgba(102,51,153,.08);color:#663399;padding-left:16px;}',
      '.vertical-sidebar-wrapper .prodex-sidebar2-section-link.router-link-active{background:rgba(102,51,153,.1);color:#663399;font-weight:600;}',
      '.vertical-sidebar-wrapper .prodex-sidebar2-mini-icon{width:15px;height:15px;min-width:15px;margin-right:10px;display:inline-flex;align-items:center;justify-content:center;color:#7b8495;}',
      '.vertical-sidebar-wrapper .prodex-sidebar2-mini-icon svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}',
      'body.dark-theme .vertical-sidebar-wrapper .prodex-sidebar2-section-link{color:#b0b0b0;}',
      'body.dark-theme .vertical-sidebar-wrapper .prodex-sidebar2-section-link:hover{color:#fff;background:rgba(118,75,162,.15);}'
    ].join('');
    document.head.appendChild(style);
  }

  function norm(value) {
    return String(value || '').trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  }

  function root() {
    return document.querySelector('.vertical-sidebar-wrapper');
  }

  function list(rootEl) {
    return rootEl && rootEl.querySelector('.vertical-nav-menu > .nav-list');
  }

  function topItems(navList) {
    return navList ? Array.prototype.slice.call(navList.children).filter(function (el) {
      return el && el.classList && el.classList.contains('nav-item');
    }) : [];
  }

  function labelOf(li) {
    var el = li && li.querySelector(':scope > .nav-link .nav-text');
    return el ? norm(el.textContent) : '';
  }

  function findTopByLabels(navList, labels) {
    var wanted = labels.map(norm);
    return topItems(navList).find(function (li) {
      return wanted.indexOf(labelOf(li)) !== -1;
    }) || null;
  }

  function setLabel(li, value) {
    var el = li && li.querySelector(':scope > .nav-link .nav-text');
    if (el && el.textContent !== value) el.textContent = value;
  }

  function visible(li) {
    if (!li) return false;
    return window.getComputedStyle(li).display !== 'none';
  }

  function vmFor(rootEl) {
    if (!rootEl) return null;
    if (rootEl.__vue__) return rootEl.__vue__;
    var el = rootEl.querySelector('.vertical-sidebar');
    return el && el.__vue__ ? el.__vue__ : null;
  }

  function permissions(vm) {
    return vm && Array.isArray(vm.currentUserPermissions) ? vm.currentUserPermissions : [];
  }

  function has(perms, permission) {
    return perms.indexOf(permission) !== -1;
  }

  function planEnabled(vm, key) {
    try {
      return !vm || typeof vm.planFeature !== 'function' ? true : !!vm.planFeature(key);
    } catch (e) {
      return true;
    }
  }

  function iconSvg(name) {
    var paths = {
      plus: '<path d="M12 5v14M5 12h14"/>',
      list: '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
      download: '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
      rotate: '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 3v6h6"/>',
      tag: '<path d="M20.6 13.6 11 4H4v7l9.6 9.6a2 2 0 0 0 2.8 0l4.2-4.2a2 2 0 0 0 0-2.8Z"/><path d="M7.5 7.5h.01"/>',
      arrows: '<path d="M7 7h11l-3-3"/><path d="m18 7-3 3"/><path d="M17 17H6l3 3"/><path d="m6 17 3-3"/>',
      alert: '<path d="M10.3 2.9 1.8 17a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 2.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>'
    };
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' + (paths[name] || paths.list) + '</svg>';
  }

  function makeLink(vm, href, text, icon) {
    var a = document.createElement('a');
    a.href = href;
    a.className = 'prodex-sidebar2-section-link';
    a.innerHTML = '<span class="prodex-sidebar2-mini-icon">' + iconSvg(icon) + '</span><span>' + text + '</span>';
    a.addEventListener('click', function (event) {
      if (vm && vm.$router) {
        event.preventDefault();
        vm.$router.push(href).catch(function () {});
      }
    });
    return a;
  }

  function ensureSection(host, key, title, links, vm) {
    if (!host || !links.length) return;
    var marker = 'prodex-sidebar2-section-' + key;
    if (host.querySelector('.' + marker)) return;

    var heading = document.createElement('li');
    heading.className = 'prodex-sidebar2-section ' + marker;
    heading.textContent = title;
    host.appendChild(heading);

    links.forEach(function (entry) {
      var li = document.createElement('li');
      li.className = 'submenu-item prodex-sidebar2-generated';
      li.appendChild(makeLink(vm, entry[0], entry[1], entry[2]));
      host.appendChild(li);
    });
  }

  function applyLabelsAndTopLevel(rootEl, vm) {
    var navList = list(rootEl);
    if (!navList) return null;

    var billing = findTopByLabels(navList, ['Facturación', 'Billing', 'Plan y facturación']);
    var people = findTopByLabels(navList, ['Gente', 'People', 'Clientes y proveedores']);
    var users = findTopByLabels(navList, ['Gestión de usuarios', 'User Management', 'Usuarios y acceso']);
    var products = findTopByLabels(navList, ['Productos', 'Products', 'Inventario']);
    var sales = findTopByLabels(navList, ['Ventas', 'Sales', 'Operaciones']);

    setLabel(billing, 'Plan y facturación');
    setLabel(people, 'Clientes y proveedores');
    setLabel(users, 'Usuarios y acceso');
    setLabel(products, 'Inventario');
    setLabel(sales, 'Operaciones');

    if (products && visible(products)) {
      ['Ajuste de Stock', 'Stock Adjustment', 'Transferencias de stock', 'Stock Transfers', 'Daños', 'Damages'].forEach(function (name) {
        var li = findTopByLabels(navList, [name]);
        if (li && li !== products) li.classList.add('prodex-sidebar2-hidden');
      });
    }

    if (sales && visible(sales)) {
      ['Compras', 'Purchases', 'Promociones', 'Promotions', 'Devolución de ventas', 'Sales Return', 'Cotizaciones', 'Quotations', 'Citas', 'Devolución de compras', 'Purchase Return'].forEach(function (name) {
        var li = findTopByLabels(navList, [name]);
        if (li && li !== sales) li.classList.add('prodex-sidebar2-hidden');
      });
    }

    return { products: products, sales: sales };
  }

  function enhanceOpenSubmenus(rootEl, vm, found) {
    if (!found) return;
    var perms = permissions(vm);

    if (found.products) {
      var inv = found.products.querySelector(':scope > .submenu');
      if (inv) {
        var stockLinks = [];
        if (has(perms, 'adjustment_add')) stockLinks.push(['/app/adjustments/store', 'Nuevo ajuste', 'plus']);
        if (has(perms, 'adjustment_view')) stockLinks.push(['/app/adjustments/list', 'Ajustes de stock', 'list']);
        if (planEnabled(vm, 'transfers') && has(perms, 'transfer_add')) stockLinks.push(['/app/transfers/store', 'Nueva transferencia', 'arrows']);
        if (planEnabled(vm, 'transfers') && has(perms, 'transfer_view')) stockLinks.push(['/app/transfers/list', 'Transferencias de stock', 'arrows']);
        if (has(perms, 'damage_view')) {
          stockLinks.push(['/app/damages/store', 'Registrar daño', 'alert']);
          stockLinks.push(['/app/damages/list', 'Daños', 'alert']);
        }
        ensureSection(inv, 'movimientos', 'Movimientos de inventario', stockLinks, vm);
      }
    }

    if (found.sales) {
      var ops = found.sales.querySelector(':scope > .submenu');
      if (ops) {
        var purchases = [];
        if (has(perms, 'Purchases_add')) purchases.push(['/app/purchases/store', 'Nueva compra', 'plus']);
        if (has(perms, 'Purchases_view')) purchases.push(['/app/purchases/list', 'Lista de compras', 'list']);
        if (has(perms, 'Purchases_add')) purchases.push(['/app/purchases/import_purchases', 'Importar compras', 'download']);
        ensureSection(ops, 'compras', 'Compras', purchases, vm);

        var quotes = [];
        if (planEnabled(vm, 'quotations') && has(perms, 'Quotations_add')) quotes.push(['/app/quotations/store', 'Nueva cotización', 'plus']);
        if (planEnabled(vm, 'quotations') && has(perms, 'Quotations_view')) quotes.push(['/app/quotations/list', 'Cotizaciones', 'list']);
        ensureSection(ops, 'cotizaciones', 'Cotizaciones', quotes, vm);

        var returns = [];
        if (has(perms, 'Sale_Returns_view')) returns.push(['/app/sale_return/list', 'Devoluciones de ventas', 'rotate']);
        if (has(perms, 'Purchase_Returns_view')) returns.push(['/app/purchase_return/list', 'Devoluciones de compras', 'rotate']);
        if (planEnabled(vm, 'promotions') && has(perms, 'promotion')) returns.push(['/app/promotions', 'Promociones', 'tag']);
        ensureSection(ops, 'devoluciones', 'Devoluciones y promociones', returns, vm);
      }
    }
  }

  function organize() {
    installStyle();
    var rootEl = root();
    if (!rootEl) return;
    var vm = vmFor(rootEl);
    var found = applyLabelsAndTopLevel(rootEl, vm);
    enhanceOpenSubmenus(rootEl, vm, found);
  }

  function schedule() {
    [0, 40, 120, 300, 700, 1400, 2400].forEach(function (delay) {
      window.setTimeout(organize, delay);
    });
  }

  document.addEventListener('click', function (event) {
    var target = event.target && event.target.closest ? event.target.closest('.vertical-sidebar-wrapper .nav-link, .layout-option') : null;
    if (!target) return;
    schedule();
  }, false);

  window.addEventListener('load', schedule, { once: true });
  document.addEventListener('DOMContentLoaded', schedule, { once: true });
  schedule();
})();
