const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

// Corte final a Bootstrap 5 (fase 5C): la hoja activa es Bootstrap 5.3 (no Bootstrap 4 ni bootstrap-vue.css), las utilidades y componentes de BS5 funcionan de
// verdad (LTR y RTL), y en las pantallas críticas no aparece marcado retirado de Bootstrap 4. La sonda `/app/_ui?probe=ui` solo existe en desarrollo.

test.use({ storageState: path.join(env.authDir, 'admin.json') });
test.setTimeout(90_000);

const REMOVED_SELECTOR = '.custom-select, .custom-control, .custom-checkbox, .custom-radio, .custom-switch, .input-group-prepend, .input-group-append, .btn-block, .form-row, .card-deck, .no-gutters, .thead-light, .thead-dark, .btn-group-toggle, .badge-pill, .dropdown-menu-right, .close[data-dismiss]';
const PAGES = ['/app/dashboard', '/app/sales/list', '/app/sales/store', '/app/purchases/store', '/app/transfers/store', '/app/adjustments/store', '/app/products/store', '/app/expenses/store',
  '/app/settings/System_settings', '/app/settings/pos_settings', '/app/settings/Cash_Drawers', '/app/hrm/employees/store', '/app/pos', '/app/People/Customers'];

test.describe('Bootstrap 5 es la hoja activa @smoke', () => {
  test('hoja de estilos: Bootstrap 5 sí; Bootstrap 4, bootstrap-vue.css y el puente de la fase 1 no', async ({ page }) => {
    await page.goto('/app/_ui?probe=ui');
    await waitForApp(page);
    await page.waitForFunction(() => typeof window.__pxCutover === 'function', undefined, { timeout: 30_000 });
    const r = await page.evaluate(() => window.__pxCutover());
    expect(r.bootstrap5.rootVariables, 'variables --bs-* de Bootstrap 5').toBe(true);
    expect(r.bootstrap5.formSelectRule).toBe(true);
    expect(r.bootstrap5.formCheckRule).toBe(true);
    expect(r.bootstrap5.visuallyHidden).toBe(true);
    expect(r.bootstrap5.utilityMs2, '`ms-2` = margin .5rem (izquierda en LTR)').toBe('8px');
    expect(r.bootstrap4, 'reglas de Bootstrap 4').toEqual({ customControlRule: false, customSelectRule: false, inputGroupPrependRule: false, formRowRule: false, srOnlyRule: true /* shim para vue-good-table, ver compat/_legacy-names.scss */, btnBlockDisplayFromClass: 'block' });
    expect(r.bootstrapVue2, 'reglas de bootstrap-vue.css').toEqual({ avatarRule: false, spinbuttonRule: false, toastRule: false });
  });

  test('utilidades de BS5 con efecto real en LTR y RTL (ms/me/ps/pe, text-start/end, float, fw, visually-hidden)', async ({ page }) => {
    await page.goto('/app/_ui?probe=ui');
    await waitForApp(page);
    await page.waitForFunction(() => typeof window.__pxProbe === 'function', undefined, { timeout: 30_000 });
    await page.evaluate(([t]) => window.__pxProbe(t), ['<div><span class="ms-3 ps-4 t1">a</span><span class="me-2 pe-1 t7">a</span><p class="text-end t2">b</p><p class="text-start t3">c</p><i class="float-end t4">d</i><b class="fw-bold t5">e</b><u class="visually-hidden t6">f</u></div>']);
    const read = () => page.evaluate(() => {
      const g = (s) => getComputedStyle(document.querySelector(`.probe-root ${s}`));
      return { msL: g('.t1').marginLeft, msR: g('.t1').marginRight, psL: g('.t1').paddingLeft, psR: g('.t1').paddingRight, meL: g('.t7').marginLeft, meR: g('.t7').marginRight, peL: g('.t7').paddingLeft, peR: g('.t7').paddingRight, end: g('.t2').textAlign, fl: g('.t4').float, fw: g('.t5').fontWeight, vh: g('.t6').width };
    });
    const ltr = await read();
    expect(ltr).toMatchObject({ msL: '16px', msR: '0px', psL: '24px', psR: '0px', meL: '0px', meR: '8px', peL: '0px', peR: '4px', fl: 'right', fw: '700', vh: '1px' });
    expect(['right', 'end']).toContain(ltr.end);
    await page.evaluate(() => { document.documentElement.setAttribute('dir', 'rtl'); document.body.classList.add('rtl'); });
    const rtl = await read();
    expect(rtl).toMatchObject({ msL: '0px', msR: '16px', psL: '0px', psR: '24px', meL: '8px', meR: '0px', peL: '4px', peR: '0px', fl: 'left' });
    expect(['left', 'end']).toContain(rtl.end);
  });

  test('Bootstrap JS nativo no está cargado: sin `window.bootstrap` ni `data-bs-*` en uso', async ({ page }) => {
    await page.goto('/app/dashboard');
    await waitForApp(page);
    expect(await page.evaluate(() => typeof window.bootstrap)).toBe('undefined');
    expect(await page.evaluate(() => typeof window.jQuery)).toBe('undefined');
  });

  for (const url of PAGES) {
    test(`${url}: sin marcado retirado de Bootstrap 4`, async ({ page }) => {
      await page.goto(url);
      await waitForApp(page);
      await page.waitForLoadState('networkidle').catch(() => {});
      const found = await page.evaluate((sel) => [...new Set([...document.querySelectorAll(sel)].map((e) => `${e.tagName.toLowerCase()}.${String(e.className).split(/\s+/).slice(0, 3).join('.')}`))], REMOVED_SELECTOR);
      expect(found, 'elementos con clases de Bootstrap 4').toEqual([]);
    });
  }
});
