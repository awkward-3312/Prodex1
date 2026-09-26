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
import { loadI18n } from './plugins/i18n.loader';
import { createEventBus } from './platform/events.js';

// Optional: global event bus if needed later
window.CD = createEventBus();

loadI18n().then((i18n) => {
  // Sin `mountWithRouter` (esta pantalla no usa router): vue-i18n 11 se instala con `app.use(i18n)` sobre la app
  // real de Vue 3 que hay detrás de la instancia de compat (`root.$.appContext.app`), igual que en `mountWithRouter`
  // (ver `platform/compat/vue-router.js`) — pasar `i18n` como opción raíz de `new Vue({...})` ya no lo instala.
  const root = new Vue({ render: h => h(CustomerDisplay) });
  root.$.appContext.app.use(i18n);
  root.$mount('#customer-display');
});


