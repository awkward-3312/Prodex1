(function () {
  'use strict';

  if (window.__prodexTransferWorkflowInstalled) return;
  window.__prodexTransferWorkflowInstalled = true;

  var API = '/api/transfer-workflow';
  var lastPath = '';

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function css() {
    if (document.getElementById('px-transfer-workflow-style')) return;
    var style = document.createElement('style');
    style.id = 'px-transfer-workflow-style';
    style.textContent = [
      '.px-twf-ref{color:#5b21b6!important;font-weight:700!important;cursor:pointer!important;text-decoration:underline;text-decoration-style:dotted;text-underline-offset:3px;}',
      '.px-twf-hint{margin:0 0 14px;padding:11px 14px;border:1px solid #dbe5ef;border-radius:10px;background:#f8fafc;color:#475467;font-size:12px;}',
      '.px-twf-hint strong{color:#182230}.px-twf-hint span{color:#6941c6;font-weight:700;}',
      '.px-twf-overlay{position:fixed;inset:0;z-index:5200;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(15,23,42,.58);}',
      '.px-twf-modal{width:min(900px,100%);max-height:92vh;overflow:auto;background:#fff;border-radius:16px;box-shadow:0 28px 80px rgba(15,23,42,.28);}',
      '.px-twf-head{position:sticky;top:0;z-index:3;display:flex;justify-content:space-between;gap:18px;padding:18px 22px;border-bottom:1px solid #e7ecf2;background:#fff;}',
      '.px-twf-head h3{margin:0;font-size:19px;color:#172033}.px-twf-head p{margin:4px 0 0;color:#667085;font-size:12px}.px-twf-close{border:0;background:transparent;font-size:25px;color:#667085;cursor:pointer;}',
      '.px-twf-body{padding:20px 22px}.px-twf-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:18px;}',
      '.px-twf-card{padding:12px;border:1px solid #e4e9f0;border-radius:10px;background:#f9fafb}.px-twf-card small{display:block;color:#7b8494;font-size:10px;font-weight:800;text-transform:uppercase}.px-twf-card strong{display:block;margin-top:4px;color:#202939;font-size:13px;}',
      '.px-twf-flow{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:0 0 18px;padding:12px;border-radius:10px;background:#f7f5ff;}.px-twf-step{padding:6px 9px;border-radius:999px;background:#fff;border:1px solid #ddd6fe;color:#5b21b6;font-size:11px;font-weight:800}.px-twf-arrow{color:#98a2b3;font-size:12px}',
      '.px-twf-actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}.px-twf-btn{min-height:38px;padding:0 14px;border-radius:8px;border:1px solid #d0d5dd;background:#fff;color:#344054;font-weight:700;cursor:pointer}.px-twf-btn.primary{background:#6f35ad;border-color:#6f35ad;color:#fff}.px-twf-btn.danger{border-color:#fda29b;color:#b42318;background:#fff}.px-twf-btn:disabled{opacity:.55;cursor:not-allowed}',
      '.px-twf-title{margin:4px 0 12px;font-size:14px;color:#202939}.px-twf-timeline{position:relative;margin-left:7px;padding-left:22px;border-left:2px solid #e4e7ec}.px-twf-event{position:relative;padding:0 0 17px}.px-twf-event:before{content:"";position:absolute;left:-29px;top:3px;width:12px;height:12px;border-radius:50%;background:#7f56d9;border:3px solid #f4ebff}.px-twf-event strong{display:block;color:#202939;font-size:12.5px}.px-twf-event span{display:block;margin-top:2px;color:#667085;font-size:11.5px}.px-twf-event small{display:block;margin-top:3px;color:#98a2b3;font-size:10.5px}.px-twf-empty{padding:22px;text-align:center;color:#7a8494;font-size:12px;border:1px dashed #d0d5dd;border-radius:10px}',
      '.px-twf-error{margin:0 0 14px;padding:10px 12px;border:1px solid #fecaca;border-radius:8px;background:#fef2f2;color:#991b1b;font-size:12px}',
      '@media(max-width:700px){.px-twf-overlay{padding:0}.px-twf-modal{height:100vh;max-height:100vh;border-radius:0}.px-twf-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.px-twf-body{padding:15px}}'
    ].join('');
    document.head.appendChild(style);
  }

  function api(method, url, data) {
    if (!window.axios) return Promise.reject({message: 'Axios no disponible'});
    return window.axios({ method: method, url: API + url, data: data, meta: { skipErrorRedirect: true, skipInitialLoader: true } });
  }

  function normalize(response) {
    if (response && response.data) return response.data;
    return response || {};
  }

  function errorText(error) {
    var data = error && error.response && error.response.data ? error.response.data : error;
    if (data && data.errors) {
      var messages = [];
      Object.keys(data.errors).forEach(function (key) {
        var value = data.errors[key];
        if (Array.isArray(value)) messages = messages.concat(value);
        else if (value) messages.push(value);
      });
      if (messages.length) return messages.join(' ');
    }
    return (data && (data.message || data.error)) || 'No se pudo completar la acción.';
  }

  function eventLabel(type) {
    return ({
      created: 'Transferencia creada',
      approved: 'Transferencia aprobada',
      rejected: 'Transferencia rechazada',
      rejection_note: 'Motivo del rechazo',
      dispatched: 'Transferencia despachada',
      partially_received: 'Recepción parcial',
      received: 'Transferencia recibida',
      received_with_issues: 'Recibida con incidencias',
      discrepancy_reported: 'Incidencia reportada',
      discrepancy_resolved: 'Incidencia resuelta'
    })[type] || String(type || 'Evento').replace(/_/g, ' ');
  }

  function statusLabel(value) {
    return ({pending:'Pendiente', in_transit:'En tránsito', partially_received:'Recepción parcial', received:'Recibida', received_with_issues:'Recibida con incidencias'})[value] || value || 'Pendiente';
  }

  function approvalLabel(value) {
    return ({pending:'Pendiente de aprobación', approved:'Aprobada', rejected:'Rechazada'})[value] || value || 'Pendiente de aprobación';
  }

  function formatDate(value) {
    if (!value) return '';
    var date = new Date(String(value).replace(' ', 'T') + (String(value).indexOf('T') >= 0 ? '' : 'Z'));
    if (isNaN(date.getTime())) date = new Date(value);
    return isNaN(date.getTime()) ? value : date.toLocaleString('es-HN', { dateStyle: 'short', timeStyle: 'medium' });
  }

  function openByReference(ref) {
    css();
    api('get', '/reference/' + encodeURIComponent(ref)).then(function (response) {
      render(normalize(response));
    }).catch(function (error) {
      window.alert(errorText(error));
    });
  }

  function render(data) {
    close();
    var t = data.transfer || {};
    var actions = data.actions || {};
    var events = data.events || [];
    var overlay = document.createElement('div');
    overlay.className = 'px-twf-overlay';
    overlay.id = 'px-transfer-workflow-overlay';
    overlay.innerHTML = '<div class="px-twf-modal">' +
      '<div class="px-twf-head"><div><h3>' + esc(t.reference || 'Transferencia') + '</h3><p>Flujo operativo y auditoría de la transferencia</p></div><button class="px-twf-close" type="button">×</button></div>' +
      '<div class="px-twf-body"><div class="px-twf-error" style="display:none"></div>' +
      '<div class="px-twf-summary">' +
        '<div class="px-twf-card"><small>Origen</small><strong>' + esc(t.from || '—') + '</strong></div>' +
        '<div class="px-twf-card"><small>Destino</small><strong>' + esc(t.to || '—') + '</strong></div>' +
        '<div class="px-twf-card"><small>Aprobación</small><strong>' + esc(approvalLabel(t.approval_status)) + '</strong></div>' +
        '<div class="px-twf-card"><small>Logística</small><strong>' + esc(statusLabel(t.logistics_status)) + '</strong></div>' +
      '</div>' +
      '<div class="px-twf-flow"><span class="px-twf-step">1. Aprobar</span><span class="px-twf-arrow">→</span><span class="px-twf-step">2. Despachar</span><span class="px-twf-arrow">→</span><span class="px-twf-step">3. En tránsito</span><span class="px-twf-arrow">→</span><span class="px-twf-step">4. Recibir</span></div>' +
      '<div class="px-twf-actions">' +
        (actions.can_approve ? '<button class="px-twf-btn primary" data-action="approve">Aprobar transferencia</button>' : '') +
        (actions.can_reject ? '<button class="px-twf-btn danger" data-action="reject">Rechazar</button>' : '') +
        (actions.can_dispatch ? '<button class="px-twf-btn primary" data-action="dispatch">Despachar ahora</button>' : '') +
        '<button class="px-twf-btn" data-action="refresh">Actualizar</button>' +
      '</div>' +
      '<h4 class="px-twf-title">Historial de la transferencia</h4>' +
      (events.length ? '<div class="px-twf-timeline">' + events.map(function (e) {
        var detail = '';
        if (e.event_type === 'rejection_note' && e.payload && e.payload.reason) detail = e.payload.reason;
        else if (e.payload && e.payload.has_issues) detail = 'Se registraron incidencias durante la recepción.';
        return '<div class="px-twf-event"><strong>' + esc(eventLabel(e.event_type)) + '</strong><span>' + esc(e.actor_name || 'Sistema') + (detail ? ' · ' + esc(detail) : '') + '</span><small>' + esc(formatDate(e.created_at)) + '</small></div>';
      }).join('') + '</div>' : '<div class="px-twf-empty">Todavía no hay eventos registrados para esta transferencia.</div>') +
      '</div></div>';
    document.body.appendChild(overlay);
    overlay.querySelector('.px-twf-close').onclick = close;
    overlay.addEventListener('click', function (event) { if (event.target === overlay) close(); });
    Array.prototype.forEach.call(overlay.querySelectorAll('[data-action]'), function (button) {
      button.onclick = function () { act(button.getAttribute('data-action'), t.id, t.reference, overlay); };
    });
  }

  function act(action, id, reference, overlay) {
    if (action === 'refresh') return openByReference(reference);
    if (action === 'reject') {
      var reason = window.prompt('Motivo del rechazo (opcional):', '');
      if (reason === null) return;
      return perform('/' + id + '/reject', { reason: reason }, reference, overlay);
    }
    if (action === 'approve' && !window.confirm('¿Aprobar esta transferencia? Aprobar no moverá inventario hasta que se despache.')) return;
    if (action === 'dispatch' && !window.confirm('¿Despachar esta transferencia? El stock saldrá físicamente del origen y quedará en tránsito.')) return;
    perform('/' + id + '/' + action, {}, reference, overlay);
  }

  function perform(url, payload, reference, overlay) {
    var buttons = overlay.querySelectorAll('.px-twf-btn');
    Array.prototype.forEach.call(buttons, function (b) { b.disabled = true; });
    var err = overlay.querySelector('.px-twf-error');
    err.style.display = 'none';
    api('post', url, payload).then(function () {
      if (window.Fire && typeof window.Fire.$emit === 'function') {
        window.Fire.$emit('Approve_Transfer');
        window.Fire.$emit('Update_Transfer');
      }
      openByReference(reference);
    }).catch(function (error) {
      err.textContent = errorText(error);
      err.style.display = 'block';
      Array.prototype.forEach.call(buttons, function (b) { b.disabled = false; });
    });
  }

  function close() {
    var old = document.getElementById('px-transfer-workflow-overlay');
    if (old) old.remove();
  }

  function referenceColumn(table) {
    var headers = table.querySelectorAll('thead th');
    for (var i = 0; i < headers.length; i += 1) {
      var label = (headers[i].textContent || '').trim().toLowerCase();
      if (label.indexOf('referencia') >= 0 || label.indexOf('reference') >= 0 || label.indexOf('référence') >= 0) return i;
    }
    return -1;
  }

  function enhanceReferences() {
    Array.prototype.forEach.call(document.querySelectorAll('table, .vgt-table'), function (table) {
      var column = referenceColumn(table);
      if (column < 0) return;
      Array.prototype.forEach.call(table.querySelectorAll('tbody tr'), function (row) {
        var cells = row.querySelectorAll('td');
        var cell = cells[column];
        if (!cell || cell.__pxTransferWorkflow) return;
        var text = (cell.textContent || '').trim();
        if (!text || text.length > 100 || /\s/.test(text)) return;
        cell.__pxTransferWorkflow = true;
        cell.classList.add('px-twf-ref');
        cell.title = 'Abrir flujo e historial';
        cell.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();
          openByReference(text);
        });
      });
    });
  }

  function enhance() {
    var path = location.pathname || '';
    if (path.indexOf('/app/transfers/list') < 0) return;
    css();
    var main = document.querySelector('.main-content');
    if (main && !document.getElementById('px-transfer-workflow-hint')) {
      var hint = document.createElement('div');
      hint.id = 'px-transfer-workflow-hint';
      hint.className = 'px-twf-hint';
      hint.innerHTML = '<strong>Flujo de transferencias:</strong> haz clic en la <span>referencia</span> para aprobar, despachar y revisar quién hizo cada movimiento.';
      var bread = main.querySelector('.breadcrumb, .breadcumb, nav');
      if (bread && bread.nextSibling) bread.parentNode.insertBefore(hint, bread.nextSibling);
      else main.insertBefore(hint, main.firstChild);
    }
    enhanceReferences();
  }

  setInterval(function () {
    var path = location.pathname || '';
    if (path !== lastPath) lastPath = path;
    enhance();
  }, 600);
  document.addEventListener('DOMContentLoaded', enhance);
})();
