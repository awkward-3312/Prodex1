// main.js
import Vue from 'vue';
import VueSweetalert2 from 'vue-sweetalert2';
 
// If you don't need the styles, do not connect
import 'sweetalert2/dist/sweetalert2.min.css';

// Confirm button follows the tenant brand colour (see _tokens.scss --px-primary,
// which resolves to var(--primary-color)). Cancel stays neutral grey.
const options = {
    confirmButtonColor: "var(--px-primary)",
    cancelButtonColor: "#6b7280"
  }
 
Vue.use(VueSweetalert2,options);