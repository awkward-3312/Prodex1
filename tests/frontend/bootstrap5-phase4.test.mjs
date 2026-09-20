import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

// Fase 4: modales y tablas de BootstrapVue 2 migrados a los wrappers de platform/bootstrap (BModal / BTable / BTableSimple…).
const ROOT = path.resolve(new URL('../..', import.meta.url).pathname);
const SRC = path.join(ROOT, 'resources/src');

function walk(dir, fn) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, e.name);
    if (e.isDirectory()) walk(full, fn);
    else fn(full);
  }
}

const vueFiles = () => {
  const out = [];
  walk(path.join(SRC, 'views'), (f) => { if (f.endsWith('.vue')) out.push(f); });
  return out;
};
const rel = (f) => path.relative(SRC, f).replace(/\\/g, '/');
const parts = (text) => {
  const script = /<script[^>]*>([\s\S]*?)<\/script>/.exec(text);
  return { template: text.replace(/<script[\s\S]*?<\/script>/g, '').replace(/<style[\s\S]*?<\/style>/g, ''), script: script ? script[1] : '' };
};
const importsFromPlatform = (script, name) => {
  const m = /import\s*\{([^}]*)\}\s*from\s*["']@\/platform\/bootstrap["']/g;
  let x;
  while ((x = m.exec(script))) if (x[1].split(',').map((s) => s.trim()).includes(name)) return true;
  return false;
};
const registers = (script, name) => new RegExp(`components:\\s*\\{[^}]*\\b${name}\\b`).test(script);

const FAMILIES = [
  ['b-modal', ['BModal']],
  ['b-table', ['BTable']],
  ['b-table-simple', ['BTableSimple', 'BThead', 'BTbody', 'BTr', 'BTh', 'BTd']],
];

test('modales y tablas: cada vista que usa <b-modal> / <b-table> / <b-table-simple> importa y registra el wrapper de platform/bootstrap (no el global de BootstrapVue 2)', () => {
  const offenders = [];
  for (const file of vueFiles()) {
    const { template, script } = parts(fs.readFileSync(file, 'utf8'));
    for (const [tag, names] of FAMILIES) {
      if (!new RegExp(`<${tag}[\\s>]`).test(template)) continue;
      for (const name of names) {
        if (tag === 'b-table-simple' && !new RegExp(`<${name.replace(/^B/, 'b-').toLowerCase()}[\\s>]`).test(template) && name !== 'BTableSimple') continue;
        if (!importsFromPlatform(script, name) || !registers(script, name)) offenders.push(`${rel(file)}: ${name}`);
      }
    }
  }
  assert.deepEqual(offenders, []);
});

test('sin restos de BootstrapVue 2 para modales: ni eventos bv::show/hide::modal, ni v-b-modal, ni msgBox*', () => {
  const offenders = [];
  for (const dir of ['views', 'components', 'containers', 'mixins', 'utils', 'platform']) {
    if (!fs.existsSync(path.join(SRC, dir))) continue;
    walk(path.join(SRC, dir), (f) => {
      if (!/\.(vue|js)$/.test(f)) return;
      const text = fs.readFileSync(f, 'utf8').split('\n').filter((l) => !/^\s*(\/\/|\*|\/\*)/.test(l)).join('\n');
      if (/bv::(show|hide)::modal|v-b-modal|\bmsgBox(Confirm|Ok)\b/.test(text)) offenders.push(rel(f));
    });
  }
  assert.deepEqual(offenders, []);
});

test('el driver de modales resuelve por el registro de BootstrapVueNext (sin fallback a la raíz de BootstrapVue 2)', () => {
  const src = fs.readFileSync(path.join(SRC, 'platform/adapters/bvn.js'), 'utf8');
  assert.match(src, /modal\.get\(id\)/);
  assert.doesNotMatch(src.split('\n').filter((l) => !/^\s*(\/\/|\*|\/\*)/.test(l)).join('\n'), /\$emit\(/);
});

test('BModal conserva el ciclo de vida de BootstrapVue 2 (lazy + unmountLazy) y traduce hide-footer/hide-header/hide-header-close/static', () => {
  const src = fs.readFileSync(path.join(SRC, 'platform/bootstrap/index.js'), 'utf8');
  const block = src.slice(src.indexOf('const MODAL_RENAMED'), src.indexOf('// Tabla (fase 4)'));
  assert.match(block, /lazy: true, unmountLazy: true/);
  for (const [from, to] of [['hide-footer', 'noFooter'], ['hide-header', 'noHeader'], ['hide-header-close', 'noHeaderClose'], ['static', 'teleportDisabled']]) {
    assert.match(block, new RegExp(`['"]?${from}['"]?:\\s*'${to}'`));
  }
  assert.match(block, /expose\(\{[^}]*show[^}]*hide[^}]*toggle/s);
});

test('BTable traduce head-variant light/dark a la clase thead-* de BS4', () => {
  const src = fs.readFileSync(path.join(SRC, 'platform/bootstrap/index.js'), 'utf8');
  assert.match(src, /`thead-\$\{variant\}`/);
});
