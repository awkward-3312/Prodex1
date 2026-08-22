(function () {
  'use strict';

  if (window.__prodexTransferLocationUiInstalled) return;
  window.__prodexTransferLocationUiInstalled = true;

  var state = { options: null, loading: null };

  function api() { return window.axios || null; }
  function parse(url) {
    try { return new URL(String(url || ''), window.location.origin + '/api/'); }
    catch (e) { return null; }
  }
  function dataObject(config) {
    if (!config) return null;
    if (config.data && typeof config.data === 'object') return config.data;
    if (typeof config.data === 'string') {
      try { var obj = JSON.parse(config.data); config.data = obj; return obj; } catch (e) {}
    }
    return null;
  }
  function loadOptions(force) {
    if (!force && state.options) return Promise.resolve(state.options);
    if (!force && state.loading) return state.loading;
    if (!api()) return Promise.resolve(null);
    state.loading = api().get('transfer-location/options', {
      meta: { skipInitialLoader: true, skipErrorRedirect: true, prodexTransferLocation: true }
    }).then(function (r) {
      state.options = r && r.data ? r.data : null;
      return state.options;
    }).catch(function () { return null; }).finally(function () { state.loading = null; });
    return state.loading;
  }
  function source(id) {
    return state.options && Array.isArray(state.options.sources)
      ? state.options.sources.find(function (x) { return String(x.id) === String(id); }) : null;
  }
  function destination(id) {
    return state.options && Array.isArray(state.options.destinations)
      ? state.options.destinations.find(function (x) { return String(x.id) === String(id); }) : null;
  }

  function isCreateFormGet(config) {
    var u = parse(config && config.url);
    return !!(u && /\/transfers\/create$/i.test(u.pathname) && String(config.method || 'get').toLowerCase() === 'get');
  }
  function editMatch(config) {
    var u = parse(config && config.url);
    if (!u) return null;
    return u.pathname.match(/\/transfers\/(\d+)\/edit$/i);
  }
  function productListMatch(config) {
    var u = parse(config && config.url);
    return u ? u.pathname.match(/\/get_Products_by_warehouse\/(\d+)$/i) : null;
  }
  function productDetailMatch(config) {
    var u = parse(config && config.url);
    return u ? u.pathname.match(/\/show_product_data\/(\d+)\/([^/]+)\/(\d+)$/i) : null;
  }
  function batchMatch(config) {
    var u = parse(config && config.url);
    return u ? u.pathname.match(/\/batches_for_transfer\/(\d+)\/(\d+)\/(\d+)$/i) : null;
  }
  function transferMutation(config) {
    var u = parse(config && config.url);
    var method = String(config && config.method || 'get').toLowerCase();
    if (!u || !['post', 'put', 'patch'].includes(method)) return false;
    return /\/transfers(?:\/\d+)?$/i.test(u.pathname);
  }

  function rewriteMutation(config) {
    var payload = dataObject(config);
    if (!payload || !payload.transfer) return config;
    var t = payload.transfer;
    var from = source(t.from_warehouse);
    var to = destination(t.to_warehouse);
    if (!from || !to) return config;

    t.from_inventory_location_id = Number(from.id);
    t.to_inventory_location_id = Number(to.id);
    t.from_warehouse = Number(from.legacy_warehouse_id);
    t.to_warehouse = Number(to.legacy_warehouse_id);
    config.__prodexTransferLocationPayload = {
      from_inventory_location_id: Number(from.id),
      to_inventory_location_id: Number(to.id)
    };
    return config;
  }

  function installAxios() {
    var axios = api();
    if (!axios || axios.__prodexTransferLocationInstalled) return;
    axios.__prodexTransferLocationInstalled = true;

    axios.interceptors.request.use(function (config) {
      if (!config || (config.meta && config.meta.prodexTransferLocation)) return config;

      var list = productListMatch(config);
      var detail = productDetailMatch(config);
      var batch = batchMatch(config);

      if (list) {
        config.url = 'transfer-location/' + list[1] + '/products';
        config.meta = Object.assign({}, config.meta || {}, { prodexTransferLocation: true, skipErrorRedirect: true });
        return config;
      }
      if (detail) {
        var variant = detail[2] && !['null', 'undefined', ''].includes(String(detail[2])) ? Number(detail[2]) : 0;
        config.url = 'transfer-location/' + detail[3] + '/products/' + detail[1] + (variant > 0 ? '?product_variant_id=' + variant : '');
        config.meta = Object.assign({}, config.meta || {}, { prodexTransferLocation: true, skipErrorRedirect: true });
        return config;
      }
      if (batch) {
        config.url = 'transfer-location/' + batch[2] + '/batches/' + batch[1] + '/' + batch[3];
        config.meta = Object.assign({}, config.meta || {}, { prodexTransferLocation: true, skipErrorRedirect: true });
        return config;
      }

      if (transferMutation(config)) {
        return loadOptions(false).then(function () { return rewriteMutation(config); });
      }

      if (isCreateFormGet(config) || editMatch(config)) {
        config.__prodexTransferLocationForm = true;
      }
      return config;
    });

    axios.interceptors.response.use(function (response) {
      var config = response && response.config;
      if (!config || !config.__prodexTransferLocationForm) return response;

      return loadOptions(false).then(function (options) {
        if (!options || !response.data) return response;

        var edit = editMatch(config);
        if (!edit) {
          response.data.warehouses = options.sources || [];
          response.data.to_warehouses = options.destinations || [];
          response.data.inventory_location_mode = true;
          return response;
        }

        return api().get('transfer-location/transfers/' + edit[1] + '/context', {
          meta: { skipInitialLoader: true, skipErrorRedirect: true, prodexTransferLocation: true }
        }).then(function (ctxResponse) {
          var ctx = ctxResponse && ctxResponse.data ? ctxResponse.data : null;
          if (ctx && ctx.location_aware && response.data.transfer) {
            response.data.transfer.from_warehouse = ctx.from.id;
            response.data.transfer.to_warehouse = ctx.to.id;
            response.data.warehouses = options.sources || [];
            response.data.to_warehouses = options.destinations || [];
            response.data.inventory_location_mode = true;
          }
          return response;
        }).catch(function () { return response; });
      });
    }, function (error) { return Promise.reject(error); });
  }

  function normalized(value) {
    return String(value || '').trim().toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  }

  function directionForText(value) {
    var text = normalized(value);
    if (!text) return null;
    var hasWarehouse = text.includes('warehouse') || text.includes('almacen') || text.includes('bodega');
    if (!hasWarehouse) return null;
    if (text.includes('from') || text.includes('origen')) return 'from';
    if (text.includes('to warehouse') || text.includes('destino')) return 'to';
    return null;
  }

  function replaceDirectionalText(root, fromText, toText) {
    if (!root || !document.createTreeWalker) return;
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
    var node;
    while ((node = walker.nextNode())) {
      var direction = directionForText(node.nodeValue);
      if (direction === 'from') node.nodeValue = fromText;
      if (direction === 'to') node.nodeValue = toText;
    }
  }

  function relabel() {
    var path = window.location.pathname || '';
    var isForm = /\/app\/transfers\/(store|edit)/i.test(path);
    var isIndex = /\/app\/transfers\/?$/i.test(path);
    if (!isForm && !isIndex) return;

    if (isForm) {
      document.querySelectorAll('.form-group').forEach(function (group) {
        var label = group.querySelector('label');
        if (!label) return;
        var direction = directionForText(label.textContent);
        if (direction === 'from') label.textContent = 'Ubicación de inventario de origen *';
        if (direction === 'to') label.textContent = 'Ubicación de inventario de destino *';
      });
      return;
    }

    document.querySelectorAll('.form-group label').forEach(function (label) {
      var direction = directionForText(label.textContent);
      if (direction === 'from') label.textContent = 'Origen';
      if (direction === 'to') label.textContent = 'Destino';
    });

    document.querySelectorAll('.vgt-table thead th').forEach(function (header) {
      replaceDirectionalText(header, 'Origen', 'Destino');
    });

    document.querySelectorAll('input[placeholder]').forEach(function (input) {
      var placeholder = normalized(input.getAttribute('placeholder'));
      if (placeholder.includes('warehouse') || placeholder.includes('almacen') || placeholder.includes('bodega')) {
        input.setAttribute('placeholder', 'Selecciona una ubicación');
      }
    });
  }

  function boot() {
    installAxios();
    loadOptions(false);
    var observer = new MutationObserver(relabel);
    try { observer.observe(document.documentElement, { childList: true, subtree: true }); } catch (e) {}
    relabel();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
