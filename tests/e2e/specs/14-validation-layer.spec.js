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

  const withText = (modal) => modal.locator('.invalid-feedback').filter({ hasText: /\S/ });

  // `observer.setErrors`/`provider.setErrors` (errores del servidor por campo) no tienen hoy ningún llamador real
  // en PRODEX — ninguna pantalla los invoca (0 resultados en el repo) — así que no hay un flujo de UI/red público
  // que los dispare; el contrato en sí (normaliza a arrays, no revienta sin observer) ya se prueba de verdad
  // contra el módulo real en tests/frontend/validation-contract.test.mjs, sin instancia de Vue de por medio.
  // `observer.reset()` (real: Cancelar + reabrir) y `observer.validate()` (real: envío vacío bloqueado) sí tienen
  // llamador de producción y ya se cubren con UI real — aquí, en un formulario válido a medias, sin instancia.
  test('validate() real: formulario válido a medias (un campo lleno, otro vacío) sigue bloqueando el envío', async ({ page }) => {
    const posts = [];
    page.on('request', (r) => {
      if (r.method() === 'POST' && /marketing\/templates/.test(r.url())) posts.push(r.url());
    });
    await page.goto('/app/marketing/templates/email');
    await waitForApp(page);
    await page.getByRole('button', { name: /Nueva plantilla/ }).click();
    const modal = page.locator('.modal.show');
    await expect(modal).toContainText(/Nueva plantilla/);

    // solo el primer campo (name) se llena; content queda vacío — igual que un usuario a medio formulario
    await modal.locator('input').first().fill('E2E parcial');
    await expect(withText(modal)).toHaveCount(0);

    // handleSubmit(fn) real del <form>: valida todos los campos, bloquea porque `content` sigue vacío
    await modal.locator('form').evaluate((f) => f.requestSubmit());
    await expect(modal.locator('textarea.is-invalid')).toHaveCount(1);
    await expect(withText(modal)).toHaveCount(1);
    expect(posts).toEqual([]);

    // completar el campo pendiente deja el formulario válido, listo para un envío real
    await modal.locator('textarea').first().fill('E2E contenido');
    await expect(withText(modal)).toHaveCount(0);
    await expect(modal.locator('input.is-invalid, textarea.is-invalid')).toHaveCount(0);
  });

  // Pantalla que sigue en la lista de excepciones (<validation-observer> / <validation-provider> con nombres antiguos):
  // Ajustes → Almacenes usa VField (provider) con <px-input v-model> dentro del slot y `obs.reset()` / `obs.validate()`.
  test('pantalla con alias legacy: mismo comportamiento (required, v-model, mensaje, reset al reabrir)', async ({ page }) => {
    const posts = [];
    page.on('request', (r) => {
      if (r.method() === 'POST' && /warehouses/i.test(r.url())) posts.push(r.url());
    });
    await page.goto('/app/settings/Warehouses');
    await waitForApp(page);
    await page.getByRole('button', { name: /Nuevo almacén/ }).click();
    const modal = page.locator('.pxn-modal, [role="dialog"]').filter({ hasText: /Nuevo almacén/ }).last();
    await expect(modal).toBeVisible();

    await modal.getByRole('button', { name: 'Guardar' }).click();
    const error = modal.locator('.pxn-field__error');
    await expect(error).toHaveCount(1);
    await expect(error).toContainText('Este campo es obligatorio');
    expect(posts).toEqual([]);

    // px-input con v-model dentro del slot de VField: escribir valida sin más acciones
    await modal.getByPlaceholder(/Centro de distribución/).fill('E2E almacén alias');
    await expect(error).toHaveCount(0);
    await modal.getByPlaceholder(/Centro de distribución/).fill('');
    await expect(error).toHaveCount(1);

    // cerrar y reabrir: openNew() llama a obs.reset() y no debe quedar ningún error
    await modal.getByRole('button', { name: 'Cancelar' }).click();
    await expect(modal).toBeHidden();
    await page.getByRole('button', { name: /Nuevo almacén/ }).click();
    const reopened = page.locator('.pxn-modal, [role="dialog"]').filter({ hasText: /Nuevo almacén/ }).last();
    await expect(reopened).toBeVisible();
    await expect(reopened.locator('.pxn-field__error')).toHaveCount(0);
    expect(posts).toEqual([]);
  });
});
