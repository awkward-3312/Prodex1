(function () {
  'use strict';

  if (window.__prodexInventoryVisibilityInstalled) return;
  window.__prodexInventoryVisibilityInstalled = true;

  var stockTimer = null;
  var observer = null;
  var FULL_PAGE_PARAM = 'prodex_inventory_view';

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function norm(value) {
    return String(value || '').trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  }

  function fmt(value) {
    var n = Number(value || 0);
    return n.toLocaleString('es-HN', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
  }

  function icon(name) {
    var paths = {
      stock: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10M7 12h4M7 16h6"/>',
      missing: '<path d="M10.3 2.9 1.8 17a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 2.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>',
      search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
      refresh: '<path d="M20 6v6h-6"/><path d="M4 18v-6h6"/><path d="M18.5 9A7 7 0 0 0 6 5.5L4 8M5.5 15A7 7 0 0 0 18 18.5l2-2.5"/>',
      close: '<path d="M18 6 6 18M6 6l12 12"/>'
    };
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' + (paths[name] || paths.stock) + '</svg>';
  }

  function installStyle() {
    if (document.getElementById('px-inventory-visibility-style')) return;
    var s = document.createElement('style');
    s.id = 'px-inventory-visibility-style';
    s.textContent = [
      '.px-iv-overlay{position:fixed;inset:0;background:rgba(17,24,39,.45);z-index:10050;display:none;align-items:flex-start;justify-content:center;padding:7vh 20px 24px;overflow:auto}',
      '.px-iv-overlay.open{display:flex}',
      '.px-iv-modal{width:min(1040px,100%);background:#fff;border-radius:12px;box-shadow:0 24px 70px rgba(15,23,42,.24);overflow:hidden}',
      '.px-iv-head{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid #e7eaf0}',
      '.px-iv-title{font-size:18px;font-weight:800;color:#111827}.px-iv-subtitle{margin-top:3px;font-size:12px;color:#667085}',
      '.px-iv-close{border:0;background:transparent;padding:6px;cursor:pointer;color:#667085}.px-iv-close svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2}',
      '.px-iv-body{padding:20px}',
      '.px-iv-search{position:relative;margin-bottom:18px}.px-iv-search svg{position:absolute;left:13px;top:12px;width:18px;height:18px;fill:none;stroke:#98a2b3;stroke-width:2}.px-iv-search input{width:100%;height:42px;padding:0 14px 0 42px;border:1px solid #d0d5dd;border-radius:8px;font-size:13px;outline:none}.px-iv-search input:focus{border-color:#38b6cf;box-shadow:0 0 0 3px rgba(56,182,207,.08)}',
      '.px-iv-empty{padding:42px 14px;text-align:center;color:#667085;font-size:13px}',
      '.px-iv-product{border:1px solid #e4e7ec;border-radius:10px;margin-bottom:14px;overflow:hidden;background:#fff}.px-iv-product-head{display:flex;justify-content:space-between;gap:12px;padding:14px 16px;background:#f8fafc}.px-iv-product-name{font-weight:800;color:#101828}.px-iv-product-code{font-size:11px;color:#667085;margin-top:2px}.px-iv-company-total{font-size:12px;color:#475467;text-align:right}.px-iv-company-total strong{display:block;font-size:18px;color:#101828}',
      '.px-iv-group{padding:13px 16px;border-top:1px solid #eaecf0}.px-iv-group.current{background:#f2fbfd}.px-iv-group-title{display:flex;justify-content:space-between;gap:10px;margin-bottom:8px;font-size:12px;font-weight:800;color:#344054}.px-iv-current-badge{display:inline-block;margin-left:7px;padding:2px 6px;border-radius:999px;background:#dff6fa;color:#16839a;font-size:9px;font-weight:800}',
      '.px-iv-row{display:grid;grid-template-columns:minmax(150px,1fr) repeat(3,90px);gap:10px;align-items:center;padding:7px 0;font-size:12px;color:#475467}.px-iv-row+.px-iv-row{border-top:1px dashed #edf0f4}.px-iv-num{text-align:right;font-variant-numeric:tabular-nums}.px-iv-row-head{font-size:10px;text-transform:uppercase;color:#98a2b3;font-weight:800}',
      '.px-iv-transit{margin-top:8px;padding:8px 10px;border-radius:7px;background:#eff6ff;color:#1d4ed8;font-size:11px}',
      '.px-iv-table-wrap{overflow:auto;border:1px solid #e4e7ec;border-radius:9px;background:#fff}.px-iv-table{width:100%;border-collapse:collapse;min-width:820px}.px-iv-table th{padding:11px 12px;background:#f8fafc;text-align:left;font-size:10px;text-transform:uppercase;color:#667085}.px-iv-table td{padding:12px;border-top:1px solid #eaecf0;font-size:12px;color:#344054}.px-iv-status{display:inline-block;padding:3px 7px;border-radius:999px;font-size:10px;font-weight:800}.px-iv-status.open{background:#fff7ed;color:#c2410c}.px-iv-status.resolved{background:#ecfdf3;color:#027a48}',
      '.px-iv-top-btn{display:inline-flex;align-items:center;gap:6px;height:38px;padding:0 11px;margin-right:8px;border:1px solid #d0d5dd;border-radius:8px;background:#fff;color:#475467;font-size:11px;font-weight:800;cursor:pointer}.px-iv-top-btn:hover{border-color:#38b6cf;color:#16839a}.px-iv-top-btn svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2}',
      '.px-iv-full-page{padding:28px 30px 18px;width:100%;box-sizing:border-box}.px-iv-page-title{font-size:26px;font-weight:800;color:#101828;margin:8px 0 4px}.px-iv-page-subtitle{font-size:13px;color:#667085;margin-bottom:22px}.px-iv-page-card{background:#fff;border:1px solid #e4e7ec;border-radius:10px;padding:22px;box-shadow:0 1px 2px rgba(16,24,40,.03)}',
      '.px-iv-toolbar{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-bottom:18px}.px-iv-toolbar .px-iv-search{margin:0;flex:1;max-width:520px}.px-iv-filter{height:42px;border:1px solid #d0d5dd;border-radius:8px;background:#fff;padding:0 12px;color:#475467;font-size:12px}.px-iv-refresh{height:42px;display:inline-flex;align-items:center;gap:7px;border:1px solid #d0d5dd;border-radius:8px;background:#fff;padding:0 13px;color:#475467;font-size:12px;font-weight:700;cursor:pointer}.px-iv-refresh svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2}.px-iv-summary{font-size:12px;color:#667085;margin-bottom:12px}.px-iv-summary strong{color:#101828}',
      '.vertical-sidebar-wrapper .px-iv-menu-active>a{background:rgba(56,182,207,.12)!important;color:#16839a!important;font-weight:700}',
      'body.dark-theme .px-iv-modal,body.dark-theme .px-iv-top-btn,body.dark-theme .px-iv-page-card,body.dark-theme .px-iv-product,body.dark-theme .px-iv-filter,body.dark-theme .px-iv-refresh{background:#1f2030;color:#e5e7eb}body.dark-theme .px-iv-title,body.dark-theme .px-iv-page-title,body.dark-theme .px-iv-product-name,body.dark-theme .px-iv-company-total strong{color:#f5f5f5}body.dark-theme .px-iv-product-head,body.dark-theme .px-iv-table th{background:#252638}body.dark-theme .px-iv-product,body.dark-theme .px-iv-table-wrap,body.dark-theme .px-iv-head,body.dark-theme .px-iv-group,body.dark-theme .px-iv-table td,body.dark-theme .px-iv-page-card{border-color:#34364a}',
      '@media(max-width:700px){.px-iv-overlay{padding:18px 8px}.px-iv-row{grid-template-columns:1fr 72px}.px-iv-row .px-hide-mobile{display:none}.px-iv-top-btn span{display:none}.px-iv-top-btn{width:38px;padding:0;justify-content:center}.px-iv-full-page{padding:18px 12px}.px-iv-toolbar{align-items:stretch;flex-direction:column}.px-iv-toolbar .px-iv-search{max-width:none}.px-iv-page-card{padding:14px}}'
    ].join('');
    document.head.appendChild(s);
  }

  function createModal(id, title, subtitle, bodyHtml) {
    var overlay = document.createElement('div');
    overlay.id = id;
    overlay.className = 'px-iv-overlay';
    overlay.innerHTML = '<div class="px-iv-modal" role="dialog" aria-modal="true">' +
      '<div class="px-iv-head"><div><div class="px-iv-title">' + esc(title) + '</div><div class="px-iv-subtitle">' + esc(subtitle) + '</div></div>' +
      '<button type="button" class="px-iv-close" data-px-close="1">' + icon('close') + '</button></div>' +
      '<div class="px-iv-body">' + bodyHtml + '</div></div>';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay || (e.target.closest && e.target.closest('[data-px-close]'))) overlay.classList.remove('open');
    });
    return overlay;
  }

  function ensureStockModal() {
    var stock = document.getElementById('px-stock-visibility-modal');
    if (stock) return stock;
    stock = createModal('px-stock-visibility-modal', 'Existencias por ubicación', 'Consulta disponibilidad en tu sucursal, otras sucursales y centros de distribución.',
      '<div class="px-iv-search">' + icon('search') + '<input id="px-stock-search" type="search" autocomplete="off" placeholder="Buscar producto por nombre o código"></div><div id="px-stock-results" class="px-iv-empty">Escribe al menos 2 caracteres para consultar existencias.</div>');
    var input = stock.querySelector('#px-stock-search');
    input.addEventListener('input', function () {
      if (stockTimer) clearTimeout(stockTimer);
      stockTimer = setTimeout(function () { loadStockInto(input.value, stock.querySelector('#px-stock-results')); }, 280);
    });
    return stock;
  }

  function openStock() {
    var modal = ensureStockModal();
    modal.classList.add('open');
    var input = modal.querySelector('#px-stock-search');
    window.setTimeout(function () { input.focus(); }, 60);
  }

  function groupStock(rows) {
    var groups = {};
    (rows || []).forEach(function (r) {
      var key = r.owner_type === 'branch' ? 'b:' + r.branch_id : 'w:' + r.warehouse_id;
      if (!groups[key]) groups[key] = {
        key: key,
        owner_type: r.owner_type,
        name: r.owner_type === 'branch' ? (r.branch_name || 'Sucursal') : (r.warehouse_name || 'Centro de Distribución'),
        current: !!r.is_current_branch,
        rows: [], total: 0, reserved: 0
      };
      groups[key].rows.push(r);
      if (!r.is_quarantine) {
        groups[key].total += Number(r.available || 0);
        groups[key].reserved += Number(r.reserved || 0);
      }
    });
    return Object.keys(groups).map(function (k) { return groups[k]; }).sort(function (a, b) {
      if (a.current !== b.current) return a.current ? -1 : 1;
      if (a.owner_type !== b.owner_type) return a.owner_type === 'branch' ? -1 : 1;
      return a.name.localeCompare(b.name);
    });
  }

  function transitForGroup(transit, group) {
    return (transit || []).filter(function (r) {
      return group.owner_type === 'branch' ? Number(r.branch_id) === Number(group.key.split(':')[1]) : Number(r.warehouse_id) === Number(group.key.split(':')[1]);
    }).reduce(function (sum, r) { return sum + Number(r.quantity || 0); }, 0);
  }

  function renderProducts(products) {
    if (!products || !products.length) return '<div class="px-iv-empty">No encontramos productos con ese nombre o código.</div>';
    return products.map(function (p) {
      var groups = groupStock(p.locations || []);
      var html = '<div class="px-iv-product"><div class="px-iv-product-head"><div><div class="px-iv-product-name">' + esc(p.name) + '</div><div class="px-iv-product-code">' + esc(p.code || 'Sin código') + '</div></div><div class="px-iv-company-total"><strong>' + fmt(p.company_available) + '</strong>disponible en la empresa</div></div>';
      if (!groups.length) {
        html += '<div class="px-iv-empty">Este producto todavía no tiene existencias por ubicación.</div>';
      } else {
        groups.forEach(function (g) {
          html += '<div class="px-iv-group ' + (g.current ? 'current' : '') + '"><div class="px-iv-group-title"><span>' + esc(g.name) + (g.current ? '<span class="px-iv-current-badge">MI SUCURSAL</span>' : '') + '</span><span>' + fmt(g.total) + ' disponible</span></div>';
          html += '<div class="px-iv-row px-iv-row-head"><span>Ubicación</span><span class="px-iv-num">Disponible</span><span class="px-iv-num px-hide-mobile">Físico</span><span class="px-iv-num px-hide-mobile">Reservado</span></div>';
          g.rows.forEach(function (r) {
            html += '<div class="px-iv-row"><span>' + esc(r.location_name) + (r.is_quarantine ? ' · Cuarentena' : '') + '</span><span class="px-iv-num">' + fmt(r.available) + '</span><span class="px-iv-num px-hide-mobile">' + fmt(r.physical) + '</span><span class="px-iv-num px-hide-mobile">' + fmt(r.reserved) + '</span></div>';
          });
          var transit = transitForGroup(p.in_transit || [], g);
          if (transit > 0) html += '<div class="px-iv-transit">En tránsito hacia esta ubicación: <strong>' + fmt(transit) + '</strong></div>';
          html += '</div>';
        });
      }
      if ((p.in_transit || []).length && !groups.length) {
        var totalTransit = p.in_transit.reduce(function (s, r) { return s + Number(r.quantity || 0); }, 0);
        html += '<div class="px-iv-group"><div class="px-iv-transit">En tránsito: <strong>' + fmt(totalTransit) + '</strong></div></div>';
      }
      return html + '</div>';
    }).join('');
  }

  function loadStockInto(term, result) {
    term = String(term || '').trim();
    if (!result) return;
    if (term.length < 2) {
      result.className = 'px-iv-empty';
      result.innerHTML = 'Escribe al menos 2 caracteres para consultar existencias.';
      return;
    }
    result.className = 'px-iv-empty';
    result.textContent = 'Consultando existencias...';
    if (!window.axios) return;
    window.axios.get('/api/inventory-visibility/search', { params: { q: term }, meta: { skipErrorRedirect: true, skipInitialLoader: true } })
      .then(function (response) {
        result.className = '';
        result.innerHTML = renderProducts(response && response.data ? response.data.products : []);
      })
      .catch(function () {
        result.className = 'px-iv-empty';
        result.textContent = 'No se pudieron consultar las existencias. Intenta nuevamente.';
      });
  }

  function missingRows(issues, term, status) {
    term = norm(term);
    return (issues || []).filter(function (i) {
      if (i.type !== 'missing') return false;
      if (status && status !== 'all' && i.resolution_status !== status) return false;
      if (!term) return true;
      return norm([i.reference, i.product_name, i.product_code, i.from_warehouse, i.to_warehouse, i.reported_by].join(' ')).indexOf(term) !== -1;
    });
  }

  function renderMissingTable(issues, term, status) {
    var missing = missingRows(issues, term, status);
    if (!missing.length) return '<div class="px-iv-empty">No hay faltantes que coincidan con los filtros.</div>';
    return '<div class="px-iv-table-wrap"><table class="px-iv-table"><thead><tr><th>Transferencia</th><th>Producto</th><th>Cantidad</th><th>Origen</th><th>Destino</th><th>Reportado por</th><th>Estado</th></tr></thead><tbody>' + missing.map(function (i) {
      var state = i.resolution_status === 'open' ? 'open' : 'resolved';
      var stateText = i.resolution_status === 'open' ? 'Pendiente' : 'Resuelto';
      return '<tr><td><strong>' + esc(i.reference) + '</strong><br><small>' + esc(i.reported_at || '') + '</small></td><td>' + esc(i.product_name) + (i.variant_name ? '<br><small>' + esc(i.variant_name) + '</small>' : '') + '</td><td><strong>' + fmt(i.quantity) + '</strong></td><td>' + esc(i.from_warehouse || '-') + '</td><td>' + esc(i.to_warehouse || '-') + '</td><td>' + esc(i.reported_by || 'Usuario') + '</td><td><span class="px-iv-status ' + state + '">' + stateText + '</span>' + (i.resolution_code ? '<br><small>' + esc(i.resolution_code) + '</small>' : '') + '</td></tr>';
    }).join('') + '</tbody></table></div>';
  }

  function currentFullPageView() {
    try { return new URL(window.location.href).searchParams.get(FULL_PAGE_PARAM) || ''; }
    catch (e) { return ''; }
  }

  function pageUrl(view) {
    return '/app/damages/list?' + FULL_PAGE_PARAM + '=' + encodeURIComponent(view);
  }

  function hideUnderlyingView(main, page) {
    Array.prototype.forEach.call(main.children, function (child) {
      if (child === page || child.classList.contains('flex-grow-1') || child.tagName.toLowerCase() === 'footer') return;
      if (!child.hasAttribute('data-px-iv-original-display')) child.setAttribute('data-px-iv-original-display', child.style.display || '');
      child.style.display = 'none';
    });
  }

  function renderMissingPage(page) {
    page.innerHTML = '<div class="px-iv-page-title">Faltantes de transferencias</div>' +
      '<div class="px-iv-page-subtitle">Mercancía enviada que no fue recibida físicamente en el destino. Los faltantes permanecen trazables hasta su resolución.</div>' +
      '<div class="px-iv-page-card"><div class="px-iv-toolbar">' +
      '<div class="px-iv-search">' + icon('search') + '<input id="px-missing-page-search" type="search" placeholder="Buscar transferencia, producto, origen o destino"></div>' +
      '<div style="display:flex;gap:8px"><select id="px-missing-page-status" class="px-iv-filter"><option value="all">Todos los estados</option><option value="open">Pendientes</option><option value="resolved">Resueltos</option></select>' +
      '<button id="px-missing-page-refresh" class="px-iv-refresh" type="button">' + icon('refresh') + 'Actualizar</button></div></div>' +
      '<div id="px-missing-page-summary" class="px-iv-summary"></div><div id="px-missing-page-results" class="px-iv-empty">Cargando faltantes...</div></div>';

    var allIssues = [];
    var search = page.querySelector('#px-missing-page-search');
    var status = page.querySelector('#px-missing-page-status');
    var results = page.querySelector('#px-missing-page-results');
    var summary = page.querySelector('#px-missing-page-summary');

    function paint() {
      var rows = missingRows(allIssues, search.value, status.value);
      summary.innerHTML = '<strong>' + rows.length + '</strong> faltante(s) mostrado(s)';
      results.className = '';
      results.innerHTML = renderMissingTable(allIssues, search.value, status.value);
    }

    function load() {
      results.className = 'px-iv-empty';
      results.textContent = 'Cargando faltantes...';
      if (!window.axios) return;
      window.axios.get('/api/transfer-logistics/issues', { meta: { skipErrorRedirect: true, skipInitialLoader: true } })
        .then(function (response) {
          allIssues = response && response.data && Array.isArray(response.data.issues) ? response.data.issues : [];
          paint();
        })
        .catch(function (error) {
          results.className = 'px-iv-empty';
          results.textContent = error && error.response && error.response.status === 403 ? 'No tienes permiso para consultar faltantes.' : 'No se pudieron cargar los faltantes.';
        });
    }

    search.addEventListener('input', paint);
    status.addEventListener('change', paint);
    page.querySelector('#px-missing-page-refresh').addEventListener('click', load);
    load();
  }

  function renderStockPage(page) {
    page.innerHTML = '<div class="px-iv-page-title">Existencias por ubicación</div>' +
      '<div class="px-iv-page-subtitle">Consulta el inventario disponible en tu sucursal, otras sucursales y centros de distribución.</div>' +
      '<div class="px-iv-page-card"><div class="px-iv-search">' + icon('search') + '<input id="px-stock-page-search" type="search" autocomplete="off" placeholder="Buscar producto por nombre o código"></div>' +
      '<div id="px-stock-page-results" class="px-iv-empty">Escribe al menos 2 caracteres para consultar existencias.</div></div>';
    var input = page.querySelector('#px-stock-page-search');
    var result = page.querySelector('#px-stock-page-results');
    input.addEventListener('input', function () {
      if (stockTimer) clearTimeout(stockTimer);
      stockTimer = setTimeout(function () { loadStockInto(input.value, result); }, 280);
    });
    window.setTimeout(function () { input.focus(); }, 60);
  }

  function mountFullPage() {
    var view = currentFullPageView();
    if (view !== 'missing' && view !== 'stock') return;
    var main = document.querySelector('.main-content-wrap');
    if (!main) return;
    var page = main.querySelector(':scope > .px-iv-full-page');
    if (!page) {
      page = document.createElement('section');
      page.className = 'px-iv-full-page';
      main.insertBefore(page, main.firstElementChild);
    }
    hideUnderlyingView(main, page);
    if (page.getAttribute('data-px-view') === view) return;
    page.setAttribute('data-px-view', view);
    if (view === 'missing') renderMissingPage(page);
    else renderStockPage(page);
  }

  function makeMenuLink(text, view, iconName) {
    var li = document.createElement('li');
    li.className = 'submenu-item prodex-sidebar2-generated px-iv-menu-' + view;
    if (currentFullPageView() === view) li.classList.add('px-iv-menu-active');
    var a = document.createElement('a');
    a.href = pageUrl(view);
    a.className = 'prodex-sidebar2-section-link';
    a.innerHTML = '<span class="prodex-sidebar2-mini-icon">' + icon(iconName) + '</span><span>' + esc(text) + '</span>';
    li.appendChild(a);
    return li;
  }

  function injectInventoryLinks() {
    var lists = document.querySelectorAll('.vertical-sidebar-wrapper .submenu');
    Array.prototype.forEach.call(lists, function (list) {
      var damage = Array.prototype.slice.call(list.querySelectorAll('a')).find(function (a) { return norm(a.textContent) === 'danos'; });
      if (!damage) return;
      var damageLi = damage.closest('li');
      if (!damageLi) return;
      if (!list.querySelector('.px-iv-menu-missing')) damageLi.insertAdjacentElement('afterend', makeMenuLink('Faltantes', 'missing', 'missing'));
      var missingLi = list.querySelector('.px-iv-menu-missing');
      if (!list.querySelector('.px-iv-menu-stock')) missingLi.insertAdjacentElement('afterend', makeMenuLink('Existencias por ubicación', 'stock', 'stock'));
    });
  }

  function injectTopButton() {
    if (document.getElementById('px-stock-visibility-nav')) return;
    var posButton = Array.prototype.slice.call(document.querySelectorAll('a,button')).find(function (el) {
      return norm(el.textContent) === 'pos' && el.getBoundingClientRect().top < 90;
    });
    if (!posButton || !posButton.parentNode) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'px-stock-visibility-nav';
    btn.className = 'px-iv-top-btn';
    btn.innerHTML = icon('stock') + '<span>Existencias</span>';
    btn.addEventListener('click', openStock);
    posButton.parentNode.insertBefore(btn, posButton.nextSibling);
  }

  function run() {
    installStyle();
    ensureStockModal();
    injectInventoryLinks();
    injectTopButton();
    mountFullPage();
  }

  function schedule() {
    [0, 60, 160, 400, 900, 1600].forEach(function (delay) { window.setTimeout(run, delay); });
  }

  document.addEventListener('DOMContentLoaded', schedule, { once: true });
  window.addEventListener('load', schedule, { once: true });
  document.addEventListener('click', function (e) {
    if (e.target && e.target.closest && e.target.closest('.vertical-sidebar-wrapper .nav-link')) schedule();
  }, false);

  if (window.MutationObserver) {
    observer = new MutationObserver(function () { window.setTimeout(run, 30); });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  schedule();
})();
