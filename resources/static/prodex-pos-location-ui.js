(function () {
  'use strict';

  if (window.__prodexPosLocationBridgeInstalled) return;
  window.__prodexPosLocationBridgeInstalled = true;

  var SESSION_KEY = 'prodex_pos_operational_context_v1';
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

  function readStoredContext() {
    try {
      var raw = window.sessionStorage ? window.sessionStorage.getItem(SESSION_KEY) : null;
      if (!raw) return null;
      var parsed = JSON.parse(raw);
      return {
        branch_id: normalizeId(parsed.branch_id),
        inventory_location_id: normalizeId(parsed.inventory_location_id),
        cash_drawer_id: normalizeId(parsed.cash_drawer_id),
        source: 'session_override'
      };
    } catch (e) {
      return null;
    }
  }

  function persistContext(ctx) {
    try {
      if (!window.sessionStorage) return;
      if (!ctx) {
        window.sessionStorage.removeItem(SESSION_KEY);
        return;
      }
      window.sessionStorage.setItem(SESSION_KEY, JSON.stringify({
        branch_id: ctx.branch_id,
        inventory_location_id: ctx.inventory_location_id,
        cash_drawer_id: ctx.cash_drawer_id
      }));
    } catch (e) {}
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

  function contextIsAllowed(ctx, payload) {
    if (!ctx || !payload) return false;
    var branch = (payload.branches || []).find(function (row) { return String(row.id) === String(ctx.branch_id); });
    var location = (payload.inventory_locations || []).find(function (row) {
      return String(row.id) === String(ctx.inventory_location_id) && String(row.branch_id) === String(ctx.branch_id);
    });
    var drawer = (payload.cash_drawers || []).find(function (row) {
      return String(row.id) === String(ctx.cash_drawer_id)
        && String(row.branch_id) === String(ctx.branch_id)
        && String(row.inventory_location_id) === String(ctx.inventory_location_id);
    });
    return !!(branch && location && drawer);
  }

  function restoreSelection(payload) {
    var effective = contextFromPayload(payload);
    var stored = readStoredContext();
    var canOverride = !!(payload && payload.effective && payload.effective.can_override);

    if (stored && canOverride && contextIsAllowed(stored, payload)) {
      state.selected = stored;
      return;
    }

    if (stored) persistContext(null);
    state.selected = effective;
  }

  function current() {
    return state.selected || contextFromPayload(state.payload);
  }

  function canUseLocationMode() {
    var ctx = current();
    return !!(ctx && ctx.branch_id && ctx.inventory_location_id && ctx.cash_drawer_id && contextIsAllowed(ctx, state.payload));
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
      restoreSelection(state.payload);
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

  function isLocationDelta(config) {
    var parsed = parseUrl(config && config.url);
    return !!(parsed && /\/pos\/location-inventory\/\d+\/changes$/i.test(parsed.pathname));
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
        config.__prodexPosSaleDetails = Array.isArray(data.details) ? data.details.map(function (row) {
          return {
            product_id: normalizeId(row && row.product_id),
            product_variant_id: normalizeId(row && row.product_variant_id)
          };
        }) : [];
        config.__prodexPosLocation = ctx;
      }
      return config;
    }

    if (isPosCatalog(config)) {
      var parsed = parseUrl(config.url);
      if (parsed) {
        config.__prodexOriginalStockFilter = parsed.searchParams.get('stock');
        // Product metadata/pricing remains on the legacy endpoint during the
        // cutover. Stock itself is overlaid from InventoryLocation below.
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

  function fetchStockMap(ctx) {
    return api().get('pos/location-inventory/' + ctx.inventory_location_id + '/stock-map', {
      meta: { skipInitialLoader: true, skipErrorRedirect: true, prodexPosStockMap: true }
    }).then(function (stockResponse) {
      return stockResponse && stockResponse.data ? stockResponse.data : {};
    });
  }

  function overlayLocationStock(response, ctx) {
    if (!api() || !response || !response.data || !Array.isArray(response.data.products)) {
      return Promise.resolve(response);
    }

    return fetchStockMap(ctx).then(function (payload) {
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
      // Once a POS is location-aware, never expose CD quantities as a fallback.
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

  function refreshSaleUpdatedStock(response, ctx, config) {
    if (!response || !response.data || response.data.success !== true) return Promise.resolve(response);
    var sold = Array.isArray(config.__prodexPosSaleDetails) ? config.__prodexPosSaleDetails : [];
    if (!sold.length) return Promise.resolve(response);

    var wanted = new Set();
    sold.forEach(function (row) {
      if (row && row.product_id) wanted.add(stockKey(row.product_id, row.product_variant_id));
    });

    return fetchStockMap(ctx).then(function (payload) {
      var rows = Array.isArray(payload.products) ? payload.products : [];
      response.data.updated_stock = rows.filter(function (row) {
        return wanted.has(stockKey(row.product_id || row.id, row.product_variant_id));
      });
      response.data.server_time = payload.server_time || response.data.server_time;
      response.data.inventory_location_id = ctx.inventory_location_id;
      response.data.branch_id = ctx.branch_id;
      return response;
    }).catch(function () {
      // Never let the old CD quantity overwrite the location-aware catalog after
      // a sale. If refresh fails, leave the UI waiting for the next location delta.
      response.data.updated_stock = [];
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
      if (isCreatePos(config)) return refreshSaleUpdatedStock(response, ctx, config);
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
    var trigger = document.querySelector('.pos-wh-trigger');
    if (!trigger) return;

    if (!canUseLocationMode() && !canChooseContext()) return;

    var expectedTitle = 'Sucursal y ubicación operativa';
    if (trigger.getAttribute('title') !== expectedTitle) trigger.setAttribute('title', expectedTitle);
    if (trigger.getAttribute('data-prodex-location-mode') !== '1') trigger.setAttribute('data-prodex-location-mode', '1');

    var eyebrow = trigger.querySelector('.pos-wh-trigger-eyebrow');
    var text = trigger.querySelector('.pos-wh-trigger-label');
    var expectedEyebrow = 'Sucursal / ubicación';
    var expectedLabel = contextLabel() || 'Seleccionar ubicación';

    if (eyebrow && eyebrow.textContent !== expectedEyebrow) eyebrow.textContent = expectedEyebrow;
    if (text && text.textContent !== expectedLabel) text.textContent = expectedLabel;

    var drawer = document.querySelector('.wh-drawer-backdrop');
    if (drawer && drawer.style.display !== 'none') drawer.style.display = 'none';
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
      var selected = {
        branch_id: branchId,
        inventory_location_id: locationId,
        cash_drawer_id: drawerId,
        source: 'session_override'
      };
      if (!branchId || !locationId || !drawerId || !contextIsAllowed(selected, payload)) return;

      state.selected = selected;
      persistContext(selected);
      closePicker();
      applyOperationalChrome();
      window.dispatchEvent(new CustomEvent('prodex:pos-context-changed', { detail: state.selected }));

      // Reload guarantees the legacy Vue cart cannot retain quantities from a
      // different branch after an authorized operational reassignment.
      if (window.location && /\/app\/pos(?:$|[/?#])/i.test(window.location.href)) {
        window.location.reload();
      }
    });
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target && event.target.closest ? event.target.closest('.pos-wh-trigger') : null;
    if (!trigger || (!canUseLocationMode() && !canChooseContext())) return;

    event.preventDefault();
    event.stopPropagation();
    if (event.stopImmediatePropagation) event.stopImmediatePropagation();
    if (canChooseContext()) openPicker();
  }, true);

  var chromePatchScheduled = false;
  function scheduleOperationalChrome() {
    if (chromePatchScheduled) return;
    chromePatchScheduled = true;

    var run = function () {
      chromePatchScheduled = false;
      if (canUseLocationMode() || canChooseContext()) applyOperationalChrome();
    };

    if (typeof window.requestAnimationFrame === 'function') {
      window.requestAnimationFrame(run);
    } else {
      window.setTimeout(run, 16);
    }
  }

  var observer = new MutationObserver(function () {
    scheduleOperationalChrome();
  });

  function boot() {
    installAxiosBridge();
    loadContext(false).then(scheduleOperationalChrome);
    try {
      observer.observe(document.body || document.documentElement, { childList: true, subtree: true });
    } catch (e) {}
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
