// -----------------------------------------------------------------------------
// px-next playground — fictitious but realistic PRODEX data.
// Central-America SMB operating on multiple branches. Nothing here is real; no
// real customer, tax id or figure. Fiscal identifiers are Honduras-shaped
// EXAMPLES of abstract concepts (tax id / fiscal authorization / fiscal
// sequence) — not global primitives of the design system.
// -----------------------------------------------------------------------------

export const MODULES = [
  { key: "dashboard",  label: "Panel",         icon: "layout-dashboard" },
  { key: "pos",        label: "Punto de venta", icon: "scan-line" },
  { key: "sales",      label: "Ventas",        icon: "receipt" },
  { key: "purchases",  label: "Compras",       icon: "shopping-cart" },
  { key: "inventory",  label: "Inventario",    icon: "boxes" },
  { key: "products",   label: "Productos",     icon: "package" },
  { key: "transfers",  label: "Traslados",     icon: "arrow-left-right" },
  { key: "customers",  label: "Clientes",      icon: "users" },
  { key: "suppliers",  label: "Proveedores",   icon: "truck" },
  { key: "accounting", label: "Contabilidad",  icon: "calculator" },
  { key: "hr",         label: "RR. HH.",       icon: "id-card" },
  { key: "reports",    label: "Reportes",      icon: "bar-chart-3" }
];

export const INVENTORY_PANEL = [
  { icon: "list", label: "Existencias", count: null },
  { icon: "layers", label: "Por ubicación", count: 6 },
  { icon: "calendar-clock", label: "Por vencer", count: 23 },
  { icon: "shield", label: "Cuarentena", count: 4 },
  { icon: "arrow-right-left", label: "Ajustes", count: null },
  { icon: "bookmark", label: "Vistas guardadas", count: 3 }
];

export const BRANCHES = [
  "Sucursal Central · Tegucigalpa",
  "Sucursal San Pedro Sula",
  "Sucursal Comayagüela",
  "CD Zona Norte · Villanueva"
];

// ---- KPIs (dashboard) -------------------------------------------------------
export const KPIS = [
  { key: "ventas_hoy",   label: "Ventas de hoy",        raw: 486230.5,  kind: "money",   delta: "8,4 %",  tone: "up",   sub: "vs. ayer" },
  { key: "ticket",       label: "Ticket promedio",       raw: 742.18,    kind: "money",   delta: "1,2 %",  tone: "down", sub: "vs. semana pasada" },
  { key: "margen",       label: "Margen bruto",          raw: 31.6,      kind: "percent", delta: "0,4 pp", tone: "up",   sub: "MTD" },
  { key: "por_cobrar",   label: "Cuentas por cobrar",    raw: 1284900,   kind: "money",   delta: "3 facturas", tone: "neutral", sub: "vencidas > 30 d" },
  { key: "sku_quiebre",  label: "SKU en quiebre",        raw: 17,        kind: "int",     delta: "+5",     tone: "down", sub: "en 2 sucursales" },
  { key: "rotacion",     label: "Rotación inventario",   raw: 4.2,       kind: "ratio",   delta: "0,3",    tone: "up",   sub: "veces / trimestre" }
];

// ---- Dense inventory table ------------------------------------------------- -
const CATS = [
  { label: "Farmacia", hue: "teal" },
  { label: "Abarrotes", hue: "moss" },
  { label: "Bebidas", hue: "indigo" },
  { label: "Ferretería", hue: "clay" },
  { label: "Cuidado personal", hue: "plum" },
  { label: "Limpieza", hue: "slate" }
];

