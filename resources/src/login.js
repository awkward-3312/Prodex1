import store from "./store";
import Vue from "vue";
import router, { setupRouterGuards } from "./router";
import { ValidationObserver, ValidationProvider, extend, localize } from 'vee-validate';
import * as rules from "vee-validate/dist/rules";
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
import Meta from "vue-meta";

Vue.use(Meta, {
  keyName: "metaInfo",
  attribute: "data-vue-meta",
  ssrAttribute: "data-vue-meta-server-rendered",
  tagIDKeyName: "vmid",
  refreshOnceOnNavigation: true
});

localize({
  es: {
    messages: {
      required: 'Este campo es obligatorio',
      required_if: 'Este campo es obligatorio',
      regex: 'Este campo debe tener un formato válido',
      mimes: 'Este archivo debe tener un tipo válido',
      size: (_, { size }) => `El tamaño del archivo debe ser menor de ${size}`,
      min: 'Este campo debe tener al menos {length} caracteres',
      max: (_, { length }) => `Este campo no puede tener más de ${length} caracteres`
    }
  },
});
localize('es');
// Install VeeValidate rules and localization
Object.keys(rules).forEach(rule => {
  extend(rule, rules[rule]);
});

// Register it globally
Vue.component("ValidationObserver", ValidationObserver);
Vue.component('ValidationProvider', ValidationProvider);

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

window.Fire = new Vue();

Vue.component('login-component', require('./views/app/sessions/signIn.vue').default);
Vue.component('forgot-component', require('./views/app/sessions/forgot.vue').default);
Vue.component('reset-component', require('./views/app/sessions/reset.vue').default);

Vue.config.productionTip = true;
Vue.config.silent = true;
Vue.config.devtools = false;

import VueI18n from 'vue-i18n';
Vue.use(VueI18n);

import { loadI18n } from './plugins/i18n.loader';

loadI18n().then(i18n => {
 store.commit('SetDefaultLanguage', { i18n, Language: i18n.locale });
  setupRouterGuards(i18n); // ✅ inject into router

  try { store.dispatch('config/initPrimaryColor'); } catch (e) {}

  new Vue({
    el: '#login',
    store,
    router,
    i18n,
  });
});

