const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp, openPos, setLanguageViaPosMenu } = require('../support/helpers');

// Vue Router 4 (sobre Vue 3 + @vue/compat): navegación, matching, guards y redirecciones que deben ser idénticos a Router 3.
// Solo en pruebas se lee la instancia del router desde la aplicación Vue (`__vue_app__`); el código de producto no lo hace.

const escape = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const resolveIn = (page, selector, target) =>
  page.evaluate(
    ({ sel, t }) => {
      const router = document.querySelector(sel).__vue_app__.config.globalProperties.$router;
      const r = router.resolve(t);
      return { name: r.name || null, path: r.path, fullPath: r.fullPath, href: r.href, params: r.params, matched: r.matched.length };
    },
    { sel: selector, t: target }
  );

test.describe('Vue Router 4 — tenant (usuario administrador) @smoke', () => {
  test.use({ storageState: path.join(env.authDir, 'admin.json') });

  test('router-link se pinta como <a href> real, sin atributo `tag`, y navega sin recargar la página', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForApp(page);
    await page.evaluate(() => { window.__noReload = 'sigue-aqui'; });

    const nav = page.locator('nav[aria-label="Navegación principal"]');
    const link = nav.getByRole('link', { name: 'Inventario', exact: true });
    await expect(link).toHaveAttribute('href', /\/app\/products\/list$/);
    await expect(link).not.toHaveAttribute('tag', /.*/);
    await link.click();
    await expect(page).toHaveURL(/\/app\/products\/list$/);
    expect(await page.evaluate(() => window.__noReload), 'navegación SPA: la página no se recargó').toBe('sigue-aqui');

    // ningún <a> del documento arrastra el antiguo atributo tag="a" de Router 3
    expect(await page.locator('a[tag]').count()).toBe(0);
  });

  test('sidebar px-next: el módulo activo se marca y cambia con la navegación', async ({ page }) => {
    await page.goto('/app/sales/list');
    await waitForApp(page);
    const nav = page.locator('nav[aria-label="Navegación principal"]');
    await expect(nav.getByRole('link', { name: 'Ventas', exact: true })).toHaveAttribute('aria-current', /page|true/);
    await nav.getByRole('link', { name: 'Compras', exact: true }).click();
    await expect(page).toHaveURL(/\/app\/purchases\/list$/);
    await expect(nav.getByRole('link', { name: 'Compras', exact: true })).toHaveAttribute('aria-current', /page|true/);
    await expect(nav.getByRole('link', { name: 'Ventas', exact: true })).not.toHaveAttribute('aria-current', /page|true/);
  });

  test('menú de cuenta: el enlace de perfil navega y cierra el menú (@click en router-link)', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForApp(page);
    await page.locator('.pxn-userchip').first().click();
    const item = page.locator('.pxn-userchip__item[href$="/app/profile"]');
    await expect(item).toBeVisible();
    await item.click();
    await expect(page).toHaveURL(/\/app\/profile$/);
    await expect(page.locator('.pxn-userchip__item[href$="/app/profile"]')).toHaveCount(0);
  });

  test('layout legacy (largeSidebar): sigue accesible y sus enlaces navegan por router-link', async ({ page }) => {
    await page.goto('/app/dashboard/legacy');
    await waitForApp(page);
    await expect(page.locator('body')).not.toContainText(/Página no encontrada|usted no está autorizado/);
    const anchors = page.locator('a[href^="/app/"]');
    expect(await anchors.count()).toBeGreaterThan(5);
    const target = await anchors.first().getAttribute('href');
    await page.evaluate(() => { window.__noReload = 1; });
    await anchors.first().click();
    await expect(page).toHaveURL(new RegExp(escape(target)));
    expect(await page.evaluate(() => window.__noReload)).toBe(1);
  });

  test('router-link con directiva de BootstrapVue (v-b-tooltip) y `to` con nombre + params', async ({ page }) => {
    await page.goto('/app/products/list-classic');
    await waitForApp(page);
    const view = page.locator('tbody tr').first().locator('a[href*="/app/products/detail/"]');
    await expect(view).toHaveAttribute('href', /\/app\/products\/detail\/\d+$/);
    await view.hover();
    await expect(page.locator('.tooltip.show')).toContainText('View', { timeout: 10_000 });
    await view.click();
    await expect(page).toHaveURL(/\/app\/products\/detail\/\d+$/);
  });

  test('deep link + recarga: ruta profunda, ruta con parámetro y ruta con parámetro opcional', async ({ page }) => {
    for (const url of ['/app/products/list', '/app/Store/Banners/Edit', '/app/products/detail/1', '/app/reports/inactive_customers']) {
      await page.goto(url);
      await waitForApp(page);
      await page.reload();
      await waitForApp(page);
      await expect(page, url).toHaveURL(new RegExp(`${escape(url)}$`));
      await expect(page.locator('body'), url).not.toContainText(/Página no encontrada/);
    }
  });

  test('atrás / adelante del navegador recorren el historial sin perder la sesión', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForApp(page);
    const nav = page.locator('nav[aria-label="Navegación principal"]');
    await nav.getByRole('link', { name: 'Inventario', exact: true }).click();
    await expect(page).toHaveURL(/\/app\/products\/list$/);
    await nav.getByRole('link', { name: 'Compras', exact: true }).click();
    await expect(page).toHaveURL(/\/app\/purchases\/list$/);

    await page.goBack();
    await expect(page).toHaveURL(/\/app\/products\/list$/);
    await waitForApp(page);
    await page.goBack();
    await expect(page).toHaveURL(/\/app\/dashboard$/);
    await page.goForward();
    await expect(page).toHaveURL(/\/app\/products\/list$/);
    await expect(page.locator('body')).not.toContainText(/usted no está autorizado|Página no encontrada/);
  });

  test('query params y params: resolución de rutas con nombre, query, hash y parámetro opcional', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForApp(page);
    const r = await page.evaluate(() => {
      const router = document.querySelector('#app').__vue_app__.config.globalProperties.$router;
      return {
        named: router.resolve('/app/products/list').name,
        query: router.resolve({ path: '/app/products/list', query: { search: 'abc', page: '2' }, hash: '#x' }).fullPath,
        withParam: router.resolve({ name: 'StoreBannerEdit', params: { id: '9' } }).fullPath,
        withoutOptional: router.resolve({ name: 'StoreBannerEdit' }).fullPath,
        catchAll: router.resolve('/app/no/existe/nada').name,
        notAuthorize: router.resolve({ name: 'not_authorize' }).fullPath,
      };
    });
    expect(r.named).toBe('index_products');
    expect(r.query).toBe('/app/products/list?search=abc&page=2#x');
    expect(r.withParam).toBe('/app/Store/Banners/Edit/9');
    expect(r.withoutOptional).toBe('/app/Store/Banners/Edit');
    expect(r.catchAll).toBe('NotFound');
    expect(r.notAuthorize).toBe('/not_authorize');

    // el query sobrevive a una navegación real y a una recarga
    await page.goto('/app/products/list?search=zzz-router4');
    await waitForApp(page);
    await page.reload();
    await waitForApp(page);
    expect(new URL(page.url()).searchParams.get('search')).toBe('zzz-router4');
  });

  test('redirects: "/" y rutas de módulo llevan a su pantalla; las de guard llevan al POS', async ({ page }) => {
    await page.goto('/');
    await expect(page).toHaveURL(/\/app\/dashboard$/);

    await page.goto('/app/products');
    await expect(page).toHaveURL(/\/app\/products\/list$/);
    await waitForApp(page);

    await page.goto('/app/serial_numbers');
    await expect(page).toHaveURL(/\/app\/serial_numbers\/list$/);

    // beforeEnter que devuelve una ubicación (antes next(location)): "Nueva venta" administrativa y cotización → venta
    await page.goto('/app/sales/store');
    await expect(page).toHaveURL(/\/app\/pos$/);
    await page.goto('/app/quotations/create_sale/42');
    await expect(page).toHaveURL(/\/app\/pos\?quotation_id=42$/);
  });

  test('404: URLs inexistentes (una y varias secciones) muestran NotFound y conservan la URL', async ({ page }) => {
    for (const url of ['/app/esta-ruta-no-existe-e2e', '/app/no/existe/nada/e2e', '/ruta-suelta-e2e']) {
      await page.goto(url);
      await waitForApp(page);
      await expect(page.locator('body'), url).toContainText(/no existe|404/i);
      await expect(page, url).not.toHaveURL(/\/login/);
    }
    expect(new URL(page.url()).pathname).toBe('/ruta-suelta-e2e');
  });

  test('not_authorize: por nombre y por URL directa muestra la pantalla de acceso denegado', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForApp(page);
    await page.evaluate(() => {
      document.querySelector('#app').__vue_app__.config.globalProperties.$router.push({ name: 'not_authorize' });
    });
    await expect(page).toHaveURL(/\/not_authorize$/);
    await expect(page.locator('body')).toContainText(/usted no está autorizado/i);
    await page.reload();
    await waitForApp(page);
    await expect(page.locator('body')).toContainText(/usted no está autorizado/i);
  });

  test('cambio de idioma en una pantalla no pierde la ruta y la navegación posterior sigue funcionando', async ({ page }) => {
    await openPos(page, { selectWarehouse: false });
    try {
      await setLanguageViaPosMenu(page, 'gb', 'en');
      await expect(page).toHaveURL(/\/app\/pos$/);
      await expect(page.locator('body')).toContainText(/Current Cart/, { timeout: 20_000 });
      await page.locator('a.pos-shell-action-btn').first().click();
      await expect(page).toHaveURL(/\/app\/dashboard$/, { timeout: 20_000 });
    } finally {
      await openPos(page, { selectWarehouse: false });
      await setLanguageViaPosMenu(page, 'es', 'es');
    }
  });
});

