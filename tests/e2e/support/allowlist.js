/**
 * Allowlist de errores JS PREEXISTENTES. Por defecto la suite falla ante cualquier `pageerror`,
 * `console.error` o respuesta HTTP >= 500 / recurso estático 404. Una entrada solo se acepta si
 * documenta POR QUÉ es preexistente y POR QUÉ no se corrige en esta fase (la fase 1 no cambia producto).
 *
 * Al corregir el error en una fase posterior, borra la entrada: la suite volverá a protegerlo.
 * (`transfer-logistics-insertBefore` ya se corrigió y se eliminó; ver 11-header-widgets.spec.js.)
 * `maxPerTest` acota cuántas veces puede ocurrir; si crece, es una regresión y el test falla.
 */
module.exports = [
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
