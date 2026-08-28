import store from "./store";

import Vue from "vue";
import router, { setupRouterGuards } from "./router";

// New organization/operations routes are registered here to avoid destabilizing
// the very large legacy router while these modules are introduced incrementally.
router.addRoutes([
  {
    path: "/app/organization",
    component: () => import("./views/app"),
    children: [
      { path: "branches", name: "organization_branches", component: () => import("./views/app/pages/organization/branches") },
      { path: "employee-access", name: "organization_employee_access", component: () => import("./views/app/pages/organization/employee_access") },
      { path: "role-templates", name: "organization_role_templates", component: () => import("./views/app/pages/organization/role_templates") },
    ],
  },
  {
    path: "/app/operations",
    component: () => import("./views/app"),
    children: [
      { path: "stock-intake", name: "stock_intake", component: () => import("./views/app/pages/inventory/stock_intake") },
    ],
  },
  {
    path: "/app/inventory",
    component: () => import("./views/app"),
    children: [
      { path: "location-stock", name: "inventory_location_stock", component: () => import("./views/app/pages/inventory/location_stock") },
      { path: "missing", name: "inventory_missing", component: () => import("./views/app/pages/inventory/missing") },
    ],
  },
]);

import App from "./App.vue";
import Auth from './auth/index.js';
import { installSarInvoiceBridge } from './utils/sarInvoiceBridge';
import { installPosOperationalLocationBridge } from './utils/posOperationalLocationBridge';
import { installNavigationPerformance } from './utils/navigationPerformance';
window.auth = new Auth();
import { ValidationObserver, ValidationProvider, extend, localize } from 'vee-validate';
import * as rules from "vee-validate/dist/rules";

localize({ es: { messages: { required: 'Este campo es obligatorio', required_if: 'Este campo es obligatorio', regex: 'Este campo debe tener un formato válido', mimes: 'Este archivo debe tener un tipo válido', size: (_, { size }) => `El tamaño del archivo debe ser menor de ${size}`, min: 'Este campo debe tener al menos {length} caracteres', max: (_, { length }) => `Este campo no puede tener más de ${length} caracteres` } } });
localize('es');
Object.keys(rules).forEach(rule => { extend(rule, rules[rule]); });

extend('url', { validate(value) { if (!value) return false; try { const parsed = new URL(value); return parsed.protocol === 'http:' || parsed.protocol === 'https:'; } catch (e) { return false; } }, message: 'Este campo debe contener una URL válida (http:// o https://)' });

Vue.component("ValidationObserver", ValidationObserver);
Vue.component('ValidationProvider', ValidationProvider);

Vue.component('qrcode-scanner', {
  props: { qrbox: { type: Number, default: 250 }, fps: { type: Number, default: 10 } },
  data() { return { isFirstScan: true, html5QrcodeScanner: null }; },
  template: `<div id="reader"></div>`,
  mounted () { this.initializeScanner(); },
  methods: {
    initializeScanner() { const config = { fps: this.fps, qrbox: this.qrbox }; this.html5QrcodeScanner = new Html5QrcodeScanner('reader', config); this.html5QrcodeScanner.render(this.onScanSuccess); },
    onScanSuccess (decodedText, decodedResult) { if (this.isFirstScan) { this.isFirstScan = false; this.$emit('result', decodedText, decodedResult); } else { this.html5QrcodeScanner.stop(); } },
  },
  beforeDestroy() { if (this.html5QrcodeScanner) this.html5QrcodeScanner.clear(); }
});

import StockyKit from "./plugins/stocky.kit";
Vue.use(StockyKit);
import FriendlyNavigation from "./plugins/friendlyNavigation";
Vue.use(FriendlyNavigation);
import VueCookies from 'vue-cookies';
Vue.use(VueCookies);
var VueCookie = require('vue-cookie');
Vue.use(VueCookie);

