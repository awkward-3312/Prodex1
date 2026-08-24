(function () {
  'use strict';

  if (window.__prodexInventoryVisibilityInstalled) return;
  window.__prodexInventoryVisibilityInstalled = true;

  var stockTimer = null;
  var observer = null;

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
      '.px-iv-search{position:relative;margin-bottom:18px}.px-iv-search svg{position:absolute;left:13px;top:12px;width:18px;height:18px;fill:none;stroke:#98a2b3;stroke-width:2}.px-iv-search input{width:100%;height:42px;padding:0 14px 0 42px;border:1px solid #d0d5dd;border-radius:8px;font-size:13px;outline:none}.px-iv-search input:focus{border-color:#6941c6;box-shadow:0 0 0 3px rgba(105,65,198,.08)}',
      '.px-iv-empty{padding:42px 14px;text-align:center;color:#667085;font-size:13px}',
      '.px-iv-product{border:1px solid #e4e7ec;border-radius:10px;margin-bottom:14px;overflow:hidden}.px-iv-product-head{display:flex;justify-content:space-between;gap:12px;padding:14px 16px;background:#f8fafc}.px-iv-product-name{font-weight:800;color:#101828}.px-iv-product-code{font-size:11px;color:#667085;margin-top:2px}.px-iv-company-total{font-size:12px;color:#475467;text-align:right}.px-iv-company-total strong{display:block;font-size:18px;color:#101828}',
      '.px-iv-group{padding:13px 16px;border-top:1px solid #eaecf0}.px-iv-group.current{background:#faf8ff}.px-iv-group-title{display:flex;justify-content:space-between;gap:10px;margin-bottom:8px;font-size:12px;font-weight:800;color:#344054}.px-iv-current-badge{display:inline-block;margin-left:7px;padding:2px 6px;border-radius:999px;background:#ede9fe;color:#6941c6;font-size:9px;font-weight:800}',
      '.px-iv-row{display:grid;grid-template-columns:minmax(150px,1fr) repeat(3,90px);gap:10px;align-items:center;padding:7px 0;font-size:12px;color:#475467}.px-iv-row+.px-iv-row{border-top:1px dashed #edf0f4}.px-iv-num{text-align:right;font-variant-numeric:tabular-nums}.px-iv-row-head{font-size:10px;text-transform:uppercase;color:#98a2b3;font-weight:800}',
      '.px-iv-transit{margin-top:8px;padding:8px 10px;border-radius:7px;background:#eff6ff;color:#1d4ed8;font-size:11px}',
      '.px-iv-table-wrap{overflow:auto;border:1px solid #e4e7ec;border-radius:9px}.px-iv-table{width:100%;border-collapse:collapse;min-width:820px}.px-iv-table th{padding:10px 12px;background:#f8fafc;text-align:left;font-size:10px;text-transform:uppercase;color:#667085}.px-iv-table td{padding:11px 12px;border-top:1px solid #eaecf0;font-size:12px;color:#344054}.px-iv-status{display:inline-block;padding:3px 7px;border-radius:999px;font-size:10px;font-weight:800}.px-iv-status.open{background:#fff7ed;color:#c2410c}.px-iv-status.resolved{background:#ecfdf3;color:#027a48}',
      '.px-iv-top-btn{display:inline-flex;align-items:center;gap:6px;height:38px;padding:0 11px;margin-right:8px;border:1px solid #d0d5dd;border-radius:8px;background:#fff;color:#475467;font-size:11px;font-weight:800;cursor:pointer}.px-iv-top-btn:hover{border-color:#6941c6;color:#6941c6}.px-iv-top-btn svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2}',
      'body.dark-theme .px-iv-modal,body.dark-theme .px-iv-top-btn{background:#1f2030;color:#e5e7eb}body.dark-theme .px-iv-title,body.dark-theme .px-iv-product-name,body.dark-theme .px-iv-company-total strong{color:#f5f5f5}body.dark-theme .px-iv-product-head,body.dark-theme .px-iv-table th{background:#252638}body.dark-theme .px-iv-product,body.dark-theme .px-iv-table-wrap,body.dark-theme .px-iv-head,body.dark-theme .px-iv-group,body.dark-theme .px-iv-table td{border-color:#34364a}',
      '@media(max-width:700px){.px-iv-overlay{padding:18px 8px}.px-iv-row{grid-template-columns:1fr 72px}.px-iv-row .px-hide-mobile{display:none}.px-iv-top-btn span{display:none}.px-iv-top-btn{width:38px;padding:0;justify-content:center}}'
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

  function ensureModals() {
    var stock = document.getElementById('px-stock-visibility-modal');
    if (!stock) {
      stock = createModal('px-stock-visibility-modal', 'Existencias por ubicación', 'Consulta disponibilidad en tu sucursal, otras sucursales y centros de distribución.',
        '<div class="px-iv-search">' + icon('search') + '<input id="px-stock-search" type="search" autocomplete="off" placeholder="Buscar producto por nombre o código"></div><div id="px-stock-results" class="px-iv-empty">Escribe al menos 2 caracteres para consultar existencias.</div>');
      var input = stock.querySelector('#px-stock-search');
      input.addEventListener('input', function () {
        if (stockTimer) clearTimeout(stockTimer);
        stockTimer = setTimeout(function () { loadStock(input.value); }, 280);
      });
    }

    var missing = document.getElementById('px-missing-modal');
    if (!missing) {
      missing = createModal('px-missing-modal', 'Faltantes de transferencias', 'Mercancía enviada que no fue recibida físicamente en el destino.',
        '<div id="px-missing-results" class="px-iv-empty">Cargando faltantes...</div>');
    }
  }

  function openStock() {
    ensureModals();
    var modal = document.getElementById('px-stock-visibility-modal');
    modal.classList.add('open');
    var input = modal.querySelector('#px-stock-search');
    window.setTimeout(function () { input.focus(); }, 60);
  }

  function openMissing() {
    ensureModals();
    document.getElementById('px-missing-modal').classList.add('open');
    loadMissing();
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

  function loadStock(term) {
    var result = document.getElementById('px-stock-results');
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

  function renderMissing(issues) {
    var missing = (issues || []).filter(function (i) { return i.type === 'missing'; });
    if (!missing.length) return '<div class="px-iv-empty">No hay faltantes registrados en transferencias.</div>';
    return '<div class="px-iv-table-wrap"><table class="px-iv-table"><thead><tr><th>Transferencia</th><th>Producto</th><th>Cantidad</th><th>Origen</th><th>Destino</th><th>Reportado por</th><th>Estado</th></tr></thead><tbody>' + missing.map(function (i) {
      var status = i.resolution_status === 'open' ? 'open' : 'resolved';
      var statusText = i.resolution_status === 'open' ? 'Pendiente' : 'Resuelto';
      return '<tr><td><strong>' + esc(i.reference) + '</strong><br><small>' + esc(i.reported_at || '') + '</small></td><td>' + esc(i.product_name) + (i.variant_name ? '<br><small>' + esc(i.variant_name) + '</small>' : '') + '</td><td><strong>' + fmt(i.quantity) + '</strong></td><td>' + esc(i.from_warehouse || '-') + '</td><td>' + esc(i.to_warehouse || '-') + '</td><td>' + esc(i.reported_by || 'Usuario') + '</td><td><span class="px-iv-status ' + status + '">' + statusText + '</span>' + (i.resolution_code ? '<br><small>' + esc(i.resolution_code) + '</small>' : '') + '</td></tr>';
    }).join('') + '</tbody></table></div>';
  }

  function loadMissing() {
    var result = document.getElementById('px-missing-results');
    if (!result || !window.axios) return;
    result.className = 'px-iv-empty';
    result.textContent = 'Cargando faltantes...';
    window.axios.get('/api/transfer-logistics/issues', { meta: { skipErrorRedirect: true, skipInitialLoader: true } })
      .then(function (response) {
        result.className = '';
        result.innerHTML = renderMissing(response && response.data ? response.data.issues : []);
      })
      .catch(function (error) {
        result.className = 'px-iv-empty';
        result.textContent = error && error.response && error.response.status === 403 ? 'No tienes permiso para consultar discrepancias de transferencias.' : 'No se pudieron cargar los faltantes.';
      });
  }

  function makeMenuLink(text, kind, iconName) {
    var li = document.createElement('li');
    li.className = 'submenu-item prodex-sidebar2-generated px-iv-menu-' + kind;
    var a = document.createElement('a');
    a.href = '#';
    a.className = 'prodex-sidebar2-section-link';
    a.innerHTML = '<span class="prodex-sidebar2-mini-icon">' + icon(iconName) + '</span><span>' + esc(text) + '</span>';
    a.addEventListener('click', function (e) { e.preventDefault(); kind === 'missing' ? openMissing() : openStock(); });
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
    ensureModals();
    injectInventoryLinks();
    injectTopButton();
  }

  function install() {
    run();
    observer = new MutationObserver(function () { window.requestAnimationFrame(run); });
    observer.observe(document.body, { childList: true, subtree: true });
    [100, 300, 700, 1400, 2400].forEach(function (delay) { window.setTimeout(run, delay); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install);
  else install();
})();
