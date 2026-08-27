(function () {
  'use strict';

  if (window.__prodexPosLocationDeltaSafetyInstalled) return;
  window.__prodexPosLocationDeltaSafetyInstalled = true;

  function isDeltaRequest(config) {
    if (!config || typeof config.url !== 'string') return false;
    var raw = config.url.split('?')[0];
    raw = raw.replace(/^https?:\/\/[^/]+/i, '').replace(/^\/api\//, '').replace(/^\//, '');
    return raw === 'pos/get_products_pos_changes'
      || /^pos\/location-inventory\/\d+\/changes$/i.test(raw);
  }

  function stripWarehouseFromUrl(url) {
    if (typeof url !== 'string' || url.indexOf('?') === -1) return url;
    try {
      var parsed = new URL(url, window.location.origin + '/api/');
      parsed.searchParams.delete('warehouse_id');
      var path = (parsed.pathname || '').replace(/^\/api\//, '').replace(/^\//, '');
      var query = parsed.searchParams.toString();
      return path + (query ? '?' + query : '');
    } catch (e) {
      return url;
    }
  }

  function sanitize(config) {
    if (!isDeltaRequest(config)) return config;

    config.url = stripWarehouseFromUrl(config.url);
    config.params = Object.assign({}, config.params || {});
    delete config.params.warehouse_id;
    config.meta = Object.assign({}, config.meta || {}, {
      skipInitialLoader: true,
      skipErrorRedirect: true,
      prodexPosLocationDeltaPoll: true
    });

    return config;
  }

  function install() {
    if (!window.axios || window.axios.__prodexPosLocationDeltaSafetyInstalled) return false;
    window.axios.__prodexPosLocationDeltaSafetyInstalled = true;
    // This script is intentionally loaded after the POS bridges. Axios request
    // interceptors run in reverse registration order, so this sanitizer sees the
    // legacy polling request first and removes warehouse_id before any bridge can
    // carry that legacy scope into the native inventory-location endpoint.
    window.axios.interceptors.request.use(sanitize);
    return true;
  }

  if (!install()) {
    var attempts = 0;
    var timer = setInterval(function () {
      attempts += 1;
      if (install() || attempts >= 40) clearInterval(timer);
    }, 100);
  }
})();
