import store from "./store";

import Vue from "vue";
import router, { setupRouterGuards } from "./router";

import App from "./App.vue";
import Auth from './auth/index.js';
window.auth = new Auth();
import { ValidationObserver, ValidationProvider, extend, localize } from 'vee-validate';
import * as rules from "vee-validate/dist/rules";

localize({
  en: {
    messages: {
      required: 'This field is required',
      required_if: 'This field is required',
      regex: 'This field must be a valid',
      mimes: `This field must have a valid file type.`,
      size: (_, { size }) => `This field size must be less than ${size}.`,
      min: 'This field must have no less than {length} characters',
      max: (_, { length }) => `This field must have no more than ${length} characters`
    }
  },
});
// Install VeeValidate rules and localization
Object.keys(rules).forEach(rule => {
  extend(rule, rules[rule]);
});

// "url" is not a built-in rule in vee-validate 3
extend('url', {
  validate(value) {
    if (!value) return false;
    try {
      const parsed = new URL(value);
      return parsed.protocol === 'http:' || parsed.protocol === 'https:';
    } catch (e) {
      return false;
    }
  },
  message: 'This field must be a valid URL (http:// or https://)'
});

// Register it globally
Vue.component("ValidationObserver", ValidationObserver);
Vue.component('ValidationProvider', ValidationProvider);


Vue.component('qrcode-scanner', {
  props: {
    qrbox: {
      type: Number,
      default: 250
    },
    fps: {
      type: Number,
      default: 10
    },
  },
  data() {
    return {
      isFirstScan: true,
      html5QrcodeScanner: null,
    };
  },
  template: `<div id="reader"></div>`, // Use ref instead of id for dynamic rendering

  mounted () {
    this.initializeScanner();
  },
  methods: {
    initializeScanner() {
      const config = {
        fps: this.fps,
        qrbox: this.qrbox,
      };
      this.html5QrcodeScanner = new Html5QrcodeScanner('reader', config); // Use id for dynamic rendering
      this.html5QrcodeScanner.render(this.onScanSuccess);
    },
    onScanSuccess (decodedText, decodedResult) {
      if (this.isFirstScan) {
        this.isFirstScan = false;
        this.$emit('result', decodedText, decodedResult);
      } else {
        this.html5QrcodeScanner.stop();
      }
    },

  },

  beforeDestroy() {
    if (this.html5QrcodeScanner) {
      this.html5QrcodeScanner.clear();
    }
  }

});

import StockyKit from "./plugins/stocky.kit";
Vue.use(StockyKit);
import VueCookies from 'vue-cookies'
Vue.use(VueCookies);

var VueCookie = require('vue-cookie');
Vue.use(VueCookie);

// Register Excel Export Component globally
import ExcelExport from "./components/ExcelExport.vue";
Vue.component('vue-excel-xlsx', ExcelExport);

// Lucide icon wrapper (replaces Iconsmind i-* font icons)
import LucideIcon from "./components/LucideIcon.vue";
Vue.component('lucide-icon', LucideIcon);

// Serial / IMEI tracking field (entry on purchase, selection on sale)
import SerialNumbersField from "./components/SerialNumbersField.vue";
Vue.component('serial-numbers-field', SerialNumbersField);

window.axios = require('axios');

window.axios.defaults.baseURL = '/api/';
window.axios.defaults.withCredentials = true;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// ==============================
// Initial load loader control
// ==============================
window.__axiosPendingCount = 0;
window.__initialLoaderActive = true;     // Only during first SPA boot
window.__appReadyToHideLoader = false;   // Set true by App.vue

window.__hideInitialLoaderIfDone = function () {
  if (!window.__initialLoaderActive) return;
  if (!window.__appReadyToHideLoader) return;
  if (window.__axiosPendingCount === 0) {
    const el = document.getElementById('loading_wrap');
    if (el) el.style.display = 'none';
    window.__initialLoaderActive = false;
  }
};

function incrementPending(config) {
  if (
    window.__initialLoaderActive &&
    !(config && config.meta && config.meta.skipInitialLoader)
  ) {
    window.__axiosPendingCount++;
  }
}

function decrementPending(config) {
  if (
    window.__initialLoaderActive &&
    !(config && config.meta && config.meta.skipInitialLoader)
  ) {
    window.__axiosPendingCount = Math.max(0, window.__axiosPendingCount - 1);
    window.__hideInitialLoaderIfDone();
  }
}

// ==============================
// Redirect guards (IMPORTANT)
// ==============================
let isRedirectingToLogin = false;

// Hard logout (web session) then go to /login.
// This prevents the classic infinite loop where:
// - API auth is gone (401) => we navigate to /login
// - but the web session is still alive => /login redirects back to /
async function hardLogoutToLogin() {
  if (isRedirectingToLogin) return;
  isRedirectingToLogin = true;

  try {
    // Use a non-API baseURL, and bypass the auth-redirect logic for this call.
    await axios.post(
      "/logout",
      {},
      {
        baseURL: "",
        meta: { skipAuthRedirect: true, skipInitialLoader: true },
      }
    );
  } catch (e) {
    // ignore: we still want to navigate to /login
  }

  window.location.replace("/login");
}

