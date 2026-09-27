import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const VUE_MAJOR = Number(require('vue/package.json').version.split('.')[0]);
// Vue 2 usa vue-template-compiler; Vue 3 (@vue/compat) usa el compilador de SFC de Vue 3 (vue-template-compiler exige `vue` 2).
const v2 = VUE_MAJOR === 2 ? require('vue-template-compiler') : null;
const v3 = VUE_MAJOR >= 3 ? require('@vue/compiler-sfc') : null;

const ROOT = path.resolve(new URL('../../resources/src', import.meta.url).pathname);

function walk(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, out);
    else if (entry.name.endsWith('.vue')) out.push(full);
  }
  return out;
}

function legacySlotUsesV3(node, found = []) {
  if (!node || typeof node !== 'object') return found;
  if (node.type === 1) {
    for (const prop of node.props || []) {
      if (prop.type === 6 && (prop.name === 'slot' || prop.name === 'slot-scope')) found.push({ tag: node.tag, attr: prop.name });
    }
  }
  for (const child of node.children || []) legacySlotUsesV3(child, found);
  for (const branch of node.branches || []) legacySlotUsesV3(branch, found);
  return found;
}

function legacySlotUses(el, found = []) {
  if (!el || el.type !== 1) return found;
  const map = el.attrsMap || {};
  if ('slot-scope' in map || 'scope' in map && el.tag === 'template') found.push({ tag: el.tag, attr: 'slot-scope' });
  if ('slot' in map) found.push({ tag: el.tag, attr: 'slot' });
  for (const child of el.children || []) legacySlotUses(child, found);
  for (const cond of el.ifConditions || []) if (cond.block !== el) legacySlotUses(cond.block, found);
  for (const key of Object.keys(el.scopedSlots || {})) legacySlotUses(el.scopedSlots[key], found);
  return found;
}

test('ningún .vue usa slot="..." ni slot-scope (solo v-slot / #)', () => {
  const offenders = [];
  for (const file of walk(ROOT)) {
    const source = fs.readFileSync(file, 'utf8');
    let found;
    if (v3) {
      const { descriptor } = v3.parse(source, { filename: file });
      if (!descriptor.template || !descriptor.template.ast) continue;
      found = legacySlotUsesV3(descriptor.template.ast);
    } else {
      const sfc = v2.parseComponent(source, { deindent: false });
      if (!sfc.template) continue;
      const { ast } = v2.compile(sfc.template.content, { whitespace: 'preserve' });
      if (!ast) continue;
      found = legacySlotUses(ast);
    }
    if (found.length) offenders.push(`${path.relative(ROOT, file)}: ${found.map((f) => `<${f.tag} ${f.attr}>`).join(', ')}`);
  }
  assert.deepEqual(offenders, []);
});
