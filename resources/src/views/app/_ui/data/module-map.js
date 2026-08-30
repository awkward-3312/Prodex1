// =============================================================================
// PRODEX · Fase B0 — Inventario y mapa de navegación (ARTEFACTO DE ANÁLISIS)
// -----------------------------------------------------------------------------
// NO es (todavía) la fuente de verdad de la navegación de PRODEX. Es un
// artefacto de análisis para el playground /app/_ui. La arquitectura real de
// navegación se decide DESPUÉS de aprobar B0.
//
// Extraído (solo lectura) de:
//   · resources/src/containers/layouts/largeSidebar/Sidebar.vue          (Sidebar)
//   · resources/src/containers/layouts/largeSidebar/VerticalSidebar.vue  (VSidebar)
//   · resources/src/main.js  router.addRoutes(...)                        (runtime-router)
//   · resources/src/router.js                                            (router)
//   · resources/static/prodex-navigation-v3.js  (agrupamiento previo)    (nav-v3)
//
// No hay telemetría de navegación/uso (ni frontend ni backend). La columna
// `freq` es HEURÍSTICA con base declarada, no un dato objetivo.
//
//   freq:  "alta" | "media" | "baja"
//   basis: por qué esa heurística (importancia operacional / posición actual /
//          transversalidad / nº de rutas / rol habitual)
//   place: "rail" | "rail?" (solo si la estructura lo justifica) | "panel"
//          (contextual de un dominio) | "topbar" | "config" | "cuenta" |
//          "mas" (zona "Más herramientas") | "pos" (acceso especial)
// =============================================================================

// ---- 1. Dominios de trabajo propuestos --------------------------------------
// Evaluados por FLUJO DE NEGOCIO real, no por parecido de nombre.
export const DOMAINS = [
  {
    key: "panel", label: "Panel", icon: "layout-dashboard",
    thesis: "Estado del negocio de un vistazo al abrir sesión.",
    flow: "—"
  },
  {
    key: "ventas", label: "Ventas", icon: "receipt",
    thesis: "Todo lo que rodea a vender: del presupuesto al documento fiscal y su devolución. El cliente es el eje.",
    flow: "cotización → venta → factura fiscal (SAR/país) → cobro → devolución"
  },
  {
    key: "compras", label: "Compras", icon: "shopping-cart",
    thesis: "Abastecerse: ordenar, recibir, devolver a proveedor. El proveedor es el eje.",
    flow: "orden de compra → recepción (total/parcial) → factura proveedor → devolución"
  },
  {
    key: "inventario", label: "Inventario", icon: "boxes",
    thesis: "La arquitectura multi-ubicación de PRODEX: dónde está el stock, cómo se mueve, cómo se corrige. Diferenciador del producto.",
    flow: "catálogo → existencias por ubicación → ajuste / conteo / traslado / cuarentena / daño"
  },
  {
    key: "finanzas", label: "Contabilidad y finanzas", icon: "calculator",
    thesis: "El dinero: contabilidad, gastos, depósitos, comisiones y el cumplimiento fiscal (D5: «Cumplimiento fiscal», label/contenido por país; SAR = implementación Honduras).",
    flow: "asiento / gasto / depósito → conciliación → estados financieros → cumplimiento fiscal"
  },
  {
    key: "rrhh", label: "RR. HH.", icon: "id-card",
    thesis: "Las personas del negocio: empleados, asistencia, planilla y reclutamiento.",
    flow: "empleado → turno / asistencia → planilla · vacante → candidato → contratación"
  },
  {
    key: "reportes", label: "Reportes", icon: "bar-chart-3",
    thesis: "D1: los reportes se distribuyen en el panel de su dominio; el módulo de riel «Reportes» es el ÍNDICE GLOBAL «Todos los reportes» para el rol dueño/analista.",
    flow: "—"
  },
  {
    key: "tienda", label: "Tienda en línea", icon: "store",
    thesis: "Canal e-commerce: catálogo publicado, pedidos, clientes de tienda. Condicional por plan.",
    flow: "publicar → pedido en línea → confirmación de pago → cumplimiento"
  },
  {
    key: "marketing", label: "Marketing", icon: "megaphone",
    thesis: "Campañas, segmentos, plantillas, WhatsApp. Condicional por plan.",
    flow: "segmento → campaña → envío → reporte"
  },
  {
    key: "organizacion", label: "Organización", icon: "building-2",
    thesis: "D4: dominio administrativo estructural (sucursales, almacenes/CD, ubicaciones, usuarios, roles). NO en el riel compacto — vive en Configuración/Más y sube al riel EXTENDIDO cuando la estructura multi-sucursal lo justifica. La operación cotidiana de stock/ubicaciones permanece en Inventario.",
    flow: "sucursal / almacén → ubicaciones → usuarios → roles y permisos"
  },
  {
    key: "gestion", label: "Gestión (add-ons)", icon: "briefcase-business",
    thesis: "Módulos complementarios, condicionales por plan: proyectos, tareas, contratos, servicio/mantenimiento, activos, reservas.",
    flow: "varía por módulo"
  },
  {
    key: "config", label: "Configuración e integraciones", icon: "settings",
    thesis: "Puesta a punto del sistema: parámetros, plantillas, pasarelas, integraciones (Woo/Shopify/QuickBooks/ZATCA), webhooks, salud del sistema.",
    flow: "—"
  },
  {
    key: "cuenta", label: "Cuenta PRODEX", icon: "credit-card",
    thesis: "La relación del tenant con PRODEX (no con su propio negocio): plan, facturación, soporte, base de conocimientos.",
    flow: "—"
  },
  {
    key: "pos", label: "Punto de venta", icon: "scan-line",
    thesis: "FUERA DEL REDISEÑO. Es un modo a pantalla completa, no una página del shell. Solo se define desde dónde se accede.",
    flow: "—"
  }
];

