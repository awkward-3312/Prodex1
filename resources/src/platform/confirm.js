/**
 * Confirmaciones de PRODEX: una sola API con resultado `Promise<boolean>`.
 *
 * El producto confirma de dos maneras y ambas se conservan como "presentaciones":
 *  - `'swal'`  (por defecto): SweetAlert2, la que usan ~116 llamadas `this.$swal({ showCancelButton: true, ... })`.
 *  - `'modal'`: el `msgBoxConfirm` de BootstrapVue, que usan 11 vistas.
 * Un driver por presentación (ver adapters/vue2.js) hace el trabajo real; en Vue 3 se sustituyen por un `PxConfirm`.
 *
 * Opciones normalizadas: `title`, `message` (alias `text`), `confirmText` (alias `confirmButtonText`), `cancelText` (alias
 * `cancelButtonText`), `variant`, `presentation`. Cualquier OTRA clave (`type`, `icon`, `confirmButtonColor`, `size`,
 * `okVariant`…) se reenvía sin cambios al driver, así que las llamadas existentes se migran sin cambiar su aspecto.
 */

export function normalizeConfirmOptions(input, extra) {
  const source = typeof input === 'string' ? { message: input, ...(extra || {}) } : { ...(input || {}) };
  const {
    title,
    message,
    text,
    confirmText,
    confirmButtonText,
    cancelText,
    cancelButtonText,
    variant,
    presentation,
    native,
    ...rest
  } = source;

  return {
    presentation: presentation || 'swal',
    title,
    message: message !== undefined ? message : text,
    confirmText: confirmText !== undefined ? confirmText : confirmButtonText,
    cancelText: cancelText !== undefined ? cancelText : cancelButtonText,
    variant,
    // Opciones propias del driver: lo que no es normalizado + lo que se pase en `native`.
    native: { ...rest, ...(native || {}) },
  };
}

export function createConfirmService() {
  const drivers = new Map();

  /** @returns {Promise<boolean>} `true` solo si el usuario confirma. */
  async function confirm(input, extra) {
    const options = normalizeConfirmOptions(input, extra);
    const driver = drivers.get(options.presentation);
    if (!driver) throw new Error(`No hay driver de confirmación para la presentación "${options.presentation}"`);
    return (await driver(options)) === true;
  }

  return Object.assign(confirm, {
    /** Instala el driver `(opcionesNormalizadas) => Promise<boolean|null|undefined>` de una presentación. */
    setDriver(presentation, fn) {
      drivers.set(presentation, fn);
      return () => {
        if (drivers.get(presentation) === fn) drivers.delete(presentation);
      };
    },
    hasDriver: (presentation = 'swal') => drivers.has(presentation),
  });
}

export const confirm = createConfirmService();
