// SOLO @vue/compat (Vue 3). vee-validate 3 detecta el campo recorriendo los VNodes de Vue 2 (`vnode.data.model`,
// `vnode.componentOptions.Ctor.options.model`, `data.on`, `componentOptions.listeners`, y los hijos de un componente en
// `componentOptions.children`). Con VNodes de Vue 3 nada de eso existe: `findModelConfig` lanza una excepción o, peor, no
// encuentra ningún campo y la validación no se enlaza NUNCA (fallo silencioso).
//
// Este `render` reemplaza SOLO esa parte de ValidationProvider. Todo lo demás (props, estado, flags, reglas, mensajes,
// observer, validate/reset/setErrors, slot props) se hereda del componente original. Reproduce el algoritmo de vee-validate:
//   1. llama al slot por defecto con los slot props (errors, valid, ...);
//   2. localiza el campo con v-model: <input|select|textarea v-model> (directiva model) o un componente con la prop del
//      `model` de Vue 2 (`value`) / evento `onModelCompat:*` — incluso si está dentro del slot de otro componente
//      (<b-form-group>, <px-field>): el slot se envuelve para inspeccionar sus VNodes cuando el hijo lo renderiza;
//   3. añade los listeners de vee-validate (input/change, blur y los del modo de validación) como `onXxx` en los props.
import { Fragment, Text, Comment, onMounted, vModelText, vModelCheckbox, vModelRadio, vModelSelect, vModelDynamic } from 'vue';

const MODEL_DIRECTIVES = [vModelText, vModelCheckbox, vModelRadio, vModelSelect, vModelDynamic].filter(Boolean);
const TEXT_TYPES = ['text', 'password', 'search', 'email', 'tel', 'url', 'number'];
const MODEL_COMPAT_PREFIX = 'onModelCompat:';

const isObject = (v) => v !== null && typeof v === 'object';

function deepEqual(a, b) {
  if (Object.is(a, b)) return true;
  if (!isObject(a) || !isObject(b)) return false;
  if (Array.isArray(a) !== Array.isArray(b)) return false;
  const ka = Object.keys(a);
  const kb = Object.keys(b);
  if (ka.length !== kb.length) return false;
  return ka.every((k) => deepEqual(a[k], b[k]));
}

