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

  // Solo en pruebas: sube desde un nodo del DOM hasta el componente de validación y devuelve su instancia pública
  // (Vue 3 / @vue/compat: `__vueParentComponent`; Vue 2: `__vue__`).
  const findComponent = (page, selector, componentName) =>
    page.evaluateHandle(
      ({ selector: sel, name }) => {
        const el = document.querySelector(sel);
        let vm = el && (el.__vueParentComponent ? el.__vueParentComponent.proxy : el.__vue__);
        while (vm) {
          const own = (vm.$options && vm.$options.name) || (vm.$ && vm.$.type && vm.$.type.name);
          if (own === name) return vm;
          vm = vm.$parent;
        }
        return null;
      },
      { selector, name: componentName }
    );

  const withText = (modal) => modal.locator('.invalid-feedback').filter({ hasText: /\S/ });

  test('API del contrato: setErrors (errores del servidor) y reset sobre provider y observer', async ({ page }) => {
    await page.goto('/app/marketing/templates/email');
    await waitForApp(page);
    await page.getByRole('button', { name: /Nueva plantilla/ }).click();
    const modal = page.locator('.modal.show');
    await expect(modal).toContainText(/Nueva plantilla/);
    await modal.locator('input').first().fill('E2E servidor');
    await expect(withText(modal)).toHaveCount(0);

    // provider.setErrors: el mensaje del servidor se pinta con el mismo slot prop `errors`
    const provider = await findComponent(page, '.modal.show input', 'PxValidationProvider');
    expect(await provider.evaluate((vm) => Boolean(vm))).toBe(true);
    await provider.evaluate((vm) => vm.setErrors(['Ya existe una plantilla con ese nombre']));
    await expect(withText(modal).first()).toHaveText('Ya existe una plantilla con ese nombre');
    await expect(modal.locator('input.is-invalid')).toHaveCount(1);

    // observer.reset: limpia errores y flags de todos los campos; el v-model se conserva
    const observer = await findComponent(page, '.modal.show input', 'PxValidationObserver');
    await observer.evaluate((vm) => vm.reset());
    await expect(withText(modal)).toHaveCount(0);
    await expect(modal.locator('input.is-invalid')).toHaveCount(0);
    await expect(modal.locator('input').first()).toHaveValue('E2E servidor');

    // observer.validate() sobre un formulario válido a medias devuelve false y marca el campo pendiente
    const ok = await observer.evaluate((vm) => vm.validate());
    expect(ok).toBe(false);
    await expect(modal.locator('textarea.is-invalid')).toHaveCount(1);
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
