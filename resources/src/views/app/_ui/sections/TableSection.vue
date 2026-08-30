<template>
  <section>
    <section-head
      num="05"
      title="Tabla ERP densa"
      desc="El centro de gravedad. Encabezado y primera columna fijos, orden por columna con teclado, selección con barra de lote, densidad 44 / 36 px y celdas de entidad. Filas realistas de inventario multi-sucursal."
    />

    <px-card flush>
      <div class="ts-toolbar">
        <px-toolbar
          :search.sync="search"
          search-placeholder="Buscar por nombre, SKU o código…"
          :filter-count="activeFilterCount"
          :view.sync="view"
          :views="[
            { value: 'table', label: 'Tabla', icon: 'table' },
            { value: 'board', label: 'Tarjetas', icon: 'layout-grid' }
          ]"
          @open-filters="showFilters = !showFilters"
        >
          <template #filters>
            <px-tag v-for="(f, i) in appliedFilters" :key="i" :label="f" hue="slate" removable @remove="removeFilter(i)" />
          </template>
          <template #actions>
            <px-button variant="ghost" icon="download">Exportar</px-button>
            <px-button variant="primary" icon="plus">Nuevo producto</px-button>
          </template>
        </px-toolbar>

        <div v-if="showFilters" class="ts-filters">
          <label>Sucursal
            <select v-model="filters.branch"><option value="">Todas</option><option v-for="b in branches" :key="b" :value="b">{{ b }}</option></select>
          </label>
          <label>Categoría
            <select v-model="filters.category"><option value="">Todas</option><option v-for="c in cats" :key="c" :value="c">{{ c }}</option></select>
          </label>
          <label>Estado
            <select v-model="filters.state">
              <option value="">Todos</option>
              <option value="ok">Disponible</option><option value="por_vencer">Próx. a vencer</option><option value="cuarentena">En cuarentena</option>
            </select>
          </label>
          <px-button size="sm" variant="ghost" @click="resetFilters">Limpiar</px-button>
        </div>
      </div>

      <transition name="ts-bulk">
        <div v-if="selected.length" class="ts-bulk">
          <span><b class="pxn-num">{{ selected.length }}</b> seleccionados</span>
          <div class="ts-bulk__actions">
            <px-button size="sm" variant="secondary" icon="arrow-left-right">Trasladar</px-button>
            <px-button size="sm" variant="secondary" icon="tag">Cambiar categoría</px-button>
            <px-button size="sm" variant="secondary" icon="printer">Etiquetas</px-button>
            <px-button size="sm" variant="ghost" @click="selected = []">Cancelar</px-button>
          </div>
        </div>
      </transition>

      <div v-if="view === 'table'" class="ts-tablebox">
        <px-table
          :columns="columns"
          :rows="pageRows"
          :selected.sync="selected"
          :density="density"
          :sort-key.sync="sortKey"
          :sort-dir.sync="sortDir"
          selectable
          has-row-actions
          sticky-first
          @sort="onSort"
        >
          <template #cell-name="{ row }">
            <px-entity-cell :name="row.name" :secondary="`${row.sku} · ${row.barcode}`" shape="square" icon="package" tight />
          </template>
          <template #cell-category="{ row }">
            <px-tag :label="row.category" :hue="row.categoryHue" />
          </template>
          <template #cell-branch="{ row }">
            <span class="pxn-fs-sm">{{ branches[row.branch] }}</span>
          </template>
          <template #cell-stock="{ row }">
            <span class="pxn-num" :class="{ 'ts-low': row.stock < 40 }">{{ number(row.stock) }}</span>
          </template>
          <template #cell-cost="{ row }"><span class="pxn-num">{{ money(row.cost) }}</span></template>
          <template #cell-price="{ row }"><span class="pxn-num ts-strong">{{ money(row.price) }}</span></template>
          <template #cell-state="{ row }">
            <px-badge :tone="stateMap[row.state].badge" :icon="stateMap[row.state].icon">{{ stateMap[row.state].label }}</px-badge>
          </template>
          <template #row-actions="{ row }">
            <px-kebab
              :items="[
                { label: 'Editar', icon: 'pencil' },
                { label: 'Ver kardex', icon: 'history' },
                { label: 'Ajustar existencias', icon: 'filter' },
                { divider: true },
                { label: 'Enviar a cuarentena', icon: 'shield', tone: 'danger' }
              ]"
              @select="onRowAction(row, $event)"
            />
          </template>
          <template #empty>
            <px-empty-state
              inline
              icon="search-x"
              title="Sin coincidencias"
              description="Ningún producto coincide con la búsqueda o los filtros aplicados."
            >
              <px-button size="sm" variant="secondary" @click="clearAll">Limpiar búsqueda y filtros</px-button>
            </px-empty-state>
          </template>
        </px-table>
      </div>

      <div v-else class="ts-board">
        <px-card v-for="row in pageRows" :key="row.id" interactive>
          <div class="ts-boardcard">
            <div class="ts-boardcard__top">
              <px-tag :label="row.category" :hue="row.categoryHue" />
              <px-badge :tone="stateMap[row.state].badge" :icon="stateMap[row.state].icon">{{ stateMap[row.state].label }}</px-badge>
            </div>
            <div class="ts-boardcard__name">{{ row.name }}</div>
            <div class="ts-boardcard__sku pxn-mono">{{ row.sku }}</div>
            <dl class="ts-boardcard__grid">
              <div><dt>Stock</dt><dd class="pxn-num">{{ number(row.stock) }}</dd></div>
              <div><dt>Precio</dt><dd class="pxn-num">{{ money(row.price) }}</dd></div>
            </dl>
          </div>
        </px-card>
      </div>

      <template #footer>
        <px-pagination :page.sync="page" :per-page.sync="perPage" :total="filtered.length" />
      </template>
    </px-card>
  </section>
