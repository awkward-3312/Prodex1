// Wrappers de BootstrapVueNext de PRODEX, partidos por FAMILIA (fase 5B) para que cada entrypoint importe solo la que usa (login: `buttons` + `forms`).
// `index.js` las reexporta todas (las vistas importan de `@/platform/bootstrap`); ver docs/architecture/BOOTSTRAP5_BOOTSTRAPVUE_NEXT_PHASE5B.md.
// Tablas de BootstrapVueNext (`vue-good-table` sigue siendo la tabla de datos principal).
import { h, ref } from 'vue';
import { pure } from './core.js';
import { BTable as _BTable, BTableSimple as _BTableSimple, BThead as _BThead, BTbody as _BTbody, BTr as _BTr, BTh as _BTh, BTd as _BTd } from 'bootstrap-vue-next/components/BTable';

// Tabla (fase 4): `b-table` de BootstrapVue 2 → BTable de BootstrapVueNext. El contrato de props usado (`items`, `fields`, `busy`, `small`, `striped`,
// `hover`, `bordered`, `responsive`, `show-empty`, `empty-text`, `thead-class`, slots `#cell(x)`, `#table-busy`, orden local con `sortable`) es
// el mismo. `head-variant="light|dark"` produce `table-light|dark` (Bootstrap 5).
export const BTable = /*#__PURE__*/ pure({
  name: 'BTable',
  inheritAttrs: false,
  setup(_props, { attrs, slots, expose }) {
    const inner = ref(null);
    expose({ refresh: () => inner.value && inner.value.refresh && inner.value.refresh() });
    return () => {
      const props = { ...attrs, ref: inner };
      if (props['thead-class'] !== undefined && props.theadClass === undefined) { props.theadClass = props['thead-class']; delete props['thead-class']; }
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
