const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env } = require('../support/helpers');

// Entrypoints de Vue distintos del SPA principal y del login: `portal` (portal del cliente) y `customer-display`
// (pantalla del cliente del POS). No basta con que webpack compile: cada uno debe montar en el navegador.
test.describe('Entrypoints portal y customer-display @smoke', () => {
  test.describe('portal del cliente', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('/portal/login monta la app del portal y muestra el formulario', async ({ page }) => {
      await page.goto('/portal/login');
      await expect(page.locator('input[type="email"]')).toBeVisible({ timeout: 20_000 });
      await expect(page.locator('input[type="password"]')).toBeVisible();
      await expect(page.locator('button[type="submit"]')).toBeEnabled();
      // v-model del portal (Vue 3 compat): escribir en el input actualiza el estado y el botón sigue operativo
      await page.locator('input[type="email"]').fill('cliente@example.test');
      await expect(page.locator('input[type="email"]')).toHaveValue('cliente@example.test');
    });

    test('una ruta protegida del portal sin sesión redirige al login del portal', async ({ page }) => {
      await page.goto('/portal/invoices');
      await expect(page).toHaveURL(/\/portal\/login/);
      await expect(page.locator('input[type="password"]')).toBeVisible({ timeout: 20_000 });
    });
  });

  test.describe('pantalla del cliente', () => {
    test.use({ storageState: path.join(env.authDir, 'admin.json') });

    test('con un token válido monta la vista y muestra el resumen a pagar', async ({ page }) => {
      await page.goto('/app/dashboard');
      const url = await page.evaluate(async () => {
        const xsrf = decodeURIComponent((document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        const r = await fetch('/api/customer-display/generate', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf },
        });
        return r.ok ? (await r.json()).url : `HTTP ${r.status}`;
      });
      expect(url).toMatch(/\/customer-display\?token=/);
      await page.goto(url);
      await expect(page.locator('.customer-display-container')).toBeVisible({ timeout: 20_000 });
      await expect(page.locator('.payable-label')).toBeVisible();
      await expect(page.locator('.store-name')).not.toBeEmpty();
    });

    test('sin token la pantalla del cliente responde 403', async ({ page }) => {
      const response = await page.goto('/customer-display', { waitUntil: 'commit' });
      expect(response.status()).toBe(403);
    });
  });
});
