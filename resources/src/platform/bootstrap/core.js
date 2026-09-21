// Utilidades comunes de los wrappers de BootstrapVueNext (sin dependencias de componentes: cualquier familia puede importarlas).
import { h } from 'vue';

const VUE3 = { MODE: 3 };

/** Marca un componente de BootstrapVueNext (Vue 3 puro) como `compatConfig: { MODE: 3 }` bajo @vue/compat. */
export function pure(component) {
  if (component && !component.compatConfig) component.compatConfig = VUE3;
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
