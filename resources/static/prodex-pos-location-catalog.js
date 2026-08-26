(function () {
  'use strict';

  if (window.__prodexPosLocationCatalogInstalled) return;
  window.__prodexPosLocationCatalogInstalled = true;

  function parseUrl(url) {
    try {
      return new URL(String(url || ''), window.location.origin + '/api/');
    } catch (e) {
      return null;
    }
  }

  function relativeApiUrl(parsed) {
    if (!parsed) return null;
    return (parsed.pathname || '').replace(/^\/api\//, '').replace(/^\//, '') + (parsed.search || '');
  }

  function isLegacyCatalog(config) {
    var parsed = parseUrl(config && config.url);
    return !!(parsed && /\/pos\/get_products_pos$/i.test(parsed.pathname));
  }

  function normalizeId(value) {
    var n = Number(value);
    return Number.isFinite(n) && n > 0 ? n : null;
  }

  function contextFromGlobal() {
    var state = window.__prodexPosLocation || {};
    var selected = state.selected || null;
    if (selected) {
      var selectedLocation = normalizeId(selected.inventory_location_id);
      if (selectedLocation) return selected;
    }

    var effective = state.payload && state.payload.effective ? state.payload.effective : null;
    var locationId = effective ? normalizeId(effective.inventory_location_id) : null;
    if (!locationId) return null;

    return {
      branch_id: normalizeId(effective.branch_id),
      inventory_location_id: locationId,
      cash_drawer_id: normalizeId(effective.cash_drawer_id),
      source: effective.source || 'default'
    };
  }

  function resolveContext() {
    var existing = contextFromGlobal();
    if (existing) return Promise.resolve(existing);

    if (!window.axios) return Promise.resolve(null);

    return window.axios.get('pos/operational-context', {
      meta: {
        skipInitialLoader: true,
        skipErrorRedirect: true,
        prodexPosContext: true,
        prodexPosNativeCatalogContext: true
      }
    }).then(function (response) {
      var payload = response && response.data ? response.data : null;
      if (!payload || !payload.effective) return null;
      var locationId = normalizeId(payload.effective.inventory_location_id);
      if (!locationId) return null;
      return {
        branch_id: normalizeId(payload.effective.branch_id),
        inventory_location_id: locationId,
        cash_drawer_id: normalizeId(payload.effective.cash_drawer_id),
        source: payload.effective.source || 'default'
      };
    }).catch(function () {
      return null;
    });
  }

  function install() {
    if (!window.axios || window.axios.__prodexPosNativeCatalogInstalled) return false;
    window.axios.__prodexPosNativeCatalogInstalled = true;

    window.axios.interceptors.request.use(function (config) {
      if (!config || (config.meta && config.meta.prodexPosNativeCatalogContext) || !isLegacyCatalog(config)) {
        return config;
      }

      return resolveContext().then(function (ctx) {
        if (!ctx || !ctx.inventory_location_id) return config;

        var parsed = parseUrl(config.url);
        if (!parsed) return config;

        parsed.pathname = '/api/pos/location-inventory/' + ctx.inventory_location_id + '/catalog';
        parsed.searchParams.delete('warehouse_id');
        config.url = relativeApiUrl(parsed);
        config.__prodexPosNativeCatalog = true;
        config.__prodexPosNativeLocationId = ctx.inventory_location_id;
        return config;
      });
    });

    return true;
  }

  function boot() {
    if (install()) return;
    var attempts = 0;
    var timer = setInterval(function () {
      attempts += 1;
      if (install() || attempts >= 40) clearInterval(timer);
    }, 100);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
