const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

// Gestión de <head> con Unhead (capa `metaInfo` → Unhead, sin vue-meta): títulos, titleTemplate del tenant, htmlAttrs/bodyAttrs,
// cambio de ruta sin dejar entradas anteriores, 403/404, login y páginas Blade. Los títulos esperados salen del `metaInfo.title`
// de cada vista; el guard de idioma (spanishDocumentTitleGuard) traduce algunas frases ("Purchases" → "Compras").

const suffixOf = (page) =>
  page.evaluate(() => {
    const store = document.querySelector('#app').__vue_app__.config.globalProperties.$store;
    const user = store.getters.currentUser || {};
    return user.page_title_suffix || window.__pageTitleSuffix || 'Gestión empresarial';
  });
const spaGo = (page, to) => page.evaluate((t) => document.querySelector('#app').__vue_app__.config.globalProperties.$router.push(t), to);
const headEntries = (page) => page.evaluate(() => document.querySelector('#app').__vue_app__.config.globalProperties.$unhead.entries.size);
const titleTags = (page) => page.evaluate(() => document.querySelectorAll('title').length);

test.describe('Head con Unhead — app (administrador) @smoke', () => {
  test.use({ storageState: path.join(env.authDir, 'admin.json') });

  test('título inicial = metaInfo.title de la vista + titleTemplate del tenant', async ({ page }) => {
    await page.goto('/app/products/list');
    await waitForApp(page);
    const suffix = await suffixOf(page);
    await expect(page).toHaveTitle(`Productos | ${suffix}`);
    expect(await titleTags(page), 'una sola etiqueta <title>').toBe(1);
  });

  test('cambia el título al navegar (SPA, sin recargar) y el guard de idioma sigue traduciendo', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForApp(page);
    await page.evaluate(() => { window.__noReload = 'x'; });
    const suffix = await suffixOf(page);
    const steps = [
      ['/app/products/list', `Productos | ${suffix}`],
      ['/app/sales/list', `Ventas | ${suffix}`],
      ['/app/purchases/list', `Compras | ${suffix}`], // metaInfo "Purchases" → guard → "Compras"
      ['/app/settings/Warehouses', `Almacenes / CD | ${suffix}`],
      ['/app/transfers/list', `Traslados | ${suffix}`],
    ];
    for (const [route, title] of steps) {
      await spaGo(page, route);
      await expect(page, route).toHaveTitle(title);
    }
    expect(await page.evaluate(() => window.__noReload)).toBe('x');
    expect(await titleTags(page)).toBe(1);
  });

  test('atrás / adelante restauran el título de cada pantalla', async ({ page }) => {
    await page.goto('/app/products/list');
    await waitForApp(page);
    const suffix = await suffixOf(page);
    await spaGo(page, '/app/sales/list');
    await expect(page).toHaveTitle(`Ventas | ${suffix}`);
    await spaGo(page, '/app/adjustments/list');
    await expect(page).toHaveTitle(`Ajustes de inventario | ${suffix}`);
    await page.goBack();
    await expect(page).toHaveTitle(`Ventas | ${suffix}`);
    await page.goBack();
    await expect(page).toHaveTitle(`Productos | ${suffix}`);
    await page.goForward();
    await expect(page).toHaveTitle(`Ventas | ${suffix}`);
  });

  test('metaInfo() con función: el título reacciona a datos y a la ruta (traslado: nuevo / editar)', async ({ page }) => {
    await page.goto('/app/transfers/store');
    await waitForApp(page);
    const suffix = await suffixOf(page);
    await expect(page).toHaveTitle(`Nuevo traslado | ${suffix}`);
    await spaGo(page, '/app/products/list');
    await expect(page).toHaveTitle(`Productos | ${suffix}`);
  });

  test('titleTemplate reactivo: el sufijo del usuario/tenant se refleja en el título', async ({ page }) => {
    await page.goto('/app/products/list');
    await waitForApp(page);
    await page.evaluate(() => {
      const store = document.querySelector('#app').__vue_app__.config.globalProperties.$store;
      store.getters.currentUser.page_title_suffix = 'Sufijo E2E';
    });
    await expect(page).toHaveTitle('Productos | Sufijo E2E');
  });

  test('sin entradas huérfanas: volver a una pantalla deja el mismo número de entradas y un solo <title>', async ({ page }) => {
    await page.goto('/app/products/list');
    await waitForApp(page);
    await page.waitForTimeout(500);
    const base = await headEntries(page);
    for (const r of ['/app/sales/list', '/app/purchases/list', '/app/products/list', '/app/sales/list', '/app/products/list']) {
      await spaGo(page, r);
      await page.waitForTimeout(400);
    }
    await expect.poll(() => headEntries(page), { timeout: 10_000 }).toBe(base);
    expect(await titleTags(page)).toBe(1);
    // ningún resto de vue-meta en el DOM
    expect(await page.locator('[data-vue-meta], [data-vue-meta-server-rendered]').count()).toBe(0);
    expect(await page.evaluate(() => Boolean(document.querySelector('#app').__vue_app__.config.globalProperties.$meta))).toBe(false);
  });

  test('htmlAttrs y bodyAttrs de App.vue: lang, dir y clases (sin duplicados) también tras navegar', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForApp(page);
    const attrs = () =>
      page.evaluate(() => ({
        lang: document.documentElement.getAttribute('lang'),
        dir: (document.documentElement.getAttribute('dir') || 'ltr').trim() || 'ltr',
        body: [...document.body.classList],
      }));
    let a = await attrs();
    expect(a.lang).toBe('es');
    expect(a.dir).toBe('ltr');
    expect(a.body).toContain('text-left');
    expect(new Set(a.body).size).toBe(a.body.length);
    await spaGo(page, '/app/sales/list');
    await expect(page).toHaveTitle(/Ventas/);
    a = await attrs();
    expect(a.lang).toBe('es');
    expect(a.body).toContain('text-left');
    expect(new Set(a.body).size).toBe(a.body.length);
  });

  test('404: URL inexistente muestra el título de NotFound', async ({ page }) => {
    await page.goto('/app/no-existe-head-e2e');
    await waitForApp(page);
    await expect(page).toHaveTitle(/404/);
    await expect(page).toHaveTitle(new RegExp(`\\| ${(await suffixOf(page)).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`));
  });

  test('vista de recibo POS (usa el marcador propio, ya no metaInfo.title): carga con su título y sin errores', async ({ page }) => {
    await page.goto('/app/settings/pos_receipt');
    await waitForApp(page);
    const suffix = await suffixOf(page);
    await expect(page).toHaveTitle(`POS Receipt | ${suffix}`);
    await spaGo(page, '/app/products/list');
    await expect(page).toHaveTitle(`Productos | ${suffix}`);
    await spaGo(page, '/app/settings/pos_receipt');
    await expect(page).toHaveTitle(`POS Receipt | ${suffix}`);
  });
});