</template>

<script>
import SectionHead from "./_SectionHead.vue";
import { PxCard, PxToolbar, PxTag, PxButton, PxTable, PxEntityCell, PxBadge, PxKebab, PxEmptyState, PxPagination } from "@/components/px-next";
import { PRODUCTS, PRODUCT_STATE, BRANCHES } from "../data/mock";
import { money as fmtMoney, number as fmtNumber } from "../data/format";

export default {
  name: "TableSection",
  components: { SectionHead, PxCard, PxToolbar, PxTag, PxButton, PxTable, PxEntityCell, PxBadge, PxKebab, PxEmptyState, PxPagination },
  props: { density: { type: String, default: "comfortable" }, country: { type: String, default: "HN" } },
  data() {
    return {
      rows: PRODUCTS,
      stateMap: PRODUCT_STATE,
      branches: BRANCHES,
      cats: ["Farmacia", "Abarrotes", "Bebidas", "Ferretería", "Cuidado personal", "Limpieza"],
      search: "",
      view: "table",
      showFilters: false,
      filters: { branch: "", category: "", state: "" },
      selected: [],
      sortKey: "name",
      sortDir: "asc",
      page: 1,
      perPage: 10,
      columns: [
        { key: "name", label: "Producto", sortable: true, width: "auto" },
        { key: "category", label: "Categoría", sortable: true },
        { key: "branch", label: "Sucursal" },
        { key: "stock", label: "Stock", align: "right", numeric: true, sortable: true },
        { key: "cost", label: "Costo", align: "right", numeric: true },
        { key: "price", label: "Precio", align: "right", numeric: true, sortable: true, strong: true },
        { key: "state", label: "Estado" }
      ]
    };
  },
  computed: {
    appliedFilters() {
      const out = [];
      if (this.filters.branch) out.push(this.branches[this.filters.branch] || this.filters.branch);
      if (this.filters.category) out.push(this.filters.category);
      if (this.filters.state) out.push(this.stateMap[this.filters.state] ? this.stateMap[this.filters.state].label : this.filters.state);
      return out;
    },
    activeFilterCount() { return this.appliedFilters.length; },
    filtered() {
      const q = this.search.trim().toLowerCase();
      let list = this.rows.filter(r => {
        if (q && !`${r.name} ${r.sku} ${r.barcode}`.toLowerCase().includes(q)) return false;
        if (this.filters.branch !== "" && String(r.branch) !== String(this.filters.branch)) return false;
        if (this.filters.category && r.category !== this.filters.category) return false;
        if (this.filters.state && r.state !== this.filters.state) return false;
        return true;
      });
      const dir = this.sortDir === "asc" ? 1 : -1;
      const k = this.sortKey;
      list = list.slice().sort((a, b) => {
        const av = a[k]; const bv = b[k];
        if (typeof av === "number" && typeof bv === "number") return (av - bv) * dir;
        return String(av).localeCompare(String(bv), "es") * dir;
      });
      return list;
    },
    pageRows() {
      const start = (this.page - 1) * this.perPage;
      return this.filtered.slice(start, start + this.perPage);
    }
  },
  watch: {
    filtered() { if ((this.page - 1) * this.perPage >= this.filtered.length) this.page = 1; }
  },
  methods: {
    money(v) { return fmtMoney(v, { country: this.country }); },
    number(v) { return fmtNumber(v, { country: this.country }); },
    onSort() { this.page = 1; },
    removeFilter(i) {
      const key = ["branch", "category", "state"].filter(k => this.filters[k] !== "")[i];
      if (key) this.filters[key] = "";
    },
    resetFilters() { this.filters = { branch: "", category: "", state: "" }; },
    clearAll() { this.search = ""; this.resetFilters(); },
    onRowAction() {},
    onSort2() {}
  }
};
</script>

