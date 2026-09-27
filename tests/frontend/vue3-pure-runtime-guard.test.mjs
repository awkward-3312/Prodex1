import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

// Guarda final de la fase vue3-pure-runtime: falla si @vue/compat (o cualquier resto de su configuración) vuelve
// a aparecer. PRODEX corre con `vue@3.5.x` NATIVO; no queda MODE 2/3, `configureCompat`, `compatConfig`,
// `compatModeFor`, el alias de webpack `vue -> @vue/compat`, ni los dos archivos que existían solo para eso.
const ROOT = path.resolve(new URL('../..', import.meta.url).pathname);
const SRC = path.join(ROOT, 'resources/src');
const read = (rel) => fs.readFileSync(path.join(ROOT, rel), 'utf8');

function walk(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, out);
    else if (/\.(vue|js)$/.test(entry.name)) out.push(full);
  }
  return out;
}
const codeOnly = (text) => text.split('\n').filter((l) => !/^\s*\/\//.test(l)).join('\n');
const ALL_FILES = walk(SRC);

test('package.json / node_modules: @vue/compat no está instalado', () => {
  const pkg = JSON.parse(read('package.json'));
  const allDeps = { ...pkg.dependencies, ...pkg.devDependencies };
  assert.ok(!allDeps['@vue/compat'], '@vue/compat no debe estar en package.json');
  assert.ok(!fs.existsSync(path.join(ROOT, 'node_modules/@vue/compat')), '@vue/compat no debe estar instalado');
});

test('webpack.mix.js: sin alias `vue -> @vue/compat` (usa el `vue` real)', () => {
  assert.doesNotMatch(read('webpack.mix.js'), /@vue\/compat/);
});

test('sin `configureCompat(` en código propio', () => {
  const offenders = ALL_FILES.filter((f) => /configureCompat\(/.test(codeOnly(fs.readFileSync(f, 'utf8')))).map((f) => path.relative(SRC, f));
  assert.deepEqual(offenders, []);
});

test('sin `compatConfig` (MODE 2/3, INSTANCE_LISTENERS, etc.) en ningún componente propio', () => {
  const offenders = ALL_FILES.filter((f) => /compatConfig/.test(codeOnly(fs.readFileSync(f, 'utf8')))).map((f) => path.relative(SRC, f));
  assert.deepEqual(offenders, []);
});

test('sin `MODE:\\s*[23]` (la opción de @vue/compat) en código propio', () => {
  const offenders = ALL_FILES.filter((f) => /\bMODE\s*:\s*[23]\b/.test(codeOnly(fs.readFileSync(f, 'utf8')))).map((f) => path.relative(SRC, f));
  assert.deepEqual(offenders, []);
});

test('sin `compatModeFor` (heurística de MODE por componente, ya no hace falta)', () => {
  const offenders = ALL_FILES.filter((f) => /compatModeFor/.test(codeOnly(fs.readFileSync(f, 'utf8')))).map((f) => path.relative(SRC, f));
  assert.deepEqual(offenders, []);
});

test('platform/vue-compat.js y platform/compat/bvn-mode.js fueron eliminados', () => {
  assert.ok(!fs.existsSync(path.join(SRC, 'platform/vue-compat.js')));
  assert.ok(!fs.existsSync(path.join(SRC, 'platform/compat/bvn-mode.js')));
});

test('ningún entrypoint importa platform/vue-compat', () => {
  for (const f of ['main.js', 'login.js', 'portal.js', 'customer-display.js']) {
    assert.doesNotMatch(read(`resources/src/${f}`), /platform\/vue-compat/, f);
  }
});

test('sin `render: h =>`/`render: (h) =>` (idioma de Vue 2: Vue 3 no inyecta `h` como argumento de `render`)', () => {
  const offenders = ALL_FILES.filter((f) => /render:\s*\(?h\)?\s*=>/.test(codeOnly(fs.readFileSync(f, 'utf8')))).map((f) => path.relative(SRC, f));
  assert.deepEqual(offenders, []);
});

test('sin `this.$set(`/`this.$delete(` (innecesarios en Vue 3: asignación/delete nativos)', () => {
  const offenders = ALL_FILES.filter((f) => /this\.\$(set|delete)\(/.test(codeOnly(fs.readFileSync(f, 'utf8')))).map((f) => path.relative(SRC, f));
  assert.deepEqual(offenders, []);
});

test('registro GLOBAL de un componente asíncrono (`app.component(name, () => import(...))`) usa `defineAsyncComponent` explícito', () => {
  const offenders = [];
  for (const f of ALL_FILES) {
    const code = codeOnly(fs.readFileSync(f, 'utf8'));
    const re = /\.component\(\s*["'][\w-]+["']\s*,\s*(?:\n\s*)?\(\)\s*=>\s*import\(/g;
    if (re.test(code)) offenders.push(path.relative(SRC, f));
  }
  assert.deepEqual(offenders, []);
});

test('vuex sigue en la serie 4.x (createStore); new Vue(/Vue.use(/Vue.prototype en 0 (ver vuex4-createapp-guard.test.mjs)', () => {
  const pkg = JSON.parse(read('package.json'));
  const allDeps = { ...pkg.dependencies, ...pkg.devDependencies };
  assert.match(allDeps.vuex, /^[\^~]?4\./);
});

// vuedraggable v4 (SortableJS-vue3) exige el slot #item explícito — a diferencia de v2/v3, ya NO renderiza
// automáticamente los hijos declarados con v-for dentro de <draggable>. Sin #item: "draggable element must have
// an item slot" en runtime (bloqueante, encontrado en producción vía CI: System_settings.vue).
test('todo `<draggable>` propio declara el slot `#item` (vuedraggable v4 no renderiza v-for hijo sin él)', () => {
  const offenders = [];
  for (const f of ALL_FILES) {
    if (!f.endsWith('.vue')) continue;
    const code = codeOnly(fs.readFileSync(f, 'utf8'));
    if (!/<draggable[\s>]/.test(code)) continue;
    if (!/#item(?:=|>|\s)|v-slot:item(?:=|>|\s)/.test(code)) offenders.push(path.relative(SRC, f));
  }
  assert.deepEqual(offenders, []);
});
