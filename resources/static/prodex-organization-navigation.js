(function () {
  'use strict';

  if (window.__prodexOrganizationNavigationInstalled) return;
  window.__prodexOrganizationNavigationInstalled = true;

  var LINK_ID = 'prodex-organization-branches-nav';
  var allowed = null;

  function canUseOrganization() {
    if (!window.axios) return Promise.resolve(false);
    return window.axios.get('/api/organization/branches', {
      baseURL: '',
      params: { search: '' },
      meta: { skipErrorRedirect: true, skipInitialLoader: true }
    }).then(function () {
      allowed = true;
      return true;
    }).catch(function (error) {
      allowed = !(error && error.response && error.response.status === 403) ? false : false;
      return false;
    });
  }

  function navList() {
    return document.querySelector('.vertical-sidebar-wrapper .vertical-nav-menu > .nav-list');
  }

  function makeItem() {
    var li = document.createElement('li');
    li.id = LINK_ID;
    li.className = 'nav-item';
    li.dataset.prodexV3ModuleFixed = 'organization';
    li.dataset.prodexV3Module = 'organization';
    li.dataset.prodexV3Section = 'admin';
    li.dataset.prodexV3Order = '8001.5';
    li.style.setProperty('--prodex-v3-order', '8001.5');
    li.innerHTML = [
      '<a class="nav-link" href="/app/organization/branches" title="Sucursales">',
      '<span class="nav-icon" aria-hidden="true">',
      '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">',
      '<path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/><path d="M8 9h.01"/><path d="M12 9h.01"/><path d="M16 9h.01"/>',
      '</svg></span>',
      '<span class="nav-text">Sucursales</span>',
      '</a>'
    ].join('');
    return li;
  }

  function ensure() {
    if (allowed !== true) return;
    var list = navList();
    if (!list) return;
    var item = document.getElementById(LINK_ID);
    if (!item) {
      item = makeItem();
      list.appendChild(item);
    }
    // The main navigation organizer may run after Vue changes the sidebar. Keep
    // the organization entry inside Administración without reordering other items.
    item.dataset.prodexV3ModuleFixed = 'organization';
    item.dataset.prodexV3Section = 'admin';
    item.dataset.prodexV3Order = '8001.5';
    item.style.setProperty('--prodex-v3-order', '8001.5');
  }

  function init() {
    canUseOrganization().then(function (ok) {
      if (!ok) return;
      ensure();
      [100, 350, 900, 1800].forEach(function (delay) { window.setTimeout(ensure, delay); });
      var root = document.querySelector('.vertical-sidebar-wrapper');
      if (root) {
        new MutationObserver(function () { window.setTimeout(ensure, 20); })
          .observe(root, { childList: true, subtree: true });
      }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
