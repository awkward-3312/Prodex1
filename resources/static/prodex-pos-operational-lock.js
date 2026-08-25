(function () {
  'use strict';

  if (window.__prodexPosOperationalLockInstalled) return;
  window.__prodexPosOperationalLockInstalled = true;

  var state = {
    context: null,
    loading: false
  };

  function api() {
    return window.axios || null;
  }

  function positiveId(value) {
    var n = Number(value);
    return Number.isFinite(n) && n > 0 ? n : null;
  }

  function effectiveContext(payload) {
    if (!payload || !payload.effective) return null;
    var branchId = positiveId(payload.effective.branch_id);
    var locationId = positiveId(payload.effective.inventory_location_id);
    if (!branchId || !locationId) return null;
    return {
      branch_id: branchId,
      inventory_location_id: locationId,
      can_override: !!payload.effective.can_override
    };
  }

  function findById(rows, id) {
    if (!Array.isArray(rows)) return null;
    return rows.find(function (row) { return Number(row.id) === Number(id); }) || null;
  }

  function assignedLabel(payload, effective) {
    var branch = findById(payload.branches, effective.branch_id);
    var location = findById(payload.inventory_locations, effective.inventory_location_id);
    var parts = [];
    if (branch && branch.name) parts.push(branch.name);
    if (location && location.name) parts.push(location.name);
    return parts.join(' · ') || 'Ubicación asignada';
  }

  function setText(element, value) {
    if (element && element.textContent !== value) element.textContent = value;
  }

  function setAttribute(element, name, value) {
    if (element && element.getAttribute(name) !== value) element.setAttribute(name, value);
  }

  function closeLegacyDrawer() {
    document.querySelectorAll('.wh-drawer-backdrop').forEach(function (drawer) {
      if (drawer.style.getPropertyValue('display') !== 'none') {
        drawer.style.setProperty('display', 'none', 'important');
      }
      setAttribute(drawer, 'aria-hidden', 'true');
    });
  }

  function apply() {
    var payload = state.context;
    var effective = effectiveContext(payload);
    if (!effective) return;

    var locked = !effective.can_override;
    var root = document.documentElement;
    if (root) {
      if (locked && !root.classList.contains('prodex-pos-assigned-location-locked')) {
        root.classList.add('prodex-pos-assigned-location-locked');
      } else if (!locked && root.classList.contains('prodex-pos-assigned-location-locked')) {
        root.classList.remove('prodex-pos-assigned-location-locked');
      }
    }

    var locationLabel = assignedLabel(payload, effective);
    document.querySelectorAll('.pos-wh-trigger').forEach(function (trigger) {
      var eyebrow = trigger.querySelector('.pos-wh-trigger-eyebrow');
      var label = trigger.querySelector('.pos-wh-trigger-label');
      var caret = trigger.querySelector('.pos-wh-trigger-caret');

      setText(eyebrow, 'Ubicación');
      setText(label, locationLabel);

      if (locked) {
        setAttribute(trigger, 'title', 'Ubicación operativa asignada');
        setAttribute(trigger, 'aria-disabled', 'true');
        setAttribute(trigger, 'tabindex', '-1');
        if (trigger.style.getPropertyValue('cursor') !== 'default') {
          trigger.style.setProperty('cursor', 'default', 'important');
        }
        if (caret && caret.style.getPropertyValue('display') !== 'none') {
          caret.style.setProperty('display', 'none', 'important');
        }
      }
    });

    if (locked) closeLegacyDrawer();
  }

  function load() {
    var axios = api();
    if (!axios || state.loading) return;
    state.loading = true;

    axios.get('pos/operational-context', {
      meta: {
        skipInitialLoader: true,
        skipErrorRedirect: true,
        prodexPosOperationalLock: true
      }
    }).then(function (response) {
      state.context = response && response.data ? response.data : null;
      apply();
    }).catch(function () {
      state.context = null;
    }).finally(function () {
      state.loading = false;
    });
  }

  function blockLockedTriggerActivation(event) {
    var trigger = event.target && event.target.closest ? event.target.closest('.pos-wh-trigger') : null;
    if (!trigger) return false;

    var effective = effectiveContext(state.context);
    if (!effective || effective.can_override) return false;

    event.preventDefault();
    event.stopPropagation();
    if (event.stopImmediatePropagation) event.stopImmediatePropagation();
    closeLegacyDrawer();
    apply();
    return true;
  }

  document.addEventListener('click', function (event) {
    blockLockedTriggerActivation(event);
  }, true);

  document.addEventListener('keydown', function (event) {
    var key = event.key;
    if (key !== 'Enter' && key !== ' ' && key !== 'Spacebar') return;
    blockLockedTriggerActivation(event);
  }, true);

  function boot() {
    load();
    setInterval(function () {
      if (/\/app\/pos(?:$|[/?#])/i.test(window.location.href)) {
        if (!state.context) load();
        apply();
      }
    }, 1500);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
