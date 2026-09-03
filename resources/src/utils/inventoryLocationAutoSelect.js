/**
 * MS5-D.1 — inventory_location_id auto-selection policy ("rule D2").
 *
 * Shared by the five purchase-family forms that must resolve an
 * inventory_location_id for a location_primary warehouse:
 *   - views/app/pages/purchases/create_purchase.vue
 *   - views/app/pages/purchases/edit_purchase.vue
 *   - views/app/pages/purchases/import_purchases.vue
 *   - views/app/pages/purchase_return/create_purchase_return.vue
 *   - views/app/pages/purchase_return/edit_purchase_return.vue
 *
 * D2 — a QUARANTINE location is never selected automatically unless an
 * explicit decision authorises it. Precedence (first match wins):
 *
 *   1. persisted document value (edit) that is still valid
 *        -> keep it, even if quarantine. This is stored document state,
 *           not an auto-selection.
 *   2. explicit warehouse default that is valid
 *        -> select it, even if quarantine. The configured default IS the
 *           explicit decision.
 *   3. linked-purchase suggestion (purchase-return create) that is valid
 *      and NOT quarantine
 *        -> select it.
 *   4. the sole usable location, only when it is NOT quarantine
 *        -> select it.
 *   5. otherwise
 *        -> null. The user must choose consciously.
 *
 * "quarantine" is read ONLY from the backend `is_quarantine` flag on each
 * location row (see GuardsPurchaseTransitionMode::inventoryLocationContextPayload).
 * It is never inferred from name / code / label / type / position.
 *
 * `default_inventory_location_id` in the endpoint payload is already
 * backend-validated as eligible (active, in scope, belongs to the
 * warehouse), so "present in `locations`" is the frontend validity test
 * for every candidate id.
 *
 * @param {Object} options
 * @param {Array<{id:(number|string), is_quarantine?:boolean}>} options.locations
 *        The `locations` array from the *_inventory_locations endpoint.
 * @param {(number|string|null)} [options.defaultLocationId]
 *        `default_inventory_location_id` from the same payload.
 * @param {(number|string|null)} [options.persistedLocationId]
 *        EDIT only — the inventory_location_id already stored on the document.
 * @param {(number|string|null)} [options.linkedLocationId]
 *        PURCHASE-RETURN CREATE only — the linked purchase's location, offered
 *        as a suggestion (never as an authorisation to pick quarantine).
 * @returns {(number|null)} the id to auto-select, or null when none is safe.
 */
function resolveAutoInventoryLocation(options) {
  var opts = options || {};
  var locations = Array.isArray(opts.locations) ? opts.locations : [];

  var toId = function (value) {
    if (value === null || value === undefined || value === '') return null;
    var n = Number(value);
    return Number.isFinite(n) ? n : null;
  };

  var byId = {};
  locations.forEach(function (l) {
    if (l && l.id !== null && l.id !== undefined) {
      byId[Number(l.id)] = l;
    }
  });

  var exists = function (id) {
    return id !== null && Object.prototype.hasOwnProperty.call(byId, id);
  };
  var isQuarantine = function (id) {
    return !!(byId[id] && byId[id].is_quarantine);
  };

  var persistedId = toId(opts.persistedLocationId);
  var defaultId = toId(opts.defaultLocationId);
  var linkedId = toId(opts.linkedLocationId);

  // 1. Persisted document value — stored state wins, quarantine included.
  if (exists(persistedId)) {
    return persistedId;
  }

  // 2. Explicit warehouse default — the configured default authorises
  //    quarantine.
  if (exists(defaultId)) {
    return defaultId;
  }

  // 3. Linked-purchase suggestion — only when it is NOT quarantine.
  if (exists(linkedId) && !isQuarantine(linkedId)) {
    return linkedId;
  }

  // 4. Sole usable location — only when it is NOT quarantine. Being "the
  //    only location" is never, by itself, authorisation for quarantine.
  var ids = Object.keys(byId).map(Number);
  if (ids.length === 1 && !isQuarantine(ids[0])) {
    return ids[0];
  }

  // 5. No safe automatic selection — leave the field for the user.
  return null;
}

module.exports = { resolveAutoInventoryLocation };
