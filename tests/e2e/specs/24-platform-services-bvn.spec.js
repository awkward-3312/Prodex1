const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

// Servicios de plataforma sobre BootstrapVueNext (notifications, modals, confirm de presentación "modal"): sin `$bvToast` / `$bvModal`.
// Las vistas siguen llamando a `notifications.notify`, `modals.show/hide` y `confirm(...)`; el orquestador de BVN se monta una vez en <body>.
// `$platform` (globalProperties) expone los tres servicios para plantillas y pruebas.

const call = (page, fn, arg) =>
  page.evaluate(
    ({ src, arg: a }) => {
      const p = document.querySelector('#app').__vue_app__.config.globalProperties.$platform;
      // eslint-disable-next-line no-new-func
      return new Function('p', 'a', `return (${src})(p, a)`)(p, a);
    },
    { src: fn.toString(), arg }
  );

test.describe('Servicios de plataforma → BootstrapVueNext @smoke', () => {
  test.use({ storageState: path.join(env.authDir, 'admin.json') });

  test.beforeEach(async ({ page }) => {
    await page.goto('/app/meeting/calendar');
    await waitForApp(page);
  });

  for (const [method, variant] of [['success', 'success'], ['error', 'danger'], ['warning', 'warning'], ['info', 'info']]) {
    test(`toast ${method}: título, texto, variante y una sola instancia`, async ({ page }) => {
      await call(page, (p, a) => p.notifications[a.m]('Texto de prueba', { title: 'Título de prueba' }), { m: method });
      const toast = page.locator('.toast');
      await expect(toast).toHaveCount(1);
      await expect(toast).toContainText('Título de prueba');
      await expect(toast).toContainText('Texto de prueba');
      await expect(toast).toHaveClass(new RegExp(`text-bg-${variant}`));
      // esquina superior derecha (como el `b-toaster-top-right` de BootstrapVue 2)
      const box = await toast.boundingBox();
      const vp = page.viewportSize();
      expect(box.x + box.width).toBeGreaterThan(vp.width - 60);
      expect(box.y).toBeLessThan(80);
    });
  }

  test('notify(msg, opts): opciones de $bvToast (title, variant, solid, autoHideDelay) y cierre automático', async ({ page }) => {
    await call(page, (p) => p.notifications.notify('Se cierra sola', { title: 'Aviso', variant: 'info', solid: true, autoHideDelay: 1200 }));
    await expect(page.locator('.toast')).toHaveCount(1);
    await expect(page.locator('.toast')).toHaveCount(0, { timeout: 8_000 });
    // el nodo se retira del orquestador (sin acumulación)
    expect(await page.locator('[data-px-bvn-orchestrator] .toast').count()).toBe(0);
  });

  test('varios toasts a la vez se apilan sin duplicarse y se pueden cerrar con la cruz', async ({ page }) => {
    await call(page, (p) => { p.notifications.success('uno'); p.notifications.error('dos'); p.notifications.info('tres'); });
    await expect(page.locator('.toast')).toHaveCount(3);
    await page.locator('.toast .btn-close').first().click();
    await expect(page.locator('.toast')).toHaveCount(2);
  });

  test('el toast sobrevive a la navegación entre vistas (orquestador global, sin errores)', async ({ page }) => {
    await call(page, (p) => p.notifications.notify('Persistente', { title: 'Nav', variant: 'success', solid: true, autoHideDelay: 6000 }));
    await page.evaluate(() => document.querySelector('#app').__vue_app__.config.globalProperties.$router.push('/app/marketing/campaigns'));
    await expect(page.locator('.toast')).toHaveCount(1);
    await expect(page.locator('.toast')).toContainText('Persistente');
  });

  test('confirm (presentación modal): aceptar → true, cancelar → false, cerrar con ESC → false; título y textos de botones', async ({ page }) => {
    const ask = () => page.evaluate(() => {
      const p = document.querySelector('#app').__vue_app__.config.globalProperties.$platform;
      window.__confirm = 'pending';
      p.confirm('¿Continuar con la acción?', { presentation: 'modal', title: 'Confirmar acción', confirmText: 'Sí, seguir', cancelText: 'No', size: 'sm' }).then((v) => { window.__confirm = v; });
    });
    const result = () => page.evaluate(() => window.__confirm);

    await ask();
    const modal = page.locator('.modal.show');
    await expect(modal).toBeVisible();
    await expect(modal).toContainText('Confirmar acción');
    await expect(modal).toContainText('¿Continuar con la acción?');
    await expect(modal.locator('.modal-dialog')).toHaveClass(/modal-sm/);
    await modal.getByRole('button', { name: 'Sí, seguir' }).click();
    await expect.poll(result).toBe(true);
    await expect(page.locator('.modal.show')).toHaveCount(0);

    await ask();
    await page.locator('.modal.show').getByRole('button', { name: 'No' }).click();
    await expect.poll(result).toBe(false);
    await expect(page.locator('.modal.show')).toHaveCount(0);

    await ask();
    await expect(page.locator('.modal.show')).toBeVisible();
    await page.waitForTimeout(500); // transición de entrada: el foco pasa al modal y ESC ya se atiende
    await page.keyboard.press('Escape');
    await expect.poll(result).toBe(false);
    await expect(page.locator('.modal.show')).toHaveCount(0);
  });

  test('confirm repetido 4 veces: sin modales ni backdrops residuales (sin duplicados)', async ({ page }) => {
    for (let i = 0; i < 4; i++) {
      await page.evaluate(() => { window.__c = document.querySelector('#app').__vue_app__.config.globalProperties.$platform.confirm('¿Otra vez?', { presentation: 'modal' }); });
      await expect(page.locator('.modal.show')).toHaveCount(1);
      await page.locator('.modal.show .btn-primary, .modal.show .btn-outline-primary').last().click();
      expect(await page.evaluate(() => window.__c)).toBe(true);
      await expect(page.locator('.modal.show')).toHaveCount(0);
    }
    await expect(page.locator('.modal-backdrop')).toHaveCount(0);
    expect(await page.evaluate(() => document.body.classList.contains('modal-open'))).toBe(false);
  });

  test('modals.show/hide por id abre y cierra un <b-modal id> declarativo (una sola apertura)', async ({ page }) => {
    await page.goto('/app/marketing/templates/email');
    await waitForApp(page);
    await call(page, (p) => p.modals.show('New_Template'));
    await expect(page.locator('.modal.show')).toHaveCount(1);
    await call(page, (p) => p.modals.hide('New_Template'));
    await expect(page.locator('.modal.show')).toHaveCount(0);
  });

  test('las plantillas usan $modals.hide (sin $bvModal): el botón Cancelar de un modal lo cierra', async ({ page }) => {
    await page.goto('/app/organization/branches');
    await waitForApp(page);
    await page.getByRole('button', { name: /Nueva sucursal|Nuevo|Crear|Agregar/i }).first().click();
    await expect(page.locator('.modal.show')).toBeVisible();
    await page.locator('.modal.show').getByRole('button', { name: /Cancelar/ }).click();
    await expect(page.locator('.modal.show')).toHaveCount(0);
  });
});
