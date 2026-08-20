import BootstrapVue from 'bootstrap-vue/dist/bootstrap-vue.esm';
import VueGoodTablePlugin from "vue-good-table";
import Meta from "vue-meta";
import "./../assets/styles/sass/themes/lite-purple.scss";
import "./sweetalert2.js";
import VueHtmlToPaper from 'vue-html-to-paper';

const options = {
  name: '_blank',
  specs: [
    'fullscreen=yes',
    'titlebar=yes',
    'scrollbars=yes'
  ],
  styles: [
    'https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css',
    'https://unpkg.com/kidlat-css/css/kidlat.css',
  ],
  timeout: 1000,
  autoClose: true,
  windowTitle: window.document.title,
};

const legacyAttributeTranslations = {
  'View': 'Ver',
  'Show': 'Ver',
  'Edit': 'Editar',
  'Delete': 'Eliminar',
  'Download': 'Descargar',
  'Print': 'Imprimir',
  'Close': 'Cerrar',
  'Save': 'Guardar',
  'Search': 'Buscar',
  'Filter': 'Filtrar',
  'Document actions': 'Acciones del documento',
  'Toggle sidebar': 'Mostrar u ocultar barra lateral',
  'Language': 'Idioma',
  'Search this table': 'Buscar en esta tabla',
  'Search...': 'Buscar...',
  'Select...': 'Seleccionar...',
};

const commonLegacyUiTranslations = {
  'View': 'Ver',
  'Show': 'Ver',
  'Edit': 'Editar',
  'Delete': 'Eliminar',
  'Download': 'Descargar',
  'Print': 'Imprimir',
  'Save': 'Guardar',
  'Close': 'Cerrar',
  'Cancel': 'Cancelar',
  'Confirm': 'Confirmar',
  'Submit': 'Guardar',
  'Add': 'Agregar',
  'Create': 'Crear',
  'Update': 'Actualizar',
  'Search': 'Buscar',
  'Filter': 'Filtrar',
  'Reset': 'Restablecer',
  'Refresh': 'Actualizar',
  'Actions': 'Acciones',
  'Action': 'Acción',
  'Status': 'Estado',
  'Date': 'Fecha',
  'Reference': 'Referencia',
  'Name': 'Nombre',
  'Description': 'Descripción',
  'Email': 'Correo electrónico',
  'Phone': 'Teléfono',
  'Address': 'Dirección',
  'Amount': 'Monto',
  'Total': 'Total',
  'Paid': 'Pagado',
  'Unpaid': 'Sin pagar',
  'Partial': 'Parcial',
  'Pending': 'Pendiente',
  'Completed': 'Completado',
  'Cancelled': 'Cancelado',
  'Canceled': 'Cancelado',
  'Received': 'Recibido',
  'Draft': 'Borrador',
  'Active': 'Activo',
  'Inactive': 'Inactivo',
  'Enabled': 'Habilitado',
  'Disabled': 'Deshabilitado',
  'Yes': 'Sí',
  'No': 'No',
  'All': 'Todos',
  'None': 'Ninguno',
  'Customer': 'Cliente',
  'Customers': 'Clientes',
  'Supplier': 'Proveedor',
  'Suppliers': 'Proveedores',
  'Product': 'Producto',
  'Products': 'Productos',
  'Warehouse': 'Almacén',
  'Warehouses': 'Almacenes',
  'Sale': 'Venta',
  'Sales': 'Ventas',
  'Purchase': 'Compra',
  'Purchases': 'Compras',
  'Payment': 'Pago',
  'Payments': 'Pagos',
  'Return': 'Devolución',
  'Returns': 'Devoluciones',
  'Quantity': 'Cantidad',
  'Price': 'Precio',
  'Discount': 'Descuento',
  'Tax': 'Impuesto',
  'Notes': 'Notas',
  'Details': 'Detalles',
  'Settings': 'Configuración',
  'Users': 'Usuarios',
  'Roles': 'Roles',
  'Permissions': 'Permisos',
  'Dashboard': 'Panel',
  'Reports': 'Reportes',
  'Loading...': 'Cargando...',
  'Processing...': 'Procesando...',
  'Saving...': 'Guardando...',
  'No data': 'Sin datos',
  'No data available': 'No hay datos disponibles',
  'No results found': 'No se encontraron resultados',
  'Select': 'Seleccionar',
  'Select All': 'Seleccionar todo',
  'Clear': 'Limpiar',
};

