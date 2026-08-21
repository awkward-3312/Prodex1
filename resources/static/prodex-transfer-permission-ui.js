(function () {
  'use strict';

  if (window.__prodexTransferPermissionUiInstalled) return;
  window.__prodexTransferPermissionUiInstalled = true;

  var PERMISSION = 'transfer_receive';
  var ID = 'prodex-transfer-receive-permission';

  function nearestVueVm(el) {
    var node = el;
    while (node) {
      if (node.__vue__ && Array.isArray(node.__vue__.permissions)) return node.__vue__;
      node = node.parentElement;
    }

    // Vue 2 component roots are not always attached to the exact form node.
    // Walk visible component roots as a safe fallback and only accept a VM that
    // owns the same permissions array used by the hard-coded permission form.
    var all = document.querySelectorAll('*');
    for (var i = 0; i < all.length; i++) {
      if (all[i].__vue__ && Array.isArray(all[i].__vue__.permissions)) return all[i].__vue__;
    }
    return null;
  }

  function install() {
    if (document.getElementById(ID)) return;

    var anchor = document.querySelector('input[value="transfer_edit"]');
    if (!anchor) return;

    var row = anchor.closest('.row');
    if (!row) return;

    var vm = nearestVueVm(anchor);
    if (!vm) return;

    var col = document.createElement('div');
    col.className = 'col-md-12';
    col.id = ID;
    col.innerHTML = [
      '<label class="checkbox checkbox-outline-primary" style="margin-top:6px">',
      '<input type="checkbox" value="' + PERMISSION + '">',
      '<span>Recibir transferencias de stock <small style="display:block;color:#7b8495;font-weight:400;margin-top:2px">Permite confirmar físicamente recepciones destinadas a las bodegas asignadas al usuario.</small></span>',
      '<span class="checkmark"></span>',
      '</label>'
    ].join('');

    var input = col.querySelector('input');
    input.checked = vm.permissions.indexOf(PERMISSION) !== -1;
    input.addEventListener('change', function () {
      var next = vm.permissions.slice();
      var index = next.indexOf(PERMISSION);
      if (input.checked && index === -1) next.push(PERMISSION);
      if (!input.checked && index !== -1) next.splice(index, 1);
      vm.permissions = next;
    });

    row.appendChild(col);

    // Keep the DOM checkbox synchronized if an edit form loads its role data after
    // this enhancer has already mounted.
    var ticks = 0;
    var timer = window.setInterval(function () {
      ticks++;
      if (!document.body.contains(col) || ticks > 40) {
        window.clearInterval(timer);
        return;
      }
      input.checked = vm.permissions.indexOf(PERMISSION) !== -1;
    }, 250);
  }

  function schedule() {
    [0, 120, 350, 900, 1800].forEach(function (delay) {
      window.setTimeout(install, delay);
    });
  }

  document.addEventListener('DOMContentLoaded', schedule, { once: true });
  window.addEventListener('load', schedule, { once: true });

  var observer = new MutationObserver(function () { schedule(); });
  if (document.documentElement) observer.observe(document.documentElement, { childList: true, subtree: true });
  schedule();
})();
