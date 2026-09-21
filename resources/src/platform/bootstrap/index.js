// BootstrapVueNext dentro de PRODEX (migración por familias; ver docs/architecture/BOOTSTRAP5_BOOTSTRAPVUE_NEXT_PHASE1.md).
//
// Regla de uso: las vistas importan los componentes DESDE AQUÍ (nunca de `bootstrap-vue-next` directamente ni por registro
// global) para que BootstrapVue 2 (global, `b-*`) y BootstrapVueNext convivan sin choques de nombres: un componente importado
// localmente (`components: { BButton }`) gana al global `BButton` de BootstrapVue 2 solo en esa vista.
//
// BootstrapVueNext es Vue 3 puro. Bajo @vue/compat MODE 2 hay que excluirlo de los comportamientos de Vue 2
// (`v-model` value/input, class/style de atributos, `$listeners`…): cada componente exportado (y los que usa por dentro) se marca
// `compatConfig: { MODE: 3 }`. Es configuración del propio componente, no un parche de su lógica; desaparece al quitar compat.
import { h, ref, nextTick, getCurrentInstance, cloneVNode, Fragment } from 'vue';
import { bootstrapPlugin } from './plugin.js';
import { vBTooltip as _vBTooltip, vBToggle as _vBToggle, vBPopover as _vBPopover } from 'bootstrap-vue-next/directives';
import { BOffcanvas as _BOffcanvas } from 'bootstrap-vue-next/components/BOffcanvas';
import { BModal as _BModal } from 'bootstrap-vue-next/components/BModal';
import { BTable as _BTable, BTableSimple as _BTableSimple, BThead as _BThead, BTbody as _BTbody, BTr as _BTr, BTh as _BTh, BTd as _BTd } from 'bootstrap-vue-next/components/BTable';
import { BButton as _BButton, BButtonGroup as _BButtonGroup, BCloseButton } from 'bootstrap-vue-next/components/BButton';
import { BBadge as _BBadge } from 'bootstrap-vue-next/components/BBadge';
import { BAlert as _BAlert } from 'bootstrap-vue-next/components/BAlert';
import { BSpinner as _BSpinner } from 'bootstrap-vue-next/components/BSpinner';
import { BLink } from 'bootstrap-vue-next/components/BLink';
import { BListGroup as _BListGroup, BListGroupItem as _BListGroupItem } from 'bootstrap-vue-next/components/BListGroup';
import { BImg as _BImg } from 'bootstrap-vue-next/components/BImg';
import { BAvatar as _BAvatar } from 'bootstrap-vue-next/components/BAvatar';
import { BTabs as _BTabs, BTab as _BTab } from 'bootstrap-vue-next/components/BTabs';
import { BDropdown as _BDropdown, BDropdownItem as _BDropdownItem, BDropdownDivider as _BDropdownDivider, BDropdownHeader as _BDropdownHeader, BDropdownForm as _BDropdownForm } from 'bootstrap-vue-next/components/BDropdown';
import { BPagination as _BPagination } from 'bootstrap-vue-next/components/BPagination';
import { BContainer as _BContainer, BRow as _BRow, BCol as _BCol } from 'bootstrap-vue-next/components/BContainer';
import {
  BCard as _BCard, BCardBody as _BCardBody, BCardHeader as _BCardHeader, BCardFooter as _BCardFooter,
  BCardText as _BCardText, BCardTitle as _BCardTitle, BCardSubtitle as _BCardSubtitle,
} from 'bootstrap-vue-next/components/BCard';
import { BProgress as _BProgress, BProgressBar as _BProgressBar } from 'bootstrap-vue-next/components/BProgress';
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
[BLink, BCloseButton].forEach(pure);

