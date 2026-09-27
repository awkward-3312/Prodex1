const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

// Componentes propios migrados a APIs de Vue 3 (sin $listeners / $scopedSlots / functional / render(h) / hooks Vue 2):
// PxButton, PxInput, PxCheck, PxTextarea, VsPx, PxModal, LucideIcon, StatTile, ListToolbar/Pager y el layout legacy.
test.use({ storageState: path.join(env.authDir, 'admin.json') });

test.describe('Componentes propios en Vue 3 @smoke', () => {
  test('PxButton / PxModal / PxCheck / VsPx: listeners únicos, footer con `close` y v-model', async ({ page }) => {
    await page.goto('/app/settings/Warehouse_Locations');
    await waitForApp(page);

    // PxButton: un click = un modal (el listener no se duplica) y el footer de PxModal (slot con `close`) se renderiza
    await page.getByRole('button', { name: /Nueva ubicación/ }).click();
    const modal = page.locator('.pxn-modal').first();
    await expect(modal).toBeVisible();
    await expect(page.locator('.pxn-modal')).toHaveCount(1);
    await expect(modal.locator('.pxn-modal__foot')).toBeVisible();
    await expect(modal.getByRole('button', { name: 'Guardar' })).toBeVisible();

    // PxCheck (switch): v-model por `change`, un click cambia el estado una sola vez
    const check = modal.locator('.pxn-check input');
    const before = await check.isChecked();
    await modal.locator('.pxn-check').click();
    expect(await check.isChecked()).toBe(!before);

    // VsPx: v-model y opciones (vue-select con listeners reenviados vía $attrs)
    await modal.locator('.vspx').first().click();
    const option = page.locator('.vs__dropdown-option').first();
    await expect(option).toBeVisible();
    const label = (await option.innerText()).trim();
    await option.click();
    await expect(modal.locator('.vs__selected').first()).toContainText(label);

    // PxInput: v-model + listener `keyup` reenviado al <input> nativo
    const code = modal.getByPlaceholder(/Ej\. A-01|rack/i).first();
    if (await code.count()) {
      await code.fill('E2E-OWN-01');
      await expect(code).toHaveValue('E2E-OWN-01');
    }

    // slot de footer con `close`: Cancelar cierra el modal
    await modal.getByRole('button', { name: 'Cancelar' }).click();
    await expect(page.locator('.pxn-modal')).toHaveCount(0);
  });

  test('PxInput: `@keyup.enter` dispara exactamente una búsqueda y PxButton `@click` una más', async ({ page }) => {
    const requests = [];
    page.on('request', (r) => {
      if (r.method() === 'GET' && /prodex-manual\/articles/.test(r.url())) requests.push(r.url());
    });
    await page.goto('/app/knowledge-base/list');
    await waitForApp(page);
    const input = page.getByPlaceholder(/crear producto|cerrar caja|CAI/i).first();
    await expect(input).toBeVisible();
    await input.fill('caja');
    const before = requests.length;
    await input.press('Enter');
    await page.waitForTimeout(1500);
    expect(requests.length - before, 'una pulsación de Enter = una petición (listener sin duplicar)').toBe(1);

    const afterEnter = requests.length;
    await page.getByRole('button', { name: 'Buscar' }).click();
    await page.waitForTimeout(1500);
    expect(requests.length - afterEnter, 'un click = una petición').toBe(1);
  });

  test('PxTextarea: v-model y atributos caen en el <textarea> raíz', async ({ page }) => {
    await page.goto('/app/damages/store');
    await waitForApp(page);
    const area = page.locator('textarea.pxn-textarea').first();
    await expect(area).toBeVisible();
    await area.fill('nota e2e');
    await expect(area).toHaveValue('nota e2e');
    await expect(area).toHaveAttribute('rows', /\d+/);
  });

  test('LucideIcon: atributos y clases reenviados sin duplicados; el icono ausente conserva su caja', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForApp(page);
    const icons = page.locator('svg.lucide-icon');
    expect(await icons.count()).toBeGreaterThan(10);
    // la clase que pasa el padre (`pxn-btn__icon`, `nav-icon`…) llega al <svg> junto a las del propio icono
    const withParentClass = page.locator('svg.lucide-icon[class*="pxn-"], svg.lucide-icon[class*="nav-"]').first();
    await expect(withParentClass).toBeVisible();
    // atributos arbitrarios se reenvían (size → width/height del svg)
    expect(await icons.first().getAttribute('width')).toMatch(/\d+/);
  });

  test('StatTile (ex funcional): los KPI de los informes se pintan con tema, etiqueta y valor', async ({ page }) => {
    for (const url of ['/app/reports/stock_adjustment_report-classic', '/app/reports/stock_transfer_report-classic', '/app/reports/warehouse_report-classic']) {
      await page.goto(url);
      await waitForApp(page);
      const tiles = page.locator('.stat-card');
      await expect(tiles.first(), url).toBeVisible({ timeout: 20_000 });
      await expect(tiles.first().locator('.stat-label'), url).not.toBeEmpty();
      expect(await tiles.first().getAttribute('class'), url).toMatch(/theme-\w+/);
      await expect(tiles.first().locator('svg.lucide-icon, .lucide-icon').first(), url).toBeVisible();
    }
  });

  test('ListToolbar / Pager (ex render(h)) del libro de cliente: buscar, restablecer y paginación', async ({ page }) => {
    await page.goto('/app/People/customers/1/ledger');
    await waitForApp(page);
    const toolbar = page.locator('.toolbar').first();
    await expect(toolbar).toBeVisible({ timeout: 20_000 });
    await expect(toolbar.locator('input').first()).toBeVisible();
    await toolbar.locator('input').first().fill('abc');
    await expect(toolbar.locator('input').first()).toHaveValue('abc');
    await toolbar.locator('button').nth(1).click(); // Restablecer
    await expect(toolbar.locator('input').first()).toHaveValue('');
    await expect(page.locator('.pager').first()).toContainText(/\d+/);
  });

  test('ciclo de vida (beforeUnmount/unmounted): salir de un informe con listeners de ventana no deja errores', async ({ page }) => {
    await page.goto('/app/reports/customer_loyalty_points_report');
    await waitForApp(page);
    await page.setViewportSize({ width: 900, height: 700 }); // dispara el handler de resize registrado por la vista
    await page.goto('/app/dashboard');
    await waitForApp(page);
    await page.setViewportSize({ width: 1280, height: 720 }); // el listener ya debe estar retirado (unmounted)
    await page.waitForTimeout(500);
    await expect(page.locator('body')).not.toContainText(/usted no está autorizado/);
  });
});

