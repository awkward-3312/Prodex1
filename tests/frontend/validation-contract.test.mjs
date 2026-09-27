import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { validateForm, resetForm, setFormErrors, submitForm } from '../../resources/src/platform/validation/contract.js';

test('validateForm: resuelve el booleano del observer', async () => {
  assert.equal(await validateForm({ validate: async () => true }), true);
  assert.equal(await validateForm({ validate: () => Promise.resolve(false) }), false);
  assert.equal(await validateForm({ validate: () => 1 }), true);
});

test('validateForm: sin observer resuelve el valor por defecto sin lanzar', async () => {
  assert.equal(await validateForm(undefined), true);
  assert.equal(await validateForm(null, false), false);
  assert.equal(await validateForm({}), true);
});

test('resetForm: llama a reset y tolera observer ausente', () => {
  let calls = 0;
  resetForm({ reset: () => calls++ });
  resetForm(undefined);
  resetForm({});
  assert.equal(calls, 1);
});

test('setFormErrors: normaliza a arrays y devuelve si aplicó', () => {
  const seen = [];
  const observer = { setErrors: (e) => seen.push(e) };
  assert.equal(setFormErrors(observer, { name: 'Ya existe', code: ['a', 'b'] }), true);
  assert.deepEqual(seen, [{ name: ['Ya existe'], code: ['a', 'b'] }]);
  assert.equal(setFormErrors(observer, null), false);
  assert.equal(setFormErrors(undefined, { a: 'x' }), false);
  assert.equal(seen.length, 1);
});

test('submitForm: solo ejecuta onValid si el formulario es válido', async () => {
  let ran = 0;
  assert.equal(await submitForm({ validate: async () => false }, () => ran++), undefined);
  assert.equal(ran, 0);
  assert.equal(await submitForm({ validate: async () => true }, () => { ran++; return 'ok'; }), 'ok');
  assert.equal(ran, 1);
});

// Guardia: vee-validate solo se importa en el adaptador; las vistas dependen de los componentes PRODEX.
const SRC = path.resolve(new URL('../../resources/src', import.meta.url).pathname);
function walk(dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, e.name);
    if (e.isDirectory()) walk(full, out);
    else if (/\.(vue|js)$/.test(e.name)) out.push(full);
  }
  return out;
}

test('vee-validate se importa únicamente desde platform/validation/vee-adapter.js', () => {
  const importers = walk(SRC)
    .filter((f) => /from\s+['"]vee-validate|require\(['"]vee-validate/.test(fs.readFileSync(f, 'utf8')))
    .map((f) => path.relative(SRC, f));
  assert.deepEqual(importers, ['platform/validation/vee-adapter.js']);
});

// Las pantallas migradas (todo salvo POS, caja, pagos, inventario crítico y facturación) no vuelven al nombre antiguo.
const LEGACY_ALLOWED = new Set(fs.readFileSync(new URL('./validation-legacy-allowlist.txt', import.meta.url), 'utf8').split('\n').filter(Boolean));
test('las etiquetas <validation-provider|observer> solo quedan en las pantallas de la lista de excepciones', () => {
  const offenders = walk(SRC)
    .filter((f) => f.endsWith('.vue'))
    .filter((f) => /<validation-(provider|observer)\b/.test(fs.readFileSync(f, 'utf8')))
    .map((f) => path.relative(SRC, f))
    .filter((f) => !LEGACY_ALLOWED.has(f));
  assert.deepEqual(offenders, []);
});