function debounce(fn, wait) {
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

/** Devuelve { value, event } si el VNode es un campo con v-model, o null. */
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

function shouldValidate(vm, value) {
  if (!vm._ignoreImmediate && vm.immediate) return true;
  if (!deepEqual(vm.value, value) && vm.normalizedEvents.length) return true;
  if (vm._needsValidation) return true;
  if (!vm.initialized && value === undefined) return true;
  return false;
}

function triggerThreadSafeValidation(vm) {
  const pending = vm.validateSilent();
  vm._pendingValidation = pending;
  return pending.then((result) => {
    if (pending === vm._pendingValidation) {
      vm.applyResult(result);
      vm._pendingValidation = undefined;
    }
    return result;
  });
}

function onRenderUpdate(vm, value) {
  if (!vm.initialized) vm.initialValue = value;
  const validateNow = shouldValidate(vm, value);
  vm._needsValidation = false;
  if (!deepEqual(vm.value, value)) vm.value = value;
  vm._ignoreImmediate = true;
  if (!validateNow) return;
  const validate = () => {
    if (vm.immediate || vm.flags.validated) return triggerThreadSafeValidation(vm);
    vm.validateSilent();
    return undefined;
  };
  if (vm.initialized) {
    validate();
    return;
  }
  if (vm.$ && vm.$.isMounted) validate();
  else onMounted(() => validate(), vm.$);
}

function commonHandlers(vm) {
  if (!vm.$veeOnInput) {
    vm.$veeOnInput = (e) => {
      vm.syncValue(e);
      vm.setFlags({ dirty: true, pristine: false });
    };
  }
  if (!vm.$veeOnBlur) vm.$veeOnBlur = () => vm.setFlags({ touched: true, untouched: false });
  if (!vm.$veeHandler || vm.$veeDebounce !== vm.debounce) {
    vm.$veeHandler = debounce(() => {
      vm.$nextTick(() => {
        if (!vm._pendingReset) triggerThreadSafeValidation(vm);
        vm._pendingReset = false;
      });
    }, vm.debounce);
    vm.$veeDebounce = vm.debounce;
  }
  return { onInput: vm.$veeOnInput, onBlur: vm.$veeOnBlur, onValidate: vm.$veeHandler };
}

// vee-validate 3 escribe el estado del campo (value, initialValue, initialized, flags) DENTRO de `render`. Vue 2 lo tolera; Vue 3
// lo trata como un bucle de actualizaciones ("Maximum recursive updates exceeded") cuando el campo se procesa mientras
// se renderiza el slot de un componente hijo. La escritura se aplaza a una microtarea (justo después del render).
function scheduleRenderUpdate(vm, value) {
  vm.__v_pxPendingValue = { value };
  if (vm.__v_pxScheduled) return;
  vm.__v_pxScheduled = true;
  Promise.resolve().then(() => {
    vm.__v_pxScheduled = false;
    const pending = vm.__v_pxPendingValue;
    if (!pending) return;
    vm.__v_pxPendingValue = null;
    onRenderUpdate(vm, pending.value);
    if (!vm.initialized) vm.initialized = true;
  });
}

function bindField(vm, vnode, field) {
  vm._inputEventName = vm._inputEventName || field.event;
  scheduleRenderUpdate(vm, field.value);
  const { onInput, onBlur, onValidate } = commonHandlers(vm);
  addListener(vnode, vm._inputEventName, onInput);
  addListener(vnode, 'blur', onBlur);
  vm.normalizedEvents.forEach((evt) => addListener(vnode, evt, onValidate));
}

/**
 * Recorre los VNodes de un slot y enlaza el primer campo (y todos los checkbox/radio). Si un componente hijo recibe el campo en
 * su propio slot, se envuelve ese slot para procesar sus VNodes cuando el hijo lo renderice.
 */
function processVNodes(vm, list) {
  let bound = 0;
  const visit = (vnode) => {
    if (!isObject(vnode) || vnode.type === Text || vnode.type === Comment) return;
    const field = vnode.type === Fragment ? null : describeField(vnode);
    if (field) {
      if (bound === 0 || field.checkable) {
        bindField(vm, vnode, field);
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
        processVNodes(vm, out);
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

function validationContext(vm) {
  return {
    ...vm.flags,
    errors: vm.errors,
    classes: vm.classes,
    failedRules: vm.failedRules,
    reset: () => vm.reset(),
    validate: (...args) => vm.validate(...args),
    ariaInput: {
      'aria-invalid': vm.flags.invalid ? 'true' : 'false',
      'aria-required': vm.isRequired ? 'true' : 'false',
      'aria-errormessage': `vee_${vm.id}`,
    },
    ariaMsg: { id: `vee_${vm.id}`, 'aria-live': vm.errors.length ? 'assertive' : 'off' },
  };
}

function slotOutput(vm, ctx) {
  // Slots reales de Vue 3 (`instance.slots`): evita `$scopedSlots` / `$slots` de compat, que avisan y devuelven arrays de Vue 2.
  const slot = vm.$ && vm.$.slots && vm.$.slots.default;
  return (typeof slot === 'function' ? slot(ctx) : null) || [];
}

const flatten = (list) => (Array.isArray(list) ? list.flat(Infinity) : list ? [list] : []);

/** `render` de ValidationProvider para VNodes de Vue 3 (ver cabecera). */
export function compatProviderRender(h) {
  this.registerField();
  const ctx = validationContext(this);
  const children = flatten(slotOutput(this, ctx));
  if (this.detectInput) processVNodes(this, children);
  return this.slim && children.length <= 1 ? children[0] : h(this.tag, children);
}