test.describe('Vue Router 4 — usuario restringido @smoke', () => {
  test.use({ storageState: path.join(env.authDir, 'restricted.json') });

  test('las rutas protegidas muestran "no autorizado" y una URL inexistente muestra NotFound', async ({ page }) => {
    for (const url of ['/app/products/list', '/app/pos', '/app/settings/system_settings']) {
      await page.goto(url);
      await waitForApp(page);
      await expect(page.locator('body'), url).toContainText(/usted no está autorizado/i);
    }
    await page.goto('/app/no-existe-restringido');
    await waitForApp(page);
    await expect(page.locator('body')).toContainText(/no existe|404/i);
  });

  test('los guards de redirección se resuelven también sin permiso de POS (sin llegar al formulario retirado)', async ({ page }) => {
    await page.goto('/app/sales/store');
    await expect(page).not.toHaveURL(/\/login/);
    await waitForApp(page);
    // En carga directa los permisos aún no están hidratados y el guard es optimista (→ /app/pos, que aplica su propio bloqueo);
    // con permisos cargados devuelve { name: 'index_sales' }. En ambos casos el formulario retirado no se renderiza.
    await expect(page.locator('body')).toContainText(/usted no está autorizado/i, { timeout: 20_000 });
    expect(new URL(page.url()).pathname).toMatch(/\/(app\/pos|app\/sales\/list|not_authorize)$/);
    await expect(page.locator('body')).not.toContainText(/Nueva venta/);
  });
});

