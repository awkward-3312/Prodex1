<template>
  <div class="px-next pxrag">
    <!--
      C3.13 — Antigüedad de inventario px-next (solo lectura). Ruta real
      /app/reports/stock_aging_report (name stock_aging_report). Endpoints
      GET report/stock_aging/filters y GET report/stock_aging sin cambios.
      Filtros: dimensión (producto/variante), almacén, buckets, marca, categoría.
      Los rangos/buckets de antigüedad los define el backend (label raw).
    -->
    <div v-if="!can('Stock_Aging_Report')" class="pxrag__denied">
      <px-empty-state icon="lock" title="No tienes permiso para este reporte"
        description="Pide a un administrador el permiso «Stock_Aging_Report»." />
    </div>

    <template v-else>
      <px-page-header title="Antigüedad de inventario" :breadcrumbs="[{ label: 'Informes' }, { label: 'Antigüedad de inventario' }]">
        <template #actions>
          <px-button variant="secondary" size="sm" icon="printer" @click="doPrint">Imprimir</px-button>
        </template>
      </px-page-header>

      <px-toolbar
        :search="search"
        search-placeholder="Buscar por código, producto…"
        :filter-count="activeFilterCount"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxrag__filters">
        <div class="pxrag__filters-grid">
          <px-field label="Dimensión">
            <template #default="{ id }">
              <px-select :id="id" :value="dimension" :options="dimensionOptions" @input="onDimensionChange" />
            </template>
          </px-field>
          <px-field label="Almacén">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="warehouse_id" :reduce="o => o.value" placeholder="Todos"
                :options="warehouseOptions" @input="refresh" />
            </template>
          </px-field>
          <px-field label="Marca">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="brand_id" :reduce="o => o.value" placeholder="Todas"
                :options="brandOptions" @input="refresh" />
            </template>
          </px-field>
          <px-field label="Categoría">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="category_id" :reduce="o => o.value" placeholder="Todas"
                :options="categoryOptions" @input="refresh" />
            </template>
          </px-field>
          <px-field label="Cortes de antigüedad (días)" hint="Separados por comas. Por defecto: 30,60,90">
            <template #default="{ id }">
              <px-input :id="id" v-model="bucketsInput" placeholder="30,60,90" @change="onBucketsChange" />
            </template>
          </px-field>
        </div>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el reporte" class="pxrag__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxrag__pad">
        <px-skeleton variant="table" :rows="10" :columns="6" />
      </div>

      <template v-else>
        <div class="pxrag__tablewrap" :class="{ 'is-busy': refreshing }">
          <px-table
            v-if="rows.length"
            :columns="columns"
            :rows="rows"
            row-key="rk"
            :sort-key="sort.field"
            :sort-dir="sort.type"
            @sort="onSort"
          >
            <template #cell-code="{ row }"><span class="pxn-mono">{{ row.code }}</span></template>
            <template #cell-on_hand="{ row }"><span class="pxn-num">{{ fmtNum(row.on_hand) }}</span></template>
            <template #cell-last_inbound_at="{ row }">{{ row.last_inbound_at || '—' }}</template>
            <template #cell-age_days="{ row }"><span class="pxn-num">{{ ageDays(row.age_days) }}</span></template>
            <template #cell-age_bucket="{ row }">
              <px-badge :tone="bucketTone(row.age_bucket)">{{ row.age_bucket || '—' }}</px-badge>
            </template>
          </px-table>

          <px-empty-state v-else icon="clock" title="Sin resultados"
            description="Ningún producto coincide con los filtros de antigüedad." />
        </div>

        <div v-if="rows.length" class="pxrag__total">
          <span>Total en stock (página)</span><strong class="pxn-num">{{ fmtNum(pageOnHand) }}</strong>
        </div>

        <px-pagination
          v-if="rows.length"
          :page="page"
          :per-page="Number(limit)"
          :total="Number(totalRows) || 0"
          :per-page-options="['10', '25', '50', '100']"
          @update:page="onPage"
          @update:perPage="onLimit"
        />
      </template>
    </template>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxSelect from "@/components/px-next/PxSelect.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";
