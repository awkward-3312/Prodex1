import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

// Bootstrap 5 + BootstrapVueNext (fase 2): modo compat por componente, contrato v-model de vee-validate con BVN, wrappers de formularios,
// clases BS5 latentes neutralizadas en pantallas críticas y servicios de plataforma en vistas no críticas.
const ROOT = path.resolve(new URL('../..', import.meta.url).pathname);
const SRC = path.join(ROOT, 'resources/src');
const read = (rel) => fs.readFileSync(path.join(SRC, rel), 'utf8');
const CRITICAL = /(pos|cash|caja|payment|pago|sale|purchase|quotation|transfer|inventory|adjustment|damage|product|expense|deposit|account|billing|invoice|sar|fiscal|commission|subscription|sessions|stock|warehouse|receiv|customfields|order|checkout|register|wallet|bank|tax|currenc|payroll|_ui|accounting|settings\/system|store\/)/i;

function walk(dir, fn) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, e.name);
    if (e.isDirectory()) walk(full, fn);
    else fn(full);
  }
}

test('compat: BootstrapVueNext (SFC con __name B…) corre en MODE 3; BootstrapVue 2 y el código propio, en MODE 2', async () => {
  const { compatModeFor, isBootstrapVueNext } = await import('../../resources/src/platform/compat/bvn-mode.js');
  assert.equal(compatModeFor({ __name: 'BFormSelectPlain', setup() {} }), 3);
  assert.equal(compatModeFor({ __name: 'BButton' }), 3);
  assert.equal(compatModeFor({ name: 'BFormSelect', model: { prop: 'value', event: 'input' } }), 2, 'BootstrapVue 2 usa `name`');
  assert.equal(compatModeFor({ name: 'PxSelect' }), 2);
  assert.equal(compatModeFor({ __name: 'Calendar' }), 2);
  assert.equal(compatModeFor({ __name: 'Breadcumb' }), 2, 'B + minúscula: SFC propio (Breadcumb), no BootstrapVueNext');
  assert.equal(compatModeFor(null), 2);
  assert.equal(compatModeFor(undefined), 2);
  assert.equal(compatModeFor(function legacyCtor() {}), 2);
  assert.equal(isBootstrapVueNext({ __name: 'BAr' }), true, 'la heurística es el prefijo B + mayúscula (documentada)');
  const compat = read('platform/vue-compat.js');
  assert.match(compat, /configureCompat\(\{\s*MODE:\s*compatModeFor,\s*CUSTOM_DIR:\s*false\s*\}\)/);
});

test('vee-validate: detecta el campo con el contrato de Vue 3 (modelValue) y conserva el de Vue 2 (value)', async () => {
  const { describeField } = await import('../../resources/src/platform/validation/vee-compat-provider.js');
  const fn = () => {};
  assert.deepEqual(describeField({ type: { __name: 'BFormInput' }, props: { modelValue: 'abc', 'onUpdate:modelValue': fn } }), { value: 'abc', event: 'update:modelValue', native: false, checkable: false });
  assert.equal(describeField({ type: {}, props: { 'onUpdate:modelValue': fn } }).event, 'update:modelValue');
  // Vue 2: `model: { prop: 'value', event: 'input' }`
  const legacy = describeField({ type: { model: { prop: 'value', event: 'input' } }, props: { value: 7 } });
  assert.deepEqual(legacy, { value: 7, event: 'input', native: false, checkable: false });
  // componente sin v-model: no es un campo
  assert.equal(describeField({ type: { name: 'PxButton' }, props: { label: 'x' } }), null);
});

