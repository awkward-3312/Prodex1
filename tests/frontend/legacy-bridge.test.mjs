import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createLegacyBridge, installLegacyBridge } from '../../resources/src/platform/legacy-bridge.js';

test('navigate delega en el router y devuelve una promesa que nunca rechaza', async () => {
  const seen = [];
  const bridge = createLegacyBridge({ navigate: (p) => { seen.push(p); return Promise.reject(new Error('duplicada')); } });
  await bridge.navigate('/app/inventory/missing'); // no debe lanzar
  assert.deepEqual(seen, ['/app/inventory/missing']);
});

test('navigate no rompe si el router lanza síncronamente o no existe', async () => {
  await createLegacyBridge({ navigate: () => { throw new Error('x'); } }).navigate('/a');
  await createLegacyBridge().navigate('/a');
});

test('getPermissions devuelve siempre un array (vacío si aún no cargaron)', () => {
  assert.deepEqual(createLegacyBridge({ getPermissions: () => ['a', 'b'] }).getPermissions(), ['a', 'b']);
  assert.deepEqual(createLegacyBridge({ getPermissions: () => null }).getPermissions(), []);
  assert.deepEqual(createLegacyBridge({ getPermissions: () => 'nope' }).getPermissions(), []);
  assert.deepEqual(createLegacyBridge().getPermissions(), []);
});

test('planFeature aplica la misma regla que el menú: sin plan o sin clave => habilitada', () => {
  const summary = { has_plan: true, features: { transfers: { enabled: false }, pos: { enabled: true } } };
  const bridge = createLegacyBridge({ getPlanSummary: () => summary });
  assert.equal(bridge.planFeature('transfers'), false);
  assert.equal(bridge.planFeature('pos'), true);
  assert.equal(bridge.planFeature('desconocida'), true);
  assert.equal(createLegacyBridge({ getPlanSummary: () => ({ has_plan: false, features: { x: { enabled: false } } }) }).planFeature('x'), true);
  assert.equal(createLegacyBridge().planFeature('x'), true);
});

test('el editor de permisos se registra y se retira explícitamente', () => {
  const bridge = createLegacyBridge();
  assert.equal(bridge.permissionEditor(), null);
  const editor = { get: () => [], set() {} };
  const remove = bridge.registerPermissionEditor(editor);
  assert.equal(bridge.permissionEditor(), editor);
  bridge.registerPermissionEditor({ get: () => [], set() {} });
  remove(); // ya no es el activo: no debe quitar al nuevo
  assert.notEqual(bridge.permissionEditor(), null);
});

test('installLegacyBridge publica window.__prodexBridge y lo retira', () => {
  const fakeWindow = {};
  const uninstall = installLegacyBridge({ getPermissions: () => ['x'] }, fakeWindow);
  assert.deepEqual(fakeWindow.__prodexBridge.getPermissions(), ['x']);
  uninstall();
  assert.equal('__prodexBridge' in fakeWindow, false);
});
