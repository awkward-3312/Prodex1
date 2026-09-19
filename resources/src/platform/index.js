/**
 * API pública de los servicios de plataforma de PRODEX.
 *
 *   import { events, notifications, confirm, modals } from "@/platform";
 *
 * En archivos .vue se importa `confirmDialog` (mismo objeto que `confirm`) para no tapar el `confirm()` global del navegador.
 *
 * No depende de Vue. Los drivers que la conectan a Vue 2 / BootstrapVue viven en `adapters/vue2.js`.
 */
export { createEventBus, events } from './events.js';
export { createNotifications, notifications } from './notifications.js';
export { createConfirmService, confirm, confirm as confirmDialog, normalizeConfirmOptions } from './confirm.js';
export { createModalService, modals } from './modals.js';
export { installVue2Platform } from './adapters/vue2.js';
