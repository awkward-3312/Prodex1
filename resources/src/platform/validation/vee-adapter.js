// Único módulo del frontend que importa vee-validate. Las vistas usan <px-validation-provider> y <px-validation-observer>
// (contrato independiente de la librería en ./contract.js); al cambiar de librería de validación solo se sustituye este archivo.
//
// vee-validate 4 no tiene componentes de clase heredables como los de la versión 3 (`ValidationProvider`/`ValidationObserver`
// extends-ables); su forma nativa es `<Field v-model>` / `<Form>`. PRODEX envuelve el control real tal cual estaba en el
// marcado (`<b-form-input v-model="x">` dentro del slot por defecto), así que aquí se reimplementa el mismo contrato
// observable de la versión 3 sobre las funciones de composición de la 4 (`useField`, `useForm`), reutilizando la
// detección de campos por VNode de `./vee-field-bridge.js` (independiente de la librería).
import { defineComponent, computed, h, provide, inject, ref, nextTick } from 'vue';
import { useField, useForm, useFormContext, defineRule, configure } from 'vee-validate';
import * as veeRules from '@vee-validate/rules';
import { debounce, deepEqual, processVNodes, addListener, flatten } from './vee-field-bridge.js';

// El observer avisa a sus providers de cada `reset()` con este token (no hay registro directo de campos, como en
// vee-validate 3). Sirve para descartar una validación que ya estaba en curso cuando llegó el reset (ver más abajo).
const RESET_TOKEN = Symbol('pxValidationResetToken');

// Sincroniza el valor inicial de TODOS los providers que monten en el mismo tick en un solo `nextTick`, no uno por
// campo. Cada sync inicial muta `formValues` (una dependencia de `form.meta`); si cada provider programa su propio
// `nextTick`, un formulario con muchos campos (60+ en Add_product.vue) encadena esa cantidad de re-renders
// COMPLETOS del formulario uno detrás de otro (cada `nextTick` ve el DOM que dejó el anterior) y la página se queda
// sin responder minutos enteros re-diffing listas largas (`patchKeyedChildren`) una y otra vez. Con un solo flush
// se aplican todos los valores de golpe y el formulario solo se re-renderiza una vez.
let pendingInitialSyncs = [];
let initialSyncFlushScheduled = false;
// Un formulario con muchos campos (Add_product.vue, 60) hecho de una sola vez sigue siendo una ráfaga larga de
// trabajo síncrono (cada sync son un `field.validate()` interno de vee-validate y un re-render del `<b-form-group>`
// que lo envuelve); en un navegador bajo presión eso puede tardar segundos en vez de milisegundos. Se reparte en
// tandas pequeñas entre `nextTick`s sucesivos: cada tanda sigue resolviendo TODA la mutación de golpe (nada de
// recursión durante el render, la razón original del batch), pero dan más oportunidades de que el hilo respire.
const INITIAL_SYNC_CHUNK = 12;
function scheduleInitialSync(fn) {
  pendingInitialSyncs.push(fn);
  if (initialSyncFlushScheduled) return;
  initialSyncFlushScheduled = true;
  const flushChunk = () => {
    const chunk = pendingInitialSyncs.splice(0, INITIAL_SYNC_CHUNK);
    chunk.forEach((run) => run());
    if (pendingInitialSyncs.length) nextTick(flushChunk);
    else initialSyncFlushScheduled = false;
  };
  nextTick(flushChunk);
}

const MESSAGES_ES = {
  required: 'Este campo es obligatorio',
  required_if: 'Este campo es obligatorio',
  regex: 'Este campo debe tener un formato válido',
  mimes: 'Este archivo debe tener un tipo válido',
  size: (ctx) => `El tamaño del archivo debe ser menor de ${param(ctx, 'size')}`,
  min: (ctx) => `Este campo debe tener al menos ${param(ctx, 'length')} caracteres`,
  max: (ctx) => `Este campo no puede tener más de ${param(ctx, 'length')} caracteres`,
  confirmed: 'Este campo no coincide',
  email: 'Este campo debe ser un correo electrónico válido',
};

function param(ctx, key) {
  const p = ctx.rule && ctx.rule.params;
  if (p == null) return '';
  if (Array.isArray(p)) return p[0];
  return p[key] ?? Object.values(p)[0];
}

const urlRule = (value) => {
  if (!value) return false;
  try {
    const parsed = new URL(value);
    return parsed.protocol === 'http:' || parsed.protocol === 'https:';
  } catch (e) {
    return false;
  }
};

