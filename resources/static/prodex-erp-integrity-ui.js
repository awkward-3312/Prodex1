(function () {
  'use strict';
  if (window.__prodexErpIntegrityUiInstalled) return;
  window.__prodexErpIntegrityUiInstalled = true;

  var state = { notifications: [], unread: 0, categories: {}, loaded: false };
  var timer = null;

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function resolveRouter() {
    var sidebar = document.querySelector('.vertical-sidebar-wrapper, .vertical-sidebar');
    var vm = sidebar && sidebar.__vue__;
    if (vm && vm.$router) return vm.$router;
    var root = document.getElementById('app');
    vm = root && root.__vue__;
    return vm && vm.$router ? vm.$router : null;
  }

  function navigate(action) {
    if (!action) return;
    if (action.charAt(0) === '/') {
      var router = resolveRouter();
      if (router) {
        router.push(action).catch(function () {});
        return;
      }
    }
    window.location.href = action;
  }

  function style() {
    if (document.getElementById('prodex-erp-integrity-style')) return;
    var s = document.createElement('style');
    s.id = 'prodex-erp-integrity-style';
    s.textContent = [
      '#notif-dd .dropdown-menu{min-width:350px!important;max-width:min(430px,calc(100vw - 24px))!important}',
      '#notif-dd .notification-item{display:none!important}',
      '.px-notification-title{padding:13px 14px 10px;font-size:13px;font-weight:800;color:#202939;border-bottom:1px solid #eef1f4}',
      '.px-notification-section{padding:9px 14px 6px;font-size:10px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#98a2b3;border-top:1px solid #eef1f4}',
      '.px-unified-notification{display:block;width:100%;border:0;border-top:1px solid #f1f3f5;background:#fff;padding:11px 14px;text-align:left;cursor:pointer}',
      '.px-unified-notification:hover{background:#f8fafc}.px-unified-notification.unread{background:#f2fbfd}',
      '.px-unified-notification strong{display:block;font-size:12px;color:#202939}.px-unified-notification span{display:block;margin-top:3px;font-size:11px;line-height:1.4;color:#667085}',
      '.px-unified-empty{padding:20px 14px;text-align:center;color:#7a8494;font-size:12px}',

      /* Header controls: keep language, notifications and profile visually aligned. */
      '.main-header .header-part-right{display:flex!important;align-items:center!important;gap:8px!important}',
      '.main-header .header-part-right>.dropdown{display:flex!important;align-items:center!important;margin:0!important}',
      '.main-header #lang-dd .dropdown-toggle,.main-header #notif-dd .dropdown-toggle{width:38px!important;height:38px!important;min-width:38px!important;padding:0!important;border-radius:10px!important;display:flex!important;align-items:center!important;justify-content:center!important;position:relative!important;overflow:visible!important;box-shadow:none!important}',
      '.main-header #lang-dd .dropdown-toggle svg,.main-header #notif-dd .dropdown-toggle svg{width:19px!important;height:19px!important;margin:0!important}',

      /* The notification count belongs to the bell, never between neighbouring buttons. */
      '.main-header #notif-dd{position:relative!important}',
      '.main-header #notif-dd .badge,.vertical-top-nav #notif-dd .badge{position:absolute!important;top:-6px!important;right:-6px!important;left:auto!important;z-index:4!important;min-width:18px!important;width:auto!important;height:18px!important;padding:0 5px!important;border-radius:999px!important;display:flex!important;align-items:center!important;justify-content:center!important;font-size:10px!important;font-weight:700!important;line-height:18px!important;white-space:nowrap!important;box-sizing:border-box!important;pointer-events:none!important}',
      '.main-header #notif-dd .dropdown-menu{right:0!important;left:auto!important;margin-top:10px!important;overflow:hidden!important}',
      '.main-header #notif-dd .dropdown-scroll{max-height:min(440px,70vh)!important}',

      /* Bootstrap's link-button styles were making the profile control look like a large purple square. */
      '.main-header #user-dd .user-dropdown-toggle,.main-header #user-dd button.user-dropdown-toggle{width:38px!important;height:38px!important;min-width:38px!important;padding:0!important;margin:0!important;border:1px solid #e5e7eb!important;border-radius:10px!important;background:#fff!important;color:#667085!important;display:flex!important;align-items:center!important;justify-content:center!important;box-shadow:none!important;text-decoration:none!important;overflow:hidden!important}',
      '.main-header #user-dd .user-dropdown-toggle:hover,.main-header #user-dd .user-dropdown-toggle:focus,.main-header #user-dd .user-dropdown-toggle:active{background:#f8fafc!important;border-color:#cfd5dd!important;color:#344054!important;box-shadow:none!important}',
      '.main-header #user-dd .user-avatar{width:28px!important;height:28px!important;min-width:28px!important;border-radius:50%!important;overflow:hidden!important;margin:0!important;background:#f2f4f7!important}',
      '.main-header #user-dd .user-avatar img{display:block!important;width:100%!important;height:100%!important;object-fit:cover!important;border-radius:50%!important;margin:0!important}',
      '.main-header #user-dd .dropdown-menu{right:0!important;left:auto!important;margin-top:10px!important}',

      '@media (max-width:575.98px){.main-header .header-part-right{gap:6px!important}.main-header #lang-dd .dropdown-toggle,.main-header #notif-dd .dropdown-toggle,.main-header #user-dd .user-dropdown-toggle{width:36px!important;height:36px!important;min-width:36px!important}.main-header #notif-dd .dropdown-menu{position:fixed!important;top:58px!important;right:12px!important;left:12px!important;width:auto!important;min-width:0!important;max-width:none!important}}',

      'body.dark-theme .px-unified-notification{background:#1f2030;color:#e5e7eb;border-color:#34364a}body.dark-theme .px-unified-notification strong,body.dark-theme .px-notification-title{color:#f4f4f5}',
      'body.dark-theme .main-header #user-dd .user-dropdown-toggle{background:#1a1a2e!important;border-color:#2d2d44!important;color:#d0d0d0!important}',
      'body.dark-theme .main-header #user-dd .user-dropdown-toggle:hover{background:#2d2d44!important;border-color:#764ba2!important;color:#fff!important}'
    ].join('');
    document.head.appendChild(s);
  }

  function fetchNotifications() {
    if (!window.axios) return;
    window.axios.get('/api/notification-center', {
      baseURL: '',
      meta: { skipErrorRedirect: true, skipInitialLoader: true }
    }).then(function (response) {
      var data = response && response.data ? response.data : {};
      state.notifications = Array.isArray(data.notifications) ? data.notifications : [];
      state.unread = Number(data.unread || 0);
      state.categories = data.categories || {};
      state.loaded = true;
      render();
    }).catch(function (error) {
      if (error && error.response && [401, 403].indexOf(error.response.status) !== -1) {
        state.notifications = [];
        state.unread = 0;
        state.loaded = true;
        render();
      }
    });
  }

  function updateBadge() {
    var root = document.getElementById('notif-dd');
    if (!root) return;
    var toggle = root.querySelector('button.dropdown-toggle,.dropdown-toggle');
    if (!toggle) return;
    var badge = toggle.querySelector('.badge');
    var total = state.unread;

    if (total <= 0) {
      if (badge) badge.style.display = 'none';
      return;
    }

    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'badge badge-primary';
      toggle.insertBefore(badge, toggle.firstChild);
    }
    badge.textContent = total > 99 ? '99+' : String(total);
    badge.style.display = 'flex';
  }

  function categoryOrder(groups) {
    var ordered = [];
    Object.keys(state.categories || {}).forEach(function (key) {
      if (groups[key] && groups[key].length) ordered.push(key);
    });
    Object.keys(groups).forEach(function (key) {
      if (ordered.indexOf(key) === -1) ordered.push(key);
    });
    return ordered;
  }

  function render() {
    var root = document.getElementById('notif-dd');
    if (!root) return;
    var menu = root.querySelector('.dropdown-menu');
    if (!menu) {
      updateBadge();
      return;
    }

    var old = menu.querySelector('#px-unified-notifications');
    if (old) old.remove();

    var wrap = document.createElement('div');
    wrap.id = 'px-unified-notifications';
    var html = '<div class="px-notification-title">Notificaciones</div>';
    var groups = {};

    state.notifications.forEach(function (n) {
      var key = n.category || 'system';
      (groups[key] || (groups[key] = [])).push(n);
    });

    categoryOrder(groups).forEach(function (key) {
      html += '<div class="px-notification-section">' + esc(state.categories[key] || key) + '</div>';
      groups[key].slice(0, 10).forEach(function (n) {
        html += '<button type="button" class="px-unified-notification ' + (n.unread ? 'unread' : '') + '" data-px-action="' + esc(n.action || '') + '" data-px-read="' + esc(n.read_endpoint || '') + '">' +
          '<strong>' + esc(n.title || 'Notificación') + '</strong>' +
          '<span>' + esc(n.message || '') + '</span></button>';
      });
    });

    if (!state.notifications.length) {
      html += '<div class="px-unified-empty">No tienes notificaciones que requieran atención.</div>';
    }

    wrap.innerHTML = html;
    menu.insertBefore(wrap, menu.firstChild);

    Array.prototype.forEach.call(wrap.querySelectorAll('.px-unified-notification'), function (button) {
      button.addEventListener('click', function () {
        var action = button.getAttribute('data-px-action');
        var read = button.getAttribute('data-px-read');
        var afterRead = function () {
          if (action) navigate(action);
          fetchNotifications();
        };

        if (read && window.axios) {
          window.axios.post(read, {}, {
            baseURL: '',
            meta: { skipErrorRedirect: true, skipInitialLoader: true }
          }).then(afterRead).catch(function () {
            if (action) navigate(action);
          });
        } else if (action) {
          navigate(action);
        }
      });
    });

    updateBadge();
  }

  function install() {
    style();
    fetchNotifications();
    if (timer) clearInterval(timer);
    timer = setInterval(fetchNotifications, 30000);
    document.addEventListener('click', function (e) {
      if (e.target && e.target.closest && e.target.closest('#notif-dd')) {
        setTimeout(render, 60);
      }
    }, true);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install);
  else install();
})();
