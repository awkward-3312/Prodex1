import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

// Corte final a Bootstrap 5 (fase 5C): la hoja activa es Bootstrap 5.3, BootstrapVue 2 no existe (paquete, CSS, imports, registro global, parches ni
// servicios `$bv*`), el puente aditivo de la fase 1 y la hoja de Bootstrap 4 desaparecieron y las clases retiradas de BS4 no reaparecen.
const ROOT = path.resolve(new URL('../..', import.meta.url).pathname);
const SRC = path.join(ROOT, 'resources/src');
const STYLES = path.join(SRC, 'assets/styles');
const require = createRequire(import.meta.url);
const pkg = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8'));
const lock = fs.readFileSync(path.join(ROOT, 'package-lock.json'), 'utf8');
const read = (file) => fs.readFileSync(file, 'utf8');
const bootstrapSource = () => ['index', 'core', 'layout', 'buttons', 'forms', 'form-text', 'form-choice', 'primitives', 'file', 'datepicker', 'skeleton', 'feedback', 'nav', 'table', 'overlay']
  .map((m) => read(path.join(SRC, `platform/bootstrap/${m}.js`))).join('\n');

function walk(dir, fn, skip = () => false) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, e.name);
    if (e.name === 'node_modules' || skip(full)) continue;
    if (e.isDirectory()) walk(full, fn, skip);
    else fn(full);
  }
}

test('dependencias: Bootstrap 5.3 + BootstrapVueNext; el paquete `bootstrap-vue` (BV2) no existe', () => {
  const deps = { ...pkg.dependencies, ...pkg.devDependencies };
  assert.match(deps.bootstrap, /^\^?5\.3\./);
  assert.match(deps['bootstrap-vue-next'], /^\^?1\./);
  assert.equal(deps['bootstrap-vue'], undefined, 'bootstrap-vue eliminado de package.json');
  assert.doesNotMatch(lock, /"node_modules\/bootstrap-vue"/, 'bootstrap-vue eliminado de package-lock.json');
  assert.equal(fs.existsSync(path.join(ROOT, 'node_modules/bootstrap-vue')), false, 'npm ci no instala bootstrap-vue');
});