<style lang="scss" scoped>
.ts-toolbar { padding: var(--pxn-space-6) var(--pxn-space-7); border-bottom: 1px solid var(--pxn-border); display: flex; flex-direction: column; gap: var(--pxn-space-5); }
.ts-filters { display: flex; flex-wrap: wrap; align-items: flex-end; gap: var(--pxn-space-5); padding: var(--pxn-space-5); background: var(--pxn-surface-2); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); }
.ts-filters label { display: flex; flex-direction: column; gap: var(--pxn-space-2); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink-2); }
.ts-filters select { height: 32px; border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-sm); background: var(--pxn-surface); padding: 0 var(--pxn-space-4); font: inherit; font-size: var(--pxn-fs-sm); }

.ts-bulk {
  display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-5);
  padding: var(--pxn-space-4) var(--pxn-space-7);
  background: var(--pxn-primary-soft);
  border-bottom: 1px solid var(--pxn-primary-border);
  font-size: var(--pxn-fs-sm); color: var(--pxn-primary-ink);
}
.ts-bulk__actions { display: flex; flex-wrap: wrap; gap: var(--pxn-space-3); }
.ts-bulk-enter-active, .ts-bulk-leave-active { transition: opacity var(--pxn-dur-2) var(--pxn-ease), transform var(--pxn-dur-2) var(--pxn-ease); }
.ts-bulk-enter, .ts-bulk-leave-to { opacity: 0; transform: translateY(-6px); }

.ts-tablebox { padding: var(--pxn-space-6) var(--pxn-space-7); }
.ts-tablebox ::v-deep .pxn-table-wrap { max-height: 520px; display: flex; flex-direction: column; }
.ts-tablebox ::v-deep .pxn-table-scroll { flex: 1; }

.ts-low { color: var(--pxn-danger-ink); font-weight: var(--pxn-fw-semibold); }
.ts-strong { color: var(--pxn-ink); font-weight: var(--pxn-fw-semibold); }

.ts-board { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: var(--pxn-space-5); padding: var(--pxn-space-7); }
.ts-boardcard { display: flex; flex-direction: column; gap: var(--pxn-space-4); }
.ts-boardcard__top { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-3); }
.ts-boardcard__name { font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); }
.ts-boardcard__sku { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.ts-boardcard__grid { display: grid; grid-template-columns: 1fr 1fr; gap: var(--pxn-space-4); margin: 0; padding-top: var(--pxn-space-4); border-top: 1px solid var(--pxn-border); }
.ts-boardcard__grid dt { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.ts-boardcard__grid dd { margin: 0; font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
</style>
