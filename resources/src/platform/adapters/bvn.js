/**
 * Driver de BootstrapVueNext para los servicios de plataforma (notifications, modals, confirm de presentación "modal").
 *
 * Arquitectura elegida (fase 3): NO se envuelve la app en `<BApp>`. `BApp` es solo `BOrchestrator` + registros + `defaults`, y los registros
 * (`orchestrator`, `registry`, `rtl`, `defaults`) ya los instala el plugin `createBootstrap` (platform/bootstrap). Lo único que falta es el
 * `BOrchestrator`, que se monta UNA vez con el contexto de la aplicación (`vnode.appContext`, el mismo mecanismo con el que BootstrapVueNext
 * monta sus tooltips) en un contenedor de `<body>`. Así no cambia el DOM ni el layout del shell, no hay que tocar la raíz de cada entrypoint
 * (main, login…), Router 4 y Unhead no se enteran y el orquestador es idéntico al de `BApp`. El RTL no necesita `BApp`: el `dir` lo gobierna
 * el atributo del documento y el registro `rtl` de BVN solo lo leen BFormRating/BFormSpinbutton (no se usan).
 *
 * Las vistas no cambian: siguen llamando a `notifications.notify`, `modals.show/hide` y `confirm(...)`.
 */
import { h, render } from 'vue';
import { useToast, useModal } from 'bootstrap-vue-next/composables';
import { BOrchestrator } from 'bootstrap-vue-next/components/BApp';
import { notifications } from '../notifications.js';
import { confirm } from '../confirm.js';
import { modals } from '../modals.js';

const DEFAULT_AUTOHIDE_MS = 5000; // `autoHideDelay` por defecto de $bvToast

/** BV2 `toaster` ("b-toaster-top-right"…) → posición de BootstrapVueNext. */
const TOASTER_POSITION = {
  'b-toaster-top-right': 'top-end',
  'b-toaster-top-left': 'top-start',
  'b-toaster-top-center': 'top-center',
  'b-toaster-top-full': 'top-center',
  'b-toaster-bottom-right': 'bottom-end',
  'b-toaster-bottom-left': 'bottom-start',
  'b-toaster-bottom-center': 'bottom-center',
  'b-toaster-bottom-full': 'bottom-center',
};

/** Opciones de `$bvToast.toast(msg, opts)` → props de BToast. Lo no reconocido se descarta (BToast no las tiene). */
export function toastProps(message, options = {}) {
  const { title, variant, solid, autoHideDelay, noAutoHide, toaster, noCloseButton, toastClass, headerClass, bodyClass, href, id } = options;
  const props = { body: message, noProgress: true, modelValue: noAutoHide ? true : Number(autoHideDelay) > 0 ? Number(autoHideDelay) : DEFAULT_AUTOHIDE_MS };
  if (title !== undefined) props.title = title;
  if (variant !== undefined) props.variant = variant;
  if (solid !== undefined) props.solid = !!solid;
  if (noCloseButton !== undefined) props.noCloseButton = !!noCloseButton;
  if (toastClass !== undefined) props.toastClass = toastClass;
  if (headerClass !== undefined) props.headerClass = headerClass;
  if (bodyClass !== undefined) props.bodyClass = bodyClass;
  if (href !== undefined) props.href = href;
  if (id !== undefined) props.id = id;
  if (toaster && TOASTER_POSITION[toaster]) props.position = TOASTER_POSITION[toaster];
  return props;
}

/** Opciones normalizadas de `confirm()` (+ `native`) → props de BModal. */
export function confirmModalProps(options) {
  const props = { body: options.message, ...options.native };
  if (options.title !== undefined) props.title = options.title;
  if (options.confirmText !== undefined) props.okTitle = options.confirmText;
  if (options.cancelText !== undefined) props.cancelTitle = options.cancelText;
  if (options.variant !== undefined) props.okVariant = options.variant;
  return props;
}

/** `true` (aceptar) / `false` (cancelar) / `null` (cerrado sin decidir), como `msgBoxConfirm`. */
export const okOf = (event) => (event && event.ok === true ? true : event && event.ok === false ? false : null);

async function dispose(event) {
  try {
    if (event && typeof event[Symbol.asyncDispose] === 'function') await event[Symbol.asyncDispose]();
  } catch (e) { /* el elemento ya no está en el registro */ }
}

/**
 * @param {import('vue').ComponentPublicInstance} rootVm instancia raíz (bajo @vue/compat `new Vue()` devuelve el vm; `rootVm.$.appContext` es el contexto de la app)
 */
export function installBootstrapVueNextPlatform(rootVm) {
  const appContext = rootVm.$.appContext;
  const vueApp = appContext.app;

  const container = document.createElement('div');
  container.setAttribute('data-px-bvn-orchestrator', '');
  document.body.appendChild(container);
  const vnode = h(BOrchestrator);
  vnode.appContext = appContext;
  render(vnode, container);

  const toast = vueApp.runWithContext(() => useToast());
  const modal = vueApp.runWithContext(() => useModal());
  const disposers = [];

  disposers.push(
    notifications.setDriver((message, options) => {
      const controller = toast.create(toastProps(message, options));
      controller.show().then(dispose);
      return controller;
    })
  );

  // Modales por id: todos los `<b-modal id>` son de BootstrapVueNext (fase 4) y se resuelven por su registro. Un id que no está montado
  // (vista con `v-if`, otra ruta) es un no-op, como lo era `bv::show::modal` de BootstrapVue 2 para un id desconocido.
  disposers.push(
    modals.setDriver({
      show: (id) => { const target = modal.get(id); if (target) target.show(); },
      hide: (id) => { const target = modal.get(id); if (target) target.hide('hide'); },
    })
  );

  disposers.push(
    confirm.setDriver('modal', async (options) => {
      const controller = modal.create({ centered: false, ...confirmModalProps(options) });
      const event = await controller.show();
      const result = okOf(event);
      await dispose(event);
      return result;
    })
  );

  return () => {
    disposers.forEach((d) => d());
    render(null, container);
    container.remove();
  };
}
