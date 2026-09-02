// =============================================================================
// PRODEX · Shell px-next — mapa de navegación REAL (data-driven, única fuente)
// -----------------------------------------------------------------------------
// Milestone 1: Panel · Ventas · Inventario · Compras (cableados).
// Milestone 2: Finanzas · Reportes · RR. HH. + Configuración / Más al pie.
//
// Continúa EXACTAMENTE el mismo modelo que M1 — no hay otro sistema de
// navegación. Cada entrada lleva:
//   · `to`      ruta INTERNA del shell (`/app/shell/<dominio>`) del dominio /
//               del ítem canónico que se queda dentro del chrome px-next.
//   · `route`   ruta REAL de PRODEX verificada contra `router.js`. El ítem sale
//               del shell (vuelve a largeSidebar) — igual que en M1. El cutover
//               del shell como layout persistente es una fase posterior.
//   · `anyPerm` lista de permisos; visible si el usuario tiene AL MENOS uno
//               (mismo mecanismo que `Sidebar.vue`: currentUserPermissions.includes).
//   · `plan`    flag de plan (mismo mecanismo que `Sidebar.vue::planFeature`).
//
// Auditoría: Sidebar.vue, VerticalSidebar.vue, router.js, main.js
// (router.addRoutes de /app/organization/*), B0-arquitectura-navegacion.md.
// No se inventaron rutas, permisos ni módulos.
// =============================================================================

export const SHELL_BASE = "/app/shell";

// ---- Catálogo de reportes (fuente única) ----------------------------------
// Lo consumen: el panel contextual de "Reportes" (sólo las CATEGORÍAS) y el
// hub `/app/shell/reportes` (todos los reportes reales permitidos). Rutas
// verificadas contra `router.js`. No se duplican en ningún otro sitio.
export const SHELL_REPORTS = [
  {
    key: "generales", title: "Generales", icon: "layout-dashboard",
    items: [
      { label: "Analítica", icon: "trending-up", route: "/app/reports/analytics_report", anyPerm: ["analytics_report"] },
      { label: "Transacciones", icon: "files", route: "/app/reports/report_transactions", anyPerm: ["report_transactions"] },
      { label: "Utilidad (P&L)", icon: "bar-chart-3", route: "/app/reports/profit_and_loss", anyPerm: ["Reports_profit"] },
      { label: "Reportes con IA", icon: "bar-chart-3", route: "/app/reports/ai_reports", plan: "ai_reports", anyPerm: ["AI_Reports"] }
    ]
  },
  {
    key: "ventas", title: "Ventas", icon: "receipt",
    items: [
      { label: "Ventas", icon: "receipt", route: "/app/reports/sales_report", anyPerm: ["Reports_sales"] },
      { label: "Ventas por producto", icon: "package", route: "/app/reports/product_sales_report", anyPerm: ["product_sales_report"] },
      { label: "Ventas por categoría", icon: "copy", route: "/app/reports/report_sales_by_category", anyPerm: ["report_sales_by_category"] },
      { label: "Ventas por marca", icon: "copy", route: "/app/reports/report_sales_by_brand", anyPerm: ["report_sales_by_brand"] },
      { label: "Top productos", icon: "trending-up", route: "/app/reports/top_selling_products", anyPerm: ["Top_products"] },
      { label: "Top clientes", icon: "users", route: "/app/reports/top_customers", anyPerm: ["Top_customers"] },
      { label: "Clientes", icon: "users", route: "/app/reports/customers_report", anyPerm: ["Reports_customers"] },
      { label: "Ratio de devoluciones", icon: "corner-up-left", route: "/app/reports/return_ratio_report", anyPerm: ["return_ratio_report"] },
      { label: "Descuentos", icon: "ticket", route: "/app/reports/discount_summary_report", anyPerm: ["discount_summary_report"] },
      { label: "Pagos de venta", icon: "coins", route: "/app/reports/payments_sale", anyPerm: ["Reports_payments_Sales"] }
    ]
  },
  {
    key: "compras", title: "Compras", icon: "shopping-cart",
    items: [
      { label: "Compras", icon: "shopping-cart", route: "/app/reports/purchase_report", anyPerm: ["Reports_purchase"] },
      { label: "Compras por producto", icon: "package", route: "/app/reports/product_purchases_report", anyPerm: ["product_purchases_report"] },
      { label: "Proveedores", icon: "truck", route: "/app/reports/providers_report", anyPerm: ["Reports_suppliers"] },
      { label: "Top proveedores", icon: "trending-up", route: "/app/reports/top_suppliers_report", anyPerm: ["Top_Suppliers_Report"] },
      { label: "Pagos de compra", icon: "coins", route: "/app/reports/payments_purchase", anyPerm: ["Reports_payments_Purchases"] }
    ]
  },
  {
    key: "inventario", title: "Inventario", icon: "boxes",
    items: [
      { label: "Existencias", icon: "boxes", route: "/app/reports/stock_report", anyPerm: ["stock_report"] },
      { label: "Valorización de inventario", icon: "calculator", route: "/app/reports/inventory_valuation_summary", anyPerm: ["inventory_valuation"] },
      { label: "Alertas de cantidad", icon: "shield", route: "/app/reports/quantity_alerts", anyPerm: ["Reports_quantity_alerts"] },
      { label: "Stock negativo", icon: "shield", route: "/app/reports/negative_stock_report", anyPerm: ["negative_stock_report"] },
      { label: "Antigüedad de stock", icon: "calendar-clock", route: "/app/reports/stock_aging_report", anyPerm: ["Stock_Aging_Report"] },
      { label: "Por vencer", icon: "calendar-clock", route: "/app/reports/expiry_report", anyPerm: ["expiry_report"] },
      { label: "Movimiento de producto", icon: "package", route: "/app/reports/product_report", anyPerm: ["product_report"] },
      { label: "Almacenes", icon: "store", route: "/app/reports/warehouse_report", anyPerm: ["Warehouse_report"] }
    ]
  },
  {
    key: "finanzas", title: "Finanzas", icon: "calculator",
    items: [
      { label: "Flujo de caja", icon: "trending-up", route: "/app/reports/cash_flow_report", anyPerm: ["cash_flow_report"] },
      { label: "Gastos", icon: "receipt", route: "/app/reports/expenses_report", anyPerm: ["expenses_report"] },
      { label: "Depósitos", icon: "landmark", route: "/app/reports/deposits_report", anyPerm: ["deposits_report"] },
      { label: "Resumen de impuestos", icon: "file-text", route: "/app/reports/tax_summary_report", anyPerm: ["tax_summary_report"] },
      { label: "Arqueo de caja", icon: "wallet", route: "/app/reports/cash-registers", anyPerm: ["cash_register_report"] }
    ]
  },
  {
    key: "personas", title: "Personas", icon: "users",
    items: [
      { label: "Usuarios", icon: "users", route: "/app/reports/users_report", anyPerm: ["users_report"] },
      { label: "Actividad de acceso", icon: "key-round", route: "/app/reports/login_activity_report", anyPerm: ["report_device_management"] },
      { label: "Asistencia", icon: "clock", route: "/app/reports/attendance_report", anyPerm: ["report_attendance_summary"] },
      { label: "Vendedores", icon: "user", route: "/app/reports/seller_report", anyPerm: ["seller_report"] }
    ]
  }
];

