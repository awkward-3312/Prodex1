import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

// Guarda de la fase vue3-legacy-ui-dependencies: falla si package.json (o el código fuente) vuelve a introducir
// cualquiera de los paquetes Vue 2 que se eliminaron (ver docs/architecture/VUE3_LEGACY_UI_DEPENDENCIES.md).
// `vuex` (3.x) y `@vue/compat` son las ÚNICAS dependencias con sabor Vue 2 que quedan pendientes, a propósito,
// para las siguientes fases ("Vuex 4 + createApp") — no las prohíbe esta guarda.
const ROOT = path.resolve(new URL('../..', import.meta.url).pathname);
const SRC = path.join(ROOT, 'resources/src');

const REMOVED_PACKAGES = [
  'vue-select',
  'vue-good-table',
  'vue-good-table-next',
  'vue2-daterange-picker',
  'vuejs-datepicker',
  '@pencilpix/vue2-clock-picker',
  'lucide-vue',
  '@johmun/vue-tags-input',
  'vue-perfect-scrollbar',
  'vue-cookie',
  'vue-cookies',
  'vue-localstorage',
  'vue-barcode',
  'vue-easy-print',
  'vue-html-to-paper',
  'vue-template-compiler',
  '@trevoreyre/autocomplete-vue',
  'vue-apexcharts',
  'vuedraggable-v2',
];

const pkg = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8'));
const allDeps = { ...pkg.dependencies, ...pkg.devDependencies };

test('package.json: ninguna de las dependencias Vue 2 eliminadas (vue-select, vue-good-table, date/range/time legacy, vuedraggable v2, lucide-vue, vue-apexcharts v2, vue-tags-input, perfect-scrollbar, cookie/localstorage, barcode, print, dead deps) vuelve a aparecer', () => {
  for (const name of REMOVED_PACKAGES) {
    assert.ok(!allDeps[name], `"${name}" no debería estar en package.json (fue eliminado en la fase vue3-legacy-ui-dependencies)`);
  }
});

test('package.json: solo vuex (3.x) y @vue/compat quedan como dependencias con sabor Vue 2, a propósito (fases siguientes)', () => {
  assert.ok(allDeps.vuex, 'vuex debe seguir presente (migración a Vuex 4 diferida)');
  assert.match(allDeps.vuex, /^[\^~]?3\./, 'vuex debe seguir en la serie 3.x');
  assert.ok(allDeps['@vue/compat'], '@vue/compat debe seguir presente (createApp/compat diferido)');
});

test('node_modules: ninguno de los paquetes Vue 2 eliminados está instalado', () => {
  for (const name of REMOVED_PACKAGES) {
    assert.ok(!fs.existsSync(path.join(ROOT, 'node_modules', name)), `node_modules/${name} no debería existir`);
  }
});

test('código fuente: ninguna vista/módulo importa un paquete Vue 2 eliminado', () => {
  const offenders = [];
  const walk = (dir) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) { walk(full); continue; }
      if (!/\.(vue|js)$/.test(entry.name)) continue;
      const text = fs.readFileSync(full, 'utf8');
      for (const name of REMOVED_PACKAGES) {
        const re = new RegExp(`from\\s+['"]${name.replace(/[/@]/g, '\\$&')}(?:/|['"])`);
        if (re.test(text)) offenders.push(`${path.relative(SRC, full)}: ${name}`);
      }
    }
  };
  walk(SRC);
  assert.deepEqual(offenders, []);
});
