<template>
  <section class="pxn-card" :class="{ 'pxn-card--flush': flush, 'pxn-card--interactive': interactive }">
    <header v-if="title || $slots.header || $slots.actions" class="pxn-card__head">
      <div class="pxn-card__heading">
        <h3 v-if="title" class="pxn-card__title">{{ title }}</h3>
        <p v-if="subtitle" class="pxn-card__subtitle">{{ subtitle }}</p>
        <slot name="header" />
      </div>
      <div v-if="$slots.actions" class="pxn-card__actions"><slot name="actions" /></div>
    </header>
    <div class="pxn-card__body" :class="{ 'is-flush': flush }"><slot /></div>
    <footer v-if="$slots.footer" class="pxn-card__foot"><slot name="footer" /></footer>
  </section>
</template>

<script>
// Static container. Border, never a shadow (shadow is for floating things).
// `interactive` adds a hover lift for cards that are themselves a link/target.
export default {
  name: "PxCard",
  props: {
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    flush: { type: Boolean, default: false },
    interactive: { type: Boolean, default: false }
  }
};
</script>

<style lang="scss" scoped>
.pxn-card {
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-lg);
  overflow: clip;
}
.pxn-card--interactive {
  transition: border-color var(--pxn-dur-1) var(--pxn-ease), box-shadow var(--pxn-dur-1) var(--pxn-ease);
  cursor: pointer;
}
.pxn-card--interactive:hover { border-color: var(--pxn-border-strong); box-shadow: var(--pxn-shadow-card-hover); }

.pxn-card__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--pxn-space-5);
  padding: var(--pxn-space-6) var(--pxn-space-7);
  border-bottom: 1px solid var(--pxn-border);
}
.pxn-card__title { font-size: var(--pxn-fs-h2); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); letter-spacing: -0.01em; }
.pxn-card__subtitle { margin-top: 2px; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxn-card__actions { display: flex; align-items: center; gap: var(--pxn-space-3); flex: none; }
.pxn-card__body { padding: var(--pxn-space-7); }
.pxn-card__body.is-flush { padding: 0; }
.pxn-card__foot {
  padding: var(--pxn-space-5) var(--pxn-space-7);
  border-top: 1px solid var(--pxn-border);
  background: var(--pxn-surface-2);
}
</style>
