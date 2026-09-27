// Bootstrap real de Vue 3 puro (`vue`, ya no `@vue/compat`, retirado por completo — ver
// docs/architecture/VUE3_PURE_RUNTIME.md). Reemplaza `platform/compat/vue-router.js` (también retirado, era la
// emulación de `new Vue()`).
//
// Cada entrypoint recibe su PROPIA instancia de `app` (nunca comparten registro global): `app.component/
// app.directive/app.mixin/app.use/app.config.globalProperties` sustituyen a `Vue.component/Vue.directive/
// Vue.mixin/Vue.use/Vue.prototype.$x` — mismos nombres de método, así que las funciones instaladoras existentes
// (`installHead(app)`, `installDirectives(app)`, `installValidation(app)`, los plugins con `{ install(app) {...} }`
// como `stocky.kit.js`) no cambiaron de forma, solo de qué objeto reciben.
import { createApp } from 'vue';

/**
 * Crea la app y le instala el store (Vuex 4, `app.use(store)`) y el router (`app.use(router)`) si se pasan.
 * Devuelve la `app` SIN montar: el entrypoint registra sus componentes/directivas/plugins propios sobre ella
 * (con `app.xxx(...)`, nunca sobre el `Vue` global) y decide cuándo llamar a `app.mount(el)` (a veces hace falta
 * esperar una carga asíncrona, p. ej. `loadI18n()`, antes de montar).
 */
export function mountWithRouter(rootComponent, { router, store } = {}) {
  const app = createApp(rootComponent);
  if (store) app.use(store);
  if (router) app.use(router);
  return app;
}
