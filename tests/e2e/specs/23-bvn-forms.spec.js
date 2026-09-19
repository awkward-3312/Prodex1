const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

// Formularios simples de BootstrapVueNext (BFormGroup, BFormInput, BFormTextarea, BFormSelect, BFormCheckbox, BFormRadio) con la capa de
// validación PRODEX (vee-validate 3): required, estado inválido, mensaje, corrección al escribir, submit bloqueado/válido, reset,
// contrato de eventos (sin dobles emisiones), RTL, móvil y desmontaje. Los fixtures fallan ante pageerror / console.error / 5xx.

const spaGo = (page, to) => page.evaluate((t) => document.querySelector('#app').__vue_app__.config.globalProperties.$router.push(t), to);
const isTemplatePost = (req) => req.method() === 'POST' && /marketing\/templates/.test(req.url());

test.describe('BootstrapVueNext — formularios simples @smoke', () => {
  test.use({ storageState: path.join(env.authDir, 'admin.json') });

  test('plantilla de marketing: required → inválido → mensaje → corrige al escribir → submit bloqueado/válido (1 sola petición) → reset', async ({ page }) => {
    const name = `E2E BVN ${Date.now()}`;
    const posts = [];
    page.on('request', (r) => { if (isTemplatePost(r)) posts.push(r.url()); });

    await page.goto('/app/marketing/templates/email');
    await waitForApp(page);
    await page.getByRole('button', { name: /Nueva plantilla/ }).click();
    const modal = page.locator('.modal.show');
    await expect(modal).toBeVisible();

    // Componentes reales: BootstrapVueNext (ids `BootstrapVueNext__ID__…`), no BootstrapVue 2 (`__BVID__`)
    const nameInput = modal.locator('input.form-control').first();
    await expect(nameInput).toHaveAttribute('id', /BootstrapVueNext/);
    await expect(modal.locator('textarea.form-control').first()).toHaveAttribute('id', /BootstrapVueNext/);

    // submit vacío: bloqueado + estado inválido + mensaje
    await modal.locator('form').evaluate((f) => f.requestSubmit());
    await expect(nameInput).toHaveClass(/is-invalid/);
    await expect(modal.locator('.invalid-feedback').first()).toBeVisible();
    await expect(modal.locator('.invalid-feedback').first()).not.toHaveText('');
    expect(posts, 'submit inválido no envía nada').toHaveLength(0);
    await expect(modal).toBeVisible();

    // escribir corrige el error (sin volver a enviar)
    await nameInput.fill(name);
    await expect(nameInput).not.toHaveClass(/is-invalid/);
    await expect(modal.locator('.invalid-feedback').first()).toBeHidden();

    // vaciar otra vez lo vuelve a marcar; volver a escribir lo corrige
    await nameInput.fill('');
    await expect(nameInput).toHaveClass(/is-invalid/);
    await nameInput.fill(name);
    await expect(nameInput).not.toHaveClass(/is-invalid/);

    // textarea: v-model + rows
    const body = modal.locator('textarea').last();
    await body.fill('Hola {customer_name}');
    await expect(body).toHaveValue('Hola {customer_name}');

    // submit válido: una sola petición, el modal se cierra y aparece la fila
    await modal.locator('form').evaluate((f) => f.requestSubmit());
    await expect(page.locator('.modal.show')).toHaveCount(0, { timeout: 15_000 });
    expect(posts, 'submit válido se envía exactamente una vez').toHaveLength(1);
    const row = page.locator('tbody tr').filter({ hasText: name });
    await expect(row).toHaveCount(1);

    // reset: al abrir de nuevo el formulario está vacío y sin estados inválidos
    await page.getByRole('button', { name: /Nueva plantilla/ }).click();
    const modal2 = page.locator('.modal.show');
    await expect(modal2.locator('input.form-control').first()).toHaveValue('');
    await expect(modal2.locator('.is-invalid')).toHaveCount(0);
    await modal2.locator('.close, .btn-close').first().click();
    await expect(page.locator('.modal.show')).toHaveCount(0);

    // tooltip de BootstrapVueNext (v-b-tooltip local): hover, contenido, placement, cleanup
    const edit = row.locator('a.cursor-pointer').nth(1);
    await expect(edit).not.toHaveAttribute('title', /.+/); // el title nativo se retira (no hay doble tooltip)
    await edit.hover();
    const tip = page.locator('.tooltip.show');
    await expect(tip).toHaveCount(1);
    await expect(tip).toContainText('Edit');
    await expect(tip).toHaveClass(/bs-tooltip-top/);
    await expect(tip).toHaveAttribute('id', /BootstrapVueNext/);
    const arrow = await tip.locator('.tooltip-arrow').evaluate((el) => getComputedStyle(el, '::before').borderTopWidth);
    expect(parseFloat(arrow)).toBeGreaterThan(0); // flecha pintada por el puente
    await page.mouse.move(2, 2);
    await expect(page.locator('.tooltip.show')).toHaveCount(0);
    // el nodo oculto no captura clics ni se ve
    const hidden = await page.locator('.tooltip.b-tooltip:not(.show)').first().evaluate((el) => { const cs = getComputedStyle(el); return { v: cs.visibility, p: cs.pointerEvents }; });
    expect(hidden).toEqual({ v: 'hidden', p: 'none' });

    // limpieza (confirmación de la plataforma)
    await row.locator('a.cursor-pointer').last().click();
    await page.locator('.swal2-confirm').click();
    await expect(row).toHaveCount(0, { timeout: 15_000 });
  });

  test('filtros de reuniones: select y fecha emiten UNA petición con el valor ya actualizado (sin doble emisión)', async ({ page }) => {
    const calls = [];
    page.on('request', (r) => { if (/meeting\/meetings\?/.test(r.url())) calls.push(new URL(r.url()).searchParams); });
    await page.goto('/app/meeting/meetings');
    await waitForApp(page);
    await page.waitForTimeout(1500);
    calls.length = 0;

    const status = page.locator('select').first();
    await expect(status).toHaveAttribute('id', /BootstrapVueNext/);
    await status.selectOption({ index: 1 });
    await expect.poll(() => calls.length, { timeout: 10_000 }).toBeGreaterThan(0);
    await page.waitForTimeout(800);
    expect(calls, 'un cambio de select = una petición').toHaveLength(1);
    const chosen = await status.inputValue();
    expect(calls[0].get('status')).toBe(chosen);

    calls.length = 0;
    const dateFrom = page.locator('input[type=date]').first();
    await dateFrom.fill('2026-01-15');
    await expect.poll(() => calls.length, { timeout: 10_000 }).toBeGreaterThan(0);
    await page.waitForTimeout(800);
    expect(calls).toHaveLength(1);
    expect(calls[0].get('date_from')).toBe('2026-01-15');
  });

  test('radio: v-model exclusivo y aspecto de custom-control (campaña)', async ({ page }) => {
    await page.goto('/app/marketing/campaigns/create');
    await waitForApp(page);
    const now = page.getByLabel(/Enviar inmediatamente/i);
    const schedule = page.getByLabel(/Programar campaña/i);
    await expect(now).toBeChecked();
    await expect(page.locator('input[type=datetime-local]')).toHaveCount(0);
    await schedule.check();
    await expect(schedule).toBeChecked();
    await expect(now).not.toBeChecked();
    await expect(page.locator('input[type=datetime-local]')).toBeVisible(); // el modelo alimenta el v-if
    // indicador pintado por el puente (no el radio nativo): appearance none, 16px, circular
    const look = await schedule.evaluate((el) => { const cs = getComputedStyle(el); return { appearance: cs.appearance, w: cs.width, r: cs.borderRadius }; });
    expect(look).toEqual({ appearance: 'none', w: '16px', r: '50%' });
    await now.check();
    await expect(page.locator('input[type=datetime-local]')).toHaveCount(0);
  });

  test('checkbox booleano: v-model y @change una sola vez (segmentos)', async ({ page }) => {
    await page.goto('/app/marketing/segments');
    await waitForApp(page);
    await page.getByRole('button', { name: /Nuevo|Nueva|Crear|Add|New/i }).first().click();
    const modal = page.locator('.modal.show');
    await expect(modal).toBeVisible();
    const box = modal.locator('input[type=checkbox]').first();
    await expect(box).toHaveClass(/px-bvn-check/);
    const before = await box.isChecked();
    await box.click();
    expect(await box.isChecked()).toBe(!before);
    await box.click();
    expect(await box.isChecked()).toBe(before);
    const look = await box.evaluate((el) => { const cs = getComputedStyle(el); return { appearance: cs.appearance, w: cs.width, r: cs.borderRadius }; });
    expect(look.appearance).toBe('none');
    expect(look.w).toBe('16px');
  });

  test('RTL: modal de formulario con etiquetas y controles a ancho completo', async ({ page }) => {
    await page.goto('/app/marketing/templates/email');
    await waitForApp(page);
    await page.evaluate(() => document.documentElement.setAttribute('dir', 'rtl'));
    await page.getByRole('button', { name: /Nueva plantilla/ }).click();
    const modal = page.locator('.modal.show');
    await expect(modal).toBeVisible();
    const input = modal.locator('input.form-control').first();
    const label = modal.locator('legend, label').first();
    const [i, l] = await Promise.all([input.boundingBox(), label.boundingBox()]);
    expect(i.width).toBeGreaterThan(100);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
    // en RTL la etiqueta está alineada al borde derecho del control (inicio de línea)
    expect(Math.abs((l.x + l.width) - (i.x + i.width))).toBeLessThan(40);
  });

  test('desmontaje: abrir el formulario, navegar y volver no deja errores ni modales colgados', async ({ page }) => {
    await page.goto('/app/marketing/templates/email');
    await waitForApp(page);
    await page.getByRole('button', { name: /Nueva plantilla/ }).click();
    await expect(page.locator('.modal.show')).toBeVisible();
    await page.locator('.modal.show .close, .modal.show .btn-close').first().click();
    for (const r of ['/app/meeting/meetings', '/app/marketing/campaigns/create', '/app/marketing/templates/email']) {
      await spaGo(page, r);
      await expect(page.locator('.main-content, [class*="content"]').first()).toBeVisible();
      await page.waitForTimeout(600);
    }
    await expect(page.locator('.modal.show')).toHaveCount(0);
    await spaGo(page, '/app/meeting/meetings');
    await page.waitForTimeout(800);
    expect(await page.locator('.tooltip').count(), 'los tooltips se desmontan con la vista').toBe(0);
  });
});

