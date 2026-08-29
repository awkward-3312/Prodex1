<template>
  <component
    :is="tag"
    class="pxn-btn pxn-ring"
    :class="[
      `pxn-btn--${variant}`,
      `pxn-btn--${size}`,
      { 'pxn-btn--block': block, 'pxn-btn--icon': iconOnly, 'is-loading': loading }
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
  </component>
</template>

<script>
// PxButton — the only button in px-next. Tenant primary carries "primary";
// every other variant is neutral so a screen never has two competing accents.
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
    disabled: { type: Boolean, default: false }
  },
  computed: {
    tag() { return this.href ? "a" : "button"; },
    iconSize() { return this.size === "sm" ? 14 : this.size === "lg" ? 18 : 16; }
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

/* primary — the tenant accent, used once per action group */
.pxn-btn--primary {
  background: var(--pxn-primary);
  color: var(--pxn-primary-contrast);
}
.pxn-btn--primary:hover:not([disabled]):not([aria-disabled="true"]) { background: var(--pxn-primary-hover); }
.pxn-btn--primary:active:not([disabled]) { background: var(--pxn-primary-active); }

/* secondary — neutral outline */
.pxn-btn--secondary {
  background: var(--pxn-surface);
  border-color: var(--pxn-border-control);
  color: var(--pxn-ink);
}
.pxn-btn--secondary:hover:not([disabled]) { background: var(--pxn-surface-2); border-color: var(--pxn-border-strong); }
.pxn-btn--secondary:active:not([disabled]) { background: var(--pxn-surface-3); }

/* ghost — no chrome until hover */
.pxn-btn--ghost { background: transparent; color: var(--pxn-ink-2); }
.pxn-btn--ghost:hover:not([disabled]) { background: var(--pxn-surface-2); color: var(--pxn-ink); }
.pxn-btn--ghost:active:not([disabled]) { background: var(--pxn-surface-3); }

/* subtle — tinted with the tenant accent, low weight */
.pxn-btn--subtle { background: var(--pxn-primary-soft); color: var(--pxn-primary-ink); }
.pxn-btn--subtle:hover:not([disabled]) { background: var(--pxn-primary-softer); }

/* danger — destructive only */
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