// ---- 2. Inventario de destinos --------------------------------------------- --
// `dest`: prefijo de módulo · `routes`: nº aprox. de rutas/subrutas hoy
// `origin`: dónde vive hoy · `parentNow`: grupo/padre actual en el sidebar
export const MODULES = [
  // -- Panel --
  { key: "dashboard", label: "Panel / Dashboard", dest: "/app/dashboard", routes: 3, origin: ["Sidebar", "router"], parentNow: "raíz", perm: "(todos)", plan: null,
    domain: "panel", place: "rail", freq: "alta", basis: "posición actual (primer ítem), rol habitual (todos), destino de arranque" },

  // -- Ventas --
  { key: "sales", label: "Ventas", dest: "/app/sales", routes: 4, origin: ["Sidebar", "router"], parentNow: "sales", perm: "Sales_view / Sales_add", plan: null,
    domain: "ventas", place: "rail", freq: "alta", basis: "importancia operacional máxima, transversal, uso diario del perfil dueño/admin y cajero" },
  { key: "sale_return", label: "Devoluciones de venta", dest: "/app/sale_return", routes: 1, origin: ["Sidebar", "router"], parentNow: "sale_return (top-level suelto)", perm: "Sale_Returns_view", plan: null,
    domain: "ventas", place: "panel", freq: "media", basis: "sub-flujo de ventas; hoy es un ítem suelto de nivel superior, debería colgar de Ventas" },
  { key: "quotations", label: "Cotizaciones", dest: "/app/quotations", routes: 2, origin: ["Sidebar", "router"], parentNow: "quotations", perm: "Quotations_view", plan: "quotations",
    domain: "ventas", place: "panel", freq: "media", basis: "paso previo a la venta; condicional por plan; no todos los tenants cotizan" },
  { key: "promotions", label: "Promociones", dest: "/app/promotions", routes: 4, origin: ["Sidebar", "router"], parentNow: "sales (suelto)", perm: "promotion", plan: "promotions",
    domain: "ventas", place: "panel", freq: "baja", basis: "configuración comercial esporádica, condicional por plan" },
  { key: "rtsc", label: "Contador de ventas en tiempo real", dest: "/app/real-time-sales-counter", routes: 1, origin: ["Sidebar", "router"], parentNow: "sales", perm: "real_time_sales_counter", plan: null,
    domain: "ventas", place: "panel", freq: "baja", basis: "pantalla de monitoreo puntual; 1 ruta; se abre en momentos concretos" },
  { key: "customers", label: "Clientes", dest: "/app/People (Customers_*)", routes: 4, origin: ["Sidebar (People)", "nav-v3 (people)"], parentNow: "People (fusionado con proveedores)", perm: "Customers_view", plan: null,
    domain: "ventas", place: "panel", freq: "alta", basis: "D2 (decisión B0): Clientes pertenece a Ventas. Se separa de «People»; eje del flujo de ventas y cartera" },

  // -- Compras --
  { key: "purchases", label: "Compras", dest: "/app/purchases", routes: 3, origin: ["Sidebar", "router"], parentNow: "purchases", perm: "Purchases_view / Purchases_add", plan: null,
    domain: "compras", place: "rail", freq: "alta", basis: "importancia operacional, uso frecuente en retail/distribución, transversal" },
  { key: "purchase_return", label: "Devoluciones de compra", dest: "/app/purchase_return", routes: 1, origin: ["Sidebar", "router"], parentNow: "purchase_return (suelto)", perm: "Purchase_Returns_view", plan: null,
    domain: "compras", place: "panel", freq: "baja", basis: "sub-flujo poco frecuente; hoy ítem suelto de nivel superior" },
  { key: "suppliers", label: "Proveedores", dest: "/app/People (Suppliers_*)", routes: 4, origin: ["Sidebar (People)"], parentNow: "People (fusionado con clientes)", perm: "Suppliers_view", plan: null,
    domain: "compras", place: "panel", freq: "media", basis: "D2 (decisión B0): Proveedores pertenece a Compras. Se separa de «People»; eje del flujo de compras" },

  // -- Inventario --
  { key: "products", label: "Productos / catálogo", dest: "/app/products", routes: 12, origin: ["Sidebar", "router"], parentNow: "products", perm: "products_view (+9)", plan: null,
    domain: "inventario", place: "panel", freq: "alta", basis: "D3 (decisión B0): «Catálogo» es un grupo contextual dentro del dominio Inventario, no un módulo de riel. 12 rutas (alta/baja, import, categorías, marcas, unidades, lotes, códigos)" },
  { key: "inventory_arch", label: "Existencias y ubicaciones", dest: "/app/inventory/* · /app/operations/stock-intake", routes: 4, origin: ["runtime-router (main.js)"], parentNow: "no está en el sidebar real", perm: "(inventory location perms)", plan: null,
    domain: "inventario", place: "panel", freq: "alta", basis: "D3/D4 (decisión B0): la operación cotidiana de stock/ubicaciones vive en Inventario. Diferenciador de PRODEX (multi-ubicación, fases 1–3); hoy semi-oculto, sólo vía router.addRoutes" },
  { key: "adjustments", label: "Ajustes de inventario", dest: "/app/adjustments", routes: 2, origin: ["Sidebar", "router"], parentNow: "adjustments", perm: "adjustment_view / adjustment_add", plan: null,
    domain: "inventario", place: "panel", freq: "media", basis: "operación de stock recurrente pero no diaria; 2 rutas" },
  { key: "transfers", label: "Traslados", dest: "/app/transfers", routes: 2, origin: ["Sidebar", "router"], parentNow: "transfers", perm: "transfer_view / transfer_add", plan: "transfers",
    domain: "inventario", place: "panel", freq: "media", basis: "clave en multi-sucursal/CD (logística, idempotencia); condicional por plan" },
  { key: "damages", label: "Daños", dest: "/app/damages", routes: 2, origin: ["Sidebar", "router"], parentNow: "damages", perm: "damage_view", plan: null,
    domain: "inventario", place: "panel", freq: "baja", basis: "registro esporádico; 2 rutas" },
  { key: "serials", label: "Números de serie", dest: "/app/serial_numbers", routes: 1, origin: ["Sidebar", "router"], parentNow: "products (submenú)", perm: "serial_numbers", plan: null,
    domain: "inventario", place: "panel", freq: "baja", basis: "sólo aplica a ciertos rubros; 1 ruta; ya es submenú de productos" },

  // -- Finanzas --
  { key: "accounting", label: "Contabilidad (v2)", dest: "/app/accounting-v2", routes: 7, origin: ["Sidebar", "router"], parentNow: "accounting", perm: "accounting_dashboard (+12)", plan: "accounting",
    domain: "finanzas", place: "rail", freq: "alta", basis: "nº de rutas (7: catálogo de cuentas, diario, balanza, ER, balance, impuestos), rol contador, condicional por plan" },
  { key: "expenses", label: "Gastos", dest: "/app/expenses", routes: 3, origin: ["Sidebar", "router"], parentNow: "accounting (grupo)", perm: "expense_view / expense_add", plan: "accounting",
    domain: "finanzas", place: "panel", freq: "media", basis: "registro frecuente pero acotado; parte del dominio finanzas" },
  { key: "deposits", label: "Depósitos", dest: "/app/deposits", routes: 3, origin: ["Sidebar", "router"], parentNow: "accounting (grupo)", perm: "deposit_view / deposit_add", plan: "accounting",
    domain: "finanzas", place: "panel", freq: "media", basis: "registro frecuente pero acotado; parte del dominio finanzas" },
  { key: "accounts", label: "Cuentas", dest: "/app/accounts", routes: 1, origin: ["Sidebar", "router"], parentNow: "accounting (grupo)", perm: "account", plan: "accounting",
    domain: "finanzas", place: "panel", freq: "baja", basis: "catálogo de configuración; 1 ruta" },
  { key: "transfer_money", label: "Transferencia de dinero", dest: "/app/transfer_money", routes: 1, origin: ["Sidebar", "router"], parentNow: "accounting (grupo)", perm: "transfer_money", plan: "accounting",
    domain: "finanzas", place: "panel", freq: "baja", basis: "operación puntual entre cuentas; 1 ruta" },
  { key: "commissions", label: "Comisiones", dest: "/app/commissions", routes: 5, origin: ["Sidebar", "router"], parentNow: "commissions", perm: "commissions_view", plan: "commissions",
    domain: "finanzas", place: "panel", freq: "baja", basis: "programas + agentes + recibos + reportes (5 rutas) pero uso mensual; condicional por plan" },
  { key: "sar_fiscal", label: "Cumplimiento fiscal (por país)", dest: "/app/settings/sar_fiscal", routes: 1, origin: ["Sidebar (submenú de products!)", "router (settings)"], parentNow: "products (mal ubicado)", perm: "setting_system", plan: null,
    domain: "finanzas", place: "panel", freq: "baja", basis: "D5 (decisión B0): dominio Finanzas → «Cumplimiento fiscal». Label y contenido dependen del país; SAR es únicamente la implementación Honduras. Hoy cuelga (mal) de Productos" },
  { key: "subscription_product", label: "Producto de suscripción", dest: "/app/subscription_product", routes: 1, origin: ["Sidebar", "router"], parentNow: "subscription_product (suelto)", perm: "subscription_product", plan: null,
    domain: "finanzas", place: "mas", freq: "baja", basis: "modelo de venta recurrente que pocos tenants usan; 1 ruta; hoy suelto en nivel superior" },

  // -- RR. HH. --
  { key: "hrm", label: "Gestión de personal (HRM)", dest: "/app/hrm", routes: 10, origin: ["Sidebar", "router"], parentNow: "hrm", perm: "view_employee (+8)", plan: "hrm",
    domain: "rrhh", place: "rail", freq: "media", basis: "D6 (decisión B0): RR. HH. permanece en el riel compacto CUANDO el plan/permiso lo habilite. 10 rutas; uso periódico (planilla/asistencia)" },
  { key: "recruit", label: "Reclutamiento", dest: "/app/recruit", routes: 7, origin: ["Sidebar", "router"], parentNow: "recruit", perm: "recruit_job (+4)", plan: "recruitment",
    domain: "rrhh", place: "panel", freq: "baja", basis: "7 rutas pero uso esporádico (sólo al contratar); condicional por plan; panel de RR. HH." },
  { key: "meeting", label: "Reuniones", dest: "/app/meeting", routes: 4, origin: ["Sidebar", "router"], parentNow: "meeting", perm: "meeting", plan: "meetings",
    domain: "rrhh", place: "mas", freq: "baja", basis: "colaboración de equipo más que RR. HH.; condicional por plan; poco frecuente" },

  // -- Reportes --
  { key: "reports", label: "Reportes — índice «Todos los reportes»", dest: "/app/reports", routes: 40, origin: ["Sidebar", "router"], parentNow: "reports", perm: "(≈45 permisos de reporte)", plan: null,
    domain: "reportes", place: "rail", freq: "media", basis: "D1 (decisión B0): los reportes se DISTRIBUYEN en el panel de su dominio (ventas→Ventas, stock→Inventario, finanzas→Finanzas). El módulo de riel «Reportes» queda como ÍNDICE GLOBAL «Todos los reportes». Requiere un pase de mapeo de los ~45 permisos de reporte a dominios (fuera de B0)" },
  { key: "ai_reports", label: "Reportes con IA", dest: "/app/reports/ai_reports", routes: 2, origin: ["Sidebar", "router"], parentNow: "ai_reports (suelto) + dentro de reports", perm: "AI_Reports", plan: "ai_reports",
    domain: "reportes", place: "panel", freq: "baja", basis: "D1/D7: función nueva, condicional por plan; vive en el índice global de Reportes o en el panel del dominio analizado" },

  // -- Tienda en línea --
  { key: "store", label: "Tienda en línea", dest: "/app/Store", routes: 8, origin: ["Sidebar", "router"], parentNow: "Store", perm: "Store_settings_view (+5)", plan: "online_orders",
    domain: "tienda", place: "rail?", freq: "media", basis: "8 rutas (pedidos, colecciones, banners, suscriptores, mensajes, códigos); dominio real SÓLO si el tenant vende en línea; condicional por plan → rail extendido" },
  { key: "realestate", label: "Bienes raíces", dest: "/app/realestate", routes: 3, origin: ["Sidebar", "router"], parentNow: "realestate", perm: "realestate_properties", plan: null,
    domain: "gestion", place: "mas", freq: "baja", basis: "rubro muy específico; 3 rutas; la mayoría de tenants no lo usa" },

  // -- Marketing --
  { key: "marketing", label: "Marketing", dest: "/app/marketing", routes: 8, origin: ["Sidebar", "router"], parentNow: "marketing", perm: "marketing_dashboard (+5)", plan: "marketing",
    domain: "marketing", place: "rail?", freq: "baja", basis: "8 rutas pero uso por campaña; condicional por plan → rail extendido sólo si activo" },
  { key: "whatsapp", label: "WhatsApp", dest: "/app/whatsapp", routes: 4, origin: ["VSidebar", "router"], parentNow: "marketing / suelto", perm: "(whatsapp perms)", plan: null,
    domain: "marketing", place: "panel", freq: "baja", basis: "canal de mensajería; panel de Marketing; 4 rutas (ajustes, plantillas, logs)" },

  // -- Organización --
  { key: "organization", label: "Sucursales y organización", dest: "/app/organization/*", routes: 3, origin: ["runtime-router (main.js)"], parentNow: "no está en el sidebar real", perm: "(org perms)", plan: null,
    domain: "organizacion", place: "config", freq: "baja", basis: "D4 (decisión B0): NO pertenece al riel compacto. Dominio administrativo estructural (sucursales, acceso de empleados, plantillas de rol) accesible desde Configuración/Más; aparece en el riel EXTENDIDO cuando la estructura multi-sucursal del tenant lo justifica. La operación cotidiana de stock/ubicaciones permanece en Inventario" },
  { key: "user_management", label: "Usuarios y acceso", dest: "/app/User_Management", routes: 2, origin: ["Sidebar", "router"], parentNow: "User_Management", perm: "users_view / permissions_view", plan: null,
    domain: "organizacion", place: "config", freq: "baja", basis: "administración de accesos; 2 rutas; setup, no operación" },

  // -- Gestión (add-ons) --
  { key: "projects", label: "Proyectos", dest: "/app/projects", routes: 1, origin: ["Sidebar", "router"], parentNow: "projects", perm: "projects", plan: "projects",
    domain: "gestion", place: "mas", freq: "baja", basis: "add-on condicional por plan; 1 ruta; no es core retail/ERP" },
  { key: "tasks", label: "Tareas", dest: "/app/tasks", routes: 1, origin: ["Sidebar", "router"], parentNow: "tasks", perm: "tasks", plan: "projects",
    domain: "gestion", place: "mas", freq: "baja", basis: "add-on condicional por plan; 1 ruta" },
  { key: "contracts", label: "Contratos", dest: "/app/contracts", routes: 1, origin: ["Sidebar", "router"], parentNow: "contracts", perm: "contracts", plan: "contracts",
    domain: "gestion", place: "mas", freq: "baja", basis: "add-on condicional por plan; 1 ruta" },
  { key: "service", label: "Servicio y mantenimiento", dest: "/app/service", routes: 5, origin: ["Sidebar", "router"], parentNow: "service", perm: "service_jobs", plan: "service_maintenance",
    domain: "gestion", place: "mas", freq: "baja", basis: "5 rutas pero rubro específico (talleres); condicional por plan" },
  { key: "assets", label: "Activos", dest: "/app/assets", routes: 4, origin: ["Sidebar", "router"], parentNow: "assets", perm: "assets", plan: "assets",
    domain: "gestion", place: "mas", freq: "baja", basis: "4 rutas (verificación, vencimientos); uso mensual; condicional por plan" },
  { key: "bookings", label: "Reservas", dest: "/app/bookings", routes: 2, origin: ["Sidebar", "router"], parentNow: "bookings", perm: "bookings", plan: "bookings",
    domain: "gestion", place: "mas", freq: "baja", basis: "add-on condicional por plan; 2 rutas" },
  { key: "kitchen", label: "Pantalla de cocina", dest: "/app/kitchen-display", routes: 1, origin: ["VSidebar", "router"], parentNow: "suelto", perm: "(pos perms)", plan: "pos",
    domain: "pos", place: "pos", freq: "baja", basis: "pantalla auxiliar del POS (restaurantes); NO se rediseña; acceso especial" },

  // -- Configuración e integraciones --
  { key: "settings", label: "Configuración del sistema", dest: "/app/settings", routes: 22, origin: ["Sidebar", "router"], parentNow: "settings", perm: "setting_system (+18)", plan: null,
    domain: "config", place: "config", freq: "baja", basis: "22 rutas de parámetros/plantillas/pasarelas; setup puntual; icono de engranaje al pie del rail / topbar" },
  { key: "woocommerce", label: "WooCommerce", dest: "/app/woocommerce", routes: 1, origin: ["Sidebar", "router"], parentNow: "woocommerce_settings", perm: "woocommerce_settings", plan: "woocommerce",
    domain: "config", place: "config", freq: "baja", basis: "integración; condicional por plan; vive en Configuración" },
  { key: "shopify", label: "Shopify", dest: "/app/shopify", routes: 1, origin: ["Sidebar", "router"], parentNow: "shopify_settings", perm: "shopify_settings", plan: "shopify",
    domain: "config", place: "config", freq: "baja", basis: "integración; condicional por plan; vive en Configuración" },

  // -- Cuenta PRODEX --
  { key: "billing", label: "Plan y facturación", dest: "/app/billing", routes: 3, origin: ["Sidebar", "router"], parentNow: "billing", perm: "billing_view", plan: null,
    domain: "cuenta", place: "cuenta", freq: "baja", basis: "relación con PRODEX, no con el negocio del tenant; menú de usuario / zona de cuenta" },
  { key: "support", label: "Soporte", dest: "/app/support", routes: 1, origin: ["Sidebar", "router"], parentNow: "support", perm: "support", plan: null,
    domain: "cuenta", place: "cuenta", freq: "baja", basis: "canal con el equipo PRODEX; 1 ruta; menú de usuario / topbar" },
  { key: "knowledge_base", label: "Base de conocimientos", dest: "/app/knowledge-base", routes: 1, origin: ["Sidebar", "router"], parentNow: "knowledge-base", perm: "knowledge_base_view", plan: "knowledge_base",
    domain: "cuenta", place: "cuenta", freq: "baja", basis: "documentación/ayuda; 1 ruta; menú de ayuda del topbar" },

  // -- POS (FUERA DEL REDISEÑO) --
  { key: "pos", label: "Punto de venta", dest: "/app/pos", routes: 1, origin: ["Sidebar (submenú de sales)", "router"], parentNow: "sales (submenú)", perm: "Pos_view", plan: "pos",
    domain: "pos", place: "pos", freq: "alta", basis: "D8 (decisión B0): contexto SEPARADO. Acceso: botón «Abrir POS» en el topbar → cambio de contexto a POS pantalla completa. No se implementa POS en esta fase; su navegación interna, rutas y comportamiento no se tocan" },
  { key: "customer_display", label: "Customer Display", dest: "/app/customer-display", routes: 1, origin: ["Sidebar (submenú de sales)", "router"], parentNow: "sales (submenú)", perm: "customer_display_screen_setup", plan: null,
    domain: "pos", place: "pos", freq: "baja", basis: "pantalla secundaria del POS; NO se rediseña; acceso especial junto al POS" }
];

