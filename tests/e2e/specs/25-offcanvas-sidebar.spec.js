const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

// `b-sidebar` (BootstrapVue 2) → BOffcanvas (BootstrapVueNext) con el wrapper BSidebar, y `v-b-toggle` → directiva de BVN. 12 pantallas de lista con
// panel de filtros. Comportamiento conservado de BV2: sin backdrop, la página sigue con scroll, ESC y la cruz cierran, se abre desde el botón,
// 320px, posición derecha física (también en RTL), se desmonta con la vista.

const PAGES = [
  ['tareas', '/app/tasks/list'],
  ['proyectos', '/app/projects/list'],
  ['reservas', '/app/bookings/list'],
  ['empleados', '/app/hrm/employees/list'],
  ['clientes', '/app/People/Customers'],
  ['proveedores', '/app/People/Suppliers'],
  ['ajustes', '/app/adjustments/list-classic'],
  ['productos', '/app/products/list-classic'],
  ['traslados', '/app/transfers/list-classic'],
  ['daños', '/app/damages/list-classic'],
  ['pagos devoluciones de ventas', '/app/reports/payments_sales_returns'],
  ['pagos devoluciones de compras', '/app/reports/payments_purchases_returns'],
];
const TOGGLE = "[aria-controls='sidebar-right'], [aria-controls='booking-filter-sidebar']";

test.describe('Offcanvas (antes b-sidebar) @smoke', () => {
  test.use({ storageState: path.join(env.authDir, 'admin.json') });

  for (const [name, url] of PAGES) {
    test(`${name}: abrir con v-b-toggle, cerrar con la cruz y con ESC; sin backdrop ni bloqueo de scroll`, async ({ page }) => {
      await page.goto(url);
      await waitForApp(page);
      const toggle = page.locator(TOGGLE).first();
      await expect(toggle).toBeVisible();
      const panel = page.locator('.offcanvas.b-sidebar');
      await expect(panel).toBeAttached();
      await expect(panel).toBeHidden();

      await toggle.click();
      await expect(panel).toBeVisible();
      await expect(panel).toHaveClass(/show/);
      await expect(panel).toHaveClass(/offcanvas-end/);
      const vp = page.viewportSize();
      // la transición de entrada (0,3 s) debe terminar pegada al borde derecho
      await expect.poll(async () => { const b = await panel.boundingBox(); return Math.round(b.x + b.width); }).toBe(vp.width);
      expect(Math.round((await panel.boundingBox()).width)).toBe(320);
      await expect(page.locator('.offcanvas-backdrop')).toHaveCount(0);
      expect(await page.evaluate(() => getComputedStyle(document.body).overflow)).not.toBe('hidden');
      await expect(panel.locator('.offcanvas-title')).not.toHaveText('');

      await expect(panel).not.toHaveClass(/showing/); // fin de la transición de entrada
      // cruz
      await panel.locator('.btn-close').click();
      await expect(panel).toBeHidden();
      // ESC
      await toggle.click();
      await expect(panel).toBeVisible();
      await expect(panel).not.toHaveClass(/showing/);
      await page.keyboard.press('Escape');
      await expect(panel).toBeHidden();
      // el mismo botón alterna
      await toggle.click();
      await expect(panel).toBeVisible();
      await expect(panel).not.toHaveClass(/showing/);
      await toggle.click({ force: true });
      await expect(panel).toBeHidden();
    });
  }

  test('el contenido del panel es interactivo (filtro de fechas) y la página de fondo no se bloquea', async ({ page }) => {
    await page.goto('/app/tasks/list');
    await waitForApp(page);
    await page.locator(TOGGLE).first().click();
    const panel = page.locator('.offcanvas.b-sidebar');
    await expect(panel).toBeVisible();
    const date = panel.locator('input[type=date]').first();
    await date.fill('2026-02-01');
    await expect(date).toHaveValue('2026-02-01');
    // el contenido de fondo sigue recibiendo clics (sin backdrop)
    await page.getByRole('button', { name: /Lista/ }).first().click({ trial: true });
  });

  test('cambio de vista: el panel se desmonta con la vista y no quedan overlays', async ({ page }) => {
    await page.goto('/app/tasks/list');
    await waitForApp(page);
    await page.locator(TOGGLE).first().click();
    await expect(page.locator('.offcanvas.b-sidebar')).toBeVisible();
    await page.evaluate(() => document.querySelector('#app').__vue_app__.config.globalProperties.$router.push('/app/projects/list'));
    await expect(page).toHaveURL(/projects\/list/);
    await expect(page.locator('.offcanvas.b-sidebar.show')).toHaveCount(0);
    await expect(page.locator('.offcanvas-backdrop')).toHaveCount(0);
    // y en la vista nueva abre otra instancia sin arrastrar estado
    await page.locator(TOGGLE).first().click();
    await expect(page.locator('.offcanvas.b-sidebar.show')).toHaveCount(1);
  });

  test('RTL: el panel sigue pegado al borde derecho (posición física, igual que b-sidebar)', async ({ page }) => {
    await page.goto('/app/tasks/list');
    await waitForApp(page);
    await page.evaluate(() => document.documentElement.setAttribute('dir', 'rtl'));
    await page.locator(TOGGLE).first().click();
    const panel = page.locator('.offcanvas.b-sidebar');
    await expect(panel).toBeVisible();
    await expect.poll(async () => { const b = await panel.boundingBox(); return Math.round(b.x + b.width); }).toBe(page.viewportSize().width);
  });

  test('móvil (390px): el panel no desborda y se cierra con la cruz', async ({ browser }) => {
    const ctx = await browser.newContext({ baseURL: env.baseURL, viewport: { width: 390, height: 844 }, storageState: path.join(env.authDir, 'admin.json') });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto('/app/tasks/list');
    await waitForApp(page);
    await page.locator(TOGGLE).first().click({ force: true });
    const panel = page.locator('.offcanvas.b-sidebar');
    await expect(panel).toBeVisible();
    await expect.poll(async () => { const b = await panel.boundingBox(); return Math.round(b.x + b.width); }).toBeLessThanOrEqual(391);
    expect((await panel.boundingBox()).x).toBeGreaterThanOrEqual(0);
    await expect(panel).not.toHaveClass(/showing/);
    await panel.locator('.btn-close').click();
    await expect(panel).toBeHidden();
    expect(errors).toEqual([]);
    await ctx.close();
  });
});
