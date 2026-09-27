// Wrappers de BootstrapVueNext de PRODEX, partidos por FAMILIA (fase 5B) para que cada entrypoint importe solo la que usa (login: `buttons` + `forms`).
// `index.js` las reexporta todas (las vistas importan de `@/platform/bootstrap`); ver docs/architecture/BOOTSTRAP5_BOOTSTRAPVUE_NEXT_PHASE5B.md.
// Retroalimentación: alertas, progreso y marcadores (placeholder).
import { h } from 'vue';
import { pure, truthyAttr, toList } from './core.js';
import { BAlert as _BAlert } from 'bootstrap-vue-next/components/BAlert';
import { BProgress as _BProgress, BProgressBar as _BProgressBar } from 'bootstrap-vue-next/components/BProgress';
import { BPlaceholder as _BPlaceholder, BPlaceholderWrapper as _BPlaceholderWrapper } from 'bootstrap-vue-next/components/BPlaceholder';

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

export const BPlaceholder = /*#__PURE__*/ pure(_BPlaceholder);
export const BPlaceholderWrapper = /*#__PURE__*/ pure(_BPlaceholderWrapper);
