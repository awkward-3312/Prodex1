const state = {
  installed: false,
  context: null,
  contextPromise: null,
  uiObserver: null,
  styleInstalled: false,
};

function normalizePath(url) {
  const raw = String(url || '').split('?')[0];
  return raw.replace(/^https?:\/\/[^/]+/i, '').replace(/^\/api\//, '').replace(/^\//, '');
}

function withMeta(config, extra) {
  config.meta = Object.assign({}, config.meta || {}, extra || {});
  return config;
}

function findLocation(context, id) {
  if (!context || !Array.isArray(context.inventory_locations)) return null;
  const numeric = Number(id);
  if (!Number.isFinite(numeric) || numeric <= 0) return null;
  return context.inventory_locations.find(location => Number(location.id) === numeric) || null;
}

function effectiveLocation(context) {
  return context && context.effective
    ? findLocation(context, context.effective.inventory_location_id)
    : null;
}

function branchForLocation(context, location) {
  if (!context || !location || !Array.isArray(context.branches)) return null;
  return context.branches.find(branch => Number(branch.id) === Number(location.branch_id)) || null;
}

function compatibilityWarehouseId(context, location) {
  if (!context || !location) return null;
  const effective = context.effective || {};
  if (Number(effective.inventory_location_id) === Number(location.id) && Number(effective.legacy_warehouse_id) > 0) {
    return Number(effective.legacy_warehouse_id);
  }
  const branch = branchForLocation(context, location);
  return branch && Number(branch.default_warehouse_id) > 0
    ? Number(branch.default_warehouse_id)
    : null;
}

function requestDataObject(config) {
  if (!config) return {};
  if (config.data && typeof config.data === 'object') return config.data;
  if (typeof config.data === 'string' && config.data.trim()) {
    try {
      const parsed = JSON.parse(config.data);
      if (parsed && typeof parsed === 'object') {
        config.data = parsed;
        return parsed;
      }
    } catch (e) {}
  }
  return {};
}

function locationForConfig(context, config) {
  if (!context) return null;
  const params = (config && config.params) || {};
  const data = requestDataObject(config);
  const requested = params.inventory_location_id || params.warehouse_id || data.inventory_location_id || data.warehouse_id;
  return findLocation(context, requested) || effectiveLocation(context);
}

async function loadContext(axios) {
  if (state.context) return state.context;
  if (state.contextPromise) return state.contextPromise;

  state.contextPromise = axios.get('pos/operational-context', {
    meta: {
      skipErrorRedirect: true,
      skipInitialLoader: true,
      prodexOperationalContextRequest: true,
    },
  }).then(response => {
    state.context = response && response.data ? response.data : null;
    return state.context;
  }).catch(() => null).finally(() => {
    state.contextPromise = null;
  });

  return state.contextPromise;
}

function locationOptions(context) {
  const effective = effectiveLocation(context);
  if (!effective) return [];
  const canOverride = !!(context.effective && context.effective.can_override);
  const allowed = Array.isArray(context.inventory_locations) ? context.inventory_locations : [];
  return canOverride ? allowed : [effective];
}

function mapCashDrawers(context) {
  if (!context || !Array.isArray(context.cash_drawers)) return [];
  const effective = context.effective || {};
  const canOverride = !!effective.can_override;
  let drawers = context.cash_drawers;

  if (!canOverride && Number(effective.cash_drawer_id) > 0) {
    drawers = drawers.filter(drawer => Number(drawer.id) === Number(effective.cash_drawer_id));
  } else if (!canOverride && Number(effective.inventory_location_id) > 0) {
    drawers = drawers.filter(drawer => Number(drawer.inventory_location_id) === Number(effective.inventory_location_id));
  }

  return drawers.map(drawer => Object.assign({}, drawer, {
    // Legacy POS filters drawers by warehouse_id. While the POS UI is migrated,
    // expose the location id through that compatibility field.
    warehouse_id: Number(drawer.inventory_location_id),
  }));
}

function installLockedUiStyle() {
  if (state.styleInstalled || typeof document === 'undefined') return;
  state.styleInstalled = true;
  const style = document.createElement('style');
  style.id = 'prodex-pos-operational-location-style';
  style.textContent = `
    html.prodex-pos-location-locked .pos-wh-trigger {
      cursor: default !important;
      pointer-events: none !important;
    }
    html.prodex-pos-location-locked .pos-wh-trigger .pos-wh-trigger-caret {
      display: none !important;
    }
  `;
  document.head.appendChild(style);
}

function patchOperationalLocationUi(context) {
  if (typeof document === 'undefined' || !context || !context.effective) return;
  installLockedUiStyle();

  const locked = !!context.effective.inventory_location_id && !context.effective.can_override;
  document.documentElement.classList.toggle('prodex-pos-location-locked', locked);

  const patch = () => {
    document.querySelectorAll('.pos-wh-trigger').forEach(trigger => {
      trigger.setAttribute('title', locked ? 'Ubicación operativa asignada' : 'Seleccionar ubicación');
      trigger.setAttribute('aria-disabled', locked ? 'true' : 'false');
      if (locked) trigger.setAttribute('tabindex', '-1');
      const eyebrow = trigger.querySelector('.pos-wh-trigger-eyebrow');
      if (eyebrow && eyebrow.textContent !== 'Ubicación') eyebrow.textContent = 'Ubicación';
    });
  };

  patch();
  if (!state.uiObserver && typeof MutationObserver !== 'undefined') {
    state.uiObserver = new MutationObserver(patch);
    state.uiObserver.observe(document.body, { childList: true, subtree: true });
  }
}

function decorateBootstrap(data, context) {
  if (!data || !context || !context.effective) return data;
  const effective = effectiveLocation(context);
  if (!effective || !effective.branch_id) return data;

  const branch = branchForLocation(context, effective);
  const options = locationOptions(context);
  const virtualWarehouses = options.map(location => {
    const owner = branchForLocation(context, location);
    return {
      id: Number(location.id),
      name: String(location.name || 'Ubicación'),
      code: location.code || null,
      branch_id: Number(location.branch_id),
      branch_name: owner ? owner.name : null,
      inventory_location_id: Number(location.id),
      is_inventory_location: true,
      compatibility_warehouse_id: compatibilityWarehouseId(context, location),
    };
  });

  data.warehouses = virtualWarehouses;
  data.defaultWarehouse = Number(effective.id);
  data.defaultCashDrawer = context.effective.cash_drawer_id || null;
  data.cash_drawers = mapCashDrawers(context);
  data.effective_assignment = Object.assign({}, data.effective_assignment || {}, {
    source: context.effective.source,
    warehouse_id: Number(effective.id),
    branch_id: Number(effective.branch_id),
    inventory_location_id: Number(effective.id),
    cash_drawer_id: context.effective.cash_drawer_id || null,
    can_override: !!context.effective.can_override,
    warehouse_name: effective.name,
    inventory_location_name: effective.name,
    inventory_location_code: effective.code || null,
    branch_name: branch ? branch.name : null,
    branch_code: branch ? branch.code : null,
  });

  patchOperationalLocationUi(context);
  return data;
}

function transformLocationRows(rows) {
  if (!Array.isArray(rows)) return [];
  return rows.map(row => {
    if (row && row.available_quantity !== undefined && row.available_quantity !== null) {
      return Object.assign({}, row, { qte: row.available_quantity });
    }
    return row;
  });
}

function transformLocationDelta(response) {
  if (!response || !response.data || !Array.isArray(response.data.products)) return response;
  response.data.products = transformLocationRows(response.data.products);
  return response;
}

async function refreshSaleLocationStock(axios, response) {
  const config = response && response.config;
  const meta = config && config.meta;
  if (!response || !response.data || response.data.success !== true || !meta || !meta.prodexLocationSale) {
    return response;
  }

  const locationId = Number(meta.inventoryLocationId || 0);
  const sold = Array.isArray(meta.prodexLocationSaleDetails) ? meta.prodexLocationSaleDetails : [];
  if (!locationId || !sold.length) return response;

  const wanted = new Set(sold.map(row => `${Number(row.product_id)}:${Number(row.product_variant_id || 0)}`));
  try {
    const stockResponse = await axios.get(`pos/location-inventory/${locationId}/stock-map`, {
      meta: {
        skipErrorRedirect: true,
        skipInitialLoader: true,
        prodexLocationStockRefresh: true,
      },
    });
    const rows = transformLocationRows((stockResponse.data && stockResponse.data.products) || []);
    response.data.updated_stock = rows.filter(row => wanted.has(`${Number(row.product_id || row.id)}:${Number(row.product_variant_id || 0)}`));
    if (stockResponse.data && stockResponse.data.server_time) {
      response.data.server_time = stockResponse.data.server_time;
    }
    response.data.inventory_location_id = locationId;
  } catch (e) {
    // Never let a legacy warehouse quantity overwrite the location-aware POS.
    // The next location delta will refresh the UI when connectivity recovers.
    response.data.updated_stock = [];
  }
  return response;
}

function parseBatchPath(path) {
  const match = path.match(/^batches_for_sale\/(\d+)\/(\d+)\/(\d+)?$/);
  if (!match) return null;
  return {
    productId: Number(match[1]),
    locationId: Number(match[2]),
    variantId: Number(match[3] || 0),
  };
}

export function installPosOperationalLocationBridge(axios) {
  if (!axios || state.installed) return;
  state.installed = true;

  axios.interceptors.request.use(async config => {
    const path = normalizePath(config && config.url);
    if (!path || (config.meta && (config.meta.prodexOperationalContextRequest || config.meta.prodexLocationStockRefresh))) return config;

    const isCurrentRegister = /^cash-registers\/current\/\d+$/.test(path);
    const relevant = path === 'pos/data_create_pos'
      || path === 'pos/get_products_pos'
      || path === 'pos/get_products_pos_changes'
      || path === 'pos/create_pos'
      || path === 'serial_numbers/available'
      || isCurrentRegister
      || /^batches_for_sale\//.test(path);

    if (!relevant) return config;

    const context = await loadContext(axios);
    if (!context || !context.effective || !context.effective.inventory_location_id) return config;

    if (path === 'pos/data_create_pos') {
      return config;
    }

    if (isCurrentRegister) {
      const params = Object.assign({}, config.params || {});
      const location = locationForConfig(context, config);
      const compatibilityId = location ? compatibilityWarehouseId(context, location) : null;

      // The POS UI exposes an inventory-location id through its legacy
      // sale.warehouse_id field. The cash-register endpoint still expects a
      // real warehouses.id, so never send the location id as warehouse_id.
      if (compatibilityId) params.warehouse_id = compatibilityId;
      else delete params.warehouse_id;

      // A cashier may legitimately have a sellable location without a physical
      // drawer. Only scope by drawer when one is actually assigned.
      if (!context.effective.can_override && Number(context.effective.cash_drawer_id) > 0) {
        params.cash_drawer_id = Number(context.effective.cash_drawer_id);
      } else if (!params.cash_drawer_id) {
        delete params.cash_drawer_id;
      }

      config.params = params;
      withMeta(config, {
        skipErrorRedirect: true,
        prodexCashRegisterContext: true,
        inventoryLocationId: Number(context.effective.inventory_location_id),
      });
      return config;
    }

    const location = locationForConfig(context, config);
    if (!location) return config;

    if (path === 'pos/get_products_pos') {
      config.url = `pos/location-inventory/${location.id}/catalog`;
      config.params = Object.assign({}, config.params || {});
      delete config.params.warehouse_id;
      withMeta(config, { prodexLocationCatalog: true, inventoryLocationId: Number(location.id) });
      return config;
    }

    if (path === 'pos/get_products_pos_changes') {
      config.url = `pos/location-inventory/${location.id}/changes`;
      config.params = Object.assign({}, config.params || {});
      delete config.params.warehouse_id;
      withMeta(config, { prodexLocationDelta: true, inventoryLocationId: Number(location.id) });
      return config;
    }

    if (path === 'pos/create_pos') {
      const data = requestDataObject(config);
      const branch = branchForLocation(context, location);
      const compatibilityId = compatibilityWarehouseId(context, location);
      data.branch_id = Number(location.branch_id || (branch && branch.id) || context.effective.branch_id);
      data.inventory_location_id = Number(location.id);
      // Keep a real legacy warehouse pointer when the branch has one. When it
      // does not, the location id is only a temporary request compatibility
      // value; PosLocationStockBridge/Sale normalize it before persistence.
      data.warehouse_id = compatibilityId || Number(location.id);
      if (!context.effective.can_override && context.effective.cash_drawer_id) {
        data.cash_drawer_id = Number(context.effective.cash_drawer_id);
      }
      config.data = data;
      withMeta(config, {
        prodexLocationSale: true,
        inventoryLocationId: Number(location.id),
        prodexLocationSaleDetails: Array.isArray(data.details)
          ? data.details.map(row => ({
              product_id: Number(row && row.product_id),
              product_variant_id: Number((row && row.product_variant_id) || 0),
            }))
          : [],
      });
      return config;
    }

    const batch = parseBatchPath(path);
    if (batch) {
      const batchLocation = findLocation(context, batch.locationId) || location;
      config.url = `pos/location-inventory/${batchLocation.id}/products/${batch.productId}`;
      config.params = Object.assign({}, config.params || {});
      if (batch.variantId > 0) config.params.product_variant_id = batch.variantId;
      withMeta(config, { prodexLocationProductInventory: true, inventoryLocationId: Number(batchLocation.id) });
      return config;
    }

    if (path === 'serial_numbers/available') {
      const params = Object.assign({}, config.params || {});
      const serialLocation = findLocation(context, params.warehouse_id) || location;
      if (!serialLocation || !params.product_id) return config;
      config.url = `pos/location-inventory/${serialLocation.id}/products/${params.product_id}`;
      config.params = {};
      if (params.product_variant_id) config.params.product_variant_id = params.product_variant_id;
      withMeta(config, { prodexLocationProductInventory: true, inventoryLocationId: Number(serialLocation.id) });
      return config;
    }

    return config;
  });

  axios.interceptors.response.use(async response => {
    const path = normalizePath(response && response.config && response.config.url);

    if (path === 'pos/data_create_pos') {
      const context = await loadContext(axios);
      if (context) decorateBootstrap(response.data, context);
      return response;
    }

    if (response && response.config && response.config.meta && response.config.meta.prodexLocationDelta) {
      return transformLocationDelta(response);
    }

    if (response && response.config && response.config.meta && response.config.meta.prodexLocationSale) {
      return refreshSaleLocationStock(axios, response);
    }

    return response;
  });
}
