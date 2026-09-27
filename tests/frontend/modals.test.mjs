import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createModalService } from '../../resources/src/platform/modals.js';

test('show y hide delegan en el driver con el id', () => {
  const modals = createModalService();
  const calls = [];
  modals.setDriver({ show: (id) => calls.push(['show', id]), hide: (id) => calls.push(['hide', id]) });
  modals.show('CloseRegisterModal');
  modals.hide('CloseRegisterModal');
  assert.deepEqual(calls, [['show', 'CloseRegisterModal'], ['hide', 'CloseRegisterModal']]);
});

test('sin driver no lanza (igual que $bvModal antes de montar) y avisa por consola', () => {
  const modals = createModalService();
  const original = console.warn;
  const warned = [];
  console.warn = (...a) => warned.push(a.join(' '));
  try {
    assert.doesNotThrow(() => modals.show('x'));
  } finally {
    console.warn = original;
  }
  assert.equal(warned.length, 1);
  assert.match(warned[0], /show\("x"\)/);
});

test('setDriver devuelve una función que lo retira; uno viejo no quita al nuevo', () => {
  const modals = createModalService();
  const calls = [];
  const removeA = modals.setDriver({ show: () => calls.push('a'), hide() {} });
  modals.setDriver({ show: () => calls.push('b'), hide() {} });
  removeA();
  modals.show('x');
  assert.deepEqual(calls, ['b']);
});
