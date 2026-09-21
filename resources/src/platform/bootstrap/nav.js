// Wrappers de BootstrapVueNext de PRODEX, partidos por FAMILIA (fase 5B) para que cada entrypoint importe solo la que usa (login: `buttons` + `forms`).
// `index.js` las reexporta todas (las vistas importan de `@/platform/bootstrap`); ver docs/architecture/BOOTSTRAP5_BOOTSTRAPVUE_NEXT_PHASE5B.md.
// Navegación: pestañas, desplegables y paginación.
import { h, nextTick, getCurrentInstance, cloneVNode, Fragment } from 'vue';
import { pure, truthyAttr, toList } from './core.js';
import { BTabs as _BTabs, BTab as _BTab } from 'bootstrap-vue-next/components/BTabs';
import { BDropdown as _BDropdown, BDropdownItem as _BDropdownItem, BDropdownDivider as _BDropdownDivider, BDropdownHeader as _BDropdownHeader, BDropdownForm as _BDropdownForm } from 'bootstrap-vue-next/components/BDropdown';
import { BPagination as _BPagination } from 'bootstrap-vue-next/components/BPagination';

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
