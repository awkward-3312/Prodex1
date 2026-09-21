const fs = require('fs');
const os = require('os');
const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

// Validación (vee-validate 3 vía platform/validation) con TODOS los controles migrados a BootstrapVueNext (fase 5B), sobre la sonda de desarrollo:
// required → mensaje y estado inválido; corrección → limpia; submit inválido (no envía) / válido (envía UNA vez); reset; `setErrors`; `observer.validate()`.
// Los fixtures fallan ante console.error / pageerror / 5xx (no hay fallos silenciosos).

test.use({ storageState: path.join(env.authDir, 'admin.json') });
test.setTimeout(60_000);

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'px-val-'));
const FILE = path.join(tmp, 'a.txt');
fs.writeFileSync(FILE, 'hola');
const root = (page) => page.locator('.probe-root');

// Cada caso: control, valor inicial, cómo dejarlo válido y cómo comprobar que quedó vacío tras `reset`.
const CONTROLS = {
  input: { ctl: '<b-form-input v-model="v" :state="st(ctx)" class="c"></b-form-input>', v: '', fill: (p) => root(p).locator('.c').fill('Juan'), empty: (p) => expect(root(p).locator('.c')).toHaveValue('') },
  'input .trim': { ctl: '<b-form-input v-model.trim="v" :state="st(ctx)" class="c"></b-form-input>', v: '', fill: (p) => root(p).locator('.c').fill('  Ana  '), empty: (p) => expect(root(p).locator('.c')).toHaveValue('') },
  'input .number': { ctl: '<b-form-input v-model.number="v" type="text" :state="st(ctx)" class="c"></b-form-input>', v: '', fill: (p) => root(p).locator('.c').fill('12'), empty: (p) => expect(root(p).locator('.c')).toHaveValue('') },
  textarea: { ctl: '<b-form-textarea v-model="v" rows="2" :state="st(ctx)" class="c"></b-form-textarea>', v: '', fill: (p) => root(p).locator('.c').fill('nota'), empty: (p) => expect(root(p).locator('.c')).toHaveValue('') },
  select: { ctl: '<b-form-select v-model="v" :options="[{ value: null, text: \'—\' }, { value: 1, text: \'Uno\' }]" :state="st(ctx)" class="c"></b-form-select>', v: null, fill: (p) => root(p).locator('.c').selectOption({ index: 1 }), empty: async (p) => expect(await root(p).locator('.c').evaluate((e) => e.selectedIndex)).toBe(0) },
  checkbox: { ctl: '<b-form-checkbox v-model="v" :state="st(ctx)" class="c">Acepto</b-form-checkbox>', v: false, rules: '{ required: { allowFalse: false } }', fill: (p) => root(p).locator('.c label').click(), empty: (p) => expect(root(p).locator('.c input')).not.toBeChecked() },
  switch: { ctl: '<b-form-checkbox v-model="v" switch :state="st(ctx)" class="c">Activo</b-form-checkbox>', v: false, rules: '{ required: { allowFalse: false } }', fill: (p) => root(p).locator('.c label').click(), empty: (p) => expect(root(p).locator('.c input')).not.toBeChecked() },
  radio: { ctl: '<b-form-radio-group v-model="v" :options="[{ value: 1, text: \'Uno\' }, { value: 2, text: \'Dos\' }]" :state="st(ctx)" class="c"></b-form-radio-group>', v: null, fill: (p) => root(p).locator('label', { hasText: 'Dos' }).click(), empty: (p) => expect(root(p).locator('input:checked')).toHaveCount(0) },
  file: { ctl: '<b-form-file v-model="v" :state="st(ctx)" class="c"></b-form-file>', v: null, fill: (p) => p.setInputFiles('.probe-root input[type=file]', FILE), empty: (p) => expect(root(p).locator('.form-file-text')).toHaveText(/No file chosen|Ningún archivo/) },
  datepicker: { ctl: '<b-form-datepicker v-model="v" :state="st(ctx)" class="c" placeholder="Fecha"></b-form-datepicker>', v: '', fill: async (p) => { await root(p).locator('.c button').first().click(); await root(p).locator('.b-calendar-grid-body [role=button]').nth(10).click(); }, empty: (p) => expect(root(p).locator('.c label').last()).toHaveText('Fecha') },
};