const tableUiTranslations = {
  'next': 'Siguiente',
  'prev': 'Anterior',
  'Next': 'Siguiente',
  'Previous': 'Anterior',
  'Previous page': 'Página anterior',
  'Next page': 'Página siguiente',
  'Rows per page': 'Filas por página',
  'of': 'de',
};

const moduleSettingsTranslations = {
  'Module Settings': 'Configuración de módulos',
  'Install, manage and configure modules to extend your Stocky application.': 'Instala, administra y configura módulos para ampliar las funciones de PRODEX.',
  'Installed': 'Instalados',
  'Active': 'Activos',
  'Inactive': 'Inactivos',
  'Install New Module': 'Instalar nuevo módulo',
  'Drag & drop your module .zip file here': 'Arrastra y suelta aquí el archivo .zip del módulo',
  'or click to browse files': 'o haz clic para buscar el archivo',
  'Install Module': 'Instalar módulo',
  'Installing...': 'Instalando...',
  'Installed Modules': 'Módulos instalados',
  'All': 'Todos',
  'Enabled': 'Habilitado',
  'Disabled': 'Deshabilitado',
  'No Modules Installed': 'No hay módulos instalados',
  'Upload a module zip file above to get started. Modules add new features and functionality to your Stocky application.': 'Carga un archivo ZIP de módulo para comenzar. Los módulos agregan nuevas funciones a PRODEX.',
};

const moduleDescriptions = {
  'Interactive API documentation with code examples and endpoint reference.': 'Documentación interactiva de la API con ejemplos de código y referencia de endpoints.',
  'Sync products and stock with your WooCommerce store.': 'Sincroniza productos e inventario con tu tienda WooCommerce.',
  'Full-featured online store with product catalog and checkout.': 'Tienda en línea completa con catálogo de productos y proceso de compra.',
  'Human resource management with employees, attendance, and payroll.': 'Gestión de recursos humanos con empleados, asistencia y nómina.',
  'Sales agent commission programs, rules, and tracking.': 'Programas de comisiones para agentes de ventas, reglas y seguimiento.',
  'Contract management with templates, tasks, and attachments.': 'Gestión de contratos con plantillas, tareas y archivos adjuntos.',
  'Appointment booking and service job management.': 'Reservación de citas y gestión de trabajos de servicio.',
  'Advanced reporting and business analytics.': 'Reportes avanzados y análisis del negocio.',
  'Recruitment management with jobs, candidates, applications, interviews and reports.': 'Gestión de reclutamiento con vacantes, candidatos, postulaciones, entrevistas y reportes.',
  'Extends your Stocky application with additional functionality.': 'Amplía PRODEX con funciones adicionales.',
};

function translateExactText(el, dictionary) {
  if (!el || !el.textContent) return;
  const current = el.textContent.trim();
  if (dictionary[current]) el.textContent = dictionary[current];
}

