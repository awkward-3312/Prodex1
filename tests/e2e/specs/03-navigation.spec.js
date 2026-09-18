const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

test.use({ storageState: path.join(env.authDir, 'admin.json') });

// Módulos del shell (PxShell) y la ruta a la que deben llevar. Fuente: resources/src/components/px-next/PxShell.vue.
const MODULES = [
  ['Ventas', /\/app\/sales\/list/],
  ['Inventario', /\/app\/products\/list/],
  ['Compras', /\/app\/purchases\/list/],
  ['Finanzas', /\/app\/accounting-v2\/dashboard/],
  ['Reportes', /\/app\/reports\/all/],
  ['RR. HH.', /\/app\/hrm\/employees/],
  ['Configuración', /\/app\/settings\/system_settings/i],
  ['Panel', /\/app\/dashboard/],
];

test.describe('Navegación principal @smoke', () => {
  test('cada módulo del menú lleva a su pantalla sin "no autorizado" ni 404', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForApp(page);
    const nav = page.locator('nav[aria-label="Navegación principal"]');
    for (const [label, url] of MODULES) {
      await nav.getByRole('link', { name: label, exact: true }).click();
      await expect(page, `módulo ${label}`).toHaveURL(url, { timeout: 20_000 });
      await waitForApp(page);
      await expect(page.locator('body'), `módulo ${label}`).not.toContainText(/usted no está autorizado/);
      await expect(page.locator('body'), `módulo ${label}`).not.toContainText(/Página no encontrada|404/i);
    }
  });

  test('recargar una ruta profunda mantiene sesión y pantalla (history mode)', async ({ page }) => {
    await page.goto('/app/products/list');
    await waitForApp(page);
    await page.reload();
    await waitForApp(page);
    await expect(page).toHaveURL(/\/app\/products\/list/);
  });

  test('una URL inexistente muestra la pantalla NotFound del SPA', async ({ page }) => {
    await page.goto('/app/esta-ruta-no-existe-e2e');
    await waitForApp(page);
    await expect(page.locator('body')).toContainText(/no existe|404/i);
    await expect(page).not.toHaveURL(/\/login/);
  });
});
