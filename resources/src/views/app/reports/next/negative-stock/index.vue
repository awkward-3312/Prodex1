<template>
  <div class="px-next pxrn">
    <!--
      C3.10 — Reporte de stock negativo px-next (solo lectura). Ruta real
      /app/reports/negative_stock_report (name negative_stock_report). Endpoint
      GET report/negative_stock sin cambios. Filtro por almacén, búsqueda,
      paginación y exportaciones (PDF / Excel-CSV / imprimir). El stock negativo
      se marca como estado crítico px-next; los datos NO se alteran.
    -->
    <div v-if="!can('negative_stock_report')" class="pxrn__denied">
      <px-empty-state icon="lock" title="No tienes permiso para el reporte de stock negativo"
        description="Pide a un administrador el permiso «negative_stock_report»." />
    </div>

    <template v-else>
      <px-page-header title="Reporte de stock negativo" :breadcrumbs="[{ label: 'Informes' }, { label: 'Stock negativo' }]">
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

      <div v-if="filtersOpen" class="pxrn__filters">
        <px-field label="Almacén">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="warehouse_id" :reduce="o => o.value" placeholder="Todos los almacenes"
              :options="warehouses.map(w => ({ label: w.name, value: w.id }))" @input="applyFilters" />
          </template>
        </px-field>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el reporte" class="pxrn__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxrn__pad">
        <px-skeleton variant="table" :rows="10" :columns="4" />
      </div>

      <template v-else>
        <px-alert v-if="rows.length" tone="danger" class="pxrn__banner">
          {{ totalRows }} línea(s) con existencias negativas. Revisa entradas/salidas de esos productos.
        </px-alert>

        <div class="pxrn__tablewrap" :class="{ 'is-busy': refreshing }">
          <px-table
            v-if="rows.length"
            :columns="columns"
            :rows="rows"
            row-key="rk"
          >
            <template #cell-code="{ row }"><span class="pxn-mono">{{ row.code }}</span></template>
            <template #cell-quantity="{ row }">
              <px-badge tone="danger">{{ fmtInt(row.quantity) }}</px-badge>
            </template>
          </px-table>

          <px-empty-state v-else icon="check-circle" title="Sin stock negativo"
            description="Ningún producto tiene existencias por debajo de cero con los filtros actuales." />
        </div>

        <px-pagination
          v-if="rows.length"
          :page="page"
          :per-page="Number(perPage)"
          :total="Number(totalRows) || 0"
          :per-page-options="['10', '25', '50', '100']"
          @update:page="onPage"
          @update:perPage="onPerPage"
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
import PxMenu from "@/components/px-next/PxMenu.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";
import { printTableDoc, exportPdf, exportCsv } from "../reportUtils.js";

export default {
  name: "NegativeStockReportNext",
  metaInfo: { title: "Reporte de stock negativo" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu,
    PxField, PxBadge, PxAlert, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      error: null,
      rawRows: [],
      warehouses: [],
      totalRows: 0,
      page: 1,
      perPage: "10",
      search: "",
      _searchTimer: null,
      filtersOpen: false,
      warehouse_id: ""
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    columns() {
      return [
        { key: "code", label: "Referencia", sortable: false, strong: true },
        { key: "name", label: "Producto", sortable: false },
        { key: "warehouse_name", label: "Almacén", sortable: false },
        { key: "quantity", label: "Cantidad", align: "right", numeric: true, sortable: false, width: "130px" }
      ];
    },
    rows() {
      return (this.rawRows || []).map((r, i) => ({ ...r, rk: (r.code || "") + "-" + (r.warehouse_name || "") + "-" + i }));
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
    fmtInt(n) {
      const v = Number(n);
      return Number.isFinite(v) ? String(v) : String(n == null ? "" : n);
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.fetch(); }, 350);
    },
    onPage(p) { if (p !== this.page) { this.page = p; this.fetch(); } },
    onPerPage(v) { this.perPage = String(v); this.page = 1; this.fetch(); },
    applyFilters() { this.page = 1; this.fetch(); },
    fetch(initial) {
      if (initial) this.initialLoading = true; else this.refreshing = true;
      this.error = null;
      NProgress.start(); NProgress.set(0.1);
      const qs = new URLSearchParams({
        page: this.page,
        limit: this.perPage,
        warehouse_id: this.warehouse_id || "",
        search: this.search || ""
      }).toString();
      window.axios
        .get("report/negative_stock?" + qs)
        .then(({ data }) => {
          this.rawRows = data.rows || [];
          this.totalRows = data.totalRows || 0;
          this.warehouses = data.warehouses || this.warehouses;
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
      const headers = ["Referencia", "Producto", "Almacén", "Cantidad"];
      const rows = (this.rawRows || []).map(r => [r.code, r.name, r.warehouse_name, this.fmtInt(r.quantity)]);
      return { headers, rows };
    },
    onExport(item) {
      const k = item && item.key;
      const { headers, rows } = this.tableExport();
      if (k === "pdf") exportPdf({ title: "Reporte de stock negativo", filename: "Reporte_stock_negativo", headers, rows });
      else if (k === "csv") exportCsv({ filename: "Reporte_stock_negativo", headers, rows });
      else if (k === "print") {
        const ok = printTableDoc({ title: "Informes / Reporte de stock negativo", headers, rows, landscape: true });
        if (!ok) this.$root.$bvToast.toast("Permite las ventanas emergentes para imprimir.", { title: "Aviso", variant: "warning", solid: true });
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrn { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrn { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrn__denied { padding: var(--pxn-space-12) 0; }
.pxrn__pad { padding: var(--pxn-space-6) 0; }
.pxrn__alert { margin-top: var(--pxn-space-5); }
.pxrn__banner { margin-top: var(--pxn-space-5); }
.pxrn__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); max-width: 420px; }
.pxrn__tablewrap { margin-top: var(--pxn-space-4); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxrn__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
</style>
