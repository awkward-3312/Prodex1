#!/usr/bin/env node
/**
 * Inventario exacto (por AST, no por regex) de las etiquetas `<b-*>` de los SFC de resources/src y de las tablas `vue-good-table`.
 * Una etiqueta es de BootstrapVueNext si su archivo importa el wrapper con ese nombre de `@/platform/bootstrap` (registro local); si no, es de
 * BootstrapVue 2 (registro global). Uso:  node tests/frontend/inventory/bootstrap-inventory.mjs [--json] [raíz]
 */
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { parse: parseSfc } = require('@vue/compiler-sfc');
const { parse: parseTemplate, NodeTypes } = require('@vue/compiler-dom');

const args = process.argv.slice(2);
const json = args.includes('--json');
const DEFAULT_ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../../..');

const pascal = (tag) => tag.split('-').map((p) => p[0].toUpperCase() + p.slice(1)).join('');
const walkFiles = (dir, out = []) => {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, e.name);
    if (e.isDirectory()) walkFiles(full, out);
    else if (e.name.endsWith('.vue')) out.push(full);
  }
  return out;
};

// Familias (por prefijo de etiqueta) para las métricas de la fase.
const FAMILIES = [
  ['form', /^b-(form|input-group)/],
  ['layout', /^b-(row|col|container|card|card-\w+|card-group|media|jumbotron)$/],
  ['button', /^b-button(-group|-toolbar)?$/],
  ['primitive', /^b-(badge|alert|spinner|progress|progress-bar|link|img|image|avatar|skeleton\w*|close-button)$/],
  ['tabs', /^b-tabs?$/],
  ['dropdown', /^b-dropdown(-\w+)?$/],
  ['pagination', /^b-pagination(-nav)?$/],
  ['collapse-nav', /^b-(collapse|nav|nav-\w+|navbar\w*|list-group\w*|breadcrumb\w*)$/],
  ['table', /^b-(table\w*|thead|tbody|tfoot|tr|th|td)$/],
  ['modal', /^b-modal$/],
];
const familyOf = (tag) => (FAMILIES.find(([, rx]) => rx.test(tag)) || ['other'])[0];

const importedNames = (script) => {
  const names = new Set();
  const rx = /import\s*\{([^}]*)\}\s*from\s*["']@\/platform\/bootstrap(?:\/[a-z-]+)?["']/g;
  let m;
  while ((m = rx.exec(script))) m[1].split(',').map((s) => s.trim().split(/\s+as\s+/).pop()).filter(Boolean).forEach((n) => names.add(n));
  return names;
};

