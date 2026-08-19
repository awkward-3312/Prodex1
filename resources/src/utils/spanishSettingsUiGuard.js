const SETTINGS_TEXT = {
  'Backup destination': 'Destino de la copia de seguridad',
  'Destination': 'Destino',
  'Local only': 'Solo local',
  'Cloud (upload after local backup)': 'Nube (subir después de crear la copia local)',
  'Local backups path:': 'Ruta de copias locales:',
  'Cloud path / folder (optional)': 'Ruta o carpeta en la nube (opcional)',
  'Cloud provider': 'Proveedor de nube',
  'Select provider': 'Seleccionar proveedor',
  'S3-compatible (AWS/MinIO/etc.)': 'Compatible con S3 (AWS/MinIO/etc.)',
  'Cloud upload runs after the backup is generated locally.': 'La carga a la nube se ejecuta después de generar la copia local.',
  'Bucket': 'Bucket / contenedor',
  'Bucket name': 'Nombre del bucket',
  'Region': 'Región',
  'Access key': 'Clave de acceso',
  'Secret key': 'Clave secreta',
  'Secret key (leave blank to keep current)': 'Clave secreta (déjala vacía para conservar la actual)',
  'Endpoint (optional for MinIO)': 'Endpoint (opcional para MinIO)',
  'Path-style URLs (MinIO often requires this)': 'URL de estilo path (MinIO suele requerirlo)',
  'Enable': 'Activar',
  'Folder ID (optional)': 'ID de carpeta (opcional)',
  'Google Drive folder id': 'ID de carpeta de Google Drive',
  'Access token (optional, short-lived)': 'Token de acceso (opcional, de corta duración)',
  'Bearer token': 'Token Bearer',
  'Refresh token (recommended)': 'Token de actualización (recomendado)',
  'Refresh token': 'Token de actualización',
  'Client ID': 'ID de cliente',
  'OAuth client id': 'ID de cliente OAuth',
  'Client secret (leave blank to keep current)': 'Secreto del cliente (déjalo vacío para conservar el actual)',
  'OAuth client secret': 'Secreto del cliente OAuth',
  'Dropbox folder path (optional)': 'Ruta de carpeta de Dropbox (opcional)',
  'Access token (leave blank to keep current)': 'Token de acceso (déjalo vacío para conservar el actual)',
  'Dropbox token': 'Token de Dropbox',
  'Save backup settings': 'Guardar configuración de copias de seguridad',
  'Backup Configuration Required': 'Se requiere configurar las copias de seguridad',
  'mysqldump not found.': 'No se encontró mysqldump.',
  'Please configure DUMP_PATH in your .env file.': 'Configura DUMP_PATH en tu archivo .env.',
  'For Laragon on Windows:': 'Para Laragon en Windows:',
  'Open your .env file in the project root': 'Abre el archivo .env en la raíz del proyecto',
  'Find your MySQL version folder in': 'Busca la carpeta de tu versión de MySQL en',
  'Add this line (replace with your actual version):': 'Agrega esta línea (sustituye la versión por la que realmente utilizas):',
  'Or use forward slashes:': 'También puedes usar barras inclinadas:',
  'After updating .env, run:': 'Después de actualizar .env, ejecuta:',
  'Backup': 'Copia de seguridad',
  'Show the floating Customize button': 'Mostrar el botón flotante Personalizar',
  'When enabled, a Customize button appears at the bottom-right of every page so users can quickly change theme, layout, primary color and language.': 'Cuando está activado, aparece un botón Personalizar en la esquina inferior derecha para cambiar rápidamente el tema, el diseño, el color principal y el idioma.',
  'Customize Button': 'Botón Personalizar',
  'Login Page': 'Página de inicio de sesión',
  'Login hero title': 'Título principal del inicio de sesión',
  'Login hero subtitle': 'Subtítulo principal del inicio de sesión',
  'Login panel title': 'Título del panel de inicio de sesión',
  'Login panel subtitle': 'Subtítulo del panel de inicio de sesión',
  'Hero badge text': 'Texto de la insignia principal',
  'Hero feature 1': 'Característica principal 1',
  'Hero feature 2': 'Característica principal 2',
  'Hero feature 3': 'Característica principal 3',
  'Sign in button text': 'Texto del botón Iniciar sesión',
  'Login footer text': 'Texto del pie del inicio de sesión',
  'Secure & Reliable': 'Seguro y confiable',
  'Real-time inventory tracking': 'Control de inventario en tiempo real',
  'Multi-location POS support': 'POS para múltiples ubicaciones',
  'Advanced reporting & analytics': 'Reportes y análisis avanzados',
  'Leave empty to use app name': 'Déjalo vacío para usar el nombre de la aplicación',
  'Choose Logo': 'Seleccionar logo',
  'Max file size: 200KB': 'Tamaño máximo del archivo: 200 KB',
  'System Settings': 'Configuración del sistema',
  'Appearance Settings': 'Configuración de apariencia',
  'App Name': 'Nombre de la aplicación',
  'Page Title Suffix': 'Sufijo del título de la página',
  'Company Name': 'Nombre de la empresa',
  'Company Phone': 'Teléfono de la empresa',
  'Developed by': 'Desarrollado por',
  'Default Email': 'Correo electrónico predeterminado',
  'General Settings': 'Configuración general',
  'Login page appearance': 'Apariencia de la página de inicio de sesión',
  'Save settings': 'Guardar configuración',
  'Settings saved': 'Configuración guardada',
  'Select language': 'Seleccionar idioma',
  'Language': 'Idioma',
  'Theme': 'Tema',
  'Layout': 'Diseño',
  'Primary color': 'Color principal',
  'Dark mode': 'Modo oscuro',
  'Light mode': 'Modo claro'
};

