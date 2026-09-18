const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp, apiStatus } = require('../support/helpers');

test.use({ storageState: path.join(env.authDir, 'restricted.json') });

// Usuario "e2e_restricted": rol sin permisos operativos (lo crea tests/e2e/scripts/provision.php).
test.describe('Permisos restringidos @smoke', () => {
  test('entra al panel pero sin sucursal ni datos operativos', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForApp(page);
    await expect(page.locator('body')).toContainText(/No tienes una sucursal asignada/);
  });

  for (const route of ['/app/products/list', '/app/settings/system_settings', '/app/pos']) {
    test(`${route} muestra "no autorizado" al usuario restringido`, async ({ page }) => {
      await page.goto(route);
      await waitForApp(page);
      await expect(page).toHaveURL(/not_authorize/);
      await expect(page.locator('body')).toContainText(/usted no está autorizado/);
    });
  }

  test('la API rechaza con 403 lo que la UI oculta', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForApp(page);
    expect(await apiStatus(page, '/api/products?page=1&limit=1')).toBe(403);
    expect(await apiStatus(page, '/api/sales?page=1&limit=1')).toBe(403);
    expect(await apiStatus(page, '/api/users')).toBe(403);
  });
});
