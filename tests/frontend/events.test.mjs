import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createEventBus, events } from '../../resources/src/platform/events.js';

const noErrors = () => ({ onError: () => assert.fail('no debía haber errores de listeners') });

test('$on + $emit entrega los argumentos, en orden, a todos los listeners', () => {
  const bus = createEventBus(noErrors());
  const calls = [];
  bus.$on('Event_Product', (...args) => calls.push(['a', ...args]));
  bus.$on('Event_Product', (...args) => calls.push(['b', ...args]));
  bus.$emit('Event_Product', 1, { id: 2 }, 'x');
  assert.deepEqual(calls, [['a', 1, { id: 2 }, 'x'], ['b', 1, { id: 2 }, 'x']]);
});

test('emitir un evento sin listeners no falla y otros eventos no se mezclan', () => {
  const bus = createEventBus(noErrors());
  let hit = 0;
  bus.$on('a', () => { hit += 1; });
  bus.$emit('nadie');
  bus.$emit('b');
  assert.equal(hit, 0);
});

test('$on acepta un array de eventos', () => {
  const bus = createEventBus(noErrors());
  const seen = [];
  bus.$on(['x', 'y'], (v) => seen.push(v));
  bus.$emit('x', 1);
  bus.$emit('y', 2);
  assert.deepEqual(seen, [1, 2]);
});

test('$off(evento, fn) quita solo ese listener', () => {
  const bus = createEventBus(noErrors());
  const a = () => calls.push('a');
  const b = () => calls.push('b');
  const calls = [];
  bus.$on('e', a).$on('e', b);
  bus.$off('e', a);
  bus.$emit('e');
  assert.deepEqual(calls, ['b']);
  assert.equal(bus.listenerCount('e'), 1);
});

test('$off(evento) quita todos los de ese evento y $off() quita todo', () => {
  const bus = createEventBus(noErrors());
  let n = 0;
  bus.$on('e', () => { n += 1; }).$on('e', () => { n += 1; }).$on('f', () => { n += 1; });
  bus.$off('e');
  bus.$emit('e');
  bus.$emit('f');
  assert.equal(n, 1);
  bus.$off();
  bus.$emit('f');
  assert.equal(n, 1);
  assert.equal(bus.listenerCount('f'), 0);
});

test('$off con un fn que no está registrado o un evento desconocido es inocuo', () => {
  const bus = createEventBus(noErrors());
  bus.$on('e', () => {});
  bus.$off('e', () => {});
  bus.$off('desconocido', () => {});
  bus.$off('desconocido');
  assert.equal(bus.listenerCount('e'), 1);
});

test('el mismo fn registrado dos veces se ejecuta dos veces y $off quita una instancia (la última) por llamada', () => {
  const bus = createEventBus(noErrors());
  let n = 0;
  const fn = () => { n += 1; };
  bus.$on('e', fn).$on('e', fn);
  bus.$emit('e');
  assert.equal(n, 2);
  bus.$off('e', fn);
  assert.equal(bus.listenerCount('e'), 1);
  bus.$emit('e');
  assert.equal(n, 3);
});

test('$once se ejecuta una sola vez y con los argumentos', () => {
  const bus = createEventBus(noErrors());
  const seen = [];
  bus.$once('e', (...a) => seen.push(a));
  bus.$emit('e', 1, 2);
  bus.$emit('e', 3, 4);
  assert.deepEqual(seen, [[1, 2]]);
  assert.equal(bus.listenerCount('e'), 0);
});

test('$off con el fn original cancela un $once que aún no se disparó', () => {
  const bus = createEventBus(noErrors());
  let n = 0;
  const fn = () => { n += 1; };
  bus.$once('e', fn);
  bus.$off('e', fn);
  bus.$emit('e');
  assert.equal(n, 0);
});

test('un listener puede desuscribirse durante la emisión sin saltarse a los demás', () => {
  const bus = createEventBus(noErrors());
  const order = [];
  const first = () => { order.push('first'); bus.$off('e', first); };
  bus.$on('e', first).$on('e', () => order.push('second')).$on('e', () => order.push('third'));
  bus.$emit('e');
  bus.$emit('e');
  assert.deepEqual(order, ['first', 'second', 'third', 'second', 'third']);
});

