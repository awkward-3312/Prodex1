// `v-append-to-body` con hooks de Vue 3 (mounted/unmounted) para el desplegable de `components/VSelect.vue`
// (mueve el nodo a <body> y lo posiciona con `calculatePosition`; lo deshace al desmontar).
// `binding.instance` es la instancia del componente que usa la directiva (equivalente al `vnode.context` de Vue 2).
// vue2-daterange-picker (que también usaba esta directiva) fue reemplazado por `components/DateRangePicker.vue`
// sobre `@vuepic/vue-datepicker`, que se posiciona solo — su equivalente de esta directiva ya no hace falta.

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

/** `{ width, left, top }` como cadenas con px. */
export const vSelectAppendToBody = {
  mounted: (el, binding) => move(el, binding.instance, (r, sx, sy) => ({ width: `${r.width}px`, left: `${sx + r.left}px`, top: `${sy + r.top + r.height}px` })),
  unmounted: (el, binding) => restore(el, binding.instance),
};
