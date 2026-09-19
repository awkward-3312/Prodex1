const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

test.use({ storageState: path.join(env.authDir, 'admin.json') });

// Capa de validación PRODEX (resources/src/platform/validation) sobre vee-validate 3, a través del bundle real.
// Formulario de plantillas de marketing: <px-validation-observer ref> + <px-validation-provider v-slot="{ errors, dirty, validated, valid }">.
test.describe('Capa de validación @smoke', () => {
  test('el observer bloquea el envío vacío, muestra errores por campo y detecta v-model', async ({ page }) => {
    const posts = [];
    page.on('request', (r) => {
      if (r.method() === 'POST' && /marketing\/templates/.test(r.url())) posts.push(r.url());
    });

    await page.goto('/app/marketing/templates/email');
    await waitForApp(page);
    await page.getByRole('button', { name: /Nueva plantilla/ }).click();
    const modal = page.locator('.modal.show');
    await expect(modal).toContainText(/Nueva plantilla/);

    // observer.validate() → false: dos campos inválidos, sin petición al servidor y con el modal abierto.
    await modal.locator('form').evaluate((f) => f.requestSubmit());
    const errors = modal.locator('.invalid-feedback');
    await expect(errors).toHaveCount(2);
    await expect(errors.first()).toHaveText('Este campo es obligatorio');
    await expect(errors.nth(1)).toHaveText('Este campo es obligatorio');
    expect(posts).toEqual([]);

    // el provider detecta el v-model de <b-form-input>: escribir limpia solo ese error (el otro campo sigue inválido).
    await modal.locator('input').first().fill('E2E validación');
    await expect(modal.locator('input.is-invalid')).toHaveCount(0);
    await expect(modal.locator('textarea.is-invalid')).toHaveCount(1);
    await expect(modal.locator('.invalid-feedback').filter({ hasText: /obligatorio/ })).toHaveCount(1);

    // vaciar de nuevo el campo vuelve a marcarlo (regla `required` reevaluada al cambiar).
    await modal.locator('input').first().fill('');
    await expect(modal.locator('input.is-invalid')).toHaveCount(1);
    expect(posts).toEqual([]);
  });
});
