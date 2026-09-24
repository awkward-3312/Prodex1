// Vinculación de campos por inspección de VNodes de Vue 3, independiente de la librería de validación.
//
// PRODEX no usa `<Field v-model="...">` de vee-validate: `<px-validation-provider>` envuelve el control real
// (`<b-form-input v-model="x">`, `<px-field>`…) tal cual estaba en el marcado, y detecta solo ese v-model, incluso
// si vive dentro del slot de otro componente (`<b-form-group>`, `<px-field>`): el slot se envuelve para inspeccionar
// sus VNodes cuando el hijo lo renderiza. Ver `bindField` en `vee-adapter.js` para cómo se conecta cada campo detectado
// a la instancia de campo de la librería de validación (antes vee-validate 3, ahora vee-validate 4).
import { Fragment, Text, Comment, vModelText, vModelCheckbox, vModelRadio, vModelSelect, vModelDynamic } from 'vue';

const MODEL_DIRECTIVES = [vModelText, vModelCheckbox, vModelRadio, vModelSelect, vModelDynamic].filter(Boolean);
const TEXT_TYPES = ['text', 'password', 'search', 'email', 'tel', 'url', 'number'];
const MODEL_COMPAT_PREFIX = 'onModelCompat:';

export const isObject = (v) => v !== null && typeof v === 'object';

export function deepEqual(a, b) {
  if (Object.is(a, b)) return true;
  if (!isObject(a) || !isObject(b)) return false;
  if (Array.isArray(a) !== Array.isArray(b)) return false;
  const ka = Object.keys(a);
  const kb = Object.keys(b);
  if (ka.length !== kb.length) return false;
  return ka.every((k) => deepEqual(a[k], b[k]));
}

export function debounce(fn, wait) {
  if (!wait) return fn;
  let timer = null;
  return function debounced(...args) {
    clearTimeout(timer);
    timer = setTimeout(() => fn.apply(this, args), wait);
  };
}

const handlerKey = (event) => `on${event.charAt(0).toUpperCase()}${event.slice(1)}`;

function addListener(vnode, event, handler) {
  vnode.props = vnode.props || {};
  const key = handlerKey(event);
  const current = vnode.props[key];
  if (current == null) vnode.props[key] = handler;
  else if (Array.isArray(current)) {
    if (!current.includes(handler)) current.push(handler);
  } else if (current !== handler) vnode.props[key] = [current, handler];
}

function nativeModelDirective(vnode) {
  if (typeof vnode.type !== 'string' || !Array.isArray(vnode.dirs)) return null;
  return vnode.dirs.find((d) => d && MODEL_DIRECTIVES.includes(d.dir)) || null;
}

function componentModelConfig(vnode) {
  const type = vnode.type;
  const options = (type && type.options) || type || {};
  return options.model || {};
}

/** Devuelve { value, event, native, checkable } si el VNode es un campo con v-model, o null. */
export function describeField(vnode) {
  const dir = nativeModelDirective(vnode);
  if (dir) {
    const tag = vnode.type;
    const type = vnode.props && vnode.props.type;
    let event = 'change';
    if (tag === 'textarea' || (tag === 'input' && (type == null || TEXT_TYPES.includes(type)))) event = dir.modifiers && dir.modifiers.lazy ? 'change' : 'input';
    return { value: dir.value, event, native: true, checkable: tag === 'input' && (type === 'checkbox' || type === 'radio') };
  }
  if (typeof vnode.type === 'object' || typeof vnode.type === 'function') {
    const props = vnode.props || {};
    const model = componentModelConfig(vnode);
    // Contrato Vue 3 (`v-model` = `modelValue` + `update:modelValue`; BootstrapVueNext, `defineModel`): componentes sin `model` de Vue 2.
    if (!model.prop && !('value' in props) && ('modelValue' in props || 'onUpdate:modelValue' in props)) {
      return { value: props.modelValue, event: 'update:modelValue', native: false, checkable: false };
    }
    const prop = model.prop || 'value';
    const event = model.event || 'input';
    const hasVModel = Object.keys(props).some((k) => k.startsWith(MODEL_COMPAT_PREFIX));
    if (hasVModel || prop in props) return { value: props[prop], event, native: false, checkable: false };
  }
  return null;
}

function eachVNode(list, fn) {
  if (list == null) return;
  if (Array.isArray(list)) {
    list.forEach((v) => eachVNode(v, fn));
    return;
  }
  if (isObject(list)) fn(list);
}

/**
 * Recorre los VNodes de un slot y enlaza el primer campo (y todos los checkbox/radio) mediante `bindField(vnode, field)`.
 * Si un componente hijo recibe el campo en su propio slot, se envuelve ese slot para procesar sus VNodes cuando el
 * hijo lo renderice. Devuelve cuántos campos se enlazaron.
 */
export function processVNodes(list, bindField) {
  let bound = 0;
  const visit = (vnode) => {
    if (!isObject(vnode) || vnode.type === Text || vnode.type === Comment) return;
    const field = vnode.type === Fragment ? null : describeField(vnode);
    if (field) {
      if (bound === 0 || field.checkable) {
        bindField(vnode, field);
        bound += 1;
      }
      return;
    }
    const children = vnode.children;
    if (Array.isArray(children)) {
      children.forEach(visit);
    } else if (isObject(children) && typeof children.default === 'function' && !children.default.__pxVeeWrapped) {
      const original = children.default;
      const wrapped = function wrappedSlot(...args) {
        const out = original.apply(this, args);
        processVNodes(out, bindField);
        return out;
      };
      Object.assign(wrapped, original);
      wrapped.__pxVeeWrapped = true;
      children.default = wrapped;
    }
  };
  eachVNode(list, visit);
  return bound;
}

export { addListener };
export const flatten = (list) => (Array.isArray(list) ? list.flat(Infinity) : list ? [list] : []);
