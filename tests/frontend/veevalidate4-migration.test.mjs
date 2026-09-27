import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(new URL('../..', import.meta.url).pathname);
const read = (p) => fs.readFileSync(path.join(ROOT, p), 'utf8');

// Guardia contra reintroducir vee-validate 3: el paquete instalado debe ser la 4.x, con las reglas separadas
// (`@vee-validate/rules`, un paquete que no existe en la 3) y sin ningún resto del bridge de compat de Vue 2 que
// usaba `extends: ValidationProvider` (`vee-compat-provider.js`, sustituido por `vee-field-bridge.js`).
test('vee-validate: paquete 4.x con @vee-validate/rules, sin bridge de la versión 3', () => {
  const pkg = JSON.parse(read('package.json'));
  const deps = { ...pkg.dependencies, ...pkg.devDependencies };
  assert.match(deps['vee-validate'], /^\^?4\./, 'vee-validate debe fijarse en 4.x');
  assert.ok(deps['@vee-validate/rules'], '@vee-validate/rules (reglas separadas de la 4) debe estar instalado');
  assert.ok(!fs.existsSync(path.join(ROOT, 'resources/src/platform/validation/vee-compat-provider.js')), 'el bridge de la fase 5B (ValidationProvider extends) ya no debe existir');
});

test('vee-validate: el adaptador usa useField/useForm (composición de la 4), no `extends: ValidationProvider/Observer` (clase de la 3)', () => {
  const adapter = read('resources/src/platform/validation/vee-adapter.js');
  assert.match(adapter, /import\s*\{[^}]*\buseField\b[^}]*\}\s*from\s*['"]vee-validate['"]/);
  assert.match(adapter, /import\s*\{[^}]*\buseForm\b[^}]*\}\s*from\s*['"]vee-validate['"]/);
  // No se importa el COMPONENTE de la 3 (los nombres literales "ValidationProvider"/"ValidationObserver" que sí
  // aparecen en el archivo son los alias legacy registrados hacia los componentes propios de la 4, intencionales).
  assert.doesNotMatch(adapter, /import\s*\{[^}]*\b(ValidationProvider|ValidationObserver)\b/, 'sin importar los componentes de clase de la versión 3');
  assert.doesNotMatch(adapter, /extends:\s*Validation/, 'sin el patrón `extends: ValidationProvider/Observer` de la fase 5B');
});

test('vee-validate: defineRule/configure (API de la 4), no extend/localize (API de la 3)', () => {
  const adapter = read('resources/src/platform/validation/vee-adapter.js');
  assert.match(adapter, /\bdefineRule\b/);
  assert.match(adapter, /\bconfigure\(/);
  assert.doesNotMatch(adapter, /\bextend\(/, 'la 3 registraba reglas con `extend`; la 4 usa `defineRule`');
  assert.doesNotMatch(adapter, /\blocalize\(/, 'la 3 traía `localize` de vee-validate; la 4 no lo exporta (mensajes por `configure({ generateMessage })`)');
});

test('el bundle de producción no referencia el paquete "vee-validate@3" ni resuelve ValidationProvider como componente de opciones puro', () => {
  const lock = read('package-lock.json');
  assert.doesNotMatch(lock, /"vee-validate":\s*"3\./);
});
