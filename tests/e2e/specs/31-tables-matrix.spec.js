const fs = require('fs');
const path = require('path');
const { test, expect } = require('../support/fixtures');
const { env, waitForApp } = require('../support/helpers');

// Tablas de BootstrapVue (`b-table`, `b-table-simple`) por PATRÓN de uso. Cada caso abre una pantalla real con datos deterministas (mock de API o
// datos asignados a la instancia del componente) y toma la firma del `<table>`: clases `table-*`, envoltorio responsive, cabeceras, filas,
// texto, estado ocupado y vacío. La firma se grabó sobre BootstrapVue 2 (`TABLES_RECORD=1` → tests/e2e/data/tables-baseline.json) y tras migrar
// a BootstrapVueNext debe ser la misma; además guarda una captura por tabla (`TABLES_SHOTS=<carpeta>`) para `visual/compare.js`.
// (`woocommerce/StatusOverviewTab.vue` no se monta en ninguna ruta: su tabla es idéntica a la de LogsTab, ya migrada en la fase 3.)
// Patrones cubiertos: small+responsive+striped (sesiones, fallos), fields+slots de celda (facturas), bordered (facturas),
// head-variant + table-modern (libro mayor), busy + slot #table-busy (detalle de cliente), show-empty + empty-text (sesiones sin filas),
// striped+hover (contratos, plantillas), b-table-simple + b-thead/b-tbody/b-tr/b-th/b-td (cocina).

const RECORD = process.env.TABLES_RECORD === '1';
const SHOTS = process.env.TABLES_SHOTS;
const FILE = path.join(__dirname, '..', 'data', 'tables-baseline.json');
const baseline = !RECORD && fs.existsSync(FILE) ? JSON.parse(fs.readFileSync(FILE, 'utf8')) : {};
const results = {};

// Firma del <table> (se ejecuta en el navegador sobre el elemento o su envoltorio).
// `spanishUiGuard` (utils) traduce los nodos de texto que cuelgan directamente de un <th>: BootstrapVue 2 los anidaba en un <div> y no los tocaba,
// BootstrapVueNext los pone en el <th> y se traducen. Se normaliza ese efecto (English → Spanish del propio diccionario del guard) y el texto
// accesible "Click to sort …", que BootstrapVue 2 pone entre paréntesis.
const GUARD = { Action: 'Acción', Name: 'Nombre', Ref: 'Referencia', Due: 'Pendiente', Status: 'Estado' };
const normText = (txt, guard) => {
  let out = txt.replace(/\(?Click to sort (ascending|descending)\)?/g, '').replace(/\s+/g, ' ').trim();
  for (const [en, es] of Object.entries(guard)) out = out.replace(new RegExp(`(^|\\s)${es}(?=\\s|$)`, 'g'), `$1${en}`);
  return out;
};
// Ocupada (`busy`): BootstrapVue 2 quitaba `table-hover`; BootstrapVueNext deja `table-hover` y añade `b-table-busy`. Ambos con aria-busy="true".
const busyNorm = (sig) => (sig && sig.busy === 'true' ? { ...sig, table: sig.table.filter((c) => c !== 'table-hover' && c !== 'b-table-busy') } : sig);
const normSig = (sig) => (sig ? { ...busyNorm(sig), ths: sig.ths.map((t) => normText(t, GUARD)), text: normText(sig.text, GUARD) } : sig);
const signatureOf = (t, guard) => {
  const norm = (txt) => {
    let out = txt.replace(/\(?Click to sort (ascending|descending)\)?/g, '').replace(/\s+/g, ' ').trim();
    for (const [en, es] of Object.entries(guard)) out = out.replace(new RegExp(`(^|\\s)${es}(?=\\s|$)`, 'g'), `$1${en}`);
    return out;
  };
  const table = t.tagName === 'TABLE' ? t : t.querySelector('table');
  if (!table) return null;
  const cls = (el, rx) => [...el.classList].filter((c) => rx.test(c)).sort();
  const thead = table.querySelector('thead');
  return {
    table: cls(table, /^(table|b-table)/),
    responsive: cls(table.parentElement, /^table-responsive/).join(' ') || null,
    thead: thead ? cls(thead, /^(thead-|table-|b-table)/) : null,
    ths: [...table.querySelectorAll('thead th')].map((th) => norm(th.textContent)),
    rows: table.querySelectorAll('tbody tr').length,
    busy: table.getAttribute('aria-busy'),
    text: norm(table.innerText).slice(0, 500),
  };
};

