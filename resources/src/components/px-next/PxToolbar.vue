<template>
  <div class="pxn-toolbar">
    <div class="pxn-toolbar__lead">
      <div v-if="searchable" class="pxn-toolbar__search">
        <lucide-icon name="search" :size="15" class="pxn-toolbar__search-ico" />
        <input
          class="pxn-toolbar__search-input pxn-ring"
          type="search"
          :value="search"
          :placeholder="searchPlaceholder"
          @input="$emit('update:search', $event.target.value)"
        />
      </div>
      <slot name="lead" />
    </div>

    <div class="pxn-toolbar__filters">
      <slot name="filters" />
      <button
        v-if="filterCount != null"
        type="button"
        class="pxn-toolbar__filterbtn pxn-ring"
        :class="{ 'is-active': filterCount > 0 }"
        @click="$emit('open-filters')"
      >
        <lucide-icon name="filter" :size="14" />
        <span>Filtros</span>
        <span v-if="filterCount > 0" class="pxn-toolbar__filtercount pxn-num">{{ filterCount }}</span>
      </button>
    </div>

    <div class="pxn-toolbar__trail">
      <slot name="trail" />
      <div v-if="views.length" class="pxn-toolbar__viewswitch" role="group" aria-label="Vista">
        <button
          v-for="v in views"
          :key="v.value"
          type="button"
          class="pxn-toolbar__viewbtn pxn-ring"
          :class="{ 'is-active': v.value === view }"
          :aria-pressed="v.value === view ? 'true' : 'false'"
          :aria-label="v.label"
          @click="$emit('update:view', v.value)"
        >
          <lucide-icon :name="v.icon" :size="15" />
        </button>
      </div>
      <slot name="actions" />
    </div>
  </div>
</template>

<script>
// Operate-mode toolbar: search + filter affordances + view switch + primary
// actions. All controls slotted where possible so a screen composes what it
// needs; the search + filter button are common enough to be built in.
export default {
  name: "PxToolbar",
  props: {
    search: { type: String, default: "" },
    searchable: { type: Boolean, default: true },
    searchPlaceholder: { type: String, default: "Buscar…" },
    filterCount: { type: Number, default: null },
    view: { type: String, default: null },
    views: { type: Array, default: () => [] } // [{ value, label, icon }]
  }
};
</script>

<style lang="scss" scoped>
.pxn-toolbar {
  display: flex;
  align-items: center;
  gap: var(--pxn-space-5);
  flex-wrap: wrap;
}
.pxn-toolbar__lead { display: flex; align-items: center; gap: var(--pxn-space-4); flex: 1 1 240px; min-width: 0; }
.pxn-toolbar__filters { display: flex; align-items: center; gap: var(--pxn-space-3); flex-wrap: wrap; }
.pxn-toolbar__trail { display: flex; align-items: center; gap: var(--pxn-space-4); margin-left: auto; flex-wrap: wrap; }

@media (max-width: 640px) {
  .pxn-toolbar__lead { flex: 1 1 100%; }
  .pxn-toolbar__trail { margin-left: 0; flex: 1 1 100%; }
  .pxn-toolbar__trail ::v-deep .pxn-btn { flex: 1 1 auto; }
}

.pxn-toolbar__search { position: relative; display: flex; align-items: center; flex: 1 1 auto; max-width: 340px; min-width: 180px; }
.pxn-toolbar__search-ico { position: absolute; left: var(--pxn-space-5); color: var(--pxn-ink-3); pointer-events: none; }
.pxn-toolbar__search-input {
  width: 100%;
  height: var(--pxn-control-h-md);
  padding: 0 var(--pxn-space-5) 0 calc(var(--pxn-space-5) + 20px);
  border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
  font: inherit;
  font-size: var(--pxn-fs-body);
  color: var(--pxn-ink);
}
.pxn-toolbar__search-input::placeholder { color: var(--pxn-ink-3); }
.pxn-toolbar__search-input:hover { border-color: var(--pxn-border-strong); }

.pxn-toolbar__filterbtn,
.pxn-toolbar__viewbtn {
  display: inline-flex;
  align-items: center;
  gap: var(--pxn-space-3);
  height: var(--pxn-control-h-md);
  padding: 0 var(--pxn-space-5);
  border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
  font: inherit;
  font-size: var(--pxn-fs-body);
  font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink-2);
  cursor: pointer;
  transition: background-color var(--pxn-dur-1) var(--pxn-ease), border-color var(--pxn-dur-1) var(--pxn-ease), color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-toolbar__filterbtn:hover { background: var(--pxn-surface-2); color: var(--pxn-ink); }
.pxn-toolbar__filterbtn.is-active { border-color: var(--pxn-primary-border); color: var(--pxn-primary-ink); background: var(--pxn-primary-softer); }
.pxn-toolbar__filtercount {
  min-width: 16px; height: 16px; padding: 0 4px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: var(--pxn-radius-pill);
  background: var(--pxn-primary); color: var(--pxn-primary-contrast);
  font-size: 10px; font-weight: var(--pxn-fw-bold);
}

.pxn-toolbar__viewswitch { display: inline-flex; padding: 2px; gap: 2px; border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); }
.pxn-toolbar__viewbtn { height: 30px; width: 32px; padding: 0; justify-content: center; border: 0; background: transparent; }
.pxn-toolbar__viewbtn.is-active { background: var(--pxn-surface); color: var(--pxn-ink); box-shadow: 0 1px 2px rgba(16,24,40,0.08); }
</style>
