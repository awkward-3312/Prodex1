const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, openPos, ensureRegisterOpen, ensureRegisterClosed, scanSku, apiJson, closeTopModal } = require('../support/helpers');

test.use({ storageState: path.join(env.authDir, 'admin.json') });

const isCreatePosRequest = (req) => req.url().endsWith('/api/pos/create_pos') && req.method() === 'POST';
const isCreatePos = (res) => isCreatePosRequest(res.request());

/** Abre "Pagar ahora" y devuelve el modal de cobro. */
async function openPayModal(page) {
  await page.getByRole('button', { name: /Pagar ahora/ }).click();
  const modal = page.locator('.modal.show').first();
  await expect(modal).toContainText(/Finalizar Pago/);
  return modal;
}

// Ventas reales (efectivo, mixto, con serial y con lote) en el tenant demo aislado. Cada test deja la caja abierta y el
// último la cierra. Los datos salen del seeder oficial (SKU PR-DEMO2-001 con lotes, PR-DEMO2-002 con seriales) más las
// ampliaciones de existencias que hace tests/e2e/scripts/provision.php.
test.describe.serial('POS: pago mixto, lote y serial @smoke', () => {
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

  test('venta con pago mixto (efectivo + tarjeta) queda pagada y registra ambos pagos', async ({ page }) => {
    await page.locator('.pos-shell-product-card').first().click();
    const modal = await openPayModal(page);

    const amounts = modal.locator('input.form-input');
    const total = parseFloat(await amounts.first().inputValue());
    expect(total).toBeGreaterThan(0);
    const cash = Math.round((total / 2) * 100) / 100;
    const card = Math.round((total - cash) * 100) / 100;

    await amounts.first().fill(String(cash)); // línea 1: efectivo (método por defecto)
    await modal.getByRole('button', { name: /Agregar Método de Pago/i }).click();
    await expect(amounts).toHaveCount(2); // una entrada de monto por línea de pago
    await amounts.nth(1).fill(String(card));
    await modal.getByText('Credit Card', { exact: true }).nth(1).click(); // línea 2: tarjeta
    await expect(modal).toContainText(/SALDO\s*L\s*0\.00/i);

    const [response] = await Promise.all([
      page.waitForResponse(isCreatePos),
      modal.getByRole('button', { name: /Completar Pago/ }).click(),
    ]);
    expect(response.status()).toBe(200);
    const sale = await response.json();
    expect(sale.success).toBe(true);

    const { payments } = await apiJson(page, `/api/get_payments_by_sale/${sale.id}`);
    expect(payments).toHaveLength(2);
    expect(new Set(payments.map((p) => p.payment_method_id)).size).toBe(2); // dos métodos distintos
    const paid = payments.reduce((sum, p) => sum + Number(p.montant), 0);
    expect(paid).toBeCloseTo(total, 2); // pagos + saldo = total, sin diferencia
    expect(payments.map((p) => Number(p.montant)).sort()).toEqual([cash, card].sort());

    await closeTopModal(page); // factura POS
  });

  test('venta con producto serializado consume el serial elegido', async ({ page }) => {
    await scanSku(page, 'PR-DEMO2-002');
    const line = page.locator('.pos-shell-cart-row').first();
    await expect(line).toContainText(/Serials Count:\s*0\s*\/\s*1/);
    await expect(page.getByRole('button', { name: /Pagar ahora/ })).toBeDisabled(); // sin serial no se puede cobrar

    const checkbox = line.locator('input[type=checkbox]').first();
    const serial = (await checkbox.evaluate((el) => el.parentElement.textContent)).trim();
    expect(serial).toMatch(/^SN-/);
    await checkbox.check();
    await expect(line).toContainText(/Serials Count:\s*1\s*\/\s*1/);

    const modal = await openPayModal(page);
    const [request, response] = await Promise.all([
      page.waitForRequest(isCreatePosRequest),
      page.waitForResponse(isCreatePos),
      modal.getByRole('button', { name: /Completar Pago/ }).click(),
    ]);
    expect(request.postDataJSON().details[0].serial_numbers).toEqual([serial]);
    expect((await response.json()).success).toBe(true);
    await closeTopModal(page);

    // El serial vendido ya no se ofrece.
    await scanSku(page, 'PR-DEMO2-002');
    await expect(page.locator('.pos-shell-cart-row').first()).toContainText(/Serials Count:\s*0\s*\/\s*1/);
    await expect(page.locator('.pos-shell-cart-row').first()).not.toContainText(serial);
    await page.getByRole('button', { name: /Restablecer/ }).click();
  });

  test('venta con producto por lote descuenta del lote elegido', async ({ page }) => {
    await scanSku(page, 'PR-DEMO2-001');
    const line = page.locator('.pos-shell-cart-row').first();
    await expect(line).toContainText(/Lotes/);
    await expect(page.getByRole('button', { name: /Pagar ahora/ })).toBeDisabled(); // sin lote no se puede cobrar

    // Existencias por lote ANTES de vender (producto 1 / almacén 1: ids del seeder demo).
    const before = new Map((await apiJson(page, '/api/batches_for_sale/1/1/0')).batches.map((b) => [b.id, b.qty_available]));

    await line.locator('.v-select').first().click();
    await page.locator('.vs__dropdown-option').first().click();
    await expect(page.getByRole('button', { name: /Pagar ahora/ })).toBeEnabled();

    const modal = await openPayModal(page);
    const [request, response] = await Promise.all([
      page.waitForRequest(isCreatePosRequest),
      page.waitForResponse(isCreatePos),
      modal.getByRole('button', { name: /Completar Pago/ }).click(),
    ]);
    const detail = request.postDataJSON().details[0];
    expect(detail.batches).toHaveLength(1);
    const { product_batch_id: batchId, qty } = detail.batches[0];
    expect((await response.json()).success).toBe(true);
    await closeTopModal(page);

    // El lote elegido bajó exactamente la cantidad vendida.
    const after = (await apiJson(page, '/api/batches_for_sale/1/1/0')).batches.find((b) => b.id === batchId);
    expect(Number(qty)).toBe(1);
    expect(after.qty_available).toBe(before.get(batchId) - Number(qty));
  });
});