const PLACEHOLDERS = {
  'e.g. StockyBackups/': 'Ej.: ProdexBackups/',
  'e.g. /StockyBackups': 'Ej.: /ProdexBackups',
  'e.g. us-east-1': 'Ej.: us-east-1',
  'e.g. https://minio.example.com': 'Ej.: https://minio.ejemplo.com',
  'Secure & Reliable': 'Seguro y confiable',
  'Real-time inventory tracking': 'Control de inventario en tiempo real',
  'Multi-location POS support': 'POS para múltiples ubicaciones',
  'Advanced reporting & analytics': 'Reportes y análisis avanzados',
  'Sign in': 'Iniciar sesión',
  'Leave empty to use app name': 'Déjalo vacío para usar el nombre de la aplicación'
};

const UI_SELECTOR = [
  'button','label','legend','th','caption','option','summary',
  'h1','h2','h3','h4','h5','h6',
  '.btn','.badge','.alert','.modal-title','.card-title','.card-header',
  '.form-text','.invalid-feedback','.text-muted',
  '.customize-toggle-title','.customize-toggle-hint','.settings-content-header'
].join(',');

function translate(value) {
  if (typeof value !== 'string') return value;
  const clean = value.trim();
  if (!clean) return value;
  const result = SETTINGS_TEXT[clean] || PLACEHOLDERS[clean];
  return result ? value.replace(clean, result) : value;
}

function applyToElement(el) {
  if (!(el instanceof Element)) return;
  if (['SCRIPT','STYLE','CODE','PRE','TEXTAREA'].includes(el.tagName)) return;

  ['placeholder','title','aria-label'].forEach(attr => {
    if (!el.hasAttribute(attr)) return;
    const before = el.getAttribute(attr);
    const after = translate(before);
    if (after !== before) el.setAttribute(attr, after);
  });

  if (el.matches(UI_SELECTOR)) {
    Array.from(el.childNodes).forEach(node => {
      if (node.nodeType !== Node.TEXT_NODE) return;
      const before = node.nodeValue;
      const after = translate(before);
      if (after !== before) node.nodeValue = after;
    });
  }
}

function scan(root) {
  if (!(root instanceof Element)) return;
  applyToElement(root);
  root.querySelectorAll('*').forEach(applyToElement);
}

export function installSpanishSettingsUiGuard() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  if (window.__prodexSpanishSettingsUiObserver) return;

  const start = () => {
    if (!document.body) return;
    scan(document.body);
    const observer = new MutationObserver(mutations => {
      mutations.forEach(mutation => {
        if (mutation.type === 'attributes') applyToElement(mutation.target);
        mutation.addedNodes.forEach(node => { if (node.nodeType === 1) scan(node); });
      });
    });
    observer.observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['placeholder','title','aria-label']
    });
    window.__prodexSpanishSettingsUiObserver = observer;
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
}
