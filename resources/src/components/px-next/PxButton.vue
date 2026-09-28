<template>
  <component
    :is="tag"
    class="pxn-btn pxn-ring"
    :class="[
      `pxn-btn--${variant}`,
      `pxn-btn--${size}`,
      { 'pxn-btn--block': block, 'pxn-btn--icon': iconOnly, 'is-loading': loading, 'pxn-btn--destructive': destructive && iconOnly }
    ]"
    :type="tag === 'button' ? type : null"
    :href="tag === 'a' ? href : null"
    :disabled="tag === 'button' ? (disabled || loading) : null"
    :aria-disabled="disabled || loading ? 'true' : null"
    :aria-busy="loading ? 'true' : null"
    v-on="$listeners"
  >
    <span v-if="loading" class="pxn-btn__spinner" aria-hidden="true"></span>
    <lucide-icon v-if="icon && !loading" :name="icon" :size="iconSize" class="pxn-btn__icon" />
    <span v-if="!iconOnly" class="pxn-btn__label"><slot /></span>
    <lucide-icon v-if="trailingIcon && !iconOnly" :name="trailingIcon" :size="iconSize" class="pxn-btn__icon pxn-btn__icon--trail" />
    <span v-if="revealName" class="pxn-btn__hint" aria-hidden="true">{{ revealName }}</span>
  </component>
</template>

