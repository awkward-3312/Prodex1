(function () {
  'use strict';

  if (window.__prodexTransferLogisticsInstalled) return;
  window.__prodexTransferLogisticsInstalled = true;

  var API = '/api/transfer-logistics';
  var state = { allowed: false, unread: 0, incoming: [], notifications: [], scanner: null, active: null };
  var pollTimer = null;

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function css() {
    if (document.getElementById('prodex-transfer-logistics-style')) return;
    var style = document.createElement('style');
    style.id = 'prodex-transfer-logistics-style';
    style.textContent = [
      '.px-tl-btn{position:relative;display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border:1px solid #dce3eb;border-radius:8px;background:#fff;color:#4b5565;cursor:pointer;transition:.15s;}',
      '.px-tl-btn:hover{border-color:#6f35ad;color:#6f35ad;background:#faf7fd;}',
      '.px-tl-btn svg{width:19px;height:19px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;}',
      '.px-tl-badge{position:absolute;right:-5px;top:-6px;min-width:18px;height:18px;padding:0 5px;display:flex;align-items:center;justify-content:center;border:2px solid #fff;border-radius:999px;background:#6f35ad;color:#fff;font-size:10px;font-weight:800;}',
      '.px-tl-panel{position:fixed;right:20px;top:68px;width:min(390px,calc(100vw - 28px));max-height:70vh;overflow:auto;background:#fff;border:1px solid #e1e6ed;border-radius:14px;box-shadow:0 18px 45px rgba(17,24,39,.18);z-index:3000;}',
      '.px-tl-panel-head{display:flex;align-items:center;justify-content:space-between;padding:15px 16px;border-bottom:1px solid #edf0f4;}',
      '.px-tl-panel-head strong{font-size:14px;color:#172033;}.px-tl-panel-head button{border:0;background:transparent;font-size:20px;color:#7b8495;cursor:pointer;}',
      '.px-tl-panel-actions{display:flex;gap:8px;padding:12px 14px;border-bottom:1px solid #edf0f4;}',
      '.px-tl-action{flex:1;border:1px solid #d8e0e9;background:#fff;border-radius:8px;padding:8px 10px;font-size:12px;font-weight:700;color:#475467;cursor:pointer;}',
      '.px-tl-action.primary{background:#6f35ad;border-color:#6f35ad;color:#fff;}',
      '.px-tl-notif{display:block;width:100%;padding:13px 15px;border:0;border-bottom:1px solid #f0f2f5;background:#fff;text-align:left;cursor:pointer;}',
      '.px-tl-notif:hover{background:#faf8fc}.px-tl-notif.unread{background:#fbf8ff;}',
      '.px-tl-notif strong{display:block;color:#202939;font-size:12.5px}.px-tl-notif span{display:block;margin-top:3px;color:#667085;font-size:11.5px;line-height:1.4}.px-tl-notif small{display:block;margin-top:5px;color:#98a2b3;font-size:10.5px;}',
      '.px-tl-empty{padding:30px 18px;text-align:center;color:#7a8494;font-size:12px;}',
      '.px-tl-overlay{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(20,24,35,.58);z-index:4100;}',
      '.px-tl-modal{width:min(1000px,100%);max-height:90vh;overflow:auto;background:#fff;border-radius:16px;box-shadow:0 28px 75px rgba(15,23,42,.28);}',
      '.px-tl-modal.sm{width:min(560px,100%)}',
      '.px-tl-modal-head{position:sticky;top:0;z-index:2;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:18px 22px;border-bottom:1px solid #e8edf3;background:#fff;}',
      '.px-tl-modal-head h3{margin:0;color:#172033;font-size:18px}.px-tl-modal-head p{margin:3px 0 0;color:#667085;font-size:12px}.px-tl-close{border:0;background:transparent;color:#667085;font-size:24px;cursor:pointer;}',
      '.px-tl-body{padding:20px 22px}.px-tl-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:18px;}',
      '.px-tl-card{padding:12px;border:1px solid #e3e8ef;border-radius:10px;background:#f9fbfc}.px-tl-card small{display:block;color:#7a8494;font-size:10px;text-transform:uppercase;font-weight:800}.px-tl-card strong{display:block;margin-top:3px;color:#202939;font-size:13px;}',
      '.px-tl-table-wrap{overflow:auto;border:1px solid #e2e7ed;border-radius:11px}.px-tl-table{width:100%;border-collapse:collapse;min-width:780px}.px-tl-table th{padding:10px 9px;background:#f7f9fb;color:#667085;font-size:10px;text-transform:uppercase;text-align:left}.px-tl-table td{padding:10px 9px;border-top:1px solid #edf0f3;color:#344054;font-size:12px;vertical-align:middle}.px-tl-table input{width:88px;height:36px;border:1px solid #d5dde7;border-radius:7px;padding:0 8px}.px-tl-table input:focus{outline:0;border-color:#6f35ad;box-shadow:0 0 0 3px rgba(111,53,173,.1)}',
      '.px-tl-product strong{display:block;color:#202939}.px-tl-product span{display:block;color:#7a8494;font-size:10.5px;margin-top:2px}.px-tl-status-good{color:#18794e;font-weight:700}.px-tl-status-warn{color:#b54708;font-weight:700}',
      '.px-tl-footer{position:sticky;bottom:0;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 22px;border-top:1px solid #e8edf3;background:#fbfcfd}.px-tl-footer-note{color:#667085;font-size:11.5px}.px-tl-footer-actions{display:flex;gap:8px}.px-tl-footer button{min-height:38px;padding:0 16px;border-radius:8px;border:1px solid #d5dde7;background:#fff;color:#475467;font-weight:700;cursor:pointer}.px-tl-footer button.primary{border-color:#6f35ad;background:#6f35ad;color:#fff}.px-tl-footer button:disabled{opacity:.55;cursor:not-allowed}',
      '.px-tl-quick{border:0;background:transparent;color:#6f35ad;font-size:11px;font-weight:800;cursor:pointer;padding:0}.px-tl-error{margin:12px 0;padding:10px 12px;border:1px solid #fecaca;border-radius:8px;background:#fef2f2;color:#991b1b;font-size:12px}.px-tl-note{width:100%;min-height:70px;border:1px solid #d5dde7;border-radius:8px;padding:10px;resize:vertical;}',
      '.px-tl-scanner{max-width:460px;margin:0 auto}.px-tl-manual{display:flex;gap:8px;margin-top:14px}.px-tl-manual input{flex:1;min-width:0;height:40px;border:1px solid #d5dde7;border-radius:8px;padding:0 10px}.px-tl-manual button{border:0;border-radius:8px;background:#6f35ad;color:#fff;padding:0 14px;font-weight:700;cursor:pointer}',
      '.px-tl-qr{text-align:center}.px-tl-qr-box{width:240px;min-height:240px;margin:16px auto;display:flex;align-items:center;justify-content:center}.px-tl-qr-box img,.px-tl-qr-box canvas{max-width:240px!important;max-height:240px!important}.px-tl-token{padding:9px;border-radius:8px;background:#f5f7fa;color:#475467;font-family:monospace;font-size:11px;word-break:break-all}',
      '.px-tl-toast{position:fixed;right:20px;bottom:22px;width:min(360px,calc(100vw - 28px));padding:14px 15px;border:1px solid #dfd5eb;border-radius:12px;background:#fff;box-shadow:0 16px 40px rgba(17,24,39,.18);z-index:3900;cursor:pointer}.px-tl-toast strong{display:block;color:#4e2580;font-size:13px}.px-tl-toast span{display:block;margin-top:4px;color:#667085;font-size:11.5px;line-height:1.4}',
      '@media(max-width:700px){.px-tl-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.px-tl-overlay{padding:0}.px-tl-modal{max-height:100vh;height:100vh;border-radius:0}.px-tl-body{padding:15px}.px-tl-footer{padding:12px 15px;flex-direction:column;align-items:stretch}.px-tl-footer-actions{justify-content:flex-end}.px-tl-panel{right:10px;top:62px}.px-tl-btn{width:36px;height:36px}}',
      'body.dark-theme .px-tl-btn,body.dark-theme .px-tl-panel,body.dark-theme .px-tl-modal,body.dark-theme .px-tl-modal-head,body.dark-theme .px-tl-notif{background:#1f2030;color:#e5e7eb;border-color:#34364a}body.dark-theme .px-tl-modal-head h3,body.dark-theme .px-tl-notif strong{color:#f4f4f5}'
    ].join('');
    document.head.appendChild(style);
  }

  function api(method, url, data) {
    if (!window.axios) return Promise.reject(new Error('Axios no disponible'));
    return window.axios({
      method: method,
      url: API + url,
      data: data,
      // These calls are capability-aware background probes. A 403 simply means
      // the current user is not the designated receiver; it must never hijack
      // the whole SPA and send the user to the global unauthorized page.
      meta: { skipErrorRedirect: true, skipInitialLoader: true }
    });
  }

  function truckSvg() {
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17h4V5H2v12h3"/><path d="M14 9h4l4 4v4h-3"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="16.5" cy="17.5" r="2.5"/></svg>';
  }

  function ensureHeaderButton() {
    if (!state.allowed || document.getElementById('px-transfer-logistics-btn')) return;
    var host = document.querySelector('.main-header .header-part-right.nav-right, .vertical-top-nav .header-part-right.nav-right');
    if (!host) return;
    var button = document.createElement('button');
    button.id = 'px-transfer-logistics-btn';
    button.type = 'button';
    button.className = 'px-tl-btn';
    button.title = 'Transferencias por recibir';
    button.setAttribute('aria-label', 'Transferencias por recibir');
    button.innerHTML = truckSvg() + '<span class="px-tl-badge" style="display:none"></span>';
    button.addEventListener('click', function () { togglePanel(); });

    var notificationHost = host.querySelector('#notif-dd');
    var parent = notificationHost && notificationHost.closest ? notificationHost.closest('.dropdown') : null;
    if (parent) host.insertBefore(button, parent);
    else host.appendChild(button);
    updateBadge();
  }

  function updateBadge() {
    var badge = document.querySelector('#px-transfer-logistics-btn .px-tl-badge');
    if (!badge) return;
    var count = Number(state.unread || 0);
    badge.textContent = count > 99 ? '99+' : String(count);
    badge.style.display = count > 0 ? 'flex' : 'none';
  }

  function togglePanel(forceOpen) {
    var old = document.getElementById('px-transfer-logistics-panel');
    if (old) {
      old.remove();
      if (!forceOpen) return;
    }
    renderPanel();
  }

  function renderPanel() {
    var panel = document.createElement('div');
    panel.id = 'px-transfer-logistics-panel';
    panel.className = 'px-tl-panel';
    panel.innerHTML = '<div class="px-tl-panel-head"><strong>Transferencias por recibir</strong><button type="button">×</button></div>' +
      '<div class="px-tl-panel-actions"><button class="px-tl-action primary" data-action="scan">Escanear QR</button><button class="px-tl-action" data-action="refresh">Actualizar</button></div>' +
      '<div class="px-tl-panel-list"></div>';
    document.body.appendChild(panel);
    panel.querySelector('.px-tl-panel-head button').onclick = function () { panel.remove(); };
    panel.querySelector('[data-action="scan"]').onclick = function () { panel.remove(); openScanner(); };
    panel.querySelector('[data-action="refresh"]').onclick = function () { refresh(true); };
    renderPanelList();
  }

  function renderPanelList() {
    var list = document.querySelector('#px-transfer-logistics-panel .px-tl-panel-list');
    if (!list) return;
    if (!state.notifications.length && !state.incoming.length) {
      list.innerHTML = '<div class="px-tl-empty">No tienes transferencias pendientes de recepción.</div>';
      return;
    }

    var seen = {};
    var html = '';
    state.notifications.forEach(function (n) {
      seen[String(n.transfer_id)] = true;
      html += '<button class="px-tl-notif ' + (!n.read_at ? 'unread' : '') + '" data-transfer="' + Number(n.transfer_id) + '" data-token="' + esc(n.receiving_token || '') + '">' +
        '<strong>' + esc(n.title || 'Transferencia en camino') + ' · ' + esc(n.reference || '') + '</strong>' +
        '<span>' + esc(n.message || '') + '</span>' +
        '<small>' + esc(statusLabel(n.logistics_status)) + '</small></button>';
    });
    state.incoming.forEach(function (t) {
      if (seen[String(t.id)]) return;
      html += '<button class="px-tl-notif" data-transfer="' + Number(t.id) + '" data-token="' + esc(t.receiving_token || '') + '">' +
        '<strong>' + esc(t.reference) + ' · ' + esc(t.from_warehouse) + ' → ' + esc(t.to_warehouse) + '</strong>' +
        '<span>Transferencia disponible para recepción.</span><small>' + esc(statusLabel(t.logistics_status)) + '</small></button>';
    });
    list.innerHTML = html;
    Array.prototype.forEach.call(list.querySelectorAll('[data-transfer]'), function (el) {
      el.onclick = function () {
        var token = el.getAttribute('data-token');
        var id = el.getAttribute('data-transfer');
        panelClose();
        if (token) loadByToken(token); else loadById(id);
      };
    });
  }

  function panelClose() {
    var panel = document.getElementById('px-transfer-logistics-panel');
    if (panel) panel.remove();
  }

  function statusLabel(status) {
    return ({ in_transit: 'En tránsito', partially_received: 'Recepción parcial', received: 'Recibida', received_with_issues: 'Recibida con incidencias' })[status] || status || '';
  }

  function refresh(showPanelAfter) {
    return Promise.all([
      api('get', '/incoming'),
      api('get', '/notifications')
    ]).then(function (responses) {
      var wasUnread = state.unread;
      state.allowed = true;
      state.incoming = responses[0].data.transfers || [];
      state.notifications = responses[1].data.notifications || [];
      state.unread = Number(responses[1].data.unread || 0);
      ensureHeaderButton();
      updateBadge();
      renderPanelList();
      if (state.unread > wasUnread && wasUnread >= 0) showToast();
      if (showPanelAfter) togglePanel(true);
    }).catch(function (error) {
      if (error && error.response && error.response.status === 403) {
        state.allowed = false;
        var btn = document.getElementById('px-transfer-logistics-btn');
        if (btn) btn.remove();
        return;
      }
      if (showPanelAfter) console.warn('No se pudieron cargar recepciones', error);
    });
  }

  function showToast() {
    var unread = state.notifications.find(function (n) { return !n.read_at; });
    if (!unread) return;
    var old = document.getElementById('px-tl-toast');
    if (old) old.remove();
    var toast = document.createElement('div');
    toast.id = 'px-tl-toast'; toast.className = 'px-tl-toast';
    toast.innerHTML = '<strong>' + esc(unread.title || 'Transferencia en camino') + '</strong><span>' + esc(unread.message || '') + '</span>';
    toast.onclick = function () { toast.remove(); loadByToken(unread.receiving_token); };
    document.body.appendChild(toast);
    setTimeout(function () { if (toast.parentNode) toast.remove(); }, 9000);
  }

  function overlay(title, subtitle, bodyHtml, className) {
    closeOverlay();
    var wrap = document.createElement('div');
    wrap.id = 'px-tl-overlay';
    wrap.className = 'px-tl-overlay';
    wrap.innerHTML = '<div class="px-tl-modal ' + (className || '') + '"><div class="px-tl-modal-head"><div><h3>' + esc(title) + '</h3><p>' + esc(subtitle || '') + '</p></div><button class="px-tl-close" type="button">×</button></div><div class="px-tl-body">' + bodyHtml + '</div></div>';
    document.body.appendChild(wrap);
    wrap.querySelector('.px-tl-close').onclick = closeOverlay;
    wrap.addEventListener('click', function (e) { if (e.target === wrap) closeOverlay(); });
    return wrap;
  }

  function closeOverlay() {
    if (state.scanner) {
      try { state.scanner.clear(); } catch (e) {}
      state.scanner = null;
    }
    var el = document.getElementById('px-tl-overlay');
    if (el) el.remove();
  }

  function openScanner() {
    var wrap = overlay('Escanear transferencia', 'Escanea el QR incluido en el despacho. PRODEX validará que pertenezca a tu bodega.', '<div id="px-tl-scanner" class="px-tl-scanner"></div><div class="px-tl-manual"><input id="px-tl-token-input" placeholder="O pega el código TRF-..."><button id="px-tl-token-open" type="button">Abrir</button></div>', 'sm');
    wrap.querySelector('#px-tl-token-open').onclick = function () {
      var value = wrap.querySelector('#px-tl-token-input').value;
      var token = extractToken(value);
      if (token) loadByToken(token);
    };

    if (typeof window.Html5QrcodeScanner === 'undefined') {
      wrap.querySelector('#px-tl-scanner').innerHTML = '<div class="px-tl-error">El lector de cámara no está disponible en este navegador. Puedes pegar el código de recepción.</div>';
      return;
    }

    try {
      state.scanner = new window.Html5QrcodeScanner('px-tl-scanner', { fps: 10, qrbox: 240 }, false);
      state.scanner.render(function (decodedText) {
        var token = extractToken(decodedText);
        if (!token) return;
        try { state.scanner.clear(); } catch (e) {}
        state.scanner = null;
        loadByToken(token);
      }, function () {});
    } catch (e) {
      wrap.querySelector('#px-tl-scanner').innerHTML = '<div class="px-tl-error">No fue posible iniciar la cámara. Revisa los permisos del navegador.</div>';
    }
  }

  function extractToken(value) {
    value = String(value || '').trim();
    if (!value) return '';
    var match = value.match(/\/transfer-receive\/([^?#/]+)/i);
    if (match) return decodeURIComponent(match[1]);
    match = value.match(/(TRF-[A-Z0-9-]+)/i);
    return match ? match[1].toUpperCase() : value;
  }

  function loadByToken(token) {
    if (!token) return;
    closeOverlay();
    overlay('Cargando transferencia', 'Validando despacho y bodega destino…', '<div class="px-tl-empty">Consultando transferencia…</div>', 'sm');
    api('get', '/scan/' + encodeURIComponent(token)).then(function (response) {
      renderReceiving(response.data);
      refresh(false);
    }).catch(showApiError);
  }

  function loadById(id) {
    closeOverlay();
    overlay('Cargando transferencia', 'Validando despacho y bodega destino…', '<div class="px-tl-empty">Consultando transferencia…</div>', 'sm');
    api('get', '/' + encodeURIComponent(id)).then(function (response) {
      renderReceiving(response.data);
      refresh(false);
    }).catch(showApiError);
  }

  function renderReceiving(payload) {
    state.active = payload;
    var t = payload.transfer || {};
    var details = payload.details || [];
    var rows = details.map(function (d) {
      var disabled = !payload.can_receive || Number(d.quantity_remaining || 0) <= 0;
      return '<tr data-detail="' + Number(d.transfer_detail_id) + '" data-remaining="' + Number(d.quantity_remaining || 0) + '">' +
        '<td class="px-tl-product"><strong>' + esc(d.name || 'Producto') + '</strong><span>' + esc(d.code || '') + (d.unit ? ' · ' + esc(d.unit) : '') + '</span></td>' +
        '<td>' + Number(d.quantity_sent || 0) + '</td><td>' + Number(d.quantity_remaining || 0) + '</td>' +
        '<td><input class="px-good" type="number" min="0" step="any" value="" ' + (disabled ? 'disabled' : '') + '></td>' +
        '<td><input class="px-defective" type="number" min="0" step="any" value="0" ' + (disabled ? 'disabled' : '') + '></td>' +
        '<td><input class="px-missing" type="number" min="0" step="any" value="0" ' + (disabled ? 'disabled' : '') + '></td>' +
        '<td>' + (disabled ? '<span class="px-tl-status-good">Contabilizado</span>' : '<button type="button" class="px-tl-quick">Todo correcto</button>') + '</td></tr>';
    }).join('');

    var body = '<div class="px-tl-summary">' +
      card('Referencia', t.reference) + card('Origen', t.from_warehouse) + card('Destino', t.to_warehouse) + card('Estado', statusLabel(t.logistics_status)) + '</div>' +
      '<div id="px-tl-receive-error"></div><div class="px-tl-table-wrap"><table class="px-tl-table"><thead><tr><th>Producto</th><th>Enviado</th><th>Pendiente</th><th>Correcto</th><th>Defectuoso</th><th>Faltante</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<div style="margin-top:14px"><label style="display:block;margin-bottom:5px;color:#475467;font-size:11px;font-weight:800">Observaciones de recepción</label><textarea id="px-tl-receive-notes" class="px-tl-note" placeholder="Opcional: condición del empaque, número de sello, observaciones…"></textarea></div>';

    var wrap = overlay('Recibir ' + (t.reference || 'transferencia'), (t.from_warehouse || '') + ' → ' + (t.to_warehouse || ''), body);
    var modal = wrap.querySelector('.px-tl-modal');
    var footer = document.createElement('div');
    footer.className = 'px-tl-footer';
    footer.innerHTML = '<div class="px-tl-footer-note">Solo las cantidades correctas entran al stock vendible. Lo defectuoso queda en cuarentena y lo faltante como incidencia.</div><div class="px-tl-footer-actions"><button type="button" data-close>Cancelar</button><button type="button" class="primary" data-submit ' + (!payload.can_receive ? 'disabled' : '') + '>Confirmar recepción</button></div>';
    modal.appendChild(footer);
    footer.querySelector('[data-close]').onclick = closeOverlay;
    var submit = footer.querySelector('[data-submit]');
    if (submit) submit.onclick = submitReceipt;

    Array.prototype.forEach.call(wrap.querySelectorAll('.px-tl-quick'), function (btn) {
      btn.onclick = function () {
        var row = btn.closest('tr');
        row.querySelector('.px-good').value = row.getAttribute('data-remaining');
        row.querySelector('.px-defective').value = '0';
        row.querySelector('.px-missing').value = '0';
        validateReceiveRows();
      };
    });
    Array.prototype.forEach.call(wrap.querySelectorAll('input[type="number"]'), function (input) { input.addEventListener('input', validateReceiveRows); });
  }

  function card(label, value) {
    return '<div class="px-tl-card"><small>' + esc(label) + '</small><strong>' + esc(value || '—') + '</strong></div>';
  }

  function validateReceiveRows() {
    var error = document.getElementById('px-tl-receive-error');
    var bad = '';
    Array.prototype.some.call(document.querySelectorAll('.px-tl-table tbody tr'), function (row) {
      var remaining = Number(row.getAttribute('data-remaining') || 0);
      var good = Number((row.querySelector('.px-good') || {}).value || 0);
      var defective = Number((row.querySelector('.px-defective') || {}).value || 0);
      var missing = Number((row.querySelector('.px-missing') || {}).value || 0);
      if (good < 0 || defective < 0 || missing < 0 || good + defective + missing > remaining + 0.000001) {
        bad = 'Una línea supera la cantidad pendiente o contiene un valor inválido.';
        return true;
      }
      return false;
    });
    if (error) error.innerHTML = bad ? '<div class="px-tl-error">' + esc(bad) + '</div>' : '';
    return !bad;
  }

  function submitReceipt() {
    if (!state.active || !validateReceiveRows()) return;
    var items = [];
    Array.prototype.forEach.call(document.querySelectorAll('.px-tl-table tbody tr'), function (row) {
      var goodInput = row.querySelector('.px-good');
      if (!goodInput || goodInput.disabled) return;
      var item = {
        transfer_detail_id: Number(row.getAttribute('data-detail')),
        quantity_good: Number(goodInput.value || 0),
        quantity_defective: Number(row.querySelector('.px-defective').value || 0),
        quantity_missing: Number(row.querySelector('.px-missing').value || 0)
      };
      if (item.quantity_good + item.quantity_defective + item.quantity_missing > 0) items.push(item);
    });
    if (!items.length) {
      var err = document.getElementById('px-tl-receive-error');
      if (err) err.innerHTML = '<div class="px-tl-error">Indica al menos una cantidad para recibir.</div>';
      return;
    }

    var button = document.querySelector('.px-tl-footer [data-submit]');
    if (button) { button.disabled = true; button.textContent = 'Procesando…'; }
    api('post', '/' + state.active.transfer.id + '/receive', {
      notes: (document.getElementById('px-tl-receive-notes') || {}).value || null,
      items: items
    }).then(function (response) {
      closeOverlay();
      var updated = response.data.transfer || {};
      var title = updated.logistics_status === 'partially_received' ? 'Recepción parcial registrada' : (updated.logistics_status === 'received_with_issues' ? 'Recepción con incidencias registrada' : 'Transferencia recibida');
      var toast = document.createElement('div');
      toast.id = 'px-tl-toast'; toast.className = 'px-tl-toast';
      toast.innerHTML = '<strong>' + esc(title) + '</strong><span>' + esc(updated.reference || '') + ' fue actualizada correctamente. El inventario recibió únicamente las cantidades confirmadas como correctas.</span>';
      document.body.appendChild(toast);
      setTimeout(function () { if (toast.parentNode) toast.remove(); }, 7000);
      refresh(false);
    }).catch(function (error) {
      if (button) { button.disabled = false; button.textContent = 'Confirmar recepción'; }
      showInlineApiError(error);
    });
  }

  function showInlineApiError(error) {
    var message = apiMessage(error);
    var err = document.getElementById('px-tl-receive-error');
    if (err) err.innerHTML = '<div class="px-tl-error">' + esc(message) + '</div>';
  }

  function showApiError(error) {
    var message = apiMessage(error);
    overlay('No se pudo abrir la transferencia', '', '<div class="px-tl-error">' + esc(message) + '</div>', 'sm');
  }

  function apiMessage(error) {
    var data = error && error.response && error.response.data;
    if (data) {
      if (data.message) return data.message;
      if (data.errors) {
        var key = Object.keys(data.errors)[0];
        if (key && data.errors[key]) return Array.isArray(data.errors[key]) ? data.errors[key][0] : data.errors[key];
      }
    }
    return 'Ocurrió un error al procesar la transferencia.';
  }

  function maybeOpenTokenFromUrl() {
    try {
      var params = new URLSearchParams(window.location.search || '');
      var token = params.get('receive_token');
      if (token && state.allowed) {
        history.replaceState({}, document.title, window.location.pathname + window.location.hash);
        loadByToken(token);
      }
    } catch (e) {}
  }

  function maybeAddQrButton() {
    var match = window.location.pathname.match(/\/app\/transfers\/detail\/(\d+)/i);
    var old = document.getElementById('px-tl-qr-action');
    if (!match) { if (old) old.remove(); return; }
    if (old) return;
    var main = document.querySelector('.main-content');
    if (!main) return;
    var btn = document.createElement('button');
    btn.id = 'px-tl-qr-action';
    btn.type = 'button';
    btn.className = 'btn btn-outline-primary btn-sm';
    btn.style.cssText = 'position:fixed;right:22px;bottom:82px;z-index:1200;box-shadow:0 6px 18px rgba(15,23,42,.12);';
    btn.textContent = 'QR de recepción';
    btn.onclick = function () { showQr(match[1]); };
    document.body.appendChild(btn);
  }

  function showQr(id) {
    api('get', '/' + encodeURIComponent(id) + '/qr').then(function (response) {
      var data = response.data;
      var wrap = overlay('QR de recepción · ' + data.reference, 'Incluye este QR con el despacho. Solo un usuario autorizado de la bodega destino podrá recibirlo.', '<div class="px-tl-qr"><div id="px-tl-qr-box" class="px-tl-qr-box"></div><div class="px-tl-token">' + esc(data.token) + '</div><p style="color:#667085;font-size:11px;margin:10px 0 0">' + esc(data.qr_value) + '</p></div>', 'sm');
      var box = wrap.querySelector('#px-tl-qr-box');
      if (typeof window.QRCode !== 'undefined') {
        try {
          new window.QRCode(box, { text: data.qr_value, width: 240, height: 240, correctLevel: window.QRCode.CorrectLevel ? window.QRCode.CorrectLevel.M : undefined });
        } catch (e) { box.innerHTML = '<div class="px-tl-error">No se pudo generar la imagen QR.</div>'; }
      } else {
        box.innerHTML = '<div class="px-tl-error">El generador QR no está disponible. Usa el código mostrado abajo.</div>';
      }
      var modal = wrap.querySelector('.px-tl-modal');
      var footer = document.createElement('div');
      footer.className = 'px-tl-footer';
      footer.innerHTML = '<div class="px-tl-footer-note">El QR no contiene el ID numérico de la transferencia; usa un token aleatorio seguro.</div><div class="px-tl-footer-actions"><button type="button" data-print>Imprimir QR</button><button type="button" class="primary" data-close>Cerrar</button></div>';
      modal.appendChild(footer);
      footer.querySelector('[data-close]').onclick = closeOverlay;
      footer.querySelector('[data-print]').onclick = function () {
        var content = wrap.querySelector('.px-tl-qr').innerHTML;
        var w = window.open('', '_blank', 'width=640,height=760');
        if (!w) return;
        w.document.write('<!doctype html><html><head><title>' + esc(data.reference) + '</title><style>body{font-family:Arial,sans-serif;text-align:center;padding:40px;color:#111}img,canvas{max-width:280px!important;max-height:280px!important}.px-tl-token{font-family:monospace;margin-top:18px}.px-tl-qr-box{display:flex;justify-content:center}</style></head><body><h2>PRODEX · Transferencia ' + esc(data.reference) + '</h2><p>Escanear para recibir en bodega destino</p>' + content + '</body></html>');
        w.document.close(); setTimeout(function () { w.focus(); w.print(); }, 250);
      };
    }).catch(showApiError);
  }

  function init() {
    css();
    refresh(false).then(function () { maybeOpenTokenFromUrl(); });
    maybeAddQrButton();
    pollTimer = window.setInterval(function () { refresh(false); maybeAddQrButton(); }, 45000);

    var observer = new MutationObserver(function () { ensureHeaderButton(); maybeAddQrButton(); });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
