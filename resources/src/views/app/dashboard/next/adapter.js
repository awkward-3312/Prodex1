// =============================================================================
// Fase B1 · Dashboard preview — adapter
// -----------------------------------------------------------------------------
// Normaliza la respuesta REAL de GET /api/dashboard_data para los componentes
// visuales del preview. NO inventa datos y NO duplica lógica de negocio: sólo
// desanida, renombra y castea.
//
// Peculiaridad de la respuesta actual: varias claves llegan como un JsonResponse
// serializado — el payload real está en `.original`:
//   sales · purchases · payments · customers · product_report · report_dashboard
// Otras llegan directas:
//   warehouses · sales_by_payment · stock_value · sales_by_cashier ·
//   sales_by_branch · branches
// =============================================================================

const NESTED = ["sales", "purchases", "payments", "customers", "product_report", "report_dashboard"];

/**
 * Devuelve `.original` si la clave llegó como JsonResponse serializado.
 * El backend serializa estas claves de forma inconsistente: unas traen
 * `{ original, headers, ... }` y otras sólo `{ original }`. El dashboard actual
 * (dashboard.vue) siempre lee `.original`, así que aquí basta con detectarla.
 */
function unwrap(value) {
  if (value && typeof value === "object" && !Array.isArray(value) && "original" in value) {
    return value.original;
  }
  return value;
}

const num = v => {
  const n = typeof v === "string" ? parseFloat(v) : v;
  return Number.isFinite(n) ? n : 0;
};
const arr = v => (Array.isArray(v) ? v : []);

/**
 * @param {object} raw  respuesta cruda de /api/dashboard_data
 * @returns {object} shape estable para los widgets
 */
export function adaptDashboard(raw) {
  const r = raw && typeof raw === "object" ? { ...raw } : {};
  NESTED.forEach(k => { if (k in r) r[k] = unwrap(r[k]); });

  const rd = r.report_dashboard && typeof r.report_dashboard === "object" ? r.report_dashboard : {};
  const report = rd.report && typeof rd.report === "object" ? rd.report : {};
  const sv = r.stock_value && typeof r.stock_value === "object" ? r.stock_value : {};

  // ---- KPIs (paridad con el dashboard actual) -------------------------------
  const kpis = {
    sales:        num(report.today_sales),
    purchases:    num(report.today_purchases),
    salesDue:     num(report.sales_due),
    purchaseDue:  num(report.purchase_due),
    profit:       num(report.today_profit),
    invoices:     num(report.today_invoices),
    returnSales:  num(report.return_sales),
    returnPurch:  num(report.return_purchases)
  };

  const stockValue = {
    byCost:      num(sv.by_cost),
    byRetail:    num(sv.by_retail),
    byWholesale: num(sv.by_wholesale)
  };

  // ---- Series de gráficos --------------------------------------------------- -
  const salesSeries = {
    days: arr(r.sales && r.sales.days),
    data: arr(r.sales && r.sales.data).map(num)
  };
  const purchasesSeries = {
    days: arr(r.purchases && r.purchases.days),
    data: arr(r.purchases && r.purchases.data).map(num)
  };
  const paymentsSeries = {
    days:     arr(r.payments && r.payments.days),
    sent:     arr(r.payments && r.payments.payment_sent).map(num),
    received: arr(r.payments && r.payments.payment_received).map(num)
  };

  // Donut "productos más vendidos" — paridad con dashboard.vue: product_report
  // es [{ name, value }] donde value = unidades vendidas.
  const productChart = arr(r.product_report).map(p => ({
    name:  p.name || p.label || "—",
    value: num(p.value != null ? p.value : p.total_sales)
  }));
  // Tabla lateral — report_dashboard.products trae además el importe.
  const topProducts = arr(rd.products).map(p => ({
    name:  p.name || p.product_name || "—",
    qty:   num(p.total_sales != null ? p.total_sales : p.quantity),
    total: num(p.total != null ? p.total : p.amount)
  }));

  // Pie "top clientes" — paridad con dashboard.vue: customers es [{ name, value }].
  const customerChart = arr(r.customers).map(c => ({
    name:  c.name || c.client_name || "—",
    value: num(c.value != null ? c.value : (c.count != null ? c.count : c.invoices))
  }));

  // Desglose de ventas por método de pago (dato directo, ya con % y color).
  const salesByPayment = arr(r.sales_by_payment).map(s => ({
    name:       s.name && s.name !== "---" ? s.name : "Sin método",
    amount:     num(s.amount),
    percentage: num(s.percentage),
    color:      s.color || null
  }));

  // ---- Tablas ------------------------------------------------------------- ---
  const recentSales = arr(rd.last_sales).map(s => ({
    id:     s.id,
    ref:    s.Ref || s.ref || "—",
    client: s.client_name || "—",
    warehouse: s.warehouse_name || "—",
    total:  num(s.GrandTotal),
    paid:   num(s.paid_amount),
    due:    num(s.due != null ? s.due : num(s.GrandTotal) - num(s.paid_amount)),
    status: s.payment_status || s.payment_statut || "—",
    state:  s.statut || null
  }));

  const stockAlerts = arr(rd.stock_alert).map(p => ({
    code:  p.code || p.product_code || "—",
    name:  p.name || p.product_name || "—",
    qty:   num(p.qte != null ? p.qte : (p.quantity != null ? p.quantity : p.stock)),
    alert: num(p.stock_alert),
    warehouse: p.warehouse_name || null
  }));

  // ---- Filtro de almacenes ---------------------------------------------- -----
  const warehouses = arr(r.warehouses).map(w => ({ id: w.id, name: w.name || `#${w.id}` }));

  // ---- Datos extra reales (post-migración) — disponibles, no en la paridad --
  const extras = {
    salesByCashier: arr(r.sales_by_cashier).map(x => ({
      name: x.cashier_name || "—", invoices: num(x.invoices), total: num(x.total_sales), paid: num(x.paid_amount), due: num(x.due)
    })),
    salesByBranch: arr(r.sales_by_branch).map(x => ({
      name: x.branch_name || "—", invoices: num(x.invoices), total: num(x.total_sales)
    })),
    branchesCount: arr(r.branches).length
  };

  const hasAnyData =
    kpis.sales || kpis.purchases || kpis.invoices ||
    recentSales.length || topProducts.length || customerChart.length ||
    salesSeries.data.some(Boolean) || salesByPayment.length ||
    stockValue.byCost || stockValue.byRetail;

  return {
    kpis, stockValue,
    salesSeries, purchasesSeries, paymentsSeries,
    productChart, customerChart, topProducts, salesByPayment,
    recentSales, stockAlerts,
    warehouses, extras,
    hasAnyData: !!hasAnyData
  };
}
