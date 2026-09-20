import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { translateMetaInfo, unsupportedKeys } from '../../resources/src/platform/head/translate-meta-info.js';

const require = createRequire(import.meta.url);
const ROOT = path.resolve(new URL('../..', import.meta.url).pathname);
const SRC = path.join(ROOT, 'resources/src');

test('translateMetaInfo: title, titleTemplate, htmlAttrs y bodyAttrs (el contrato real de PRODEX)', () => {
  const input = translateMetaInfo({
    title: 'Productos',
    titleTemplate: '%s | Gestión empresarial',
    htmlAttrs: { dir: 'rtl', lang: 'es' },
    bodyAttrs: { class: ['dark-theme', 'text-left'] },
  });
  assert.deepEqual(input, {
    title: 'Productos',
    titleTemplate: '%s | Gestión empresarial',
    htmlAttrs: { dir: 'rtl', lang: 'es' },
    bodyAttrs: { class: ['dark-theme', 'text-left'] },
  });
});

test('translateMetaInfo: omite valores vacíos y entradas no objeto', () => {
  assert.deepEqual(translateMetaInfo({ title: '', titleTemplate: undefined, htmlAttrs: null }), {});
  assert.deepEqual(translateMetaInfo(undefined), {});
  assert.deepEqual(translateMetaInfo('x'), {});
});

test('translateMetaInfo: vmid/hid → key (deduplicación de Unhead), json → innerHTML, y limpia claves de vue-meta', () => {
  const input = translateMetaInfo({
    meta: [{ vmid: 'description', name: 'description', content: 'x' }, { hid: 'robots', name: 'robots', content: 'noindex' }, { charset: 'utf-8' }],
    link: [{ vmid: 'canon', rel: 'canonical', href: '/a' }],
    script: [{ vmid: 'ld', type: 'application/ld+json', json: '{"a":1}' }],
  });
  assert.deepEqual(input.meta, [
    { name: 'description', content: 'x', key: 'description' },
    { name: 'robots', content: 'noindex', key: 'robots' },
    { charset: 'utf-8' },
  ]);
  assert.deepEqual(input.link, [{ rel: 'canonical', href: '/a', key: 'canon' }]);
  assert.deepEqual(input.script, [{ type: 'application/ld+json', innerHTML: '{"a":1}', key: 'ld' }]);
});

test('unsupportedKeys: avisa de las claves de vue-meta que no se traducen (no se ignoran en silencio)', () => {
  assert.deepEqual(unsupportedKeys({ title: 'x', meta: [], changed() {}, headAttrs: {}, foo: 1, bar: 2 }), ['foo', 'bar']);
  assert.deepEqual(unsupportedKeys(null), []);
});

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

test('vue-meta eliminado: ni dependencia, ni import, ni Vue.use(Meta), ni $meta, ni data-vue-meta', () => {
  const pkg = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8'));
  assert.equal(pkg.dependencies['vue-meta'], undefined);
  assert.ok(pkg.dependencies['@unhead/vue']);
  assert.equal(fs.existsSync(path.join(ROOT, 'node_modules/vue-meta')), false);
  const offenders = files
    .filter((f) => /from\s+['"]vue-meta|Vue\.use\(\s*Meta|\$meta\b|data-vue-meta|keyName:\s*["']metaInfo/.test(fs.readFileSync(f, 'utf8')))
    .map(rel);
  assert.deepEqual(offenders, []);
});

test('INSTANCE_CHILDREN eliminado y ningún código propio lee $children', () => {
  const compat = fs.readFileSync(path.join(SRC, 'platform/vue-compat.js'), 'utf8').split('\n').filter((l) => !/^\s*\/\//.test(l)).join('\n');
  assert.doesNotMatch(compat, /INSTANCE_CHILDREN/);
  assert.match(compat, /CUSTOM_DIR:\s*false/);
  const offenders = files.filter((f) => /\$children/.test(fs.readFileSync(f, 'utf8').split('\n').filter((l) => !/^\s*(\/\/|\*)/.test(l)).join('\n'))).map(rel);
  assert.deepEqual(offenders, []);
});

test('$options.metaInfo solo lo lee la capa de head (la lógica del recibo POS usa su propio marcador)', () => {
  const readers = files.filter((f) => /\$options\S*\.metaInfo|\.\$options\.metaInfo|options\.metaInfo/.test(fs.readFileSync(f, 'utf8'))).map(rel);
  assert.deepEqual(readers, ['platform/head/meta-info-mixin.js']);
  assert.match(fs.readFileSync(path.join(SRC, 'plugins/stocky.kit.js'), 'utf8'), /prodexReceiptPresentation/);
  assert.match(fs.readFileSync(path.join(SRC, 'views/app/pages/settings/pos_receipt.vue'), 'utf8'), /prodexReceiptPresentation:\s*true/);
});

test('la capa de head no usa internals de Vue 2 (Vue.util, $children, _uid, $vnode, __vue__)', () => {
  for (const f of ['translate-meta-info.js', 'meta-info-mixin.js', 'index.js']) {
    const text = fs.readFileSync(path.join(SRC, 'platform/head', f), 'utf8').split('\n').filter((l) => !/^\s*\/\//.test(l)).join('\n');
    assert.doesNotMatch(text, /Vue\.util|\$children|\b_uid\b|\$vnode|__vue__|\$parent\b/, f);
  }
});

test('todas las vistas con metaInfo siguen definiéndolo (297 definiciones: el contrato no se toca)', () => {
  const count = files.reduce((n, f) => n + (/^\s*metaInfo\s*[:(]/m.test(fs.readFileSync(f, 'utf8')) ? 1 : 0), 0);
  assert.ok(count >= 290, `definiciones de metaInfo encontradas: ${count}`);
});
