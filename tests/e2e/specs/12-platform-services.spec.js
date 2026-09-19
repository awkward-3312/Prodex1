const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

test.use({ storageState: path.join(env.authDir, 'admin.json') });

// Servicios de plataforma (resources/src/platform): eventos, notificaciones, modales por id y confirmaciones.
// Se prueban a través de vistas ya migradas a esos servicios, con datos propios del test (no consumen el demo).
test.describe('Servicios de plataforma @smoke', () => {
  test('window.Fire es el bus propio (no una instancia de Vue) y sigue funcionando en el bundle real', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForApp(page);
    const result = await page.evaluate(() => {
      const fire = window.Fire;
      const seen = [];
      const handler = (...a) => seen.push(a);
      fire.$on('e2e-event', handler);
      fire.$emit('e2e-event', 1, 'x');
      fire.$off('e2e-event', handler);
      fire.$emit('e2e-event', 2);
      const once = [];
      fire.$once('e2e-once', (v) => once.push(v));
      fire.$emit('e2e-once', 'a');
      fire.$emit('e2e-once', 'b');
      return { isVue: !!(fire._isVue || fire.$options || fire.$el), seen, once, methods: ['$on', '$off', '$once', '$emit'].map((m) => typeof fire[m]) };
    });
    expect(result.isVue).toBe(false);
    expect(result.methods).toEqual(['function', 'function', 'function', 'function']);
    expect(result.seen).toEqual([[1, 'x']]);
    expect(result.once).toEqual(['a']);
  });

  test('plantilla de marketing: modal por id, notificación y confirmación (cancelar y aceptar)', async ({ page }) => {
    const name = `E2E plantilla ${Date.now()}`;
    await page.goto('/app/marketing/templates/email');
    await waitForApp(page);
    const rows = page.locator('tbody tr').filter({ hasText: name });

    // modals.show("New_Template")
    await page.getByRole('button', { name: /Nueva plantilla/ }).click();
    const modal = page.locator('.modal.show');
    await expect(modal).toContainText(/Nueva plantilla/);
    await modal.locator('input').first().fill(name);
    await modal.locator('textarea').last().fill('Hola {customer_name}');
    await modal.locator('form').evaluate((f) => f.requestSubmit());

    // modals.hide + notifications.notify (toast de BootstrapVue con el mismo texto de siempre)
    await expect(page.locator('.modal.show')).toHaveCount(0, { timeout: 15_000 });
    await expect(page.locator('.b-toast').first()).toContainText(/Creado correctamente/, { timeout: 15_000 });
    await expect(rows).toHaveCount(1);

    // confirmDialog (SweetAlert2): cancelar no borra
    await rows.locator('a.cursor-pointer').last().click();
    await expect(page.locator('.swal2-popup')).toBeVisible();
    await page.locator('.swal2-cancel').click();
    await expect(page.locator('.swal2-popup')).toHaveCount(0);
    await expect(rows).toHaveCount(1);

    // confirmDialog: aceptar borra y avisa
    await rows.locator('a.cursor-pointer').last().click();
    await page.locator('.swal2-confirm').click();
    await expect(rows).toHaveCount(0, { timeout: 15_000 });
  });

  test('activos: la confirmación con presentación "modal" (msgBoxConfirm) cancela sin borrar', async ({ page }) => {
    await page.goto('/app/assets/list');
    await waitForApp(page);
    const rows = page.locator('tbody tr');
    await expect(rows.first()).toBeVisible({ timeout: 20_000 });
    const before = await rows.count();

    await rows.first().locator('button.btn-outline-danger').click();
    const box = page.locator('.modal.show');
    await expect(box).toBeVisible();
    await box.getByRole('button', { name: /cancel/i }).click();
    await expect(page.locator('.modal.show')).toHaveCount(0);
    expect(await rows.count()).toBe(before);
  });
});
