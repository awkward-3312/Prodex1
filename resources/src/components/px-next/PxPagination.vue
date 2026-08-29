<template>
  <nav class="pxn-pg" aria-label="Paginación">
    <div class="pxn-pg__summary pxn-num">
      {{ rangeStart }}–{{ rangeEnd }} <span class="pxn-pg__of">de</span> {{ total }}
    </div>

    <div class="pxn-pg__controls">
      <label class="pxn-pg__size">
        <span>Filas</span>
        <px-select :value="String(perPage)" :options="perPageOptions" @input="changeSize" />
      </label>

      <div class="pxn-pg__pages">
        <button type="button" class="pxn-pg__btn pxn-ring" :disabled="page <= 1" aria-label="Anterior" @click="go(page - 1)">
          <lucide-icon name="chevron-left" :size="15" />
        </button>
        <button
          v-for="(p, i) in pageList"
          :key="i"
          type="button"
          class="pxn-pg__btn pxn-num pxn-ring"
          :class="{ 'is-active': p === page, 'is-gap': p === '…' }"
          :disabled="p === '…'"
          :aria-current="p === page ? 'page' : null"
          @click="p !== '…' && go(p)"
        >{{ p }}</button>
        <button type="button" class="pxn-pg__btn pxn-ring" :disabled="page >= pageCount" aria-label="Siguiente" @click="go(page + 1)">
          <lucide-icon name="chevron-right" :size="15" />
        </button>
      </div>
    </div>
  </nav>
</template>

<script>
import PxSelect from "./PxSelect.vue";
export default {
  name: "PxPagination",
  components: { PxSelect },
  props: {
    page: { type: Number, required: true },
    perPage: { type: Number, default: 25 },
    total: { type: Number, required: true },
    perPageOptions: { type: Array, default: () => ["10", "25", "50", "100"] }
  },
  computed: {
    pageCount() { return Math.max(1, Math.ceil(this.total / this.perPage)); },
    rangeStart() { return this.total === 0 ? 0 : (this.page - 1) * this.perPage + 1; },
    rangeEnd() { return Math.min(this.page * this.perPage, this.total); },
    pageList() {
      const n = this.pageCount;
      const c = this.page;
      if (n <= 7) return Array.from({ length: n }, (_, i) => i + 1);
      const out = [1];
      const lo = Math.max(2, c - 1);
      const hi = Math.min(n - 1, c + 1);
      if (lo > 2) out.push("…");
      for (let i = lo; i <= hi; i += 1) out.push(i);
      if (hi < n - 1) out.push("…");
      out.push(n);
      return out;
    }
  },
  methods: {
    go(p) { const t = Math.min(Math.max(1, p), this.pageCount); if (t !== this.page) this.$emit("update:page", t); },
    changeSize(v) { this.$emit("update:perPage", Number(v)); this.$emit("update:page", 1); }
  }
};
</script>

<style lang="scss" scoped>
.pxn-pg {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--pxn-space-6);
  flex-wrap: wrap;
  padding: var(--pxn-space-4) var(--pxn-space-5);
  font-size: var(--pxn-fs-sm);
  color: var(--pxn-ink-2);
}
.pxn-pg__of { color: var(--pxn-ink-3); }
.pxn-pg__controls { display: flex; align-items: center; gap: var(--pxn-space-6); }
.pxn-pg__size { display: inline-flex; align-items: center; gap: var(--pxn-space-3); color: var(--pxn-ink-3); }
.pxn-pg__size ::v-deep .pxn-select { height: var(--pxn-control-h-sm); width: auto; padding-right: 30px; }
.pxn-pg__pages { display: inline-flex; align-items: center; gap: 2px; }
.pxn-pg__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 30px; height: 30px;
  padding: 0 var(--pxn-space-3);
  border: 1px solid transparent;
  border-radius: var(--pxn-radius-sm);
  background: transparent;
  font: inherit;
  font-size: var(--pxn-fs-sm);
  color: var(--pxn-ink-2);
  cursor: pointer;
}
.pxn-pg__btn:hover:not(:disabled):not(.is-gap) { background: var(--pxn-surface-2); }
.pxn-pg__btn.is-active { background: var(--pxn-primary-soft); border-color: var(--pxn-primary-border); color: var(--pxn-primary-ink); font-weight: var(--pxn-fw-semibold); }
.pxn-pg__btn:disabled { color: var(--pxn-ink-disabled); cursor: default; }
.pxn-pg__btn.is-gap { cursor: default; }
</style>
