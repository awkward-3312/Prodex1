(function () {
  'use strict';

  if (window.__prodexOptionalCashDrawerInstalled) return;
  window.__prodexOptionalCashDrawerInstalled = true;

  var NO_DRAWER_VALUE = '-1';

  function isPosPage() {
    return window.location && window.location.pathname === '/app/pos';
  }

  function normalizeText(value) {
    return String(value || '')
      .trim()
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '');
  }

  function normalizePath(url) {
    return String(url || '')
      .split('?')[0]
      .replace(/^https?:\/\/[^/]+/i, '')
      .replace(/^\/api\//, '')
      .replace(/^\//, '');
  }

  function requestData(config) {
    if (!config) return null;
    if (config.data && typeof config.data === 'object') return config.data;
    if (typeof config.data === 'string' && config.data.trim()) {
      try {
        var parsed = JSON.parse(config.data);
        if (parsed && typeof parsed === 'object') {
          config.data = parsed;
          return parsed;
        }
      } catch (e) {}
    }
    return null;
  }

  function installAxiosPatch() {
    if (!window.axios || window.__prodexOptionalCashDrawerAxiosPatched) return;
    window.__prodexOptionalCashDrawerAxiosPatched = true;

    window.axios.interceptors.request.use(function (config) {
      var path = normalizePath(config && config.url);
      if (path !== 'cash-registers/open' && path !== 'pos/registers/open') {
        return config;
      }

      var data = requestData(config);
      if (!data) return config;

      var drawerId = Number(data.cash_drawer_id || 0);
      if (!Number.isFinite(drawerId) || drawerId <= 0) {
        delete data.cash_drawer_id;
      }
      config.data = data;
      return config;
    });
  }

  function patchInstance(vm) {
    if (!vm || vm.__prodexOptionalCashDrawerPatched) return;

    try {
      if (vm.$options && vm.$options.name === 'ModernPaymentModal' && typeof vm.ensureCashDrawerAssigned === 'function') {
        vm.__prodexOptionalCashDrawerPatched = true;
        vm.ensureCashDrawerAssigned = function () {
          // Native POS requires Branch + InventoryLocation. A physical drawer is
          // additional traceability, not a prerequisite for completing a sale.
          return true;
        };
      }
    } catch (e) {
      // Keep the POS usable even if a component instance cannot be inspected.
    }

    try {
      (vm.$children || []).forEach(patchInstance);
    } catch (e) {
      // Ignore component-tree traversal failures.
    }
  }

  function patchOpenRegisterDrawerField() {
    if (!isPosPage()) return;

    var labels = document.querySelectorAll('.modal label, .modal-content label');
    for (var i = 0; i < labels.length; i++) {
      var label = labels[i];
      var text = normalizeText(label.textContent);
      if (text !== 'efectivo cajon' && text !== 'cash drawer' && text !== 'caja fisica') continue;

      var scope = label.parentElement || label.closest('.form-group');
      var select = scope && scope.querySelector ? scope.querySelector('select') : null;
      if (!select) {
        var group = label.closest('.form-group');
        select = group && group.querySelector ? group.querySelector('select') : null;
      }
      if (!select) continue;

      label.textContent = 'Caja física (opcional)';

      var sentinel = null;
      for (var j = 0; j < select.options.length; j++) {
        if (String(select.options[j].value) === NO_DRAWER_VALUE) {
          sentinel = select.options[j];
          break;
        }
      }
      if (!sentinel) {
        sentinel = document.createElement('option');
        sentinel.value = NO_DRAWER_VALUE;
        sentinel.textContent = 'Sin caja física';
        select.insertBefore(sentinel, select.firstChild);
      }

      var current = String(select.value || '').trim();
      if (!current) {
        select.value = NO_DRAWER_VALUE;
        select.dispatchEvent(new Event('input', { bubbles: true }));
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
  }

  function patchMountedPaymentModal() {
    if (!isPosPage()) return;

    var roots = document.querySelectorAll('.modern-payment-modal');
    for (var i = 0; i < roots.length; i++) {
      var el = roots[i];
      if (el && el.__vue__) patchInstance(el.__vue__);
    }

    var app = document.getElementById('app');
    if (app && app.__vue__) patchInstance(app.__vue__);

    patchOpenRegisterDrawerField();
  }

  function schedulePatch() {
    installAxiosPatch();
    setTimeout(patchMountedPaymentModal, 0);
    setTimeout(patchMountedPaymentModal, 100);
    setTimeout(patchMountedPaymentModal, 300);
  }

  schedulePatch();

  document.addEventListener('click', schedulePatch, true);
  window.addEventListener('popstate', schedulePatch);

  if (window.MutationObserver) {
    var observer = new MutationObserver(function () {
      patchMountedPaymentModal();
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  setInterval(function () {
    installAxiosPatch();
    patchMountedPaymentModal();
  }, 1000);
})();
