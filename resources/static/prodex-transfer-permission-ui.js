(function () {
  'use strict';

  if (window.__prodexTransferPermissionUiInstalled) return;
  window.__prodexTransferPermissionUiInstalled = true;

  var DEFINITIONS = [
    {
      permission: 'transfer_receive',
      id: 'prodex-transfer-receive-permission',
      label: 'Recibir transferencias de stock',
      help: 'Permite confirmar físicamente recepciones destinadas a las bodegas asignadas al usuario.'
    },
    {
      permission: 'transfer_issue_manage',
      id: 'prodex-transfer-issue-permission',
      label: 'Resolver incidencias de transferencias',
      help: 'Permite cerrar faltantes o productos defectuosos con una resolución documentada y trazable.'
    }
  ];

  function nearestVueVm(el) {
    var node = el;
    while (node) {
      if (node.__vue__ && Array.isArray(node.__vue__.permissions)) return node.__vue__;
      node = node.parentElement;
    }

    var all = document.querySelectorAll('*');
    for (var i = 0; i < all.length; i++) {
      if (all[i].__vue__ && Array.isArray(all[i].__vue__.permissions)) return all[i].__vue__;
    }
    return null;
  }

  function installDefinition(definition, row, vm) {
    if (document.getElementById(definition.id)) return;

    var col = document.createElement('div');
    col.className = 'col-md-12';
    col.id = definition.id;
    col.innerHTML = [
      '<label class="checkbox checkbox-outline-primary" style="margin-top:6px">',
      '<input type="checkbox" value="' + definition.permission + '">',
      '<span>' + definition.label + '<small style="display:block;color:#7b8495;font-weight:400;margin-top:2px">' + definition.help + '</small></span>',
      '<span class="checkmark"></span>',
      '</label>'
    ].join('');

    var input = col.querySelector('input');
    input.checked = vm.permissions.indexOf(definition.permission) !== -1;
    input.addEventListener('change', function () {
      var next = vm.permissions.slice();
      var index = next.indexOf(definition.permission);
      if (input.checked && index === -1) next.push(definition.permission);
      if (!input.checked && index !== -1) next.splice(index, 1);
      vm.permissions = next;
    });

    row.appendChild(col);

    var ticks = 0;
    var timer = window.setInterval(function () {
      ticks++;
      if (!document.body.contains(col) || ticks > 40) {
        window.clearInterval(timer);
        return;
      }
      input.checked = vm.permissions.indexOf(definition.permission) !== -1;
    }, 250);
  }

  function install() {
    var anchor = document.querySelector('input[value="transfer_edit"]');
    if (!anchor) return;

    var row = anchor.closest('.row');
    if (!row) return;

    var vm = nearestVueVm(anchor);
    if (!vm) return;

    DEFINITIONS.forEach(function (definition) {
      installDefinition(definition, row, vm);
    });
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