export const PRODUCTS = [
  ["7501001","Acetaminofén 500 mg · caja 100 tab","ACE-500-100",0,1240, 38.5,  62.0, "Farmacia", "ok",       "2027-04-30"],
  ["7501002","Amoxicilina 500 mg · blíster 21 cáps","AMX-500-21",1,86,  74.0, 119.0, "Farmacia", "por_vencer","2026-09-12"],
  ["7411023","Coca-Cola 2.5 L retornable","BEB-CC-2500",2,540,  18.4,  27.0, "Bebidas", "ok",         null],
  ["7411044","Agua Azul 600 ml · paca 24","BEB-AZ-600-24",2,132,  92.0, 130.0, "Bebidas", "ok",        null],
  ["7622210","Jabón de tocador 110 g","CPS-JB-110",4,integ(0),  9.5,  16.0, "Cuidado personal","ok",  null],
  ["7501310","Harina de maíz 1 kg","ABA-HM-1000",1,integ(1),  22.0,  31.0, "Abarrotes","ok",           null],
  ["7501311","Frijol rojo seleccionado 2 lb","ABA-FR-900",1,integ(2), 34.0, 47.5, "Abarrotes","por_vencer","2026-08-30"],
  ["7391120","Cemento gris 42.5 kg","FER-CEM-425",3,integ(3), 178.0, 214.0, "Ferretería","ok",         null],
  ["7391121","Clavo de 3\" · libra","FER-CLV-3",3,integ(4),  14.0,  22.0, "Ferretería","ok",           null],
  ["7622300","Detergente en polvo 1 kg","LIM-DT-1000",5,integ(5), 41.0, 58.0, "Limpieza","ok",         null],
  ["7501400","Suero oral · sobre 27.9 g","FAR-SRO-28",0,integ(6), 6.0,  11.0, "Farmacia","cuarentena",  "2026-11-05"],
  ["7411090","Jugo de naranja 1 L","BEB-JN-1000",2,integ(7), 24.0, 36.0, "Bebidas","por_vencer",       "2026-09-02"]
].map((r, i) => ({
  id: `P-${1000 + i}`,
  barcode: r[0],
  name: r[1],
  sku: r[2],
  category: CATS[r[3]].label,
  categoryHue: CATS[r[3]].hue,
  stock: r[4],
  cost: r[5],
  price: r[6],
  branch: r[7],
  state: r[8],
  expiry: r[9]
}));

function integ(seed) {
  const x = Math.sin((seed + 3) * 91.17) * 4123.77;
  return Math.round((x - Math.floor(x)) * 900) + 12;
}

export const PRODUCT_STATE = {
  ok:         { badge: "success", icon: "check", label: "Disponible" },
  por_vencer: { badge: "warning", icon: "calendar-clock", label: "Próx. a vencer" },
  cuarentena: { badge: "danger",  icon: "shield", label: "En cuarentena" },
  quiebre:    { badge: "danger",  icon: "trending-down", label: "Quiebre" }
};

// ---- Sales / fiscal documents -------------------------------------------- ---
export const SALES = [
  { id: "S-45213", doc: "001-001-01-00045213", customer: "Farmacia La Bendición",       taxId: "08019995123456", city: "Tegucigalpa",  date: "2026-08-27T14:22:00", items: 12, total: 12450.0, state: "emitida",  method: "Crédito 30 d" },
  { id: "S-45212", doc: "001-001-01-00045212", customer: "Pulpería Doña Marta",          taxId: "05019984000112", city: "San Pedro Sula", date: "2026-08-27T13:05:00", items: 4,  total: 843.75,  state: "emitida",  method: "Efectivo" },
  { id: "S-45211", doc: "001-001-01-00045211", customer: "Distribuidora El Progreso",    taxId: "18016000559001", city: "El Progreso", date: "2026-08-27T11:40:00", items: 31, total: 58210.4, state: "emitida",  method: "Transferencia" },
  { id: "S-45210", doc: "001-001-01-00045210", customer: "Consumidor final",             taxId: null,             city: "San Pedro Sula", date: "2026-08-27T10:18:00", items: 2,  total: 96.0,    state: "anulada",  method: "Efectivo" },
  { id: "S-45209", doc: "001-001-01-00045209", customer: "Comercial Sula S. de R.L.",    taxId: "05019012345671", city: "San Pedro Sula", date: "2026-08-26T17:55:00", items: 8,  total: 7320.0,  state: "emitida",  method: "Tarjeta" },
  { id: "S-45208", doc: "borrador",             customer: "Abarrotería El Ahorro",        taxId: "06011999888777", city: "Choloma",     date: "2026-08-26T16:30:00", items: 6,  total: 2140.5,  state: "pendiente", method: "Crédito 15 d" }
];

