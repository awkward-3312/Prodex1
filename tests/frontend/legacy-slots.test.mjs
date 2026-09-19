import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { parseComponent, compile } = require('vue-template-compiler');

const ROOT = path.resolve(new URL('../../resources/src', import.meta.url).pathname);

function walk(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, out);
    else if (entry.name.endsWith('.vue')) out.push(full);
  }
  return out;
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
    const sfc = parseComponent(fs.readFileSync(file, 'utf8'), { deindent: false });
    if (!sfc.template) continue;
    const { ast } = compile(sfc.template.content, { whitespace: 'preserve' });
    if (!ast) continue;
    const found = legacySlotUses(ast);
    if (found.length) offenders.push(`${path.relative(ROOT, file)}: ${found.map((f) => `<${f.tag} ${f.attr}>`).join(', ')}`);
  }
  assert.deepEqual(offenders, []);
});
