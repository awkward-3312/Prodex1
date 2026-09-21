// Wrappers de BootstrapVueNext de PRODEX, partidos por FAMILIA (fase 5B) para que cada entrypoint importe solo la que usa (login: `buttons` + `forms`).
// `index.js` reexporta todas (las vistas importan de `@/platform/bootstrap`); ver docs/architecture/BOOTSTRAP5_BOOTSTRAPVUE_NEXT_PHASE5B.md.
// Layout: contenedor, filas, columnas y tarjetas.
import { pure } from './core.js';
import { h } from 'vue';
import { BContainer as _BContainer, BRow as _BRow, BCol as _BCol } from 'bootstrap-vue-next/components/BContainer';
import {
  BCard as _BCard, BCardBody as _BCardBody, BCardHeader as _BCardHeader, BCardFooter as _BCardFooter,
  BCardText as _BCardText, BCardTitle as _BCardTitle, BCardSubtitle as _BCardSubtitle,
} from 'bootstrap-vue-next/components/BCard';

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