test('wrappers de formularios: contrato explícito y documentado (platform/bootstrap/forms.js)', () => {
  const src = read('platform/bootstrap/forms.js');
  const index = read('platform/bootstrap/index.js');
  for (const name of ['BFormGroup', 'BFormInput', 'BFormTextarea', 'BFormSelect', 'BFormSelectOption', 'BFormCheckbox', 'BFormRadio', 'BFormInvalidFeedback']) {
    assert.match(src, new RegExp(`export const ${name}\\b`), name);
  }
  assert.match(src, /labelFor: ''/, 'BFormGroup mantiene fieldset+legend de BV2');
  assert.match(src, /'custom-select'/, 'BFormSelect emite custom-select (BS4)');
  assert.match(src, /px-bvn-check/);
  assert.match(index, /export \* from '\.\/forms\.js'/);
  assert.match(read('platform/bootstrap/overlay.js'), /export const vBTooltip/);
  // contrato de BootstrapVue 2 medido (fase 5B): los eventos entregan el VALOR, no el Event nativo
  assert.match(src, /`@input` \/ `@change` reciben el VALOR/);
  assert.match(src, /`v-model\.trim`/);
});

test('vistas migradas a formularios BVN: registro local coherente (cada b-form-* BVN está importado y registrado)', () => {
  const offenders = [];
  walk(path.join(SRC, 'views'), (file) => {
    if (!file.endsWith('.vue')) return;
    const text = fs.readFileSync(file, 'utf8');
    const m = /import\s*\{([^}]*)\}\s*from\s*["']@\/platform\/bootstrap(?:\/[a-z]+)?["']/.exec(text);
    if (!m) return;
    for (const name of m[1].split(',').map((s) => s.trim()).filter(Boolean)) {
      const directive = { vBTooltip: 'b-tooltip', vBToggle: 'b-toggle', vBPopover: 'b-popover' }[name];
      if (directive) {
        if (!new RegExp(`'${directive}':\\s*${name}`).test(text)) offenders.push(`${path.relative(SRC, file)}: ${name} importado sin registrar`);
        continue;
      }
      const registered = new RegExp(`components:\\s*\\{[^}]*\\b${name}\\b`).test(text);
      if (!registered) offenders.push(`${path.relative(SRC, file)}: ${name} importado sin registrar`);
    }
  });
  assert.deepEqual(offenders, []);
});

test('clases BS5 latentes: en pantallas críticas el puente NO las activa (neutralizadas y registradas)', () => {
  const BS5 = /(?<![A-Za-z0-9_-])(m[se]-(?:(?:sm|md|lg|xl)-)?(?:\d|auto)|p[se]-(?:(?:sm|md|lg|xl)-)?\d|text-(?:(?:sm|md|lg|xl)-)?(?:start|end)|float-(?:(?:sm|md|lg|xl)-)?(?:start|end)|fw-(?:bold|bolder|normal|light|lighter|semibold))(?![A-Za-z0-9_-])/;
  const recorded = JSON.parse(fs.readFileSync(path.join(ROOT, 'tests/frontend/latent-bs5-neutralized.json'), 'utf8'));
  assert.ok(recorded.length > 60, 'registro de clases neutralizadas');
  const files = [...new Set(recorded.map((r) => r.file))];
  for (const f of files) {
    assert.match(f, CRITICAL, `${f} debe ser una pantalla crítica`);
    const lines = fs.readFileSync(path.join(SRC, f), 'utf8').split('\n');
    const bad = lines.map((l, i) => (BS5.test(l) ? `${f}:${i + 1}` : null)).filter(Boolean);
    assert.deepEqual(bad, [], `clases BS5 en ${f}: al migrar la pantalla se reponen desde tests/frontend/latent-bs5-neutralized.json`);
  }
});