const _repCatPerms = c => c.items.reduce((a, i) => a.concat(i.anyPerm || []), []);
const _allReportPerms = SHELL_REPORTS.reduce((a, c) => a.concat(_repCatPerms(c)), []);

// ---- Riel principal ---------------------------------------------------------
// Panel · Ventas · Inventario · Compras: siempre según permiso (como M1).
// Finanzas · Reportes · RR. HH.: `gated` — sólo si el plan/permiso habilita ≥1
// opción real de su panel (B0 §7 / CONDITIONAL_TREATMENT).
export const SHELL_RAIL = [
  {
    key: "panel",
    label: "Panel",
    icon: "layout-dashboard",
    to: SHELL_BASE + "/panel",
    always: true,
    panel: null
  },
  {
    key: "ventas",
    label: "Ventas",
    icon: "shopping-cart",
    to: SHELL_BASE + "/ventas",
    anyPerm: ["Sales_view", "Sales_add", "Pos_view", "real_time_sales_counter", "Sale_Returns_view"],
    panel: {
      title: "Ventas",
      groups: [
        {
          title: "Operar",
          items: [
            { label: "Ventas", icon: "list", to: SHELL_BASE + "/ventas", activeMatch: "/app/sales", anyPerm: ["Sales_view"] },
            { label: "Nueva venta", icon: "plus", route: "/app/sales/store", anyPerm: ["Sales_add"] },
            { label: "Devoluciones", icon: "corner-up-left", route: "/app/sale_return/list", anyPerm: ["Sale_Returns_view"] },
            { label: "Importar ventas", icon: "download", route: "/app/sales/import_sales", anyPerm: ["Sales_add"] },
            { label: "Cotizaciones", icon: "file-text", route: "/app/quotations/list", plan: "quotations", anyPerm: ["Quotations_view", "Quotations_add"] },
            { label: "Promociones", icon: "ticket", route: "/app/promotions", plan: "promotions", anyPerm: ["promotion"] }
          ]
        },
        {
          title: "Cartera",
          items: [
            { label: "Clientes", icon: "users", route: "/app/People/Customers", anyPerm: ["Customers_view"] },
            { label: "Contador en tiempo real", icon: "activity", route: "/app/real-time-sales-counter", anyPerm: ["real_time_sales_counter"] }
          ]
        }
      ],
      reportsInline: ["Ventas por sucursal", "Top clientes", "Descuentos", "Devoluciones (ratio)"]
    }
  },
  {
    key: "inventario",
    label: "Inventario",
    icon: "boxes",
    to: SHELL_BASE + "/inventario",
    anyPerm: ["products_view", "products_add", "product_import", "adjustment_view", "damage_view", "count_stock", "barcode_view", "brand", "unit", "category"],
    panel: {
      title: "Inventario",
      groups: [
        {
          title: "Existencias",
          items: [
            { label: "Conteo de stock", icon: "check-check", route: "/app/products/count_stock", anyPerm: ["count_stock"] }
          ]
        },
        {
          title: "Movimientos",
          items: [
            { label: "Ajustes", icon: "settings-2", route: "/app/adjustments/list", anyPerm: ["adjustment_view", "adjustment_add"] },
            { label: "Traslados", icon: "arrow-right-left", route: "/app/transfers/list", plan: "transfers", anyPerm: ["transfer_view", "transfer_add"] },
            { label: "Daños", icon: "shield", route: "/app/damages/list", anyPerm: ["damage_view"] }
          ]
        },
        {
          title: "Catálogo",
          items: [
            { label: "Productos", icon: "package", to: SHELL_BASE + "/inventario", activeMatch: "/app/products", anyPerm: ["products_view"] },
            { label: "Categorías", icon: "copy", route: "/app/products/Categories", anyPerm: ["category", "subcategory"] },
            { label: "Marcas", icon: "copy", route: "/app/products/Brands", anyPerm: ["brand"] },
            { label: "Unidades", icon: "copy", route: "/app/products/Units", anyPerm: ["unit"] },
            { label: "Lotes y vencimientos", icon: "calendar-clock", route: "/app/products/Batches", anyPerm: ["products_view"] },
            { label: "Códigos de barra", icon: "scan-barcode", route: "/app/products/barcode", anyPerm: ["barcode_view"] },
            { label: "Números de serie", icon: "hash", route: "/app/serial_numbers/list", anyPerm: ["serial_numbers"] },
            { label: "Importar / actualizar", icon: "download", route: "/app/products/import", anyPerm: ["product_import"] }
          ]
        }
      ],
      reportsInline: ["Kardex valorizado", "Rotación", "Antigüedad de stock", "Stock negativo", "Por vencer"]
    }
  },
  {
    key: "compras",
    label: "Compras",
    icon: "receipt",
    to: SHELL_BASE + "/compras",
    anyPerm: ["Purchases_view", "Purchases_add", "Purchase_Returns_view"],
    panel: {
      title: "Compras",
      groups: [
        {
          title: "Operar",
          items: [
            { label: "Compras", icon: "list", to: SHELL_BASE + "/compras", activeMatch: "/app/purchases", anyPerm: ["Purchases_view"] },
            { label: "Nueva orden de compra", icon: "plus", route: "/app/purchases/store", anyPerm: ["Purchases_add"] },
            { label: "Devoluciones a proveedor", icon: "corner-up-right", route: "/app/purchase_return/list", anyPerm: ["Purchase_Returns_view"] },
            { label: "Importar compras", icon: "download", route: "/app/purchases/import_purchases", anyPerm: ["Purchases_add"] }
          ]
        },
        {
          title: "Abastecimiento",
          items: [
            { label: "Proveedores", icon: "truck", route: "/app/People/Suppliers", anyPerm: ["Suppliers_view"] }
          ]
        }
      ],
      reportsInline: ["Compras por proveedor", "Top proveedores", "Pagos de compra", "Alertas de cantidad"]
    }
  },

  // ===================== Milestone 2 — dominios condicionales =====================
  {
    key: "finanzas",
    label: "Finanzas",
    icon: "calculator",
    to: SHELL_BASE + "/finanzas",
    gated: true,
    plan: "accounting",
    // Dominio visible con acceso real a contabilidad/tesorería. Comisiones es un
    // ítem del panel (gate propio); no habilita el dominio por sí solo.
    anyPerm: [
      "accounting_dashboard", "chart_of_accounts", "journal_entries", "trial_balance",
      "accounting_profit_loss", "balance_sheet", "accounting_tax_report",
      "account", "transfer_money", "expense_view", "expense_add", "deposit_view", "deposit_add"
    ],
    panel: {
      title: "Finanzas",
      groups: [
        {
          title: "Resumen",
          items: [
            { label: "Panel de Finanzas", icon: "calculator", to: SHELL_BASE + "/finanzas", anyPerm: ["accounting_dashboard", "chart_of_accounts", "journal_entries", "trial_balance", "accounting_profit_loss", "balance_sheet", "accounting_tax_report", "account", "transfer_money", "expense_view", "expense_add", "deposit_view", "deposit_add"] }
          ]
        },
        {
          title: "Contabilidad",
          items: [
            { label: "Panel contable", icon: "trending-up", route: "/app/accounting-v2/dashboard", anyPerm: ["accounting_dashboard"] },
            { label: "Catálogo de cuentas", icon: "database", route: "/app/accounting-v2/chart-of-accounts", anyPerm: ["chart_of_accounts"] },
            { label: "Asientos de diario", icon: "files", route: "/app/accounting-v2/journal-entries", anyPerm: ["journal_entries"] },
            { label: "Balanza de comprobación", icon: "bar-chart-3", route: "/app/accounting-v2/reports/trial-balance", anyPerm: ["trial_balance"] },
            { label: "Estado de resultados", icon: "trending-up", route: "/app/accounting-v2/reports/profit-and-loss", anyPerm: ["accounting_profit_loss"] },
            { label: "Balance general", icon: "pie-chart", route: "/app/accounting-v2/reports/balance-sheet", anyPerm: ["balance_sheet"] },
            { label: "Resumen de impuestos", icon: "file-text", route: "/app/accounting-v2/reports/tax-report", anyPerm: ["accounting_tax_report"] }
          ]
        },
        {
          title: "Tesorería",
          items: [
            { label: "Gastos", icon: "receipt", route: "/app/expenses/list", anyPerm: ["expense_view"] },
            { label: "Categorías de gasto", icon: "copy", route: "/app/expenses/category", anyPerm: ["expense_view"] },
            { label: "Depósitos", icon: "landmark", route: "/app/deposits/list", anyPerm: ["deposit_view"] },
            { label: "Categorías de depósito", icon: "copy", route: "/app/deposits/category", anyPerm: ["deposit_view"] },
            { label: "Cuentas", icon: "wallet", route: "/app/accounts", anyPerm: ["account"] },
            { label: "Transferencia de dinero", icon: "arrow-right-left", route: "/app/transfer_money", anyPerm: ["transfer_money"] }
          ]
        },
        {
          title: "Comercial",
          items: [
            { label: "Comisiones", icon: "percent", route: "/app/commissions/programs", plan: "commissions", anyPerm: ["commissions_view"] }
          ]
        },
        {
          title: "Cumplimiento fiscal",
          items: [
            { label: "Cumplimiento fiscal (SAR)", icon: "file-text", route: "/app/settings/sar_fiscal", anyPerm: ["setting_system"] }
          ]
        },
        {
          title: "Reportes",
          items: [
            { label: "Flujo de caja", icon: "bar-chart-3", route: "/app/reports/cash_flow_report", anyPerm: ["cash_flow_report"] },
            { label: "Utilidad (P&L)", icon: "bar-chart-3", route: "/app/reports/profit_and_loss", anyPerm: ["Reports_profit"] },
            { label: "Reporte de gastos", icon: "bar-chart-3", route: "/app/reports/expenses_report", anyPerm: ["expenses_report"] },
            { label: "Reporte de depósitos", icon: "bar-chart-3", route: "/app/reports/deposits_report", anyPerm: ["deposits_report"] }
          ]
        }
      ]
    }
  },
  {
    key: "reportes",
    label: "Reportes",
    icon: "bar-chart-3",
    to: SHELL_BASE + "/reportes",
    gated: true,
    // Índice global "Todos los reportes" (B0 D1). Visible si el usuario tiene
    // al menos un permiso de reporte. `anyPerm` = unión de todo el catálogo.
    anyPerm: _allReportPerms,
    panel: {
      title: "Reportes",
      // El panel se queda en el nivel superior: Resumen + Categorías. El detalle
      // (los ~35 reportes reales) vive en el hub `/app/shell/reportes` (con
      // búsqueda). "Todos los reportes" abre el hub completo; cada categoría lo
      // abre filtrado por `?cat=<key>` — sin rutas nuevas por categoría.
      groups: [
        {
          title: "Resumen",
          items: [
            { label: "Todos los reportes", icon: "bar-chart-3", to: SHELL_BASE + "/reportes", catchAllReports: true, anyPerm: _allReportPerms }
          ]
        },
        {
          title: "Categorías",
          items: SHELL_REPORTS.map(c => ({
            label: c.title,
            icon: c.icon,
            to: SHELL_BASE + "/reportes",
            query: { cat: c.key },
            anyPerm: _repCatPerms(c)
          }))
        }
      ]
    }
  },
  {
    key: "rrhh",
    label: "RR. HH.",
    icon: "id-card",
    to: SHELL_BASE + "/rrhh",
    gated: true,
    plan: "hrm",
    anyPerm: ["company", "department", "designation", "office_shift", "view_employee", "attendance", "leave", "holiday", "payroll"],
    panel: {
      title: "RR. HH.",
      groups: [
        {
          title: "Resumen",
          items: [
            { label: "Panel de RR. HH.", icon: "id-card", to: SHELL_BASE + "/rrhh", anyPerm: ["company", "department", "designation", "office_shift", "view_employee", "attendance", "leave", "holiday", "payroll"] }
          ]
        },
        {
          title: "Personal",
          items: [
            { label: "Empleados", icon: "users", route: "/app/hrm/employees", anyPerm: ["view_employee"] },
            { label: "Asistencia", icon: "clock", route: "/app/hrm/attendance", anyPerm: ["attendance"] },
            { label: "Solicitudes de permiso", icon: "calendar", route: "/app/hrm/leaves/list", anyPerm: ["leave"] },
            { label: "Tipos de permiso", icon: "list", route: "/app/hrm/leaves/type", anyPerm: ["leave"] },
            { label: "Días festivos", icon: "calendar-check", route: "/app/hrm/holidays", anyPerm: ["holiday"] },
            { label: "Nómina", icon: "banknote", route: "/app/hrm/payrolls", anyPerm: ["payroll"] }
          ]
        },
        {
          title: "Estructura",
          items: [
            { label: "Departamentos", icon: "building-2", route: "/app/hrm/departments", anyPerm: ["department"] },
            { label: "Cargos", icon: "id-card", route: "/app/hrm/designations", anyPerm: ["designation"] },
            { label: "Turnos", icon: "clock", route: "/app/hrm/office_Shift", anyPerm: ["office_shift"] },
            { label: "Empresa", icon: "briefcase-business", route: "/app/hrm/company", anyPerm: ["company"] }
          ]
        },
        {
          title: "Reclutamiento",
          items: [
            { label: "Panel de reclutamiento", icon: "layout-dashboard", route: "/app/recruit/dashboard", plan: "recruitment", anyPerm: ["recruit_job"] },
            { label: "Vacantes", icon: "briefcase-business", route: "/app/recruit/jobs", plan: "recruitment", anyPerm: ["recruit_job"] },
            { label: "Candidatos", icon: "user", route: "/app/recruit/candidates", plan: "recruitment", anyPerm: ["recruit_candidate"] },
            { label: "Postulaciones", icon: "file-text", route: "/app/recruit/applications", plan: "recruitment", anyPerm: ["recruit_application"] },
            { label: "Entrevistas", icon: "calendar", route: "/app/recruit/interviews", plan: "recruitment", anyPerm: ["recruit_interview"] },
            { label: "Categorías de vacante", icon: "list", route: "/app/recruit/categories", plan: "recruitment", anyPerm: ["recruit_category"] }
          ]
        },
        {
          title: "Reportes",
          items: [
            { label: "Asistencia", icon: "bar-chart-3", route: "/app/reports/attendance_report", anyPerm: ["report_attendance_summary"] }
          ]
        }
      ]
    }
  }
];