// ---- 3. Riel COMPACTO — BASE PREDETERMINADA APROBADA (B0) ------------------ --
// 7 módulos + Configuración/Más al pie. Un módulo sólo aparece si el plan y el
// permiso del usuario lo habilitan (D6): p. ej. RR. HH. desaparece sin plan `hrm`.
export const RAIL_COMPACT = [
  { key: "panel",      label: "Panel",        icon: "layout-dashboard", always: true },
  { key: "ventas",     label: "Ventas",       icon: "receipt",          always: true },
  { key: "inventario", label: "Inventario",   icon: "boxes",            always: true },
  { key: "compras",    label: "Compras",      icon: "shopping-cart",    always: true },
  { key: "finanzas",   label: "Finanzas",     icon: "calculator",       cond: "plan accounting" },
  { key: "reportes",   label: "Reportes",     icon: "bar-chart-3",      cond: "≥1 permiso de reporte" },
  { key: "rrhh",       label: "RR. HH.",      icon: "id-card",          cond: "plan hrm + permiso" }
  // + engranaje "Configuración" y "Más" fijados al pie (no cuentan como módulos)
];

// ---- 4. Riel EXTENDIDO — CONDICIONAL a módulos/estructura, NO manual (B0) -- --
// No es una preferencia estética que se activa a mano. Aparece automáticamente
// cuando el tenant tiene habilitados/estructurados esos dominios.
export const RAIL_EXTENDED = [
  ...RAIL_COMPACT,
  { key: "tienda",       label: "Tienda en línea", icon: "store",      cond: "plan online_orders activo" },
  { key: "marketing",    label: "Marketing",       icon: "megaphone",  cond: "plan marketing activo" },
  { key: "organizacion", label: "Organización",    icon: "building-2", cond: "estructura multi-sucursal / multi-almacén (D4)" }
];

