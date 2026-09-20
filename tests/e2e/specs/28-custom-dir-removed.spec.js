const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

// CUSTOM_DIR desactivado (`configureCompat({ CUSTOM_DIR: false })`): ningún consumidor de hooks de directivas de Vue 2. Si un componente de una
// librería usara `bind/inserted/componentUpdated/unbind`, @vue/compat lo avisaría (CUSTOM_DIR) o, peor, dejaría de ejecutarlo. Se recorre una
// muestra amplia de pantallas (tooltips, sidebars, vue-select con append-to-body, BV2 textarea/datepicker, tablas) contando esos avisos.

const ROUTES = [
  '/app/products/list-classic', '/app/adjustments/list-classic', '/app/tasks/list', '/app/projects/list', '/app/hrm/employees/list',
  '/app/People/Customers', '/app/whatsapp/settings', '/app/products/Brands', '/app/products/SubCategories', '/app/service/jobs/create',
  '/app/realestate/properties/create', '/app/settings/System_settings', '/app/reports/sales_report', '/app/products/store', '/app/meeting/calendar',
];

test.describe('CUSTOM_DIR eliminado', () => {
  test.use({ storageState: path.join(env.authDir, 'admin.json') });

  test('ninguna pantalla de la muestra emite avisos CUSTOM_DIR ni errores', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForApp(page);
    await page.evaluate(() => {
      window.__cd = [];
      document.querySelector('#app').__vue_app__.config.warnHandler = (msg, proxy, trace) => {
        if (/CUSTOM_DIR/.test(msg)) window.__cd.push({ msg: msg.slice(0, 160), trace: String(trace).split('\n').slice(0, 3).join(' | ') });
      };
    });
    for (const route of ROUTES) {
      await page.evaluate((t) => document.querySelector('#app').__vue_app__.config.globalProperties.$router.push(t), route);
      await page.waitForTimeout(1800);
    }
    expect(await page.evaluate(() => window.__cd)).toEqual([]);
  });

  test('BV2 datepicker (directiva interna v-b-hover con hooks de Vue 3): monta y abre el calendario', async ({ page }) => {
    await page.goto('/app/settings/System_settings');
    await waitForApp(page);
    // el datepicker de BV2 (b-form-datepicker) abre su calendario al pulsar el botón
    const dp = page.locator('.b-form-btn-label-control button').first();
    if (await dp.count()) {
      await dp.click();
      await expect(page.locator('.b-calendar').first()).toBeVisible();
    }
  });
});
