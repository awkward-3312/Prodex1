const spanishUiMessages = {
  AI_Reports: 'Informes con IA',
  Applications: 'Solicitudes',
  Available_Serial_Numbers: 'Números de serie disponibles',
  Billing: 'Facturación',
  Candidates: 'Candidatos',
  Cash_Drawer_Auto_Open: 'Apertura automática del cajón de efectivo',
  Cash_Drawer_Auto_Open_Help: 'Activa esta opción para abrir automáticamente el cajón de efectivo al imprimir el recibo.',
  Cash_Drawer_Printer_Name: 'Nombre de la impresora del cajón',
  Cash_Drawer_Printer_Name_Help: 'Escribe el nombre exacto de la impresora vinculada al cajón de efectivo.',
  Cash_Drawer_Settings: 'Configuración del cajón de efectivo',
  Dashboard: 'Panel',
  Import_Sales: 'Importar ventas',
  Internal_Location_Report: 'Informe de ubicaciones internas',
  Interviews: 'Entrevistas',
  Job_Categories: 'Categorías de empleo',
  Jobs: 'Vacantes',
  Layout_4_Bilingual: 'Diseño 4 - Bilingüe',
  Layout_5_Minimal: 'Diseño 5 - Minimalista',
  Leave_blank_for_default_receipt_printer: 'Déjalo vacío para utilizar la impresora de recibos predeterminada',
  Login_Activity_Report: 'Informe de actividad de inicio de sesión',
  Product_Serial_Inventory: 'Inventario de números de serie por producto',
  Promotions: 'Promociones',
  Recruit: 'Reclutamiento',
  Serial_Movement_Log: 'Historial de movimientos de números de serie',
  Serial_Numbers: 'Números de serie',
  Shopify_Settings: 'Configuración de Shopify',
  Sold_Serial_Numbers: 'Números de serie vendidos',
  System_Health: 'Salud del sistema',
  Warehouse_Locations: 'Ubicaciones del almacén',
  Warranty_Guarantee_Report: 'Informe de garantías',
  choose_cash_drawer: 'Selecciona una caja física'
};

const spanishWords = {
  add: 'agregar',
  available: 'disponibles',
  cash: 'efectivo',
  customer: 'cliente',
  drawer: 'cajón',
  edit: 'editar',
  health: 'salud',
  internal: 'internas',
  location: 'ubicación',
  locations: 'ubicaciones',
  product: 'producto',
  report: 'informe',
  settings: 'configuración',
  sold: 'vendidos',
  system: 'sistema',
  warehouse: 'almacén'
};

function humanizeKey(key, locale) {
  const normalized = String(key || '')
    .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
    .replace(/[_.-]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

  if (!normalized) return '';

  if (locale === 'es') {
    return normalized
      .split(' ')
      .map(word => spanishWords[word.toLowerCase()] || word)
      .join(' ')
      .replace(/^./, character => character.toUpperCase());
  }

  return normalized.replace(/^./, character => character.toUpperCase());
}

export function bundledUiMessages(locale) {
  return locale === 'es' ? spanishUiMessages : {};
}

export function readableMissingTranslation(locale, key) {
  const messages = bundledUiMessages(locale);
  return messages[key] || humanizeKey(key, locale);
}