// ---- Milestone 3 — resolución ruta REAL → dominio del shell ----------------
// El shell px-next puede envolver /app/* como layout persistente (opt-in). Como
// ya no basta con leer /app/shell/<dominio>, este mapa traduce cualquier ruta
// real a su dominio. ÚNICA fuente: no metas reglas ad-hoc en PxShell.vue.
//
// Orden = prioridad (primera coincidencia gana). Cada entrada usa `prefix`
// (coincidencia exacta o `prefix + "/"`) o `test` (RegExp) para familias que no
// se cubren con un prefijo limpio (p. ej. People, con hijos Customers*/Suppliers*
// y variantes de mayúsculas). Rutas verificadas contra router.js + main.js.
export const SHELL_ROUTE_DOMAINS = [
  // --- Panel ---
  { prefix: "/app/dashboard", domain: "panel" },

  // --- Ventas (clientes viven en Ventas por B0) ---
  { prefix: "/app/sales", domain: "ventas" },
  { prefix: "/app/sale_return", domain: "ventas" },
  { prefix: "/app/quotations", domain: "ventas" },
  { prefix: "/app/promotions", domain: "ventas" },

  // --- Compras (proveedores viven en Compras) ---
  { prefix: "/app/purchases", domain: "compras" },
  { prefix: "/app/purchase_return", domain: "compras" },

  // --- People: Suppliers* → Compras; el resto del módulo (Customers*, ledger,
  //     detalles, importaciones, redirect a Customers) → Ventas. ---
  { test: /^\/app\/People\/[Ss]uppliers/, domain: "compras" },
  { prefix: "/app/People", domain: "ventas" },

  // --- Inventario ---
  { prefix: "/app/products", domain: "inventario" },
  { prefix: "/app/adjustments", domain: "inventario" },
  { prefix: "/app/transfers", domain: "inventario" },
  { prefix: "/app/damages", domain: "inventario" },
  { prefix: "/app/serial_numbers", domain: "inventario" },
  { prefix: "/app/stock_intake", domain: "inventario" },
  { prefix: "/app/operations", domain: "inventario" },
  { prefix: "/app/inventory", domain: "inventario" },

  // --- Reportes (antes que Finanzas: /app/reports/* siempre es Reportes) ---
  { prefix: "/app/reports", domain: "reportes" },

  // --- Finanzas ---
  { prefix: "/app/accounting-v2", domain: "finanzas" },
  { prefix: "/app/expenses", domain: "finanzas" },
  { prefix: "/app/deposits", domain: "finanzas" },
  { prefix: "/app/accounts", domain: "finanzas" },
  { prefix: "/app/transfer_money", domain: "finanzas" },
  { prefix: "/app/commissions", domain: "finanzas" },

  // --- RR. HH. ---
  { prefix: "/app/hrm", domain: "rrhh" },
  { prefix: "/app/recruit", domain: "rrhh" },

  // --- Configuración ---
  { prefix: "/app/settings", domain: "config" },
  { prefix: "/app/User_Management", domain: "config" },
  { prefix: "/app/organization", domain: "config" },

  // --- Más herramientas ---
  { prefix: "/app/billing", domain: "mas" },
  { prefix: "/app/support", domain: "mas" },
  { prefix: "/app/knowledge-base", domain: "mas" },
  { prefix: "/app/Store", domain: "mas" },
  { prefix: "/app/whatsapp", domain: "mas" },
  { prefix: "/app/marketing", domain: "mas" },
  { prefix: "/app/woocommerce", domain: "mas" },
  { prefix: "/app/shopify", domain: "mas" },
  { prefix: "/app/projects", domain: "mas" },
  { prefix: "/app/tasks", domain: "mas" },
  { prefix: "/app/contracts", domain: "mas" },
  { prefix: "/app/service", domain: "mas" },
  { prefix: "/app/assets", domain: "mas" },
  { prefix: "/app/bookings", domain: "mas" },
  { prefix: "/app/realestate", domain: "mas" },
  { prefix: "/app/meeting", domain: "mas" },
  { prefix: "/app/subscription_product", domain: "mas" }
];

