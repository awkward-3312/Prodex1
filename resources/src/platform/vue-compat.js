// Ajustes globales de @vue/compat (Vue 3). Solo tienen efecto bajo compat; en Vue 2 el archivo no hace nada.
//  1. Configuración explícita del modo de compatibilidad.
//  2. `Vue.use` vuelve a ignorar un plugin ya instalado (Vue 2): store/index.js y store/modules/auth.js hacían dos veces
//     `Vue.use(Vuex)` y Vuex informaba "[vuex] already installed".
//  3. Los plugins híbridos (vue-sweetalert2 5.x) que publican en `Vue.config.globalProperties` se traspasan a `Vue.prototype`.
import Vue, { configureCompat } from 'vue';
import { compatModeFor } from './compat/bvn-mode.js';

// Configuración explícita de @vue/compat. MODE 2 = comportamiento de Vue 2 en todos los componentes (los que se migren
// pasan a `compatConfig: { MODE: 3 }`). NO se desactiva ni silencia ningún aviso: se necesita ver cada uno (ver
// docs/architecture/VUE3_COMPAT_SPIKE.md y `npm run test:e2e:compat-warnings`).
// CUSTOM_DIR: false. Los hooks de directivas de Vue 2 (bind/inserted/componentUpdated/unbind) están DESACTIVADOS a propósito: ya no queda ningún
// consumidor. Las directivas de BootstrapVue 2 (`v-b-tooltip`, `v-b-toggle`, `v-b-popover`) se sustituyeron por las de BootstrapVueNext
// (registro local por vista), `v-append-to-body` de vue-select / vue2-daterange-picker por directivas de Vue 3 en wrappers
// (platform/directives/append-to-body.js, platform/compat/*) y las dos directivas internas de BV2 (`v-b-visible`, `v-b-hover`) reciben hooks
// de Vue 3 (platform/compat/bootstrap-vue.js). Si aparece un aviso CUSTOM_DIR, es un consumidor nuevo. Ver docs/architecture/BOOTSTRAP5_BOOTSTRAPVUE_NEXT_PHASE3.md.
//
// MODE por componente: BootstrapVueNext es Vue 3 puro y se compone de componentes internos NO exportados (p. ej. BFormSelect ->
// BFormSelectPlain). `compatConfig` solo se puede fijar en los exportados (platform/bootstrap); en los internos compat aplicaría el
// contrato de Vue 2 (`v-model` -> `value`/`input`) y el <select> quedaba sin valor. `compat/bvn-mode.js` los reconoce (`__name: 'B…'`) y los
// ejecuta en MODE 3.
if (String(Vue.version).startsWith('3')) configureCompat({ MODE: compatModeFor, CUSTOM_DIR: false });

if (Vue && String(Vue.version).startsWith('3')) {
  const installed = new Set();
  const use = Vue.use;
  Vue.use = function useOnce(plugin, ...options) {
    if (installed.has(plugin)) return Vue;
    installed.add(plugin);
    const globalProperties = Vue.config && Vue.config.globalProperties;
    const before = new Set(globalProperties ? Object.keys(globalProperties) : []);
    const result = use.call(this, plugin, ...options);
    // Plugins de Vue 2/3 híbridos (vue-sweetalert2 5.x) detectan `Vue.config.globalProperties` y publican `$swal` ahí. Las
    // instancias creadas con `new Vue()` heredan de `Vue.prototype`, no de esa configuración: se traspasa lo que el plugin añadió.
    if (globalProperties) {
      Object.keys(globalProperties).forEach((key) => {
        if (!before.has(key) && !(key in Vue.prototype)) Vue.prototype[key] = globalProperties[key];
      });
    }
    return result;
  };
}
