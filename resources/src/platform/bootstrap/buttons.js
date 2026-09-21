// Wrappers de BootstrapVueNext de PRODEX, partidos por FAMILIA (fase 5B) para que cada entrypoint importe solo la que usa (login: `buttons` + `forms`).
// `index.js` reexporta todas (las vistas importan de `@/platform/bootstrap`); ver docs/architecture/BOOTSTRAP5_BOOTSTRAPVUE_NEXT_PHASE5B.md.
// Botones e "primitives": botón, insignia, spinner y enlace.
import { pure, wrapper } from './core.js';
import { BButton as _BButton, BButtonGroup as _BButtonGroup, BCloseButton } from 'bootstrap-vue-next/components/BButton';
import { BBadge as _BBadge } from 'bootstrap-vue-next/components/BBadge';
import { BSpinner as _BSpinner } from 'bootstrap-vue-next/components/BSpinner';
import { BLink as _BLink } from 'bootstrap-vue-next/components/BLink';

// Los componentes internos de la familia (BLink, BCloseButton) también deben quedar en MODE 3. Se marcan dentro de una llamada `/*#__PURE__*/`
// para que solo se evalúe cuando el wrapper que los usa se importa (un `forEach` a nivel de módulo no se puede eliminar por tree-shaking).
const withInternals = (component, ...internals) => { internals.forEach(pure); return component; };

export const BSpinner = /*#__PURE__*/ pure(_BSpinner);
// Wrappers PRODEX (vista → wrapper → BootstrapVueNext) que conservan el marcado que la base Bootstrap 4 y la capa de diseño PRODEX
// ya estilan, para que migrar de familia no cambie el aspecto:
//  - BButton: BootstrapVueNext eliminó la prop `block` (BS5: `w-100` / `d-grid`); se conserva como clase `btn-block` de BS4.
//  - BBadge: la capa de diseño (`_interactions.scss`) y el color del tenant (`config.js`) estilan `.badge-<variante>`; se emite esa clase
//    (sin pasar `variant` al componente) en lugar de `text-bg-*`. Al cortar a BS5 se cambia aquí, no en las vistas.
// `target="_blank"` sin `rel`: BootstrapVue 2 añadía `rel="noopener"`; BootstrapVueNext no.
export const BButton = /*#__PURE__*/ wrapper('BButton', /*#__PURE__*/ withInternals(_BButton, _BLink, BCloseButton), { block: { type: Boolean, default: false } }, (props, attrs) => ({
  class: [attrs.class, props.block ? 'btn-block' : null],
  ...(attrs.target === '_blank' && !attrs.rel ? { rel: 'noopener' } : {}),
}));
export const BButtonGroup = /*#__PURE__*/ pure(_BButtonGroup);

// `pill`: BootstrapVue 2 (BS4) emite `badge-pill`; BootstrapVueNext, `rounded-pill` (sin el relleno horizontal de `.badge-pill`).
export const BBadge = /*#__PURE__*/ wrapper('BBadge', _BBadge, { variant: { type: String, default: 'secondary' }, pill: { type: Boolean, default: false } }, (props, attrs) => ({
  variant: null,
  class: [`badge-${props.variant}`, props.pill ? 'badge-pill' : null, attrs.class],
}));

export const BLink = /*#__PURE__*/ pure(_BLink);