export const SALE_STATE = {
  emitida:   { badge: "success", icon: "check", label: "Emitida" },
  anulada:   { badge: "danger",  icon: "x", label: "Anulada" },
  pendiente: { badge: "warning", icon: "clock", label: "Pendiente" }
};

// ---- Fiscal context (Honduras EXAMPLE of abstract concepts) ---------------- -
export const FISCAL_CONTEXT = {
  country: "HN",
  taxIdLabel: "RTN",                         // abstract: "identificación tributaria"
  taxId: "08019995123456",
  authorizationLabel: "CAI",                 // abstract: "autorización fiscal"
  authorization: "A1B2C3-D4E5F6-A1B2C3-D4E5F6-A1B2C3-89",
  sequenceLabel: "Rango autorizado",         // abstract: "secuencia fiscal"
  sequenceFrom: "001-001-01-00040001",
  sequenceTo: "001-001-01-00050000",
  sequenceNext: "001-001-01-00045214",
  limitDate: "2026-12-31",
  note: "Representación concreta específica de Honduras. En Guatemala, Costa Rica, etc. cambian etiquetas, formato y validación; el sistema de diseño trata estos campos como identificación tributaria / autorización fiscal / secuencia fiscal."
};

// ---- Purchases ------------------------------------------------------------- -
export const PURCHASES = [
  { id: "OC-2026-0188", supplier: "Laboratorios Vijosa", ref: "FAC-77821", date: "2026-08-25", items: 22, total: 184300.0, state: "recibida_parcial", eta: "2026-08-30" },
  { id: "OC-2026-0187", supplier: "Cervecería Hondureña", ref: "FAC-90114", date: "2026-08-24", items: 9,  total: 61240.0,  state: "recibida",        eta: null },
  { id: "OC-2026-0186", supplier: "Grupo Karim's",        ref: "—",          date: "2026-08-23", items: 14, total: 43880.5,  state: "enviada",         eta: "2026-09-02" },
  { id: "OC-2026-0185", supplier: "Distribuidora Comercial", ref: "FAC-45590", date: "2026-08-21", items: 5, total: 12960.0, state: "borrador",        eta: null }
];

export const PURCHASE_STATE = {
  borrador:         { badge: "neutral", icon: "file", label: "Borrador" },
  enviada:          { badge: "info",    icon: "send", label: "Enviada" },
  recibida_parcial: { badge: "warning", icon: "package-open", label: "Recepción parcial" },
  recibida:         { badge: "success", icon: "package-open", label: "Recibida" }
};

// ---- Accounting ---------------------------------------------------------- ---
export const ACCOUNTS = [
  { code: "1101", name: "Caja general",           type: "Activo",   debit: 128400.0, credit: 0,        balance: 128400.0 },
  { code: "1102", name: "Bancos · Cta. corriente", type: "Activo",   debit: 2450900.0, credit: 812300.0, balance: 1638600.0 },
  { code: "1201", name: "Cuentas por cobrar",     type: "Activo",   debit: 1284900.0, credit: 220000.0, balance: 1064900.0 },
  { code: "2101", name: "Proveedores locales",    type: "Pasivo",   debit: 190000.0, credit: 640500.0, balance: -450500.0 },
  { code: "4101", name: "Ventas gravadas 15 %",   type: "Ingreso",  debit: 0,        credit: 3894200.0, balance: -3894200.0 },
  { code: "5101", name: "Costo de ventas",        type: "Gasto",    debit: 2610400.0, credit: 0,        balance: 2610400.0 }
];

