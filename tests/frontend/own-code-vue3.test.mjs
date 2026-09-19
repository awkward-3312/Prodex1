import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

// Guardia: el código PROPIO de PRODEX no vuelve a usar APIs de Vue 2 que no existen en Vue 3 puro. Las dependencias externas
// (BootstrapVue, vee-validate, vue-meta…) no se escanean: viven en node_modules.
const SRC = path.resolve(new URL('../../resources/src', import.meta.url).pathname);

function walk(dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, e.name);
    if (e.isDirectory()) walk(full, out);
    else if (/\.(vue|js)$/.test(e.name)) out.push(full);
  }
  return out;
}
const files = walk(SRC);
const rel = (f) => path.relative(SRC, f);

// Quita comentarios de línea (// y * de bloque) y HTML para que las notas históricas no cuenten como uso.
function code(file) {
  return fs
    .readFileSync(file, 'utf8')
    .split('\n')
    .filter((l) => !/^\s*(\/\/|\*|\/\*|<!--)/.test(l))
    .join('\n');
}

const RULES = [
  ['$listeners', /\$listeners/],
  ['$scopedSlots', /\$scopedSlots/],
  ['beforeDestroy', /\bbeforeDestroy\b/],
  ['destroyed', /^\s*destroyed\s*[(:]/m],
  ['Vue.util', /Vue\.util\b/],
  ['functional: true', /\bfunctional:\s*true/],
  ['render(h)', /\brender\s*\(\s*h\b|render:\s*function\s*\(\s*h\b|render:\s*\(\s*h\s*\)\s*=>\s*h\(['"`]/],
  ['$children', /\$children/],
  ['_uid (API privada)', /\b(this\._uid|\{\{\s*_uid|\+_uid\+)/],
  ['hook: events', /['"`]hook:/],
];

// Excepciones documentadas: (regla, archivo) que dependen de una API de compat/plataforma y no son código de vista.
const ALLOWED = new Set([
  'render(h)|main.js', // raíz de la app: `render: h => h(App)` (montaje de compat; desaparece con createApp)
  'render(h)|login.js',
  'render(h)|portal.js',
]);

for (const [name, re] of RULES) {
  test(`código propio sin ${name}`, () => {
    const offenders = files
      .filter((f) => re.test(code(f)))
      .map(rel)
      .filter((f) => !ALLOWED.has(`${name}|${f}`));
    assert.deepEqual(offenders, []);
  });
}

test('los Px* migrados declaran la config de compat que aplica Vue 3 a los listeners', () => {
  for (const f of ['components/px-next/PxButton.vue', 'components/px-next/PxInput.vue', 'components/px-next/PxCheck.vue', 'components/px-next/PxTextarea.vue', 'views/app/products/next/edit/VsPx.vue']) {
    assert.match(fs.readFileSync(path.join(SRC, f), 'utf8'), /INSTANCE_LISTENERS:\s*false/, f);
  }
});

test('forwardListeners solo reenvía listeners (onXxx) y forwardPlainAttrs el resto sin class/style', async () => {
  const { forwardListeners, forwardPlainAttrs } = await import('../../resources/src/utils/forwardListeners.js');
  const attrs = { onClick() {}, onKeyup() {}, class: 'a', style: 'b', 'data-x': '1', maxlength: 5, online: true };
  assert.deepEqual(Object.keys(forwardListeners(attrs)), ['onClick', 'onKeyup']);
  assert.deepEqual(Object.keys(forwardPlainAttrs(attrs)).sort(), ['data-x', 'maxlength', 'online']);
  assert.deepEqual(forwardListeners(undefined), {});
});
