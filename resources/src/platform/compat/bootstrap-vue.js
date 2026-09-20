// SOLO @vue/compat. BootstrapVue 2 pinta <select> / <input type=checkbox|radio> con `directives: [{ name: 'model' }]` y sus
// propios `on: { change }`. En Vue 3 esa directiva instala un listener `change` que llama a `el[assignKey]`, y `assignKey` solo
// existe si el vnode trae `onUpdate:modelValue`; sin él cada cambio lanza "el[assignKey] is not a function". Se añade un
// asignador vacío a esos elementos (BootstrapVue ya actualiza su valor con su `change`). Desaparece al migrar BootstrapVue.
import { BFormSelect, BFormCheckbox, BFormRadio, BFormTags, VBVisible, VBHover } from 'bootstrap-vue/dist/bootstrap-vue.esm.js';

const noop = () => {};

function markModelInputs(vnode) {
  if (!vnode || typeof vnode !== 'object') return;
  if (Array.isArray(vnode)) {
    vnode.forEach(markModelInputs);
    return;
  }
  if (typeof vnode.type === 'string' && vnode.dirs) {
    vnode.props = vnode.props || {};
    if (!vnode.props['onUpdate:modelValue']) vnode.props['onUpdate:modelValue'] = noop;
  }
  if (Array.isArray(vnode.children)) vnode.children.forEach(markModelInputs);
}

// Las únicas directivas de BootstrapVue 2 que quedan activas son INTERNAS de dos componentes (`v-b-visible` en BFormTextarea con `max-rows`,
// `v-b-hover` en BFormDatepicker) y traen hooks de Vue 2 (`bind/componentUpdated/unbind`). En vez de mantener `CUSTOM_DIR` de @vue/compat
// para todo el proyecto, se les añaden los hooks de Vue 3 equivalentes (`mounted/updated/unmounted`). Sus hooks no usan `vnode.context`.
function addVue3DirectiveHooks(directive) {
  if (!directive || directive.mounted) return;
  directive.mounted = directive.bind;
  directive.updated = directive.componentUpdated;
  directive.unmounted = directive.unbind;
  // @vue/compat avisa (y, con CUSTOM_DIR desactivado, ignora) cualquier clave de Vue 2 presente: se retiran tras crear las de Vue 3.
  delete directive.bind;
  delete directive.componentUpdated;
  delete directive.unbind;
}

export function patchBootstrapVueForCompat(Vue) {
  if (!Vue || !String(Vue.version).startsWith('3')) return;
  [VBVisible, VBHover].forEach(addVue3DirectiveHooks);
  [BFormSelect, BFormCheckbox, BFormRadio, BFormTags].forEach((Component) => {
    const options = Component && (Component.options || Component);
    if (!options || typeof options.render !== 'function' || options.render.__pxCompatPatched) return;
    const original = options.render;
    options.render = function patchedRender(h) {
      const vnode = original.call(this, h);
      markModelInputs(vnode);
      return vnode;
    };
    options.render.__pxCompatPatched = true;
  });
}
