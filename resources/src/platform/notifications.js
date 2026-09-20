/**
 * Notificaciones (toasts) de PRODEX.
 *
 * Las vistas llaman a este servicio; un "driver" que se instala una sola vez al arrancar (ver adapters/vue2.js) decide cómo
 * se muestran. El driver actual usa `useToast()` de BootstrapVueNext (adapters/bvn.js); cambiarlo no toca las vistas.
 *
 * `notify(mensaje, opciones)` reenvía las opciones tal cual al driver (por eso reproduce EXACTAMENTE las llamadas antiguas
 * `$bvToast.toast(msg, { title, variant, solid })`). `success/error/warning/info` son atajos con el `variant` de
 * BootstrapVue y `solid: true`, que es lo que ya usaba todo el producto.
 */

const MAX_PENDING = 50;

export function createNotifications() {
  let driver = null;
  const pending = [];

  function notify(message, options = {}) {
    if (driver) return driver(message, options);
    // Antes de que el driver esté instalado (arranque) se conservan las últimas notificaciones para mostrarlas luego.
    pending.push([message, options]);
    if (pending.length > MAX_PENDING) pending.shift();
    return undefined;
  }

  const shortcut = (variant) => (message, options = {}) => notify(message, { solid: true, ...options, variant });

  return {
    notify,
    success: shortcut('success'),
    error: shortcut('danger'),
    warning: shortcut('warning'),
    info: shortcut('info'),

    /** Instala el driver `(mensaje, opciones) => void` y vacía lo pendiente. Devuelve la función que lo retira. */
    setDriver(fn) {
      driver = fn;
      if (driver) while (pending.length) driver(...pending.shift());
      return () => {
        if (driver === fn) driver = null;
      };
    },
    hasDriver: () => driver !== null,
    pendingCount: () => pending.length,
  };
}

export const notifications = createNotifications();