// Rutas que NUNCA usan el shell normal: contextos fullscreen / operativos que
// tienen su propio chrome (o ninguno). Aquí el layout px-next renderiza la
// página SIN rail/panel/topbar. `/app/pos` ya es una ruta top-level sin layout;
// se incluye por defensa en profundidad. login/portal/storefront/customer-display
// (bundles aparte) y superadmin (server) ni siquiera pasan por este layout.
export const SHELL_EXCLUDED_ROUTES = [
  "/app/pos",
  "/app/pos_",
  "/app/kitchen-display",
  "/app/customer-display",
  "/app/real-time-sales-counter",
  "/app/reports/sales-3d-dashboard"
];

// Rutas administrativas que SÍ usan el shell pero NO pertenecen a ningún
// dominio de navegación (estado neutro: rail + topbar, sin ítem activo, sin
// panel). Clasificación explícita y documentada — no un fallback silencioso.
//   · /app/profile — cuenta personal del usuario; se abre desde el chip de
//     perfil del topbar, no desde la navegación por dominios.
export const SHELL_NEUTRAL_ROUTES = [
  "/app/profile"
];

const KNOWN_DOMAINS = ["panel", "ventas", "inventario", "compras", "finanzas", "reportes", "rrhh", "config", "mas"];

function matchRouteRule(p, r) {
  if (r.test) return r.test.test(p);
  return p === r.prefix || p.indexOf(r.prefix + "/") === 0;
}

