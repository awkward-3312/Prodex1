import VueSweetalert2 from 'vue-sweetalert2';

// If you don't need the styles, do not connect
import 'sweetalert2/dist/sweetalert2.min.css';

// Confirm button follows the tenant brand colour (see _tokens.scss --px-primary,
// which resolves to var(--primary-color)). Cancel stays neutral grey.
const options = {
  confirmButtonColor: "var(--px-primary)",
  cancelButtonColor: "#6b7280"
};

// Plugin de app de Vue 3 real (antes efecto lateral `Vue.use(VueSweetalert2, options)` sobre el `Vue` global al
// importar este archivo). `$swal` queda en `app.config.globalProperties`, de donde ya lo lee
// `platform/adapters/vue2.js` (`installSweetAlertConfirm`).
export default {
  install(app) {
    app.use(VueSweetalert2, options);
  }
};
