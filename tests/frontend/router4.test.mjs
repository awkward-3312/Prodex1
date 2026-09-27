import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const SRC = path.resolve(new URL('../../resources/src', import.meta.url).pathname);
const SNAPSHOT = require('../e2e/routes/routes.snapshot.json');

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
const read = (f) => fs.readFileSync(f, 'utf8');

test('todas las rutas del snapshot (tenant y portal) son válidas para el matcher de Vue Router 4', () => {
  const { createRouterMatcher } = require('vue-router');
  for (const key of ['tenant', 'portal']) {
    const matcher = createRouterMatcher([], {});
    for (const [i, record] of SNAPSHOT[key].entries()) {
      assert.doesNotThrow(() => matcher.addRoute({ path: record.path, component: {}, name: record.name ? `${key}:${record.name}:${i}` : undefined }), `${key}: ${record.path}`);
    }
  }
});

test('el catch-all y not_authorize usan la sintaxis de Router 4 con las mismas URLs', () => {
  const byName = (n) => SNAPSHOT.tenant.find((r) => r.name === n);
  assert.equal(byName('NotFound').path, '/:pathMatch(.*)*');
  assert.equal(byName('not_authorize').path, '/not_authorize');
  assert.equal(SNAPSHOT.tenant.filter((r) => r.path === '*').length, 0);
});

test('ningún <router-link> usa `tag`, `event`, `append`, `exact` ni `.native` (retirados en Router 4)', () => {
  const offenders = [];
  for (const f of files.filter((x) => x.endsWith('.vue'))) {
    const text = read(f);
    for (const m of text.matchAll(/<router-link\b/g)) {
      let i = m.index + 12;
      let quote = null;
      for (; i < text.length; i += 1) {
        const c = text[i];
        if (quote) { if (c === quote) quote = null; } else if (c === '"' || c === "'") quote = c;
        else if (c === '>') break;
      }
      const tag = text.slice(m.index, i + 1);
      if (/\s(:?tag|:?event|:?append|:?exact)=|@[\w:-]+\.native\b/.test(tag)) offenders.push(`${rel(f)}: ${tag.replace(/\s+/g, ' ').slice(0, 90)}`);
    }
  }
  assert.deepEqual(offenders, []);
});

test('no quedan APIs de vue-router 3 en el código (new Router, mode, addRoutes, Router.prototype, path "*")', () => {
  const offenders = [];
  for (const f of files) {
    const text = read(f);
    if (/new Router\(|new VueRouter\(|Router\.prototype|\.addRoutes\(|\bmode:\s*["']history["']/.test(text)) offenders.push(rel(f));
    if (/path:\s*["']\*["']/.test(text)) offenders.push(`${rel(f)} (catch-all "*")`);
  }
  assert.deepEqual(offenders, []);
});

test('vue-router solo se importa en los dos routers y no existe el adaptador de router-link', () => {
  const importers = files.filter((f) => /from\s+['"]vue-router['"]/.test(read(f))).map(rel).sort();
  assert.deepEqual(importers, ['portal/router.js', 'router.js']);
  assert.equal(fs.existsSync(path.join(SRC, 'platform/compat/router-link.js')), false);
});

test('los guards devuelven la redirección (no usan next) y el router usa createWebHistory', () => {
  const router = read(path.join(SRC, 'router.js'));
  const code = router.split('\n').filter((l) => !/^\s*\/\//.test(l)).join('\n');
  assert.doesNotMatch(code, /\bnext\(/);
  assert.match(router, /createWebHistory\(\)/);
  assert.match(read(path.join(SRC, 'portal/router.js')), /createWebHistory\('\/portal'\)/);
});