// Layout legacy (largeSidebar): estado `open`, enlace activo y navegación/regreso tras Router 4 (?pxshell=0 = rollback a legacy).
test.describe('Sidebar legacy tras Router 4 @smoke', () => {
  const activeLink = (page, href) => page.locator(`a[href="${href}"]`).first();

  test('vertical (por defecto): padre/hija, `open`, enlace activo, navegación y regreso', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/app/dashboard?pxshell=0');
    await waitForApp(page);
    await page.goto('/app/products/list');
    await waitForApp(page);
    await expect(activeLink(page, '/app/products/list')).toHaveClass(/router-link-exact-active/);
    await expect(activeLink(page, '/app/products/list')).toHaveClass(/\bopen\b/);
    const parent = page.locator('li.has-submenu', { has: page.locator('a[href="/app/products/list"]') }).first();
    await expect(parent).toHaveClass(/\bactive\b/);
    await expect(parent).toHaveClass(/\bopen\b/);

    await page.goto('/app/sales/list');
    await waitForApp(page);
    await expect(activeLink(page, '/app/sales/list')).toHaveClass(/router-link-exact-active/);
    await expect(page.locator('a.router-link-exact-active[href="/app/products/list"]')).toHaveCount(0);

    await page.goBack();
    await waitForApp(page);
    await expect(page).toHaveURL(/\/app\/products\/list(\?.*)?$/);
    await expect(activeLink(page, '/app/products/list')).toHaveClass(/router-link-exact-active/);

    // hija de un módulo: el padre sigue marcado como activo
    await page.goto('/app/hrm/employees/store');
    await waitForApp(page);
    await expect(page.locator('li.has-submenu.active').first()).toBeVisible();
  });

  test.describe('horizontal (Sidebar.vue, `a.open` con estilo)', () => {
    test.beforeEach(async ({ page }) => {
      await page.addInitScript(() => {
        localStorage.setItem('sidebarLayoutVersion', 'prodex-sidebar-layout-v2');
        localStorage.setItem('sidebarLayout', 'horizontal');
      });
      await page.setViewportSize({ width: 1440, height: 900 });
    });

    test('ruta hija marca `open` el enlace del padre por prefijo (regla de Router 3 conservada)', async ({ page }) => {
      for (const [route, href] of [
        ['/app/marketing/campaigns/create', '/app/marketing/campaigns'],
        ['/app/hrm/employees/store', '/app/hrm/employees'],
        ['/app/projects/store', '/app/projects'],
        ['/app/User_Management/Users/create', '/app/User_Management/Users'],
      ]) {
        await page.goto(`${route}?pxshell=0`);
        await waitForApp(page);
        await expect(activeLink(page, href), `${route} → ${href}`).toHaveClass(/\bopen\b/);
      }
    });

    test('enlace exacto + open, y al navegar a otro módulo el anterior se apaga; el regreso lo restaura', async ({ page }) => {
      await page.goto('/app/People/Customers/create?pxshell=0');
      await waitForApp(page);
      await expect(activeLink(page, '/app/People/Customers/create')).toHaveClass(/router-link-exact-active/);
      await expect(activeLink(page, '/app/People/Customers/create')).toHaveClass(/\bopen\b/);

      await page.goto('/app/marketing/campaigns/create');
      await waitForApp(page);
      await expect(activeLink(page, '/app/People/Customers/create')).not.toHaveClass(/\bopen\b/);

      await page.goBack();
      await waitForApp(page);
      await expect(page).toHaveURL(/\/app\/People\/Customers\/create(\?.*)?$/);
      await expect(activeLink(page, '/app/People/Customers/create')).toHaveClass(/\bopen\b/);
    });
  });
});
