(function () {
  'use strict';

  if (window.__prodexInventorySpaNavigationInstalled) return;
  window.__prodexInventorySpaNavigationInstalled = true;

  var PARAM = 'prodex_inventory_view';
  var lastUrl = window.location.href;

  function currentView() {
    try {
      return new URL(window.location.href).searchParams.get(PARAM) || '';
    } catch (e) {
      return '';
    }
  }

  function triggerInventoryRenderer() {
    var root = document.documentElement;
    var value = String(Date.now());
    root.setAttribute('data-px-inventory-route-tick', value);
    window.setTimeout(function () {
      if (root.getAttribute('data-px-inventory-route-tick') === value) {
        root.removeAttribute('data-px-inventory-route-tick');
      }
    }, 0);
  }

  function restoreUnderlyingPage() {
    var main = document.querySelector('.main-content-wrap');
    if (!main) return;

    var page = main.querySelector(':scope > .px-iv-full-page');
    if (page) page.remove();

    Array.prototype.forEach.call(main.children, function (child) {
      if (!child.hasAttribute('data-px-iv-original-display')) return;
      child.style.display = child.getAttribute('data-px-iv-original-display') || '';
      child.removeAttribute('data-px-iv-original-display');
    });

    document.querySelectorAll('.px-iv-menu-active').forEach(function (item) {
      item.classList.remove('px-iv-menu-active');
    });
  }

  function activateMenu(view) {
    document.querySelectorAll('.px-iv-menu-active').forEach(function (item) {
      item.classList.remove('px-iv-menu-active');
    });
    var current = document.querySelector('.px-iv-menu-' + view);
    if (current) current.classList.add('px-iv-menu-active');
  }

  function syncToUrl() {
    var view = currentView();
    if (!view) {
      restoreUnderlyingPage();
    } else {
      activateMenu(view);
      triggerInventoryRenderer();
    }
    lastUrl = window.location.href;
  }

  function navigateInventoryView(view, href) {
    if (!view) return;

    if (currentView() === view) {
      activateMenu(view);
      triggerInventoryRenderer();
      return;
    }

    window.history.pushState({ prodexInventoryView: view }, '', href);
    activateMenu(view);
    triggerInventoryRenderer();
    lastUrl = window.location.href;
  }

  // Vue Router changes the browser URL through history.pushState/replaceState.
  // Listen to those methods directly so a custom inventory view is always removed
  // as soon as the user navigates to another PRODEX screen.
  ['pushState', 'replaceState'].forEach(function (method) {
    var original = window.history[method];
    if (typeof original !== 'function' || original.__prodexInventoryWrapped) return;

    var wrapped = function () {
      var result = original.apply(window.history, arguments);
      window.setTimeout(function () {
        if (window.location.href !== lastUrl) syncToUrl();
      }, 0);
      return result;
    };
    wrapped.__prodexInventoryWrapped = true;
    window.history[method] = wrapped;
  });

  // Capture the two generated inventory links before native anchor navigation.
  // The top-bar Existencias button is intentionally excluded and remains a popup.
  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (!event.target || !event.target.closest) return;

    var link = event.target.closest('.px-iv-menu-missing a, .px-iv-menu-stock a');
    if (link) {
      event.preventDefault();
      event.stopPropagation();
      var item = link.closest('.px-iv-menu-missing, .px-iv-menu-stock');
      var view = item && item.classList.contains('px-iv-menu-missing') ? 'missing' : 'stock';
      navigateInventoryView(view, link.getAttribute('href'));
      return;
    }

    // Restore immediately before a normal sidebar navigation. The history wrapper
    // above is the final safety net once Vue Router updates the URL.
    if (currentView() && event.target.closest('.vertical-sidebar-wrapper a, .sidebar-left a, .navigation-left a')) {
      restoreUnderlyingPage();
    }
  }, true);

  window.addEventListener('popstate', function () {
    syncToUrl();
  });

  // Safety net for any navigation implementation that changes the URL without
  // emitting popstate or using the patched history methods.
  window.setInterval(function () {
    if (window.location.href !== lastUrl) syncToUrl();
  }, 250);
})();
