// SOLO @vue/compat. vue-router 3 decide si `<router-link>` usa slot con ámbito con `this.$scopedSlots.$hasNormal` (Vue 2.6).
// En Vue 3 ese marcador no existe, así que el slot por defecto se trata como slot con ámbito, devuelve varios hijos y
// el enlace se pinta como <span> (sin <a> ni navegación). Se declara ese slot como "normal", que es lo que ocurría en Vue 2:
// ninguna vista usa `<router-link v-slot>`. Desaparece con vue-router 4.
export function patchRouterLinkForCompat(Vue) {
  if (!Vue || !String(Vue.version).startsWith('3')) return;
  const Link = Vue.component('RouterLink');
  if (!Link || Link.__pxCompatPatched) return;
  const options = Link.options || Link;
  const original = options.render;
  const asNormalSlots = (instance) =>
    new Proxy(instance, {
      get(target, key) {
        if (key === '$scopedSlots') return new Proxy(target.$scopedSlots, { get: (slots, k) => (k === '$hasNormal' ? true : slots[k]) });
        const value = Reflect.get(target, key, target);
        return typeof value === 'function' ? value.bind(target) : value;
      },
    });
  Vue.component('RouterLink', {
    ...options,
    render(h) {
      return original.call(asNormalSlots(this), h);
    },
    __pxCompatPatched: true,
  });
}
