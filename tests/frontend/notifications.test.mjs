import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createNotifications } from '../../resources/src/platform/notifications.js';

test('notify reenvía mensaje y opciones tal cual al driver (equivale a $bvToast.toast)', () => {
  const n = createNotifications();
  const calls = [];
  n.setDriver((m, o) => calls.push([m, o]));
  const options = { title: 'Aviso', variant: 'success', solid: true };
  n.notify('Guardado', options);
  assert.deepEqual(calls, [['Guardado', options]]);
  assert.equal(calls[0][1], options);
});

test('atajos: success/error/warning/info usan el variant de BootstrapVue y solid por defecto', () => {
  const n = createNotifications();
  const calls = [];
  n.setDriver((m, o) => calls.push([m, o]));
  n.success('a');
  n.error('b', { title: 'T' });
  n.warning('c');
  n.info('d');
  assert.deepEqual(calls, [
    ['a', { solid: true, variant: 'success' }],
    ['b', { solid: true, title: 'T', variant: 'danger' }],
    ['c', { solid: true, variant: 'warning' }],
    ['d', { solid: true, variant: 'info' }],
  ]);
});

test('el variant del atajo no se puede pisar desde las opciones; solid sí', () => {
  const n = createNotifications();
  const calls = [];
  n.setDriver((m, o) => calls.push(o));
  n.error('x', { variant: 'success', solid: false });
  assert.deepEqual(calls[0], { solid: false, variant: 'danger' });
});

test('antes de instalar el driver las notificaciones se conservan y salen en orden al instalarlo', () => {
  const n = createNotifications();
  n.notify('1', { variant: 'info' });
  n.success('2');
  assert.equal(n.pendingCount(), 2);
  const calls = [];
  n.setDriver((m) => calls.push(m));
  assert.deepEqual(calls, ['1', '2']);
  assert.equal(n.pendingCount(), 0);
});

test('la cola pendiente está acotada (se descartan las más antiguas)', () => {
  const n = createNotifications();
  for (let i = 0; i < 60; i += 1) n.notify(`m${i}`);
  assert.equal(n.pendingCount(), 50);
  const seen = [];
  n.setDriver((m) => seen.push(m));
  assert.equal(seen[0], 'm10');
  assert.equal(seen.at(-1), 'm59');
});

test('setDriver devuelve una función que lo retira, y retirar uno viejo no quita al nuevo', () => {
  const n = createNotifications();
  const seen = [];
  const removeA = n.setDriver(() => seen.push('a'));
  n.setDriver(() => seen.push('b'));
  removeA(); // ya no es el driver activo: no debe quitar 'b'
  n.notify('x');
  assert.deepEqual(seen, ['b']);
  assert.equal(n.hasDriver(), true);
});

test('el driver que lanza un error propaga al llamador (igual que $bvToast)', () => {
  const n = createNotifications();
  n.setDriver(() => { throw new Error('driver'); });
  assert.throws(() => n.notify('x'), /driver/);
});
