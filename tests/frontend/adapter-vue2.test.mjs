import { test } from 'node:test';
import assert from 'node:assert/strict';
import { confirm } from '../../resources/src/platform/confirm.js';
import { installSweetAlertConfirm } from '../../resources/src/platform/adapters/vue2.js';
import { toastProps, confirmModalProps, okOf } from '../../resources/src/platform/adapters/bvn.js';
import { normalizeConfirmOptions } from '../../resources/src/platform/confirm.js';

// Adaptadores de plataforma. El driver de BootstrapVueNext necesita DOM (se prueba en el E2E 24); aquí se prueban sus funciones de mapeo (puras)
// y el driver de SweetAlert2 con una raíz falsa.

test('toast: opciones de $bvToast.toast → props de BToast (título, variante, solid, autoHideDelay, posición)', () => {
  assert.deepEqual(toastProps('Hola', { title: 'T', variant: 'success', solid: true }), { body: 'Hola', noProgress: true, modelValue: 5000, title: 'T', variant: 'success', solid: true });
  assert.equal(toastProps('x', { autoHideDelay: 1200 }).modelValue, 1200);
  assert.equal(toastProps('x', { noAutoHide: true }).modelValue, true);
  assert.equal(toastProps('x', { toaster: 'b-toaster-bottom-left' }).position, 'bottom-start');
  assert.equal(toastProps('x', { toaster: 'b-toaster-top-right' }).position, 'top-end');
  assert.equal('position' in toastProps('x', {}), false, 'sin `toaster` BVN usa top-end (como b-toaster-top-right)');
  assert.equal('foo' in toastProps('x', { foo: 1 }), false, 'opciones desconocidas no llegan al componente');
});

test('confirm modal: opciones normalizadas de confirm() → props de BModal y resultado true/false/null', () => {
  const opts = normalizeConfirmOptions('¿Seguro?', { presentation: 'modal', title: 'Confirmar', confirmText: 'Sí', cancelText: 'No', variant: 'danger', size: 'sm', centered: true, footerClass: 'p-2', okTitle: 'Yes' });
  const props = confirmModalProps(opts);
  assert.deepEqual({ body: props.body, title: props.title, size: props.size, centered: props.centered, footerClass: props.footerClass, okVariant: props.okVariant, cancelTitle: props.cancelTitle }, { body: '¿Seguro?', title: 'Confirmar', size: 'sm', centered: true, footerClass: 'p-2', okVariant: 'danger', cancelTitle: 'No' });
  assert.equal(props.okTitle, 'Sí', 'confirmText gana a okTitle nativo');
  assert.equal(okOf({ ok: true }), true);
  assert.equal(okOf({ ok: false }), false);
  assert.equal(okOf({ ok: null }), null);
  assert.equal(okOf(undefined), null);
});

test('confirm swal: reproduce las opciones de una llamada $swal legacy y resuelve boolean', async () => {
  const calls = [];
  const root = { $swal: async (o) => { calls.push(o); return { isConfirmed: true, value: true }; } };
  const uninstall = installSweetAlertConfirm(root);
  assert.equal(await confirm('¿Eliminar?', { title: 'Eliminar', confirmText: 'Sí', cancelText: 'No', type: 'warning' }), true);
  assert.deepEqual(calls[0], { showCancelButton: true, title: 'Eliminar', text: '¿Eliminar?', confirmButtonText: 'Sí', cancelButtonText: 'No', type: 'warning' });
  uninstall();
  assert.equal(confirm.hasDriver('swal'), false);
});

test('confirm swal: cancelar o cerrar resuelve false; acepta el resultado antiguo con value', async () => {
  for (const [result, expected] of [[{ isConfirmed: false }, false], [undefined, false], [{ value: true }, true], [{ value: false }, false]]) {
    const uninstall = installSweetAlertConfirm({ $swal: async () => result });
    assert.equal(await confirm('x'), expected);
    uninstall();
  }
});
