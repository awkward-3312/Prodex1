<template>
  <div class="px-next pxrs">
    <!--
      C3.9 — Reporte de existencias px-next (solo lectura). Ruta real
      /app/reports/stock_report (name stock_report). Endpoint GET report/stock
      sin cambios. Filtro por almacén, búsqueda, orden, paginación y
      exportaciones (PDF / Excel-CSV / imprimir). La acción por fila abre el
      detalle drill-down existente (ruta detail_stock_report, sin cambios).
    -->
    <div v-if="!can('stock_report')" class="pxrs__denied">
      <px-empty-state icon="lock" title="No tienes permiso para el reporte de existencias"
        description="Pide a un administrador el permiso «stock_report»." />
    </div>

    <template v-else>
      <px-page-header title="Reporte de existencias" :breadcrumbs="[{ label: 'Informes' }, { label: 'Existencias' }]">
        <template #actions>
          <px-menu :items="exportMenu" align="end" @select="onExport">
            <template #trigger>
              <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">Exportar</px-button>
            </template>
          </px-menu>
        </template>
      </px-page-header>

      <px-toolbar
        :search="search"
        search-placeholder="Buscar por código, nombre…"
        :filter-count="warehouse_id ? 1 : 0"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxrs__filters">
        <px-field label="Almacén">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="warehouse_id" :reduce="o => o.value" placeholder="Todos los almacenes"
              :options="warehouses.map(w => ({ label: w.name, value: w.id }))" @input="applyFilters" />
          </template>
        </px-field>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el reporte" class="pxrs__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxrs__pad">
        <px-skeleton variant="table" :rows="10" :columns="5" />
      </div>

      <template v-else>
        <div class="pxrs__tablewrap" :class="{ 'is-busy': refreshing }">
          <px-table
            v-if="rows.length"
            :columns="columns"
            :rows="rows"
            row-key="id"
            :sort-key="sort.field"
            :sort-dir="sort.type"
            has-row-actions
            @sort="onSort"
          >
            <template #cell-code="{ row }"><span class="pxn-mono">{{ row.code }}</span></template>
            <template #cell-category="{ row }">{{ row.category || '—' }}</template>
            <template #cell-quantity="{ row }"><span class="pxn-num">{{ qtyText(row.quantity) }}</span></template>
            <template #row-actions="{ row }">
              <px-button size="sm" variant="secondary" icon="bar-chart-2" @click="goDetail(row)">Detalle</px-button>
            </template>
          </px-table>

          <px-empty-state v-else icon="trending-up" title="Sin existencias"
            description="No hay productos que coincidan con los filtros." />
        </div>

        <div v-if="rows.length" class="pxrs__total">
          <span>Total en esta página</span>
          <strong class="pxn-num">{{ fmt(pageTotal) }}</strong>
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
import { getPriceDecimals } from "@/utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxMenu from "@/components/px-next/PxMenu.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";
import { printTableDoc, exportPdf, exportCsv } from "../reportUtils.js";

export default {
  name: "StockReportNext",
  metaInfo: { title: "Reporte de existencias" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu,
    PxField, PxAlert, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      error: null,
      reports: [],
      warehouses: [],
      totalRows: "",
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "id", type: "desc" },
      filtersOpen: false,
      warehouse_id: ""
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    columns() {
      return [
        { key: "code", label: "Código", sortable: true, strong: true },
        { key: "name", label: "Producto", sortable: false },
        { key: "category", label: "Categoría", sortable: false },
        { key: "quantity", label: "Stock actual", align: "right", numeric: true, sortable: true, width: "150px" }
      ];
    },
    rows() {
      return this.reports || [];
    },
    pageTotal() {
      return (this.reports || []).reduce((s, r) => s + (parseFloat(r.quantity) || 0), 0);
    },
    exportMenu() {
      return [
        { key: "pdf", label: "PDF", icon: "file-text" },
        { key: "csv", label: "Excel (CSV)", icon: "file-spreadsheet" },
        { key: "print", label: "Imprimir", icon: "printer" }
      ];
    }
  },
  created() {
    this.fetch(true);
  },
  methods: {
    can(p) {
      const list = Array.isArray(this.currentUserPermissions) ? this.currentUserPermissions : [];
      return list.includes(p);
    },
    fmt(n) {
      const v = Number(n);
      if (!Number.isFinite(v)) return "0";
      return v.toFixed(this.priceDecimals);
    },
    // El backend ya entrega "quantity" formateado (a veces con unidad, p. ej.
    // "25.000 PB"). Se muestra tal cual, igual que el legacy; no lo re-formatea.
    qtyText(q) {
      return q == null || q === "" ? "0" : String(q);
    },
    goDetail(row) {
      // Detalle drill-down existente (7 pestañas). Ruta y contrato sin cambios.
      this.$router.push({ name: "detail_stock_report", params: { id: row.id } });
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.fetch(); }, 350);
    },
    onSort({ key, dir }) { this.sort = { field: key, type: dir }; this.fetch(); },
    onPage(p) { if (p !== this.page) { this.page = p; this.fetch(); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.fetch(); },
    applyFilters() { this.page = 1; this.fetch(); },
    fetch(initial) {
      if (initial) this.initialLoading = true; else this.refreshing = true;
      this.error = null;
      NProgress.start(); NProgress.set(0.1);
      const qs =
        "report/stock?page=" + this.page +
        "&SortField=" + this.sort.field +
        "&SortType=" + this.sort.type +
        "&warehouse_id=" + encodeURIComponent(this.warehouse_id || "") +
        "&search=" + encodeURIComponent(this.search || "") +
        "&limit=" + this.limit;
      window.axios
        .get(qs)
        .then(response => {
          this.reports = response.data.report || [];
          this.totalRows = response.data.totalRows;
          this.warehouses = response.data.warehouses || this.warehouses;
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
    tableExport() {
      const headers = ["Código", "Producto", "Categoría", "Stock actual"];
      const rows = (this.reports || []).map(r => [r.code, r.name, r.category, this.qtyText(r.quantity)]);
      const footer = ["Total", "", "", this.fmt(this.pageTotal)];
      return { headers, rows, footer };
    },
    onExport(item) {
      const k = item && item.key;
      const { headers, rows, footer } = this.tableExport();
      if (k === "pdf") exportPdf({ title: "Reporte de existencias", filename: "Reporte_existencias", headers, rows, footer });
      else if (k === "csv") exportCsv({ filename: "Reporte_existencias", headers, rows: rows.concat([footer]) });
      else if (k === "print") {
        const ok = printTableDoc({ title: "Informes / Reporte de existencias", headers, rows, footer });
        if (!ok) this.$root.$bvToast.toast("Permite las ventanas emergentes para imprimir.", { title: "Aviso", variant: "warning", solid: true });
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrs { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrs { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrs__denied { padding: var(--pxn-space-12) 0; }
.pxrs__pad { padding: var(--pxn-space-6) 0; }
.pxrs__alert { margin-top: var(--pxn-space-5); }
.pxrs__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); max-width: 420px; }
.pxrs__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxrs__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
.pxrs__total {
  display: flex; align-items: baseline; justify-content: flex-end; gap: var(--pxn-space-3);
  margin-top: var(--pxn-space-4); padding: var(--pxn-space-3) var(--pxn-space-4);
  background: var(--pxn-surface-2); border-radius: var(--pxn-radius-md);
  font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3);
}
.pxrs__total strong { font-size: var(--pxn-fs-body); color: var(--pxn-ink); }
</style>
