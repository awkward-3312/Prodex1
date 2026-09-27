const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

// Bootstrap 5 + BootstrapVueNext (fase 1): puente CSS aditivo (`ms-*`, `me-*`, `text-end`…) sobre la hoja BS4, componentes
// BootstrapVueNext (BButton, BBadge, BAlert, BCard, BRow/BCol…) registrados localmente en pantallas de bajo riesgo, y convivencia
// con BootstrapVue 2 (toast, modal, tooltip) y Router 4. Los fixtures fallan ante pageerror / console.error / 5xx.

const spaGo = (page, to) => page.evaluate((t) => document.querySelector('#app').__vue_app__.config.globalProperties.$router.push(t), to);

const styleOf = (page, className, props, { dir = 'ltr' } = {}) =>
  page.evaluate(({ cls, ps, d }) => {
    document.documentElement.setAttribute('dir', d);
    const el = document.createElement('div');
    el.className = cls;
    el.textContent = 'x';
    document.body.appendChild(el);
    const cs = getComputedStyle(el);
    const out = {};
    ps.forEach((p) => { out[p] = cs.getPropertyValue(p); });
    el.remove();
    document.documentElement.removeAttribute('dir');
    return out;
  }, { cls: className, ps: props, d: dir });

test.describe('Bootstrap 5 + BootstrapVueNext @smoke', () => {
  test.use({ storageState: path.join(env.authDir, 'admin.json') });

  test('utilidades de Bootstrap 5: ms/me/ps/pe/text-end en LTR', async ({ page }) => {
    await page.goto('/app/organization/role-templates');
    await waitForApp(page);
    expect(await styleOf(page, 'ms-2', ['margin-left', 'margin-right'])).toEqual({ 'margin-left': '8px', 'margin-right': '0px' });
    expect(await styleOf(page, 'me-3', ['margin-left', 'margin-right'])).toEqual({ 'margin-left': '0px', 'margin-right': '16px' });
    expect(await styleOf(page, 'ps-1', ['padding-left', 'padding-right'])).toEqual({ 'padding-left': '4px', 'padding-right': '0px' });
    expect(await styleOf(page, 'pe-4', ['padding-left', 'padding-right'])).toEqual({ 'padding-left': '0px', 'padding-right': '24px' });
    expect(['right', 'end']).toContain((await styleOf(page, 'text-end', ['text-align']))['text-align']);
    expect((await styleOf(page, 'fw-bold', ['font-weight']))['font-weight']).toBe('700');
  });

  test('utilidades de Bootstrap 5 se invierten en RTL (reglas generadas con postcss-rtlcss, equivalen a bootstrap-rtl de BS4)', async ({ page }) => {
    await page.goto('/app/organization/role-templates');
    await waitForApp(page);
    expect(await styleOf(page, 'ms-2', ['margin-left', 'margin-right'], { dir: 'rtl' })).toEqual({ 'margin-left': '0px', 'margin-right': '8px' });
    expect(await styleOf(page, 'me-3', ['margin-left', 'margin-right'], { dir: 'rtl' })).toEqual({ 'margin-left': '16px', 'margin-right': '0px' });
    expect(await styleOf(page, 'ps-1', ['padding-left', 'padding-right'], { dir: 'rtl' })).toEqual({ 'padding-left': '0px', 'padding-right': '4px' });
    // float-start en RTL: el elemento queda pegado al borde derecho del contenedor
    const side = await page.evaluate(() => {
      document.documentElement.setAttribute('dir', 'rtl');
      const wrap = document.createElement('div');
      wrap.style.cssText = 'width:300px;position:absolute;top:0;left:0';
      wrap.innerHTML = '<div class="float-start" style="width:50px;height:10px"></div><div style="clear:both"></div>';
      document.body.appendChild(wrap);
      const r = { wrap: wrap.getBoundingClientRect(), item: wrap.firstChild.getBoundingClientRect() };
      wrap.remove();
      document.documentElement.removeAttribute('dir');
      return { itemRight: r.item.right, wrapRight: r.wrap.right };
    });
    expect(side.itemRight).toBe(side.wrapRight);
  });

  test('gap-*: utilidad de Bootstrap 5 (en la fase 1 era un no-op deliberado; con el corte, `gap-2` = .5rem)', async ({ page }) => {
    await page.goto('/app/organization/role-templates');
    await waitForApp(page);
    expect((await styleOf(page, 'gap-2', ['gap']))['gap']).toBe('8px');
  });

  test('BButton de BootstrapVueNext: variantes y emisión única del click (calendario)', async ({ page }) => {
    await page.goto('/app/meeting/calendar');
    await waitForApp(page);
    const toolbar = page.locator('.btn.btn-sm.btn-outline-secondary');
    await expect(toolbar).toHaveCount(3);
    const title = page.locator('.mt-cal-title');
    const before = await title.innerText();
    await toolbar.nth(2).click(); // mes siguiente
    await expect(title).not.toHaveText(before);
    const next = await title.innerText();
    await toolbar.nth(0).click(); // mes anterior: si el click se emitiera dos veces no volvería al mes original
    await expect(title).toHaveText(before);
    expect(next).not.toBe(before);
    // la variante outline-secondary conserva la geometría BS4 (borde 1px, alto de .btn-sm)
    const box = await toolbar.nth(1).evaluate((el) => { const cs = getComputedStyle(el); return { border: cs.borderTopWidth, radius: cs.borderTopLeftRadius }; });
    expect(box.border).toBe('1px');
  });

  test('BButton primary en plantillas de rol navega con Router 4 sin recargar', async ({ page }) => {
    await page.goto('/app/organization/role-templates');
    await waitForApp(page);
    await page.evaluate(() => { window.__noReload = 'x'; });
    const create = page.locator('button.btn.btn-primary', { hasText: 'Crear rol' });
    await expect(create).toBeVisible();
    await create.click();
    await expect(page).toHaveURL(/\/app\/User_Management\/permissions\/store/);
    expect(await page.evaluate(() => window.__noReload)).toBe('x');
  });

  test('BAlert / BBadge / BCard de BootstrapVueNext se pintan con el marcado y las clases de PRODEX (guía WooCommerce)', async ({ page }) => {
    await page.goto('/app/woocommerce');
    await waitForApp(page);
    await expect(page.locator('.card').first()).toBeVisible();
    // BBadge: `badge-<variant>` (no `text-bg-*`) para conservar el color del tenant
    const badges = await page.locator('.badge').evaluateAll((els) => els.map((e) => e.className));
    expect(badges.length).toBeGreaterThan(0);
    expect(badges.some((c) => /\bbadge-(primary|secondary|info|success|warning|danger|light|dark)\b/.test(c))).toBe(true);
    expect(badges.every((c) => !/text-bg-/.test(c))).toBe(true);
    // BAlert: `alert alert-<variant>`
    const alerts = await page.locator('.alert').evaluateAll((els) => els.map((e) => e.className));
    if (alerts.length) expect(alerts.every((c) => /\balert-(primary|secondary|info|success|warning|danger|light|dark)\b/.test(c))).toBe(true);
  });

  test('BRow / BCol (no autorizado y no encontrado) mantienen la rejilla', async ({ page }) => {
    await page.goto('/app/no-existe-e2e-bs5');
    await waitForApp(page);
    await expect(page.locator('.row').first()).toBeVisible();
    await expect(page.locator('.row > [class*="col"]').first()).toBeVisible();
  });

  test('navegación SPA entre pantallas migradas (Router 4) sin recarga', async ({ page }) => {
    await page.goto('/app/meeting/calendar');
    await waitForApp(page);
    await page.evaluate(() => { window.__noReload = 'x'; });
    for (const [route, selector] of [
      ['/app/organization/role-templates', '.card'],
      ['/app/marketing/dashboard', '.card'],
      ['/app/woocommerce', '.card'],
      ['/app/support/tickets', '.row'],
      ['/app/meeting/calendar', '.mt-cal-title'],
    ]) {
      await spaGo(page, route);
      await expect(page.locator(selector).first(), route).toBeVisible({ timeout: 15_000 });
    }
    expect(await page.evaluate(() => window.__noReload)).toBe('x');
  });

  test('convivencia con BootstrapVue 2: modal simple (BV2) se abre/cierra y no hereda reglas .modal de BS5', async ({ page }) => {
    await page.goto('/app/marketing/templates/email');
    await waitForApp(page);
    await page.getByRole('button', { name: /Nueva plantilla/ }).click();
    const modal = page.locator('.modal.show');
    await expect(modal).toBeVisible();
    // marcado/estilo BS4 de BootstrapVue 2: cabecera con .close (no .btn-close) y diálogo centrado por PRODEX
    await expect(modal.locator('.modal-header .close, .modal-header .btn-close').first()).toBeVisible();
    const backdrop = await page.locator('.modal-backdrop').count();
    expect(backdrop).toBeGreaterThan(0);
    await modal.locator('.modal-header .close, .modal-header .btn-close').first().click();
    await expect(page.locator('.modal.show')).toHaveCount(0);
    await expect(page.locator('.modal-backdrop')).toHaveCount(0);
  });

  test('BVN no lleva su CSS global y Bootstrap 4 no está: `.card-deck` ya no existe; `.container` conserva el gutter de la aplicación', async ({ page }) => {
    await page.goto('/app/organization/role-templates');
    await waitForApp(page);
    const rules = await page.evaluate(() => {
      const probe = (cls) => { const el = document.createElement('div'); el.className = cls; document.body.appendChild(el); const cs = getComputedStyle(el); const r = { display: cs.display, paddingLeft: cs.paddingLeft }; el.remove(); return r; };
      return { deck: probe('card-deck'), container: probe('container') };
    });
    expect(rules.deck.display).toBe('block'); // .card-deck existía en BS4 y desaparece en BS5
    expect(rules.container.paddingLeft).toBe('15px'); // `$grid-gutter-width` de la aplicación (30px), no el 24px de BS5
  });
});

