/**
 * Confirmación por SweetAlert2 (`vue-sweetalert2` expone `$swal` en la raíz; es un plugin de Vue 2/compat) + entrada única que instala el
 * driver de BootstrapVueNext (toast, modales por id, confirmación en modal). Lo que era `$bvToast` / `$bvModal` ya no se usa.
 */
import { confirm } from '../confirm.js';
import { modals } from '../modals.js';
import { notifications } from '../notifications.js';
import { installBootstrapVueNextPlatform } from './bvn.js';

function swalOptions({ title, message, confirmText, cancelText, native }) {
  const options = { showCancelButton: true };
  if (title !== undefined) options.title = title;
  if (message !== undefined) options.text = message;
  if (confirmText !== undefined) options.confirmButtonText = confirmText;
  if (cancelText !== undefined) options.cancelButtonText = cancelText;
  return { ...options, ...native };
}

/** Confirmación por SweetAlert2 (`vue-sweetalert2` expone `$swal` en la raíz con los colores del tenant ya configurados). Sin DOM: se puede probar en node. */
export function installSweetAlertConfirm(rootVm) {
  return confirm.setDriver('swal', async (options) => {
    const result = await rootVm.$swal(swalOptions(options));
    return result ? (result.isConfirmed !== undefined ? result.isConfirmed === true : !!result.value) : false;
  });
}

export function installVue2Platform(rootVm) {
  const disposers = [installBootstrapVueNextPlatform(rootVm), installSweetAlertConfirm(rootVm)];

  // Las plantillas no pueden importar el servicio: `@click="$modals.hide('id')"` (antes `$bvModal.hide`). Mismo objeto que `modals` del script.
  const globals = rootVm.$.appContext.config.globalProperties;
  globals.$modals = modals;
  // `$platform`: los tres servicios juntos para plantillas y pruebas E2E (`app.config.globalProperties.$platform`).
  globals.$platform = { notifications, modals, confirm };
  disposers.push(() => { delete globals.$modals; delete globals.$platform; });

  return () => disposers.forEach((dispose) => dispose());
}