const json = (data) => ({ status: 200, contentType: 'application/json', body: JSON.stringify(data) });

const SESSIONS = [
  { token_id: 1, device: 'Chrome on macOS', ip_address: '10.0.0.5', login_at: '2026-03-01 09:00:00', last_activity_at: '2026-03-01 10:30:00', is_current: true },
  { token_id: 2, device: 'Firefox on Windows', ip_address: '10.0.0.9', login_at: '2026-02-28 18:20:00', last_activity_at: '2026-02-28 19:00:00', is_current: false },
];
const REPORT = {
  order_failures: [{ order_id: 11, order_number: 'WC-11', reason: 'SKU no vinculado', created_at: '2026-03-01' }, { order_id: 12, order_number: 'WC-12', reason: 'Sin stock', created_at: '2026-03-02' }],
  unlinked_products: { total: 2, sample: [{ id: 5, name: 'Camisa', sku: 'CAM-1', price: '10.00' }, { id: 6, name: 'Pantalón', sku: 'PAN-1', price: '20.00' }] },
  unlinked_variants: { total: 1, sample: [{ id: 7, name: 'Camisa M', sku: 'CAM-1-M', parent_id: 5 }] },
};

const goApp = async (page, url) => { await page.goto(url); await waitForApp(page); await page.waitForTimeout(1200); };