// Fase 4: `$bvToast` / `$bvModal` a 0 en TODO el código propio (vistas, componentes, contenedores, mixins, utilidades), incluidos POS, caja,
// pagos, inventario, transferencias, ajustes, mermas y ventas/compras. Todo pasa por los servicios de plataforma.
test('servicios de plataforma: ningún archivo propio usa $bvToast / $bvModal (ni en script ni en plantilla)', () => {
  const offenders = [];
  for (const dir of ['views', 'components', 'containers', 'mixins', 'utils', 'layouts', 'store', 'routes']) {
    if (!fs.existsSync(path.join(SRC, dir))) continue;
    walk(path.join(SRC, dir), (file) => {
      if (!/\.(vue|js)$/.test(file)) return;
      const rel = path.relative(SRC, file).replace(/\\/g, '/');
      const text = fs.readFileSync(file, 'utf8').replace(/<!--[\s\S]*?-->/g, '').split('\n').filter((l) => !/^\s*(\/\/|\*|\/\*)/.test(l)).join('\n');
      if (/\$bv(Toast|Modal)\b/.test(text)) offenders.push(rel);
    });
  }
  assert.deepEqual(offenders, []);
});

test('CUSTOM_DIR eliminado: vue-select y vue2-daterange-picker usan wrappers con directivas de Vue 3 (sin parchear node_modules)', () => {
  const dir = read('platform/directives/append-to-body.js');
  assert.match(dir, /mounted:/);
  assert.match(dir, /unmounted:/);
  assert.doesNotMatch(dir.split('\n').filter((l) => !/^\s*\/\//.test(l)).join('\n'), /\b(inserted|unbind|componentUpdated)\b/);
  assert.match(read('platform/compat/vue-select.js'), /appendToBody:\s*vSelectAppendToBody/);
  assert.match(read('platform/compat/daterange-picker.js'), /appendToBody:\s*daterangeAppendToBody/);
  assert.match(read('main.js'), /import vSelect from '\.\/platform\/compat\/vue-select\.js'/);
  assert.match(fs.readFileSync(path.join(ROOT, 'webpack.mix.js'), 'utf8'), /'vue2-daterange-picker\$'/);
});

test('sin claves duplicadas `components` / `directives` en el objeto de opciones de una vista (la última pisaría a la primera)', () => {
  const offenders = [];
  walk(path.join(SRC, 'views'), (file) => {
    if (!file.endsWith('.vue')) return;
    const script = /<script[^>]*>([\s\S]*?)<\/script>/.exec(fs.readFileSync(file, 'utf8'));
    if (!script) return;
    for (const key of ['components', 'directives']) {
      const n = (script[1].match(new RegExp(`(?<![\\w.'"])${key}\\s*:\\s*\\{`, 'g')) || []).length;
      if (n > 1) offenders.push(`${path.relative(SRC, file)}: ${key} x${n}`);
    }
  });
  assert.deepEqual(offenders, []);
});

test('patrones que en la fase 2 eran blockers (v-model.trim, :value, switch, multiple, @input) los resuelve el wrapper (fase 5B), no cada vista', () => {
  const forms = read('platform/bootstrap/forms.js');
  // .trim: el modelo se recorta en cada evento y el campo conserva el texto que escribe el usuario (BVN recortaba el DOM al perder el foco)
  assert.match(forms, /const trim = !!\(modelModifiers && modelModifiers\.trim\)/);
  assert.match(forms, /const \{ trim: _trim, \.\.\.others \} = modelModifiers/, 'el modificador `trim` no llega a BVN');
  // `:value` sin v-model, `@input`/`@change` con el valor (no el Event), `unchecked-value` = false
  assert.match(forms, /props\.modelValue === undefined && value !== undefined/);
  assert.match(forms, /uncheckedValue: false/);
  assert.match(forms, /nextTick\(\(\) => toList\(onChange\)/, 'change en el siguiente tick, con el modelo ya actualizado');
  // ninguna vista trae ya su propia traducción de esos patrones
  const offenders = [];
  walk(path.join(SRC, 'views'), (file) => {
    if (file.endsWith('.vue') && /BvProbe|model-value=.*\.trim/.test(fs.readFileSync(file, 'utf8'))) offenders.push(path.relative(SRC, file));
  });
  assert.deepEqual(offenders.filter((f) => !f.includes('_ui')), []);
});
