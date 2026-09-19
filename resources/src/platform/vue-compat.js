// Ajustes globales de @vue/compat (Vue 3). Solo tienen efecto bajo compat; en Vue 2 el archivo no hace nada.
//  1. Configuración explícita del modo de compatibilidad.
//  2. `Vue.use` vuelve a ignorar un plugin ya instalado (Vue 2): store/index.js y store/modules/auth.js hacían dos veces
//     `Vue.use(Vuex)` y Vuex informaba "[vuex] already installed".
//  3. Los plugins híbridos (vue-sweetalert2 5.x) que publican en `Vue.config.globalProperties` se traspasan a `Vue.prototype`.
import Vue, { configureCompat } from 'vue';

// Configuración explícita de @vue/compat. MODE 2 = comportamiento de Vue 2 en todos los componentes (los que se migren
// pasan a `compatConfig: { MODE: 3 }`). NO se desactiva ni silencia ningún aviso: se necesita ver cada uno (ver
// docs/architecture/VUE3_COMPAT_SPIKE.md y `npm run test:e2e:compat-warnings`).
// INSTANCE_CHILDREN: vue-meta 2 recorre `vm.$children` de TODOS los componentes, también de `<router-view>` de vue-router 4, que
// se declara `compatConfig: { MODE: 3 }` y hace que `$children` lance "INSTANCE_CHILDREN compat has been disabled". Activarlo de
// forma global (`true`, con su aviso) deja a vue-meta funcionar sin tocar vue-router. Desaparece al migrar vue-meta.
// CUSTOM_DIR: 23 plantillas usan `<router-link v-b-tooltip>`; una directiva sobre un componente se ejecuta en el contexto de
// ese componente (RouterLink, MODE 3) y sin esta clave los hooks Vue 2 de BootstrapVue (`bind/inserted/componentUpdated`) se
// desactivan ("compat behavior is disabled") y el tooltip no funciona. Desaparece al migrar BootstrapVue.
if (String(Vue.version).startsWith('3')) configureCompat({ MODE: 2, INSTANCE_CHILDREN: true, CUSTOM_DIR: true });

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
