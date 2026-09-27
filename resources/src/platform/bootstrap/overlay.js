// Wrappers de BootstrapVueNext de PRODEX, partidos por FAMILIA (fase 5B) para que cada entrypoint importe solo la que usa (login: `buttons` + `forms`).
// `index.js` las reexporta todas (las vistas importan de `@/platform/bootstrap`); ver docs/architecture/BOOTSTRAP5_BOOTSTRAPVUE_NEXT_PHASE5B.md.
// Superposiciones: modal, barra lateral y directivas (`v-b-tooltip`, `v-b-toggle`, `v-b-popover`).
import { h, ref } from 'vue';
import { pure } from './core.js';
import { vBTooltip as _vBTooltip, vBToggle as _vBToggle, vBPopover as _vBPopover } from 'bootstrap-vue-next/directives';
import { BOffcanvas as _BOffcanvas } from 'bootstrap-vue-next/components/BOffcanvas';
import { BModal as _BModal } from 'bootstrap-vue-next/components/BModal';

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

/** `v-b-toggle` de BootstrapVueNext (hooks de Vue 3). Uso local: `directives: { 'b-toggle': vBToggle }`. */
export const vBToggle = _vBToggle;

/** `v-b-popover` de BootstrapVueNext. Uso local: `directives: { 'b-popover': vBPopover }`. */
export const vBPopover = _vBPopover;
