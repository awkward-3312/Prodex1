(function () {
  'use strict';

  if (window.__prodexOptionalCashDrawerInstalled) return;
  window.__prodexOptionalCashDrawerInstalled = true;

  function isPosPage() {
    return window.location && window.location.pathname === '/app/pos';
  }

  function patchInstance(vm) {
    if (!vm || vm.__prodexOptionalCashDrawerPatched) return;

    try {
      if (vm.$options && vm.$options.name === 'ModernPaymentModal' && typeof vm.ensureCashDrawerAssigned === 'function') {
        vm.__prodexOptionalCashDrawerPatched = true;
        vm.ensureCashDrawerAssigned = function () {
          // A physical drawer is optional in the native POS model. The backend
          // already accepts cash_drawer_id = null and still validates the user's
          // operational Branch + InventoryLocation assignment.
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

  function patchMountedPaymentModal() {
    if (!isPosPage()) return;

    var roots = document.querySelectorAll('.modern-payment-modal');
    for (var i = 0; i < roots.length; i++) {
      var el = roots[i];
      if (el && el.__vue__) patchInstance(el.__vue__);
    }

    var app = document.getElementById('app');
    if (app && app.__vue__) patchInstance(app.__vue__);
  }

  function schedulePatch() {
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

  setInterval(patchMountedPaymentModal, 1000);
})();
