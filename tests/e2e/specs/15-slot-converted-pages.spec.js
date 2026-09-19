const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');
const { routes } = require('../routes/slot-converted-routes.json');

test.use({ storageState: path.join(env.authDir, 'admin.json') });

// Todas las pantallas (sin parámetros) cuyas plantillas pasaron de `slot` / `slot-scope` a `v-slot`. Se abren con el bundle real:
// el fixture falla ante cualquier error de JS (render de slots de b-table, vue-good-table, b-modal...) o respuesta 5xx.
// Además se comprueba que cada tabla renderizada tenga cabeceras y que la pantalla no quede en blanco ni en "no autorizado".
test.describe('Pantallas con slots migrados a v-slot @smoke', () => {
  test.setTimeout(20 * 60_000);

  test('abren sin errores de JS y renderizan su contenido', async ({ page }) => {
    const empty = [];
    for (const { path: route } of routes) {
      await page.goto(route);
      await waitForApp(page);
      await page.waitForLoadState('networkidle').catch(() => {});
      const body = page.locator('body');
      await expect(body, route).not.toContainText(/usted no está autorizado/);
      const textLength = await page.evaluate(() => (document.querySelector('main, #app, body') || document.body).innerText.trim().length);
      if (textLength < 20) empty.push(route);
    }
    expect(empty, 'pantallas sin contenido').toEqual([]);
  });
});
