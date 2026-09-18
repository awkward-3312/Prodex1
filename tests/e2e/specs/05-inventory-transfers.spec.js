const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

test.use({ storageState: path.join(env.authDir, 'admin.json') });

// Solo lectura y navegación: no se crean traslados ni ajustes (el tenant demo no tiene sucursales/ubicaciones para transferir).
test.describe('Inventario y transferencias @smoke', () => {
  test('el listado de productos muestra el catálogo demo', async ({ page }) => {
    await page.goto('/app/products/list');
    await waitForApp(page);
    await expect(page.locator('table tbody tr').first()).toBeVisible({ timeout: 30_000 });
    expect(await page.locator('table tbody tr').count()).toBeGreaterThan(3);
  });

  test('existencias por ubicación carga su buscador', async ({ page }) => {
    await page.goto('/app/inventory/location-stock');
    await waitForApp(page);
    await expect(page.getByPlaceholder('Buscar producto por nombre o código').first()).toBeVisible();
    await expect(page.locator('body')).toContainText(/Existencias por ubicación/);
  });

  test('traslados: listado, acceso a recepciones y formulario de nuevo traslado', async ({ page }) => {
    await page.goto('/app/transfers/list');
    await waitForApp(page);
    await expect(page.locator('body')).toContainText(/Traslados/);
    await expect(page.getByRole('button', { name: /Recepciones/ }).first()).toBeVisible();

    await page.getByRole('button', { name: /Recepciones/ }).first().click();
    await expect(page).toHaveURL(/\/app\/transfers\/receptions/);
    await expect(page.locator('body')).toContainText(/Recepciones entrantes/);

    await page.goto('/app/transfers/list');
    await waitForApp(page);
    await page.getByRole('button', { name: /Nuevo traslado/ }).first().click();
    await expect(page).toHaveURL(/\/app\/transfers\/store/);
    await expect(page.getByRole('button', { name: /Guardar traslado/ }).first()).toBeVisible({ timeout: 20_000 });
  });

  test('ajustes de inventario carga su listado', async ({ page }) => {
    await page.goto('/app/adjustments/list');
    await waitForApp(page);
    await expect(page.locator('body')).toContainText(/Ajustes de inventario/);
  });
});
