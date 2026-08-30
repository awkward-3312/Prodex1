<template>
  <header class="pxn-pagehead">
    <nav v-if="breadcrumbs.length" class="pxn-pagehead__crumbs" aria-label="Ruta">
      <template v-for="(c, i) in breadcrumbs">
        <a v-if="c.href && i < breadcrumbs.length - 1" :key="`c${i}`" :href="c.href" class="pxn-pagehead__crumb">{{ c.label }}</a>
        <span v-else :key="`c${i}`" class="pxn-pagehead__crumb is-current" aria-current="page">{{ c.label }}</span>
        <lucide-icon v-if="i < breadcrumbs.length - 1" :key="`s${i}`" name="chevron-right" :size="13" class="pxn-pagehead__sep" />
      </template>
    </nav>

    <div class="pxn-pagehead__row">
      <div class="pxn-pagehead__titles">
        <div class="pxn-pagehead__titleline">
          <h1 class="pxn-pagehead__title">{{ title }}</h1>
          <slot name="title-badge" />
        </div>
        <p v-if="subtitle" class="pxn-pagehead__subtitle">{{ subtitle }}</p>
        <div v-if="$slots.meta" class="pxn-pagehead__meta"><slot name="meta" /></div>
      </div>
      <div v-if="$slots.actions" class="pxn-pagehead__actions"><slot name="actions" /></div>
    </div>

    <div v-if="$slots.tabs" class="pxn-pagehead__tabs"><slot name="tabs" /></div>
  </header>
</template>

<script>
export default {
  name: "PxPageHeader",
  props: {
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    breadcrumbs: { type: Array, default: () => [] }
  }
};
</script>

<style lang="scss" scoped>
.pxn-pagehead { display: flex; flex-direction: column; gap: var(--pxn-space-5); }
.pxn-pagehead__crumbs { display: flex; align-items: center; gap: var(--pxn-space-2); font-size: var(--pxn-fs-xs); }
.pxn-pagehead__crumb { color: var(--pxn-ink-3); }
.pxn-pagehead__crumb.is-current { color: var(--pxn-ink-2); font-weight: var(--pxn-fw-medium); }
.pxn-pagehead__sep { color: var(--pxn-ink-disabled); }

.pxn-pagehead__row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--pxn-space-6);
  flex-wrap: wrap;
}
.pxn-pagehead__titleline { display: flex; align-items: center; gap: var(--pxn-space-4); }
.pxn-pagehead__title {
  font-size: var(--pxn-fs-display);
  font-weight: var(--pxn-fw-bold);
  line-height: var(--pxn-lh-tight);
  letter-spacing: -0.025em;
  color: var(--pxn-ink);
}
.pxn-pagehead__subtitle { margin-top: var(--pxn-space-3); font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); max-width: 62ch; }
.pxn-pagehead__meta { margin-top: var(--pxn-space-4); display: flex; align-items: center; gap: var(--pxn-space-5); flex-wrap: wrap; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxn-pagehead__actions { display: flex; align-items: center; gap: var(--pxn-space-4); flex: none; }
.pxn-pagehead__tabs { margin-top: var(--pxn-space-2); }
</style>
