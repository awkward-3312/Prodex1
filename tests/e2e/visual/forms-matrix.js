#!/usr/bin/env node
/**
 * Matriz visual de formularios (fase 5B): la MISMA plantilla montada con BootstrapVue 2 y con los wrappers de platform/bootstrap en la sonda
 * `/app/_ui?probe=bv`, capturada en LTR, RTL y móvil, con el % de píxeles distintos por caso (tolerancia de canal 12).
 *   node tests/e2e/visual/forms-matrix.js [filtro] [--out=/ruta] [--all]     (--all guarda también las capturas sin diferencia)
 * Requiere el servidor de E2E y un build de desarrollo (igual que probe-diff.js). Salida: <out>/{bv2,bvn,diff}/<caso>-<modo>.png
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');
const { PNG } = require('playwright-core/lib/utilsBundle');
const env = require('../support/env');

const args = process.argv.slice(2);
const filter = args.find((a) => !a.startsWith('--')) ? new RegExp(args.find((a) => !a.startsWith('--')), 'i') : null;
const out = path.resolve((args.find((a) => a.startsWith('--out=')) || '--out=/tmp/forms-visual').slice(6));
const keepAll = args.includes('--all');

const grp = (label, inner, attrs = '') => `<b-form-group label="${label}" description="Texto de ayuda" ${attrs}>${inner}</b-form-group>`;
const CASES = [
  { name: 'input-estados', tpl: `<div class="p-3" style="width:360px">${grp('Normal', '<b-form-input v-model="v" placeholder="Escribe…"></b-form-input>')}${grp('Válido', '<b-form-input v-model="v" :state="true"></b-form-input>')}${grp('Inválido', '<b-form-input v-model="v" :state="false"></b-form-input><b-form-invalid-feedback :state="false">Campo requerido</b-form-invalid-feedback>')}${grp('Deshabilitado', '<b-form-input value="x" disabled></b-form-input>')}${grp('Pequeño', '<b-form-input size="sm" value="sm"></b-form-input>')}</div>`, data: { v: 'abc' } },
  { name: 'select-textarea', tpl: '<div class="p-3" style="width:360px"><b-form-select v-model="v" :options="o"></b-form-select><b-form-select v-model="v" :options="o" size="sm" class="mt-2"></b-form-select><b-form-select v-model="v" :options="o" :state="false" class="mt-2"></b-form-select><b-form-textarea class="mt-2" rows="3" placeholder="Notas" v-model="t"></b-form-textarea><b-form-textarea class="mt-2" rows="2" max-rows="4" :state="false" value="x"></b-form-textarea></div>', data: { v: 'b', t: '', o: [{ value: 'a', text: 'Uno' }, { value: 'b', text: 'Dos' }] } },
  { name: 'checkbox', tpl: '<div class="p-3" style="width:360px"><b-form-checkbox v-model="a">Marcada</b-form-checkbox><b-form-checkbox v-model="b">Sin marcar</b-form-checkbox><b-form-checkbox v-model="a" disabled>Deshabilitada marcada</b-form-checkbox><b-form-checkbox v-model="b" disabled>Deshabilitada</b-form-checkbox><b-form-checkbox v-model="b" :state="false">Inválida</b-form-checkbox><b-form-checkbox v-model="a" inline>En línea</b-form-checkbox><b-form-checkbox v-model="b" inline>En línea 2</b-form-checkbox></div>', data: { a: true, b: false } },
  { name: 'switch', tpl: '<div class="p-3" style="width:360px"><b-form-checkbox v-model="a" switch>Activo</b-form-checkbox><b-form-checkbox v-model="b" switch>Inactivo</b-form-checkbox><b-form-checkbox v-model="a" switch disabled>Deshabilitado activo</b-form-checkbox><b-form-checkbox v-model="b" switch disabled>Deshabilitado</b-form-checkbox></div>', data: { a: true, b: false } },
  { name: 'checkbox-group', tpl: '<div class="p-3" style="width:360px"><b-form-checkbox-group v-model="v" :options="o" stacked></b-form-checkbox-group><hr><b-form-checkbox-group v-model="v" :options="o"></b-form-checkbox-group><hr><b-form-checkbox-group v-model="v" :options="o" buttons button-variant="outline-primary"></b-form-checkbox-group><hr><b-form-checkbox-group v-model="v" :options="o" switches stacked></b-form-checkbox-group></div>', data: { v: ['b'], o: [{ value: 'a', text: 'Uno' }, { value: 'b', text: 'Dos' }, { value: 'c', text: 'Tres', disabled: true }] } },
  { name: 'radio', tpl: '<div class="p-3" style="width:360px"><b-form-radio v-model="v" value="a" name="r1">Opción A</b-form-radio><b-form-radio v-model="v" value="b" name="r1">Opción B</b-form-radio><b-form-radio v-model="v" value="c" name="r1" disabled>Deshabilitada</b-form-radio><b-form-radio-group v-model="v" :options="o" stacked></b-form-radio-group><hr><b-form-radio-group v-model="v" :options="o"></b-form-radio-group></div>', data: { v: 'b', o: [{ value: 'a', text: 'Uno' }, { value: 'b', text: 'Dos' }, { value: 'c', text: 'Tres' }] } },
  { name: 'radio-botones', tpl: '<div class="p-3" style="width:420px"><b-form-radio-group v-model="v" :options="o" buttons button-variant="outline-primary"></b-form-radio-group><br><b-form-radio-group v-model="v" :options="o" buttons button-variant="outline-success" size="sm" class="mt-2"></b-form-radio-group><br><b-form-radio-group v-model="v" :options="o" buttons button-variant="primary" class="mt-2"></b-form-radio-group></div>', data: { v: 'b', o: [{ value: 'a', text: 'Uno' }, { value: 'b', text: 'Dos' }, { value: 'c', text: 'Tres' }] } },
  {
    name: 'input-group-texto',
    tpl: '<div class="p-3" style="width:400px"><b-input-group prepend="$" class="mb-2"><b-form-input value="10.00"></b-form-input></b-input-group><b-input-group append="USD" class="mb-2"><b-form-input value="10.00"></b-form-input></b-input-group><b-input-group prepend="L." append=".00" size="sm" class="mb-2"><b-form-input value="5"></b-form-input></b-input-group><b-input-group prepend="$" class="mb-2"><b-form-input value="mal" :state="false"></b-form-input></b-input-group></div>',
  },
  {
    name: 'input-group-botones',
    bv2: '<div class="p-3" style="width:400px"><b-input-group class="mb-2"><b-form-input value="Buscar"></b-form-input><b-input-group-append><b-button variant="primary">Ir</b-button></b-input-group-append></b-input-group><b-input-group class="mb-2"><b-input-group-prepend><span class="btn btn-primary btn-sm">-</span></b-input-group-prepend><input class="form-control" value="1"><b-input-group-append><span class="btn btn-primary btn-sm">+</span></b-input-group-append></b-input-group><b-input-group class="mb-2"><b-input-group-prepend is-text>@</b-input-group-prepend><b-form-input value="usuario"></b-form-input><b-input-group-append><b-button variant="outline-secondary">Copiar</b-button></b-input-group-append></b-input-group><b-input-group size="sm"><b-form-input value="sm"></b-form-input><b-input-group-append><b-button variant="primary">Ir</b-button></b-input-group-append></b-input-group></div>',
    bvn: '<div class="p-3" style="width:400px"><b-input-group class="mb-2"><b-form-input value="Buscar"></b-form-input><div class="input-group-append"><b-button variant="primary">Ir</b-button></div></b-input-group><b-input-group class="mb-2"><div class="input-group-prepend"><span class="btn btn-primary btn-sm">-</span></div><input class="form-control" value="1"><div class="input-group-append"><span class="btn btn-primary btn-sm">+</span></div></b-input-group><b-input-group class="mb-2"><div class="input-group-prepend"><div class="input-group-text">@</div></div><b-form-input value="usuario"></b-form-input><div class="input-group-append"><b-button variant="outline-secondary">Copiar</b-button></div></b-input-group><b-input-group size="sm"><b-form-input value="sm"></b-form-input><div class="input-group-append"><b-button variant="primary">Ir</b-button></div></b-input-group></div>',
  },
  { name: 'file', tpl: '<div class="p-3" style="width:400px"><b-form-file placeholder="Elegir archivo"></b-form-file><b-form-file class="mt-2" size="sm" :state="false"></b-form-file><b-form-file class="mt-2" disabled></b-form-file></div>' },
  { name: 'datepicker-cerrado', tpl: '<div class="p-3" style="width:360px"><b-form-datepicker v-model="v" placeholder="Desde" reset-button></b-form-datepicker><b-form-datepicker class="mt-2" size="sm" value="2026-01-05"></b-form-datepicker><b-form-datepicker class="mt-2" :state="false" value="2026-01-05"></b-form-datepicker></div>', data: { v: '' } },
  { name: 'datepicker-abierto', tpl: '<div class="p-3" style="width:360px;min-height:420px"><b-form-datepicker v-model="v" class="dp" reset-button today-button></b-form-datepicker></div>', data: { v: '2026-09-15' }, act: async (page) => { await page.locator('.probe-root .dp button').first().click(); await page.waitForTimeout(400); } },
  { name: 'skeleton', tpl: '<div class="p-3" style="width:360px"><b-skeleton-img class="rounded-xl shadow-soft" height="110px"></b-skeleton-img></div>' },
  { name: 'form-grupo', tpl: '<div class="p-3" style="width:420px"><b-form><b-form-group label="Nombre" label-for="n" description="Ayuda"><b-form-input id="n" v-model="v"></b-form-input></b-form-group><b-form-group label="Elegir"><b-form-radio-group v-model="r" :options="o" stacked></b-form-radio-group></b-form-group><b-form-group label="Acepto"><b-form-checkbox v-model="c">Términos</b-form-checkbox></b-form-group></b-form></div>', data: { v: 'x', r: 'a', c: true, o: [{ value: 'a', text: 'A' }, { value: 'b', text: 'B' }] } },
];

const MODES = [
  { name: 'ltr', viewport: { width: 900, height: 700 }, rtl: false },
  { name: 'rtl', viewport: { width: 900, height: 700 }, rtl: true },
  { name: 'movil', viewport: { width: 390, height: 800 }, rtl: false },
];

function compare(a, b) {
  const A = PNG.sync.read(a);
  const B = PNG.sync.read(b);
  if (A.width !== B.width || A.height !== B.height) return { pct: 100, note: `tamaño ${A.width}x${A.height} → ${B.width}x${B.height}` };
  const diff = new PNG({ width: A.width, height: A.height });
  let n = 0;
  for (let i = 0; i < A.data.length; i += 4) {
    const d = Math.max(Math.abs(A.data[i] - B.data[i]), Math.abs(A.data[i + 1] - B.data[i + 1]), Math.abs(A.data[i + 2] - B.data[i + 2]));
    if (d > 12) { n += 1; diff.data[i] = 255; diff.data[i + 1] = 0; diff.data[i + 2] = 0; diff.data[i + 3] = 255; } else { const g = Math.round((A.data[i] + A.data[i + 1] + A.data[i + 2]) / 3); diff.data[i] = diff.data[i + 1] = diff.data[i + 2] = g; diff.data[i + 3] = 60; }
  }
  return { pct: (100 * n) / (A.width * A.height), diff: n ? PNG.sync.write(diff) : null };
}

(async () => {
  for (const d of ['bv2', 'bvn', 'diff']) fs.mkdirSync(path.join(out, d), { recursive: true });
  const browser = await chromium.launch();
  const rows = [];
  for (const mode of MODES) {
    const ctx = await browser.newContext({ baseURL: env.baseURL, storageState: path.join(env.authDir, 'admin.json'), viewport: mode.viewport });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => console.log('PAGEERROR', e.message.slice(0, 160)));
    await page.goto('/app/_ui?probe=bv');
    await page.waitForFunction(() => typeof window.__pxProbe === 'function', undefined, { timeout: 30000 });
    await page.evaluate((rtl) => { document.documentElement.setAttribute('dir', rtl ? 'rtl' : 'ltr'); document.body.classList.toggle('rtl', rtl); }, mode.rtl);
    for (const c of CASES) {
      if (filter && !filter.test(c.name)) continue;
      const shots = {};
      for (const bvn of [false, true]) {
        const tpl = (bvn ? c.bvn : c.bv2) || c.tpl;
        const r = await page.evaluate(([t, o]) => window.__pxProbe(t, o), [tpl, { bvn, data: c.data || {}, wait: 150 }]);
        if (r.missing && r.missing.length) console.log(`  (${c.name}) sin wrapper BVN: ${r.missing.join(', ')}`);
        if (c.act) await c.act(page);
        await page.evaluate(() => { const a = document.activeElement; if (a && a.blur) a.blur(); document.body.style.caretColor = 'transparent'; });
        await page.waitForTimeout(150);
        shots[bvn ? 'bvn' : 'bv2'] = await page.locator('.probe-root').screenshot({ animations: 'disabled' });
      }
      const id = `${c.name}-${mode.name}`;
      const cmp = compare(shots.bv2, shots.bvn);
      rows.push([id, cmp.pct, cmp.note]);
      if (cmp.pct > 0 || keepAll) {
        fs.writeFileSync(path.join(out, 'bv2', `${id}.png`), shots.bv2);
        fs.writeFileSync(path.join(out, 'bvn', `${id}.png`), shots.bvn);
        if (cmp.diff) fs.writeFileSync(path.join(out, 'diff', `${id}.png`), cmp.diff);
      }
    }
    await ctx.close();
  }
  await browser.close();
  rows.forEach(([id, pct, note]) => console.log(`${pct === 0 ? '=' : pct <= 0.05 ? '~' : '≠'} ${id.padEnd(34)} ${pct.toFixed(3)} %${note ? ` ${note}` : ''}`));
  const bad = rows.filter((r) => r[1] > 0.05);
  console.log(`\n${rows.length} capturas; con diferencia > 0.05 %: ${bad.length}  →  ${out}`);
})();
