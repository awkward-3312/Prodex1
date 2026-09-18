const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, openPos, registerPill, ensureRegisterOpen, ensureRegisterClosed } = require('../support/helpers');

test.use({ storageState: path.join(env.authDir, 'admin.json') });

// Sin lógica de dinero real: se abre con fondo 0 sobre la caja física de prueba (E2E-01) y se cierra sin ventas.
// Todo ocurre en la BD demo aislada (docker-compose.yml); el test deja la caja cerrada al terminar.
test.describe.serial('Caja (apertura y cierre) @smoke', () => {
  test.beforeEach(async ({ page }) => {
    await openPos(page);
    await ensureRegisterClosed(page); // parte siempre de un estado conocido
  });

  test.afterAll(async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: path.join(env.authDir, 'admin.json'), baseURL: env.baseURL });
    const page = await ctx.newPage();
    await openPos(page);
    await ensureRegisterClosed(page);
    await ctx.close();
  });

  test('el formulario de apertura ofrece almacén, caja física, saldo inicial y notas', async ({ page }) => {
    await registerPill(page).click();
    const modal = page.locator('#OpenRegisterModal');
    await expect(modal).toBeVisible();
    await expect(modal).toContainText(/Abrir caja/);
    await expect(modal.locator('select')).toHaveCount(2);
    await expect(modal.locator('textarea')).toHaveCount(1);
    await expect(modal.locator('select').nth(1).locator('option')).toContainText(['Seleccionar efectivo cajón', 'Caja E2E (E2E-01)']);
    await modal.getByRole('button', { name: 'Cancelar' }).click();
    await expect(registerPill(page)).toHaveClass(/is-closed/);
  });

  test('abrir caja cambia el estado a OPEN y cerrarla lo devuelve a CLOSED', async ({ page }) => {
    await ensureRegisterOpen(page);
    await expect(registerPill(page)).toContainText('OPEN');

    // El estado sobrevive a recargar (viene del servidor, no solo de memoria).
    await page.reload();
    await expect(page.locator('.pos-wh-trigger')).toBeVisible({ timeout: 30_000 });
    await expect(registerPill(page)).toHaveClass(/is-open/, { timeout: 20_000 });

    await registerPill(page).click();
    const close = page.locator('#CloseRegisterModal');
    await expect(close).toBeVisible();
    await expect(close).toContainText(/Fondo inicial/);
    await expect(close.locator('.cr-denom-qty').first()).toBeVisible(); // conteo por denominaciones
    await close.getByRole('button', { name: 'Cerrar caja' }).click();
    await expect(registerPill(page)).toHaveClass(/is-closed/, { timeout: 20_000 });
    await expect(registerPill(page)).toContainText('CLOSED');
  });
});
