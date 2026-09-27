// Proyecto "setup": inicia sesión UNA vez por rol y guarda el estado (cookies) en .artifacts/auth/.
// La prueba de login como tal vive en 01-login.spec.js; esto solo prepara sesiones reutilizables.
const fs = require('fs');
const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, login, waitForApp } = require('../support/helpers');

test.beforeAll(() => env.requireSecrets());

for (const [role, email, password] of [
  ['admin', env.adminEmail, env.adminPassword],
  ['restricted', env.restrictedEmail, env.restrictedPassword],
]) {
  test(`sesión ${role}`, async ({ page }) => {
    fs.mkdirSync(env.authDir, { recursive: true });
    await login(page, email, password);
    await page.waitForURL(/\/app\//, { timeout: 30_000 });
    await waitForApp(page);
    await page.context().storageState({ path: path.join(env.authDir, `${role}.json`) });
  });
}
