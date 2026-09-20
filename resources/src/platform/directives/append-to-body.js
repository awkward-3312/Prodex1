// `v-append-to-body` de vue-select y vue2-daterange-picker con hooks de Vue 3 (mounted/unmounted). Las dos librerías traen la misma directiva
// con hooks de Vue 2 (`inserted`/`unbind`, `vnode.context`), que solo funcionaban con `CUSTOM_DIR` de @vue/compat. NO se parchea `node_modules`:
// `platform/compat/vue-select.js` y `platform/compat/daterange-picker.js` extienden el componente de la librería y sustituyen SOLO esta
// directiva. La lógica es la de la librería (mover el desplegable a <body> y posicionarlo con `calculatePosition`; deshacerlo al desmontar).
// `binding.instance` es la instancia del componente de la librería (lo que era `vnode.context`).

function move(el, context, position) {
  if (!context || !context.appendToBody) return;
  const rect = context.$refs.toggle.getBoundingClientRect();
  const scrollX = window.scrollX || window.pageXOffset;
  const scrollY = window.scrollY || window.pageYOffset;
  el.unbindPosition = context.calculatePosition(el, context, position(rect, scrollX, scrollY));
  document.body.appendChild(el);
}

function restore(el, context) {
  if (!context || !context.appendToBody) return;
  if (typeof el.unbindPosition === 'function') el.unbindPosition();
  if (el.parentNode) el.parentNode.removeChild(el);
}

/** vue-select: `{ width, left, top }` como cadenas con px. */
export const vSelectAppendToBody = {
  mounted: (el, binding) => move(el, binding.instance, (r, sx, sy) => ({ width: `${r.width}px`, left: `${sx + r.left}px`, top: `${sy + r.top + r.height}px` })),
  unmounted: (el, binding) => restore(el, binding.instance),
};

/** vue2-daterange-picker: `{ width, top, left, right }` numéricos. */
export const daterangeAppendToBody = {
  mounted: (el, binding) => move(el, binding.instance, (r, sx, sy) => ({ width: r.width, top: sy + r.top + r.height, left: sx + r.left, right: r.right })),
  unmounted: (el, binding) => restore(el, binding.instance),
};