// Cada caso devuelve la lista de [nombre, selector CSS del <table> o de su envoltorio].
const CASES = [
  {
    // Tab real (nav "Seguridad", icono shield-check): el propio watcher de `activeTab` llama a
    // `LoadSecuritySessions()` (GET security/sessions, ya interceptada), como haría un usuario real.
    name: 'system_settings: sesiones (small, responsive="sm", cell slots, acciones)',
    run: async (page) => {
      await page.route('**/security/sessions', (r) => r.fulfill(json({ sessions: SESSIONS })));
      await goApp(page, '/app/settings/System_settings');
      await page.locator('.settings-nav-item:has(.lucide-shield-check)').click();
      await page.waitForTimeout(600);
      return [['sesiones', '.system-actions-card table.b-table, .system-actions-card table.table']];
    },
  },
  {
    name: 'system_settings: sesiones vacías (show-empty + empty-text)',
    run: async (page) => {
      await page.route('**/security/sessions', (r) => r.fulfill(json({ sessions: [] })));
      await goApp(page, '/app/settings/System_settings');
      await page.locator('.settings-nav-item:has(.lucide-shield-check)').click();
      await page.waitForTimeout(600);
      return [['sesiones-vacio', '.system-actions-card table.b-table, .system-actions-card table.table']];
    },
  },
  {
    // Botón real "View Unmapped Items" (ProductsTab.vue): abre el modal y dispara `loadUnmappedReport()`
    // (GET woocommerce/products/unmapped-report, ya interceptada), como haría un usuario real.
    name: 'woocommerce: informe de no mapeados (small + striped + responsive dentro de un modal)',
    run: async (page) => {
      await page.route('**/woocommerce/products/unmapped-report**', (r) => r.fulfill(json({ ok: true, ...REPORT })));
      await goApp(page, '/app/woocommerce');
      await page.locator('.pxcfg__tab').nth(1).click();
      await page.waitForTimeout(800);
      // ProductsTab.vue trae su propia mini-pestaña interna ("WooCommerce → Stocky"): el botón vive ahí, no en
      // la primera ("Stocky → WooCommerce", activa por defecto).
      await page.getByRole('tab', { name: /WooCommerce → Stocky/ }).click();
      await page.getByRole('button', { name: 'View Unmapped Items' }).click();
      await page.locator('.modal.show table').first().waitFor({ timeout: 10_000 });
      await page.waitForTimeout(500);
      return [['woo-fallos', '.modal.show table >> nth=0'], ['woo-productos', '.modal.show table >> nth=1'], ['woo-variantes', '.modal.show table >> nth=2']];
    },
  },
  {
    name: 'subscription_product: facturas (bordered + responsive, fields dinámicos, cell slots)',
    run: async (page) => {
      await page.route('**/subscriptions/1', (r) => r.fulfill(json({
        subscription: { id: 1, ref: 'SUB-1', status: 'active', product: { name: 'Plan' }, customer: { name: 'Cliente' } },
        invoices: [{ sale_id: 9, ref: 'INV-9', date: '2026-03-01', total: '100.00', status: 'paid' }, { sale_id: 10, ref: 'INV-10', date: '2026-04-01', total: '100.00', status: 'unpaid' }],
        invoiceFields: [{ key: 'ref', label: 'Ref' }, { key: 'date', label: 'Fecha' }, { key: 'total', label: 'Total' }, { key: 'status', label: 'Estado' }],
      })));
      await goApp(page, '/app/subscription_product/detail/1');
      return [['suscripcion-facturas', 'table.b-table, table.table']];
    },
  },
  {
    // CustomerDetails.vue carga sales/payments/returns/payment_returns en un único batch (Promise.allSettled en
    // created()) contra las rutas reales, ya interceptadas; cada pestaña real (b-tabs, "lazy") solo decide cuál
    // de las 4 tablas ya cargadas se ve. Sin setVm: se hace clic en la pestaña real (role=tab) como un usuario.
    name: 'cliente: detalle (striped + hover + responsive + busy, slot #table-busy)',
    run: async (page) => {
      const row = (i) => ({ id: i, Ref: `REF-${i}`, Sale_Ref: `SL-${i}`, date: '2026-03-01', GrandTotal: 100 * i, paid_amount: 50 * i, due: i === 1 ? 50 : 0, payment_status: i === 1 ? 'partial' : 'paid', payment_type: 'Cash', montant: 25 * i, statut: 'completed', Reglement: 'Cash' });
      const rows = [row(1), row(2)];
      await page.route('**/clients/1', (r) => (r.request().method() === 'GET' ? r.fulfill(json({ client: { id: 1, name: 'Cliente demo' }, payment_methods: [], accounts: [] })) : r.continue()));
      await page.route('**/custom-fields**', (r) => r.fulfill(json({ custom_fields: [] })));
      await page.route('**/custom-field-values**', (r) => r.fulfill(json({ success: true, values: {} })));
      await page.route('**/sales_client**', (r) => r.fulfill(json({ sales: rows, totalRows: rows.length })));
      await page.route('**/payments_client**', (r) => r.fulfill(json({ payments: rows, totalRows: rows.length })));
      await page.route('**/returns_client**', (r) => r.fulfill(json({ returns: rows, totalRows: rows.length })));
      await page.route('**/payment_returns_client**', (r) => r.fulfill(json({ payment_returns: rows, totalRows: rows.length })));
      await goApp(page, '/app/People/customers/1/details');
      await page.waitForTimeout(700);
      const tab = (n) => async () => { await page.getByRole('tab').nth(n).click(); await page.waitForTimeout(500); };
      return [
        ['cliente-ventas', 'table', tab(0)],
        ['cliente-pagos', 'table', tab(1)],
        ['cliente-devoluciones', 'table', tab(2)],
        ['cliente-pagos-devol', 'table', tab(3)],
        // Ocupada: `fetchSales()` (re-búsqueda real, @input del campo) se retrasa indefinidamente en la propia
        // red — `:busy` real, no un flag inyectado. CustomerDetails solo carga una vez en created(): hace
        // falta disparar una petición nueva para observar el estado ocupado.
        ['cliente-ventas-ocupada', 'table', async () => {
          await page.getByRole('tab').nth(0).click();
          await page.unroute('**/sales_client**');
          await page.route('**/sales_client**', () => new Promise(() => {}));
          await page.locator('table').first().locator('xpath=ancestor::*[@role="tabpanel"]').locator('input').first().pressSequentially('x');
          await page.waitForTimeout(400);
        }],
      ];
    },
  },
  {
    // CustomerLedger.vue: mismo patrón — created() carga sales/payments/quotations/returns de una vez contra
    // las rutas reales; se hace clic en la pestaña real para ver cada tabla ya cargada.
    name: 'cliente: libro mayor (striped + hover + small + head-variant="light" + class="table-modern")',
    run: async (page) => {
      const row = (i) => ({ id: i, Ref: `REF-${i}`, Sale_Ref: `SL-${i}`, date: '2026-03-01', GrandTotal: 100 * i, paid_amount: 50 * i, due: i === 1 ? 50 : 0, payment_status: i === 1 ? 'partial' : 'paid', payment_type: 'Cash', montant: 25 * i, statut: 'completed' });
      const rows = [row(1), row(2)];
      await page.route('**/clients/1/brief', (r) => r.fulfill(json({ name: 'Cliente demo' })));
      await page.route('**/sales_client**', (r) => r.fulfill(json({ sales: rows, totalRows: rows.length })));
      await page.route('**/payments_client**', (r) => r.fulfill(json({ payments: rows, totalRows: rows.length })));
      await page.route('**/quotations_client**', (r) => r.fulfill(json({ quotations: rows, totalRows: rows.length })));
      await page.route('**/returns_client**', (r) => r.fulfill(json({ returns_customer: rows, totalRows: rows.length })));
      await goApp(page, '/app/People/customers/1/ledger');
      await page.waitForTimeout(700);
      const tab = (n) => async () => { await page.getByRole('tab').nth(n).click(); await page.waitForTimeout(500); };
      return [
        ['mayor-ventas', 'table', tab(0)],
        ['mayor-pagos', 'table', tab(1)],
        ['mayor-cotizaciones', 'table', tab(2)],
        ['mayor-devoluciones', 'table', tab(3)],
      ];
    },
  },
  {
    // GET /kitchen/orders (ya interceptada) puebla el tablero real; el botón "Details" de la tarjeta del pedido
    // dispara `openDetails(order)`, que abre el modal con exactamente ese pedido — igual que un usuario real.
    name: 'cocina: detalle del pedido (b-table-simple + b-thead/b-tbody/b-tr/b-th/b-td)',
    run: async (page) => {
      const order = { id: 1, ref: 'K-1', sale_id: 1, status: 'pending', customer_name: 'Cliente demo', created_at: '2026-03-01 10:00:00', items: [{ id: 1, name: 'Hamburguesa', quantity: 2, unit: 'u' }, { id: 2, name: 'Papas', quantity: 1, unit: '' }] };
      await page.route('**/kitchen/orders*', (r) => (r.request().method() === 'GET' ? r.fulfill(json({ grouped: { pending: [order], preparing: [], completed: [], on_hold: [] }, warehouses: [] })) : r.continue()));
      await page.route('**/kitchen/orders/poll*', (r) => r.fulfill(json({ grouped: { pending: [order], preparing: [], completed: [], on_hold: [] } })));
      await goApp(page, '/app/kitchen-display');
      await page.waitForTimeout(700);
      await page.locator('.t-btn.t-ghost.t-icon').first().click();
      await page.waitForTimeout(500);
      return [['cocina', '.modal.show table']];
    },
  },
  {
    name: 'contratos: tareas y plantillas (small; small + striped + hover)',
    run: async (page) => {
      // Un 404 de la API redirige a la página 404 (interceptor de axios): se simula un contrato existente.
      await page.route('**/contracts/1', (r) => r.fulfill(json({ contract: { id: 1, contract_number: 'C-1', title: 'Contrato', status: 'active', party_type: 'client', start_date: '2026-01-01', end_date: '2026-12-31', tasks: [{ id: 1, title: 'Firmar', due_date: '2026-03-01', status: 'pending' }, { id: 2, title: 'Enviar', due_date: '2026-03-05', status: 'done' }], comments: [], notes: [], renewals: [], attachments: [] } })));
      await page.route('**/contracts-templates', (r) => r.fulfill(json({ templates: [{ id: 1, name: 'Plantilla A' }, { id: 2, name: 'Plantilla B' }] })));
      await page.route('**/contracts/merge-fields', (r) => r.fulfill(json({ merge_fields: [] })));
      await goApp(page, '/app/contracts/view/1');
      // Pestaña real (role=tab): Contract/Attachments/Comments/Renewal History/Tasks/Notes/Templates, orden fijo del template.
      const tab = (n) => async () => { await page.getByRole('tab').nth(n).click(); await page.waitForTimeout(600); };
      return [['contrato-tareas', '.tab-pane.active table', tab(4)], ['contrato-plantillas', '.tab-pane.active table', tab(6)]];
    },
  },
];