// ---- People / customers / suppliers for entity cells --------------------- ---
export const CUSTOMERS = [
  { name: "Farmacia La Bendición", secondary: "RTN 08019995123456 · Tegucigalpa", balance: 12450.0, tag: "Mayorista" },
  { name: "Pulpería Doña Marta",   secondary: "RTN 05019984000112 · San Pedro Sula", balance: 0, tag: "Minorista" },
  { name: "Distribuidora El Progreso", secondary: "RTN 18016000559001 · El Progreso", balance: 58210.4, tag: "Mayorista" },
  { name: "Comercial Sula S. de R.L.", secondary: "RTN 05019012345671 · San Pedro Sula", balance: 7320.0, tag: "Corporativo" }
];

export const SUPPLIERS = [
  { name: "Laboratorios Vijosa",  secondary: "El Salvador · Farmacéutico" },
  { name: "Cervecería Hondureña", secondary: "Honduras · Bebidas" },
  { name: "Grupo Karim's",        secondary: "Honduras · Consumo masivo" }
];

// ---- HR ---------------------------------------------------------------- -----
export const EMPLOYEES = [
  { name: "Betzabé Escobar",  role: "Administradora",       branch: "Central · Tegucigalpa",   status: "activo",     joined: "2021-03-01" },
  { name: "Óscar Munguía",    role: "Cajero",               branch: "San Pedro Sula",          status: "en_turno",   joined: "2023-07-15" },
  { name: "Keyla Rodríguez",  role: "Jefa de bodega",       branch: "CD Zona Norte",           status: "activo",     joined: "2022-01-10" },
  { name: "Wilmer Cárcamo",   role: "Contador",             branch: "Central · Tegucigalpa",   status: "vacaciones", joined: "2020-09-01" },
  { name: "Dania Flores",     role: "Vendedora de piso",    branch: "Comayagüela",             status: "inactivo",   joined: "2024-02-19" }
];

export const EMPLOYEE_STATE = {
  activo:     { badge: "success", icon: "check", label: "Activo" },
  en_turno:   { badge: "info",    icon: "clock", label: "En turno" },
  vacaciones: { badge: "warning", icon: "sun", label: "Vacaciones" },
  inactivo:   { badge: "neutral", icon: "minus", label: "Inactivo" }
};

// ---- Operational states (for badges section) --------------------------- -----
export const OPERATIONAL_STATES = [
  { tone: "success", icon: "check-circle", label: "Caja abierta" },
  { tone: "neutral", icon: "lock", label: "Turno cerrado" },
  { tone: "warning", icon: "alert-triangle", label: "Bloqueo operativo" },
  { tone: "info",    icon: "truck", label: "Traslado en tránsito" },
  { tone: "danger",  icon: "shield", label: "Lote en cuarentena" },
  { tone: "warning", icon: "x-circle", label: "POS sin conexión" },
  { tone: "success", icon: "cloud-check", label: "Sincronizado" },
  { tone: "info",    icon: "receipt-text", label: "Factura fiscal lista" }
];

// ---- Reports list ---------------------------------------------------- -------
export const REPORTS = [
  { name: "Ventas por sucursal", area: "Ventas", updated: "2026-08-27T14:00:00", scope: "MTD" },
  { name: "Kardex valorizado", area: "Inventario", updated: "2026-08-27T06:00:00", scope: "Ayer" },
  { name: "Antigüedad de saldos", area: "Cartera", updated: "2026-08-27T06:00:00", scope: "Al cierre" },
  { name: "Rotación de inventario", area: "Inventario", updated: "2026-08-26T06:00:00", scope: "Trimestre" },
  { name: "Estado de resultados", area: "Contabilidad", updated: "2026-08-25T22:10:00", scope: "Agosto" }
];

// ---- Payment-volume mini chart data (bars) ------------------------------ ----
export const VOLUME_BARS = [
  { label: "lun", value: 62 }, { label: "mar", value: 48 }, { label: "mié", value: 74 },
  { label: "jue", value: 91 }, { label: "vie", value: 100 }, { label: "sáb", value: 83 },
  { label: "dom", value: 29 }
];
