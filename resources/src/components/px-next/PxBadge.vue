<template>
  <span class="pxn-badge" :class="[`pxn-badge--${tone}`, { 'pxn-badge--solid': solid, 'pxn-badge--dot-only': dotOnly }]">
    <span v-if="dot || dotOnly" class="pxn-badge__dot" aria-hidden="true"></span>
    <lucide-icon v-else-if="icon" :name="icon" :size="12" class="pxn-badge__icon" />
    <span v-if="!dotOnly" class="pxn-badge__label"><slot /></span>
  </span>
</template>

<script>
// Status pill. Semantic tones only (success/warning/danger/info/neutral). Every
// badge carries a label AND an icon-or-dot — status is never conveyed by colour
// alone. For categories/types use PxTag, not PxBadge.
export default {
  name: "PxBadge",
  props: {
    tone: { type: String, default: "neutral" }, // success | warning | danger | info | neutral
    icon: { type: String, default: null },
    dot: { type: Boolean, default: false },
    dotOnly: { type: Boolean, default: false },
    solid: { type: Boolean, default: false }
  }
};
</script>

<style lang="scss" scoped>
.pxn-badge {
  display: inline-flex;
  align-items: center;
  gap: var(--pxn-space-3);
  height: 22px;
  padding: 0 var(--pxn-space-4);
  border-radius: var(--pxn-radius-pill);
  border: 1px solid transparent;
  font-size: var(--pxn-fs-xs);
  font-weight: var(--pxn-fw-semibold);
  line-height: 1;
  white-space: nowrap;
}
.pxn-badge--dot-only { padding: 0; width: 22px; justify-content: center; }
.pxn-badge__dot { width: 6px; height: 6px; border-radius: 999px; background: currentColor; flex: none; }
.pxn-badge__icon { flex: none; margin-left: -2px; }

@mixin tone($ink, $soft, $border) {
  color: $ink; background: $soft; border-color: $border;
  &.pxn-badge--solid { color: #fff; background: currentColor; border-color: transparent; }
}
.pxn-badge--success { @include tone(var(--pxn-success-ink), var(--pxn-success-soft), var(--pxn-success-border)); }
.pxn-badge--warning { @include tone(var(--pxn-warning-ink), var(--pxn-warning-soft), var(--pxn-warning-border)); }
.pxn-badge--danger  { @include tone(var(--pxn-danger-ink),  var(--pxn-danger-soft),  var(--pxn-danger-border)); }
.pxn-badge--info    { @include tone(var(--pxn-info-ink),     var(--pxn-info-soft),     var(--pxn-info-border)); }
.pxn-badge--neutral { @include tone(var(--pxn-neutral-ink),  var(--pxn-neutral-soft),  var(--pxn-neutral-border)); }
.pxn-badge--solid .pxn-badge__label { color: #fff; }
</style>
