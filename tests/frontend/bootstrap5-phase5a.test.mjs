import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { collectInventory } from './inventory/bootstrap-inventory.mjs';

// Fase 5A: layout y primitives de BootstrapVue 2 → BootstrapVueNext, medidos por AST (no por regex).
const ROOT = path.resolve(new URL('../..', import.meta.url).pathname);
const SRC = path.join(ROOT, 'resources/src');
const inv = collectInventory(ROOT);

test('inventario por AST: no queda layout, botones, primitives, pestañas, desplegables ni paginación de BootstrapVue 2', () => {
  for (const family of ['layout', 'button', 'tabs', 'dropdown', 'pagination', 'collapse-nav', 'table', 'modal']) {
    assert.equal((inv.families[family] || { bv2: 0 }).bv2, 0, `familia ${family}: etiquetas de BV2`);
  }
  assert.equal((inv.families.primitive || { bv2: 0 }).bv2, 0);
});

test('lo único que queda de BootstrapVue 2 son formularios y las etiquetas sin equivalente (b-skeleton-img)', () => {
  const rest = Object.entries(inv.byTag).filter(([, c]) => c.bv2 > 0).map(([t]) => t);
  const allowed = (t) => /^b-(form|input-group)/.test(t) || t === 'b-skeleton-img';
  assert.deepEqual(rest.filter((t) => !allowed(t)), []);
  assert.ok(inv.tags.bv2 <= 2140, `etiquetas BV2 (${inv.tags.bv2}) no debe crecer respecto a la fase 5A`);
});

test('el registro global de BV2 (platform/compat/bootstrap-vue-forms.js) cubre exactamente las etiquetas que aún se usan', () => {
  const covered = new Set(['b-form', 'b-form-group', 'b-form-input', 'b-form-textarea', 'b-form-select', 'b-form-select-option', 'b-form-select-option-group', 'b-form-checkbox', 'b-form-checkbox-group',
    'b-form-radio', 'b-form-radio-group', 'b-form-file', 'b-form-datepicker', 'b-form-invalid-feedback', 'b-form-valid-feedback', 'b-form-text', 'b-form-row', 'b-input-group',
    'b-input-group-append', 'b-input-group-prepend', 'b-input-group-text', 'b-input-group-addon', 'b-skeleton-img']);
  const used = Object.entries(inv.byTag).filter(([, c]) => c.bv2 > 0).map(([t]) => t);
  assert.deepEqual(used.filter((t) => !covered.has(t)), [], 'etiquetas BV2 en uso que el registro reducido no registra');
  const kit = fs.readFileSync(path.join(SRC, 'plugins/stocky.kit.js'), 'utf8');
  assert.match(kit, /Vue\.use\(BootstrapVueRemaining\)/);
  assert.doesNotMatch(kit, /Vue\.use\(BootstrapVue\)/);
  assert.doesNotMatch(fs.readFileSync(path.join(SRC, 'login.js'), 'utf8'), /Vue\.use\(BootstrapVue\)/);
});

test('el wrapper de platform/bootstrap exporta toda la familia migrada', async () => {
  const src = fs.readFileSync(path.join(SRC, 'platform/bootstrap/index.js'), 'utf8');
  for (const name of ['BRow', 'BCol', 'BContainer', 'BCard', 'BCardBody', 'BCardHeader', 'BCardFooter', 'BCardTitle', 'BCardText', 'BButton', 'BButtonGroup', 'BBadge', 'BAlert', 'BSpinner',
    'BProgress', 'BProgressBar', 'BLink', 'BListGroup', 'BListGroupItem', 'BImg', 'BAvatar', 'BTabs', 'BTab', 'BDropdown', 'BDropdownItem', 'BDropdownDivider', 'BDropdownHeader', 'BDropdownForm', 'BPagination']) {
    assert.match(src, new RegExp(`export (?:const ${name}\\b|\\{[^}]*\\b${name}\\b)`), `${name} exportado`);
  }
});

