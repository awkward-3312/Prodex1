import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

// DOM simulado mínimo: `document.documentElement` con add/removeEventListener/dispatch, y elementos con `contains`.
function installFakeDom() {
  const listeners = new Map();
  const documentElement = {
    addEventListener(type, fn) { (listeners.get(type) || listeners.set(type, new Set()).get(type)).add(fn); },
    removeEventListener(type, fn) { const set = listeners.get(type); if (set) set.delete(fn); },
    dispatch(type, target) { [...(listeners.get(type) || [])].forEach((fn) => fn({ type, target })); },
    count(type) { return (listeners.get(type) || new Set()).size; },
  };
  globalThis.document = { documentElement };
  return documentElement;
}
const makeEl = (...children) => {
  const el = { children, contains(t) { return t === el || children.some((c) => c === t || (c.contains && c.contains(t))); } };
  return el;
};
const tick = () => new Promise((r) => setTimeout(r, 5));

const root = installFakeDom();
const { clickaway } = await import('../../resources/src/platform/directives/clickaway.js');
const { installDirectives } = await import('../../resources/src/platform/directives/index.js');

const outside = { id: 'fuera' };

test('mounted: registra un único listener y la guarda de primer tick ignora el click que abre el elemento', async () => {
  const el = makeEl();
  const calls = [];
  clickaway.mounted(el, { value: (e) => calls.push(e), instance: {} });
  assert.equal(root.count('click'), 1);
  root.dispatch('click', outside); // mismo tick que el montaje: el click que lo abrió
  assert.equal(calls.length, 0);
  await tick();
  root.dispatch('click', outside);
  assert.equal(calls.length, 1);
  clickaway.unmounted(el);
});

test('click dentro no dispara; click fuera dispara con el evento y `this` = instancia del componente', async () => {
  const child = { id: 'hijo' };
  const el = makeEl(child);
  const instance = { name: 'cmp' };
  let seen;
  clickaway.mounted(el, { value: function handler(e) { seen = { ctx: this, e }; }, instance });
  await tick();
  root.dispatch('click', el);
  root.dispatch('click', child);
  assert.equal(seen, undefined);
  root.dispatch('click', outside);
  assert.equal(seen.ctx, instance);
  assert.equal(seen.e.target, outside);
  clickaway.unmounted(el);
});

test('updated: un handler nuevo sustituye al anterior sin listeners duplicados y rearma la guarda', async () => {
  const el = makeEl();
  const a = []; const b = [];
  const first = () => a.push(1);
  const second = () => b.push(1);
  clickaway.mounted(el, { value: first, instance: {} });
  await tick();
  clickaway.updated(el, { value: second, oldValue: first, instance: {} });
  assert.equal(root.count('click'), 1);
  root.dispatch('click', outside); // guarda rearmada: el click del propio cambio no cuenta
  assert.equal(b.length, 0);
  await tick();
  root.dispatch('click', outside);
  assert.deepEqual([a.length, b.length], [0, 1]);
  // mismo valor: no rearma ni duplica
  clickaway.updated(el, { value: second, oldValue: second, instance: {} });
  root.dispatch('click', outside);
  assert.equal(b.length, 2);
  clickaway.unmounted(el);
});

test('unmounted: retira el listener, cancela la guarda pendiente y es idempotente', async () => {
  const el = makeEl();
  const calls = [];
  clickaway.mounted(el, { value: () => calls.push(1), instance: {} });
  clickaway.unmounted(el);
  assert.equal(root.count('click'), 0);
  await tick();
  root.dispatch('click', outside);
  assert.equal(calls.length, 0);
  assert.doesNotThrow(() => clickaway.unmounted(el));
  assert.doesNotThrow(() => clickaway.unmounted(makeEl()));
});

test('mounted dos veces sobre el mismo elemento no duplica listeners', async () => {
  const el = makeEl();
  const calls = [];
  clickaway.mounted(el, { value: () => calls.push(1), instance: {} });
  clickaway.mounted(el, { value: () => calls.push(1), instance: {} });
  assert.equal(root.count('click'), 1);
  await tick();
  root.dispatch('click', outside);
  assert.equal(calls.length, 1);
  clickaway.unmounted(el);
});