// ---- 4b. Requisito futuro del riel (B0) ----------------------------------- ---
// A verificar en la implementación de navegación real (fase posterior).
export const RAIL_REQUIREMENTS = [
  "Cada icono lleva TOOLTIP en hover Y en focus (no sólo hover).",
  "Cada icono lleva aria-label con el nombre del módulo.",
  "Estado activo INEQUÍVOCO: no basta el color; marca de posición (barra lateral) + fondo + peso, y aria-current=\"page\".",
  "Orden estable entre sesiones; los módulos condicionales que aparecen/desaparecen no reordenan los fijos.",
  "El riel es navegable por teclado (flechas / Tab) con foco visible."
];

// ---- 5. Paneles contextuales de los 4 dominios complejos ------------------- --
export const CONTEXT_PANELS = {
  ventas: {
    label: "Ventas",
    groups: [
      { title: "Operar", items: [
        { label: "Nueva venta", icon: "plus" },
        { label: "Ventas", icon: "list", count: 45213 },
        { label: "Devoluciones", icon: "corner-up-left" },
        { label: "Cotizaciones", icon: "file-text", cond: "plan quotations" }
      ]},
      { title: "Cartera", items: [
        { label: "Clientes", icon: "users" },
        { label: "Pagos pendientes", icon: "coins" },
        { label: "Contador en tiempo real", icon: "activity" }
      ]},
      { title: "Configurar", items: [
        { label: "Promociones", icon: "tag", cond: "plan promotions" },
        { label: "Recibo del POS", icon: "receipt-text" }
      ]}
    ],
    reportsInline: ["Ventas por sucursal", "Top clientes", "Descuentos", "Devoluciones (ratio)"]
  },
  inventario: {
    label: "Inventario",
    groups: [
      { title: "Existencias", items: [
        { label: "Existencias por ubicación", icon: "layers" },
        { label: "Faltantes / diferencias", icon: "shield" },
        { label: "Conteo de stock", icon: "check-check" }
      ]},
      { title: "Movimientos", items: [
        { label: "Recepción de stock", icon: "package-open" },
        { label: "Ajustes", icon: "settings-2" },
        { label: "Traslados", icon: "arrow-right-left", cond: "plan transfers" },
        { label: "Daños", icon: "shield" }
      ]},
      { title: "Catálogo", items: [
        { label: "Productos", icon: "package" },
        { label: "Categorías / marcas / unidades", icon: "copy" },
        { label: "Lotes y vencimientos", icon: "calendar-clock" },
        { label: "Códigos de barra", icon: "scan-barcode" },
        { label: "Números de serie", icon: "hash" },
        { label: "Importar / actualizar", icon: "download" }
      ]}
    ],
    reportsInline: ["Kardex valorizado", "Rotación", "Antigüedad de stock", "Stock negativo", "Por vencer"]
  },
  compras: {
    label: "Compras",
    groups: [
      { title: "Operar", items: [
        { label: "Nueva orden de compra", icon: "plus" },
        { label: "Compras", icon: "list" },
        { label: "Devoluciones a proveedor", icon: "corner-up-right" },
        { label: "Importar compras", icon: "download" }
      ]},
      { title: "Abastecimiento", items: [
        { label: "Proveedores", icon: "truck" },
        { label: "Pagos a proveedores", icon: "coins" }
      ]}
    ],
    reportsInline: ["Compras por proveedor", "Top proveedores", "Pagos de compra", "Alertas de cantidad"]
  },
  finanzas: {
    label: "Contabilidad y finanzas",
    groups: [
      { title: "Contabilidad", items: [
        { label: "Panel contable", icon: "trending-up" },
        { label: "Catálogo de cuentas", icon: "database" },
        { label: "Asientos de diario", icon: "files" },
        { label: "Balanza / ER / Balance", icon: "bar-chart-3" },
        { label: "Reportes fiscales", icon: "file-text" }
      ]},
      { title: "Tesorería", items: [
        { label: "Gastos", icon: "receipt" },
        { label: "Depósitos", icon: "landmark" },
        { label: "Cuentas", icon: "wallet" },
        { label: "Transferencia de dinero", icon: "arrow-right-left" }
      ]},
      { title: "Comercial", items: [
        { label: "Comisiones", icon: "percent", cond: "plan commissions" }
      ]},
      { title: "Cumplimiento fiscal", items: [
        { label: "Cumplimiento fiscal", icon: "file-text", cond: "impl. HN: SAR" }
      ]}
    ],
    reportsInline: ["Flujo de caja", "Estado de resultados", "Balance general", "Resumen de impuestos"]
  }
};

