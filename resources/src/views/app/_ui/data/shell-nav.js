// =============================================================================
// PRODEX · Shell funcional — mapa de navegación REAL (milestone acotado)
// -----------------------------------------------------------------------------
// Continúa la dirección aprobada en `B0-arquitectura-navegacion.md`
// (rail compacto: Panel · Ventas · Inventario · Compras · Finanzas · Reportes ·
//  RR. HH. + Configuración/Más al pie; panel contextual por dominio).
//
// A diferencia de `module-map.js` (artefacto de ANÁLISIS, con destinos/permisos
// descriptivos), aquí cada entrada lleva:
//   · `route`   ruta REAL de PRODEX verificada contra `router.js` (sale del shell,
//               vuelve al layout largeSidebar — navegación real intacta)
//   · `to`      ruta INTERNA del shell (`/app/shell/<dominio>`): el ítem canónico
//               de cada panel se queda dentro del chrome px-next y puede marcar
//               estado activo inequívoco
//   · `anyPerm` lista de permisos; visible si el usuario tiene AL MENOS uno
//               (mismo mecanismo que `Sidebar.vue`: currentUserPermissions.includes)
//   · `plan`    flag de plan (mismo mecanismo que `Sidebar.vue::planFeature`)
//
// ALCANCE DE ESTA RONDA: se cablean de verdad los 4 dominios core
// (Panel · Ventas · Inventario · Compras) y sus paneles contextuales.
// Finanzas / Reportes / RR. HH. / riel extendido / "Más" quedan VISIBLES pero
// marcados `pending: true` (sin navegación) hasta el siguiente milestone.
//
// No cambia la navegación real de PRODEX (Sidebar.vue / VerticalSidebar.vue /
// TopNav.vue / router intactos). El shell vive en su propia ruta `/app/shell`.
// =============================================================================

// Rutas internas del shell (envuelven la página real dentro del chrome px-next).
export const SHELL_BASE = "/app/shell";

// ---- Riel core — CABLEADO (4 dominios) --------------------------------------
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
            { label: "Ventas", icon: "list", to: SHELL_BASE + "/ventas", anyPerm: ["Sales_view"] },
            { label: "Nueva venta", icon: "plus", route: "/app/sales/store", anyPerm: ["Sales_add"] },
            { label: "Devoluciones", icon: "corner-up-left", route: "/app/sale_return/list", anyPerm: ["Sale_Returns_view"] },
            { label: "Importar ventas", icon: "download", route: "/app/sales/import_sales", anyPerm: ["Sales_add"] },
            { label: "Cotizaciones", icon: "file-text", route: "/app/quotations/list", plan: "quotations", anyPerm: ["Quotations_view", "Quotations_add"] },
            { label: "Promociones", icon: "tag", route: "/app/promotions", plan: "promotions", anyPerm: ["promotion"] }
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
            { label: "Productos", icon: "package", to: SHELL_BASE + "/inventario", anyPerm: ["products_view"] },
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
            { label: "Compras", icon: "list", to: SHELL_BASE + "/compras", anyPerm: ["Purchases_view"] },
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
  }
];

// ---- Riel core — PENDIENTE (visible, sin navegación este milestone) --------
// La estructura/decisión (B0) ya está aprobada; sólo falta el pase de mapeo de
// rutas/permisos reales (los ~45 permisos de reporte, las 7 rutas de
// contabilidad, las 10 de HRM). Se cablean en la siguiente ronda.
export const SHELL_RAIL_PENDING = [
  { key: "finanzas", label: "Finanzas", icon: "calculator", plan: "accounting", pending: true },
  { key: "reportes", label: "Reportes", icon: "bar-chart-3", pending: true },
  { key: "rrhh", label: "RR. HH.", icon: "id-card", plan: "hrm", pending: true }
];

// ---- Pie del riel — Configuración / Más (pendiente) -----------------------
export const SHELL_FOOT = [
  { key: "config", label: "Configuración", icon: "settings", pending: true },
  { key: "mas", label: "Más herramientas", icon: "more-horizontal", pending: true }
];
