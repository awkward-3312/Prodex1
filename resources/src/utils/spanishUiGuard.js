const EXACT = {
  'Client Portal': 'Portal del cliente',
  'Signed in': 'Sesión iniciada',
  'Profile': 'Perfil',
  'Logout': 'Cerrar sesión',
  'Home': 'Inicio',
  'Invoices': 'Facturas',
  'Invoice': 'Factura',
  'Quotations': 'Cotizaciones',
  'Quotation': 'Cotización',
  'Appointments': 'Citas',
  'Appointment': 'Cita',
  'Contracts': 'Contratos',
  'Contract': 'Contrato',
  'Payments': 'Pagos',
  'Payment': 'Pago',
  'Statement': 'Estado de cuenta',
  'Help': 'Ayuda',
  'Account': 'Cuenta',
  'Dashboard': 'Panel',
  'Welcome back': 'Bienvenido de nuevo',
  'Welcome': 'Bienvenido',
  'Email': 'Correo electrónico',
  'Password': 'Contraseña',
  'Sign in': 'Iniciar sesión',
  'Signing in...': 'Iniciando sesión...',
  'Login failed': 'No se pudo iniciar sesión',
  'Save': 'Guardar',
  'Saving...': 'Guardando...',
  'Cancel': 'Cancelar',
  'Close': 'Cerrar',
  'Delete': 'Eliminar',
  'Edit': 'Editar',
  'View': 'Ver',
  'View details': 'Ver detalles',
  'Details': 'Detalles',
  'Search': 'Buscar',
  'Search...': 'Buscar...',
  'Filter': 'Filtrar',
  'Clear': 'Limpiar',
  'Loading...': 'Cargando...',
  'Loading': 'Cargando',
  'No data': 'Sin datos',
  'No data available': 'No hay datos disponibles',
  'No results found': 'No se encontraron resultados',
  'No records found': 'No se encontraron registros',
  'Back': 'Volver',
  'Next': 'Siguiente',
  'Previous': 'Anterior',
  'Submit': 'Enviar',
  'Update': 'Actualizar',
  'Create': 'Crear',
  'Add': 'Agregar',
  'New': 'Nuevo',
  'Download': 'Descargar',
  'Print': 'Imprimir',
  'Status': 'Estado',
  'Date': 'Fecha',
  'Amount': 'Monto',
  'Total': 'Total',
  'Subtotal': 'Subtotal',
  'Balance': 'Saldo',
  'Due': 'Pendiente',
  'Paid': 'Pagado',
  'Unpaid': 'No pagado',
  'Pending': 'Pendiente',
  'Completed': 'Completado',
  'Cancelled': 'Cancelado',
  'Canceled': 'Cancelado',
  'Active': 'Activo',
  'Inactive': 'Inactivo',
  'Open': 'Abierto',
  'Closed': 'Cerrado',
  'Resolved': 'Resuelto',
  'Draft': 'Borrador',
  'Sent': 'Enviado',
  'Received': 'Recibido',
  'Approved': 'Aprobado',
  'Rejected': 'Rechazado',
  'Overdue': 'Vencido',
  'Name': 'Nombre',
  'Phone': 'Teléfono',
  'Address': 'Dirección',
  'Description': 'Descripción',
  'Reference': 'Referencia',
  'Notes': 'Notas',
  'Customer': 'Cliente',
  'Supplier': 'Proveedor',
  'Product': 'Producto',
  'Products': 'Productos',
  'Quantity': 'Cantidad',
  'Price': 'Precio',
  'Cost': 'Costo',
  'Tax': 'Impuesto',
  'Discount': 'Descuento',
  'Shipping': 'Envío',
  'Warehouse': 'Almacén',
  'User': 'Usuario',
  'Users': 'Usuarios',
  'Role': 'Rol',
  'Permissions': 'Permisos',
  'Settings': 'Configuración',
  'Reports': 'Informes',
  'Sales': 'Ventas',
  'Purchases': 'Compras',
  'Returns': 'Devoluciones',
  'Expenses': 'Gastos',
  'Today': 'Hoy',
  'This week': 'Esta semana',
  'This month': 'Este mes',
  'This year': 'Este año',
  'Required': 'Obligatorio',
  'Optional': 'Opcional',
  'Yes': 'Sí',
  'No': 'No',
  'None': 'Ninguno',
  'All': 'Todos',
  'Actions': 'Acciones',
  'Action': 'Acción',
  'Success': 'Éxito',
  'Error': 'Error',
  'Warning': 'Advertencia',
  'Information': 'Información',
  'Are you sure?': '¿Estás seguro?',
  'Confirm': 'Confirmar',
  'Select': 'Seleccionar',
  'Select an option': 'Selecciona una opción',
  'Choose': 'Seleccionar',
  'Upload': 'Subir',
  'Browse': 'Examinar',
  'Remove': 'Eliminar',
  'Current': 'Actual',
  'Start date': 'Fecha de inicio',
  'End date': 'Fecha de finalización',
  'Created at': 'Fecha de creación',
  'Updated at': 'Fecha de actualización',
  'Last updated': 'Última actualización',
  'All rights reserved': 'Todos los derechos reservados'
};

const PLACEHOLDERS = {
  'Search...': 'Buscar...',
  'Search': 'Buscar',
  'Enter name': 'Ingresa el nombre',
  'Enter email': 'Ingresa el correo electrónico',
  'Enter phone': 'Ingresa el teléfono',
  'Enter address': 'Ingresa la dirección',
  'Select...': 'Seleccionar...'
};

function translateTextNode(node) {
  if (!node || node.nodeType !== Node.TEXT_NODE) return;
  const original = node.nodeValue;
  const trimmed = String(original || '').trim();
  if (!trimmed || !EXACT[trimmed]) return;
  node.nodeValue = original.replace(trimmed, EXACT[trimmed]);
}

function translateElement(element) {
  if (!(element instanceof Element)) return;
  if (['SCRIPT', 'STYLE', 'CODE', 'PRE'].includes(element.tagName)) return;

  for (const attr of ['placeholder', 'title', 'aria-label']) {
    const value = element.getAttribute(attr);
    if (!value) continue;
    const translated = EXACT[value] || PLACEHOLDERS[value];
    if (translated) element.setAttribute(attr, translated);
  }

  Array.from(element.childNodes).forEach(child => {
    if (child.nodeType === Node.TEXT_NODE) translateTextNode(child);
  });
}

function scan(root) {
  if (!root) return;
  if (root.nodeType === Node.TEXT_NODE) {
    translateTextNode(root);
    return;
  }
  if (root instanceof Element) {
    translateElement(root);
    root.querySelectorAll('*').forEach(translateElement);
  }
}

export function installSpanishUiGuard() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  const start = () => {
    scan(document.body);
    const observer = new MutationObserver(mutations => {
      mutations.forEach(mutation => {
        mutation.addedNodes.forEach(scan);
        if (mutation.type === 'characterData') translateTextNode(mutation.target);
      });
    });
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });
    window.__prodexSpanishUiObserver = observer;
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
}