export const BSpinner = /*#__PURE__*/ pure(_BSpinner);
export const BContainer = /*#__PURE__*/ pure(_BContainer);
// Fila: `no-gutters` de BS4 (clase `.no-gutters`, que la base estila); BVN lo traduce a `g-0`, que la hoja BS4 no tiene.
export const BRow = /*#__PURE__*/ pure({
  name: 'BRow',
  inheritAttrs: false,
  setup(_props, { attrs, slots }) {
    return () => {
      const { noGutters, 'no-gutters': noGuttersKebab, ...rest } = attrs;
      const off = noGutters !== undefined ? noGutters : noGuttersKebab;
      return h(_BRow, { ...rest, class: [rest.class, off === '' || off === true ? 'no-gutters' : null] }, slots);
    };
  },
});
export const BCol = /*#__PURE__*/ pure(_BCol);
export const BCard = /*#__PURE__*/ pure(_BCard);
export const BCardBody = /*#__PURE__*/ pure(_BCardBody);
export const BCardHeader = /*#__PURE__*/ pure(_BCardHeader);
export const BCardFooter = /*#__PURE__*/ pure(_BCardFooter);
export const BCardText = /*#__PURE__*/ pure(_BCardText);
export const BCardTitle = /*#__PURE__*/ pure(_BCardTitle);
export const BCardSubtitle = /*#__PURE__*/ pure(_BCardSubtitle);

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

// `target="_blank"` sin `rel`: BootstrapVue 2 añadía `rel="noopener"`; BootstrapVueNext no.
export const BButton = /*#__PURE__*/ wrapper('BButton', _BButton, { block: { type: Boolean, default: false } }, (props, attrs) => ({
  class: [attrs.class, props.block ? 'btn-block' : null],
  ...(attrs.target === '_blank' && !attrs.rel ? { rel: 'noopener' } : {}),
}));
export const BButtonGroup = /*#__PURE__*/ pure(_BButtonGroup);

