(function () {
  'use strict';

  // Compatibility shim kept temporarily because older layouts may still load
  // this asset. Physical cash drawers are no longer bypassed here.
  //
  // Native POS still uses Branch + InventoryLocation as its operating context,
  // but opening a cash-register session and restricted cashier operation must
  // use a real active CashDrawer assigned to that same context.
  window.__prodexOptionalCashDrawerInstalled = true;
})();
