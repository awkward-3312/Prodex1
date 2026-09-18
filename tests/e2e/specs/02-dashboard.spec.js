const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp, apiStatus } = require('../support/helpers');

test.use({ storageState: path.join(env.authDir, 'admin.json') });

test.describe('Dashboard @smoke', () => {
  test('el panel carga con sus indicadores y sin errores fatales', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForApp(page);
    // Indicadores del panel operativo (es es el idioma por defecto del tenant demo).
    await expect(page.locator('body')).toContainText(/Ventas de la sucursal/);
    await expect(page.locator('body')).toContainText(/Inventario a costo/);
    await expect(page.locator('body')).not.toContainText(/usted no está autorizado/);
  });

  test('el endpoint de datos del panel responde a la sesión del SPA', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForApp(page);
    expect(await apiStatus(page, '/api/get_user_auth')).toBe(200);
    expect(await apiStatus(page, '/api/dashboard_data?from=2026-09-01&to=2026-09-18')).toBe(200);
  });
});