test.describe('Bootstrap 5 + BootstrapVueNext — RTL y móvil', () => {
  test.use({ storageState: path.join(env.authDir, 'admin.json') });

  test('pantallas migradas en RTL: sin desbordamiento horizontal y con el contenido visible', async ({ page }) => {
    for (const url of ['/app/organization/role-templates', '/app/woocommerce', '/app/meeting/calendar']) {
      await page.goto(url);
      await waitForApp(page);
      await page.evaluate(() => document.documentElement.setAttribute('dir', 'rtl'));
      await expect(page.locator('.card').first()).toBeVisible();
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(overflow, `${url} RTL`).toBeLessThanOrEqual(1);
    }
  });

  test('pantallas migradas en móvil (390px): sin desbordamiento horizontal', async ({ browser }) => {
    const ctx = await browser.newContext({ baseURL: env.baseURL, viewport: { width: 390, height: 844 }, storageState: path.join(env.authDir, 'admin.json') });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    for (const url of ['/app/organization/role-templates', '/app/woocommerce', '/app/meeting/calendar', '/app/marketing/dashboard']) {
      await page.goto(url);
      await waitForApp(page);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(overflow, `${url} móvil`).toBeLessThanOrEqual(1);
    }
    expect(errors).toEqual([]);
    await ctx.close();
  });
});
