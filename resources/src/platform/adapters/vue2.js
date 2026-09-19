/**
 * ÚNICO sitio que conoce Vue 2 y BootstrapVue para los servicios de plataforma.
 * Se instala una vez, con la instancia raíz, cuando la aplicación ya arrancó.
 *
 * Al pasar a Vue 3 este archivo se reemplaza por un adaptador equivalente (PxToast, PxConfirm, PxModal) y las vistas no
 * cambian. Se usa siempre `rootVm` como origen: es lo que hacían las llamadas `this.$root.$bvToast` / `this.$root.$bvModal`.
 */
import { notifications } from '../notifications.js';
import { confirm } from '../confirm.js';
import { modals } from '../modals.js';

function swalOptions({ title, message, confirmText, cancelText, native }) {
  const options = { showCancelButton: true };
  if (title !== undefined) options.title = title;
  if (message !== undefined) options.text = message;
  if (confirmText !== undefined) options.confirmButtonText = confirmText;
  if (cancelText !== undefined) options.cancelButtonText = cancelText;
  return { ...options, ...native };
}

export function installVue2Platform(rootVm) {
  const disposers = [];

  disposers.push(notifications.setDriver((message, options) => rootVm.$bvToast.toast(message, options)));

  disposers.push(
    modals.setDriver({
      show: (id) => rootVm.$bvModal.show(id),
      hide: (id) => rootVm.$bvModal.hide(id),
    })
  );

  // SweetAlert2 (vue-sweetalert2 expone la función en `$swal` con los colores del tenant ya configurados).
  disposers.push(
    confirm.setDriver('swal', async (options) => {
      const result = await rootVm.$swal(swalOptions(options));
      return result ? (result.isConfirmed !== undefined ? result.isConfirmed === true : !!result.value) : false;
    })
  );

  // msgBoxConfirm de BootstrapVue.
  disposers.push(
    confirm.setDriver('modal', (options) => {
      const modalOptions = { ...options.native };
      if (options.title !== undefined) modalOptions.title = options.title;
      if (options.confirmText !== undefined) modalOptions.okTitle = options.confirmText;
      if (options.cancelText !== undefined) modalOptions.cancelTitle = options.cancelText;
      if (options.variant !== undefined) modalOptions.okVariant = options.variant;
      return rootVm.$bvModal.msgBoxConfirm(options.message, modalOptions);
    })
  );

  return () => disposers.forEach((dispose) => dispose());
}
