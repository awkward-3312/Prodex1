<template>
  <div class="pxn-empty" :class="{ 'pxn-empty--inline': inline }">
    <div class="pxn-empty__glyph" :class="`is-${tone}`" aria-hidden="true">
      <lucide-icon :name="icon" :size="inline ? 20 : 24" />
    </div>
    <h3 class="pxn-empty__title">{{ title }}</h3>
    <p v-if="description" class="pxn-empty__desc">{{ description }}</p>
    <div v-if="$slots.default" class="pxn-empty__actions"><slot /></div>
  </div>
</template>

<script>
// Empty / zero / no-results state. `tone` only tints the glyph chip — the
// message text carries the meaning.
export default {
  name: "PxEmptyState",
  props: {
    icon: { type: String, default: "inbox" },
    title: { type: String, required: true },
    description: { type: String, default: null },
    tone: { type: String, default: "neutral" }, // neutral | info | warning
    inline: { type: Boolean, default: false }
  }
};
</script>

<style lang="scss" scoped>
.pxn-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: var(--pxn-space-11) var(--pxn-space-8);
  gap: var(--pxn-space-4);
}
.pxn-empty--inline { padding: var(--pxn-space-9) var(--pxn-space-7); }
.pxn-empty__glyph {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 48px; height: 48px;
  border-radius: var(--pxn-radius-lg);
  margin-bottom: var(--pxn-space-2);
}
.pxn-empty--inline .pxn-empty__glyph { width: 40px; height: 40px; }
.pxn-empty__glyph.is-neutral { background: var(--pxn-surface-2); color: var(--pxn-ink-3); }
.pxn-empty__glyph.is-info { background: var(--pxn-info-soft); color: var(--pxn-info); }
.pxn-empty__glyph.is-warning { background: var(--pxn-warning-soft); color: var(--pxn-warning); }
.pxn-empty__title { font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxn-empty__desc { font-size: var(--pxn-fs-body); color: var(--pxn-ink-3); max-width: 42ch; line-height: var(--pxn-lh-snug); }
.pxn-empty__actions { display: flex; gap: var(--pxn-space-4); margin-top: var(--pxn-space-4); }
</style>