// (sin modo serie: un caso que falla no oculta a los demás)
test.describe('Tablas BootstrapVue por patrón @smoke', () => {
  test.use({ storageState: path.join(env.authDir, 'admin.json') });
  test.setTimeout(120_000);

  for (const c of CASES) {
    test(c.name, async ({ page }) => {
      const errors = [];
      page.on('pageerror', (e) => errors.push(e.message));
      const tables = await c.run(page);
      for (const [name, selector, prep] of tables) {
        if (prep) await prep();
        const nth = /^(.*) >> nth=(\d+)$/.exec(selector);
        const loc = nth ? page.locator(nth[1]).nth(Number(nth[2])) : page.locator(selector).first();
        const el = await loc.elementHandle({ timeout: 5000 }).catch(() => null);
        const real = el ? await el.evaluate(signatureOf, GUARD) : null;
        results[name] = real;
        if (SHOTS && el) { fs.mkdirSync(SHOTS, { recursive: true }); await el.screenshot({ path: path.join(SHOTS, `${name}.png`) }); }
        if (!RECORD) {
          expect(real, `${name}: tabla presente`).not.toBeNull();
          expect(normSig(real), `${name}: firma`).toEqual(normSig(baseline[name]));
        } else {
          expect(real, `${name}: tabla presente al grabar`).not.toBeNull();
        }
      }
      expect(errors.filter((e) => !/ResizeObserver/.test(e)), 'errores de página').toEqual([]);
    });
  }

  // Comportamiento (no firma): orden local por columna con `sortable: true`, sin `sort-by` externo. Ascendente → descendente, con aria-sort.
  test('orden local: clic en la cabecera ordena asc/desc y marca aria-sort (fields sortable)', async ({ page }) => {
    const rows = ['B', 'A', 'C'].map((r, i) => ({ id: i + 1, Ref: `REF-${r}`, date: `2026-03-0${i + 1}`, GrandTotal: 10 * (i + 1), paid_amount: 0, due: 0, payment_status: 'paid', statut: 'completed' }));
    await page.route('**/clients/1', (r) => (r.request().method() === 'GET' ? r.fulfill(json({ client: { id: 1, name: 'Cliente demo' }, payment_methods: [], accounts: [] })) : r.continue()));
    await page.route('**/custom-fields**', (r) => r.fulfill(json({ custom_fields: [] })));
    await page.route('**/custom-field-values**', (r) => r.fulfill(json({ success: true, values: {} })));
    await page.route('**/sales_client**', (r) => r.fulfill(json({ sales: rows, totalRows: rows.length })));
    await page.route('**/payments_client**', (r) => r.fulfill(json({ payments: [], totalRows: 0 })));
    await page.route('**/returns_client**', (r) => r.fulfill(json({ returns: [], totalRows: 0 })));
    await page.route('**/payment_returns_client**', (r) => r.fulfill(json({ payment_returns: [], totalRows: 0 })));
    await goApp(page, '/app/People/customers/1/details');
    await page.waitForTimeout(700);
    const table = page.locator('table').first();
    const firstCol = () => table.locator('tbody tr td:first-child').allInnerTexts().then((l) => l.map((t) => t.trim()));
    expect(await firstCol()).toEqual(['REF-B', 'REF-A', 'REF-C']);
    const th = table.locator('thead th').first();
    await th.click();
    expect(await firstCol()).toEqual(['REF-A', 'REF-B', 'REF-C']);
    await expect(th).toHaveAttribute('aria-sort', 'ascending');
    await th.click();
    expect(await firstCol()).toEqual(['REF-C', 'REF-B', 'REF-A']);
    await expect(th).toHaveAttribute('aria-sort', 'descending');
  });

  // Shopify (módulo opcional; en el tenant E2E la API responde 403): con la API simulada. No hay firma grabada sobre BootstrapVue 2 (se migró junto
  // con el resto de tablas de la fase 4); se comprueba el contrato de `b-table` explícitamente: striped + hover + responsive, filas y slots de celda.
  test('shopify: tiendas y registros (striped + hover + responsive, cell slots)', async ({ page }) => {
    await page.route('**/shopify/stores', (r) => (r.request().method() === 'GET' ? r.fulfill(json({ stores: [{ id: 1, name: 'Tienda demo', shop_domain: 'demo.myshopify.com', api_version: '2024-10', warehouse_id: null, location_name: 'Principal', is_active: true }, { id: 2, name: 'Tienda 2', shop_domain: 'dos.myshopify.com', api_version: '2024-10', warehouse_id: null, location_name: '', is_active: false }], warehouses: [] })) : r.continue()));
    await page.route('**/shopify/stores/*/test-connection', (r) => r.fulfill(json({ ok: true })));
    await page.route('**/shopify/logs**', (r) => r.fulfill(json({ data: [{ id: 1, created_at: '2026-03-01T10:00:00Z', action: 'products.push', level: 'error', message: 'Falló', context: { sku: 'A' } }, { id: 2, created_at: '2026-03-02T10:00:00Z', action: 'stock.push', level: 'info', message: 'OK', context: null }], total: 2, current_page: 1, last_page: 1 })));
    await goApp(page, '/app/shopify');
    const stores = page.locator('table.b-table').first();
    await expect(stores).toBeVisible({ timeout: 15_000 });
    await expect(stores).toHaveClass(/table-striped/);
    await expect(stores).toHaveClass(/table-hover/);
    await expect(page.locator('div.table-responsive').first()).toBeVisible();
    await expect(stores.locator('tbody tr')).toHaveCount(2);
    await expect(stores.locator('tbody tr').first()).toContainText('demo.myshopify.com');
    await page.locator('.pxcfg__tab').nth(5).click(); // "Ver registros"
    const logs = page.locator('table.b-table').first();
    await expect(logs.locator('tbody tr', { hasText: 'products.push' })).toHaveCount(1, { timeout: 10_000 });
    await expect(logs).toHaveClass(/table-striped/);
    await expect(logs).toHaveClass(/table-hover/);
  });

  test.afterAll(() => {
    if (RECORD) fs.writeFileSync(FILE, JSON.stringify(results, null, 1));
  });
});
