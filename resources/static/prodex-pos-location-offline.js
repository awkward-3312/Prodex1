(function () {
  'use strict';

  if (window.__prodexPosLocationOfflineInstalled) return;
  window.__prodexPosLocationOfflineInstalled = true;

  var SALES_KEY = 'pos_offline_sales_v1';
  var SNAPSHOT_KEY = 'pos_warehouse_snapshots_v1';
  var DETAIL_KEY = 'pos_product_details_v1';
  var MARKER_KEY = 'prodex_pos_location_cache_marker_v1';

  function storage() {
    try { return window.localStorage || null; } catch (e) { return null; }
  }

  function read(key, fallback) {
    var s = storage();
    if (!s) return fallback;
    try {
      var raw = s.getItem(key);
      return raw ? JSON.parse(raw) : fallback;
    } catch (e) { return fallback; }
  }

  function write(key, value) {
    var s = storage();
    if (!s) return;
    try { s.setItem(key, JSON.stringify(value)); } catch (e) {}
  }

  function currentContext() {
    var state = window.__prodexPosLocation || {};
    var ctx = state.selected || (state.payload && state.payload.effective) || null;
    if (!ctx) return null;
    var branchId = Number(ctx.branch_id || 0);
    var locationId = Number(ctx.inventory_location_id || 0);
    var drawerId = Number(ctx.cash_drawer_id || 0);
    if (!branchId || !locationId || !drawerId) return null;
    return {
      branch_id: branchId,
      inventory_location_id: locationId,
      cash_drawer_id: drawerId
    };
  }

  function pendingSales() {
    var list = read(SALES_KEY, []);
    if (!Array.isArray(list)) return [];
    return list.filter(function (row) {
      return row && row.status !== 'synced';
    });
  }

  function enrichOfflineQueue(ctx) {
    var list = read(SALES_KEY, []);
    if (!Array.isArray(list) || !list.length) return;
    var changed = false;

    list.forEach(function (row) {
      if (!row || row.status === 'synced' || !row.payload || typeof row.payload !== 'object') return;
      var payload = row.payload;

      // Existing context is immutable: never rewrite an offline transaction that
      // was already stamped at another physical location.
      if (payload.branch_id && payload.inventory_location_id && payload.cash_drawer_id) return;

      payload.branch_id = ctx.branch_id;
      payload.inventory_location_id = ctx.inventory_location_id;
      payload.cash_drawer_id = payload.cash_drawer_id || ctx.cash_drawer_id;
      payload.operational_context_version = 1;
      row.updatedAt = new Date().toISOString();
      changed = true;
    });

    if (changed) write(SALES_KEY, list);
  }

  function markerFor(ctx) {
    return String(ctx.branch_id) + ':' + String(ctx.inventory_location_id);
  }

  function keepCacheScoped(ctx) {
    var s = storage();
    if (!s) return;
    var next = markerFor(ctx);
    var current = null;
    try { current = s.getItem(MARKER_KEY); } catch (e) {}

    if (!current) {
      try { s.setItem(MARKER_KEY, next); } catch (e) {}
      return;
    }
    if (current === next) return;

    // Never discard local stock state while unsynced transactions still depend
    // on it. The context picker is disabled in that situation as well.
    if (pendingSales().length) return;

    try {
      s.removeItem(SNAPSHOT_KEY);
      s.removeItem(DETAIL_KEY);
      s.setItem(MARKER_KEY, next);
    } catch (e) {}
  }

  function guardContextPicker() {
    var picker = document.querySelector('.prodex-pos-context-picker');
    if (!picker) return;
    var buttons = picker.querySelectorAll('button');
    var save = null;
    buttons.forEach(function (button) {
      if ((button.textContent || '').trim() === 'Usar ubicación') save = button;
    });
    if (!save) return;

    var offline = typeof navigator !== 'undefined' && navigator.onLine === false;
    var pending = pendingSales().length;
    if (!offline && !pending) {
      save.disabled = false;
      save.style.opacity = '';
      save.style.cursor = 'pointer';
      var existing = picker.querySelector('[data-prodex-context-warning]');
      if (existing && existing.parentNode) existing.parentNode.removeChild(existing);
      return;
    }

    save.disabled = true;
    save.style.opacity = '.5';
    save.style.cursor = 'not-allowed';

    if (!picker.querySelector('[data-prodex-context-warning]')) {
      var warning = document.createElement('div');
      warning.setAttribute('data-prodex-context-warning', '1');
      warning.style.cssText = 'margin-top:12px;padding:10px 12px;border-radius:8px;background:#fff7ed;color:#9a3412;font-size:12px;line-height:1.45;';
      warning.textContent = offline
        ? 'Conéctate a internet antes de cambiar de sucursal o ubicación. Las ventas offline deben conservar el lugar físico donde fueron registradas.'
        : 'Hay ventas offline pendientes de sincronizar. Sincronízalas antes de cambiar de sucursal o ubicación.';
      var card = picker.firstElementChild;
      if (card) card.appendChild(warning);
    }
  }

  function tick() {
    var ctx = currentContext();
    if (ctx) {
      keepCacheScoped(ctx);
      enrichOfflineQueue(ctx);
    }
    guardContextPicker();
  }

  var observer = new MutationObserver(guardContextPicker);
  try { observer.observe(document.documentElement, { childList: true, subtree: true }); } catch (e) {}

  tick();
  setInterval(tick, 350);
})();
