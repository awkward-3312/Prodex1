#!/usr/bin/env node
/** node tests/e2e/visual/compare.js <antes> <después> [salida-diff]  — % de píxeles distintos por captura (tolerancia de canal 12). */
const fs = require('fs');
const path = require('path');
const { PNG } = require('playwright-core/lib/utilsBundle');

const [a, b, outArg] = process.argv.slice(2);
const out = path.resolve(outArg || 'visual-diff');
fs.mkdirSync(out, { recursive: true });
const rows = [];
for (const f of fs.readdirSync(a).filter((x) => x.endsWith('.png')).sort()) {
  if (!fs.existsSync(path.join(b, f))) { rows.push([f, 'falta en después']); continue; }
  const A = PNG.sync.read(fs.readFileSync(path.join(a, f)));
  const B = PNG.sync.read(fs.readFileSync(path.join(b, f)));
  if (A.width !== B.width || A.height !== B.height) { rows.push([f, `tamaño distinto ${A.width}x${A.height} → ${B.width}x${B.height}`, 100]); continue; }
  const diff = new PNG({ width: A.width, height: A.height });
  let n = 0;
  for (let i = 0; i < A.data.length; i += 4) {
    const d = Math.max(Math.abs(A.data[i] - B.data[i]), Math.abs(A.data[i + 1] - B.data[i + 1]), Math.abs(A.data[i + 2] - B.data[i + 2]));
    if (d > 12) { n += 1; diff.data[i] = 255; diff.data[i + 1] = 0; diff.data[i + 2] = 0; diff.data[i + 3] = 255; }
    else { diff.data[i] = diff.data[i + 1] = diff.data[i + 2] = Math.round((A.data[i] + A.data[i + 1] + A.data[i + 2]) / 3); diff.data[i + 3] = 60; }
  }
  const pct = (100 * n) / (A.width * A.height);
  if (n) fs.writeFileSync(path.join(out, f), PNG.sync.write(diff));
  rows.push([f, `${pct.toFixed(3)} %`, pct]);
}
rows.forEach((r) => console.log(`${String(r[0]).padEnd(38)} ${r[1]}`));
const bad = rows.filter((r) => typeof r[2] === 'number' && r[2] > 0.05);
console.log(`\n${rows.length} capturas; con diferencia > 0.05 %: ${bad.length}`);
