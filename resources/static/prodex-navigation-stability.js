(function () {
  'use strict';

  if (window.__prodexNavigationIdentityInstalled) return;
  window.__prodexNavigationIdentityInstalled = true;

  // Compatibility shim. Module identity is now fixed by navigation-v3 from
  // the top-level label/direct route and never inferred from lazy submenu DOM.
  // Synthetic hidden hrefs and subtree observers are intentionally removed.
})();