test('un listener agregado durante la emisión no se ejecuta en esa misma emisión', () => {
  const bus = createEventBus(noErrors());
  const seen = [];
  bus.$on('e', () => { seen.push('a'); bus.$on('e', () => seen.push('nuevo')); });
  bus.$emit('e');
  assert.deepEqual(seen, ['a']);
  bus.$emit('e');
  assert.deepEqual(seen, ['a', 'a', 'nuevo']);
});

test('un listener que lanza un error se reporta y NO impide que corran los demás', () => {
  const errors = [];
  const bus = createEventBus({ onError: (error, event) => errors.push([error.message, event]) });
  const seen = [];
  bus.$on('e', () => { throw new Error('boom'); });
  bus.$on('e', () => seen.push('ok'));
  bus.$emit('e');
  assert.deepEqual(seen, ['ok']);
  assert.deepEqual(errors, [['boom', 'e']]);
});

test('un $once que lanza un error igualmente queda eliminado', () => {
  const bus = createEventBus({ onError: () => {} });
  bus.$once('e', () => { throw new Error('boom'); });
  bus.$emit('e');
  assert.equal(bus.listenerCount('e'), 0);
});

test('el manejador de errores por defecto escribe en console.error', () => {
  const original = console.error;
  const logged = [];
  console.error = (...args) => logged.push(args);
  try {
    const bus = createEventBus();
    bus.$on('e', () => { throw new Error('x'); });
    bus.$emit('e');
  } finally {
    console.error = original;
  }
  assert.equal(logged.length, 1);
  assert.match(String(logged[0][0]), /"e"/);
});

test('$on con algo que no es función lanza un TypeError claro', () => {
  const bus = createEventBus(noErrors());
  assert.throws(() => bus.$on('e', undefined), TypeError);
  assert.throws(() => bus.$once('e', 'no'), TypeError);
});

test('todos los métodos $ devuelven el bus (encadenables), como Vue', () => {
  const bus = createEventBus(noErrors());
  const fn = () => {};
  assert.equal(bus.$on('e', fn), bus);
  assert.equal(bus.$once('e', fn), bus);
  assert.equal(bus.$emit('e'), bus);
  assert.equal(bus.$off('e', fn), bus);
  assert.equal(bus.$off(), bus);
});

test('el listener recibe el bus como `this`', () => {
  const bus = createEventBus(noErrors());
  let self;
  bus.$on('e', function () { self = this; });
  bus.$emit('e');
  assert.equal(self, bus);
});

test('los nombres de evento son exactos (mayúsculas, dos puntos) y admiten símbolos', () => {
  const bus = createEventBus(noErrors());
  const sym = Symbol('e');
  const seen = [];
  bus.$on('offline-sync:auto-result', (v) => seen.push(v));
  bus.$on('Event_sms', (v) => seen.push(`sms:${v}`));
  bus.$on(sym, (v) => seen.push(`sym:${v}`));
  bus.$emit('offline-sync:auto-result', 1);
  bus.$emit('event_sms', 2); // distinto (minúsculas): no dispara
  bus.$emit('Event_sms', 3);
  bus.$emit(sym, 4);
  assert.deepEqual(seen, [1, 'sms:3', 'sym:4']);
});

test('API nueva: on() devuelve la función que cancela y once()/emit()/off() equivalen a los $', () => {
  const bus = createEventBus(noErrors());
  const seen = [];
  const stop = bus.on('e', (v) => seen.push(v));
  bus.emit('e', 1);
  stop();
  bus.emit('e', 2);
  bus.once('o', (v) => seen.push(`once:${v}`));
  bus.emit('o', 3);
  bus.emit('o', 4);
  assert.deepEqual(seen, [1, 'once:3']);
});

test('la comprobación que hacen los consumidores existentes (window.Fire && window.Fire.$emit) funciona', () => {
  const fire = createEventBus(noErrors());
  assert.equal(typeof fire.$emit, 'function');
  assert.equal(typeof fire.$on, 'function');
  assert.equal(typeof fire.$off, 'function');
});

test('los buses son independientes entre sí y el compartido existe', () => {
  const a = createEventBus(noErrors());
  const b = createEventBus(noErrors());
  let n = 0;
  a.$on('e', () => { n += 1; });
  b.$emit('e');
  assert.equal(n, 0);
  assert.equal(typeof events.$emit, 'function');
});
