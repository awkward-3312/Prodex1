import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(new URL('../..', import.meta.url).pathname);
const read = (p) => fs.readFileSync(path.join(ROOT, p), 'utf8');
const ENTRYPOINTS = ['resources/src/main.js', 'resources/src/login.js', 'resources/src/customer-display.js'];

// Guardia contra reintroducir vue-i18n 8: el paquete instalado debe ser la 11.x, y ningún entrypoint debe volver al
// patrón `Vue.use(VueI18n)` + `new VueI18n({...})` (mixin global de clase de Vue 2, origen fuerte de los avisos
// OPTIONS_BEFORE_DESTROY/PRIVATE_APIS de @vue/compat antes de esta fase).
test('vue-i18n: paquete 11.x, sin `Vue.use(VueI18n)` en ningún entrypoint', () => {
  const pkg = JSON.parse(read('package.json'));
  const deps = { ...pkg.dependencies, ...pkg.devDependencies };
  assert.match(deps['vue-i18n'], /^\^?11\./, 'vue-i18n debe fijarse en 11.x');
  ENTRYPOINTS.forEach((f) => {
    const src = read(f);
    assert.doesNotMatch(src, /Vue\.use\(VueI18n\)/, `${f}: sin Vue.use(VueI18n) (API de la 8)`);
    assert.doesNotMatch(src, /new VueI18n\(/, `${f}: sin \`new VueI18n(...)\` (API de la 8)`);
    assert.doesNotMatch(src, /from ['"]vue-i18n['"]/, `${f}: no debe importar vue-i18n directamente (usa el loader compartido)`);
  });
});

test('vue-i18n: el loader usa createI18n (composición de la 11), modo legacy para no reescribir los $t() existentes', () => {
  const loader = read('resources/src/plugins/i18n.loader.js');
  assert.match(loader, /import\s*\{[^}]*\bcreateI18n\b[^}]*\}\s*from\s*['"]vue-i18n['"]/);
  assert.match(loader, /createI18n\(/);
  assert.match(loader, /legacy:\s*true/, 'legacy:true preserva $t/$tc/$te/$d/$n/$i18n en cada instancia sin useI18n()');
  assert.doesNotMatch(loader, /new VueI18n\(/, 'sin el constructor de clase de la 8');
});

// vue-i18n 9+ ya no se instala pasando `i18n` como opción raíz de `new Vue({...})` (truco de mixin de la 8): se
// instala explícitamente con `app.use(i18n)` sobre la app real de Vue 3. Los entrypoints deben pasarlo como plugin,
// no como opción raíz, o el mixin de `legacy:true` nunca se registra y $t/$i18n quedan undefined en producción.
test('vue-i18n: los entrypoints instalan i18n con app.use (plugin), no como opción raíz de `new Vue({ i18n })`', () => {
  ENTRYPOINTS.forEach((f) => {
    const src = read(f);
    assert.doesNotMatch(src, /new Vue\(\{\s*i18n[,:]/, `${f}: \`i18n\` no debe pasarse como opción raíz de \`new Vue({...})\` (patrón de la 8)`);
  });
  const main = read('resources/src/main.js');
  assert.match(main, /mountWithRouter\([\s\S]*?\[[^\]]*\bi18n\b[^\]]*\]\)/, 'main.js: i18n va en el array de plugins de mountWithRouter');
  const login = read('resources/src/login.js');
  assert.match(login, /mountWithRouter\([\s\S]*?\[[^\]]*\bi18n\b[^\]]*\]\)/, 'login.js: i18n va en el array de plugins de mountWithRouter');
  const cd = read('resources/src/customer-display.js');
  assert.match(cd, /\.appContext\.app\.use\(i18n\)/, 'customer-display.js: instala i18n explícitamente sobre la app real (no usa mountWithRouter)');
});

test('vue-i18n: el bundle de producción no referencia el paquete "vue-i18n@8"', () => {
  const lock = read('package-lock.json');
  assert.doesNotMatch(lock, /"vue-i18n":\s*"8\./);
  assert.doesNotMatch(lock, /"resolved":\s*"https:\/\/registry\.npmjs\.org\/vue-i18n\/-\/vue-i18n-8\./);
});
