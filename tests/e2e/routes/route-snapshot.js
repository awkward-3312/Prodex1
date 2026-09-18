#!/usr/bin/env node
/**
 * Snapshot de rutas del SPA, generado desde el CÓDIGO REAL (AST), no mantenido a mano.
 *
 *   node tests/e2e/routes/route-snapshot.js --check    # compara contra routes.snapshot.json (falla si difiere)
 *   node tests/e2e/routes/route-snapshot.js --update   # regenera el snapshot (cambio intencional de rutas)
 *
 * Fuentes: resources/src/router.js (rutas base + bloque solo-desarrollo), resources/src/main.js (`router.addRoutes`)
 * y resources/src/portal/router.js. Protege: path completo, name, redirect, componente (import), props, beforeEnter,
 * claves de meta, alias, y si la ruta solo existe fuera de producción. No requiere servidor ni base de datos.
 *
 * Usa @babel/parser y @babel/traverse, que llegan como dependencias de laravel-mix (@babel/core). Si en una fase
 * futura se sustituye Mix/Babel, fijar ambos paquetes explícitamente en devDependencies.
 */
const fs = require('fs');
const path = require('path');
const parser = require('@babel/parser');
const traverse = require('@babel/traverse').default;

const ROOT = path.resolve(__dirname, '..', '..', '..');
const SNAPSHOT = path.join(__dirname, 'routes.snapshot.json');

const SOURCES = [
  { key: 'tenant', file: 'resources/src/router.js' },
  { key: 'tenant', file: 'resources/src/main.js' },
  { key: 'portal', file: 'resources/src/portal/router.js' },
];

const propName = (p) => (p.key && (p.key.name || p.key.value)) || null;
const getProp = (obj, name) => obj.properties.find((p) => p.type === 'ObjectProperty' && propName(p) === name);
const str = (node) => (node && node.type === 'StringLiteral' ? node.value : node && node.type === 'TemplateLiteral' && node.expressions.length === 0 ? node.quasis[0].value.cooked : null);

