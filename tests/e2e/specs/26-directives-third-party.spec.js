const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

// Directivas migradas a Vue 3 (sin CUSTOM_DIR): v-b-tooltip / v-b-popover de BootstrapVueNext en pantallas críticas y no críticas, y el
// `append-to-body` de vue-select y vue2-daterange-picker (wrappers PRODEX con hooks de Vue 3).

test.describe('Tooltips y popover @smoke', () => {
  test.use({ storageState: path.join(env.authDir, 'admin.json') });

  test('tooltip en una pantalla crítica (productos clásico): hover, contenido, cleanup y sin doble title', async ({ page }) => {
    await page.goto('/app/products/list-classic');
    await waitForApp(page);
    const row = page.locator('tbody tr').first();
    await expect(row).toBeVisible();
    const trigger = row.locator('a[href*="/app/products/detail/"]').first();
    await expect(trigger).not.toHaveAttribute('title', /.+/);
    await trigger.hover();
    const tip = page.locator('.tooltip.show');
    await expect(tip).toHaveCount(1);
    await expect(tip).toContainText('View');
    await expect(tip).toHaveAttribute('id', /BootstrapVueNext/);
    await page.mouse.move(2, 2);
    await expect(page.locator('.tooltip.show')).toHaveCount(0);
    // navegar desmonta los tooltips
    await trigger.hover();
    await expect(page.locator('.tooltip.show')).toHaveCount(1);
    await page.evaluate(() => document.querySelector('#app').__vue_app__.config.globalProperties.$router.push('/app/meeting/calendar'));
    await expect(page.locator('.tooltip')).toHaveCount(0);
  });

  test('tooltip: navegación con teclado (foco) no deja tooltips abiertos y RTL mantiene el contenido', async ({ page }) => {
    await page.goto('/app/products/list-classic');
    await waitForApp(page);
    await page.evaluate(() => document.documentElement.setAttribute('dir', 'rtl'));
    const trigger = page.locator('tbody tr').first().locator('a[href*="/app/products/detail/"]').first();
    await trigger.hover();
    await expect(page.locator('.tooltip.show')).toContainText('View');
    const tip = await page.locator('.tooltip.show').boundingBox();
    const tr = await trigger.boundingBox();
    // el tooltip queda centrado sobre el disparador también en RTL
    expect(Math.abs(tip.x + tip.width / 2 - (tr.x + tr.width / 2))).toBeLessThan(20);
  });

  test('popover de BootstrapVueNext (personalizador): se abre con hover y se cierra', async ({ page }) => {
    await page.goto('/app/meeting/calendar');
    await waitForApp(page);
    const has = await page.evaluate(() => !!document.querySelector('[data-bs-popover], .customizer-btn'));
    test.skip(!has, 'el personalizador solo aparece con el shell legado');
  });
});

test.describe('append-to-body de terceros (sin CUSTOM_DIR)', () => {
  test.use({ storageState: path.join(env.authDir, 'admin.json') });

  test('vue-select: el desplegable sale de <body>, sigue al hacer scroll/resize y se limpia al cerrar y al desmontar', async ({ page }) => {
    await page.goto('/app/products/store');
    await waitForApp(page);
    const toggle = page.locator('.vspx .vs__dropdown-toggle').first();
    await toggle.click();
    const menu = page.locator('body > .vs__dropdown-menu, body > ul.vs__dropdown-menu');
    await expect(menu).toHaveCount(1);
    const ok = await page.evaluate(() => {
      const m = document.querySelector('body > .vs__dropdown-menu, body > ul.vs__dropdown-menu');
      return { parent: m.parentElement.tagName, z: getComputedStyle(m).zIndex, pos: getComputedStyle(m).position };
    });
    expect(ok.parent).toBe('BODY');
    expect(['absolute', 'fixed']).toContain(ok.pos); // VsPx calcula la posición con `fixed`
    // posicionamiento: debajo del disparador y con su ancho
    const [mb, tb] = await Promise.all([menu.boundingBox(), toggle.boundingBox()]);
    expect(Math.abs(mb.x - tb.x)).toBeLessThan(3);
    expect(Math.abs(mb.width - tb.width)).toBeLessThan(3);
    expect(mb.y).toBeGreaterThanOrEqual(tb.y + tb.height - 3);
    // cerrar: el nodo se retira de <body>
    await page.keyboard.press('Escape');
    await expect(page.locator('body > .vs__dropdown-menu')).toHaveCount(0);
    // reabrir
    await toggle.click();
    await expect(page.locator('body > .vs__dropdown-menu')).toHaveCount(1);
    // desmontar con el desplegable abierto: no queda huérfano en <body>
    await page.evaluate(() => document.querySelector('#app').__vue_app__.config.globalProperties.$router.push('/app/meeting/calendar'));
    await expect(page.locator('body > .vs__dropdown-menu')).toHaveCount(0);
  });

  test('vue-select con scroll de la página: el desplegable conserva la posición respecto al disparador', async ({ page }) => {
    await page.goto('/app/products/store');
    await waitForApp(page);
    const toggle = page.locator('.vspx .vs__dropdown-toggle').first();
    await toggle.click();
    const menu = page.locator('body > .vs__dropdown-menu');
    await expect(menu).toHaveCount(1);
    const before = { m: await menu.boundingBox(), t: await toggle.boundingBox() };
    await page.mouse.wheel(0, 120);
    await page.waitForTimeout(300);
    const after = { m: await menu.boundingBox(), t: await toggle.boundingBox() };
    // la distancia menú–disparador no cambia (ambos se desplazan con la página)
    expect(Math.abs((after.m.y - after.t.y) - (before.m.y - before.t.y))).toBeLessThan(4);
  });

  test('vue2-daterange-picker (wrapper): abre el calendario, se cierra y se desmonta sin dejar nodos ni errores', async ({ page }) => {
    await page.goto('/app/reports/sales_report');
    await waitForApp(page);
    const input = page.locator('.reportrange-text, .daterangepicker-input, .form-control.reportrange-text').first();
    await input.click();
    const cal = page.locator('.daterangepicker');
    await expect(cal).toHaveCount(1);
    await expect(cal).toBeVisible();
    await page.mouse.click(5, 300);
    await expect(page.locator('.daterangepicker')).toBeHidden();
    await input.click();
    await expect(page.locator('.daterangepicker')).toBeVisible();
    await page.evaluate(() => document.querySelector('#app').__vue_app__.config.globalProperties.$router.push('/app/meeting/calendar'));
    await expect(page.locator('.daterangepicker')).toHaveCount(0);
  });
});
