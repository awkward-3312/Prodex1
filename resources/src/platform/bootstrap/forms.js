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
import { h, nextTick } from 'vue';
import { pure, toList } from './core.js';
import { BFormGroup as _BFormGroup } from 'bootstrap-vue-next/components/BFormGroup';
import { BFormInput as _BFormInput } from 'bootstrap-vue-next/components/BFormInput';
import { BFormTextarea as _BFormTextarea } from 'bootstrap-vue-next/components/BFormTextarea';
import { BFormCheckbox as _BFormCheckbox, BFormCheckboxGroup as _BFormCheckboxGroup } from 'bootstrap-vue-next/components/BFormCheckbox';
import { BFormRadio as _BFormRadio, BFormRadioGroup as _BFormRadioGroup } from 'bootstrap-vue-next/components/BFormRadio';
import { BFormSelect as _BFormSelect, BFormSelectOption as _BFormSelectOption, BFormSelectOptionGroup as _BFormSelectOptionGroup } from 'bootstrap-vue-next/components/BFormSelect';
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
export const BFormSelectOption = /*#__PURE__*/ pure(_BFormSelectOption);
export const BFormSelectOptionGroup = /*#__PURE__*/ pure(_BFormSelectOptionGroup);
export const BInputGroup = /*#__PURE__*/ pure(_BInputGroup);
export const BInputGroupText = /*#__PURE__*/ pure(_BInputGroupText);

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

// ---------------------------------------------------------------------------------------------------------------------------------
// Controles con valor tipado (select, casilla, radio y sus grupos): `input(valor)` → modelo → `change(valor)` en el siguiente tick.
// ---------------------------------------------------------------------------------------------------------------------------------
const typedControl = (name, Component, tune) => pure({
  name,
  inheritAttrs: false,
  setup(_props, { attrs, slots }) {
    return () => {
      const { onInput, onChange, value, 'onUpdate:modelValue': onUpdate, ...rest } = attrs;
      let props = { ...rest };
      if (props.modelValue === undefined && value !== undefined && tune && tune.valueIsModel) props.modelValue = value;
      else if (value !== undefined) props.value = value;
      props['onUpdate:modelValue'] = (v) => {
        toList(onUpdate).forEach((f) => f(v));
        toList(onInput).forEach((f) => f(v));
        if (onChange) nextTick(() => toList(onChange).forEach((f) => f(v)));
      };
      if (tune && tune.props) props = tune.props(props, attrs);
      return h(Component, props, slots);
    };
  },
});

// `custom-select` (+ `-sm` / `-lg`): lo que estila la base BS4 y el tema; BVN emite `form-select`.
export const BFormSelect = /*#__PURE__*/ typedControl('BFormSelect', _BFormSelect, {
  valueIsModel: true,
  props: (props, attrs) => {
    const size = attrs.size === 'sm' || attrs.size === 'lg' ? attrs.size : null;
    return { ...props, class: [attrs.class, 'custom-select', size ? `custom-select-${size}` : null] };
  },
});

// Casilla: BFormCheckbox aplica `class` al contenedor; la marca `px-bvn-check` va en el input (`inputClass`). `unchecked-value` = `false` por
// defecto (BV2). El valor marcado es `value` (`true` por defecto); BV2 ignora `checked-value`, BVN también: el atributo pasa tal cual.
export const BFormCheckbox = /*#__PURE__*/ typedControl('BFormCheckbox', _BFormCheckbox, {
  props: (props, attrs) => ({
    ...props,
    ...(props.uncheckedValue === undefined && attrs['unchecked-value'] === undefined ? { uncheckedValue: false } : {}),
    inputClass: [attrs.inputClass, attrs['input-class'], 'px-bvn-check'],
  }),
});
// Radio: BFormRadio aplica `class` al <input>.
export const BFormRadio = /*#__PURE__*/ typedControl('BFormRadio', _BFormRadio, {
  props: (props, attrs) => ({ ...props, class: [attrs.class, 'px-bvn-check'] }),
});
// Grupos: el contenedor lleva `px-bvn-group` (BVN crea las casillas por dentro, sin pasar por estos wrappers).
export const BFormCheckboxGroup = /*#__PURE__*/ typedControl('BFormCheckboxGroup', _BFormCheckboxGroup, {
  props: (props, attrs) => ({ ...props, class: [attrs.class, 'px-bvn-group'] }),
});
export const BFormRadioGroup = /*#__PURE__*/ typedControl('BFormRadioGroup', _BFormRadioGroup, {
  props: (props, attrs) => ({ ...props, class: [attrs.class, 'px-bvn-group'] }),
});
