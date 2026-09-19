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

/** Plugin de aplicación (registros, RTL, valores por defecto). No registra componentes: se importan explícitamente. */
export const bootstrapPlugin = createBootstrap({ components: {} });
