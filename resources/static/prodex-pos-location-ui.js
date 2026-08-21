(function () {
  'use strict';

  if (window.__prodexPosLocationBridgeInstalled) return;
  window.__prodexPosLocationBridgeInstalled = true;

  var state = window.__prodexPosLocation = window.__prodexPosLocation || {
    loaded: false,
    loading: null,
    payload: null,
    selected: null,
    picker: null,
  };

  function api() {
    return window.axios || null;
  }

  function normalizeId(value) {
    var n = Number(value);
    return Number.isFinite(n) && n > 0 ? n : null;
  }

  function contextFromPayload(payload) {
    if (!payload || typeof payload !== 'object') return null;
    var effective = payload.effective || {};
    var branchId = normalizeId(effective.branch_id);
    var locationId = normalizeId(effective.inventory_location_id);
    var drawerId = normalizeId(effective.cash_drawer_id);

    if (!branchId || !locationId || !drawerId) return null;

    return {
      branch_id: branchId,
      inventory_location_id: locationId,
      cash_drawer_id: drawerId,
      source: effective.source || 'default'
    };
  }

  function current() {
    return state.selected || contextFromPayload(state.payload);
  }

  function branchById(id) {
    var rows = state.payload && Array.isArray(state.payload.branches) ? state.payload.branches : [];
    return rows.find(function (row) { return String(row.id) === String(id); }) || null;
  }

  function locationById(id) {
    var rows = state.payload && Array.isArray(state.payload.inventory_locations) ? state.payload.inventory_locations : [];
    return rows.find(function (row) { return String(row.id) === String(id); }) || null;
  }

  function drawerById(id) {
    var rows = state.payload && Array.isArray(state.payload.cash_drawers) ? state.payload.cash_drawers : [];
    return rows.find(function (row) { return String(row.id) === String(id); }) || null;
  }

  function canUseLocationMode() {
    var ctx = current();
    return !!(ctx && ctx.branch_id && ctx.inventory_location_id && ctx.cash_drawer_id);
  }

  function canChooseContext() {
    var payload = state.payload || {};
    return !!(
      payload.effective && payload.effective.can_override &&
      Array.isArray(payload.branches) && payload.branches.length &&
      Array.isArray(payload.inventory_locations) && payload.inventory_locations.length &&
      Array.isArray(payload.cash_drawers) && payload.cash_drawers.length
    );
  }

  function loadContext(force) {
    if (!force && state.loaded) return Promise.resolve(state.payload);
    if (!force && state.loading) return state.loading;

    var axios = api();
    if (!axios) return Promise.resolve(null);

    state.loading = axios.get('pos/operational-context', {
      meta: { skipInitialLoader: true, skipErrorRedirect: true, prodexPosContext: true }
    }).then(function (response) {
      state.payload = response && response.data ? response.data : null;
      state.loaded = true;
      if (!state.selected) state.selected = contextFromPayload(state.payload);
      applyOperationalChrome();
      return state.payload;
    }).catch(function () {
      state.loaded = true;
      state.payload = null;
      state.selected = null;
      return null;
    }).finally(function () {
      state.loading = null;
    });

    return state.loading;
  }

  function parseUrl(url) {
    try {
      return new URL(String(url || ''), window.location.origin + '/api/');
    } catch (e) {
      return null;
    }
  }

  function relativeApiUrl(parsed) {
    if (!parsed) return null;
    var path = parsed.pathname || '';
    path = path.replace(/^\/api\//, '').replace(/^\//, '');
    return path + (parsed.search || '');
  }

  function isPosCatalog(config) {
    var parsed = parseUrl(config && config.url);
    return !!(parsed && /\/pos\/get_products_pos$/i.test(parsed.pathname));
  }

  function isPosDelta(config) {
    var parsed = parseUrl(config && config.url);
    return !!(parsed && /\/pos\/get_products_pos_changes$/i.test(parsed.pathname));
  }

  function isCreatePos(config) {
    var parsed = parseUrl(config && config.url);
    return !!(parsed && /\/pos\/create_pos$/i.test(parsed.pathname));
  }

  function isBatchForSale(config) {
    var parsed = parseUrl(config && config.url);
    return !!(parsed && /\/batches_for_sale\/\d+\/\d+\/\d+$/i.test(parsed.pathname));
  }

  function requestDataObject(config) {
    if (!config) return null;
    if (config.data && typeof config.data === 'object') return config.data;
    if (typeof config.data === 'string' && config.data.trim()) {
      try {
        var decoded = JSON.parse(config.data);
        if (decoded && typeof decoded === 'object') {
          config.data = decoded;
          return decoded;
        }
      } catch (e) {}
    }
    return null;
  }

  function prepareLocationRequest(config) {
    var ctx = current();
    if (!ctx) return config;

    if (isCreatePos(config)) {
      var data = requestDataObject(config);
      if (data) {
        data.branch_id = ctx.branch_id;
        data.inventory_location_id = ctx.inventory_location_id;
        data.cash_drawer_id = ctx.cash_drawer_id;
        config.__prodexPosLocation = ctx;
      }
      return config;
    }

    if (isPosCatalog(config)) {
      var parsed = parseUrl(config.url);
      if (parsed) {
        config.__prodexOriginalStockFilter = parsed.searchParams.get('stock');
        // The legacy endpoint remains the metadata/pricing source during the
        // migration. Asking it for all rows prevents CD stock from hiding a
        // product that physically exists in a branch inventory location.
        parsed.searchParams.set('stock', '0');
        config.url = relativeApiUrl(parsed);
        config.__prodexPosLocation = ctx;
      }
      return config;
    }

    if (isPosDelta(config)) {
      var deltaParsed = parseUrl(config.url);
      var since = deltaParsed ? deltaParsed.searchParams.get('since') : null;
      if (!since && config.params) since = config.params.since;
      config.url = 'pos/location-inventory/' + ctx.inventory_location_id + '/changes';
      config.params = Object.assign({}, config.params || {}, { since: since });
      config.__prodexPosLocation = ctx;
      return config;
    }

    if (isBatchForSale(config)) {
      config.__prodexPosLocation = ctx;
    }

    return config;
  }

  function stockKey(productId, variantId) {
    return String(productId) + ':' + (variantId === null || variantId === undefined || variantId === '' ? 'null' : String(variantId));
  }

  function overlayLocationStock(response, ctx) {
    var axios = api();
    if (!axios || !response || !response.data || !Array.isArray(response.data.products)) {
      return Promise.resolve(response);
    }

    return axios.get('pos/location-inventory/' + ctx.inventory_location_id + '/stock-map', {
      meta: { skipInitialLoader: true, skipErrorRedirect: true, prodexPosStockMap: true }
    }).then(function (stockResponse) {
      var payload = stockResponse && stockResponse.data ? stockResponse.data : {};
      var rows = Array.isArray(payload.products) ? payload.products : [];
      var map = new Map();
      rows.forEach(function (row) {
        map.set(stockKey(row.product_id || row.id, row.product_variant_id), row);
      });

      response.data.products = response.data.products.map(function (product) {
        if (!product || product.product_type === 'is_service') return product;
        var row = map.get(stockKey(product.id, product.product_variant_id));
        return Object.assign({}, product, {
          qte: row ? Number(row.qte || 0) : 0,
          qte_sale: row ? Number(row.qte_sale || 0) : 0,
          reserved_quantity: row ? Number(row.reserved_quantity || 0) : 0,
          available_quantity: row ? Number(row.available_quantity || 0) : 0,
          inventory_location_id: ctx.inventory_location_id,
          branch_id: ctx.branch_id,
          stock_source: 'inventory_location'
        });
      });

      if (payload.server_time) response.data.server_time = payload.server_time;
      response.data.inventory_location_id = ctx.inventory_location_id;
      response.data.branch_id = ctx.branch_id;
      response.data.stock_source = 'inventory_location';
      response.data.totalRows = response.data.products.length;
      return response;
    }).catch(function () {
      // Do not silently fall back to CD quantities once location mode is active.
      // Returning zero stock is safer than allowing a cashier to sell inventory
      // that belongs to a different physical location.
      response.data.products = response.data.products.map(function (product) {
        if (!product || product.product_type === 'is_service') return product;
        return Object.assign({}, product, {
          qte: 0,
          qte_sale: 0,
          available_quantity: 0,
          inventory_location_id: ctx.inventory_location_id,
          branch_id: ctx.branch_id,
          stock_source: 'inventory_location_unavailable'
        });
      });
      return response;
    });
  }

  function overlayBatchAvailability(response, ctx, config) {
    var parsed = parseUrl(config && config.url);
    if (!parsed || !response || !response.data) return Promise.resolve(response);
    var match = parsed.pathname.match(/\/batches_for_sale\/(\d+)\/\d+\/(\d+)$/i);
    if (!match) return Promise.resolve(response);

    var productId = Number(match[1]);
    var variantId = Number(match[2]) || 0;
    var suffix = variantId > 0 ? '?product_variant_id=' + encodeURIComponent(variantId) : '';

    return api().get(
      'pos/location-inventory/' + ctx.inventory_location_id + '/products/' + productId + suffix,
      { meta: { skipInitialLoader: true, skipErrorRedirect: true, prodexPosTraceRead: true } }
    ).then(function (locationResponse) {
      var payload = locationResponse && locationResponse.data ? locationResponse.data : {};
      var batches = Array.isArray(payload.batches) ? payload.batches : [];
      response.data.batches = batches.map(function (batch) {
        return Object.assign({}, batch, {
          qty_available: Number(batch.available_quantity != null ? batch.available_quantity : batch.quantity || 0)
        });
      });
      response.data.inventory_location_id = ctx.inventory_location_id;
      return response;
    });
  }

  function installAxiosBridge() {
    var axios = api();
    if (!axios || axios.__prodexPosLocationInstalled) return;
    axios.__prodexPosLocationInstalled = true;

    axios.interceptors.request.use(function (config) {
      if (!config || (config.meta && (config.meta.prodexPosContext || config.meta.prodexPosStockMap || config.meta.prodexPosTraceRead))) {
        return config;
      }

      if (!isPosCatalog(config) && !isPosDelta(config) && !isCreatePos(config) && !isBatchForSale(config)) {
        return config;
      }

      return loadContext(false).then(function () {
        return canUseLocationMode() ? prepareLocationRequest(config) : config;
      });
    });

    axios.interceptors.response.use(function (response) {
      var config = response && response.config;
      var ctx = config && config.__prodexPosLocation;
      if (!ctx) return response;

      if (isPosCatalog(config)) return overlayLocationStock(response, ctx);
      if (isBatchForSale(config)) return overlayBatchAvailability(response, ctx, config);
      return response;
    }, function (error) {
      return Promise.reject(error);
    });
  }

  function contextLabel() {
    var ctx = current();
    if (!ctx) return null;
    var branch = branchById(ctx.branch_id);
    var location = locationById(ctx.inventory_location_id);
    var parts = [];
    if (branch && branch.name) parts.push(branch.name);
    if (location && location.name) parts.push(location.name);
    return parts.join(' · ') || null;
  }

  function applyOperationalChrome() {
    if (!canUseLocationMode()) return;
    var label = contextLabel();
    var trigger = document.querySelector('.pos-wh-trigger');
    if (trigger) {
      trigger.setAttribute('title', 'Sucursal y ubicación operativa');
      trigger.setAttribute('data-prodex-location-mode', '1');
      var eyebrow = trigger.querySelector('.pos-wh-trigger-eyebrow');
      var text = trigger.querySelector('.pos-wh-trigger-label');
      if (eyebrow) eyebrow.textContent = 'Sucursal / ubicación';
      if (text && label) text.textContent = label;
    }

    var drawer = document.querySelector('.wh-drawer-backdrop');
    if (drawer) drawer.style.display = 'none';
  }

  function closePicker() {
    if (state.picker && state.picker.parentNode) state.picker.parentNode.removeChild(state.picker);
    state.picker = null;
  }

  function pickerOption(select, value, label) {
    var option = document.createElement('option');
    option.value = String(value);
    option.textContent = label;
    select.appendChild(option);
  }

  function openPicker() {
    if (!canChooseContext()) return;
    closePicker();

    var payload = state.payload;
    var ctx = current() || {};
    var overlay = document.createElement('div');
    overlay.className = 'prodex-pos-context-picker';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.42);display:flex;align-items:center;justify-content:center;padding:20px;';

    var card = document.createElement('div');
    card.style.cssText = 'width:min(520px,100%);background:#fff;border-radius:16px;padding:22px;box-shadow:0 24px 70px rgba(15,23,42,.25);font-family:Inter,system-ui,sans-serif;';
    card.innerHTML = '<div style="font-size:18px;font-weight:700;color:#172033;margin-bottom:4px;">Cambiar ubicación operativa</div><div style="font-size:13px;color:#64748b;margin-bottom:18px;">Selecciona la sucursal, el inventario de venta y la caja para esta sesión de POS.</div>';

    function field(label) {
      var wrap = document.createElement('label');
      wrap.style.cssText = 'display:block;margin-bottom:14px;font-size:12px;font-weight:700;color:#475569;';
      var title = document.createElement('span');
      title.textContent = label;
      title.style.cssText = 'display:block;margin-bottom:6px;';
      var select = document.createElement('select');
      select.style.cssText = 'width:100%;height:42px;border:1px solid #dbe1ea;border-radius:9px;padding:0 10px;background:#fff;color:#172033;';
      wrap.appendChild(title);
      wrap.appendChild(select);
      card.appendChild(wrap);
      return select;
    }

    var branchSelect = field('Sucursal');
    var locationSelect = field('Ubicación de inventario');
    var drawerSelect = field('Caja física');

    (payload.branches || []).forEach(function (row) { pickerOption(branchSelect, row.id, row.name); });

    function populateLocations() {
      locationSelect.innerHTML = '';
      var rows = (payload.inventory_locations || []).filter(function (row) {
        return String(row.branch_id) === String(branchSelect.value);
      });
      rows.forEach(function (row) { pickerOption(locationSelect, row.id, row.name); });
      if (ctx.inventory_location_id && rows.some(function (r) { return String(r.id) === String(ctx.inventory_location_id); })) {
        locationSelect.value = String(ctx.inventory_location_id);
      }
      populateDrawers();
    }

    function populateDrawers() {
      drawerSelect.innerHTML = '';
      var rows = (payload.cash_drawers || []).filter(function (row) {
        return String(row.branch_id) === String(branchSelect.value) && String(row.inventory_location_id) === String(locationSelect.value);
      });
      rows.forEach(function (row) { pickerOption(drawerSelect, row.id, row.name); });
      if (ctx.cash_drawer_id && rows.some(function (r) { return String(r.id) === String(ctx.cash_drawer_id); })) {
        drawerSelect.value = String(ctx.cash_drawer_id);
      }
    }

    if (ctx.branch_id) branchSelect.value = String(ctx.branch_id);
    populateLocations();
    branchSelect.addEventListener('change', populateLocations);
    locationSelect.addEventListener('change', populateDrawers);

    var actions = document.createElement('div');
    actions.style.cssText = 'display:flex;justify-content:flex-end;gap:10px;margin-top:18px;';
    var cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.textContent = 'Cancelar';
    cancel.style.cssText = 'height:40px;padding:0 16px;border:1px solid #dbe1ea;border-radius:9px;background:#fff;color:#475569;font-weight:700;cursor:pointer;';
    var save = document.createElement('button');
    save.type = 'button';
    save.textContent = 'Usar ubicación';
    save.style.cssText = 'height:40px;padding:0 16px;border:0;border-radius:9px;background:#4f46e5;color:#fff;font-weight:700;cursor:pointer;';
    actions.appendChild(cancel);
    actions.appendChild(save);
    card.appendChild(actions);
    overlay.appendChild(card);
    document.body.appendChild(overlay);
    state.picker = overlay;

    cancel.addEventListener('click', closePicker);
    overlay.addEventListener('click', function (event) { if (event.target === overlay) closePicker(); });
    save.addEventListener('click', function () {
      var branchId = normalizeId(branchSelect.value);
      var locationId = normalizeId(locationSelect.value);
      var drawerId = normalizeId(drawerSelect.value);
      if (!branchId || !locationId || !drawerId) return;

      state.selected = {
        branch_id: branchId,
        inventory_location_id: locationId,
        cash_drawer_id: drawerId,
        source: 'session_override'
      };
      closePicker();
      applyOperationalChrome();
      window.dispatchEvent(new CustomEvent('prodex:pos-context-changed', { detail: state.selected }));

      // The existing Vue POS owns the catalog state. A controlled reload is the
      // safest way to clear any cart/stock cached for the previous physical site.
      if (window.location && /\/app\/pos(?:$|[/?#])/i.test(window.location.href)) {
        window.location.reload();
      }
    });
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target && event.target.closest ? event.target.closest('.pos-wh-trigger') : null;
    if (!trigger || !canUseLocationMode()) return;

    event.preventDefault();
    event.stopPropagation();
    if (event.stopImmediatePropagation) event.stopImmediatePropagation();
    if (canChooseContext()) openPicker();
  }, true);

  var observer = new MutationObserver(function () {
    if (canUseLocationMode()) applyOperationalChrome();
  });

  function boot() {
    installAxiosBridge();
    loadContext(false);
    try {
      observer.observe(document.documentElement, { childList: true, subtree: true });
    } catch (e) {}
    setInterval(function () {
      if (/\/app\/pos(?:$|[/?#])/i.test(window.location.href)) {
        loadContext(false).then(applyOperationalChrome);
      }
    }, 3000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
