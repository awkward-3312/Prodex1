// Ajustes globales de @vue/compat (Vue 3). Solo tienen efecto bajo compat; en Vue 2 el archivo no hace nada.
// Configuración explícita del modo de compatibilidad para los COMPONENTES que aún no se migraron a Vue 3 puro
// (ver docs/architecture/VUE3_COMPAT_SPIKE.md y `npm run test:e2e:compat-warnings`).
import Vue, { configureCompat } from 'vue';
import { compatModeFor } from './compat/bvn-mode.js';

// Configuración explícita de @vue/compat. MODE 2 = comportamiento de Vue 2 en todos los componentes (los que se migren
// pasan a `compatConfig: { MODE: 3 }`). NO se desactiva ni silencia ningún aviso: se necesita ver cada uno.
// CUSTOM_DIR: false. Los hooks de directivas de Vue 2 (bind/inserted/componentUpdated/unbind) están DESACTIVADOS a propósito: ya no queda ningún
// consumidor. Las directivas de BootstrapVue 2 (`v-b-tooltip`, `v-b-toggle`, `v-b-popover`) se sustituyeron por las de BootstrapVueNext
// (registro local por vista), `v-append-to-body` de vue-select / vue2-daterange-picker por directivas de Vue 3 en wrappers
// (platform/directives/append-to-body.js, platform/compat/*). Ya no queda ninguna directiva ni componente de BootstrapVue 2 en la aplicación (fase 5B). Si aparece un aviso CUSTOM_DIR, es un consumidor nuevo. Ver docs/architecture/BOOTSTRAP5_BOOTSTRAPVUE_NEXT_PHASE3.md.
//
// MODE por componente: BootstrapVueNext es Vue 3 puro y se compone de componentes internos NO exportados (p. ej. BFormSelect ->
// BFormSelectPlain). `compatConfig` solo se puede fijar en los exportados (platform/bootstrap); en los internos compat aplicaría el
// contrato de Vue 2 (`v-model` -> `value`/`input`) y el <select> quedaba sin valor. `compat/bvn-mode.js` los reconoce (`__name: 'B…'`) y los
// ejecuta en MODE 3.
if (String(Vue.version).startsWith('3')) configureCompat({ MODE: compatModeFor, CUSTOM_DIR: false });