import ExcelExport from "./components/ExcelExport.vue";
Vue.component('vue-excel-xlsx', ExcelExport);
import LucideIcon from "./components/LucideIcon.vue";
Vue.component('lucide-icon', LucideIcon);
import SerialNumbersField from "./components/SerialNumbersField.vue";
Vue.component('serial-numbers-field', SerialNumbersField);

window.axios = require('axios');
window.axios.defaults.baseURL = '/api/';
window.axios.defaults.withCredentials = true;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.__axiosPendingCount = 0;
window.__initialLoaderActive = true;
window.__appReadyToHideLoader = false;
window.__hideInitialLoaderIfDone = function () { if (!window.__initialLoaderActive || !window.__appReadyToHideLoader) return; if (window.__axiosPendingCount === 0) { const el = document.getElementById('loading_wrap'); if (el) el.style.display = 'none'; window.__initialLoaderActive = false; } };
function incrementPending(config) { if (window.__initialLoaderActive && !(config && config.meta && config.meta.skipInitialLoader)) window.__axiosPendingCount++; }
function decrementPending(config) { if (window.__initialLoaderActive && !(config && config.meta && config.meta.skipInitialLoader)) { window.__axiosPendingCount = Math.max(0, window.__axiosPendingCount - 1); window.__hideInitialLoaderIfDone(); } }

const legacyApiMessageRules = [
  [/^Payment Create successfully$/i, 'Pago creado correctamente'], [/^Payment Update successfully$/i, 'Pago actualizado correctamente'], [/^Payment Delete successfully$/i, 'Pago eliminado correctamente'], [/^Created successfully$/i, 'Creado correctamente'], [/^Updated successfully$/i, 'Actualizado correctamente'], [/^Deleted successfully$/i, 'Eliminado correctamente'], [/^Successfully Created$/i, 'Creado correctamente'], [/^Successfully Updated$/i, 'Actualizado correctamente'], [/^Successfully Deleted$/i, 'Eliminado correctamente'], [/^Success$/i, 'Éxito'], [/^Failed$/i, 'Error'], [/^Not found$/i, 'No encontrado'], [/^Unauthorized$/i, 'No autorizado'], [/^Forbidden$/i, 'Acceso denegado'], [/^Invalid data$/i, 'Datos no válidos'], [/^Something went wrong\.?$/i, 'Ocurrió un error.'], [/^An error occurred\.?$/i, 'Ocurrió un error.'], [/^Return exist for the Transaction$/i, 'Ya existe una devolución para esta transacción'], [/^You are not allowed to access this sale \(warehouse restriction\)\.?$/i, 'No tienes permiso para acceder a esta venta por la restricción de almacén.'], [/^Insufficient stock for (.+)$/i, 'Inventario insuficiente para $1'], [/^Product not found\.?$/i, 'Producto no encontrado.'], [/^Customer not found\.?$/i, 'Cliente no encontrado.'], [/^Supplier not found\.?$/i, 'Proveedor no encontrado.'], [/^Warehouse not found\.?$/i, 'Almacén no encontrado.'], [/^Sale not found\.?$/i, 'Venta no encontrada.'], [/^Purchase not found\.?$/i, 'Compra no encontrada.'], [/^Payment not found\.?$/i, 'Pago no encontrado.'], [/^The given data was invalid\.?$/i, 'Los datos proporcionados no son válidos.'], [/^Unauthenticated\.?$/i, 'No autenticado. Inicia sesión nuevamente.'],
];
function translateLegacyApiMessage(value) { if (typeof value !== 'string' || !value.trim()) return value; let translated = value.trim(); legacyApiMessageRules.some(([pattern, replacement]) => { if (pattern.test(translated)) { translated = translated.replace(pattern, replacement); return true; } return false; }); return translated; }
function translateApiFeedback(data) { if (!data || typeof data !== 'object') return data; if (typeof data.message === 'string') data.message = translateLegacyApiMessage(data.message); if (typeof data.error === 'string') data.error = translateLegacyApiMessage(data.error); if (data.errors && typeof data.errors === 'object') Object.keys(data.errors).forEach(key => { const value = data.errors[key]; data.errors[key] = Array.isArray(value) ? value.map(item => translateLegacyApiMessage(item)) : (typeof value === 'string' ? translateLegacyApiMessage(value) : value); }); return data; }

