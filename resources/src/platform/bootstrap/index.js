// BootstrapVueNext dentro de PRODEX (migración por familias; ver docs/architecture/BOOTSTRAP5_BOOTSTRAPVUE_NEXT_PHASE1.md).
//
// Regla de uso: las vistas importan los componentes DESDE AQUÍ (nunca de `bootstrap-vue-next` directamente ni por registro global) y los
// registran localmente (`components: { BButton }`). Cada familia vive en su módulo (fase 5B) y los entrypoints pequeños (login) importan
// directamente el de la familia que usan (`@/platform/bootstrap/buttons`, `/forms`) para no depender del tree-shaking del barril.
//
// BootstrapVueNext es Vue 3 puro: se ejecuta tal cual, sin runtime de compatibilidad de por medio (ver
// core.js `pure`, que ya no marca nada — queda como identidad por compatibilidad de los puntos de uso).
export * from './layout.js';
export * from './buttons.js';
export * from './primitives.js';
export * from './forms.js';
export { BFormFile } from './file.js';
export { BFormDatepicker } from './datepicker.js';
export { BSkeletonImg } from './skeleton.js';
export * from './feedback.js';
export * from './nav.js';
export * from './table.js';
export * from './overlay.js';
export { bootstrapPlugin } from './plugin.js';
