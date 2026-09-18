const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp, openPos } = require('../support/helpers');

test.use({ storageState: path.join(env.authDir, 'admin.json') });

// El POS es zona de alto riesgo: aquí solo se comprueba que carga, muestra catálogo y responde a lo esencial.
// No se cobra nada (las ventas se cubren en 08-pos-offline.spec.js dentro del tenant demo aislado).
test.describe('POS @smoke', () => {
  test('carga sin errores fatales y muestra la interfaz principal', async ({ page }) => {
    await page.goto('/app/pos');
    await expect(page.locator('.pos-wh-trigger')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('.pos-shell-register-pill')).toBeVisible();
    await expect(page.getByRole('button', { name: /Pagar ahora/ })).toBeVisible();
    await expect(page.locator('body')).toContainText(/En línea/);
    await expect(page.locator('body')).toContainText(/Carrito Actual/);
  });

  test('al elegir almacén aparece el catálogo y un producto entra al carrito', async ({ page }) => {
    await openPos(page);
    const cards = page.locator('.pos-shell-product-card');
    expect(await cards.count()).toBeGreaterThan(3);
    await cards.first().click();
    await expect(page.locator('body')).toContainText(/1 artículo/);
    // "Restablecer" deja el carrito vacío otra vez.
    await page.getByRole('button', { name: /Restablecer/ }).click();
    await expect(page.locator('body')).toContainText(/0 Artículos/, { timeout: 10_000 });
  });

  test('entrada por teclado/escáner: un SKU + Enter agrega el producto; un código inexistente no agrega nada', async ({ page }) => {
    await openPos(page);
    const box = page.getByPlaceholder(/Escanear \/ Buscar producto/);
    await box.fill('999999999999');
    await box.press('Enter');
    await expect(page.locator('body')).toContainText(/0 Artículos/);

    await box.fill('PR-DEMO2-054');
    await box.press('Enter');
    await expect(page.locator('body')).toContainText(/1 artículo/, { timeout: 15_000 });
    // Los nombres del catálogo demo dependen de la "persona" que el seeder elige por hash del tenant; el SKU es estable.
    await expect(page.locator('body')).toContainText(/SKU\s*·\s*PR-DEMO2-054/);
  });

  test('navegación crítica: "Inicio" vuelve al panel sin perder la sesión', async ({ page }) => {
    await openPos(page, { selectWarehouse: false });
    await page.locator('a.pos-shell-action-btn', { hasText: 'Inicio' }).click();
    await expect(page).toHaveURL(/\/app\/dashboard/, { timeout: 20_000 });
    await waitForApp(page);
    await page.goBack();
    await expect(page.locator('.pos-wh-trigger')).toBeVisible({ timeout: 30_000 });
  });
});
