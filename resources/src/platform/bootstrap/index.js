// BootstrapVueNext dentro de PRODEX (migración por familias; ver docs/architecture/BOOTSTRAP5_BOOTSTRAPVUE_NEXT_PHASE1.md).
//
// Regla de uso: las vistas importan los componentes DESDE AQUÍ (nunca de `bootstrap-vue-next` directamente ni por registro
// global) para que BootstrapVue 2 (global, `b-*`) y BootstrapVueNext convivan sin choques de nombres: un componente importado
// localmente (`components: { BButton }`) gana al global `BButton` de BootstrapVue 2 solo en esa vista.
//
// BootstrapVueNext es Vue 3 puro. Bajo @vue/compat MODE 2 hay que excluirlo de los comportamientos de Vue 2
// (`v-model` value/input, class/style de atributos, `$listeners`…): cada componente exportado (y los que usa por dentro) se marca
// `compatConfig: { MODE: 3 }`. Es configuración del propio componente, no un parche de su lógica; desaparece al quitar compat.
import { h } from 'vue';
import { createBootstrap } from 'bootstrap-vue-next/plugins';
import { vBTooltip as _vBTooltip } from 'bootstrap-vue-next/directives';
import { BButton as _BButton, BCloseButton } from 'bootstrap-vue-next/components/BButton';
import { BBadge as _BBadge } from 'bootstrap-vue-next/components/BBadge';
import { BAlert as _BAlert } from 'bootstrap-vue-next/components/BAlert';
import { BSpinner as _BSpinner } from 'bootstrap-vue-next/components/BSpinner';
import { BLink } from 'bootstrap-vue-next/components/BLink';
import { BContainer as _BContainer, BRow as _BRow, BCol as _BCol } from 'bootstrap-vue-next/components/BContainer';
import {
  BCard as _BCard, BCardBody as _BCardBody, BCardHeader as _BCardHeader, BCardFooter as _BCardFooter,
  BCardText as _BCardText, BCardTitle as _BCardTitle, BCardSubtitle as _BCardSubtitle,
} from 'bootstrap-vue-next/components/BCard';
import { BProgress, BProgressBar } from 'bootstrap-vue-next/components/BProgress';
import { BFormGroup as _BFormGroup } from 'bootstrap-vue-next/components/BFormGroup';
import { BFormInput as _BFormInput } from 'bootstrap-vue-next/components/BFormInput';
import { BFormTextarea as _BFormTextarea } from 'bootstrap-vue-next/components/BFormTextarea';
import { BFormCheckbox as _BFormCheckbox } from 'bootstrap-vue-next/components/BFormCheckbox';
import { BFormRadio as _BFormRadio } from 'bootstrap-vue-next/components/BFormRadio';
import { BFormSelect as _BFormSelect, BFormSelectOption as _BFormSelectOption } from 'bootstrap-vue-next/components/BFormSelect';
import { BFormInvalidFeedback as _BFormInvalidFeedback } from 'bootstrap-vue-next/components/BForm';

const VUE3 = { MODE: 3 };

function pure(component) {
  if (component && !component.compatConfig) component.compatConfig = VUE3;
  return component;
}

// Los componentes internos de la familia (BLink, BCloseButton, BProgress…) también deben quedar en MODE 3.
[BLink, BCloseButton, BProgress, BProgressBar].forEach(pure);

export const BAlert = pure(_BAlert);
export const BSpinner = pure(_BSpinner);
export const BContainer = pure(_BContainer);
export const BRow = pure(_BRow);
export const BCol = pure(_BCol);
export const BCard = pure(_BCard);
export const BCardBody = pure(_BCardBody);
export const BCardHeader = pure(_BCardHeader);
export const BCardFooter = pure(_BCardFooter);
export const BCardText = pure(_BCardText);
export const BCardTitle = pure(_BCardTitle);
export const BCardSubtitle = pure(_BCardSubtitle);

// Wrappers PRODEX (vista → wrapper → BootstrapVueNext) que conservan el marcado que la base Bootstrap 4 y la capa de diseño PRODEX
// ya estilan, para que migrar de familia no cambie el aspecto:
//  - BButton: BootstrapVueNext eliminó la prop `block` (BS5: `w-100` / `d-grid`); se conserva como clase `btn-block` de BS4.
//  - BBadge: la capa de diseño (`_interactions.scss`) y el color del tenant (`config.js`) estilan `.badge-<variante>`; se emite esa clase
//    (sin pasar `variant` al componente) en lugar de `text-bg-*`. Al cortar a BS5 se cambia aquí, no en las vistas.
const wrapper = (name, component, extraProps, extraClass) => pure({
  name,
  inheritAttrs: false,
  props: extraProps,
  setup(props, { attrs, slots }) {
    return () => h(component, { ...attrs, ...extraClass(props, attrs) }, slots);
  },
});

