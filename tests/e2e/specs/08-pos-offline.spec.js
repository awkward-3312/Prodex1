const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, openPos, ensureRegisterOpen, ensureRegisterClosed } = require('../support/helpers');

test.use({ storageState: path.join(env.authDir, 'admin.json') });

const QUEUE_KEY = 'pos_offline_sales_v1';
const readQueue = (page) => page.evaluate((k) => JSON.parse(localStorage.getItem(k) || '[]'), QUEUE_KEY);
// Tras sincronizar una venta el POS recarga la página (pos.vue: `onlineReloadAfterSale`); durante la recarga el contexto
// de ejecución desaparece un instante, así que la lectura de la cola se reintenta en vez de fallar.
const readQueueSafe = (page) => readQueue(page).catch(() => undefined);

// Comportamiento offline esencial del POS (ver public/sw.js y resources/src/utils/globalOfflineSync.js).
// Se vende offline en el tenant demo aislado: la venta queda en la cola local y se sincroniza al volver la conexión.
test.describe.serial('POS offline y recuperación @smoke', () => {
  test.afterAll(async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: path.join(env.authDir, 'admin.json'), baseURL: env.baseURL });
    const page = await ctx.newPage();
    await openPos(page);
    await ensureRegisterClosed(page);
    await ctx.close();
  });

  test('cambiar a offline y volver online no rompe la aplicación ni pierde el carrito', async ({ page, context }) => {
    await openPos(page);
    await page.locator('.pos-shell-product-card').first().click();
    await expect(page.locator('body')).toContainText(/1 artículo/);

    await context.setOffline(true);
    await expect(page.locator('body')).toContainText(/sin conexión/i, { timeout: 15_000 });
    await expect(page.locator('.pos-shell-register-pill')).toHaveCount(0); // el POS oculta la caja sin red
    await expect(page.locator('body')).toContainText(/1 artículo/); // el carrito sigue intacto

    await context.setOffline(false);
    await expect(page.locator('body')).toContainText(/En línea/, { timeout: 30_000 });
    await expect(page.locator('body')).toContainText(/1 artículo/);
    await expect(page.locator('.pos-shell-register-pill')).toBeVisible({ timeout: 20_000 });
  });

  test('una venta hecha sin conexión queda en la cola local y se sincroniza al recuperar la red', async ({ page, context }) => {
    await openPos(page);
    await ensureRegisterOpen(page);
    const before = (await readQueue(page)).length;

    await page.locator('.pos-shell-product-card').first().click();
    await expect(page.locator('body')).toContainText(/1 artículo/);

    await context.setOffline(true);
    await expect(page.locator('body')).toContainText(/sin conexión/i, { timeout: 15_000 });
    await page.getByRole('button', { name: /Pagar ahora/ }).click();
    await page.getByRole('button', { name: /Completar Pago/ }).click();
    await expect(page.locator('body')).toContainText(/Venta guardada sin conexión/, { timeout: 15_000 });

    // La operación quedó en la cola local, pendiente, con su carga útil.
    const queued = await readQueue(page);
    expect(queued.length).toBe(before + 1);
    const entry = queued[queued.length - 1];
    expect(entry.status).toBe('pending');
    expect(entry.payload.details.length).toBeGreaterThan(0);
    expect(entry.payload.cash_drawer_id).toBeTruthy();

    // Restaurar la conexión: la cola se sincroniza sola (y el POS puede recargarse al terminar, comportamiento existente).
    await context.setOffline(false);
    await expect.poll(async () => ((await readQueueSafe(page)) || []).find((q) => q.id === entry.id)?.status, { timeout: 45_000 }).toBe('synced');
    await expect(page.locator('body')).toContainText(/En línea/);
    await expect(page).toHaveURL(/\/app\/pos/);
  });
});