const tpl = (ctl, rules = "'required'") => `<validation-observer ref="obs" v-slot="{ handleSubmit }"><form @submit.prevent="handleSubmit(ok)" novalidate>
  <validation-provider name="campo" :rules="${rules}" v-slot="ctx">${ctl}<b-form-invalid-feedback :state="ctx.errors.length ? false : null" class="fb">{{ ctx.errors[0] }}</b-form-invalid-feedback></validation-provider>
  <button type="submit" class="s">enviar</button><button type="button" class="r" @click="doReset">reset</button></form></validation-observer>`;
const methods = {
  st: 'function (ctx) { return ctx.errors.length ? false : null }',
  ok: 'function () { this.sent += 1 }',
  doReset: 'function () { this.v = this.init; this.$nextTick(() => this.$refs.obs.reset()) }',
  validateAll: 'function () { return this.$refs.obs.validate().then((r) => { this.result = r; return r }) }',
  putErrors: 'function () { this.$refs.obs.setErrors({ campo: ["Servidor dice no"] }) }',
};

test.describe('Validación con los controles de BootstrapVueNext @smoke', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/app/_ui?probe=bv');
    await waitForApp(page);
    await page.waitForFunction(() => typeof window.__pxProbe === 'function', undefined, { timeout: 30_000 });
  });

  for (const [name, c] of Object.entries(CONTROLS)) {
    test(`${name}: required → mensaje → corrige → envía una vez → reset limpia`, async ({ page }) => {
      const r = await page.evaluate(([t, o]) => window.__pxProbe(t, o), [tpl(c.ctl, c.rules), { bvn: true, data: { v: c.v, init: c.v, sent: 0, result: null }, methods }]);
      expect(r.missing).toEqual([]);

      // submit inválido: no envía, muestra el mensaje y marca el estado
      await root(page).locator('.s').click();
      await expect(root(page).locator('.fb')).toContainText(/.+/);
      expect(await page.evaluate(() => window.__pxProbeData().sent)).toBe(0);
      await expect(root(page).locator('.is-invalid').first()).toBeVisible();

      // corrección: el mensaje desaparece
      await c.fill(page);
      await expect(root(page).locator('.fb')).toHaveText('');
      await expect(root(page).locator('.is-invalid')).toHaveCount(0);

      // submit válido: UNA sola vez
      await root(page).locator('.s').click();
      await expect.poll(() => page.evaluate(() => window.__pxProbeData().sent)).toBe(1);

      // reset: modelo y control vacíos, sin errores
      await root(page).locator('.r').click();
      await c.empty(page);
      await expect(root(page).locator('.fb')).toHaveText('');
    });
  }

  test('observer.validate() y setErrors() (errores de servidor) con un input', async ({ page }) => {
    await page.evaluate(([t, o]) => window.__pxProbe(t, o), [tpl(CONTROLS.input.ctl), { bvn: true, data: { v: '', sent: 0, result: null }, methods }]);
    // validate() sin valor → false y mensaje
    expect(await page.evaluate(() => window.__pxProbeInner().validateAll())).toBe(false);
    await expect(root(page).locator('.fb')).toContainText(/.+/);
    await root(page).locator('.c').fill('x');
    expect(await page.evaluate(() => window.__pxProbeInner().validateAll())).toBe(true);
    // setErrors (respuesta 422 del servidor)
    await page.evaluate(() => window.__pxProbeInner().putErrors());
    await expect(root(page).locator('.fb')).toHaveText('Servidor dice no');
    await expect(root(page).locator('input.is-invalid')).toHaveCount(1);
  });
});
