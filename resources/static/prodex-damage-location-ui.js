(function () {
  'use strict';

  if (window.__prodexDamageLocationUiInstalled) return;
  window.__prodexDamageLocationUiInstalled = true;

  var cache = {};

  function transferIdFromRef(ref) {
    var match = String(ref || '').match(/^TR-DMG-(\d+)-/i);
    return match ? Number(match[1]) : null;
  }

  function getLabel(transferId) {
    if (!transferId || !window.axios) return Promise.resolve(null);
    if (cache[transferId]) return cache[transferId];

    cache[transferId] = window.axios.get('/api/transfer-logistics/damage-location/' + transferId, {
      meta: { skipErrorRedirect: true, skipInitialLoader: true, prodexDamageLocationLookup: true }
    }).then(function (response) {
      return response && response.data ? response.data.label || null : null;
    }).catch(function () {
      return null;
    });

    return cache[transferId];
  }

  function enhanceDamageRows(response) {
    var rows = response && response.data && Array.isArray(response.data.damages)
      ? response.data.damages
      : null;
    if (!rows || !rows.length) return Promise.resolve(response);

    var jobs = rows.map(function (row) {
      var transferId = transferIdFromRef(row && row.Ref);
      if (!transferId) return Promise.resolve();
      return getLabel(transferId).then(function (label) {
        if (!label) return;
        row.warehouse_name = label;
        row.inventory_location_label = label;
        row.is_transfer_damage = true;
      });
    });

    return Promise.all(jobs).then(function () { return response; });
  }

  function enhanceDamageDetail(response) {
    var damage = response && response.data ? response.data.damage : null;
    if (!damage) return Promise.resolve(response);

    var transferId = transferIdFromRef(damage.Ref);
    if (!transferId) return Promise.resolve(response);

    return getLabel(transferId).then(function (label) {
      if (label) {
        damage.warehouse = label;
        damage.warehouse_name = label;
        damage.inventory_location_label = label;
        damage.is_transfer_damage = true;
      }
      return response;
    });
  }

  function install() {
    if (!window.axios || !window.axios.interceptors) {
      setTimeout(install, 250);
      return;
    }

    window.axios.interceptors.response.use(function (response) {
      var config = response && response.config ? response.config : {};
      if (config.meta && config.meta.prodexDamageLocationLookup) return response;

      var url = String(config.url || '');
      if (url.indexOf('damages?') !== -1 || /\/damages(?:\?|$)/.test(url)) {
        return enhanceDamageRows(response);
      }
      if (url.indexOf('damages/detail/') !== -1) {
        return enhanceDamageDetail(response);
      }
      return response;
    });
  }

  install();
})();