// ==============================
// Request interceptor
// ==============================
axios.interceptors.request.use(
  (config) => {
    incrementPending(config);
    // Tell the backend which locale to render server-side output (Blade PDFs) in,
    // so downloaded/printed PDFs match the language selected in the UI.
    // Read straight from localStorage (same key vue-localstorage writes in the
    // language store module) to avoid a store dependency inside the interceptor.
    try {
      const uiLocale = window.localStorage.getItem('language');
      if (uiLocale) {
        config.headers = config.headers || {};
        config.headers['X-Pdf-Locale'] = uiLocale;
      }
    } catch (e) { /* localStorage unavailable — ignore */ }
    return config;
  },
  (error) => {
    decrementPending(error && error.config);
    return Promise.reject(error);
  }
);

// ==============================
// Response interceptor
// ==============================
axios.interceptors.response.use(
  (response) => {
    decrementPending(response && response.config);
    return response;
  },
  (error) => {
    decrementPending(error && error.config);

    if (!error.response) {
      return Promise.reject(error.message);
    }

    // Allow specific requests to opt out of the global auth redirect logic
    if (error.config && error.config.meta && error.config.meta.skipAuthRedirect) {
      return Promise.reject(error.response.data || error.message);
    }

    // 🔥 SESSION REVOKED (Security tab logout)
    if (
      error.response &&
      error.response.status === 409 &&
      error.response.headers["x-session-revoked"] === "1"
    ) {
      // Ensure the web session is also cleared to avoid /login <-> / loops
      hardLogoutToLogin();
      return Promise.reject(error);
    }

    const { status, data } = error.response;
    const currentPath = window.location.pathname;

    // ==========================
    // 401 – Unauthenticated
    // ==========================
    if (status === 401) {
      // Ensure the web session is also cleared to avoid /login <-> / loops
      hardLogoutToLogin();
      return Promise.reject(data || error.message);
    }


    // ==========================
    // 404 / 403
    // ==========================
    // Only a failed page-resource LOAD (a GET) is allowed to navigate the whole
    // app to NotFound / not_authorize — e.g. opening a detail page for a record
    // that no longer exists. Everything else rejects quietly so the calling
    // component can surface the error inline:
    //   - mutations (POST/PUT/PATCH/DELETE): a 404/403 means "action failed",
    //     not "page missing" — these should show a toast, never blank the app.
    //   - background / fire-and-forget calls (e.g. locale sync, polling): opt
    //     out explicitly via meta.skipErrorRedirect.
    const skipErrorRedirect =
      error.config && error.config.meta && error.config.meta.skipErrorRedirect;

    const method = (
      (error.config && error.config.method) || 'get'
    ).toString().toLowerCase();

    const isNavigationalLoad = method === 'get' && !skipErrorRedirect;

    if (status === 404 && isNavigationalLoad) {
      router.push({ name: 'NotFound' });
    }

    if (status === 403) {
      if (data && data.status === 'limit_reached') {
        // Stay on the current page — show an upgrade modal instead of redirecting
        Vue.prototype.$limitReachedMessage = data.message || 'You have reached a plan limit. Please upgrade to continue.';
        window.Fire.$emit('show-limit-reached', data.message || 'You have reached a plan limit. Please upgrade to continue.');
      } else if (isNavigationalLoad) {
        router.push({ name: 'not_authorize' });
      }
    }

    return Promise.reject(data || error.message);
  }
);


import vSelect from 'vue-select'
Vue.component('v-select', vSelect)
import 'vue-select/dist/vue-select.css';

import '@trevoreyre/autocomplete-vue/dist/style.css';

window.Fire = new Vue();

// Global helper for tenant-aware image paths
Vue.prototype.$uploadPath = window.__uploadPath || 'images';
Vue.prototype.$imgUrl = function(subfolder, filename) {
  return '/' + this.$uploadPath + '/' + subfolder + '/' + filename;
};

import Breadcumb from "./components/breadcumb";
import VueI18n from 'vue-i18n';
Vue.use(VueI18n);


Vue.component("breadcumb", Breadcumb);

Vue.config.productionTip = true;
Vue.config.silent = true;
Vue.config.devtools = false;

import { loadI18n } from './plugins/i18n.loader';
import { setupGlobalOfflineSync } from './utils/globalOfflineSync';

loadI18n().then(i18n => {
 store.commit('SetDefaultLanguage', { i18n, Language: i18n.locale });
  setupRouterGuards(i18n); // ✅ inject into router

  // Initialize global offline sales sync (works from any page)
  try {
    setupGlobalOfflineSync();
  } catch (e) {}

  new Vue({
    store,
    router,
    VueCookie,
    i18n, // vue-i18n will inject $i18n to all components
    render: h => h(App),
  }).$mount("#app");
});

  