// `pill`: BootstrapVue 2 (BS4) emite `badge-pill`; BootstrapVueNext, `rounded-pill` (sin el relleno horizontal de `.badge-pill`).
export const BBadge = /*#__PURE__*/ wrapper('BBadge', _BBadge, { variant: { type: String, default: 'secondary' }, pill: { type: Boolean, default: false } }, (props, attrs) => ({
  variant: null,
  class: [`badge-${props.variant}`, props.pill ? 'badge-pill' : null, attrs.class],
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
export const BFormGroup = /*#__PURE__*/ pure({
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
export const BFormInput = /*#__PURE__*/ pure(_BFormInput);
export const BFormTextarea = /*#__PURE__*/ pure(_BFormTextarea);
export const BFormInvalidFeedback = /*#__PURE__*/ pure(_BFormInvalidFeedback);
export const BFormSelectOption = /*#__PURE__*/ pure(_BFormSelectOption);

export const BFormSelect = /*#__PURE__*/ pure({
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
export const BFormCheckbox = /*#__PURE__*/ checkWrapper('BFormCheckbox', _BFormCheckbox, (attrs) => ({ ...attrs, inputClass: [attrs.inputClass, attrs['input-class'], 'px-bvn-check'] }));
export const BFormRadio = /*#__PURE__*/ checkWrapper('BFormRadio', _BFormRadio, (attrs) => ({ ...attrs, class: [attrs.class, 'px-bvn-check'] }));

// Directiva `v-b-tooltip` de BootstrapVueNext (Floating UI). Se registra localmente (`directives: { 'b-tooltip': vBTooltip }`); la global de
// BootstrapVue 2 sigue en las vistas no migradas. Hooks de Vue 3 (`mounted/updated/beforeUnmount`): no depende de `CUSTOM_DIR`.
export const vBTooltip = _vBTooltip;

// ---------------------------------------------------------------------------------------------------------------------------------
// Barra lateral (fase 3): `b-sidebar` de BootstrapVue 2 → BOffcanvas de BootstrapVueNext. Contrato conservado (subconjunto realmente usado):
// `id`, `title`, `right`, `shadow`, `bg-variant`, `sidebar-class`, contenido por el slot por defecto. Comportamiento de BV2 conservado:
// sin backdrop, el resto de la página sigue interactuando y con scroll (`body-scrolling`), sin trampa de foco, se cierra con ESC y con la
// cruz. Se abre/cierra con `v-b-toggle.<id>` (directiva de BVN, `vBToggle`) o por id. No cierra por cambio de ruta: el componente se
// desmonta con la vista.
// ---------------------------------------------------------------------------------------------------------------------------------
export const BSidebar = /*#__PURE__*/ pure({
  name: 'BSidebar',
  inheritAttrs: false,
  props: {
    right: { type: Boolean, default: false },
    shadow: { type: [Boolean, String], default: false },
    bgVariant: { type: String, default: 'light' },
    textVariant: { type: String, default: 'dark' },
    sidebarClass: { type: [String, Array, Object], default: undefined },
    width: { type: String, default: '320px' },
  },
  setup(props, { attrs, slots }) {
    return () =>
      h(
        _BOffcanvas,
        {
          ...attrs,
          placement: props.right ? 'end' : 'start',
          noBackdrop: true,
          bodyScrolling: true,
          noTrap: true,
          teleportDisabled: true, // b-sidebar se renderizaba en su sitio: las reglas `.prodex-ui …` de la capa de diseño deben alcanzarlo
          width: props.width,
          headerClass: 'b-sidebar-header',
          bodyClass: 'b-sidebar-body',
          shadow: props.shadow === '' || props.shadow === true ? 'sm' : props.shadow || undefined,
          class: ['b-sidebar', props.right ? 'b-sidebar-right' : null, `bg-${props.bgVariant}`, `text-${props.textVariant}`, props.sidebarClass, attrs.class],
        },
        slots
      );
  },
});


// ---------------------------------------------------------------------------------------------------------------------------------
// Modal (fase 4): `<b-modal>` de BootstrapVue 2 → BModal de BootstrapVueNext. Contrato de BootstrapVue 2 conservado (subconjunto usado):
//   - `id` (se abre/cierra por id con `modals.show/hide`, que resuelve el registro de BootstrapVueNext), `title`, `size`, `centered`,
//     `scrollable`, `modal-class`, `body-class`, `ok-only/ok-title/ok-variant/ok-disabled`, `no-close-on-backdrop/esc`, `visible` y `v-model`.
//   - Nombres que BootstrapVueNext cambió: `hide-footer` → `noFooter`, `hide-header` → `noHeader`, `hide-header-close` → `noHeaderClose`,
//     `static` → `teleportDisabled`.
//   - Ubicación en el DOM: el `<b-modal>` de BootstrapVue 2 se quedaba dentro de la vista (bajo `.prodex-ui`, de la que dependen la jerarquía
//     y el espaciado de `.modal-header/.modal-title/.modal-body`). BootstrapVueNext teletransporta a `<body>` y perdería esas reglas, así que
//     `teleportDisabled` es `true` por defecto (igual que en `BSidebar`).
//   - Ciclo de vida de BootstrapVue 2: un modal no estático solo renderiza su contenido mientras está abierto y lo destruye al cerrarse
//     (los formularios de dentro se reinician y sus `mounted` se repiten). BootstrapVueNext monta siempre el contenido por defecto:
//     se activan `lazy` + `unmountLazy` salvo que la vista los indique.
//   - Foco: el modal toma el foco en `shown` solo si el foco no está ya dentro (regla de BootstrapVue 2; BootstrapVueNext lo roba siempre).
//   - Eventos `@show/@shown/@hide/@hidden/@ok/@cancel/@close` (con `preventDefault()`, como BootstrapVue 2) y `$refs.x.show()/hide()/toggle()`.
// El resto de BootstrapVueNext se deja tal cual; las diferencias de marcado (cabecera, cierre) las absorbe el puente de Bootstrap 5.
// ---------------------------------------------------------------------------------------------------------------------------------
const MODAL_RENAMED = {
  'hide-footer': 'noFooter', hideFooter: 'noFooter',
  'hide-header': 'noHeader', hideHeader: 'noHeader',
  'hide-header-close': 'noHeaderClose', hideHeaderClose: 'noHeaderClose',
  static: 'teleportDisabled',
};

export const BModal = /*#__PURE__*/ pure({
  name: 'BModal',
  inheritAttrs: false,
  setup(_props, { attrs, slots, expose }) {
    const inner = ref(null);
    expose({
      show: (...args) => inner.value && inner.value.show(...args),
      hide: (trigger, ...rest) => inner.value && inner.value.hide(trigger, ...rest),
      toggle: (...args) => inner.value && inner.value.toggle(...args),
    });
    return () => {
      // Foco (BV2): al terminar la animación el modal recibe el foco SOLO si no está ya dentro de él. BVN lo mueve siempre al contenedor
      // (`focus` por defecto), con lo que quien ya escribía en el primer campo lo pierde ~300 ms después de abrir. Se desactiva el foco inicial
      // de BVN (`focus: false`) y se aplica la regla de BV2 en `shown` (el ESC y el ciclo con Tab necesitan el foco dentro).
      const focusIfOutside = () => {
        const el = inner.value && document.getElementById(inner.value.id);
        if (el && !el.contains(document.activeElement)) el.focus({ preventScroll: true });
      };
      const props = { lazy: true, unmountLazy: true, focus: false, ref: inner };
      for (const key of Object.keys(attrs)) props[MODAL_RENAMED[key] || key] = attrs[key];
      const userShown = props.onShown;
      props.onShown = [focusIfOutside, ...(Array.isArray(userShown) ? userShown : userShown ? [userShown] : [])];
      return h(_BModal, props, slots);
    };
  },
});

// Tabla (fase 4): `b-table` de BootstrapVue 2 → BTable de BootstrapVueNext. El contrato de props usado (`items`, `fields`, `busy`, `small`, `striped`,
// `hover`, `bordered`, `responsive`, `show-empty`, `empty-text`, `thead-class`, slots `#cell(x)`, `#table-busy`, orden local con `sortable`) es
// el mismo. Única diferencia de marcado: `head-variant="light|dark"`. BootstrapVue 2 (BS4) pintaba `thead.thead-light`, que estila la base
// (`.thead-light th`); BootstrapVueNext emite `table-light` (fila de color, otro gris). Se emite la clase de BS4 y no se pasa `headVariant`.
export const BTable = /*#__PURE__*/ pure({
  name: 'BTable',
  inheritAttrs: false,
  setup(_props, { attrs, slots, expose }) {
    const inner = ref(null);
    expose({ refresh: () => inner.value && inner.value.refresh && inner.value.refresh() });
    return () => {
      const { headVariant, 'head-variant': headVariantKebab, ...rest } = attrs;
      const variant = headVariant || headVariantKebab;
      const theadClass = rest.theadClass !== undefined ? rest.theadClass : rest['thead-class'];
      const merged = variant === 'light' || variant === 'dark' ? [theadClass, `thead-${variant}`] : theadClass;
      const props = { ...rest, ref: inner };
      delete props['thead-class'];
      if (variant && variant !== 'light' && variant !== 'dark') props.headVariant = variant;
      if (merged !== undefined) props.theadClass = merged;
      return h(_BTable, props, slots);
    };
  },
});
export const BTableSimple = /*#__PURE__*/ pure(_BTableSimple);
export const BThead = /*#__PURE__*/ pure(_BThead);
export const BTbody = /*#__PURE__*/ pure(_BTbody);
export const BTr = /*#__PURE__*/ pure(_BTr);
export const BTh = /*#__PURE__*/ pure(_BTh);
export const BTd = /*#__PURE__*/ pure(_BTd);

/** `v-b-toggle` de BootstrapVueNext (hooks de Vue 3). Uso local: `directives: { 'b-toggle': vBToggle }`. */
export const vBToggle = _vBToggle;

/** `v-b-popover` de BootstrapVueNext. Uso local: `directives: { 'b-popover': vBPopover }`. */
export const vBPopover = _vBPopover;


// ---------------------------------------------------------------------------------------------------------------------------------
// Primitives y navegación (fase 5A). Contrato de BootstrapVue 2 (subconjunto usado, auditado por AST) sobre BootstrapVueNext:
//   - BAlert: `show` (booleano o número de segundos) ↔ `modelValue`; `@dismissed` ↔ `close` (clic en la cruz); el cuerpo queda en `.alert-body` (BVN) y el puente lo
//     hace crecer. Sigue aceptando `model-value` (las vistas migradas en la fase 2).
//   - BProgress / BProgressBar: la variante se pinta con `bg-<v>` (BS4), no con `text-bg-<v>` (BS5, otro color de texto).
//   - BTabs: `lazy` implica `unmountLazy` en cada BTab (BV2 destruye la pestaña inactiva); `v-model` = índice de la pestaña (BVN usa el id en `modelValue` y el índice en `index`); `@input` (BV2) ↔ `update:index`.
//   - BDropdown: `right` → `placement="bottom-end"`, `dropup` → `top-start`; envoltorio propio con la identidad de BV2 (`div#id.b-dropdown.btn-group`, botón `#id__BV_toggle_`) y
//     eventos de raíz `bv::dropdown::show|hide`.
//   - BPagination: `align` left/right ↔ start/end; `@change` (solo por interacción del usuario) ↔ `page-click`. BV2 emitía `change` ANTES de actualizar el
//     v-model (un manejador que leía `this.page` veía la página anterior); aquí se emite con el v-model ya actualizado (corrige ese desfase).
// Quitar estas traducciones cuando las vistas hablen el contrato de BVN.
// ---------------------------------------------------------------------------------------------------------------------------------
const truthyAttr = (v) => v === '' || v === true;
const toList = (fn) => (Array.isArray(fn) ? fn : fn ? [fn] : []);

export const BAlert = /*#__PURE__*/ pure({
  name: 'BAlert',
  inheritAttrs: false,
  setup(_props, { attrs, slots }) {
    return () => {
      const { show, modelValue, onDismissed, ...rest } = attrs;
      let visible = false;
      if (modelValue !== undefined) visible = modelValue;
      else if (typeof show === 'number') visible = show > 0 ? show * 1000 : false;
      else if (truthyAttr(show) || show === true) visible = true;
      else visible = !!show;
      return h(_BAlert, { ...rest, modelValue: visible, onClose: [...toList(rest.onClose), ...toList(onDismissed)] }, slots);
    };
  },
});

export const BProgress = /*#__PURE__*/ pure(_BProgress);
export const BProgressBar = /*#__PURE__*/ pure({
  name: 'BProgressBar',
  inheritAttrs: false,
  setup(_props, { attrs, slots }) {
    return () => {
      const { variant, ...rest } = attrs;
      return h(_BProgressBar, { ...rest, class: [rest.class, variant ? `bg-${variant}` : null] }, slots);
    };
  },
});

export const BTab = /*#__PURE__*/ pure(_BTab);

// BVN solo reconoce como pestañas los hijos cuyo `type` es EXACTAMENTE su BTab (`tab.type === BTab`): un BTab envuelto no se registra y la primera pestaña
// no se activa. Por eso `BTab` se exporta tal cual y `lazy` → `unmountLazy` (BV2 destruye la pestaña inactiva; en BVN es una prop de cada BTab) se aplica desde
// BTabs clonando los vnodes hijos.
const withUnmountLazy = (vnodes) => vnodes.map((vnode) => {
  if (!vnode || typeof vnode !== 'object') return vnode;
  if (vnode.type === _BTab) return vnode.props && vnode.props.unmountLazy !== undefined ? vnode : cloneVNode(vnode, { unmountLazy: true });
  if (vnode.type === Fragment && Array.isArray(vnode.children)) return h(Fragment, { key: vnode.key }, withUnmountLazy(vnode.children));
  return vnode;
});

export const BTabs = /*#__PURE__*/ pure({
  name: 'BTabs',
  inheritAttrs: false,
  setup(_props, { attrs, slots }) {
    return () => {
      const { modelValue, 'onUpdate:modelValue': onUpdate, onInput, ...rest } = attrs;
      const props = { ...rest };
      if (typeof modelValue === 'number') props.index = modelValue;
      if (onUpdate || onInput) props['onUpdate:index'] = (i) => { toList(onUpdate).forEach((f) => f(i)); toList(onInput).forEach((f) => f(i)); };
      const lazy = truthyAttr(rest.lazy) || rest.lazy === true;
      const tabSlots = lazy && slots.default ? { ...slots, default: (...args) => withUnmountLazy(slots.default(...args)) } : slots;
      return h(_BTabs, props, tabSlots);
    };
  },
});

export const BDropdown = /*#__PURE__*/ pure({
  name: 'BDropdown',
  inheritAttrs: false,
  setup(_props, { attrs, slots }) {
    // Eventos de raíz de BV2 (`this.$root.$on('bv::dropdown::show|hide')`): las listas con acciones por fila los usan para dar altura a la tabla
    // mientras el menú está abierto (`showDropdown`). Se emiten con el mismo nombre.
    const instance = getCurrentInstance();
    const root = () => instance && instance.proxy && instance.proxy.$root;
    const relay = (name) => (event) => { const r = root(); if (r && typeof r.$emit === 'function') r.$emit(name, event); };
    return () => {
      const { right, dropup, id, class: cls, style, ...rest } = attrs;
      const end = truthyAttr(right) || right === true;
      const up = truthyAttr(dropup) || dropup === true;
      // Identidad de BV2 (`div#id.b-dropdown.btn-group.dropdown` > `button#id__BV_toggle_` + `ul.dropdown-menu`): el CSS de la barra superior y del
      // POS engancha `#lang-dd`, `#user-dd`, `#lang-dd__BV_toggle_`… BVN pone el id en el botón y su envoltorio no lo lleva, así que el envoltorio
      // es propio (`noWrapper`) y BVN recibe el id del botón.
      // Posicionamiento `absolute` (por defecto de BVN): con `fixed` el menú se descolocaba dentro de la cabecera del POS (ancestros con transform/filter
      // crean el bloque contenedor). El recorte por `overflow` de las tablas ya lo resolvían las vistas con `showDropdown` (eventos `bv::dropdown::*`).
      // `boundary` de BV2: 'scrollParent' | 'viewport' | 'window' (o un elemento); BVN: 'clippingAncestors' | 'viewport' | 'document' | elemento.
      const BOUNDARY = { window: 'viewport', scrollParent: 'clippingAncestors' };
      const props = { ...rest, noWrapper: true };
      if (typeof rest.boundary === 'string' && BOUNDARY[rest.boundary]) props.boundary = BOUNDARY[rest.boundary];
      props.onShow = [relay('bv::dropdown::show'), ...toList(rest.onShow)];
      props.onHide = [relay('bv::dropdown::hide'), ...toList(rest.onHide)];
      if (id !== undefined) props.id = `${id}__BV_toggle_`;
      if (rest.placement === undefined) props.placement = `${up ? 'top' : 'bottom'}-${end ? 'end' : 'start'}`;
      return h('div', { id, class: ['b-dropdown', 'btn-group', up ? 'dropup' : 'dropdown', cls], style }, [h(_BDropdown, props, slots)]);
    };
  },
});
export const BDropdownItem = /*#__PURE__*/ pure(_BDropdownItem);
export const BDropdownDivider = /*#__PURE__*/ pure(_BDropdownDivider);
export const BDropdownHeader = /*#__PURE__*/ pure(_BDropdownHeader);
export const BDropdownForm = /*#__PURE__*/ pure(_BDropdownForm);

const PAGINATION_ALIGN = { left: 'start', right: 'end' };
export const BPagination = /*#__PURE__*/ pure({
  name: 'BPagination',
  inheritAttrs: false,
  setup(_props, { attrs, slots }) {
    return () => {
      const { onChange, onInput, align, value, ...rest } = attrs;
      const props = { ...rest };
      if (align !== undefined) props.align = PAGINATION_ALIGN[align] || align;
      // BV2 dibuja siempre al menos la página 1 (también con 0 filas); BVN no dibuja ninguna.
      const rows = props.totalRows !== undefined ? props.totalRows : props['total-rows'];
      if (!(Number(rows) >= 1)) { delete props['total-rows']; props.totalRows = 1; }
      // BV2: `value`/`@input` (también con `:value` + `@input`, sin v-model) ↔ `modelValue`/`update:modelValue`.
      if (value !== undefined && props.modelValue === undefined) props.modelValue = value;
      if (onInput) props['onUpdate:modelValue'] = [...toList(rest['onUpdate:modelValue']), ...toList(onInput)];
      // `change` de BV2: solo por clic del usuario. Se emite tras actualizar el v-model (BVN emite `page-click` antes de actualizarlo).
      props.onPageClick = [...toList(rest.onPageClick), (_event, page) => nextTick(() => toList(onChange).forEach((f) => f(page)))];
      return h(_BPagination, props, slots);
    };
  },
});

export { BLink };
export const BListGroup = /*#__PURE__*/ pure(_BListGroup);
export const BListGroupItem = /*#__PURE__*/ pure(_BListGroupItem);
export const BImg = /*#__PURE__*/ pure(_BImg);
export const BAvatar = /*#__PURE__*/ pure(_BAvatar);


export { bootstrapPlugin };