// Dominio de un path real. Rutas internas del shell (/app/shell/<dominio>) se
// resuelven por su segmento; el resto por SHELL_ROUTE_DOMAINS.
//
// FAIL-SAFE: una ruta que no coincide con NINGÚN prefijo/regla (o está en
// SHELL_NEUTRAL_ROUTES) devuelve `null` — estado neutro. NUNCA se hace pasar
// una ruta desconocida por "panel". El test de arquitectura
// ShellDomainCoverageArchitectureTest impide que una /app/* administrativa
// quede sin clasificar.
export function resolveShellDomain(path) {
  const p = String(path || "");
  const shell = p.match(/^\/app\/shell\/([a-z]+)/i);
  if (shell) {
    const seg = shell[1].toLowerCase();
    return KNOWN_DOMAINS.indexOf(seg) !== -1 ? seg : null;
  }
  if (SHELL_NEUTRAL_ROUTES.some(x => p === x || p.indexOf(x + "/") === 0)) return null;
  const hit = SHELL_ROUTE_DOMAINS.find(r => matchRouteRule(p, r));
  return hit ? hit.domain : null;
}

export function isShellExcluded(path) {
  const p = String(path || "");
  return SHELL_EXCLUDED_ROUTES.some(x => p === x || p.indexOf(x) === 0);
}

