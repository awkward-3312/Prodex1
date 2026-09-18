const { test, expect } = require('../support/fixtures');
const { env, login, waitForApp } = require('../support/helpers');

test.use({ storageState: { cookies: [], origins: [] } });
test.beforeAll(() => env.requireSecrets());

test.describe('Login @smoke', () => {
  test('la página de login del tenant renderiza el formulario', async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('#email')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
    await expect(page.locator('#login_submit_btn')).toBeEnabled();
  });

  test('una ruta protegida sin sesión no expone el SPA', async ({ page }) => {
    await page.goto('/app/dashboard');
    await expect(page).toHaveURL(/\/login/);
  });

  test('credenciales inválidas se rechazan y el usuario permanece en /login', async ({ page }) => {
    await login(page, env.adminEmail, `${env.adminPassword}-incorrecta`);
    await page.waitForTimeout(1500);
    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('#password')).toBeVisible();
  });

  test('credenciales válidas llevan al panel', async ({ page }) => {
    await login(page, env.adminEmail, env.adminPassword);
    await page.waitForURL(/\/app\/dashboard/, { timeout: 30_000 });
    await waitForApp(page);
    await expect(page.locator('nav[aria-label="Navegación principal"]')).toBeVisible();
  });
});