function describeComponent(src, node) {
  if (!node) return null;
  const text = src.slice(node.start, node.end);
  const m = text.match(/import\(\s*(?:\/\*[\s\S]*?\*\/\s*)?["'`]([^"'`]+)["'`]/);
  if (m) return m[1];
  return `eager:${text.replace(/\s+/g, ' ').slice(0, 60)}`;
}

function describeValue(src, node) {
  if (!node) return null;
  const s = str(node);
  if (s !== null) return s;
  return src.slice(node.start, node.end).replace(/\s+/g, ' ').slice(0, 80);
}

function isRouteObject(node) {
  if (node.type !== 'ObjectExpression') return false;
  const p = getProp(node, 'path');
  if (!p || str(p.value) === null) return false;
  return ['component', 'redirect', 'children', 'components', 'name'].some((k) => getProp(node, k));
}

function extract(file) {
  const src = fs.readFileSync(path.join(ROOT, file), 'utf8');
  const ast = parser.parse(src, { sourceType: 'module', plugins: ['dynamicImport', 'optionalChaining', 'nullishCoalescingOperator'] });
  const routes = [];

  traverse(ast, {
    ObjectExpression(p) {
      if (!isRouteObject(p.node)) return;

      // Ruta padre (si este objeto es elemento de un `children: [...]`).
      const chain = [];
      let cur = p;
      while (cur) {
        const arr = cur.parentPath;
        const prop = arr && arr.parentPath;
        const owner = prop && prop.parentPath;
        if (arr && arr.isArrayExpression() && prop.isObjectProperty() && propName(prop.node) === 'children' && owner && isRouteObject(owner.node)) {
          chain.unshift(str(getProp(owner.node, 'path').value));
          cur = owner;
        } else {
          break;
        }
      }
      let full = '';
      for (const seg of [...chain, str(getProp(p.node, 'path').value)]) {
        if (seg.startsWith('/')) full = seg;
        else full = `${full.replace(/\/$/, '')}/${seg}`.replace(/^\/\//, '/');
      }
      if (chain.length === 0) full = str(getProp(p.node, 'path').value);

      const devOnly = !!p.findParent((a) => a.isIfStatement() && /NODE_ENV\s*!==\s*["']production["']/.test(src.slice(a.node.test.start, a.node.test.end)));
      const meta = getProp(p.node, 'meta');
      const entry = { path: full, name: str((getProp(p.node, 'name') || {}).value) };
      const redirect = getProp(p.node, 'redirect');
      if (redirect) entry.redirect = describeValue(src, redirect.value);
      const component = getProp(p.node, 'component');
      if (component) entry.component = describeComponent(src, component.value);
      const alias = getProp(p.node, 'alias');
      if (alias) entry.alias = describeValue(src, alias.value);
      const props = getProp(p.node, 'props');
      if (props) entry.props = describeValue(src, props.value);
      const guard = getProp(p.node, 'beforeEnter');
      if (guard) entry.beforeEnter = describeValue(src, guard.value);
      if (meta && meta.value.type === 'ObjectExpression') entry.metaKeys = meta.value.properties.map(propName).sort();
      const children = getProp(p.node, 'children');
      if (children && children.value.type === 'ArrayExpression') entry.children = children.value.elements.length;
      if (devOnly) entry.devOnly = true;
      entry.source = file;
      routes.push(entry);
    },
  });
  return routes;
}

function build() {
  const out = { tenant: [], portal: [] };
  for (const s of SOURCES) out[s.key].push(...extract(s.file));
  const pathsOf = (list) => list.filter((r) => !r.devOnly).length;
  return {
    _readme: 'Generado por tests/e2e/routes/route-snapshot.js desde el código fuente. No editar a mano: npm run test:e2e:routes:update',
    counts: {
      tenantRecords: out.tenant.length,
      tenantProduction: pathsOf(out.tenant),
      tenantNamed: out.tenant.filter((r) => r.name).length,
      tenantRedirects: out.tenant.filter((r) => r.redirect).length,
      portalRecords: out.portal.length,
    },
    tenant: out.tenant,
    portal: out.portal,
  };
}

function key(r) { return `${r.path}\u0000${r.name || ''}\u0000${r.source}`; }

function diff(prev, next) {
  const lines = [];
  for (const section of ['tenant', 'portal']) {
    const a = new Map(prev[section].map((r) => [key(r), r]));
    const b = new Map(next[section].map((r) => [key(r), r]));
    for (const [k, r] of a) if (!b.has(k)) lines.push(`- [${section}] ELIMINADA/RENOMBRADA  ${r.path}${r.name ? `  (${r.name})` : ''}`);
    for (const [k, r] of b) if (!a.has(k)) lines.push(`+ [${section}] NUEVA                 ${r.path}${r.name ? `  (${r.name})` : ''}`);
    for (const [k, r] of b) {
      if (a.has(k) && JSON.stringify(a.get(k)) !== JSON.stringify(r)) {
        const old = a.get(k);
        const fields = Object.keys({ ...old, ...r }).filter((f) => JSON.stringify(old[f]) !== JSON.stringify(r[f]));
        lines.push(`~ [${section}] CAMBIADA              ${r.path}${r.name ? `  (${r.name})` : ''}  campos: ${fields.join(', ')}`);
      }
    }
  }
  if (!lines.length && JSON.stringify(prev.counts) !== JSON.stringify(next.counts)) lines.push('~ cambió el orden o los conteos');
  if (!lines.length && JSON.stringify(prev) !== JSON.stringify(next)) lines.push('~ cambió el orden de las rutas (el orden importa en vue-router)');
  return lines;
}

const mode = process.argv.includes('--update') ? 'update' : 'check';
const next = build();

if (mode === 'update') {
  fs.writeFileSync(SNAPSHOT, `${JSON.stringify(next, null, 2)}\n`);
  console.log(`Snapshot actualizado: ${next.counts.tenantRecords} rutas tenant (${next.counts.tenantProduction} en producción), ${next.counts.portalRecords} portal.`);
  process.exit(0);
}

if (!fs.existsSync(SNAPSHOT)) {
  console.error('No existe routes.snapshot.json. Ejecuta: npm run test:e2e:routes:update');
  process.exit(2);
}
const prev = JSON.parse(fs.readFileSync(SNAPSHOT, 'utf8'));
const changes = diff(prev, next);
if (changes.length) {
  console.error(`El mapa de rutas cambió respecto al snapshot (${changes.length} diferencias):`);
  changes.slice(0, 60).forEach((l) => console.error(`  ${l}`));
  if (changes.length > 60) console.error(`  … y ${changes.length - 60} más`);
  console.error('\nSi el cambio es intencional: npm run test:e2e:routes:update y revisa el diff del snapshot en el PR.');
  process.exit(1);
}
console.log(`OK: ${next.counts.tenantRecords} rutas tenant (${next.counts.tenantProduction} en producción, ${next.counts.tenantNamed} con nombre, ${next.counts.tenantRedirects} redirects) y ${next.counts.portalRecords} de portal coinciden con el snapshot.`);