let uid = 0;

/** true si `rules` (string "required|min:3" u objeto { required: true, min: 3 }) exige el campo. */
function parseRequired(rules) {
  if (!rules) return false;
  if (typeof rules === 'string') return /(^|\|)required(_if)?(:|$|\|)/.test(rules);
  if (typeof rules === 'object') {
    if ('required' in rules) return rules.required !== false;
    if ('required_if' in rules) return Boolean(rules.required_if);
  }
  return false;
}

function fieldFlags(meta, validated) {
  return {
    valid: meta.valid,
    invalid: !meta.valid,
    dirty: meta.dirty,
    pristine: !meta.dirty,
    touched: meta.touched,
    untouched: !meta.touched,
    pending: meta.pending,
    validated,
    passed: validated && meta.valid,
    failed: validated && !meta.valid,
    changed: meta.dirty,
  };
}

/**
 * `<px-validation-provider name="..." rules="required" v-slot="{ errors, valid, ... }">`: envuelve UN control (lo
 * detecta con `./vee-field-bridge.js`, incluso a través del slot de un componente hijo como `<b-form-group>`/`<px-field>`)
 * y expone el mismo contrato observable que la versión 3 (ver `contract.js`).
 */
export const PxValidationProvider = defineComponent({
  name: 'PxValidationProvider',
  props: {
    name: { type: String, default: undefined },
    vid: { type: String, default: undefined },
    rules: { type: [String, Object], default: '' },
    immediate: { type: Boolean, default: false },
    bails: { type: Boolean, default: true },
    debounce: { type: Number, default: 0 },
    tag: { type: String, default: 'span' },
    slim: { type: Boolean, default: false },
  },
  setup(props, { slots, expose }) {
    const anonId = `field_${++uid}`;
    const fieldName = computed(() => props.vid || props.name || anonId);
    const rulesRef = computed(() => props.rules);
    const field = useField(fieldName, rulesRef, {
      validateOnValueUpdate: false,
      validateOnMount: props.immediate,
      bails: props.bails,
      label: props.name,
    });
    // `field.meta.validated` es del propio vee-validate 4: se pone a `true` tanto si valida el campo (aquí abajo)
    // como si valida el formulario entero (`observer.validate()`) o se le ponen errores de servidor — igual que el
    // flag `validated` de vee-validate 3, del que dependen vistas como VField (`v.touched || v.validated`).
    let mounted = false;
    let initialSyncPending = false;
    let initialFieldValue;
    const observerResetToken = inject(RESET_TOKEN, null);
    const enclosingForm = useFormContext();
    let ownOpToken = 0;
    let lastManualErrors = null; // último `setErrors(...)` recibido, para reaplicarlo si una validación tardía lo pisa

    // Si el observer (o el propio provider) resetea el formulario, o alguien llama a `setErrors`/`validate` de forma
    // manual, MIENTRAS esta validación automática está en curso (promesa aún sin resolver), el resultado llega tarde
    // y pisaría el estado que esa llamada ya dejó — al volver, si algún token cambió mientras tanto, se corrige en
    // lugar de aplicar un resultado obsoleto.
    const runValidate = debounce(() => {
      const startObserverToken = observerResetToken ? observerResetToken.value : null;
      const startOwnToken = ownOpToken;
      field.validate().then(() => {
        const staleByObserver = observerResetToken && observerResetToken.value !== startObserverToken;
        const staleByOwn = ownOpToken !== startOwnToken;
        if (staleByObserver) field.resetField({ value: initialFieldValue });
        else if (staleByOwn && lastManualErrors) field.setErrors(lastManualErrors);
      });
    }, props.debounce);

    // Igual que vee-validate 3: el primer valor visto (al montar) fija el estado inicial sin disparar una validación,
    // salvo que `immediate` lo pida; solo un cambio POSTERIOR (el usuario escribe) dispara `runValidate`. La
    // comparación es contra `field.value.value` (no una copia propia): así un reset disparado por el observer —
    // que ya deja `field.value.value` en el valor inicial antes de que el control vuelva a renderizar — no se lee
    // como "el usuario cambió el campo" y no reabre el mensaje de error que el propio reset acaba de limpiar.
    function onFieldValue(newValue, isInitial) {
      const changed = !deepEqual(field.value.value, newValue);
      // Solo se escribe cuando de verdad cambia el CONTENIDO: un array/objeto "igual" pero con otra referencia (p. ej.
      // un multi-select recalculando sus opciones en cada render) igual dispara la reactividad de vee-validate —
      // reescribirlo sin necesidad realimenta un ciclo: este campo se revalida → el formulario se re-renderiza → el
      // control vuelve a emitir un valor "igual" con otra referencia → se vuelve a escribir →… ("Maximum recursive
      // updates exceeded"), visto en el multi-select de categorías.
      if (changed) field.value.value = newValue;
      if (isInitial) { if (changed && props.immediate) runValidate(); return; }
      if (!changed) return;
      ownOpToken += 1;
      lastManualErrors = null;
      runValidate();
    }

    function bindField(vnode, described) {
      const isCheckable = described.checkable;
      // vee-validate no conoce el valor inicial real: `useField` se llama sin él (se detecta más tarde, aquí, en el
      // primer VNode visto). Sin esto, un `observer.reset()` (que usa el "valor inicial" que la FORM recuerda para
      // cada campo) lo dejaría en `undefined` en vez del valor real con el que arrancó el control.
      //
      // Diferido a `nextTick`: escribir el valor de un campo del formulario (`field.value.value = x`) muta
      // `formValues`, una dependencia de `form.meta` — y el observer YA leyó `form.meta.value` al empezar A
      // RENDERIZAR, antes de invocar el slot que (a través de cada provider) acaba llamando a `bindField`. En un
      // formulario con muchos campos, esa mutación durante el propio render del observer lo reprograma sobre sí
      // mismo una vez por campo y Vue lo corta como "Maximum recursive updates exceeded"; fuera del render no pasa.
      if (mounted) {
        onFieldValue(described.value, false);
      } else if (!initialSyncPending) {
        // Solo el primer render de todos los que puedan llegar antes de que corra el `nextTick` programa el sync
        // inicial; `mounted` no se marca hasta que de verdad corre — así un render intermedio (p. ej. disparado por
        // OTRO campo del mismo formulario) sigue viendo "sin montar todavía" en vez de tratar el valor real (que
        // aún no se ha escrito) como si fuera un cambio del usuario.
        initialSyncPending = true;
        initialFieldValue = described.value;
        scheduleInitialSync(() => {
          mounted = true;
          initialSyncPending = false;
          onFieldValue(initialFieldValue, true);
          if (enclosingForm) enclosingForm.stageInitialValue(fieldName.value, initialFieldValue, true);
        });
      }
      const onInput = (e) => {
        const raw = e && typeof e === 'object' && 'target' in e ? (isCheckable ? e.target.checked : e.target.value) : e;
        onFieldValue(raw);
      };
      const onBlur = () => field.handleBlur();
      addListener(vnode, described.event, onInput);
      addListener(vnode, 'blur', onBlur);
    }

    function validate(value) {
      ownOpToken += 1;
      lastManualErrors = null;
      if (arguments.length) field.value.value = value;
      return field.validate().then((r) => ({ valid: r.valid, errors: r.errors }));
    }
    // API interna de vee-validate 3 (no documentada en contract.js, pero usada directamente por ~30 vistas: dan
    // por hecho que `$refs.xProvider.syncValue(valor)` existe para empujar un valor sin esperar a que la
    // detección de VNodes lo vea — típicamente antes de llamar a `.validate()` a mano). Es lo mismo que ve el
    // provider cuando el propio control cambia.
    function syncValue(value) {
      onFieldValue(value);
    }
    function reset() {
      ownOpToken += 1;
      lastManualErrors = null;
      field.resetField({ value: initialFieldValue });
    }
    function setErrors(errors) {
      ownOpToken += 1;
      lastManualErrors = errors && errors.length ? errors : null;
      field.setErrors(errors || []);
    }

    // `field.meta.valid` puede quedar un tick por detrás de `field.errors` (vee-validate 4 lo recalcula de forma
    // asíncrona tras un `setErrors` manual, sin pasar por nuestro `runValidate`); `errors.length === 0` es la fuente
    // de verdad inmediata y coincide con lo que de verdad se pinta (el mensaje bajo el campo).
    function metaFor() {
      const valid = field.errors.value.length === 0;
      return { ...field.meta, valid };
    }

    expose({ validate, reset, setErrors, syncValue, get errors() { return field.errors.value; }, get flags() { return fieldFlags(metaFor(), field.meta.validated); } });

    return () => {
      const meta = metaFor();
      const validated = meta.validated;
      const ctx = {
        errors: field.errors.value,
        failedRules: {},
        ...fieldFlags(meta, validated),
        required: parseRequired(props.rules),
        classes: { 'is-valid': validated && meta.valid, 'is-invalid': validated && !meta.valid },
        validate,
        reset,
        ariaInput: {
          'aria-invalid': !meta.valid ? 'true' : 'false',
          'aria-required': parseRequired(props.rules) ? 'true' : 'false',
          'aria-errormessage': `vee_${anonId}`,
        },
        ariaMsg: { id: `vee_${anonId}`, 'aria-live': field.errors.value.length ? 'assertive' : 'off' },
      };
      const slot = slots.default ? slots.default(ctx) : [];
      const children = flatten(slot);
      processVNodes(children, bindField);
      return props.slim && children.length <= 1 ? children[0] : h(props.tag, children);
    };
  },
});

