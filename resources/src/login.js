import './platform/vue-compat';
import { patchBootstrapVueForCompat } from './platform/compat/bootstrap-vue';
import { mountWithRouter } from './platform/compat/vue-router';
import { head, installHead } from './platform/head';
import store from "./store";
import Vue from "vue";
patchBootstrapVueForCompat(Vue);
import router, { setupRouterGuards } from "./router";
import { installValidation } from './platform/validation';
import BootstrapVue from 'bootstrap-vue/dist/bootstrap-vue.esm';
Vue.use(BootstrapVue);

Vue.component(
  "large-sidebar",
  // The `import` function returns a Promise.
  () => import(/* webpackChunkName: "largeSidebar" */ "./containers/layouts/largeSidebar")
);

Vue.component(
  "customizer",
  // The `import` function returns a Promise.
  () => import(/* webpackChunkName: "customizer" */ "./components/common/customizer.vue")
);
Vue.component("vue-perfect-scrollbar", () =>
  import(/* webpackChunkName: "vue-perfect-scrollbar" */ "vue-perfect-scrollbar")
);
installHead(Vue);

installValidation(Vue);

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

Vue.component('login-component', require('./views/app/sessions/signIn.vue').default);
Vue.component('forgot-component', require('./views/app/sessions/forgot.vue').default);
Vue.component('reset-component', require('./views/app/sessions/reset.vue').default);

Vue.config.productionTip = true;
Vue.config.silent = true;
Vue.config.devtools = false;

import VueI18n from 'vue-i18n';
Vue.use(VueI18n);

import { loadI18n } from './plugins/i18n.loader';
import { events, installVue2Platform } from './platform';

loadI18n().then(i18n => {
 store.commit('SetDefaultLanguage', { i18n, Language: i18n.locale });
  setupRouterGuards(i18n); // ✅ inject into router

  try { store.dispatch('config/initPrimaryColor'); } catch (e) {}

  const app = mountWithRouter({ store, i18n }, router, '#login', [head]);
  installVue2Platform(app);
});