// ---- 6. Tratamiento del POS --------------------------------------------------
export const POS_TREATMENT = {
  question: "¿Desde dónde accede un usuario administrativo al POS?",
  today: "Ventas → (submenú) → «Punto de venta» (router-link a /app/pos, línea 1326 de Sidebar.vue), junto a «Customer Display» y «Contador en tiempo real».",
  proposal: [
    "El POS es un MODO a pantalla completa, no una página del shell. No entra al riel ni a un panel contextual.",
    "Acceso: botón dedicado «Abrir POS» en el topbar (visualmente distinto — es un cambio de contexto), y opcionalmente un ítem al pie del riel marcado como «abre en pantalla completa».",
    "Customer Display y Pantalla de cocina son pantallas auxiliares del POS: se acceden desde la configuración del POS o desde el propio POS, no desde el shell administrativo.",
    "NO se rediseña su navegación interna. NO se tocan sus rutas, componentes ni comportamiento. El rediseño del POS es una fase independiente."
  ]
};

// ---- 7. Módulos condicionales por plan/permiso — REGLAS APROBADAS (B0) ---- ---
export const CONDITIONAL_TREATMENT = {
  principle: "El riel nunca muestra un módulo que el plan o el permiso del usuario no habilita. Se decide en render, no con CSS. El riel extendido es CONDICIONAL a módulos/estructura, no una preferencia manual.",
  rules: [
    { case: "Módulo core del riel compacto sin plan (p. ej. Finanzas sin plan `accounting`, RR. HH. sin plan `hrm`)", action: "No aparece en el riel; el riel compacto puede quedar en 4–5 ítems para un plan básico. Los fijos (Panel, Ventas, Inventario, Compras) no reordenan." },
    { case: "Dominio del riel extendido (Tienda / Marketing / Organización)", action: "Aparece automáticamente al pie del riel cuando su plan está activo (Tienda, Marketing) o cuando la estructura multi-sucursal lo justifica (Organización, D4). No hay conmutador manual." },
    { case: "Add-ons de «Gestión» (proyectos, tareas, contratos, servicio, activos, reservas, bienes raíces, suscripción, reuniones)", action: "Zona «Más herramientas» al pie del riel. Sólo los habilitados. Nunca al riel principal." },
    { case: "Integraciones (Woo / Shopify / QuickBooks / ZATCA / WhatsApp)", action: "Configuración → Integraciones. Nunca son módulos de riel." },
    { case: "Permiso parcial (usuario ve 2 de 22 rutas de Configuración)", action: "El módulo aparece; el panel contextual sólo lista lo permitido; si queda vacío, el módulo no aparece." }
  ]
};

