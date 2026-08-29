<template>
  <div class="pxn-stat" :class="{ 'pxn-stat--bordered': bordered }">
    <div class="pxn-stat__top">
      <span class="pxn-stat__label">{{ label }}</span>
      <lucide-icon v-if="icon" :name="icon" :size="15" class="pxn-stat__icon" />
    </div>
    <div class="pxn-stat__value pxn-num">
      <span v-if="prefix" class="pxn-stat__affix">{{ prefix }}</span>{{ value }}<span v-if="suffix" class="pxn-stat__affix">{{ suffix }}</span>
    </div>
    <div v-if="delta || sub" class="pxn-stat__foot">
      <span
        v-if="delta"
        class="pxn-stat__delta"
        :class="`is-${deltaTone}`"
      >
        <lucide-icon :name="deltaIcon" :size="13" />
        <span class="pxn-num">{{ delta }}</span>
      </span>
      <span v-if="sub" class="pxn-stat__sub">{{ sub }}</span>
    </div>
  </div>
</template>

<script>
// KPI figure. Numbers render immediately — no count-up animation. Delta tone
// is explicit (never inferred from a leading "+"/"-"), and always paired with
// an arrow glyph so meaning is not colour-only.
export default {
  name: "PxStat",
  props: {
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    prefix: { type: String, default: null },
    suffix: { type: String, default: null },
    delta: { type: String, default: null },
    deltaTone: { type: String, default: "neutral" }, // up | down | neutral
    sub: { type: String, default: null },
    icon: { type: String, default: null },
    bordered: { type: Boolean, default: false }
  },
  computed: {
    deltaIcon() {
      return this.deltaTone === "up" ? "trending-up" : this.deltaTone === "down" ? "trending-down" : "minus";
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-stat { display: flex; flex-direction: column; gap: var(--pxn-space-4); min-width: 0; }
.pxn-stat--bordered {
  padding: var(--pxn-space-6);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-lg);
  background: var(--pxn-surface);
}
.pxn-stat__top { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-4); }
.pxn-stat__label {
  font-size: var(--pxn-fs-sm);
  font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink-2);
}
.pxn-stat__icon { color: var(--pxn-ink-3); flex: none; }
.pxn-stat__value {
  font-size: var(--pxn-fs-kpi);
  font-weight: var(--pxn-fw-bold);
  line-height: var(--pxn-lh-tight);
  color: var(--pxn-ink);
  letter-spacing: -0.02em;
}
.pxn-stat__affix { font-size: 0.62em; font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink-2); }
.pxn-stat__foot { display: flex; align-items: center; gap: var(--pxn-space-4); flex-wrap: wrap; }
.pxn-stat__delta {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  font-size: var(--pxn-fs-sm);
  font-weight: var(--pxn-fw-semibold);
}
.pxn-stat__delta.is-up { color: var(--pxn-success-ink); }
.pxn-stat__delta.is-down { color: var(--pxn-danger-ink); }
.pxn-stat__delta.is-neutral { color: var(--pxn-ink-3); }
.pxn-stat__sub { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
</style>
