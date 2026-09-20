/**
 * Abrir/cerrar modales por id (`show`/`hide`), sin que la vista conozca `$bvModal`.
 *
 * Los modales siguen siendo `<b-modal id="...">` dentro de cada vista (el BModal de platform/bootstrap, sobre BootstrapVueNext); este servicio
 * abre y cierra por id, lo que antes hacían `this.$bvModal.show('id')` / `this.$bvModal.hide('id')`. Las confirmaciones NO van aquí: usan `confirm()`.
 */

export function createModalService() {
  let driver = null;

  const call = (method, id) => {
    if (!driver) {
      console.warn(`[PRODEX modals] no hay driver instalado; se ignoró ${method}("${id}")`);
      return undefined;
    }
    return driver[method](id);
  };

  return {
    show: (id) => call('show', id),
    hide: (id) => call('hide', id),
    /** Instala `{ show(id), hide(id) }`. Devuelve la función que lo retira. */
    setDriver(next) {
      driver = next;
      return () => {
        if (driver === next) driver = null;
      };
    },
    hasDriver: () => driver !== null,
  };
}

export const modals = createModalService();