test.describe('Vue Router 4 — portal del cliente @smoke', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('createWebHistory("/portal"): rutas, nombres, parámetros y href con base', async ({ page }) => {
    await page.goto('/portal/login');
    await expect(page.locator('input[type="email"]')).toBeVisible({ timeout: 20_000 });

    expect(await resolveIn(page, '#portal-app', '/login')).toMatchObject({ name: 'PortalLogin', href: '/portal/login' });
    expect(await resolveIn(page, '#portal-app', { name: 'PortalInvoiceDetail', params: { id: '5' } })).toMatchObject({
      path: '/invoices/5',
      href: '/portal/invoices/5',
    });
    expect(await resolveIn(page, '#portal-app', '/help/mi-articulo')).toMatchObject({ name: 'PortalArticle', params: { slug: 'mi-articulo' } });
    // rutas hijas anidadas (AuthGate → layout → vista): tres registros
    expect((await resolveIn(page, '#portal-app', '/statement')).matched).toBe(3);
  });

  test('deep link del portal sin sesión: el servidor redirige al login y la SPA lo muestra', async ({ page }) => {
    await page.goto('/portal/invoices/5');
    await expect(page).toHaveURL(/\/portal\/login$/);
    await expect(page.locator('input[type="password"]')).toBeVisible({ timeout: 20_000 });
  });
});

test.describe('Vue Router 4 — login → app @smoke', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('el login por UI aterriza en /app/dashboard y sobrevive a una recarga', async ({ page }) => {
    await page.goto('/login');
    await page.locator('#email').fill(env.adminEmail);
    await page.locator('#password').fill(env.adminPassword);
    await page.locator('#login_submit_btn').click();
    await page.waitForURL(/\/app\/dashboard/, { timeout: 30_000 });
    await waitForApp(page);
    await expect(page.locator('nav[aria-label="Navegación principal"]')).toBeVisible();
    await page.reload();
    await waitForApp(page);
    await expect(page).toHaveURL(/\/app\/dashboard$/);
  });
});
