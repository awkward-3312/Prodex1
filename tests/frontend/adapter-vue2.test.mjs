import { test } from 'node:test';
import assert from 'node:assert/strict';
import { notifications } from '../../resources/src/platform/notifications.js';
import { confirm } from '../../resources/src/platform/confirm.js';
import { modals } from '../../resources/src/platform/modals.js';
import { installVue2Platform } from '../../resources/src/platform/adapters/vue2.js';

// Se prueba el adaptador con un "root" falso que imita la superficie de Vue 2 + BootstrapVue + vue-sweetalert2 usada.
function fakeRoot({ swalResult = { isConfirmed: true, value: true }, msgBox = true } = {}) {
  const log = [];
  return {
    log,
    $bvToast: { toast: (m, o) => log.push(['toast', m, o]) },
    $bvModal: {
      show: (id) => log.push(['show', id]),
      hide: (id) => log.push(['hide', id]),
      msgBoxConfirm: async (m, o) => { log.push(['msgBox', m, o]); return msgBox; },
    },
    $swal: async (o) => { log.push(['swal', o]); return swalResult; },
  };
}

test('instalado, las notificaciones llegan a $bvToast.toast con las mismas opciones', () => {
  const root = fakeRoot();
  const uninstall = installVue2Platform(root);
  notifications.notify('Hola', { title: 'T', variant: 'success', solid: true });
  notifications.error('Mal');
  uninstall();
  assert.deepEqual(root.log, [
    ['toast', 'Hola', { title: 'T', variant: 'success', solid: true }],
    ['toast', 'Mal', { solid: true, variant: 'danger' }],
  ]);
});

test('show/hide llegan a $bvModal', () => {
  const root = fakeRoot();
  const uninstall = installVue2Platform(root);
  modals.show('A');
  modals.hide('A');
  uninstall();
  assert.deepEqual(root.log, [['show', 'A'], ['hide', 'A']]);
});

test('confirm swal: reproduce las opciones de una llamada $swal legacy y resuelve boolean', async () => {
  const root = fakeRoot();
  const uninstall = installVue2Platform(root);
  const ok = await confirm({
    title: 'Borrar',
    text: 'Seguro?',
    type: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#0CC652',
    cancelButtonColor: '#d33',
    cancelButtonText: 'No',
    confirmButtonText: 'Sí',
  });
  uninstall();
  assert.equal(ok, true);
  assert.deepEqual(root.log[0], ['swal', {
    showCancelButton: true,
    title: 'Borrar',
    text: 'Seguro?',
    confirmButtonText: 'Sí',
    cancelButtonText: 'No',
    type: 'warning',
    confirmButtonColor: '#0CC652',
    cancelButtonColor: '#d33',
  }]);
});

test('confirm swal: cancelar o cerrar resuelve false; acepta el resultado antiguo con value', async () => {
  let root = fakeRoot({ swalResult: { isConfirmed: false, isDismissed: true, value: undefined } });
  let uninstall = installVue2Platform(root);
  assert.equal(await confirm({ title: 'x' }), false);
  uninstall();
  root = fakeRoot({ swalResult: { value: true } }); // sin isConfirmed
  uninstall = installVue2Platform(root);
  assert.equal(await confirm({ title: 'x' }), true);
  uninstall();
});

test('confirm modal: usa msgBoxConfirm con mensaje y opciones de BootstrapVue', async () => {
  const root = fakeRoot({ msgBox: null }); // cerrar con la X devuelve null
  const uninstall = installVue2Platform(root);
  const ok = await confirm('¿Seguro?', { presentation: 'modal', size: 'sm', title: 'Título', confirmText: 'Sí', cancelText: 'No', variant: 'danger' });
  uninstall();
  assert.equal(ok, false);
  assert.deepEqual(root.log[0], ['msgBox', '¿Seguro?', { size: 'sm', title: 'Título', okTitle: 'Sí', cancelTitle: 'No', okVariant: 'danger' }]);
});

test('desinstalar retira los drivers', async () => {
  const uninstall = installVue2Platform(fakeRoot());
  uninstall();
  assert.equal(notifications.hasDriver(), false);
  assert.equal(modals.hasDriver(), false);
  assert.equal(confirm.hasDriver('swal'), false);
});
