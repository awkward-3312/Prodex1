// Utilidades comunes de los wrappers de BootstrapVueNext (sin dependencias de componentes: cualquier familia puede importarlas).
import { h } from 'vue';

// Vue 3 nativo (sin runtime de compatibilidad): los componentes de BootstrapVueNext ya se ejecutan tal cual son,
// sin necesitar ninguna marca especial. `pure` queda como identidad — se mantiene el nombre y las ~56 llamadas
// existentes (`pure(component)`, `wrapper(...)` de abajo) para no tocar cada punto de uso en esta familia.
export function pure(component) {
  return component;
}

/** Wrapper con props propias y clases extra (vista → wrapper → componente). */
export const wrapper = (name, component, extraProps, extraClass) => pure({
  name,
  inheritAttrs: false,
  props: extraProps,
  setup(props, { attrs, slots }) {
    return () => h(component, { ...attrs, ...extraClass(props, attrs) }, slots);
  },
});

/** Atributo booleano de plantilla: `<x flag>` (cadena vacía) o `:flag="true"`. */
export const truthyAttr = (v) => v === '' || v === true;
/** Manejador o lista de manejadores → lista. */
export const toList = (fn) => (Array.isArray(fn) ? fn : fn ? [fn] : []);
