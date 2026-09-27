const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp, openPos, registerPill, setLanguageViaPosMenu } = require('../support/helpers');

test.use({ storageState: path.join(env.authDir, 'admin.json') });

// Idiomas del SPA con el selector real del POS. `flag` = bandera del menú; `dir` = dirección esperada del documento.
const LANGUAGES = [
  { code: 'es', flag: 'es', dir: 'ltr', cart: /Carrito Actual/ },
  { code: 'en', flag: 'gb', dir: 'ltr', cart: /Current Cart/ },
  { code: 'ar', flag: 'sa', dir: 'rtl', cart: /السلة الحالية/ },
];

const MODULES = [
  ['Ventas', /\/app\/sales\/list/],
  ['Inventario', /\/app\/products\/list/],
  ['Compras', /\/app\/purchases\/list/],
  ['Panel', /\/app\/dashboard/],
];

const hasHorizontalOverflow = (page) =>
  page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
const documentDir = (page) => page.evaluate(() => (document.documentElement.getAttribute('dir') || 'ltr').trim() || 'ltr');

// El idioma es un ajuste del tenant (el selector lo guarda en el servidor), así que estos tests van en serie y al final
// devuelven el tenant a español.
test.describe.serial('Idiomas: es / en / ar (RTL) @smoke', () => {
  test.afterAll(async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: path.join(env.authDir, 'admin.json'), baseURL: env.baseURL });
    const page = await ctx.newPage();
    await openPos(page, { selectWarehouse: false });
    await setLanguageViaPosMenu(page, 'es', 'es');
    await ctx.close();
  });

  for (const lang of LANGUAGES) {
    test(`${lang.code}: POS y shell cargan, dirección ${lang.dir.toUpperCase()}, sin desbordes ni errores`, async ({ page }) => {
      await openPos(page, { selectWarehouse: false });
      await setLanguageViaPosMenu(page, lang.flag, lang.code);

      // El POS se muestra en el idioma elegido y la preferencia persiste.
      await expect(page.locator('body')).toContainText(lang.cart, { timeout: 20_000 });
      expect(await page.evaluate(() => localStorage.getItem('language'))).toBe(lang.code);
      await expect(registerPill(page)).toBeVisible();
      expect(await documentDir(page)).toBe(lang.dir);
      expect(await hasHorizontalOverflow(page), 'el POS no debe desbordar horizontalmente').toBe(false);

      // Tras recargar el idioma (y la dirección) se conservan: vienen del servidor, no solo de memoria.
      await page.reload();
      await expect(page.locator('body')).toContainText(lang.cart, { timeout: 30_000 });
      expect(await documentDir(page)).toBe(lang.dir);

      // Shell y navegación principal.
      await page.goto('/app/dashboard');
      await waitForApp(page);
      const rail = page.locator('nav[aria-label="Navegación principal"]');
      await expect(rail).toBeVisible();
      const box = await rail.boundingBox();
      const viewport = page.viewportSize();
      if (lang.dir === 'rtl') {
        expect(Math.round(box.x + box.width), 'en RTL el menú lateral va a la derecha').toBe(viewport.width);
      } else {
        expect(Math.round(box.x), 'en LTR el menú lateral va a la izquierda').toBe(0);
      }
      expect(await documentDir(page)).toBe(lang.dir);

      for (const [label, url] of MODULES) {
        await rail.getByRole('link', { name: label, exact: true }).click();
        await expect(page, `módulo ${label} en ${lang.code}`).toHaveURL(url, { timeout: 20_000 });
        await waitForApp(page);
        await expect(page.locator('body')).not.toContainText(/usted no está autorizado/);
        expect(await hasHorizontalOverflow(page), `${label} no debe desbordar en ${lang.code}`).toBe(false);
      }
    });
  }

  test('volver a español devuelve la dirección LTR', async ({ page }) => {
    await openPos(page, { selectWarehouse: false });
    await setLanguageViaPosMenu(page, 'es', 'es');
    await expect(page.locator('body')).toContainText(/Carrito Actual/, { timeout: 20_000 });
    expect(await documentDir(page)).toBe('ltr'); // volver a es devuelve LTR
  });
});
