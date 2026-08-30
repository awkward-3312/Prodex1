<template>
  <header class="pxn-shead">
    <div class="pxn-shead__num pxn-mono">{{ num }}</div>
    <div class="pxn-shead__text">
      <h2>{{ title }}</h2>
      <p v-if="desc">{{ desc }}</p>
    </div>
    <div v-if="$slots.aside" class="pxn-shead__aside"><slot name="aside" /></div>
  </header>
</template>

<script>
export default {
  name: "PxSectionHead",
  props: {
    num: { type: String, default: "" },
    title: { type: String, required: true },
    desc: { type: String, default: null }
  }
};
</script>

<style lang="scss" scoped>
.pxn-shead { display: flex; align-items: flex-start; gap: var(--pxn-space-6); margin-bottom: var(--pxn-space-8); }
.pxn-shead__num {
  flex: none;
  width: 34px; height: 34px;
  display: inline-flex; align-items: center; justify-content: center;
  border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface-2); color: var(--pxn-ink-3);
  font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold);
}
.pxn-shead__text { flex: 1; min-width: 0; }
.pxn-shead__text h2 { font-size: var(--pxn-fs-h1); font-weight: var(--pxn-fw-bold); letter-spacing: -0.02em; color: var(--pxn-ink); }
.pxn-shead__text p { margin-top: var(--pxn-space-3); font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); max-width: 68ch; line-height: var(--pxn-lh-snug); }
.pxn-shead__aside { flex: none; }
</style>