export const BButton = wrapper('BButton', _BButton, { block: { type: Boolean, default: false } }, (props, attrs) => ({
  class: [attrs.class, props.block ? 'btn-block' : null],
}));

export const BBadge = wrapper('BBadge', _BBadge, { variant: { type: String, default: 'secondary' } }, (props, attrs) => ({
  variant: null,
  class: [`badge-${props.variant}`, attrs.class],
}));

// ---------------------------------------------------------------------------------------------------------------------------------
// Formularios simples (fase 2). Contrato Vue 3 explícito, SIN adaptador de eventos de Vue 2:
//   - `v-model` = `modelValue` + `update:modelValue` (modificadores `.trim` / `.number` vía `modelModifiers`, los implementa BVN).
//   - `@input` / `@change` sobre b-form-input / b-form-select / b-form-textarea / b-form-checkbox son los eventos NATIVOS del
//     elemento (reciben un `Event`), no el valor que emitía BootstrapVue 2: las vistas que necesitan el valor usan
//     `@update:model-value` o leen el modelo.
//   - no hay props `value`, `checked`, `trim`, `number`, `lazy` (BV2): `:model-value`, `.trim`, `.number`, `.lazy`.
//   - BFormInput / BFormTextarea / BFormInvalidFeedback / BFormSelectOption: solo MODE 3 (marcado ya idéntico).
//   - BFormGroup: clase `form-group` (margen inferior de BS4) y `label-for=""` por defecto (fieldset + legend, como BV2).
//   - BFormSelect: se añade `custom-select` (+ `-sm` / `-lg`), que es lo que estila la base BS4 y el tema; BVN emite `form-select`.
//   - BFormCheckbox / BFormRadio: clase `px-bvn-check` en el input para que el puente pinte la casilla/radio como el `custom-control` de BS4.
// Quitar estas clases cuando la hoja base pase a Bootstrap 5.
// ---------------------------------------------------------------------------------------------------------------------------------
export const BFormGroup = pure({
  name: 'BFormGroup',
  inheritAttrs: false,
  setup(_props, { attrs, slots }) {
    // BootstrapVue 2 sin `label-for` pintaba `fieldset.form-group > legend.col-form-label.pt-0`, marcado que la capa de diseño ya estila
    // (también dentro de modales). BootstrapVueNext localizaría el input hijo y emitiría `label.form-label`; `label-for=""` (no es
    // nulo) mantiene el marcado de BV2. Si la vista pasa `label-for`, se respeta.
    return () => {
      const explicit = 'label-for' in attrs || 'labelFor' in attrs;
      return h(_BFormGroup, { ...attrs, ...(explicit ? {} : { labelFor: '' }), class: [attrs.class, 'form-group'] }, slots);
    };
  },
});
export const BFormInput = pure(_BFormInput);
export const BFormTextarea = pure(_BFormTextarea);
export const BFormInvalidFeedback = pure(_BFormInvalidFeedback);
export const BFormSelectOption = pure(_BFormSelectOption);

export const BFormSelect = pure({
  name: 'BFormSelect',
  inheritAttrs: false,
  setup(_props, { attrs, slots }) {
    return () => {
      const size = attrs.size === 'sm' || attrs.size === 'lg' ? attrs.size : null;
      return h(_BFormSelect, { ...attrs, class: [attrs.class, 'custom-select', size ? `custom-select-${size}` : null] }, slots);
    };
  },
});

// BFormRadio aplica `class` al <input>; BFormCheckbox, al contenedor. Para tener el mismo gancho en ambos, la marca va SIEMPRE en el
// input (`inputClass` en la casilla, `class` en el radio) y el puente localiza el contenedor con `:has(> .px-bvn-check)`.
const checkWrapper = (name, component, mark) => pure({
  name,
  inheritAttrs: false,
  setup(_props, { attrs, slots }) {
    return () => h(component, mark(attrs), slots);
  },
});
export const BFormCheckbox = checkWrapper('BFormCheckbox', _BFormCheckbox, (attrs) => ({ ...attrs, inputClass: [attrs.inputClass, attrs['input-class'], 'px-bvn-check'] }));
export const BFormRadio = checkWrapper('BFormRadio', _BFormRadio, (attrs) => ({ ...attrs, class: [attrs.class, 'px-bvn-check'] }));

// Directiva `v-b-tooltip` de BootstrapVueNext (Floating UI). Se registra localmente (`directives: { 'b-tooltip': vBTooltip }`); la global de
// BootstrapVue 2 sigue en las vistas no migradas. Hooks de Vue 3 (`mounted/updated/beforeUnmount`): no depende de `CUSTOM_DIR`.
export const vBTooltip = _vBTooltip;

/** Plugin de aplicación (registros, RTL, valores por defecto). No registra componentes: se importan explícitamente. */
export const bootstrapPlugin = createBootstrap({ components: {} });