import { printTableDoc } from "../reportUtils.js";

export default {
  name: "StockAgingReportNext",
  metaInfo: { title: "Antigüedad de inventario" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton,
    PxField, PxInput, PxSelect, PxBadge, PxAlert, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      error: null,
      report: [],
      totalRows: 0,
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "age_days", type: "desc" },
      filtersOpen: false,
      dimension: "product",
      buckets: [30, 60, 90],
      bucketsInput: "30,60,90",
      warehouse_id: "",
      brand_id: "",
      category_id: "",
      warehouses: [],
      brands: [],
      categories: []
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    dimensionOptions() {
      return [
        { value: "product", label: "Por producto" },
        { value: "variant", label: "Por variante" }
      ];
    },
    warehouseOptions() {
      return (this.warehouses || []).map(w => ({ label: w.name, value: Number(w.id) }));
    },
    brandOptions() {
      return (this.brands || []).map(b => ({ label: b.name, value: Number(b.id) }));
    },
    categoryOptions() {
      return (this.categories || []).map(c => ({ label: c.name, value: Number(c.id) }));
    },
    columns() {
      const base = [
        { key: "code", label: "Código", sortable: true, strong: true },
        { key: "product_name", label: "Producto", sortable: true }
      ];
      if (this.dimension === "variant") base.push({ key: "variant_name", label: "Variante", sortable: true });
      base.push(
        { key: "on_hand", label: "En stock", align: "right", numeric: true, sortable: true, width: "110px" },
        { key: "last_inbound_at", label: "Última entrada", sortable: true, width: "160px" },
        { key: "age_days", label: "Días de antigüedad", align: "right", numeric: true, sortable: true, width: "150px" },
        { key: "age_bucket", label: "Rango", sortable: true, width: "130px" }
      );
      return base;
    },
    rows() {
      return (this.report || []).map((r, i) => ({ ...r, rk: (r.code || "") + "-" + (r.variant_name || "") + "-" + i }));
    },
    pageOnHand() {
      return (this.report || []).reduce((s, r) => s + (Number(r.on_hand) || 0), 0);
    },
    activeFilterCount() {
      let n = 0;
      if (this.dimension !== "product") n++;
      if (this.warehouse_id !== "" && this.warehouse_id != null) n++;
      if (this.brand_id !== "" && this.brand_id != null) n++;
      if (this.category_id !== "" && this.category_id != null) n++;
      if (this.bucketsInput.replace(/\s/g, "") !== "30,60,90") n++;
      return n;
    }
  },
  created() {
    this.init();
  },
  methods: {
    can(p) {
      const list = Array.isArray(this.currentUserPermissions) ? this.currentUserPermissions : [];
      return list.includes(p);
    },
    fmtNum(n) {
      const v = Number(n);
      return Number.isFinite(v) ? v.toLocaleString(undefined, { maximumFractionDigits: 2 }) : String(n == null ? "" : n);
    },
    ageDays(v) {
      const n = Number(v);
      return Number.isNaN(n) ? "—" : Math.floor(n);
    },
    bucketTone(bucket) {
      if (!bucket) return "neutral";
      if (bucket.includes("0–") || bucket.includes("0-")) return "success";
      if (bucket.includes("31–") || bucket.includes("30–") || bucket.includes("31-") || bucket.includes("30-")) return "info";
      if (bucket.includes("61–") || bucket.includes("61-")) return "warning";
      return "danger";
    },
    async init() {
      try {
        const { data } = await window.axios.get("report/stock_aging/filters");
        this.warehouses = data.warehouses || [];
        this.brands = data.brands || [];
        this.categories = data.categories || [];
      } catch (e) { /* mantiene "todos" */ }
      this.fetch(true);
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.fetch(); }, 350);
    },
    onSort({ key, dir }) { this.sort = { field: key, type: dir }; this.fetch(); },
    onPage(p) { if (p !== this.page) { this.page = p; this.fetch(); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.fetch(); },
    refresh() { this.page = 1; this.fetch(); },
    onDimensionChange(v) {
      this.dimension = v;
      if (this.sort.field === "variant_name" && v !== "variant") this.sort.field = "product_name";
      this.page = 1;
      this.fetch();
    },
    onBucketsChange() {
      const parts = (this.bucketsInput || "")
        .split(",")
        .map(s => parseInt(String(s).trim(), 10))
        .filter(n => !isNaN(n) && n > 0)
        .sort((a, b) => a - b);
      this.buckets = parts.length ? parts : [30, 60, 90];
      this.bucketsInput = this.buckets.join(",");
      this.refresh();
    },
    fetch(initial) {
      if (initial) this.initialLoading = true; else this.refreshing = true;
      this.error = null;
      NProgress.start(); NProgress.set(0.1);
      const qs =
        "page=" + this.page +
        "&SortField=" + encodeURIComponent(this.sort.field) +
        "&SortType=" + encodeURIComponent(this.sort.type) +
        "&search=" + encodeURIComponent(this.search || "") +
        "&limit=" + encodeURIComponent(this.limit) +
        "&dimension=" + encodeURIComponent(this.dimension) +
        "&buckets=" + encodeURIComponent(this.buckets.join(",")) +
        (this.warehouse_id !== "" && this.warehouse_id != null ? "&warehouse_id=" + encodeURIComponent(this.warehouse_id) : "") +
        (this.brand_id !== "" && this.brand_id != null ? "&brand_id=" + encodeURIComponent(this.brand_id) : "") +
        (this.category_id !== "" && this.category_id != null ? "&category_id=" + encodeURIComponent(this.category_id) : "");
      window.axios
        .get("report/stock_aging?" + qs)
        .then(({ data }) => {
          this.report = Array.isArray(data.report) ? data.report : [];
          this.totalRows = Number(data.totalRows || 0);
          NProgress.done();
          this.initialLoading = false;
          this.refreshing = false;
        })
        .catch(err => {
          NProgress.done();
          this.error =
            (err && err.response && err.response.data && (err.response.data.message || err.response.data.error)) ||
            (err && err.message) || "Error de red.";
          setTimeout(() => { this.initialLoading = false; this.refreshing = false; }, 300);
        });
    },
    doPrint() {
      const headers = this.columns.map(c => c.label);
      const rows = (this.report || []).map(r =>
        this.columns.map(c => {
          if (c.key === "age_days") return this.ageDays(r.age_days);
          if (c.key === "last_inbound_at") return r.last_inbound_at || "—";
          if (c.key === "age_bucket") return r.age_bucket || "—";
          if (c.key === "on_hand") return this.fmtNum(r.on_hand);
          return r[c.key] == null ? "" : r[c.key];
        })
      );
      const footer = headers.map((h, i) => (i === 0 ? "Total" : (this.columns[i].key === "on_hand" ? this.fmtNum(this.pageOnHand) : "")));
      const ok = printTableDoc({ title: "Informes / Antigüedad de inventario", headers, rows, footer, landscape: true });
      if (!ok) this.$root.$bvToast.toast("Permite las ventanas emergentes para imprimir.", { title: "Aviso", variant: "warning", solid: true });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrag { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrag { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrag__denied { padding: var(--pxn-space-12) 0; }
.pxrag__pad { padding: var(--pxn-space-6) 0; }
.pxrag__alert { margin-top: var(--pxn-space-5); }
.pxrag__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxrag__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxrag__filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 620px) { .pxrag__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxrag__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxrag__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
.pxrag__total {
  display: flex; align-items: baseline; justify-content: flex-end; gap: var(--pxn-space-3);
  margin-top: var(--pxn-space-4); padding: var(--pxn-space-3) var(--pxn-space-4);
  background: var(--pxn-surface-2); border-radius: var(--pxn-radius-md);
  font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3);
}
.pxrag__total strong { font-size: var(--pxn-fs-body); color: var(--pxn-ink); }
</style>