// ---- 8. Decisiones B0 — RESUELTAS (definitivas) -------------------------- ----
export const DECISIONS = [
  { id: "D1", title: "Reportes", decision:
    "DISTRIBUIDOS por dominio (ventas→Ventas, stock→Inventario, finanzas→Finanzas) + un ÍNDICE GLOBAL «Todos los reportes» como módulo de riel. Requiere, fuera de B0, un pase de mapeo de los ~45 permisos de reporte a dominios." },
  { id: "D2", title: "Clientes / Proveedores", decision:
    "SEPARADOS. Clientes pertenece al dominio Ventas; Proveedores al dominio Compras. Se disuelve «People»." },
  { id: "D3", title: "Catálogo / Existencias", decision:
    "UN ÚNICO dominio Inventario. «Catálogo» es un grupo contextual dentro de Inventario, no un módulo de riel." },
  { id: "D4", title: "Organización", decision:
    "NO pertenece al riel compacto. Dominio administrativo estructural accesible desde Configuración/Más; aparece en el riel EXTENDIDO cuando la estructura multi-sucursal del tenant lo justifica. La operación cotidiana de stock/ubicaciones permanece en Inventario." },
  { id: "D5", title: "Cumplimiento fiscal", decision:
    "Se mueve conceptualmente a Finanzas → «Cumplimiento fiscal». Label y contenido dependen del país. SAR es únicamente la implementación Honduras." },
  { id: "D6", title: "RR. HH.", decision:
    "PERMANECE en el riel compacto cuando el plan/permiso lo habilite." },
  { id: "D7", title: "Ítems sueltos de primer nivel", decision:
    "sale_return, purchase_return, promotions, subscription_product, ai_reports se RECOLOCAN en sus dominios correspondientes (paneles contextuales / «Más»)." },
  { id: "D8", title: "Fusión Ventas + Compras + POS", decision:
    "DESCARTADA definitivamente. Ventas y Compras son dominios diferentes. POS es un contexto separado — se accede con «Abrir POS» desde el topbar (cambio de contexto a pantalla completa); no se implementa en esta fase." }
];
