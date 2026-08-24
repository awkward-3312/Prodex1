(function () {
  'use strict';

  if (window.__prodexErpIntegrityUiInstalled) return;
  window.__prodexErpIntegrityUiInstalled = true;

  var state = { notifications: [], unread: 0, loaded: false };
  var timer = null;

  function path() {
    return window.location.pathname || '';
  }

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function isTransferPage() {
    return /^\/app\/transfers(?:\/|$)/.test(path());
  }

  function isDamagePage() {
    return /^\/app\/damages(?:\/|$)/.test(path());
  }

  function normalize(text) {
    return String(text || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  }

  function isLockedTransferRow(row) {
    var text = normalize(row && row.textContent);
    return text.indexOf('completed') !== -1 ||
      text.indexOf('completado') !== -1 ||
      text.indexOf('complete') !== -1 ||
      text.indexOf('sent') !== -1 ||
      text.indexOf('enviado') !== -1 ||
      text.indexOf('en transito') !== -1 ||
      text.indexOf('recepcion parcial') !== -1 ||
      text.indexOf('recibido') !== -1;
  }

  function isMutatingLabel(el) {
    var text = normalize(el && el.textContent);
    var title = normalize(el && el.getAttribute && el.getAttribute('title'));
    var href = normalize(el && el.getAttribute && el.getAttribute('href'));
    return text === 'edit' || text === 'editar' || text === 'delete' || text === 'eliminar' ||
      text === 'del' || title === 'edit' || title === 'editar' || title === 'delete' ||
      title === 'eliminar' || href.indexOf('/edit/') !== -1;
  }

  function lockTransferActions() {
    if (!isTransferPage()) return;

    // Bulk deletion is unsafe for mixed selections because dispatched transfers are
    // immutable. Individual pending transfers can still be deleted through their row.
    Array.prototype.forEach.call(document.querySelectorAll('.vgt-selection-info-row, .vgt-selection-info-row__actions, [class*="selected-row-actions"]'), function (box) {
      Array.prototype.forEach.call(box.querySelectorAll('button,a'), function (el) {
        var text = normalize(el.textContent);
        if (text.indexOf('del') !== -1 || text.indexOf('delete') !== -1 || text.indexOf('eliminar') !== -1) {
          el.style.display = 'none';
          el.setAttribute('data-prodex-locked-action', '1');
        }
      });
    });

    Array.prototype.forEach.call(document.querySelectorAll('.vgt-table tbody tr'), function (row) {
      if (!isLockedTransferRow(row)) return;
      Array.prototype.forEach.call(row.querySelectorAll('a,button,.dropdown-item'), function (el) {
        if (isMutatingLabel(el)) {
          el.style.display = 'none';
          el.setAttribute('data-prodex-locked-action', '1');
        }
      });
    });

    if (/\/app\/transfers\/detail\//.test(path())) {
      var container = document.querySelector('.main-content');
      if (container && isLockedTransferRow(container)) {
        Array.prototype.forEach.call(container.querySelectorAll('a,button'), function (el) {
          if (isMutatingLabel(el)) {
            el.style.display = 'none';
            el.setAttribute('data-prodex-locked-action', '1');
          }
        });
      }
    }
  }

  function lockAutomaticDamageActions() {
    if (!isDamagePage()) return;

    Array.prototype.forEach.call(document.querySelectorAll('.vgt-table tbody tr'), function (row) {
      if (String(row.textContent || '').indexOf('TR-DMG-') === -1) return;
      row.setAttribute('data-prodex-logistics-damage', '1');
      Array.prototype.forEach.call(row.querySelectorAll('a,button'), function (el) {
        if (isMutatingLabel(el)) {
          el.style.display = 'none';
          el.setAttribute('data-prodex-locked-action', '1');
        }
      });
    });
  }

  function style() {
    if (document.getElementById('prodex-erp-integrity-style')) return;
    var s = document.createElement('style');
    s.id = 'prodex-erp-integrity-style';
    s.textContent = [
      '#px-transfer-logistics-btn{display:none!important}',
      '#notif-dd .dropdown-menu{min-width:350px!important;max-width:min(410px,calc(100vw - 24px))!important}',
      '.px-unified-head{padding:12px 14px 8px;font-size:12px;font-weight:800;color:#344054;border-top:1px solid #eef1f4}',
      '.px-unified-transfer{display:block;width:100%;border:0;border-top:1px solid #f1f3f5;background:#fff;padding:11px 14px;text-align:left;cursor:pointer}',
      '.px-unified-transfer:hover{background:#faf8fc}.px-unified-transfer.unread{background:#fbf8ff}',
      '.px-unified-transfer strong{display:block;font-size:12px;color:#202939}.px-unified-transfer span{display:block;margin-top:3px;font-size:11px;line-height:1.4;color:#667085}',
      '.px-unified-empty{padding:20px 14px;text-align:center;color:#7a8494;font-size:12px}',
      '.px-unified-action{display:block;width:calc(100% - 24px);margin:10px 12px 12px;border:1px solid #d8e0e9;background:#fff;color:#63318f;border-radius:8px;padding:8px 10px;font-size:11px;font-weight:800;cursor:pointer}',
      '.main-header #notif-dd .badge{min-width:18px!important;width:auto!important;padding:2px 5px!important}',
      'body.dark-theme .px-unified-transfer,body.dark-theme .px-unified-action{background:#1f2030;color:#e5e7eb;border-color:#34364a}body.dark-theme .px-unified-transfer strong{color:#f4f4f5}'
    ].join('');
    document.head.appendChild(s);
  }

  function fetchNotifications() {
    if (!window.axios) return;
    window.axios.get('/api/transfer-logistics/notifications', {
      meta: { skipErrorRedirect: true, skipInitialLoader: true }
    }).then(function (response) {
      var data = response && response.data ? response.data : {};
      state.notifications = Array.isArray(data.notifications) ? data.notifications : [];
      state.unread = Number(data.unread || 0);
      state.loaded = true;
      renderNotificationCenter();
    }).catch(function (error) {
      // 403 simply means this user is not a destination receiver. The stock-alert
      // bell remains fully functional and should not redirect to an unauthorized page.
      if (error && error.response && error.response.status === 403) {
        state.notifications = [];
        state.unread = 0;
        state.loaded = true;
        renderNotificationCenter();
      }
    });
  }

  function stockAlertCount(menu) {
    if (!menu) return 0;
    var items = menu.querySelectorAll('.notification-item');
    var total = 0;
    Array.prototype.forEach.call(items, function (item) {
      if (item.closest && item.closest('#px-unified-notifications')) return;
      var match = String(item.textContent || '').match(/\d+/);
      if (match) total += Number(match[0] || 0);
    });
    return total;
  }

  function updateBellBadge(menu) {
    var root = document.getElementById('notif-dd');
    if (!root) return;
    var toggle = root.querySelector('button.dropdown-toggle, .dropdown-toggle');
    if (!toggle) return;
    var existing = toggle.querySelector('.badge');
    var total = stockAlertCount(menu) + state.unread;

    if (total <= 0) {
      if (existing) existing.style.display = 'none';
      return;
    }

    if (!existing) {
      existing = document.createElement('span');
      existing.className = 'badge badge-primary';
      toggle.insertBefore(existing, toggle.firstChild);
    }
    existing.textContent = total > 99 ? '99+' : String(total);
    existing.style.display = 'flex';
  }

  function renderNotificationCenter() {
    var root = document.getElementById('notif-dd');
    if (!root) return;
    var menu = root.querySelector('.dropdown-menu');
    if (!menu) {
      updateBellBadge(null);
      return;
    }

    var old = menu.querySelector('#px-unified-notifications');
    if (old) old.remove();

    var wrap = document.createElement('div');
    wrap.id = 'px-unified-notifications';

    var html = '<div class="px-unified-head">Transferencias</div>';
    if (!state.notifications.length) {
      html += '<div class="px-unified-empty">No tienes transferencias nuevas.</div>';
    } else {
      state.notifications.slice(0, 8).forEach(function (n) {
        html += '<button type="button" class="px-unified-transfer ' + (!n.read_at ? 'unread' : '') + '" data-px-transfer="' + Number(n.transfer_id || 0) + '" data-px-notification="' + Number(n.id || 0) + '">' +
          '<strong>' + esc((n.title || 'Transferencia') + (n.reference ? ' · ' + n.reference : '')) + '</strong>' +
          '<span>' + esc(n.message || 'Transferencia disponible para recepción.') + '</span></button>';
      });
    }
    html += '<button type="button" class="px-unified-action" data-px-open-receiving="1">Abrir recepción de transferencias</button>';
    wrap.innerHTML = html;
    menu.appendChild(wrap);

    Array.prototype.forEach.call(wrap.querySelectorAll('[data-px-transfer]'), function (button) {
      button.addEventListener('click', function () {
        var id = Number(button.getAttribute('data-px-transfer') || 0);
        var notificationId = Number(button.getAttribute('data-px-notification') || 0);
        var go = function () {
          if (id > 0) window.location.href = '/app/transfers/detail/' + id;
        };
        if (notificationId > 0 && window.axios) {
          window.axios.post('/api/transfer-logistics/notifications/' + notificationId + '/read', {}, {
            meta: { skipErrorRedirect: true, skipInitialLoader: true }
          }).then(go).catch(go);
        } else go();
      });
    });

    var receiver = wrap.querySelector('[data-px-open-receiving]');
    if (receiver) receiver.addEventListener('click', function () {
      var logisticsButton = document.getElementById('px-transfer-logistics-btn');
      if (logisticsButton) logisticsButton.click();
    });

    updateBellBadge(menu);
  }

  function runUiGuards() {
    lockTransferActions();
    lockAutomaticDamageActions();
    renderNotificationCenter();
  }

  function install() {
    style();
    fetchNotifications();
    runUiGuards();

    if (timer) clearInterval(timer);
    timer = setInterval(fetchNotifications, 30000);

    var observer = new MutationObserver(function () {
      window.requestAnimationFrame(runUiGuards);
    });
    observer.observe(document.body, { childList: true, subtree: true });

    document.addEventListener('click', function (event) {
      var notif = event.target && event.target.closest ? event.target.closest('#notif-dd') : null;
      if (notif) setTimeout(renderNotificationCenter, 60);
    }, true);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install);
  else install();
})();
