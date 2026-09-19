import './platform/vue-compat';
import Vue from 'vue';
import CustomerDisplay from './views/app/pages/customer/CustomerDisplay.vue';

// Lightweight boot: avoid pulling the entire app store/router
// We only need axios for optional polling
import axios from 'axios';
window.axios = axios;
window.axios.defaults.baseURL = '/api/';
window.axios.defaults.withCredentials = true;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// i18n setup (reuse shared loader)
import VueI18n from 'vue-i18n';
Vue.use(VueI18n);
import { loadI18n } from './plugins/i18n.loader';
import { createEventBus } from './platform/events.js';

// Optional: global event bus if needed later
window.CD = createEventBus();

loadI18n().then((i18n) => {
  new Vue({
    i18n,
    render: h => h(CustomerDisplay),
  }).$mount('#customer-display');
});


