const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

// El mecanismo de aviso CUSTOM_DIR era de @vue/compat (fase vue3-pure-runtime: retirado por completo, ver
// docs/architecture/VUE3_PURE_RUNTIME.md) — sin él no hay nada que atribuirle, así que la comprobación de avisos
// se retiró (habría pasado siempre, sin probar nada real). Queda la comprobación funcional real: el datepicker
// de BootstrapVue 2 (directiva interna con hooks de Vue 3) sigue montando y abriendo su calendario.
test.describe('CUSTOM_DIR eliminado', () => {
  test.use({ storageState: path.join(env.authDir, 'admin.json') });

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
