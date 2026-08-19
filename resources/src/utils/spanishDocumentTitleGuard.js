const TITLE_PHRASES = [
  ['Appearance Settings', 'Configuración de apariencia'],
  ['System Settings', 'Configuración del sistema'],
  ['Sold Serials Report', 'Informe de seriales vendidos'],
  ['Available Serials Report', 'Informe de seriales disponibles'],
  ['Serial Inventory Report', 'Informe de inventario por serial'],
  ['Property Categories', 'Categorías de propiedades'],
  ['Customer Maintenance History', 'Historial de mantenimiento del cliente'],
  ['Invite Codes', 'Códigos de invitación'],
  ['Usage Report', 'Informe de uso'],
  ['AI Reports', 'Informes con IA'],
  ['Permissions', 'Permisos'],
  ['Promotions', 'Promociones'],
  ['Backup', 'Copia de seguridad'],
  ['Dashboard', 'Panel'],
  ['Products', 'Productos'],
  ['Product', 'Producto'],
  ['Sales', 'Ventas'],
  ['Sale', 'Venta'],
  ['Purchases', 'Compras'],
  ['Purchase', 'Compra'],
  ['Quotations', 'Cotizaciones'],
  ['Quotation', 'Cotización'],
  ['Customers', 'Clientes'],
  ['Customer', 'Cliente'],
  ['Suppliers', 'Proveedores'],
  ['Supplier', 'Proveedor'],
  ['Users', 'Usuarios'],
  ['User', 'Usuario'],
  ['Reports', 'Informes'],
  ['Settings', 'Configuración'],
  ['Expenses', 'Gastos'],
  ['Expense', 'Gasto'],
  ['Transfers', 'Transferencias'],
  ['Transfer', 'Transferencia'],
  ['Returns', 'Devoluciones'],
  ['Return', 'Devolución'],
  ['Contracts', 'Contratos'],
  ['Contract', 'Contrato'],
  ['Appointments', 'Citas'],
  ['Appointment', 'Cita'],
  ['Payments', 'Pagos'],
  ['Payment', 'Pago'],
  ['Help Center', 'Centro de ayuda'],
  ['Profile', 'Perfil'],
  ['Login', 'Inicio de sesión']
];

function translateTitle(value) {
  let result = String(value || '');
  TITLE_PHRASES.forEach(([from, to]) => {
    const escaped = from.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    result = result.replace(new RegExp(`\\b${escaped}\\b`, 'gi'), to);
  });
  result = result.replace(/Stocky(?:\s*\|\s*Ultimate Inventory With POS)?/gi, 'PRODEX');
  result = result.replace(/Ultimate Inventory With POS/gi, 'Gestión empresarial');
  return result;
}

export function installSpanishDocumentTitleGuard() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  if (window.__prodexSpanishTitleObserver) return;

  const apply = () => {
    const current = document.title || '';
    const translated = translateTitle(current);
    if (translated && translated !== current) document.title = translated;
  };

  const start = () => {
    apply();
    const titleEl = document.querySelector('title');
    if (!titleEl) return;
    const observer = new MutationObserver(apply);
    observer.observe(titleEl, { childList: true, characterData: true, subtree: true });
    window.__prodexSpanishTitleObserver = observer;
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
}