/**
 * `<px-validation-observer ref="obs" v-slot="{ invalid, handleSubmit }">`: agrupa los `px-validation-provider` que
 * monte su slot por defecto (`useField` se registra solo en el `useForm()` ambiente más cercano).
 */
export const PxValidationObserver = defineComponent({
  name: 'PxValidationObserver',
  props: {
    tag: { type: String, default: 'span' },
    slim: { type: Boolean, default: false },
  },
  setup(props, { slots, expose }) {
    const form = useForm();
    let validated = false;
    const resetToken = ref(0);
    provide(RESET_TOKEN, resetToken);

    function validate() {
      validated = true;
      return form.validate().then((r) => r.valid);
    }
    function reset() {
      validated = false;
      resetToken.value += 1;
      form.resetForm();
    }
    function setErrors(errors) {
      validated = true;
      form.setErrors(errors || {});
    }
    // Contrato de vee-validate 3 y de las plantillas de PRODEX: `<form @submit.prevent="handleSubmit(onValid)">`
    // invoca `handleSubmit(onValid)` como una expresión de una sola vez (Vue descarta lo que devuelva); tiene que
    // validar y, si es válido, llamar a `onValid` de inmediato — no puede limitarse a devolver un manejador para más
    // tarde, como hace `useForm().handleSubmit` de vee-validate 4 (pensado para `@submit="handleSubmit(onValid)"`).
    function handleSubmit(onValid, onInvalid) {
      validated = true;
      return form.validate().then((r) => {
        if (r.valid) return onValid && onValid(form.values, { evt: undefined });
        if (onInvalid) onInvalid({ values: form.values, errors: r.errors });
        return undefined;
      });
    }

    expose({ validate, reset, setErrors });

    return () => {
      const meta = form.meta.value;
      const ctx = {
        ...fieldFlags(meta, validated),
        errors: form.errors.value,
        handleSubmit,
        validate,
        reset,
      };
      const slot = slots.default ? slots.default(ctx) : [];
      return props.slim && slot.length <= 1 ? slot[0] : h(props.tag, slot);
    };
  },
});

