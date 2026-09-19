// SOLO @vue/compat. `new Vue({ router })` es la API de vue-router 3 y bajo compat ya no instala nada: vue-router 4 se instala
// con `app.use(router)` sobre la aplicación real que hay detrás de la instancia raíz (no sobre el `Vue` global de compat, cuyo
// `provide` no llega a las aplicaciones creadas con `new Vue`). Debe hacerse ANTES de montar. Desaparece con `createApp(...)`.
import Vue from 'vue';

//
// Vuex 3 solo inyecta `$store` a través de `options.parent`. `<router-view>` de vue-router 4 no es un componente de Vue 2 (no
// expone `$options.parent`), así que las vistas de ruta se quedaban sin `$store` ("_modulesNamespaceMap" de undefined). Se
// publica el store también como propiedad global de la aplicación, que es lo que hace Vuex 4 (sin migrar Vuex).
export function mountWithRouter(options, router, el) {
  const { el: _ignored, ...rootOptions } = options;
  const root = new Vue(rootOptions);
  const app = root.$.appContext.app;
  if (rootOptions.store) app.config.globalProperties.$store = rootOptions.store;
  app.use(router);
  return root.$mount(el);
}
