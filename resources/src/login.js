import './platform/vue-compat';
import { mountWithRouter } from './platform/mount';
import { head, installHead } from './platform/head';
import store from "./store";
import router, { setupRouterGuards } from "./router";
import { installValidation } from './platform/validation';

// `app` real de Vue 3, con store (Vuex 4) y router ya instalados (ver platform/mount.js).
const app = mountWithRouter({}, { router, store });

app.component(
  "large-sidebar",
  // The `import` function returns a Promise.
  () => import(/* webpackChunkName: "largeSidebar" */ "./containers/layouts/largeSidebar")
);

app.component(
  "customizer",
  // The `import` function returns a Promise.
  () => import(/* webpackChunkName: "customizer" */ "./components/common/customizer.vue")
);
app.component("vue-perfect-scrollbar", () =>
  import(/* webpackChunkName: "vue-perfect-scrollbar" */ "./components/VuePerfectScrollbar.vue")
);
installHead(app);

installValidation(app);

window.axios = require('axios');
window.axios.defaults.baseURL = '';

window.axios.defaults.withCredentials = true;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

axios.interceptors.response.use((response) => {

  return response;
}, (error) => {
    if (error.response && error.response.data) {
    if (error.response.status === 401) {
      // Full page navigation (non-SPA) back to login
      window.location.replace('/login');
    }

    if (error.response.status === 404) {
      router.push({ name: 'NotFound' });
    }
    if (error.response.status === 403) {
      router.push({ name: 'not_authorize' });
    }

    return Promise.reject(error.response.data);
  }
  return Promise.reject(error.message);
});

// Adaptador temporal: `window.Fire` ya no es una instancia de Vue sino el bus de plataforma.
window.Fire = events;

app.component('login-component', require('./views/app/sessions/signIn.vue').default);
app.component('forgot-component', require('./views/app/sessions/forgot.vue').default);
app.component('reset-component', require('./views/app/sessions/reset.vue').default);

// `Vue.config.silent/productionTip/devtools`: ajustes globales de @vue/compat, no de esta `app` (ver main.js).
import Vue from "vue";
Vue.config.productionTip = true;
Vue.config.silent = true;
Vue.config.devtools = false;

import { loadI18n } from './plugins/i18n.loader';
import { events, installVue2Platform } from './platform';
import { bootstrapPlugin } from './platform/bootstrap/plugin.js';

loadI18n().then(i18n => {
 store.commit('SetDefaultLanguage', { i18n, Language: i18n.locale });
  setupRouterGuards(i18n); // ✅ inject into router

  try { store.dispatch('config/initPrimaryColor'); } catch (e) {}

  app.use(bootstrapPlugin);
  app.use(i18n);
  const vm = app.mount('#login');
  installVue2Platform(vm);
});