test.describe('BootstrapVueNext — v-model con modificadores', () => {
  test.use({ storageState: path.join(env.authDir, 'admin.json') });

  test('v-model.number: escribe "12.5" y el modelo es el número 12.5 (contrato nuevo)', async ({ page }) => {
    await page.goto('/app/contracts/store');
    await waitForApp(page);
    const input = page.locator('input[type=number][step="0.01"]').first();
    await expect(input).toHaveAttribute('id', /BootstrapVueNext/);
    await input.fill('12.5');
    const model = await input.evaluate((el) => {
      let c = el.__vueParentComponent;
      while (c) {
        const d = c.proxy && c.proxy.$data;
        if (d && d.contract) return { type: typeof d.contract.value, value: d.contract.value };
        c = c.parent;
      }
      return null;
    });
    expect(model).toEqual({ type: 'number', value: 12.5 });
  });
});

test.describe('BootstrapVueNext — formularios en móvil', () => {
  test.use({ storageState: path.join(env.authDir, 'admin.json') });

  test('390px: formulario de campaña sin desbordamiento horizontal y controles a ancho completo', async ({ browser }) => {
    const ctx = await browser.newContext({ baseURL: env.baseURL, viewport: { width: 390, height: 844 }, storageState: path.join(env.authDir, 'admin.json') });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto('/app/marketing/campaigns/create');
    await waitForApp(page);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
    const w = await page.locator('input.form-control').first().evaluate((el) => el.getBoundingClientRect().width);
    expect(w).toBeGreaterThan(250);
    expect(errors).toEqual([]);
    await ctx.close();
  });
});
