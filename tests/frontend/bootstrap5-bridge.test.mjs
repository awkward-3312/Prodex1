import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

// Bootstrap 5 + BootstrapVueNext (fase 1): dependencias, puente CSS aditivo compilado con sass real, envoltorios de BVN y guardia
// de las clases BS4 migradas. La convivencia BS4 (hoja base) + BS5 (puente) se documenta en
// docs/architecture/BOOTSTRAP5_BOOTSTRAPVUE_NEXT_PHASE1.md.
const ROOT = path.resolve(new URL('../..', import.meta.url).pathname);
const SRC = path.join(ROOT, 'resources/src');
// Los wrappers viven en módulos por familia (fase 5B): las comprobaciones de contrato leen el conjunto.
const bootstrapSource = () => ['index', 'core', 'layout', 'buttons', 'forms', 'form-text', 'form-choice', 'primitives', 'file', 'datepicker', 'skeleton', 'feedback', 'nav', 'table', 'overlay']
  .map((m) => fs.readFileSync(path.join(SRC, `platform/bootstrap/${m}.js`), 'utf8')).join('\n');

const require = createRequire(import.meta.url);
const pkg = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8'));

test('dependencias: Bootstrap 5 estable + BootstrapVueNext instalados; BootstrapVue 2 sigue hasta cubrir todas las familias', () => {
  const deps = { ...pkg.dependencies, ...pkg.devDependencies };
  assert.match(deps.bootstrap, /^\^?5\.3\./);
  assert.match(deps['bootstrap-vue-next'], /^\^?1\./);
  assert.match(deps['bootstrap-vue'], /^\^?2\./, 'BootstrapVue 2 se retira en una fase posterior, no en la fase 1');
  assert.equal(JSON.parse(fs.readFileSync(path.join(ROOT, 'node_modules/bootstrap/package.json'), 'utf8')).version.split('.')[0], '5');
});

function compileBridge() {
  const sass = require('sass');
  const vendor = path.join(SRC, 'assets/styles/vendor/bootstrap');
  const bridge = path.join(SRC, 'assets/styles/sass/bootstrap5/_bridge.scss');
  const source = [
    `@import "${path.join(SRC, 'assets/styles/sass/variables').replace(/\\/g, '/')}";`,
    `@import "${vendor.replace(/\\/g, '/')}/functions";`,
    `@import "${vendor.replace(/\\/g, '/')}/variables";`,
    `@import "${vendor.replace(/\\/g, '/')}/mixins";`,
    `@import "${bridge.replace(/\\/g, '/')}";`,
  ].join('\n');
  return sass.compileString(source, { loadPaths: [vendor, path.join(SRC, 'assets/styles/sass')], silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'mixed-decls', 'slash-div'], logger: sass.Logger.silent }).css;
}

test('puente CSS: compila con sass y declara las utilidades lógicas de BS5 (LTR y RTL a la vez)', () => {
  const css = compileBridge();
  for (const sel of ['.ms-2', '.me-3', '.ps-1', '.pe-4', '.ms-auto', '.ms-md-3', '.text-end', '.text-md-start', '.float-start', '.fw-bold', '.border-start', '.rounded-end', '.visually-hidden', '.text-bg-primary', '.btn-close']) {
    assert.ok(css.includes(`${sel} `) || css.includes(`${sel},`) || css.includes(`${sel}{`), `falta ${sel}`);
  }
  assert.match(css, /\.ms-2\s*\{[^}]*margin-inline-start:\s*0?\.5rem\s*!important/);
  assert.match(css, /\.me-3\s*\{[^}]*margin-inline-end:\s*1rem\s*!important/);
  assert.match(css, /\.text-end\s*\{[^}]*text-align:\s*end\s*!important/);
});

test('puente CSS: no pisa nombres que las vistas ya usan con estilos propios (gap-*, form-select) ni reglas de BS4', () => {
  const css = compileBridge();
  assert.doesNotMatch(css, /\.gap-\d/, '`gap-2` era un no-op en dashboard (gap propio de 0.75rem); una regla global lo cambiaría');
  assert.doesNotMatch(css, /\.form-select\b/);
  assert.doesNotMatch(css, /(^|\})\s*\.(container|card-deck|table-responsive|modal)\s*\{/m);
});

test('theme: el puente se importa después de bootstrap-rtl y antes de globals (las vistas pueden seguir sobrescribiendo)', () => {
  const theme = fs.readFileSync(path.join(SRC, 'assets/styles/sass/themes/lite-purple.scss'), 'utf8');
  const order = ['bootstrap-rtl.scss', 'bootstrap5/bridge', 'globals/globals.scss'].map((s) => theme.indexOf(s));
  assert.ok(order.every((i) => i > 0));
  assert.deepEqual([...order].sort((a, b) => a - b), order);
});

