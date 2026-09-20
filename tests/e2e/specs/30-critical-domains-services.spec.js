const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp, openPos, ensureRegisterOpen, ensureRegisterClosed, closeTopModal, apiJson } = require('../support/helpers');

// Dominios críticos (POS, traslados, ajustes, mermas) sobre los servicios de plataforma (`notifications`, `modals`, `confirm`): sin `$bvToast` /
// `$bvModal`, con modales de BootstrapVueNext. Complementa a 29-modals-matrix (cuyo registro sí se grabó sobre BootstrapVue 2) con los flujos de negocio.
// No depende de la implementación: mira `.toast` / `.modal.show` y el resultado de negocio.

test.use({ storageState: path.join(env.authDir, 'admin.json') });

const isCreatePos = (res) => res.url().endsWith('/api/pos/create_pos') && res.request().method() === 'POST';

test.describe('Traslados, ajustes y mermas: aviso de validación por toast @smoke', () => {
  for (const [name, route] of [['traslados', '/app/transfers/store-classic'], ['ajustes', '/app/adjustments/store-classic'], ['mermas', '/app/damages/store-classic']]) {
    test(`${name}: enviar vacío muestra un toast de error, se apila una sola vez y se cierra solo`, async ({ page }) => {
      await page.goto(route);
      await waitForApp(page);
      await page.getByRole('button', { name: /^\s*guardar\s*$/i }).first().click();
      const toast = page.locator('.toast');
      await expect(toast).toHaveCount(1);
      await expect(toast).toContainText('Por favor complete el formulario correctamente');
      await expect(toast).toHaveClass(/text-bg-danger/);
      await expect(page.locator('.invalid-feedback:visible').first()).toBeVisible();
      await expect(toast).toHaveCount(0, { timeout: 12_000 }); // autoHideDelay por defecto de $bvToast: 5 s
    });
  }

  test('traslados: el escáner de códigos abre y cierra su modal por id sin dejar backdrop', async ({ page }) => {
    await page.goto('/app/transfers/store-classic');
    await waitForApp(page);
    await page.evaluate(() => document.querySelector('#app').__vue_app__.config.globalProperties.$platform.modals.show('open_scan'));
    await expect(page.locator('.modal.show')).toContainText('Barcode Scanner');
    await page.waitForTimeout(600); // transición de entrada: el foco pasa al modal y ESC ya se atiende
    await page.keyboard.press('Escape');
    await expect(page.locator('.modal.show')).toHaveCount(0);
    await expect(page.locator('.modal-backdrop')).toHaveCount(0);
  });
});

test.describe.serial('POS: líneas, confirmaciones, cobro y factura @smoke', () => {
  test.beforeEach(async ({ page }) => {
    await openPos(page);
    await ensureRegisterOpen(page);
  });

  test.afterAll(async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: path.join(env.authDir, 'admin.json'), baseURL: env.baseURL });
    const page = await ctx.newPage();
    await openPos(page);
    await ensureRegisterClosed(page);
    await ctx.close();
  });

  test('cantidad: + y − cambian el artículo; eliminar la línea deja el carrito vacío', async ({ page }) => {
    await page.locator('.pos-shell-product-card').first().click();
    await expect(page.locator('body')).toContainText(/1 artículo/);
    const qty = page.locator('div:has(> button[title="Aumentar"]) > input');
    await expect(qty).toHaveValue('1');
    await page.getByTitle('Aumentar').first().click();
    await expect(qty).toHaveValue('2');
    await page.getByTitle('Disminuir').first().click();
    await expect(qty).toHaveValue('1');
    await page.getByTitle('Eliminar').first().click();
    await expect(page.locator('body')).toContainText(/0 Artículos/, { timeout: 10_000 });
  });

  test('confirmación "vaciar carrito" (modal por id): "Sí, vaciar" limpia el carrito y cierra el modal', async ({ page }) => {
    await page.locator('.pos-shell-product-card').first().click();
    await expect(page.locator('body')).toContainText(/1 artículo/);
    await page.evaluate(() => document.querySelector('#app').__vue_app__.config.globalProperties.$platform.modals.show('pos-confirm-clear-cart'));
    const modal = page.locator('.modal.show');
    await expect(modal).toHaveCount(1);
    await expect(modal.locator('.pos-confirm-clear-title')).toBeVisible();
    await expect(modal.locator('.modal-header')).toHaveCount(0); // hide-header
    await modal.getByRole('button', { name: /Yes, Clear It|Sí, vaciar|Sí, borrar/i }).click();
    await expect(page.locator('.modal.show')).toHaveCount(0);
    await expect(page.locator('body')).toContainText(/0 Artículos/, { timeout: 10_000 });
  });

  test('confirmación "vaciar carrito": ESC cancela y conserva el carrito', async ({ page }) => {
    await page.locator('.pos-shell-product-card').first().click();
    await page.evaluate(() => document.querySelector('#app').__vue_app__.config.globalProperties.$platform.modals.show('pos-confirm-clear-cart'));
    await expect(page.locator('.modal.show')).toHaveCount(1);
    await page.waitForTimeout(500);
    await page.keyboard.press('Escape');
    await expect(page.locator('.modal.show')).toHaveCount(0);
    await expect(page.locator('body')).toContainText(/1 artículo/);
  });

  test('cobro: cerrar el modal de pago con la X lo descarta y al reabrirlo el formulario está reiniciado', async ({ page }) => {
    await page.locator('.pos-shell-product-card').first().click();
    await page.getByRole('button', { name: /Pagar ahora/ }).click();
    const modal = page.locator('.modal.show').first();
    await expect(modal).toContainText(/Finalizar Pago/);
    const total = await modal.locator('input.form-input').first().inputValue();
    await modal.locator('input.form-input').first().fill('1');
    await modal.locator('.close-button').first().click();
    await expect(page.locator('.modal.show')).toHaveCount(0);
    await page.getByRole('button', { name: /Pagar ahora/ }).click();
    await expect(page.locator('.modal.show').first().locator('input.form-input').first()).toHaveValue(total);
    await page.keyboard.press('Escape');
  });

  test('cobro en efectivo: crea la venta, abre la factura por id y se cierra con la X', async ({ page }) => {
    await page.locator('.pos-shell-product-card').first().click();
    await page.getByRole('button', { name: /Pagar ahora/ }).click();
    const modal = page.locator('.modal.show').first();
    await expect(modal).toContainText(/Finalizar Pago/);
    const [response] = await Promise.all([
      page.waitForResponse(isCreatePos),
      modal.getByRole('button', { name: /Completar Pago/ }).click(),
    ]);
    expect(response.status()).toBe(200);
    const sale = await response.json();
    expect(sale.success).toBe(true);
    const { payments } = await apiJson(page, `/api/get_payments_by_sale/${sale.id}`);
    expect(payments).toHaveLength(1);
    await expect(page.locator('.modal.show')).toContainText(/PRODEX|Factura|Recibo|Imprimir/i, { timeout: 20_000 });
    await closeTopModal(page);
    await expect(page.locator('.modal-backdrop')).toHaveCount(0);
    expect(await page.evaluate(() => document.body.classList.contains('modal-open'))).toBe(false);
  });
});