/**
 * Registra reglas, mensajes y componentes. `legacyAliases` mantiene los nombres globales antiguos
 * (`ValidationProvider` / `ValidationObserver`) para las pantallas que todavía no se migraron
 * (POS, caja, pagos, inventario crítico, facturación).
 */
export function installValidation(Vue, { legacyAliases = true, extraRules = {} } = {}) {
  Object.keys(veeRules).forEach((rule) => {
    if (typeof veeRules[rule] === 'function') defineRule(rule, veeRules[rule]);
  });
  defineRule('url', urlRule);
  Object.keys(extraRules).forEach((rule) => defineRule(rule, extraRules[rule]));

  configure({
    generateMessage: (ctx) => {
      const msg = MESSAGES_ES[ctx.rule.name];
      if (typeof msg === 'function') return msg(ctx);
      if (typeof msg === 'string') return msg;
      return `El campo ${ctx.field || ctx.name} no es válido`;
    },
    validateOnBlur: true,
    validateOnChange: false,
    validateOnInput: false,
    validateOnModelUpdate: false,
  });

  Vue.component('PxValidationProvider', PxValidationProvider);
  Vue.component('PxValidationObserver', PxValidationObserver);
  if (legacyAliases) {
    Vue.component('ValidationProvider', PxValidationProvider);
    Vue.component('ValidationObserver', PxValidationObserver);
  }
}

export { defineRule as extendRule };