function translateLegacyUi(root = document) {
  if (!root || !root.querySelectorAll) return;

  root.querySelectorAll('[title], [aria-label], [placeholder]').forEach(el => {
    ['title', 'aria-label', 'placeholder'].forEach(attr => {
      const value = el.getAttribute(attr);
      if (value && legacyAttributeTranslations[value]) {
        el.setAttribute(attr, legacyAttributeTranslations[value]);
      }
    });
  });

  // Solo elementos de interfaz; nunca celdas normales ni contenido de negocio.
  root.querySelectorAll(
    'button, .btn, .dropdown-item, .nav-link, label, th, .modal-title, .card-title, .badge, .alert-heading, .vgt-global-search__input'
  ).forEach(el => {
    translateExactText(el, commonLegacyUiTranslations);
  });

  root.querySelectorAll('.vgt-wrap__footer *').forEach(el => {
    translateExactText(el, tableUiTranslations);
  });

  const moduleRoot = root.querySelector('.module-header-card')
    ? root
    : (root.closest && root.closest('.main-content')) || null;

  if (moduleRoot && moduleRoot.querySelector('.module-header-card')) {
    moduleRoot.querySelectorAll(
      '.module-header-title, .module-header-desc, .stat-label, .upload-card-header, .upload-text, .upload-subtext, .upload-btn, .modules-section-title, .filter-btn, .module-status-badge, .toggle-label, .empty-state-title, .empty-state-desc, .module-description'
    ).forEach(el => {
      translateExactText(el, moduleSettingsTranslations);
      translateExactText(el, moduleDescriptions);
    });
  }
}

function installSpanishLegacyUiGuard() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;

  const run = () => translateLegacyUi(document);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run, { once: true });
  } else {
    run();
  }

  if (typeof MutationObserver !== 'undefined') {
    const observer = new MutationObserver(mutations => {
      mutations.forEach(mutation => {
        // For dynamically inserted Vue components, translate only the
        // newly-added subtree instead of rescanning the entire document.
        if (mutation.type === 'childList') {
          mutation.addedNodes.forEach(node => {
            if (node.nodeType !== Node.ELEMENT_NODE) return;

            translateLegacyUi(node);

            // translateLegacyUi() searches descendants, so also process the
            // inserted root element itself when it is a translatable control.
            if (node.matches) {
              if (node.matches('[title], [aria-label], [placeholder]')) {
                ['title', 'aria-label', 'placeholder'].forEach(attr => {
                  const value = node.getAttribute(attr);
                  if (value && legacyAttributeTranslations[value]) {
                    node.setAttribute(attr, legacyAttributeTranslations[value]);
                  }
                });
              }

              if (node.matches(
                'button, .btn, .dropdown-item, .nav-link, label, th, .modal-title, .card-title, .badge, .alert-heading, .vgt-global-search__input'
              )) {
                translateExactText(node, commonLegacyUiTranslations);
              }

              if (node.matches('.vgt-wrap__footer *')) {
                translateExactText(node, tableUiTranslations);
              }
            }
          });

          return;
        }

        // Attribute changes only require checking the affected element.
        if (
          mutation.type === 'attributes' &&
          mutation.target &&
          mutation.target.nodeType === Node.ELEMENT_NODE
        ) {
          const el = mutation.target;
          const attr = mutation.attributeName;

          if (attr && ['title', 'aria-label', 'placeholder'].includes(attr)) {
            const value = el.getAttribute(attr);

            if (value && legacyAttributeTranslations[value]) {
              el.setAttribute(attr, legacyAttributeTranslations[value]);
            }
          }
        }
      });
    });

    observer.observe(document.documentElement, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['title', 'aria-label', 'placeholder'],
    });
  }
}

export default {
  install(Vue) {
    Vue.use(BootstrapVue);
    Vue.component(
      "large-sidebar",
      () => import(/* webpackChunkName: "largeSidebar" */ "../containers/layouts/largeSidebar")
    );

    Vue.component(
      "customizer",
      () => import(/* webpackChunkName: "customizer" */ "../components/common/customizer.vue")
    );
    Vue.component("vue-perfect-scrollbar", () =>
      import(/* webpackChunkName: "vue-perfect-scrollbar" */ "vue-perfect-scrollbar")
    );
    Vue.use(Meta, {
      keyName: "metaInfo",
      attribute: "data-vue-meta",
      ssrAttribute: "data-vue-meta-server-rendered",
      tagIDKeyName: "vmid",
      refreshOnceOnNavigation: true
    });
    Vue.use(VueGoodTablePlugin);
    Vue.use(VueHtmlToPaper, options);
    installSpanishLegacyUiGuard();
  }
};