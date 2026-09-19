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
  assert.match(compat, /configureCompat\(\{\s*MODE:\s*compatModeFor,\s*CUSTOM_DIR:\s*true\s*\}\)/);
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

test('wrappers de formularios: contrato explícito y documentado (platform/bootstrap)', () => {
  const src = read('platform/bootstrap/index.js');
  for (const name of ['BFormGroup', 'BFormInput', 'BFormTextarea', 'BFormSelect', 'BFormSelectOption', 'BFormCheckbox', 'BFormRadio', 'BFormInvalidFeedback']) {
    assert.match(src, new RegExp(`export const ${name}\\b`), name);
  }
  assert.match(src, /labelFor: ''/, 'BFormGroup mantiene fieldset+legend de BV2');
  assert.match(src, /'custom-select'/, 'BFormSelect emite custom-select (BS4)');
  assert.match(src, /px-bvn-check/);
  assert.match(src, /export const vBTooltip/);
  // documentación del cambio de contrato en la propia cabecera
  assert.match(src, /`@input` \/ `@change`.*eventos NATIVOS/s);
});

test('vistas migradas a formularios BVN: registro local coherente (cada b-form-* BVN está importado y registrado)', () => {
  const offenders = [];
  walk(path.join(SRC, 'views'), (file) => {
    if (!file.endsWith('.vue')) return;
    const text = fs.readFileSync(file, 'utf8');
    const m = /import\s*\{([^}]*)\}\s*from\s*["']@\/platform\/bootstrap["']/.exec(text);
    if (!m) return;
    for (const name of m[1].split(',').map((s) => s.trim()).filter(Boolean)) {
      if (name === 'vBTooltip') {
        if (!/'b-tooltip':\s*vBTooltip/.test(text)) offenders.push(`${path.relative(SRC, file)}: vBTooltip importado sin registrar`);
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

test('servicios de plataforma: las vistas no críticas ya no llaman a this.$bvToast.toast ni a $bvModal.show/hide desde el script', () => {
  const offenders = [];
  walk(path.join(SRC, 'views'), (file) => {
    const rel = path.relative(SRC, file).replace(/\\/g, '/');
    if (!file.endsWith('.vue') || CRITICAL.test(rel)) return;
    const text = fs.readFileSync(file, 'utf8');
    const script = /<script[^>]*>([\s\S]*?)<\/script>/.exec(text);
    if (script && /\$(bvToast\.toast|bvModal\.(show|hide))\(/.test(script[1])) offenders.push(rel);
  });
  assert.deepEqual(offenders, []);
});

test('CUSTOM_DIR: las directivas propias usan hooks de Vue 3 y los consumidores restantes son de terceros (documentados)', () => {
  const compat = read('platform/vue-compat.js');
  assert.match(compat, /vue-select\/src\/directives\/appendToBody\.js/);
  assert.match(compat, /vue2-daterange-picker\/src\/directives\/appendToBody\.js/);
  for (const pkg of ['vue-select', 'vue2-daterange-picker']) {
    const file = path.join(ROOT, 'node_modules', pkg, 'src/directives/appendToBody.js');
    if (fs.existsSync(file)) assert.match(fs.readFileSync(file, 'utf8'), /\binserted\s*\(/, `${pkg} sigue usando hooks de Vue 2`);
  }
});

test('patrones de BootstrapVue 2 que NO se migran a BVN (blockers documentados): v-model.trim, :value/:checked, @input en select, switch, multiple', () => {
  const tag = (name) => new RegExp(`<${name}(?=[\\s>/])((?:"[^"]*"|'[^']*'|[^>"'])*)>`, 'g');
  const offenders = [];
  walk(path.join(SRC, 'views'), (file) => {
    if (!file.endsWith('.vue')) return;
    const text = fs.readFileSync(file, 'utf8');
    const m = /import\s*\{([^}]*)\}\s*from\s*["']@\/platform\/bootstrap["']/.exec(text);
    if (!m) return;
    const bvn = new Set(m[1].split(',').map((s) => s.trim()));
    const tpl = /<template>([\s\S]*)<\/template>/.exec(text);
    const body = tpl ? tpl[1] : text;
    const rel = path.relative(SRC, file);
    const check = (comp, name, rx, why) => {
      if (!bvn.has(comp)) return;
      for (const t of body.matchAll(tag(name))) if (rx.test(t[1])) offenders.push(`${rel}: <${name}> ${why}`);
    };
    for (const [comp, name] of [['BFormInput', 'b-form-input'], ['BFormTextarea', 'b-form-textarea'], ['BFormSelect', 'b-form-select'], ['BFormCheckbox', 'b-form-checkbox'], ['BFormRadio', 'b-form-radio']]) {
      check(comp, name, /v-model\.[a-z.]*trim/, 'v-model.trim (BVN recorta al perder el foco, no al escribir)');
      check(comp, name, /(?<![\w:-]):value=|v-bind:value/, ':value (usar :model-value)');
    }
    check('BFormCheckbox', 'b-form-checkbox', /(?<![\w-])switch(?![\w-])|:checked/, 'switch/:checked (sin CSS de custom-switch en BS4)');
    check('BFormSelect', 'b-form-select', /@input|(?<![\w-])multiple/, '@input/multiple');
  });
  assert.deepEqual(offenders, []);
});
