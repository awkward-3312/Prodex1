const EXACT = {
  'Stocky → WooCommerce': 'PRODEX → WooCommerce',
  'WooCommerce → Stocky': 'WooCommerce → PRODEX',
  'Stop Sync': 'Detener sincronización',
  'Stopping...': 'Deteniendo...',
  'Stopping… current item will finish then the sync will stop.': 'Deteniendo… el artículo actual terminará y luego se detendrá la sincronización.',
  'Fix Uncategorized Products': 'Corregir productos sin categoría',
  'Fixing...': 'Corrigiendo...',
  'Progress:': 'Progreso:',
  'Stage:': 'Etapa:',
  'Total in WooCommerce': 'Total en WooCommerce',
  'Imported to Stocky': 'Importados a PRODEX',
  'Not Yet Imported': 'Aún no importados',
  'Sync Products from WooCommerce': 'Sincronizar productos desde WooCommerce',
  'Order import troubleshooting': 'Solución de problemas de importación de pedidos',
  'Sync Orders from WooCommerce': 'Sincronizar pedidos desde WooCommerce',
  'Pulling orders...': 'Importando pedidos...',
  'Auto-Link Products by SKU': 'Vincular productos automáticamente por SKU',
  'Linking...': 'Vinculando...',
  'View Unmapped Items': 'Ver artículos no vinculados',
  'Loading...': 'Cargando...',
  'Unmapped Items Report': 'Informe de artículos no vinculados',
  'Failed order line items': 'Líneas de pedidos con error',
  'No recent failures.': 'No hay errores recientes.',
  'Stocky products without WooCommerce link': 'Productos de PRODEX sin vínculo con WooCommerce',
  'Stocky variants without WooCommerce link': 'Variantes de PRODEX sin vínculo con WooCommerce',
  'Refresh': 'Actualizar',
  'Auto-Link by SKU': 'Vincular automáticamente por SKU',
  'Syncing Products (Woo → Stocky)': 'Sincronizando productos (WooCommerce → PRODEX)',
  'Connection Settings': 'Configuración de conexión',
  'WooCommerce Sync Guide': 'Guía de sincronización con WooCommerce',
  'Connected': 'Conectado',
  'Disconnected': 'Desconectado',
  'Unknown': 'Desconocido',
  'Processed': 'Procesados',
  'Created': 'Creados',
  'Updated': 'Actualizados',
  'Failed': 'Con errores',
  'Skipped': 'Omitidos',
  'Imported': 'Importados',
  'Products': 'Productos',
  'Stock': 'Inventario',
  'Categories': 'Categorías',
  'Brands': 'Marcas',
  'Customers': 'Clientes',
  'Orders': 'Pedidos',
  'View Logs': 'Ver registros',
  'Stop': 'Detener',
  'Stopping': 'Deteniendo',
  'Resetting': 'Restableciendo',
  'Syncing': 'Sincronizando',
  'Sync in progress': 'Sincronización en curso'
};

function translateText(value) {
  if (typeof value !== 'string') return value;
  const clean = value.trim();
  if (!clean) return value;
  if (EXACT[clean]) return value.replace(clean, EXACT[clean]);

  let translated = clean;
  translated = translated.replace(/^(\d+)\s+products$/i, '$1 productos');
  translated = translated.replace(/^Showing latest (\d+) of (\d+)\.?$/i, 'Mostrando los últimos $1 de $2.');
  translated = translated.replace(/^Imported to Stocky$/i, 'Importados a PRODEX');
  translated = translated.replace(/Stocky/g, 'PRODEX');
  return translated === clean ? value : value.replace(clean, translated);
}

const SELECTOR = [
  'button','th','label','legend','option','small','strong','h1','h2','h3','h4','h5','h6',
  '.modal-title','.badge','.alert','.text-muted',
  '[class*="stat-"]','[class*="progress-"]','[class*="sync-"]','[class*="guide-"]',
  '[class*="action-"]','[class*="connection-"]','[class*="counter-"]'
].join(',');

function apply(el) {
  if (!(el instanceof Element)) return;
  if (['SCRIPT','STYLE','CODE','PRE','TEXTAREA'].includes(el.tagName)) return;

  ['title','placeholder','aria-label'].forEach(attr => {
    const before = el.getAttribute(attr);
    if (!before) return;
    const after = translateText(before);
    if (after !== before) el.setAttribute(attr, after);
  });

  if (!el.matches(SELECTOR)) return;
  Array.from(el.childNodes).forEach(node => {
    if (node.nodeType !== Node.TEXT_NODE) return;
    const before = node.nodeValue;
    const after = translateText(before);
    if (after !== before) node.nodeValue = after;
  });
}

function scan(root) {
  if (!root) return;
  if (root.nodeType === Node.TEXT_NODE) {
    if (root.parentElement && root.parentElement.matches(SELECTOR)) {
      const before = root.nodeValue;
      const after = translateText(before);
      if (after !== before) root.nodeValue = after;
    }
    return;
  }
  if (!(root instanceof Element)) return;
  apply(root);
  root.querySelectorAll(SELECTOR).forEach(apply);
}

export function installSpanishCommerceIntegrationGuard() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  if (window.__prodexSpanishCommerceIntegrationObserver) return;

  const start = () => {
    if (!document.body) return;
    scan(document.body);
    const observer = new MutationObserver(mutations => {
      mutations.forEach(mutation => {
        mutation.addedNodes.forEach(scan);
        if (mutation.type === 'characterData') scan(mutation.target);
        if (mutation.type === 'attributes') apply(mutation.target);
      });
    });
    observer.observe(document.body, {
      childList: true,
      subtree: true,
      characterData: true,
      attributes: true,
      attributeFilter: ['title','placeholder','aria-label']
    });
    window.__prodexSpanishCommerceIntegrationObserver = observer;
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
}