export function collectInventory(ROOT = DEFAULT_ROOT) {
const SRC = path.join(ROOT, 'resources/src');
const attrUse = {}; // etiqueta BV2 → { atributo/evento/slot: nº }
const result = { tags: { bv2: 0, bvn: 0 }, files: { bv2: 0, bvn: 0, total: 0 }, families: {}, byTag: {}, bv2Files: [], vgt: { files: 0, tables: 0, features: {}, list: [] } };
const VGT_FEATURES = {
  'mode remote (server)': (a) => a.mode === 'remote',
  'pagination': (a) => 'pagination-options' in a || 'paginationOptions' in a,
  'search': (a) => 'search-options' in a || 'searchOptions' in a,
  'sort (@on-sort-change)': (a) => 'on-sort-change' in a,
  'sort-options': (a) => 'sort-options' in a,
  'page change (@on-page-change)': (a) => 'on-page-change' in a,
  'per page change (@on-per-page-change)': (a) => 'on-per-page-change' in a,
  'selection (select-options)': (a) => 'select-options' in a,
  'grouping (group-options)': (a) => 'group-options' in a,
  'row click (@on-row-click)': (a) => 'on-row-click' in a,
  'row style class': (a) => 'row-style-class' in a,
  'expandable groups (group-options collapsable)': (a) => typeof a['group-options'] === 'string' && /collapsable/.test(a['group-options']),
  'fixed header / max-height': (a) => 'fixed-header' in a || 'max-height' in a,
  'theme': (a) => 'theme' in a,
  'line numbers': (a) => 'line-numbers' in a,
  'compact mode': (a) => 'compact-mode' in a,
  'rtl': (a) => 'rtl' in a,
};
const SLOTS = ['table-row', 'table-column', 'table-actions', 'table-actions-bottom', 'selected-row-actions', 'emptystate', 'loadingContent', 'column-filter'];

for (const file of walkFiles(SRC).sort()) {
  const rel = path.relative(SRC, file).replace(/\\/g, '/');
  const { descriptor } = parseSfc(fs.readFileSync(file, 'utf8'), { filename: file });
  const tpl = descriptor.template && descriptor.template.content;
  if (!tpl) continue;
  const script = [descriptor.script && descriptor.script.content, descriptor.scriptSetup && descriptor.scriptSetup.content].filter(Boolean).join('\n');
  const local = importedNames(script);
  const ast = parseTemplate(tpl, { comments: false });
  let bv2 = 0;
  let bvn = 0;
  const visit = (node) => {
    if (!node || node.type !== NodeTypes.ELEMENT) { if (node && node.children) node.children.forEach(visit); return; }
    const tag = node.tag;
    if (/^b-[a-z]/.test(tag)) {
      const isBvn = local.has(pascal(tag));
      const fam = familyOf(tag);
      const slot = isBvn ? 'bvn' : 'bv2';
      result.tags[slot] += 1;
      isBvn ? (bvn += 1) : (bv2 += 1);
      result.families[fam] = result.families[fam] || { bv2: 0, bvn: 0 };
      result.families[fam][slot] += 1;
      result.byTag[tag] = result.byTag[tag] || { bv2: 0, bvn: 0 };
      result.byTag[tag][slot] += 1;
      if (!isBvn) {
        const u = (attrUse[tag] = attrUse[tag] || {});
        const bump = (k) => { u[k] = (u[k] || 0) + 1; };
        for (const p of node.props) {
          if (p.type === NodeTypes.ATTRIBUTE) bump(p.name);
          else if (p.type === NodeTypes.DIRECTIVE) {
            const arg = p.arg && p.arg.type === NodeTypes.SIMPLE_EXPRESSION ? p.arg.content : p.arg ? '[dyn]' : '';
            bump(p.name === 'model' ? `v-model${(p.modifiers || []).map((m) => '.' + (m.content || m)).join('')}${arg ? ':' + arg : ''}` : p.name === 'bind' ? `:${arg || '(obj)'}` : p.name === 'on' ? `@${arg}` : p.name === 'slot' ? `#${arg || 'default'}` : `v-${p.name}${arg ? ':' + arg : ''}`);
          }
        }
      }
    }
    if (tag === 'vue-good-table') {
      const attrs = {};
      for (const p of node.props) {
        if (p.type === NodeTypes.ATTRIBUTE) attrs[p.name] = p.value ? p.value.content : true;
        else if (p.type === NodeTypes.DIRECTIVE) {
          const arg = p.arg && p.arg.content;
          if (p.name === 'on' && arg) attrs[arg] = true;
          else if (p.name === 'bind' && arg) attrs[arg] = p.exp ? p.exp.content : true;
          else if (p.name === 'slot' && arg) attrs[`#${arg}`] = true;
        }
      }
      const slots = [];
      const collect = (n) => {
        if (!n || !n.children) return;
        for (const c of n.children) {
          if (c.type === NodeTypes.ELEMENT && c.tag === 'template') for (const p of c.props) if (p.type === NodeTypes.DIRECTIVE && p.name === 'slot' && p.arg) slots.push(p.arg.content);
          collect(c);
        }
      };
      // solo los <template #slot> hijos directos del componente
      for (const c of node.children) if (c.type === NodeTypes.ELEMENT && c.tag === 'template') for (const p of c.props) if (p.type === NodeTypes.DIRECTIVE && p.name === 'slot' && p.arg && p.arg.content) slots.push(p.arg.content);
      const feats = Object.entries(VGT_FEATURES).filter(([, f]) => f(attrs)).map(([n]) => n);
      for (const s of new Set(slots)) if (SLOTS.includes(s)) feats.push(`slot #${s}`);
      if (attrs.mode === 'remote') attrs.__remote = true;
      result.vgt.tables += 1;
      result.vgt.list.push({ file: rel, features: feats });
      feats.forEach((f) => { result.vgt.features[f] = (result.vgt.features[f] || 0) + 1; });
    }
    node.children.forEach(visit);
  };
  visit(ast);
  result.files.total += 1;
  if (bv2) { result.files.bv2 += 1; result.bv2Files.push([rel, bv2]); }
  if (bvn) result.files.bvn += 1;
}
result.attrs = attrUse;
result.vgt.files = new Set(result.vgt.list.map((t) => t.file)).size;

return result;
}

const isMain = process.argv[1] && path.resolve(process.argv[1]) === new URL(import.meta.url).pathname;
if (isMain) {
const result = collectInventory(path.resolve(args.find((a) => !a.startsWith('--')) || DEFAULT_ROOT));
if (args.includes('--vgt')) {
  fs.writeFileSync(path.join(DEFAULT_ROOT, 'tests/frontend/inventory/vue-good-table.json'), JSON.stringify(result.vgt, null, 1) + '\n');
  console.log(`vue-good-table.json: ${result.vgt.tables} tablas en ${result.vgt.files} archivos`);
} else if (json) {
  process.stdout.write(JSON.stringify(result, null, 1));
} else {
  console.log(`<b-*> BV2 ${result.tags.bv2} (${result.files.bv2} archivos) · BVN ${result.tags.bvn} (${result.files.bvn} archivos)`);
  for (const [f, v] of Object.entries(result.families).sort()) console.log(`  ${f.padEnd(13)} BV2 ${String(v.bv2).padStart(5)}  BVN ${String(v.bvn).padStart(5)}`);
  console.log(`vue-good-table: ${result.vgt.tables} tablas en ${result.vgt.files} archivos`);
  for (const [f, n] of Object.entries(result.vgt.features).sort((a, b) => b[1] - a[1])) console.log(`  ${String(n).padStart(3)}  ${f}`);
}
}
