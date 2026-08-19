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
  'Edit': 'Editar',
  'Delete': 'Eliminar',
  'Download': 'Descargar',
  'Document actions': 'Acciones del documento',
  'Toggle sidebar': 'Mostrar u ocultar barra lateral',
  'Language': 'Idioma',
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
  if (dictionary[current]) {
    el.textContent = dictionary[current];
  }
}

function translateLegacyUi(root = document) {
  if (!root || !root.querySelectorAll) return;

  // Attribute-only replacements are safe because they never touch business data.
  root.querySelectorAll('[title], [aria-label]').forEach(el => {
    ['title', 'aria-label'].forEach(attr => {
      const value = el.getAttribute(attr);
      if (value && legacyAttributeTranslations[value]) {
        el.setAttribute(attr, legacyAttributeTranslations[value]);
      }
    });
  });

  // Module Settings is an old component with hard-coded English. Restrict replacements
  // to its own UI classes so product/customer/user content elsewhere is never modified.
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
    const observer = new MutationObserver(() => translateLegacyUi(document));
    observer.observe(document.documentElement, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['title', 'aria-label'],
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