// Ruta REAL de un reporte (/app/reports/<x>) → clave de categoría de
// SHELL_REPORTS (generales | ventas | compras | inventario | finanzas |
// personas). `null` si el reporte no está catalogado. Fuente única: SHELL_REPORTS.
export function resolveReportCategory(path) {
  const p = String(path || "");
  for (let ci = 0; ci < SHELL_REPORTS.length; ci++) {
    const items = SHELL_REPORTS[ci].items || [];
    for (let i = 0; i < items.length; i++) {
      const r = items[i].route;
      if (r && (p === r || p.indexOf(r + "/") === 0)) return SHELL_REPORTS[ci].key;
    }
  }
  return null;
}

// ---- Pie del riel — Configuración / Más (reales, `gated`) ------------------
export const SHELL_FOOT = [
  {
    key: "config",
    label: "Configuración",
    icon: "settings",
    to: SHELL_BASE + "/config",
    foot: true,
    gated: true,
    anyPerm: [
      "setting_system", "appearance_settings", "translations_settings", "currency",
      "payment_methods", "payment_gateway", "backup", "system_health_view",
      "warehouse", "cash_drawers_view", "warehouse_locations",
      "users_view", "permissions_view", "permissions_edit", "login_device_management",
      "zatca_settings", "quickbooks_settings", "woocommerce_settings", "shopify_settings", "webhooks_view",
      "pos_settings", "sms_settings", "notification_template", "mail_settings",
      "users_edit", "users_add"
    ],
    panel: {
      title: "Configuración",
      groups: [
        {
          title: "Resumen",
          items: [
            { label: "Configuración", icon: "settings", to: SHELL_BASE + "/config", anyPerm: ["setting_system", "appearance_settings", "translations_settings", "currency", "payment_methods", "payment_gateway", "backup", "system_health_view", "warehouse", "cash_drawers_view", "warehouse_locations", "users_view", "permissions_view", "permissions_edit", "login_device_management", "zatca_settings", "quickbooks_settings", "woocommerce_settings", "shopify_settings", "webhooks_view", "pos_settings", "sms_settings", "notification_template", "mail_settings", "users_edit", "users_add"] }
          ]
        },
        {
          title: "Sistema",
          items: [
            { label: "Ajustes del sistema", icon: "settings", route: "/app/settings/System_settings", anyPerm: ["setting_system"] },
            { label: "Apariencia", icon: "settings-2", route: "/app/settings/appearance_settings", anyPerm: ["appearance_settings"] },
            { label: "Traducciones", icon: "globe", route: "/app/settings/translations_settings", anyPerm: ["translations_settings"] },
            { label: "Monedas", icon: "coins", route: "/app/settings/Currencies", anyPerm: ["currency"] },
            { label: "Respaldo", icon: "archive", route: "/app/settings/Backup", anyPerm: ["backup"] },
            { label: "Salud del sistema", icon: "activity", route: "/app/settings/system_health", anyPerm: ["system_health_view"] }
          ]
        },
        {
          title: "Organización",
          items: [
            { label: "Sucursales", icon: "building-2", route: "/app/organization/branches", anyPerm: ["warehouse"] },
            { label: "Almacenes", icon: "store", route: "/app/settings/Warehouses", anyPerm: ["warehouse"] },
            { label: "Cajas", icon: "wallet", route: "/app/settings/Cash_Drawers", anyPerm: ["cash_drawers_view"] },
            { label: "Ubicaciones de almacén", icon: "map-pin", route: "/app/settings/Warehouse_Locations", anyPerm: ["warehouse_locations"] },
            { label: "Acceso de empleados", icon: "users", route: "/app/organization/employee-access", anyPerm: ["users_edit", "users_add", "users_view"] }
          ]
        },
        {
          title: "Usuarios y acceso",
          items: [
            { label: "Usuarios", icon: "users", route: "/app/User_Management/Users", anyPerm: ["users_view"] },
            { label: "Roles y permisos", icon: "shield-check", route: "/app/User_Management/permissions", anyPerm: ["permissions_view"] },
            { label: "Plantillas de roles", icon: "shield-plus", route: "/app/organization/role-templates", anyPerm: ["permissions_edit", "permissions_view"] },
            { label: "Dispositivos de acceso", icon: "key-round", route: "/app/settings/login_devices", anyPerm: ["login_device_management"] }
          ]
        },
        {
          title: "Fiscal",
          items: [
            { label: "Cumplimiento fiscal (SAR)", icon: "file-text", route: "/app/settings/sar_fiscal", anyPerm: ["setting_system"] },
            { label: "ZATCA", icon: "file-text", route: "/app/settings/zatca_settings", plan: "zatca", anyPerm: ["zatca_settings"] }
          ]
        },
        {
          title: "POS y notificaciones",
          items: [
            { label: "Ajustes de POS", icon: "settings", route: "/app/settings/pos_settings", anyPerm: ["pos_settings"] },
            { label: "Recibo de POS", icon: "receipt", route: "/app/settings/pos_receipt", anyPerm: ["pos_settings"] },
            { label: "Métodos de pago", icon: "credit-card", route: "/app/settings/payment_methods", anyPerm: ["payment_methods"] },
            { label: "Pasarela de pago", icon: "credit-card", route: "/app/settings/payment_gateway", anyPerm: ["payment_gateway"] },
            { label: "Ajustes de SMS", icon: "message-circle", route: "/app/settings/sms_settings", anyPerm: ["sms_settings"] },
            { label: "Plantillas de SMS", icon: "file-text", route: "/app/settings/sms_templates", anyPerm: ["notification_template"] },
            { label: "Ajustes de correo", icon: "send", route: "/app/settings/mail_settings", anyPerm: ["mail_settings"] },
            { label: "Plantillas de correo", icon: "file-text", route: "/app/settings/email_templates", anyPerm: ["notification_template"] }
          ]
        },
        {
          title: "Integraciones",
          items: [
            { label: "QuickBooks", icon: "puzzle", route: "/app/settings/quickbooks_sync", plan: "quickbooks", anyPerm: ["quickbooks_settings"] },
            { label: "WooCommerce", icon: "puzzle", route: "/app/woocommerce", plan: "woocommerce", anyPerm: ["woocommerce_settings"] },
            { label: "Shopify", icon: "puzzle", route: "/app/shopify", plan: "shopify", anyPerm: ["shopify_settings"] },
            { label: "Webhooks", icon: "webhook", route: "/app/settings/webhooks", plan: "webhooks", anyPerm: ["webhooks_view"] }
          ]
        }
      ]
    }
  },
  {
    key: "mas",
    label: "Más herramientas",
    icon: "layout-grid",
    to: SHELL_BASE + "/mas",
    foot: true,
    gated: true,
    anyPerm: [
      "billing_view", "support", "knowledge_base_view",
      "projects", "tasks", "contracts", "service_jobs", "assets", "bookings",
      "realestate_properties", "meeting", "subscription_product",
      "Orders_view", "Store_settings_view", "marketing_dashboard",
      "whatsapp_settings", "whatsapp_templates", "whatsapp_logs"
    ],
    panel: {
      title: "Más herramientas",
      groups: [
        {
          title: "Resumen",
          items: [
            { label: "Todas las herramientas", icon: "layout-grid", to: SHELL_BASE + "/mas", anyPerm: ["billing_view", "support", "knowledge_base_view", "projects", "tasks", "contracts", "service_jobs", "assets", "bookings", "realestate_properties", "meeting", "subscription_product", "Orders_view", "Store_settings_view", "marketing_dashboard", "whatsapp_settings", "whatsapp_templates", "whatsapp_logs"] }
          ]
        },
        {
          title: "Cuenta PRODEX",
          items: [
            { label: "Plan y facturación", icon: "credit-card", route: "/app/billing/current-plan", anyPerm: ["billing_view"] },
            { label: "Cambiar de plan", icon: "credit-card", route: "/app/billing/change-plan", anyPerm: ["billing_view"] },
            { label: "Historial de facturación", icon: "files", route: "/app/billing/history", anyPerm: ["billing_view"] },
            { label: "Soporte", icon: "life-buoy", route: "/app/support/tickets", anyPerm: ["support"] },
            { label: "Base de conocimientos", icon: "book", route: "/app/knowledge-base", plan: "knowledge_base", anyPerm: ["knowledge_base_view"] }
          ]
        },
        {
          title: "Canales",
          items: [
            { label: "Tienda en línea", icon: "store", route: "/app/Store/Orders", plan: "online_orders", anyPerm: ["Orders_view", "Store_settings_view"] },
            { label: "Marketing", icon: "megaphone", route: "/app/marketing/dashboard", plan: "marketing", anyPerm: ["marketing_dashboard"] },
            { label: "WhatsApp", icon: "message-circle", route: "/app/whatsapp/settings", anyPerm: ["whatsapp_settings", "whatsapp_templates", "whatsapp_logs"] }
          ]
        },
        {
          title: "Herramientas",
          items: [
            { label: "Proyectos", icon: "briefcase-business", route: "/app/projects/list", plan: "projects", anyPerm: ["projects"] },
            { label: "Tareas", icon: "list", route: "/app/tasks/list", plan: "projects", anyPerm: ["tasks"] },
            { label: "Contratos", icon: "file-text", route: "/app/contracts/list", plan: "contracts", anyPerm: ["contracts"] },
            { label: "Servicio y mantenimiento", icon: "wrench", route: "/app/service/jobs", plan: "service_maintenance", anyPerm: ["service_jobs"] },
            { label: "Activos", icon: "package", route: "/app/assets/list", plan: "assets", anyPerm: ["assets"] },
            { label: "Reservas", icon: "calendar-check", route: "/app/bookings/list", plan: "bookings", anyPerm: ["bookings"] },
            { label: "Bienes raíces", icon: "building-2", route: "/app/realestate/properties", anyPerm: ["realestate_properties"] },
            { label: "Reuniones", icon: "calendar", route: "/app/meeting/dashboard", plan: "meetings", anyPerm: ["meeting"] },
            { label: "Producto de suscripción", icon: "refresh-cw", route: "/app/subscription_product/list", anyPerm: ["subscription_product"] }
          ]
        }
      ]
    }
  }
];