let isRedirectingToLogin = false;
async function hardLogoutToLogin() { if (isRedirectingToLogin) return; isRedirectingToLogin = true; try { await axios.post('/logout', {}, { baseURL: '', meta: { skipAuthRedirect: true, skipInitialLoader: true } }); } catch (e) {} window.location.replace('/login'); }

axios.interceptors.request.use(config => { incrementPending(config); try { const uiLocale = window.localStorage.getItem('language'); if (uiLocale) { config.headers = config.headers || {}; config.headers['X-Pdf-Locale'] = uiLocale; } } catch (e) {} return config; }, error => { decrementPending(error && error.config); return Promise.reject(error); });
axios.interceptors.response.use(response => { decrementPending(response && response.config); if (response && response.data && typeof response.data === 'object') translateApiFeedback(response.data); return response; }, error => {
  decrementPending(error && error.config);
  if (!error.response) return Promise.reject(translateLegacyApiMessage(error.message));
  if (error.response.data && typeof error.response.data === 'object') translateApiFeedback(error.response.data);
  if (error.config && error.config.meta && error.config.meta.skipAuthRedirect) return Promise.reject(error.response.data || translateLegacyApiMessage(error.message));
  if (error.response.status === 409 && error.response.headers['x-session-revoked'] === '1') { hardLogoutToLogin(); return Promise.reject(error); }
  const { status, data } = error.response;
  if (status === 401) { hardLogoutToLogin(); return Promise.reject(data || translateLegacyApiMessage(error.message)); }
  const skipErrorRedirect = error.config && error.config.meta && error.config.meta.skipErrorRedirect;
  const method = ((error.config && error.config.method) || 'get').toString().toLowerCase();
  const requestUrl = ((error.config && error.config.url) || '').toString();
  const isTransferLogisticsCapabilityRequest = /(^|\/)transfer-logistics(\/|$)/i.test(requestUrl);
  const isOrganizationCapabilityRequest = /(^|\/)organization(\/|$)/i.test(requestUrl);
  const isNavigationalLoad = method === 'get' && !skipErrorRedirect && !isTransferLogisticsCapabilityRequest && !isOrganizationCapabilityRequest;
  if (status === 404 && isNavigationalLoad) router.push({ name: 'NotFound' });
  if (status === 403) { if (data && data.status === 'limit_reached') { Vue.prototype.$limitReachedMessage = data.message || 'Has alcanzado el límite de tu plan. Actualiza tu plan para continuar.'; window.Fire.$emit('show-limit-reached', data.message || 'Has alcanzado el límite de tu plan. Actualiza tu plan para continuar.'); } else if (isNavigationalLoad) router.push({ name: 'not_authorize' }); }
  return Promise.reject(data || translateLegacyApiMessage(error.message));
});

installSarInvoiceBridge(window.axios);
installPosOperationalLocationBridge(window.axios);
import vSelect from 'vue-select';
Vue.component('v-select', vSelect);
import 'vue-select/dist/vue-select.css';
import '@trevoreyre/autocomplete-vue/dist/style.css';
window.Fire = new Vue();
Vue.prototype.$uploadPath = window.__uploadPath || 'images';
Vue.prototype.$imgUrl = function(subfolder, filename) { return '/' + this.$uploadPath + '/' + subfolder + '/' + filename; };
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
  setupRouterGuards(i18n);
  installNavigationPerformance(window.axios, router);
  try { setupGlobalOfflineSync(); } catch (e) {}
  new Vue({ store, router, VueCookie, i18n, render: h => h(App) }).$mount('#app');
});