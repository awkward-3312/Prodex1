import './platform/vue-compat';
import { mountWithRouter } from './platform/mount';
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
  // Sin router: `mountWithRouter` crea la `app` real de Vue 3 igual (createApp), solo se instala i18n sobre ella.
  const app = mountWithRouter({ render: h => h(CustomerDisplay) });
  app.use(i18n);
  app.mount('#customer-display');
});