<script>
// PxButton: el único botón en px-next. El primario del tenant lleva "primary";
// toda otra variante es neutral para que una pantalla nunca tenga dos acentos compitiendo.
//
// `destructive` (#13): SOLO visual, y solo tiene efecto junto con `icon-only`. No es "todo botón rojo
// es destructivo" — un "×" que solo quita una línea de un formulario local (PxButton ya lo cubre con
// variant="danger") no debe sentirse igual que una eliminación real en backend. `destructive` marca esa
// segunda categoría: al hover/focus-visible intensifica hacia danger y revela el nombre accesible (el
// mismo texto de `aria-label`, nunca un texto propio) en una etiqueta flotante fuera del flujo (position:
// absolute), así que el botón NUNCA cambia de tamaño — cero layout shift en tablas/toolbars. El primitive
// no sabe nada de axios/confirmación/permisos: el padre sigue siendo dueño de la petición y del `loading`.
export default {
  name: "PxButton",
  props: {
    variant: { type: String, default: "secondary" }, // primary | secondary | ghost | subtle | danger | link
    size: { type: String, default: "md" },           // sm | md | lg
    type: { type: String, default: "button" },
    href: { type: String, default: null },
    icon: { type: String, default: null },
    trailingIcon: { type: String, default: null },
    iconOnly: { type: Boolean, default: false },
    block: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    destructive: { type: Boolean, default: false } // acción irreversible en backend; solo con icon-only
  },
  computed: {
    tag() { return this.href ? "a" : "button"; },
    iconSize() { return this.size === "sm" ? 14 : this.size === "lg" ? 18 : 16; },
    // Decorativo (aria-hidden): el nombre accesible real sigue siendo el aria-label del consumidor,
    // presente siempre, con o sin hover. Sin aria-label no hay nada que revelar (evita un globo vacío).
    revealName() {
      return this.destructive && this.iconOnly ? (this.$attrs["aria-label"] || null) : null;
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-btn {
  --_h: var(--pxn-control-h-md);
  --_px: var(--pxn-space-6);
  --_fs: var(--pxn-fs-body);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--pxn-space-3);
  height: var(--_h);
  padding: 0 var(--_px);
  border: 1px solid transparent;
  border-radius: var(--pxn-radius-md);
  font: inherit;
  font-size: var(--_fs);
  font-weight: var(--pxn-fw-medium);
  line-height: 1;
  white-space: nowrap;
  cursor: pointer;
  user-select: none;
  transition: background-color var(--pxn-dur-1) var(--pxn-ease),
    border-color var(--pxn-dur-1) var(--pxn-ease),
    color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-btn--sm { --_h: var(--pxn-control-h-sm); --_px: var(--pxn-space-5); --_fs: var(--pxn-fs-sm); }
.pxn-btn--lg { --_h: var(--pxn-control-h-lg); --_px: var(--pxn-space-7); }
.pxn-btn--block { display: flex; width: 100%; }
.pxn-btn--icon { --_px: 0; width: var(--_h); }

.pxn-btn[disabled],
.pxn-btn[aria-disabled="true"] { cursor: not-allowed; opacity: 0.5; }
.pxn-btn.is-loading { cursor: progress; }

/* primary: el acento del tenant, usado una vez por grupo de acciones */
.pxn-btn--primary {
  background: var(--pxn-primary);
  color: var(--pxn-primary-contrast);
}
.pxn-btn--primary:hover:not([disabled]):not([aria-disabled="true"]) { background: var(--pxn-primary-hover); }
.pxn-btn--primary:active:not([disabled]) { background: var(--pxn-primary-active); }

/* secondary: outline neutral */
.pxn-btn--secondary {
  background: var(--pxn-surface);
  border-color: var(--pxn-border-control);
  color: var(--pxn-ink);
}
.pxn-btn--secondary:hover:not([disabled]) { background: var(--pxn-surface-2); border-color: var(--pxn-border-strong); }
.pxn-btn--secondary:active:not([disabled]) { background: var(--pxn-surface-3); }

/* ghost: sin chrome hasta hover */
.pxn-btn--ghost { background: transparent; color: var(--pxn-ink-2); }
.pxn-btn--ghost:hover:not([disabled]) { background: var(--pxn-surface-2); color: var(--pxn-ink); }
.pxn-btn--ghost:active:not([disabled]) { background: var(--pxn-surface-3); }

/* subtle: tintado con el acento del tenant, bajo peso */
.pxn-btn--subtle { background: var(--pxn-primary-soft); color: var(--pxn-primary-ink); }
.pxn-btn--subtle:hover:not([disabled]) { background: var(--pxn-primary-softer); }

/* danger: solo destructivo */
.pxn-btn--danger { background: var(--pxn-danger); color: #fff; }
.pxn-btn--danger:hover:not([disabled]) { background: color-mix(in srgb, var(--pxn-danger) 86%, #000); }

/* link */
.pxn-btn--link {
  --_px: 0; --_h: auto;
  background: transparent;
  color: var(--pxn-primary-ink);
  font-weight: var(--pxn-fw-medium);
}
.pxn-btn--link:hover:not([disabled]) { text-decoration: underline; }

.pxn-btn__icon { flex: none; }
.pxn-btn__label { display: inline-flex; }

/* #13 · destructivo compacto (icon-only): reposo idéntico a la variante recibida (ghost o danger),
   hover/focus-visible intensifica hacia danger-soft, el icono se desplaza sutilmente y el nombre
   accesible se revela en una etiqueta flotante fuera del flujo — el botón nunca cambia de tamaño. */
.pxn-btn--destructive { position: relative; }
.pxn-btn--destructive:hover:not([disabled]):not([aria-disabled="true"]),
.pxn-btn--destructive:focus-visible:not([disabled]) {
  background: var(--pxn-danger-soft);
  border-color: var(--pxn-danger-border);
  color: var(--pxn-danger-ink);
}
.pxn-btn--destructive .pxn-btn__icon { transition: transform var(--pxn-dur-1) var(--pxn-ease); }
.pxn-btn--destructive:hover:not([disabled]) .pxn-btn__icon,
.pxn-btn--destructive:focus-visible:not([disabled]) .pxn-btn__icon { transform: translateY(1px); }

.pxn-btn__hint {
  position: absolute;
  left: 50%;
  bottom: calc(100% + var(--pxn-space-3));
  transform: translate(-50%, 4px);
  padding: var(--pxn-space-2) var(--pxn-space-4);
  border-radius: var(--pxn-radius-sm);
  background: var(--pxn-ink);
  color: var(--pxn-surface);
  font-size: var(--pxn-fs-xs);
  font-weight: var(--pxn-fw-medium);
  white-space: nowrap;
  opacity: 0;
  pointer-events: none;
  z-index: 40;
  transition: opacity var(--pxn-dur-1) var(--pxn-ease), transform var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-btn--destructive:hover:not([disabled]) .pxn-btn__hint,
.pxn-btn--destructive:focus-visible:not([disabled]) .pxn-btn__hint {
  opacity: 1;
  transform: translate(-50%, 0);
}

@media (prefers-reduced-motion: reduce) {
  .pxn-btn--destructive .pxn-btn__icon,
  .pxn-btn__hint { transition: none; }
  .pxn-btn--destructive:hover:not([disabled]) .pxn-btn__icon,
  .pxn-btn--destructive:focus-visible:not([disabled]) .pxn-btn__icon { transform: none; }
}

.pxn-btn__spinner {
  width: 14px; height: 14px; flex: none;
  border: 2px solid currentColor;
  border-right-color: transparent;
  border-radius: 999px;
  animation: pxn-btn-spin 0.6s linear infinite;
}
@keyframes pxn-btn-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) {
  .pxn-btn__spinner { animation-duration: 0.001ms; border-right-color: currentColor; opacity: 0.5; }
}
</style>
