// Formularios sobre BootstrapVueNext (fases 2 y 5B). Contrato de BootstrapVue 2 MEDIDO en navegador (tests/e2e/specs/33-forms-parity.spec.js: la
// misma plantilla sobre BV2 y BVN, con el orden de eventos, el tipo JS del valor y el momento en que cambia el modelo):
//   - `@input` / `@change` reciben el VALOR (cadena en los campos de texto, valor tipado en select/casilla/radio), no el `Event` nativo de BVN.
//     Campos de texto: `input` en cada pulsación con el texto tal cual (aunque el modelo lleve `.trim` / `.number`); `change` al perder el foco.
//     Select / casilla / radio: `input(valor)` → el modelo cambia → `change(valor)` en el siguiente tick (BV2 lo emitía tras `$nextTick`, con el
//     `v-model` ya actualizado y los observadores ya ejecutados).
//   - `v-model.trim` (Vue 2 sobre componente): el modelo recibe el valor recortado en cada evento, pero el campo conserva el texto que escribe
//     el usuario (los espacios internos y finales siguen ahí mientras escribe y al salir). BVN recorta el DOM al perder el foco: se implementa
//     aquí y no se le pasa el modificador.
//   - `:value` + `@input` (sin v-model), `unchecked-value` por defecto `false` (BVN: `undefined`).
// Marcado de BS4 que la base y la capa de diseño ya estilan: `custom-select`, `form-group` con fieldset/legend, y en casillas/radios/interruptores
// las clases `px-bvn-check` / `px-bvn-group` que el puente (`bootstrap5/_bridge.scss`) pinta como el `custom-control` de BS4.
import { h } from 'vue';
import { pure, toList } from './core.js';
import { BFormGroup as _BFormGroup } from 'bootstrap-vue-next/components/BFormGroup';
import { BFormInput as _BFormInput } from 'bootstrap-vue-next/components/BFormInput';
import { BFormTextarea as _BFormTextarea } from 'bootstrap-vue-next/components/BFormTextarea';
import { BForm as _BForm, BFormInvalidFeedback as _BFormInvalidFeedback, BFormValidFeedback as _BFormValidFeedback, BFormText as _BFormText, BFormRow as _BFormRow } from 'bootstrap-vue-next/components/BForm';
import { BInputGroup as _BInputGroup, BInputGroupText as _BInputGroupText } from 'bootstrap-vue-next/components/BInputGroup';

// ---------------------------------------------------------------------------------------------------------------------------------
// Contenedores y mensajes: sin traducción de contrato (solo MODE 3).
// ---------------------------------------------------------------------------------------------------------------------------------
export const BForm = /*#__PURE__*/ pure(_BForm);
export const BFormRow = /*#__PURE__*/ pure(_BFormRow);
export const BFormText = /*#__PURE__*/ pure(_BFormText);
export const BFormInvalidFeedback = /*#__PURE__*/ pure(_BFormInvalidFeedback);
export const BFormValidFeedback = /*#__PURE__*/ pure(_BFormValidFeedback);
export const BInputGroupText = /*#__PURE__*/ pure(_BInputGroupText);

// Grupo de entrada. BV2 pintaba `prepend` / `append` (props) como `div.input-group-prepend|append > div.input-group-text`, y el tema, la hoja RTL y el CSS
// de muchas vistas apuntan a esas clases (`.input-group > .input-group-append > .btn`…); BootstrapVueNext pone `span.input-group-text` directo. Se emite
// el marcado de BV2 (las vistas escriben además `div.input-group-prepend|append` planos). Desaparece con el corte a BS5 (fase 5C).
export const BInputGroup = /*#__PURE__*/ pure({
  name: 'BInputGroup',
  inheritAttrs: false,
  props: { prepend: { type: [String, Number], default: undefined }, append: { type: [String, Number], default: undefined } },
  setup(props, { attrs, slots }) {
    const addon = (kind, text) => h('div', { class: `input-group-${kind}` }, [h('div', { class: 'input-group-text' }, String(text))]);
    return () => h(_BInputGroup, attrs, {
      default: () => [
        props.prepend !== undefined && props.prepend !== '' ? addon('prepend', props.prepend) : null,
        ...(slots.default ? slots.default() : []),
        props.append !== undefined && props.append !== '' ? addon('append', props.append) : null,
      ],
    });
  },
});

// BootstrapVue 2 sin `label-for` pintaba `fieldset.form-group > legend.col-form-label.pt-0`, marcado que la capa de diseño ya estila (también dentro
// de modales). BootstrapVueNext localizaría el input hijo y emitiría `label.form-label`; `label-for=""` (no es nulo) mantiene el marcado de BV2.
export const BFormGroup = /*#__PURE__*/ pure({
  name: 'BFormGroup',
  inheritAttrs: false,
  setup(_props, { attrs, slots }) {
    return () => {
      const explicit = 'label-for' in attrs || 'labelFor' in attrs;
      return h(_BFormGroup, { ...attrs, ...(explicit ? {} : { labelFor: '' }), class: [attrs.class, 'form-group'] }, slots);
    };
  },
});

// ---------------------------------------------------------------------------------------------------------------------------------
// Campos de texto (input / textarea)
// ---------------------------------------------------------------------------------------------------------------------------------
const textControl = (name, Component) => pure({
  name,
  inheritAttrs: false,
  setup(_props, { attrs, slots }) {
    return () => {
      const { onInput, onChange, modelModifiers, value, 'onUpdate:modelValue': onUpdate, ...rest } = attrs;
      const props = { ...rest };
      const trim = !!(modelModifiers && modelModifiers.trim);
      if (modelModifiers) {
        const { trim: _trim, ...others } = modelModifiers;
        props.modelModifiers = others;
      }
      // BV2: `:value` (una vía) + `@input`
      if (props.modelValue === undefined && value !== undefined) props.modelValue = value;
      if (onUpdate) {
        props['onUpdate:modelValue'] = trim
          ? (v) => toList(onUpdate).forEach((f) => f(typeof v === 'string' ? v.trim() : v))
          : onUpdate;
      }
      if (onInput) props.onInput = (event) => toList(onInput).forEach((f) => f(event && event.target ? event.target.value : event));
      if (onChange) props.onChange = (event) => toList(onChange).forEach((f) => f(event && event.target ? event.target.value : event));
      return h(Component, props, slots);
    };
  },
});
export const BFormInput = /*#__PURE__*/ textControl('BFormInput', _BFormInput);
export const BFormTextarea = /*#__PURE__*/ textControl('BFormTextarea', _BFormTextarea);