test('platform/bootstrap: envoltorios de BVN marcados MODE 3, BButton acepta `block` y BBadge conserva `badge-<variante>`', async () => {
  const src = bootstrapSource();
  assert.match(src, /compatConfig\s*[:=]\s*\{\s*MODE:\s*3/);
  assert.match(src, /btn-block/);
  assert.match(src, /badge-\$\{/);
  assert.match(fs.readFileSync(path.join(SRC, 'platform/bootstrap/plugin.js'), 'utf8'), /createBootstrap\(/);
  for (const name of ['BButton', 'BBadge', 'BAlert', 'BSpinner', 'BContainer', 'BRow', 'BCol', 'BCard']) assert.match(src, new RegExp(`export const ${name}\\b`), name);
});

test('platform/bootstrap: sin BApp ni orquestador global (toast/modal siguen en BootstrapVue 2 durante la fase 1)', () => {
  const main = fs.readFileSync(path.join(SRC, 'main.js'), 'utf8');
  assert.match(main, /bootstrapPlugin/);
  const app = fs.readFileSync(path.join(SRC, 'App.vue'), 'utf8');
  assert.doesNotMatch(app, /<b-app|<BApp/i);
});

test('BVN no se importa fuera de platform/bootstrap (las vistas usan el envoltorio, nunca bootstrap-vue-next directo)', () => {
  const offenders = [];
  const walk = (dir) => {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, e.name);
      if (e.isDirectory()) walk(full);
      else if (/\.(vue|js)$/.test(e.name) && !full.includes(`${path.sep}platform${path.sep}bootstrap${path.sep}`) && !full.includes(`${path.sep}platform${path.sep}adapters${path.sep}`)) {
        if (/from\s+['"]bootstrap-vue-next/.test(fs.readFileSync(full, 'utf8'))) offenders.push(path.relative(SRC, full));
      }
    }
  };
  walk(SRC);
  assert.deepEqual(offenders, []);
});

test('BVN CSS no se carga globalmente en la fase 1 (sus reglas de .container/.card-deck/.table-responsive rompen BS4)', () => {
  for (const f of ['main.js', 'plugins/stocky.kit.js', 'assets/styles/sass/themes/lite-purple.scss']) {
    const p = path.join(SRC, f);
    if (fs.existsSync(p)) assert.doesNotMatch(fs.readFileSync(p, 'utf8'), /bootstrap-vue-next\/dist\/bootstrap-vue-next\.css|bootstrap-vue-next.*\.css/, f);
  }
});

// Guardia de clases: en las pantallas NO críticas migradas no vuelven `ml-*`, `mr-*`, `text-left`… (rutas críticas: POS, caja,
// pagos, inventario, ventas, compras, productos, facturación/SAR, nómina… quedan para fases posteriores).
const CRITICAL = /(pos|cash|caja|payment|pago|sale|purchase|quotation|transfer|inventory|adjustment|damage|product|expense|deposit|account|billing|invoice|sar|fiscal|commission|subscription|sessions|stock|warehouse|receiv|customfields|order|checkout|register|wallet|bank|tax|currenc|payroll|_ui|accounting|settings\/system|store\/)/i;
const LEGACY = /(?<![\w-])(?:m[lr]-(?:(?:sm|md|lg|xl)-)?(?:\d|auto)\b|p[lr]-(?:(?:sm|md|lg|xl)-)?\d\b|text-(?:(?:sm|md|lg|xl)-)?(?:left|right)(?![\w-])|float-(?:(?:sm|md|lg|xl)-)?(?:left|right)(?![\w-])|font-weight-(?:bold|bolder|normal|light|lighter)(?![\w-]))/;

test('clases BS4 direccionales migradas a BS5 en pantallas no críticas', () => {
  const offenders = [];
  const walk = (dir) => {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, e.name);
      const rel = path.relative(ROOT, full).replace(/\\/g, '/');
      if (e.isDirectory()) walk(full);
      else if (/\.(vue|js)$/.test(e.name) && /^resources\/src\/(views|components|containers|portal)\//.test(rel) && !CRITICAL.test(rel)) {
        const text = fs.readFileSync(full, 'utf8');
        text.split('\n').forEach((line, i) => { if (LEGACY.test(line) && /class|Class|:class|\.[a-z]/.test(line)) offenders.push(`${rel}:${i + 1}`); });
      }
    }
  };
  walk(SRC);
  assert.ok(offenders.length <= 0, `clases BS4 sin migrar (${offenders.length}): ${offenders.slice(0, 10).join(', ')}`);
});

test('toolchain: la hoja completa de Bootstrap 5.3 compila con el sass del proyecto (base del corte final de la fase 2)', () => {
  const sass = require('sass');
  const entry = path.join(ROOT, 'node_modules/bootstrap/scss/bootstrap.scss');
  const { css } = sass.compile(entry, { loadPaths: [path.join(ROOT, 'node_modules')], silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'mixed-decls', 'slash-div'], logger: sass.Logger.silent });
  for (const sel of ['.ms-2', '.me-2', '.text-end', '.btn-close', '.form-select', '.text-bg-primary', '.visually-hidden', '.gap-2', '.offcanvas']) assert.ok(css.includes(sel), `BS5 debe declarar ${sel}`);
  assert.ok(!css.includes('.card-deck'), 'BS5 ya no tiene .card-deck (BS4 sí): son clases que hay que sustituir antes del corte');
  assert.ok(!css.includes('.custom-select'), 'BS5 ya no tiene .custom-select');
});

test('directivas propias con hooks de Vue 3 (mounted/unmounted) y CUSTOM_DIR retirado', () => {
  const offenders = [];
  const walk = (dir) => {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, e.name);
      if (e.isDirectory()) walk(full);
      else if (/\.(vue|js)$/.test(e.name) && /^\s+(bind|inserted|componentUpdated|unbind)\s*[(:]/m.test(fs.readFileSync(full, 'utf8'))) offenders.push(path.relative(SRC, full));
    }
  };
  walk(SRC);
  assert.deepEqual(offenders, []);
  const compat = fs.readFileSync(path.join(SRC, 'platform/vue-compat.js'), 'utf8').split('\n').filter((l) => !/^\s*\/\//.test(l)).join('\n');
  assert.match(compat, /CUSTOM_DIR:\s*false/, 'CUSTOM_DIR desactivado explícitamente');
});
