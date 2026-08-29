<template>
  <div class="pxn-table-wrap" :class="[`is-${density}`, { 'is-sticky-col': stickyFirst, 'has-check': selectable }]">
    <div class="pxn-table-scroll pxn-scroll" role="region" aria-label="Tabla de datos" tabindex="0">
      <table class="pxn-table">
        <colgroup>
          <col v-if="selectable" style="width:44px" />
          <col v-for="col in columns" :key="`c-${col.key}`" :style="col.width ? `width:${col.width}` : null" />
          <col v-if="hasRowActions" style="width:52px" />
        </colgroup>

        <thead>
          <tr>
            <th v-if="selectable" class="pxn-th pxn-th--check" scope="col">
              <px-check
                type="checkbox"
                :model-value="allSelected"
                :aria-label="allSelected ? 'Deseleccionar todo' : 'Seleccionar todo'"
                @change="toggleAll"
              />
            </th>
            <th
              v-for="(col, ci) in columns"
              :key="`h-${col.key}`"
              class="pxn-th"
              :class="[`is-${col.align || 'left'}`, { 'is-sortable': col.sortable, 'pxn-th--pin': stickyFirst && ci === 0 }]"
              scope="col"
              :aria-sort="ariaSort(col)"
            >
              <button v-if="col.sortable" type="button" class="pxn-th__sort pxn-ring" @click="sort(col)">
                <span>{{ col.label }}</span>
                <span class="pxn-th__caret" :class="caretState(col)" aria-hidden="true">
                  <lucide-icon name="chevron-up" :size="11" />
                  <lucide-icon name="chevron-down" :size="11" />
                </span>
              </button>
              <span v-else>{{ col.label }}</span>
            </th>
            <th v-if="hasRowActions" class="pxn-th pxn-th--actions" scope="col"><span class="pxn-sr">Acciones</span></th>
          </tr>
        </thead>

        <tbody>
          <tr
            v-for="(row, ri) in rows"
            :key="rowKey ? row[rowKey] : ri"
            class="pxn-tr"
            :class="{ 'is-selected': isSelected(row) }"
          >
            <td v-if="selectable" class="pxn-td pxn-td--check">
              <px-check
                type="checkbox"
                :model-value="isSelected(row)"
                :aria-label="`Seleccionar fila ${ri + 1}`"
                @change="toggleRow(row)"
              />
            </td>
            <td
              v-for="(col, ci) in columns"
              :key="`${ri}-${col.key}`"
              class="pxn-td"
              :class="[`is-${col.align || 'left'}`, { 'pxn-num': col.numeric, 'is-strong': col.strong, 'pxn-td--pin': stickyFirst && ci === 0 }]"
            >
              <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]" :col="col">
                {{ formatCell(row[col.key], col) }}
              </slot>
            </td>
            <td v-if="hasRowActions" class="pxn-td pxn-td--actions">
              <slot name="row-actions" :row="row" :index="ri" />
            </td>
          </tr>

          <tr v-if="!rows.length">
            <td class="pxn-td pxn-td--empty" :colspan="totalCols">
              <slot name="empty"><span class="pxn-table__emptytext">Sin resultados</span></slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script>
import PxCheck from "./PxCheck.vue";
// Dense ERP table.
//   · sticky header, optional sticky first column
//   · numeric columns right-aligned + tabular figures
//   · sort is a real button in the header (keyboard + aria-sort)
//   · density: comfortable 44px / compact 36px (token-driven)
//   · selection is controlled via v-model on `selected`
export default {
  name: "PxTable",
  components: { PxCheck },
  model: { prop: "selected", event: "update:selected" },
  props: {
    columns: { type: Array, required: true }, // [{ key, label, align?, numeric?, sortable?, strong?, width?, format? }]
    rows: { type: Array, required: true },
    rowKey: { type: String, default: "id" },
    selectable: { type: Boolean, default: false },
    selected: { type: Array, default: () => [] },
    density: { type: String, default: "comfortable" }, // comfortable | compact
    sortKey: { type: String, default: null },
    sortDir: { type: String, default: "asc" },
    hasRowActions: { type: Boolean, default: false },
    stickyFirst: { type: Boolean, default: false }
  },
  computed: {
    totalCols() {
      return this.columns.length + (this.selectable ? 1 : 0) + (this.hasRowActions ? 1 : 0);
    },
    allSelected() {
      return this.rows.length > 0 && this.rows.every(r => this.isSelected(r));
    }
  },
  methods: {
    keyOf(row) { return this.rowKey ? row[this.rowKey] : row; },
    isSelected(row) { return this.selected.includes(this.keyOf(row)); },
    toggleRow(row) {
      const k = this.keyOf(row);
      const next = this.selected.slice();
      const i = next.indexOf(k);
      i === -1 ? next.push(k) : next.splice(i, 1);
      this.$emit("update:selected", next);
    },
    toggleAll() {
      this.$emit("update:selected", this.allSelected ? [] : this.rows.map(r => this.keyOf(r)));
    },
    sort(col) {
      let dir = "asc";
      if (this.sortKey === col.key) dir = this.sortDir === "asc" ? "desc" : "asc";
      this.$emit("update:sortKey", col.key);
      this.$emit("update:sortDir", dir);
      this.$emit("sort", { key: col.key, dir });
    },
    caretState(col) {
      if (this.sortKey !== col.key) return "is-none";
      return this.sortDir === "asc" ? "is-asc" : "is-desc";
    },
    ariaSort(col) {
      if (!col.sortable) return null;
      if (this.sortKey !== col.key) return "none";
      return this.sortDir === "asc" ? "ascending" : "descending";
    },
    formatCell(v, col) {
      if (typeof col.format === "function") return col.format(v);
      return v == null ? "—" : v;
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-table-wrap { --_row-h: var(--pxn-row-h); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); overflow: hidden; }
.pxn-table-wrap.is-compact { --_row-h: var(--pxn-row-h-compact); }
.pxn-table-scroll { overflow: auto; max-height: 100%; }

.pxn-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  font-size: var(--pxn-fs-body);
}
.pxn-sr, .pxn-table__emptytext { }
.pxn-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }

.pxn-th {
  position: sticky;
  top: 0;
  z-index: var(--pxn-z-sticky);
  height: 38px;
  padding: 0 var(--pxn-cell-px);
  background: var(--pxn-surface-2);
  border-bottom: 1px solid var(--pxn-border);
  font-size: var(--pxn-fs-xs);
  font-weight: var(--pxn-fw-semibold);
  color: var(--pxn-ink-2);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  text-align: left;
  white-space: nowrap;
}
.pxn-th.is-right { text-align: right; }
.pxn-th.is-center { text-align: center; }
.pxn-th--check, .pxn-td--check { padding-left: var(--pxn-space-5); width: 44px; }
.pxn-th--actions, .pxn-td--actions { width: 52px; text-align: right; padding-right: var(--pxn-space-5); }

.pxn-th__sort {
  display: inline-flex;
  align-items: center;
  gap: var(--pxn-space-2);
  border: 0;
  background: transparent;
  font: inherit;
  font-size: inherit;
  font-weight: inherit;
  letter-spacing: inherit;
  text-transform: inherit;
  color: inherit;
  cursor: pointer;
}
.is-right .pxn-th__sort { flex-direction: row-reverse; }
.pxn-th__sort:hover { color: var(--pxn-ink); }
.pxn-th__caret { display: inline-flex; flex-direction: column; line-height: 0; color: var(--pxn-ink-disabled); }
.pxn-th__caret svg { display: block; margin: -2px 0; }
.pxn-th__caret.is-asc :first-child { color: var(--pxn-primary); }
.pxn-th__caret.is-desc :last-child { color: var(--pxn-primary); }

.pxn-tr { transition: background-color var(--pxn-dur-1) var(--pxn-ease); }
.pxn-tr:hover { background: var(--pxn-surface-hover); }
.pxn-tr.is-selected { background: var(--pxn-selected-bg); }
.pxn-tr.is-selected:hover { background: color-mix(in srgb, var(--pxn-primary) 12%, #fff); }

.pxn-td {
  height: var(--_row-h);
  padding: 0 var(--pxn-cell-px);
  border-bottom: 1px solid var(--pxn-border);
  color: var(--pxn-ink-2);
  vertical-align: middle;
  white-space: nowrap;
}
.pxn-tr:last-child .pxn-td { border-bottom: 0; }
.pxn-td.is-right { text-align: right; }
.pxn-td.is-center { text-align: center; }
.pxn-td.is-strong { color: var(--pxn-ink); font-weight: var(--pxn-fw-medium); }
.pxn-td--empty { text-align: center; height: 120px; color: var(--pxn-ink-3); }

/* sticky first data column — cells are tagged explicitly (--pin) so it works
   with or without the checkbox column in front of it */
.pxn-th--pin, .pxn-td--pin {
  position: sticky;
  left: 0;
  background: var(--pxn-surface);
  box-shadow: 1px 0 0 var(--pxn-border);
}
.pxn-td--pin { z-index: 1; }
.pxn-th--pin { z-index: calc(var(--pxn-z-sticky) + 1); background: var(--pxn-surface-2); }
.is-sticky-col.has-check .pxn-td--check { position: sticky; left: 0; z-index: 1; background: var(--pxn-surface); box-shadow: none; }
.is-sticky-col.has-check .pxn-th--check { position: sticky; left: 0; z-index: calc(var(--pxn-z-sticky) + 1); background: var(--pxn-surface-2); }
.is-sticky-col.has-check .pxn-td--pin,
.is-sticky-col.has-check .pxn-th--pin { left: 44px; }
.pxn-tr:hover .pxn-td--pin { background: var(--pxn-surface-hover); }
.pxn-tr.is-selected .pxn-td--pin { background: var(--pxn-selected-bg); }
.pxn-tr:hover .pxn-td--check { background: var(--pxn-surface-hover); }
.pxn-tr.is-selected .pxn-td--check { background: var(--pxn-selected-bg); }
</style>
