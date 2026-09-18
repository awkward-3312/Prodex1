/**
 * Allowlist de errores JS PREEXISTENTES. Por defecto la suite falla ante cualquier `pageerror`,
 * `console.error` o respuesta HTTP >= 500 / recurso estático 404. Una entrada solo se acepta si
 * documenta POR QUÉ es preexistente y POR QUÉ no se corrige en esta fase (la fase 1 no cambia producto).
 *
 * Al corregir el error en una fase posterior, borra la entrada: la suite volverá a protegerlo.
 * `maxPerTest` acota cuántas veces puede ocurrir; si crece, es una regresión y el test falla.
 */
module.exports = [
  {
    id: 'transfer-logistics-insertBefore',
    kind: 'pageerror',
    message: /Failed to execute 'insertBefore' on 'Node': The node before which the new node is to be inserted is not a child of this node\./,
    stack: /prodex-transfer-logistics\.js/,
    maxPerTest: 300,
    why:
      'resources/static/prodex-transfer-logistics.js (función ensureHeaderButton, línea ~93) inserta un botón con ' +
      'insertBefore() sobre un nodo de referencia que Vue ya movió/reemplazó. Lo dispara un MutationObserver del script ' +
      'suelto en cada re-render de la cabecera, así que aparece en toda pantalla /app/* (≈11 veces al cargar el panel). ' +
      'Existe en f138210 (verificado en el worktree limpio). Es una carrera entre el script externo y el DOM de Vue, ' +
      'exactamente el acoplamiento descrito en la sección 17 (#8) de FRONTEND_MODERNIZATION_AUDIT.md.',
    notFixedBecause:
      'Corregirlo exige modificar el script de transferencias (comportamiento de producto), fuera del alcance de la fase 1 ' +
      '"red de seguridad, sin cambiar comportamiento". Se corregirá al sustituir los scripts sueltos (fase 4 de la auditoría).',
  },
  {
    id: 'stripe-frame-csp-report-only',
    kind: 'console',
    message: /Framing 'https:\/\/js\.stripe\.com\/' violates the following report-only Content Security Policy directive/,
    maxPerTest: 20,
    why:
      'app/Http/Middleware/SecurityHeaders.php emite una Content-Security-Policy en modo REPORT-ONLY con ' +
      "`default-src 'self'` y sin `frame-src`; el POS y ModernPaymentModal cargan Stripe.js (iframe de js.stripe.com). " +
      'Chrome lo registra con console.error, pero el navegador NO bloquea nada ("no further action has been taken"). ' +
      'Aparece en /app/pos en f138210.',
    notFixedBecause:
      'Ajustar la CSP (añadir frame-src/script-src de Stripe) es un cambio de seguridad de producto, fuera de la fase 1. ' +
      'Debe revisarlo quien defina la política CSP; hasta entonces el aviso es inocuo (report-only).',
  },
];
