import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createConfirmService, normalizeConfirmOptions } from '../../resources/src/platform/confirm.js';

test('normaliza los nombres legacy de SweetAlert2 y conserva el resto como opciones nativas', () => {
  const o = normalizeConfirmOptions({
    title: 'Borrar',
    text: 'Seguro?',
    type: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#0CC652',
    cancelButtonColor: '#d33',
    confirmButtonText: 'Sí',
    cancelButtonText: 'No',
  });
  assert.deepEqual(o, {
    presentation: 'swal',
    title: 'Borrar',
    message: 'Seguro?',
    confirmText: 'Sí',
    cancelText: 'No',
    variant: undefined,
    native: { type: 'warning', showCancelButton: true, confirmButtonColor: '#0CC652', cancelButtonColor: '#d33' },
  });
});

test('los nombres nuevos tienen prioridad sobre los legacy y `native` se fusiona', () => {
  const o = normalizeConfirmOptions({ message: 'nuevo', text: 'viejo', confirmText: 'OK', confirmButtonText: 'viejo', icon: 'warning', native: { icon: 'error', extra: 1 } });
  assert.equal(o.message, 'nuevo');
  assert.equal(o.confirmText, 'OK');
  assert.deepEqual(o.native, { icon: 'error', extra: 1 });
});

test('un string es el mensaje y acepta opciones como segundo argumento (forma de msgBoxConfirm)', () => {
  const o = normalizeConfirmOptions('¿Seguro?', { size: 'sm', presentation: 'modal' });
  assert.equal(o.message, '¿Seguro?');
  assert.equal(o.presentation, 'modal');
  assert.deepEqual(o.native, { size: 'sm' });
});

test('confirm() resuelve true solo cuando el driver confirma', async () => {
  const confirm = createConfirmService();
  confirm.setDriver('swal', async () => true);
  assert.equal(await confirm({ title: 'x' }), true);
  confirm.setDriver('swal', async () => false);
  assert.equal(await confirm({ title: 'x' }), false);
  confirm.setDriver('swal', async () => null); // p. ej. msgBoxConfirm cerrado con la X
  assert.equal(await confirm({ title: 'x' }), false);
  confirm.setDriver('swal', async () => undefined);
  assert.equal(await confirm({ title: 'x' }), false);
});

test('la presentación elige el driver y por defecto es swal', async () => {
  const confirm = createConfirmService();
  const seen = [];
  confirm.setDriver('swal', async (o) => { seen.push(['swal', o.message]); return true; });
  confirm.setDriver('modal', async (o) => { seen.push(['modal', o.message]); return true; });
  await confirm('a');
  await confirm('b', { presentation: 'modal' });
  await confirm({ message: 'c', presentation: 'swal' });
  assert.deepEqual(seen, [['swal', 'a'], ['modal', 'b'], ['swal', 'c']]);
});

test('sin driver para la presentación, confirm() rechaza con un error claro', async () => {
  const confirm = createConfirmService();
  await assert.rejects(() => confirm({ title: 'x' }), /swal/);
  confirm.setDriver('swal', async () => true);
  await assert.rejects(() => confirm({ presentation: 'modal' }), /modal/);
});

test('el driver recibe las opciones normalizadas; un error del driver se propaga', async () => {
  const confirm = createConfirmService();
  let received;
  confirm.setDriver('swal', async (o) => { received = o; return true; });
  await confirm({ title: 'T', text: 'M', cancelButtonText: 'C' });
  assert.equal(received.title, 'T');
  assert.equal(received.message, 'M');
  assert.equal(received.cancelText, 'C');
  confirm.setDriver('swal', async () => { throw new Error('fallo'); });
  await assert.rejects(() => confirm('x'), /fallo/);
});

test('setDriver devuelve la función que lo retira', async () => {
  const confirm = createConfirmService();
  const remove = confirm.setDriver('swal', async () => true);
  assert.equal(confirm.hasDriver('swal'), true);
  remove();
  assert.equal(confirm.hasDriver('swal'), false);
});
