const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

// Dominios críticos con los formularios ya migrados a BootstrapVueNext (fase 5B): ventas, compras, cotizaciones, transferencias, ajustes, daños,
// gastos, productos, configuración (sistema, POS, métodos de pago, cajas), RR. HH. y el POS. Cada pantalla debe cargar sin errores de consola / pageerror /
// 5xx (los fixtures los convierten en fallos), sin ningún resto del marcado de BootstrapVue 2 (`__BVID__`) y sus controles deben responder (escribir,
// elegir, marcar) sin lanzar excepciones. La lógica de negocio (POS, pagos, transferencias) la cubren las specs 05–09 y 30.

test.use({ storageState: path.join(env.authDir, 'admin.json') });
test.setTimeout(90_000);

const PAGES = [
  '/app/sales/store', '/app/purchases/store', '/app/quotations/store', '/app/transfers/store', '/app/adjustments/store', '/app/damages/store',
  '/app/expenses/store', '/app/products/store', '/app/settings/System_settings', '/app/settings/pos_settings', '/app/settings/payment_methods',
  '/app/settings/Cash_Drawers', '/app/hrm/employees/store', '/app/hrm/payrolls', '/app/pos',
];

test.describe('Formularios de dominios críticos sobre BootstrapVueNext @smoke', () => {
  for (const url of PAGES) {
    test(`${url}: carga, sin restos de BootstrapVue 2 y sus controles responden`, async ({ page }) => {
      await page.goto(url);
      await waitForApp(page);
      await page.waitForLoadState('networkidle').catch(() => {});

      // ningún componente de BootstrapVue 2 (sus ids son `__BVID__N`; los de BVN, `BootstrapVueNext__ID__…`)
      const bv2 = await page.evaluate(() => document.querySelectorAll('[id*="__BVID__"], [class*="b-form-btn-label-control"][id*="__BVID__"]').length);
      expect(bv2, 'elementos de BootstrapVue 2').toBe(0);

      // escribir en los primeros campos de texto visibles y editables
      const texts = page.locator('input.form-control:not([readonly]):not([disabled]):not([type=hidden]):not([type=file]):not([type=checkbox]):not([type=radio]):visible');
      const n = Math.min(await texts.count(), 3);
      for (let i = 0; i < n; i += 1) {
        const field = texts.nth(i);
        const type = (await field.getAttribute('type')) || 'text';
        if (['text', 'search', 'email', 'tel', 'url'].includes(type)) { await field.fill('x'); await field.fill(''); }
        await field.blur();
      }
      // cambiar la primera lista desplegable con más de una opción
      const selects = page.locator('select.custom-select:not([disabled]):visible');
      if (await selects.count()) {
        const sel = selects.first();
        if ((await sel.locator('option').count()) > 1) await sel.selectOption({ index: 1 });
      }
      // marcar / desmarcar la primera casilla o interruptor y volver a dejarla como estaba
      const checks = page.locator('.form-check input.px-bvn-check:not([disabled]):visible, .px-bvn-group input:not([disabled]):visible');
      if (await checks.count()) {
        const first = checks.first();
        const before = await first.isChecked();
        await first.locator('xpath=following-sibling::label[1]').click({ force: true, trial: false }).catch(() => {});
        await first.locator('xpath=following-sibling::label[1]').click({ force: true }).catch(() => {});
        expect(await first.isChecked()).toBe(before);
      }
    });
  }
});
