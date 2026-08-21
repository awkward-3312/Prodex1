(function () {
  'use strict';

  if (window.__prodexTransferIssuesInstalled) return;
  window.__prodexTransferIssuesInstalled = true;

  var API = '/api/transfer-logistics/issues';
  var state = { allowed: false, canManage: false, openCount: 0, issues: [], resolutions: {} };
  var timer = null;

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function api(method, url, data) {
    if (!window.axios) return Promise.reject(new Error('Axios no disponible'));
    return window.axios({ method: method, url: API + (url || ''), data: data });
  }

  function css() {
    if (document.getElementById('prodex-transfer-issues-style')) return;
    var style = document.createElement('style');
    style.id = 'prodex-transfer-issues-style';
    style.textContent = [
      '.px-ti-btn{position:relative;display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;margin-left:5px;border:1px solid #dce3eb;border-radius:8px;background:#fff;color:#7c4a03;cursor:pointer}',
      '.px-ti-btn:hover{border-color:#c27703;background:#fffaf0}.px-ti-btn svg{width:19px;height:19px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}',
      '.px-ti-badge{position:absolute;right:-5px;top:-6px;min-width:18px;height:18px;padding:0 5px;display:flex;align-items:center;justify-content:center;border:2px solid #fff;border-radius:999px;background:#c27703;color:#fff;font-size:10px;font-weight:800}',
      '.px-ti-overlay{position:fixed;inset:0;z-index:4200;background:rgba(20,24,35,.58);display:flex;align-items:center;justify-content:center;padding:20px}',
      '.px-ti-modal{width:min(1050px,100%);max-height:90vh;overflow:auto;background:#fff;border-radius:16px;box-shadow:0 28px 75px rgba(15,23,42,.28)}',
      '.px-ti-head{position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid #e8edf3;background:#fff}.px-ti-head h3{margin:0;font-size:18px;color:#172033}.px-ti-head button{border:0;background:transparent;font-size:24px;color:#667085;cursor:pointer}',
      '.px-ti-body{padding:18px 22px}.px-ti-toolbar{display:flex;gap:8px;align-items:center;margin-bottom:14px}.px-ti-toolbar button,.px-ti-resolve button{border:1px solid #d5dde7;border-radius:8px;background:#fff;padding:8px 12px;font-weight:700;color:#475467;cursor:pointer}.px-ti-toolbar button.primary,.px-ti-resolve button.primary{background:#6f35ad;border-color:#6f35ad;color:#fff}',
      '.px-ti-card{border:1px solid #e2e7ed;border-radius:11px;margin-bottom:10px;padding:13px 14px}.px-ti-card.open{border-left:4px solid #c27703}.px-ti-card.resolved{opacity:.72}.px-ti-top{display:flex;justify-content:space-between;gap:12px}.px-ti-title{font-weight:800;color:#202939;font-size:13px}.px-ti-meta{margin-top:4px;color:#667085;font-size:11.5px;line-height:1.45}.px-ti-type{font-size:11px;font-weight:800;padding:4px 7px;border-radius:999px;background:#fff4df;color:#9a5b00;white-space:nowrap}.px-ti-type.defective{background:#fef2f2;color:#b42318}',
      '.px-ti-status{margin-top:8px;font-size:11px;color:#667085}.px-ti-actions{margin-top:10px;display:flex;justify-content:flex-end}.px-ti-actions button{border:0;border-radius:7px;background:#6f35ad;color:#fff;padding:7px 11px;font-size:11px;font-weight:800;cursor:pointer}',
      '.px-ti-empty{padding:36px 18px;text-align:center;color:#7a8494;font-size:12px}.px-ti-error{padding:10px 12px;border:1px solid #fecaca;border-radius:8px;background:#fef2f2;color:#991b1b;font-size:12px}',
      '.px-ti-resolve label{display:block;margin:10px 0 5px;color:#475467;font-size:11px;font-weight:800}.px-ti-resolve select,.px-ti-resolve input,.px-ti-resolve textarea{width:100%;border:1px solid #d5dde7;border-radius:8px;padding:9px 10px}.px-ti-resolve textarea{min-height:90px;resize:vertical}.px-ti-resolve-footer{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}',
      '@media(max-width:700px){.px-ti-overlay{padding:0}.px-ti-modal{height:100vh;max-height:100vh;border-radius:0}.px-ti-body{padding:14px}.px-ti-top{flex-direction:column}}'
    ].join('');
    document.head.appendChild(style);
  }

  function icon() {
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.3 3.7 2.5 17.2A2 2 0 0 0 4.2 20h15.6a2 2 0 0 0 1.7-2.8L13.7 3.7a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>';
  }

  function ensureButton() {
    if (!state.allowed || document.getElementById('px-transfer-issues-btn')) return;
    var host = document.querySelector('.main-header .header-part-right.nav-right, .vertical-top-nav .header-part-right.nav-right');
    if (!host) return;
    var button = document.createElement('button');
    button.id = 'px-transfer-issues-btn';
    button.type = 'button';
    button.className = 'px-ti-btn';
    button.title = 'Incidencias de transferencias';
    button.setAttribute('aria-label', 'Incidencias de transferencias');
    button.innerHTML = icon() + '<span class="px-ti-badge" style="display:none"></span>';
    button.onclick = openIssues;
    var transferBtn = document.getElementById('px-transfer-logistics-btn');
    if (transferBtn && transferBtn.parentNode === host) host.insertBefore(button, transferBtn.nextSibling);
    else host.appendChild(button);
    updateBadge();
  }

  function updateBadge() {
    var badge = document.querySelector('#px-transfer-issues-btn .px-ti-badge');
    if (!badge) return;
    var count = Number(state.openCount || 0);
    badge.textContent = count > 99 ? '99+' : String(count);
    badge.style.display = count > 0 ? 'flex' : 'none';
  }

  function refresh(openAfter) {
    return api('get', '').then(function (response) {
      var data = response.data || {};
      state.allowed = true;
      state.canManage = !!data.can_manage;
      state.openCount = Number(data.open_count || 0);
      state.issues = data.issues || [];
      state.resolutions = data.resolutions || {};
      ensureButton();
      updateBadge();
      if (openAfter) renderIssues();
    }).catch(function (error) {
      if (error && error.response && error.response.status === 403) {
        state.allowed = false;
        var btn = document.getElementById('px-transfer-issues-btn');
        if (btn) btn.remove();
      }
    });
  }

  function openIssues() {
    refresh(true);
  }

  function close() {
    var old = document.getElementById('px-ti-overlay');
    if (old) old.remove();
  }

  function overlay(title, body) {
    close();
    var wrap = document.createElement('div');
    wrap.id = 'px-ti-overlay';
    wrap.className = 'px-ti-overlay';
    wrap.innerHTML = '<div class="px-ti-modal"><div class="px-ti-head"><h3>' + esc(title) + '</h3><button type="button">×</button></div><div class="px-ti-body">' + body + '</div></div>';
    document.body.appendChild(wrap);
    wrap.querySelector('.px-ti-head button').onclick = close;
    wrap.addEventListener('click', function (e) { if (e.target === wrap) close(); });
    return wrap;
  }

  function typeLabel(type) {
    return type === 'defective' ? 'Defectuoso' : 'Faltante';
  }

  function resolutionLabel(code) {
    var labels = {
      confirmed_loss: 'Pérdida confirmada',
      reconciled_by_adjustment: 'Conciliado mediante ajuste de inventario',
      written_off: 'Dado de baja',
      returned_to_origin: 'Devuelto a bodega origen'
    };
    return labels[code] || code || '—';
  }

  function renderIssues() {
    var body = '<div class="px-ti-toolbar"><button type="button" class="primary" data-open>Abiertas (' + state.openCount + ')</button><button type="button" data-all>Historial completo</button><button type="button" data-refresh>Actualizar</button></div><div id="px-ti-list"></div>';
    var wrap = overlay('Incidencias de transferencias', body);
    wrap.querySelector('[data-open]').onclick = function () { renderList(true); };
    wrap.querySelector('[data-all]').onclick = function () { renderList(false); };
    wrap.querySelector('[data-refresh]').onclick = function () { refresh(true); };
    renderList(true);
  }

  function renderList(openOnly) {
    var list = document.getElementById('px-ti-list');
    if (!list) return;
    var rows = state.issues.filter(function (issue) { return !openOnly || issue.resolution_status === 'open'; });
    if (!rows.length) {
      list.innerHTML = '<div class="px-ti-empty">' + (openOnly ? 'No hay incidencias abiertas.' : 'No hay incidencias registradas.') + '</div>';
      return;
    }

    list.innerHTML = rows.map(function (issue) {
      var name = (issue.variant_name ? '[' + issue.variant_name + '] ' : '') + (issue.product_name || 'Producto');
      var status = issue.resolution_status === 'open'
        ? 'Pendiente de resolución'
        : 'Resuelta: ' + resolutionLabel(issue.resolution_code) + (issue.resolution_reference ? ' · Ref. ' + issue.resolution_reference : '');
      return '<div class="px-ti-card ' + esc(issue.resolution_status) + '">' +
        '<div class="px-ti-top"><div><div class="px-ti-title">' + esc(issue.reference) + ' · ' + esc(name) + '</div>' +
        '<div class="px-ti-meta">' + esc(issue.from_warehouse || '') + ' → ' + esc(issue.to_warehouse || '') + '<br>Cantidad: <strong>' + Number(issue.quantity || 0) + '</strong>' + (issue.reported_by ? ' · Reportó: ' + esc(issue.reported_by) : '') + '</div></div>' +
        '<span class="px-ti-type ' + (issue.type === 'defective' ? 'defective' : '') + '">' + typeLabel(issue.type) + '</span></div>' +
        '<div class="px-ti-status">' + esc(status) + (issue.resolution_notes ? '<br>' + esc(issue.resolution_notes) : '') + '</div>' +
        (state.canManage && issue.resolution_status === 'open' ? '<div class="px-ti-actions"><button type="button" data-resolve="' + Number(issue.id) + '">Resolver incidencia</button></div>' : '') + '</div>';
    }).join('');

    Array.prototype.forEach.call(list.querySelectorAll('[data-resolve]'), function (button) {
      button.onclick = function () {
        var id = Number(button.getAttribute('data-resolve'));
        var issue = state.issues.find(function (row) { return Number(row.id) === id; });
        if (issue) renderResolve(issue);
      };
    });
  }

  function renderResolve(issue) {
    var options = state.resolutions[issue.type] || [];
    var body = '<div class="px-ti-resolve"><div class="px-ti-card"><div class="px-ti-title">' + esc(issue.reference) + ' · ' + esc(issue.product_name || 'Producto') + '</div><div class="px-ti-meta">' + esc(typeLabel(issue.type)) + ' · Cantidad ' + Number(issue.quantity || 0) + '</div></div>' +
      '<div id="px-ti-error"></div><label>Resolución</label><select id="px-ti-code"><option value="">Selecciona…</option>' + options.map(function (o) { return '<option value="' + esc(o.value) + '">' + esc(o.label) + '</option>'; }).join('') + '</select>' +
      '<label>Referencia</label><input id="px-ti-reference" maxlength="120" placeholder="Ej. AJ-00045, acta, devolución…"><label>Detalle de la resolución</label><textarea id="px-ti-notes" maxlength="3000" placeholder="Explica qué se verificó y cómo se resolvió."></textarea>' +
      '<div class="px-ti-resolve-footer"><button type="button" data-back>Volver</button><button type="button" class="primary" data-save>Guardar resolución</button></div></div>';
    var wrap = overlay('Resolver incidencia', body);
    wrap.querySelector('[data-back]').onclick = renderIssues;
    wrap.querySelector('[data-save]').onclick = function () { submitResolve(issue.id); };
  }

  function submitResolve(id) {
    var code = (document.getElementById('px-ti-code') || {}).value || '';
    var reference = (document.getElementById('px-ti-reference') || {}).value || '';
    var notes = (document.getElementById('px-ti-notes') || {}).value || '';
    var err = document.getElementById('px-ti-error');
    if (!code || !notes.trim()) {
      if (err) err.innerHTML = '<div class="px-ti-error">Selecciona una resolución y documenta lo ocurrido.</div>';
      return;
    }
    if (code === 'reconciled_by_adjustment' && !reference.trim()) {
      if (err) err.innerHTML = '<div class="px-ti-error">La conciliación mediante ajuste requiere la referencia del ajuste de inventario.</div>';
      return;
    }

    var button = document.querySelector('[data-save]');
    if (button) { button.disabled = true; button.textContent = 'Guardando…'; }
    api('post', '/' + encodeURIComponent(id) + '/resolve', {
      resolution_code: code,
      resolution_reference: reference || null,
      resolution_notes: notes
    }).then(function () {
      return refresh(false);
    }).then(function () {
      renderIssues();
    }).catch(function (error) {
      if (button) { button.disabled = false; button.textContent = 'Guardar resolución'; }
      var data = error && error.response && error.response.data;
      var message = (data && data.message) || 'No se pudo resolver la incidencia.';
      if (data && data.errors) {
        var key = Object.keys(data.errors)[0];
        if (key) message = Array.isArray(data.errors[key]) ? data.errors[key][0] : data.errors[key];
      }
      if (err) err.innerHTML = '<div class="px-ti-error">' + esc(message) + '</div>';
    });
  }

  function init() {
    css();
    refresh(false);
    timer = window.setInterval(function () { refresh(false); }, 60000);
    var observer = new MutationObserver(function () { ensureButton(); });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