test.describe('Head con Unhead — usuario restringido (403) @smoke', () => {
  test.use({ storageState: path.join(env.authDir, 'restricted.json') });

  test('403: la pantalla de acceso denegado fija su título', async ({ page }) => {
    await page.goto('/app/products/list');
    await waitForApp(page);
    await expect(page.locator('body')).toContainText(/usted no está autorizado/i);
    await expect(page).toHaveTitle(/403/);
  });
});

test.describe('Head con Unhead — login y páginas Blade @smoke', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('login: entrypoint `login` con Unhead conserva el título del servidor y arranca sin errores', async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('#email')).toBeVisible();
    const title = await page.title();
    expect(title.length).toBeGreaterThan(0);
    expect(title).not.toContain(' | ');
    expect(await titleTags(page)).toBe(1);
    // el bundle del entrypoint `login` lleva Unhead y ya no vue-meta
    const bundle = await page.evaluate(async () => (await fetch('/js/login.min.js')).text());
    expect(bundle).toContain('unhead');
    expect(bundle).not.toMatch(/data-vue-meta|vue-meta\/dist/);
    expect(await page.evaluate(() => document.documentElement.getAttribute('lang'))).toBe('es');
  });

  test('reset/forgot (Blade): el título sigue siendo el del servidor', async ({ page }) => {
    const login = await (async () => { await page.goto('/login'); return page.title(); })();
    await page.goto('/password/reset');
    await expect(page.locator('body')).not.toContainText(/Página no encontrada/);
    expect(await page.title()).toBe(login);
  });

  test('portal y pantalla del cliente no usan metadata de vue-meta ni Unhead', async ({ page }) => {
    await page.goto('/portal/login');
    await expect(page.locator('input[type="email"]')).toBeVisible({ timeout: 20_000 });
    expect(await page.evaluate(() => { const el = [...document.querySelectorAll('body *')].find((e) => e.__vue_app__); return Boolean(el && el.__vue_app__.config.globalProperties.$unhead); })).toBe(false);
    expect(await page.title()).toBeTruthy();
  });
});
