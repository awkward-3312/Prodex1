// Directiva `v-on-clickaway` propia (sustituye a la librería click-away de Vue 2, que dependía de `Vue.util` y de los hooks de Vue 2).
// Hooks de Vue 3 (mounted / updated / unmounted); sin internals de Vue, sin `$children`.
//
// Semántica reproducida de esa librería (la única que PRODEX necesita):
//  - Escucha `click` en `document.documentElement` (fase de burbujeo). Un toque en móvil genera su `click`, por eso no se
//    escucha `touchstart` (duplicaría el handler).
//  - El handler se ejecuta solo si el objetivo del click NO está dentro del elemento (`el.contains`).
//  - Guarda de "primer tick": el click que abre el elemento (y sube hasta el documento tras montarse o tras cambiar el handler)
//    no lo cierra. Se rearma con un `setTimeout(0)` al montar y cada vez que cambia el valor de la directiva, igual que el
//    `unbind + bind` de la librería.
//  - El handler se llama con `this` = componente que declara la directiva y recibe el evento.
//  - Si el valor no es una función: aviso en desarrollo y no se ejecuta nada.
//  - Un único listener por elemento (WeakMap), retirado en `unmounted`.

const states = new WeakMap();

function warn(message) {
  if (typeof process !== 'undefined' && process.env && process.env.NODE_ENV === 'production') return;
  if (typeof console !== 'undefined' && console.warn) console.warn(`[on-clickaway] ${message}`);
}

function arm(state) {
  state.armed = false;
  state.timer = setTimeout(() => {
    state.armed = true;
    state.timer = null;
  }, 0);
}

function configure(state, binding) {
  const handler = binding.value;
  state.context = binding.instance;
  if (typeof handler === 'function') {
    state.handler = handler;
  } else {
    state.handler = null;
    warn(`el valor de la directiva debe ser una función y es ${handler === null ? 'null' : typeof handler}.`);
  }
  arm(state);
}

function mounted(el, binding) {
  if (states.has(el)) unmounted(el); // nunca dos listeners para el mismo elemento
  const state = { handler: null, context: null, armed: false, timer: null, listener: null };
  state.listener = (event) => {
    if (!state.armed || !state.handler || el.contains(event.target)) return undefined;
    return state.handler.call(state.context, event);
  };
  states.set(el, state);
  configure(state, binding);
  document.documentElement.addEventListener('click', state.listener, false);
}

function updated(el, binding) {
  const state = states.get(el);
  if (!state) return mounted(el, binding);
  if (binding.value === binding.oldValue) return undefined;
  configure(state, binding); // mismo listener, handler nuevo, guarda rearmada
  return undefined;
}

function unmounted(el) {
  const state = states.get(el);
  if (!state) return;
  if (state.timer) clearTimeout(state.timer);
  document.documentElement.removeEventListener('click', state.listener, false);
  states.delete(el);
}

export const clickaway = { mounted, updated, unmounted };
export default clickaway;
