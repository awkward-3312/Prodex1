const DOCUMENT_LABELS = {
  'PURCHASE ORDER': 'ORDEN DE COMPRA',
  'SUPPLIER INFO': 'INFORMACIÓN DEL PROVEEDOR',
  'COMPANY INFO': 'INFORMACIÓN DE LA EMPRESA',
  'PRODUCT': 'PRODUCTO',
  'PRODUCTS': 'PRODUCTOS',
  'COST': 'COSTO',
  'QTY': 'CANT.',
  'DISC': 'DESC.',
  'TAX': 'IMPUESTO',
  'TOTAL': 'TOTAL',
  'Date:': 'Fecha:',
  'Order #:': 'Orden #:',
  'Status:': 'Estado:',
  'Payment:': 'Pago:',
  'Phone:': 'Teléfono:',
  'Email:': 'Correo:',
  'Address:': 'Dirección:',
  'Tax #:': 'RTN/Impuesto #:',
  'Code:': 'Código:',
  'Cost:': 'Costo:',
  'Qty:': 'Cant.:',
  'Disc:': 'Desc.:',
  'Tax:': 'Impuesto:',
  'Batches': 'Lotes',
  'Batch No': 'N.º de lote',
  'Mfg Date': 'Fecha de fabricación',
  'Expiry Date': 'Fecha de vencimiento',
  'items': 'artículos',
  'Receipt preview': 'Vista previa del recibo',
  'Print demo receipt': 'Imprimir recibo de demostración',
  'Demo Store': 'Tienda de demostración',
  'Date: 2025-12-10 12:34': 'Fecha: 2025-12-10 12:34',
  'Seller: John Doe': 'Vendedor: Juan Pérez',
  'Customer: Jane Smith': 'Cliente: María López',
  'Warehouse: Main Store': 'Almacén: Principal',
  'Demo Product A': 'Producto de demostración A',
  'Demo Product B': 'Producto de demostración B',
  'Demo A': 'Demo A',
  'Demo B': 'Demo B',
  'Discount: -2.00': 'Descuento: -2.00',
  'Pay By': 'Forma de pago',
  'Amount': 'Monto',
  'Change': 'Cambio',
  'Cash': 'Efectivo',
  'Thank you for your purchase!': '¡Gracias por tu compra!',
  'Paid': 'Pagado',
  'Due': 'Pendiente',
  'Tax': 'Impuesto',
  'Discount': 'Descuento',
  'Shipping': 'Envío',
  'Item': 'Artículo',
  'Price': 'Precio',
  'Ref: REF-12345': 'Ref.: REF-12345'
};

const SAFE_UI_SELECTOR = [
  'th',
  'button',
  '[class*="-label"]',
  '[class*="-header"]',
  '[class*="-title"]',
  '[class*="-badge"]',
  '.invoice-main-title',
  '.invoice-box-header',
  '.invoice-product-code',
  '.invoice-product-card-label',
  '.invoice-product-card-detail-label',
  '.pos-receipt-demo *'
].join(',');

function translateTextNode(node) {
  if (!node || node.nodeType !== Node.TEXT_NODE) return;
  const original = String(node.nodeValue || '');
  const trimmed = original.trim();
  if (!trimmed || !DOCUMENT_LABELS[trimmed]) return;
  node.nodeValue = original.replace(trimmed, DOCUMENT_LABELS[trimmed]);
}

function translateElement(element) {
  if (!(element instanceof Element)) return;

  const title = element.getAttribute('title');
  if (title && DOCUMENT_LABELS[title]) {
    element.setAttribute('title', DOCUMENT_LABELS[title]);
  }

  if (!element.matches(SAFE_UI_SELECTOR)) return;
  Array.from(element.childNodes).forEach(translateTextNode);
}

function scan(root) {
  if (!root) return;
  if (root.nodeType === Node.TEXT_NODE) {
    const parent = root.parentElement;
    if (parent && parent.matches(SAFE_UI_SELECTOR)) translateTextNode(root);
    return;
  }
  if (!(root instanceof Element)) return;
  translateElement(root);
  root.querySelectorAll(SAFE_UI_SELECTOR).forEach(translateElement);
}

export function installSpanishLegacyDocumentGuard() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  if (window.__prodexSpanishLegacyDocumentObserver) return;

  const start = () => {
    scan(document.body);
    const observer = new MutationObserver(mutations => {
      mutations.forEach(mutation => {
        mutation.addedNodes.forEach(scan);
        if (mutation.type === 'characterData') scan(mutation.target);
        if (mutation.type === 'attributes') translateElement(mutation.target);
      });
    });
    observer.observe(document.body, {
      childList: true,
      subtree: true,
      characterData: true,
      attributes: true,
      attributeFilter: ['title']
    });
    window.__prodexSpanishLegacyDocumentObserver = observer;
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
}
