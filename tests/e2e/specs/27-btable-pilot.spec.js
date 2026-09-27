const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

// Piloto de BTable de BootstrapVueNext: settings/woocommerce/LogsTab (pantalla claramente no crítica). Datos idénticos por mock de `woocommerce/logs`.
// Usa: fields con label, items (computed filtrado+paginado), small, responsive="sm", thead-class, class y slots `#cell(date|action|direction|status|message)`.

const ROWS = Array.from({ length: 12 }, (_, i) => ({
  id: i + 1,
  created_at: `2026-03-${String(i + 1).padStart(2, '0')}T10:15:00Z`,
  action: i % 3 === 0 ? 'products.sync' : i % 3 === 1 ? 'stock.push' : 'orders.pull',
  level: i % 4 === 0 ? 'error' : i % 4 === 1 ? 'warning' : 'info',
  message: `Mensaje de registro ${i + 1}`,
}));
const mock = (page, rows) => page.route('**/woocommerce/logs**', (route) => (route.request().method() === 'GET'
  ? route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: rows }) })
  : route.continue()));
const openLogs = async (page) => {
  await page.goto('/app/woocommerce');
  await waitForApp(page);
  await page.locator('.pxcfg__tab').nth(7).click();
  await expect(page.locator('div.logs-table table')).toBeVisible();
};

test.describe('Piloto BTable (woocommerce/LogsTab)', () => {
  test.use({ storageState: path.join(env.authDir, 'admin.json') });

  test('render: fields, items paginados (10 de 12), slots de celda, small + responsive="sm" + thead-class', async ({ page }) => {
    await mock(page, ROWS);
    await openLogs(page);
    const table = page.locator('div.logs-table table');
    await expect(table.locator('thead th')).toHaveCount(5);
    await expect(table.locator('tbody tr')).toHaveCount(10);
    await expect(table).toHaveClass(/table-sm/);
    await expect(page.locator('div.logs-table')).toHaveClass(/table-responsive-sm/);
    await expect(table.locator('thead')).toHaveClass(/logs-table-header/);
    // orden: más reciente primero (computed filteredLogs)
    await expect(table.locator('tbody tr').first()).toContainText('Mensaje de registro 12');
    // slot #cell(date): fecha formateada con icono; #cell(status): badge con variante y texto
    await expect(table.locator('tbody tr').first().locator('td').first()).toContainText(/2026-03-\d\d \d\d:\d\d/);
    const badge = table.locator('tbody tr').first().locator('.badge');
    await expect(badge).toHaveCount(1);
    await expect(badge).toHaveClass(/badge-(success|warning|danger)/);
    await expect(table.locator('tbody tr').first().locator('.log-message')).toHaveText('Mensaje de registro 12');
  });

  test('interacción: filtro por estado (v-select) y paginación (b-pagination) actualizan los items sin duplicar filas', async ({ page }) => {
    await mock(page, ROWS);
    await openLogs(page);
    const table = page.locator('div.logs-table table');
    // paginación
    await page.locator('.pagination .page-link', { hasText: '2' }).first().click();
    await expect(table.locator('tbody tr')).toHaveCount(2);
    await expect(table.locator('tbody tr').first()).toContainText('Mensaje de registro 2');
    await page.locator('.pagination .page-link', { hasText: '1' }).first().click();
    await expect(table.locator('tbody tr')).toHaveCount(10);
    // filtro: solo errores (i % 4 === 0 → 3 filas)
    await page.locator('.v-select-modern').nth(1).locator('.vs__dropdown-toggle').click();
    await page.locator('.vs__dropdown-option', { hasText: /^\s*Error\s*$/ }).first().click();
    await expect(table.locator('tbody tr')).toHaveCount(3);
    for (const b of await table.locator('tbody .badge').evaluateAll((els) => els.map((e) => e.className))) expect(b).toMatch(/badge-danger/);
  });

  test('sin datos: la tabla queda sin filas (sin show-empty en esta tabla)', async ({ page }) => {
    await mock(page, []);
    await openLogs(page);
    await expect(page.locator('div.logs-table table tbody tr')).toHaveCount(0);
  });

  test('móvil (390px): contenedor responsive con scroll propio, sin desbordar la página', async ({ browser }) => {
    const ctx = await browser.newContext({ baseURL: env.baseURL, viewport: { width: 390, height: 844 }, storageState: path.join(env.authDir, 'admin.json') });
    const page = await ctx.newPage();
    await mock(page, ROWS);
    await openLogs(page);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
    await ctx.close();
  });
});
