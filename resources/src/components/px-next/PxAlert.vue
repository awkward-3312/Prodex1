<template>
  <div class="pxn-alert" :class="[`pxn-alert--${tone}`, { 'pxn-alert--bare': bare }]" role="status">
    <lucide-icon :name="resolvedIcon" :size="16" class="pxn-alert__icon" />
    <div class="pxn-alert__content">
      <p v-if="title" class="pxn-alert__title">{{ title }}</p>
      <div class="pxn-alert__text"><slot /></div>
      <div v-if="$slots.actions" class="pxn-alert__actions"><slot name="actions" /></div>
    </div>
    <button v-if="dismissible" type="button" class="pxn-alert__x pxn-ring" aria-label="Descartar" @click="$emit('dismiss')">
      <lucide-icon name="x" :size="14" />
    </button>
  </div>
</template>

<script>
const ICONS = { info: "info", success: "check-circle", warning: "alert-triangle", danger: "alert-circle" };
export default {
  name: "PxAlert",
  props: {
    tone: { type: String, default: "info" }, // info | success | warning | danger
    title: { type: String, default: null },
    icon: { type: String, default: null },
    dismissible: { type: Boolean, default: false },
    bare: { type: Boolean, default: false }
  },
  computed: { resolvedIcon() { return this.icon || ICONS[this.tone] || "info"; } }
};
</script>

<style lang="scss" scoped>
.pxn-alert {
  display: flex;
  align-items: flex-start;
  gap: var(--pxn-space-4);
  padding: var(--pxn-space-5) var(--pxn-space-5);
  border: 1px solid transparent;
  border-radius: var(--pxn-radius-md);
  font-size: var(--pxn-fs-body);
}
.pxn-alert--bare { border-radius: 0; border-width: 0 0 0 3px; }
.pxn-alert__icon { flex: none; margin-top: 1px; }
.pxn-alert__content { flex: 1; min-width: 0; }
.pxn-alert__title { font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); margin-bottom: 2px; }
.pxn-alert__text { color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxn-alert__actions { display: flex; gap: var(--pxn-space-4); margin-top: var(--pxn-space-4); }
.pxn-alert__x {
  flex: none;
  display: inline-flex; align-items: center; justify-content: center;
  width: 22px; height: 22px; margin: -2px -2px 0 0;
  border: 0; border-radius: var(--pxn-radius-xs);
  background: transparent; color: var(--pxn-ink-3); cursor: pointer;
}
.pxn-alert__x:hover { background: rgba(0, 0, 0, 0.05); color: var(--pxn-ink); }

.pxn-alert--info    { background: var(--pxn-info-soft);    border-color: var(--pxn-info-border);    }
.pxn-alert--info .pxn-alert__icon    { color: var(--pxn-info); }
.pxn-alert--success { background: var(--pxn-success-soft); border-color: var(--pxn-success-border); }
.pxn-alert--success .pxn-alert__icon { color: var(--pxn-success); }
.pxn-alert--warning { background: var(--pxn-warning-soft); border-color: var(--pxn-warning-border); }
.pxn-alert--warning .pxn-alert__icon { color: var(--pxn-warning); }
.pxn-alert--danger  { background: var(--pxn-danger-soft);  border-color: var(--pxn-danger-border);  }
.pxn-alert--danger .pxn-alert__icon  { color: var(--pxn-danger); }
</style>
