import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

function walk(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, out);
    else if (/\.(js|vue)$/.test(entry.name)) out.push(full);
  }
  return out;
}

test('ningún código de la app ni script suelto lee internals de Vue desde el DOM', () => {
  const forbidden = [/\.__vue__\b/, /\.__vue_app__\b/, /\._vnode\b/, /\.\$vnode\b/, /__vueParentComponent/];
  const offenders = [];
  for (const file of [...walk(path.join(root, 'resources/src')), ...walk(path.join(root, 'resources/static'))]) {
    const text = fs.readFileSync(file, 'utf8');
    text.split('\n').forEach((line, i) => {
      if (forbidden.some((re) => re.test(line))) offenders.push(`${path.relative(root, file)}:${i + 1}: ${line.trim().slice(0, 90)}`);
    });
  }
  assert.deepEqual(offenders, [], 'Los scripts deben usar window.__prodexBridge (platform/legacy-bridge.js), no internals de Vue');
});

// --- prodex-inventory-native-menu.js con un DOM mínimo simulado ---------------------------------------------------
function runNativeMenu({ bridge } = {}) {
  const links = [];
  const makeLink = () => {
    const attrs = new Map();
    const listeners = [];
    return {
      attrs,
      getAttribute: (k) => attrs.get(k) ?? null,
      setAttribute: (k, v) => attrs.set(k, v),
      addEventListener: (type, fn) => listeners.push([type, fn]),
      click(event) { listeners.filter(([t]) => t === 'click').forEach(([, fn]) => fn(event)); },
    };
  };
  const stockLink = makeLink();
  links.push(stockLink);
  const sandbox = {
    window: null,
    document: {
      body: null,
      querySelectorAll: (selector) => (selector === '.px-iv-menu-stock a' ? [stockLink] : []),
      addEventListener() {},
    },
    MutationObserver: class { observe() {} },
  };
  sandbox.window = { addEventListener() {}, requestAnimationFrame: (fn) => fn(), __prodexBridge: bridge };
  vm.runInNewContext(fs.readFileSync(path.join(root, 'resources/static/prodex-inventory-native-menu.js'), 'utf8'), sandbox);
  return { stockLink };
}

const clickEvent = (extra = {}) => {
  const event = { button: 0, defaultPrevented: false, propagationStopped: false, ...extra };
  event.preventDefault = () => { event.defaultPrevented = true; };
  event.stopPropagation = () => { event.propagationStopped = true; };
  return event;
};

test('menú nativo de inventario: fija el href y navega por el puente explícito', () => {
  const calls = [];
  const { stockLink } = runNativeMenu({ bridge: { navigate: (p) => calls.push(p) } });
  assert.equal(stockLink.getAttribute('href'), '/app/inventory/location-stock');
  const event = clickEvent();
  stockLink.click(event);
  assert.deepEqual(calls, ['/app/inventory/location-stock']);
  assert.equal(event.defaultPrevented, true);
  assert.equal(event.propagationStopped, true);
});

test('menú nativo de inventario: con Ctrl/Cmd/Shift/Alt o botón secundario no intercepta el clic', () => {
  const calls = [];
  const { stockLink } = runNativeMenu({ bridge: { navigate: (p) => calls.push(p) } });
  for (const extra of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }]) {
    const event = clickEvent(extra);
    stockLink.click(event);
    assert.equal(event.defaultPrevented, false);
  }
  assert.deepEqual(calls, []);
});

test('menú nativo de inventario: sin puente deja el enlace normal (no bloquea la navegación)', () => {
  const { stockLink } = runNativeMenu({ bridge: undefined });
  const event = clickEvent();
  stockLink.click(event);
  assert.equal(event.defaultPrevented, false);
});
