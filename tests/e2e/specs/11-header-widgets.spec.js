const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

test.use({ storageState: path.join(env.authDir, 'admin.json') });

// Regresión: prodex-transfer-logistics.js llamaba insertBefore() sobre un nodo que no era hijo directo del header del
// shell px-next (NotFoundError en cada mutación del DOM). Estaba en la allowlist; ya no. Aquí se comprueba explícitamente.
test.describe('Widgets globales del header @smoke', () => {
  test('el script de logística de transferencias no lanza excepciones al montarse ni al re-renderizar', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    await page.goto('/app/dashboard');
    await waitForApp(page);
    await page.waitForTimeout(2500); // deja correr varias mutaciones del DOM (MutationObserver del script)
    await page.getByRole('link', { name: 'Ventas', exact: true }).click();
    await expect(page).toHaveURL(/\/app\/sales\/list/);
    await page.waitForTimeout(1500);

    expect(errors.filter((m) => /insertBefore|not a child of this node/.test(m))).toEqual([]);
  });
});
