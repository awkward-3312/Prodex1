import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

// Guarda de la fase vuex4-createapp: falla si el código propio reintroduce el bootstrap de Vue 2 (`new Vue(`,
// `Vue.use(`, `Vue.prototype`, `new Vuex.Store`, `Vue.set(`/`Vue.delete(`) o si `vuex` vuelve a la serie 3.x.
// `@vue/compat` SIGUE activo a propósito (se retira en la fase siguiente): esta guarda no lo prohíbe.
const ROOT = path.resolve(new URL('../..', import.meta.url).pathname);
const SRC = path.join(ROOT, 'resources/src');

const read = (rel) => fs.readFileSync(path.join(ROOT, rel), 'utf8');

// Comentarios explicativos (líneas que empiezan por `//` tras trim) mencionan estos patrones a propósito para
// documentar QUÉ se eliminó — se excluyen de la búsqueda de código real, solo el código ejecutable cuenta.
function codeOnly(text) {
  return text.split('\n').filter((l) => !/^\s*\/\//.test(l)).join('\n');
}

function walk(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, out);
    else if (/\.(vue|js)$/.test(entry.name)) out.push(full);
  }
  return out;
}

test('sin `new Vue(` propio (bootstrap de Vue 2): los 4 entrypoints usan `mountWithRouter`/`createApp` real', () => {
  const offenders = walk(SRC)
    .map((f) => ({ f, code: codeOnly(fs.readFileSync(f, 'utf8')) }))
    .filter(({ code }) => /new Vue\(/.test(code))
    .map(({ f }) => path.relative(SRC, f));
  assert.deepEqual(offenders, []);
});

test('sin `Vue.use(` propio: cada plugin se instala con `app.use(...)` sobre la app real', () => {
  const offenders = walk(SRC)
    .map((f) => ({ f, code: codeOnly(fs.readFileSync(f, 'utf8')) }))
    .filter(({ code }) => /\bVue\.use\(/.test(code))
    .map(({ f }) => path.relative(SRC, f));
  assert.deepEqual(offenders, []);
});

test('sin `Vue.prototype` propio: los globals van en `app.config.globalProperties`', () => {
  const offenders = walk(SRC)
    .map((f) => ({ f, code: codeOnly(fs.readFileSync(f, 'utf8')) }))
    .filter(({ code }) => /Vue\.prototype/.test(code))
    .map(({ f }) => path.relative(SRC, f));
  assert.deepEqual(offenders, []);
});

test('sin `Vue.set(`/`Vue.delete(` propios (innecesarios en Vue 3: asignación/delete nativos)', () => {
  const offenders = walk(SRC)
    .map((f) => ({ f, code: codeOnly(fs.readFileSync(f, 'utf8')) }))
    .filter(({ code }) => /Vue\.(set|delete)\(/.test(code))
    .map(({ f }) => path.relative(SRC, f));
  assert.deepEqual(offenders, []);
});

test('sin `new Vuex.Store`/`Vuex.Store(`: el store usa `createStore(...)` (Vuex 4)', () => {
  const offenders = walk(SRC)
    .map((f) => ({ f, code: codeOnly(fs.readFileSync(f, 'utf8')) }))
    .filter(({ code }) => /new Vuex\.Store|Vuex\.Store\(/.test(code))
    .map(({ f }) => path.relative(SRC, f));
  assert.deepEqual(offenders, []);
  assert.match(fs.readFileSync(path.join(SRC, 'store/index.js'), 'utf8'), /createStore\(/);
});

test('vuex está en la serie 4.x (no 3.x) y sigue siendo el único gestor de estado (no Pinia)', () => {
  const pkg = JSON.parse(read('package.json'));
  const allDeps = { ...pkg.dependencies, ...pkg.devDependencies };
  assert.ok(allDeps.vuex, 'vuex debe estar presente');
  assert.match(allDeps.vuex, /^[\^~]?4\./);
  assert.ok(!allDeps.pinia, 'no se migró a Pinia (fuera de alcance de esta fase)');
});

// Fase vue3-pure-runtime: @vue/compat fue retirado del todo. Ver
// tests/frontend/vuex4-createapp-guard.test.mjs (mismo nombre de archivo, guarda de esta fase) para la lista
// completa de comprobaciones.
test('@vue/compat ya NO está presente (fase vue3-pure-runtime) y el alias de webpack apunta al `vue` real', () => {
  const pkg = JSON.parse(read('package.json'));
  const allDeps = { ...pkg.dependencies, ...pkg.devDependencies };
  assert.ok(!allDeps['@vue/compat']);
  assert.doesNotMatch(read('webpack.mix.js'), /@vue\/compat/);
});

test('platform/compat/vue-router.js (bootstrap `new Vue()` antiguo) fue retirado: platform/mount.js lo reemplaza con createApp real', () => {
  assert.ok(!fs.existsSync(path.join(SRC, 'platform/compat/vue-router.js')));
  const mount = fs.readFileSync(path.join(SRC, 'platform/mount.js'), 'utf8');
  assert.match(mount, /import\s*\{\s*createApp\s*\}\s*from\s*['"]vue['"]/);
  assert.match(mount, /createApp\(/);
});

test('los 4 entrypoints reales (main, login, portal, customer-display) importan mountWithRouter de platform/mount', () => {
  for (const f of ['main.js', 'login.js', 'portal.js', 'customer-display.js']) {
    const src = read(`resources/src/${f}`);
    assert.match(src, /from ['"]\.\/platform\/mount['"]/, `${f} debe importar mountWithRouter de platform/mount`);
  }
});