test('múltiples instancias son independientes: cada una recibe el click fuera y solo la desmontada deja de recibirlo', async () => {
  const one = makeEl(); const two = makeEl();
  const hits = { one: 0, two: 0 };
  clickaway.mounted(one, { value: () => hits.one++, instance: {} });
  clickaway.mounted(two, { value: () => hits.two++, instance: {} });
  assert.equal(root.count('click'), 2);
  await tick();
  root.dispatch('click', one); // dentro de `one`, fuera de `two`
  assert.deepEqual(hits, { one: 0, two: 1 });
  clickaway.unmounted(one);
  root.dispatch('click', outside);
  assert.deepEqual(hits, { one: 0, two: 2 });
  clickaway.unmounted(two);
  assert.equal(root.count('click'), 0);
});

test('handler inválido: avisa en desarrollo, no ejecuta nada y se recupera al recibir una función', async () => {
  const warnings = [];
  const original = console.warn;
  console.warn = (m) => warnings.push(String(m));
  try {
    const el = makeEl();
    clickaway.mounted(el, { value: 'no-soy-funcion', instance: {} });
    await tick();
    assert.doesNotThrow(() => root.dispatch('click', outside));
    assert.equal(warnings.length, 1);
    assert.match(warnings[0], /debe ser una función/);
    const calls = [];
    const fn = () => calls.push(1);
    clickaway.updated(el, { value: fn, oldValue: 'no-soy-funcion', instance: {} });
    await tick();
    root.dispatch('click', outside);
    assert.equal(calls.length, 1);
    // vuelve a ser inválido: deja de ejecutar
    clickaway.updated(el, { value: null, oldValue: fn, instance: {} });
    await tick();
    root.dispatch('click', outside);
    assert.equal(calls.length, 1);
    clickaway.unmounted(el);
  } finally {
    console.warn = original;
  }
});

test('installDirectives registra `on-clickaway` (mismo nombre que vue-clickaway) con la directiva propia', () => {
  const registered = {};
  installDirectives({ directive: (name, def) => { registered[name] = def; } });
  assert.equal(registered['on-clickaway'], clickaway);
  assert.deepEqual(Object.keys(clickaway).sort(), ['mounted', 'unmounted', 'updated']);
});

// Guardia del repo
const SRC = path.resolve(new URL('../../resources/src', import.meta.url).pathname);
function walk(dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, e.name);
    if (e.isDirectory()) walk(full, out);
    else if (/\.(vue|js)$/.test(e.name)) out.push(full);
  }
  return out;
}

test('vue-clickaway eliminado: sin dependencia, sin import, sin mixin y la directiva se registra en main.js', () => {
  const pkg = JSON.parse(fs.readFileSync(path.join(SRC, '../../package.json'), 'utf8'));
  assert.equal(pkg.dependencies['vue-clickaway'], undefined);
  assert.equal(fs.existsSync(path.join(SRC, '../../node_modules/vue-clickaway')), false);
  const offenders = walk(SRC).filter((f) => /vue-clickaway|mixin as clickaway/.test(fs.readFileSync(f, 'utf8'))).map((f) => path.relative(SRC, f));
  assert.deepEqual(offenders, []);
  assert.match(fs.readFileSync(path.join(SRC, 'main.js'), 'utf8'), /installDirectives\(Vue\)/);
});

test('la directiva no usa internals de Vue 2 (Vue.util, $children, hooks bind/inserted/unbind)', () => {
  const text = fs.readFileSync(path.join(SRC, 'platform/directives/clickaway.js'), 'utf8').split('\n').filter((l) => !/^\s*\/\//.test(l)).join('\n');
  assert.doesNotMatch(text, /Vue\.util|\$children|\b(bind|inserted|componentUpdated|unbind)\s*[(:]/);
});
