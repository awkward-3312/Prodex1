// BootstrapVueNext dentro de PRODEX (migración por familias; ver docs/architecture/BOOTSTRAP5_BOOTSTRAPVUE_NEXT_PHASE1.md).
//
// Regla de uso: las vistas importan los componentes DESDE AQUÍ (nunca de `bootstrap-vue-next` directamente ni por registro global) y los
// registran localmente (`components: { BButton }`). Cada familia vive en su módulo (fase 5B) y los entrypoints pequeños (login) importan
// directamente el de la familia que usan (`@/platform/bootstrap/buttons`, `/forms`) para no depender del tree-shaking del barril.
//
// BootstrapVueNext es Vue 3 puro. Bajo @vue/compat MODE 2 hay que excluirlo de los comportamientos de Vue 2 (`v-model` value/input, class/style de
// atributos, `$listeners`…): cada componente exportado (y los que usa por dentro) se marca `compatConfig: { MODE: 3 }` (core.js `pure`). Es
// configuración del propio componente, no un parche de su lógica; desaparece al quitar compat.
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