test('ningún archivo del proyecto importa BootstrapVue 2 ni su CSS, ni lo registra, ni usa sus servicios', () => {
  const offenders = [];
  const importBv2 = /(?:from\s+|import\s*\(|require\(\s*|@import\s+)['"~]*bootstrap-vue(?!-next)(?:['"/.]|$)/;
  const noComments = (text) => text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
  const scan = (file, app) => {
    if (!/\.(vue|js|mjs|scss|css)$/.test(file)) return;
    const rel = path.relative(ROOT, file);
    const text = read(file);
    if (importBv2.test(noComments(text))) offenders.push(`${rel}: importa bootstrap-vue`);
    if (!app) return; // los tests (y este archivo) citan estos nombres para comprobar que NO existen
    const code = noComments(text);
    if (/Vue\.use\(\s*BootstrapVue\b|BootstrapVueRemaining|patchBootstrapVueForCompat/.test(code)) offenders.push(`${rel}: registro/parche de BootstrapVue 2`);
    if (/\$bvToast|\$bvModal|\$bv\./.test(code)) offenders.push(`${rel}: servicio $bv*`);
  };
  walk(path.join(ROOT, 'resources/src'), (f) => scan(f, true));
  walk(path.join(ROOT, 'resources/static'), (f) => scan(f, true));
  walk(path.join(ROOT, 'tests'), (f) => scan(f, false), (f) => f.includes(`${path.sep}.artifacts`) || f.endsWith('bootstrap5-cutover.test.mjs'));
  scan(path.join(ROOT, 'webpack.mix.js'), true);
  assert.deepEqual(offenders, []);
  for (const f of ['platform/compat/bootstrap-vue.js', 'platform/compat/bootstrap-vue-forms.js']) assert.equal(fs.existsSync(path.join(SRC, f)), false, f);
});

test('hoja activa: Bootstrap 5 (`~bootstrap/scss/bootstrap`); sin Bootstrap 4, bootstrap-rtl, bootstrap-vue.css ni el puente de la fase 1', () => {
  const theme = read(path.join(STYLES, 'sass/themes/lite-purple.scss'));
  assert.match(theme, /@import\s+"bootstrap5"/);
  assert.match(read(path.join(STYLES, 'sass/themes/_bootstrap5.scss')), /@import\s+"~bootstrap\/scss\/bootstrap"/);
  assert.doesNotMatch(theme.replace(/\/\/.*$/gm, ''), /bootstrap-vue|bootstrap-rtl|bootstrap5\/bridge|vendor\/bootstrap|bootstrap\.scss/);
  assert.match(theme, /@import\s+"\.\.\/prodex-bootstrap-compat"/);
  for (const gone of ['vendor/bootstrap', 'vendor/bootstrap-rtl', 'sass/bootstrap-rtl.scss', 'sass/bootstrap5', 'sass/themes/dark-purple.scss', 'sass/themes/lite-blue.scss']) {
    assert.equal(fs.existsSync(path.join(STYLES, gone)), false, `${gone} debe haberse eliminado`);
  }
  // Lo que queda de la era Bootstrap 4 es SOLO la API SCSS (funciones, variables y mixins: no emite CSS).
  const api = fs.readdirSync(path.join(STYLES, 'sass/bs4-api')).sort();
  assert.deepEqual(api, ['_functions.scss', '_mixins.scss', '_variables.scss', 'mixins']);
});

test('CSS compilado: Bootstrap 5 activo (variables --bs-*, .form-select, .form-check-input…) y ninguna regla de Bootstrap 4 ni de bootstrap-vue.css', () => {
  const sass = require('sass');
  const tilde = { findFileUrl: (url) => (url.startsWith('~') ? new URL(`file://${ROOT}/node_modules/${url.slice(1)}`) : null) };
  const { css } = sass.compile(path.join(STYLES, 'sass/themes/lite-purple.scss'), {
    importers: [tilde], loadPaths: [path.join(ROOT, 'node_modules')], quietDeps: true,
    silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'mixed-decls', 'slash-div', 'legacy-js-api', 'abs-percent', 'if-function'], logger: sass.Logger.silent,
  });
  for (const marker of ['--bs-body-font-family', '.form-select', '.form-check-input', '.btn-close', '.visually-hidden', '.ms-2', '.text-end', '.offcanvas', '.input-group-text']) {
    assert.ok(css.includes(marker), `Bootstrap 5 debe declarar ${marker}`);
  }
  // Reglas que solo existían en Bootstrap 4 / BootstrapVue 2
  const legacy = [/\.custom-control\s*\{/, /\.custom-select\s*\{/, /\.custom-checkbox\b/, /\.custom-radio\b/, /\.input-group-prepend\s*\{/, /\.input-group-append\s*\{/, /\.form-row\s*\{/,
    /\.close\s*\{/, /\.btn-block\s*\{/, /\.card-deck\b/, /\.no-gutters\s*\{/, /\.jumbotron\s*\{/, /\.thead-light\b/, /\.b-avatar\b/, /\.b-form-spinbutton\b/, /\.b-form-tags\b/, /\.b-rating\b/, /\.b-toast\b/];
  const found = legacy.filter((rx) => rx.test(css)).map(String);
  assert.deepEqual(found, [], 'reglas heredadas de Bootstrap 4 / BootstrapVue 2 en la hoja compilada');
  // El CSS propio que sustituye a bootstrap-vue.css sí está (datepicker, archivo, marcador de carga)
  for (const own of ['.b-calendar', '.b-form-btn-label-control', '.custom-file-label', '.b-skeleton', '.badge-primary', '.form-group']) assert.ok(css.includes(own), `compat PRODEX: ${own}`);
});

test('compat de PRODEX: cada bloque copiado de bootstrap-vue.css está clasificado y no hay CSS de componentes que la aplicación no usa', () => {
  const compat = read(path.join(STYLES, 'sass/compat/_bv-components.scss'));
  for (const section of ['utilidad requerida', 'datepicker', 'archivo', 'marcador de carga']) assert.match(compat, new RegExp(section));
  for (const dead of ['b-avatar', 'b-form-spinbutton', 'b-form-tags', 'b-rating', 'b-popover', 'b-toast', 'b-table-stacked', 'b-time', 'b-table-selectable']) assert.doesNotMatch(compat.replace(/\/\/.*$/gm, ''), new RegExp(dead), `CSS muerto: ${dead}`);
});

test('wrappers: contrato de Bootstrap 5 (`d-block w-100`, `rounded-pill`, `form-select`, hijos directos en `.input-group`), sin clases de BS4', () => {
  const src = bootstrapSource();
  assert.match(src, /d-block w-100/);
  assert.match(src, /rounded-pill/);
  assert.match(src, /compatConfig\s*[:=]\s*\{\s*MODE:\s*3/);
  assert.match(fs.readFileSync(path.join(SRC, 'platform/bootstrap/plugin.js'), 'utf8'), /createBootstrap\(/);
  const code = src.split('\n').filter((l) => !/^\s*(\/\/|\*)/.test(l)).join('\n');
  for (const old of ['btn-block', 'badge-pill', 'custom-select', 'custom-control', 'btn-group-toggle', 'no-gutters', 'input-group-prepend', 'input-group-append', 'thead-light', 'thead-dark']) {
    assert.doesNotMatch(code, new RegExp(`['"\`]${old}`), `wrapper con clase de Bootstrap 4: ${old}`);
  }
  assert.match(code, /`badge-\$\{props\.variant\}`/, '`badge-<variante>` es un contrato propio de PRODEX (prodex-bootstrap-compat)');
});

// Clases de Bootstrap 4 que Bootstrap 5 retiró o renombró: no deben volver a las plantillas, a las columnas de vue-good-table ni al CSS propio.
const REMOVED = ['(?:m[lr]|p[lr])-(?:(?:sm|md|lg|xl)-)?(?:\\d|auto)', 'text-(?:(?:sm|md|lg|xl)-)?(?:left|right)', 'float-(?:(?:sm|md|lg|xl)-)?(?:left|right)', 'font-weight-(?:bold|bolder|normal|light|lighter)',
  'font-italic', 'badge-pill', 'sr-only', 'btn-block', 'no-gutters', 'custom-select(?:-(?:sm|lg))?', 'custom-control(?:-[a-z]+)*', 'custom-(?:checkbox|radio|switch|range)', 'input-group-(?:prepend|append)',
  'thead-(?:light|dark)', 'border-(?:left|right)(?:-0)?', 'dropdown-menu-(?:left|right)', 'form-row', 'card-deck', 'card-columns', 'jumbotron', 'btn-group-toggle', 'embed-responsive(?:-[a-z0-9]+)?', 'media(?:-body)?'];
const removedToken = new RegExp(`(?<![A-Za-z0-9_-])(?:${REMOVED.join('|')})(?![A-Za-z0-9_-])`);

test('clases retiradas de Bootstrap 4: ninguna plantilla (`class` / `:class`), columna de vue-good-table ni selector de CSS propio las usa', () => {
  const offenders = [];
  const classTokens = (tpl) => {
    const out = [];
    for (const m of tpl.matchAll(/(?<![:\w@-])class\s*=\s*(?:"([^"]*)"|'([^']*)')/g)) out.push(...(m[1] ?? m[2]).split(/\s+/));
    for (const m of tpl.matchAll(/:class\s*=\s*"([^"]*)"/g)) {
      for (const s of m[1].matchAll(/['`]([A-Za-z0-9_ :-]+)['`]/g)) out.push(...s[1].split(/\s+/));
      for (const k of m[1].matchAll(/(?<![\w'"-])([a-z][a-z0-9-]*)\s*:/g)) out.push(k[1]);
    }
    return out;
  };
  walk(path.join(SRC), (file) => {
    if (!/\.vue$/.test(file)) return;
    const rel = path.relative(SRC, file);
    const text = read(file);
    const tpl = /<template>[\s\S]*<\/template>/.exec(text);
    if (tpl) for (const t of classTokens(tpl[0])) if (removedToken.test(t) && new RegExp(`^(?:${REMOVED.join('|')})$`).test(t)) offenders.push(`${rel}: class "${t}"`);
    for (const m of text.matchAll(/\b(?:tdClass|thClass)\s*:\s*["']([^"']*)["']/g)) if (m[1].split(/\s+/).some((t) => new RegExp(`^(?:${REMOVED.join('|')})$`).test(t))) offenders.push(`${rel}: ${m[0]}`);
    for (const block of text.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)) {
      for (const sel of block[1].matchAll(/\.((?:${'x'})?[A-Za-z][A-Za-z0-9_-]*)/g)) if (new RegExp(`^(?:${REMOVED.join('|')})$`).test(sel[1])) offenders.push(`${rel}: selector .${sel[1]}`);
    }
  });
  walk(path.join(STYLES, 'sass'), (file) => {
    if (!file.endsWith('.scss') || file.includes(`${path.sep}bs4-api${path.sep}`) || file.includes(`${path.sep}compat${path.sep}`)) return;
    for (const sel of read(file).replace(/\/\/.*$/gm, '').matchAll(/\.([A-Za-z][A-Za-z0-9_-]*)/g)) {
      if (new RegExp(`^(?:${REMOVED.join('|')})$`).test(sel[1])) offenders.push(`${path.relative(SRC, file)}: selector .${sel[1]}`);
    }
  });
  assert.deepEqual(offenders, [], `clases de Bootstrap 4 retiradas (${offenders.length})`);
});

test('BVN no se importa fuera de platform/bootstrap y su CSS no se carga (la hoja es Bootstrap 5 + compat PRODEX)', () => {
  const offenders = [];
  walk(SRC, (full) => {
    if (!/\.(vue|js)$/.test(full) || full.includes(`${path.sep}platform${path.sep}bootstrap${path.sep}`) || full.includes(`${path.sep}platform${path.sep}adapters${path.sep}`)) return;
    if (/from\s+['"]bootstrap-vue-next/.test(read(full))) offenders.push(path.relative(SRC, full));
  });
  assert.deepEqual(offenders, []);
  for (const f of ['main.js', 'plugins/stocky.kit.js', 'assets/styles/sass/themes/lite-purple.scss']) assert.doesNotMatch(read(path.join(SRC, f)), /bootstrap-vue-next.*\.css/, f);
});

test('Bootstrap JS nativo no se carga (BootstrapVueNext implementa modales, desplegables, tooltips y offcanvas)', () => {
  const offenders = [];
  walk(SRC, (full) => {
    if (!/\.(vue|js)$/.test(full)) return;
    if (/from\s+['"]bootstrap(?:\/js\/dist[^'"]*|\/dist\/js[^'"]*)?['"]|require\(\s*['"]bootstrap['"]\s*\)|@popperjs\/core|bootstrap\.bundle/.test(read(full))) offenders.push(path.relative(SRC, full));
  });
  assert.deepEqual(offenders, []);
  const deps = { ...pkg.dependencies, ...pkg.devDependencies };
  assert.equal(deps['@popperjs/core'], undefined);
  assert.equal(deps.jquery, undefined);
});

test('directivas propias con hooks de Vue 3 (mounted/unmounted) y CUSTOM_DIR retirado', () => {
  const offenders = [];
  walk(SRC, (full) => {
    if (/\.(vue|js)$/.test(full) && /^\s+(bind|inserted|componentUpdated|unbind)\s*[(:]/m.test(read(full))) offenders.push(path.relative(SRC, full));
  });
  assert.deepEqual(offenders, []);
  const compat = read(path.join(SRC, 'platform/vue-compat.js')).split('\n').filter((l) => !/^\s*\/\//.test(l)).join('\n');
  assert.match(compat, /CUSTOM_DIR:\s*false/, 'CUSTOM_DIR desactivado explícitamente');
});

test('reglas RTL de Bootstrap 5: el archivo generado (postcss-rtlcss) está al día y cubre las utilidades y componentes usados', async () => {
  const { generate, OUTPUT } = await import('../../scripts/generate-bs5-rtl.mjs');
  const generated = await generate();
  assert.equal(read(OUTPUT), generated, 'compat/_bs5-rtl.generated.scss desactualizado: ejecuta `node scripts/generate-bs5-rtl.mjs`');
  for (const sel of ['.ms-2', '.me-2', '.ps-3', '.pe-3', '.text-end', '.text-start', '.float-end', '.float-start', '.form-select', '.form-check', '.dropdown-menu-end', '.input-group', '.btn-group', '.modal-header', '.alert-dismissible']) {
    assert.ok(generated.includes(`[dir="rtl"] ${sel}`), `RTL de ${sel}`);
  }
  assert.match(read(path.join(STYLES, 'sass/prodex-bootstrap-compat.scss')), /compat\/bs5-rtl\.generated/);
});
