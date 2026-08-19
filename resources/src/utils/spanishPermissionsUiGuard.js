const TEXT = {
  'Count Stock': 'Conteo de inventario',
  'Subscription Product': 'Producto por suscripción',
  'Opening Stock Import': 'Importar inventario inicial',
  'Pharmacy': 'Farmacia',
  'View Batches': 'Ver lotes',
  'Manage Batches': 'Gestionar lotes',
  'Write-Off Batches': 'Dar de baja lotes',
  'Override Expiry Block': 'Anular bloqueo por vencimiento',
  'Expiry Report': 'Informe de vencimientos',
  'Batch Register Report': 'Informe de registro de lotes',
  'if unchecked only welcome message will be displayed in dashboard': 'Si se desmarca, en el panel solo se mostrará el mensaje de bienvenida.',
  'Role name': 'Nombre del rol',
};

function translate(value) {
  if (typeof value !== 'string') return value;
  const clean = value.trim();
  if (!clean || !TEXT[clean]) return value;
  return value.replace(clean, TEXT[clean]);
}

function apply(el) {
  if (!(el instanceof Element)) return;
  if (['SCRIPT', 'STYLE', 'CODE', 'PRE', 'TEXTAREA'].includes(el.tagName)) return;

  ['title', 'placeholder', 'aria-label'].forEach(attr => {
    if (!el.hasAttribute(attr)) return;
    const before = el.getAttribute(attr);
    const after = translate(before);
    if (after !== before) el.setAttribute(attr, after);
  });

  if (!el.matches('span,label,button,th,h1,h2,h3,h4,h5,h6,.card-title,.tooltip-inner')) return;
  Array.from(el.childNodes).forEach(node => {
    if (node.nodeType !== Node.TEXT_NODE) return;
    const before = node.nodeValue;
    const after = translate(before);
    if (after !== before) node.nodeValue = after;
  });
}

function scan(root) {
  if (!(root instanceof Element)) return;
  apply(root);
  root.querySelectorAll('span,label,button,th,h1,h2,h3,h4,h5,h6,.card-title,.tooltip-inner,[title],[placeholder],[aria-label]').forEach(apply);
}

export function installSpanishPermissionsUiGuard() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  if (window.__prodexSpanishPermissionsObserver) return;

  const start = () => {
    if (!document.body) return;
    scan(document.body);
    const observer = new MutationObserver(mutations => {
      mutations.forEach(mutation => {
        mutation.addedNodes.forEach(node => { if (node.nodeType === 1) scan(node); });
        if (mutation.type === 'attributes') apply(mutation.target);
        if (mutation.type === 'characterData' && mutation.target.parentElement) apply(mutation.target.parentElement);
      });
    });
    observer.observe(document.body, {
      childList: true,
      subtree: true,
      characterData: true,
      attributes: true,
      attributeFilter: ['title', 'placeholder', 'aria-label']
    });
    window.__prodexSpanishPermissionsObserver = observer;
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
}