test('contrato de los wrappers de la fase 5A (traducciones de BV2 que no deben perderse)', () => {
  const src = fs.readFileSync(path.join(SRC, 'platform/bootstrap/index.js'), 'utf8');
  assert.match(src, /'no-gutters'/, 'BRow: no-gutters → clase');
  assert.match(src, /badge-pill/, 'BBadge: pill → badge-pill');
  assert.match(src, /bg-\$\{variant\}/, 'BProgressBar: variante bg-*');
  assert.match(src, /noWrapper: true/, 'BDropdown: envoltorio propio con el id de BV2');
  assert.match(src, /__BV_toggle_/, 'BDropdown: id del botón como en BV2');
  assert.match(src, /bv::dropdown::show/, 'BDropdown: eventos de raíz de BV2');
  assert.match(src, /onPageClick/, 'BPagination: change por interacción');
  assert.match(src, /onUpdate:index/, 'BTabs: v-model = índice');
  assert.match(src, /export const BTab = (?:\/\*#__PURE__\*\/ )?pure\(_BTab\)/, 'BTab sin envolver: BVN solo registra hijos cuyo type es su BTab');
  assert.match(src, /target === '_blank'[\s\S]*noopener/, 'BButton: rel noopener');
});

test('inventario de vue-good-table registrado (fase 5C): 73 archivos / 86 tablas, sin cambios de implementación', () => {
  const file = path.join(ROOT, 'tests/frontend/inventory/vue-good-table.json');
  const saved = JSON.parse(fs.readFileSync(file, 'utf8'));
  assert.equal(inv.vgt.tables, saved.tables, 'nº de tablas');
  assert.equal(inv.vgt.files, saved.files, 'nº de archivos');
  assert.deepEqual(inv.vgt.features, saved.features, 'funciones usadas');
  assert.deepEqual(inv.vgt.list, saved.list, 'inventario por archivo (regenerar con `node tests/frontend/inventory/bootstrap-inventory.mjs --vgt`)');
});

test('plantillas en cadena (`template: `...`` dentro de <script>) con etiquetas b-*: cada una registra sus wrappers (no hay registro global de BV2 para layout/primitives)', () => {
  const offenders = [];
  const walkDir = (dir) => {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, e.name);
      if (e.isDirectory()) walkDir(full);
      else if (/\.(vue|js)$/.test(e.name) && !full.includes(`${path.sep}platform${path.sep}`)) {
        const text = fs.readFileSync(full, 'utf8');
        const scripts = e.name.endsWith('.vue') ? [...text.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)].map((m) => m[1]).join('\n') : text;
        const tags = [...new Set([...scripts.matchAll(/<(b-[a-z0-9-]+)/g)].map((m) => m[1]))].filter((t) => !/^b-(form|input-group|skeleton)/.test(t));
        if (tags.length) offenders.push(`${path.relative(SRC, full)}: ${tags.join(', ')}`);
      }
    }
  };
  walkDir(SRC);
  // CustomerLedger registra `BButton` / `BPagination` en sus dos componentes en línea
  assert.deepEqual(offenders.filter((o) => !o.startsWith('views/app/pages/people/CustomerLedger.vue')), []);
  const ledger = fs.readFileSync(path.join(SRC, 'views/app/pages/people/CustomerLedger.vue'), 'utf8');
  assert.match(ledger, /ListToolbar\.components = \{ BButton \}/);
  assert.match(ledger, /Pager\.components = \{ BPagination \}/);
});

test('platform/bootstrap sin efectos laterales al importar (cada entrypoint solo arrastra los componentes que usa)', () => {
  const pkg = JSON.parse(fs.readFileSync(path.join(SRC, 'platform/bootstrap/package.json'), 'utf8'));
  assert.equal(pkg.sideEffects, false);
  const src = fs.readFileSync(path.join(SRC, 'platform/bootstrap/index.js'), 'utf8');
  const bare = src.split('\n').filter((l) => /^(?:export )?const \w+ = (?:pure|wrapper|checkWrapper)\(/.test(l));
  assert.deepEqual(bare, [], 'toda llamada de nivel superior a pure()/wrapper() debe llevar /*#__PURE__*/');
});
