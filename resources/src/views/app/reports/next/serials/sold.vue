<template>
  <div class="px-next pxsso">
    <!--
      C3.21 — Informe de números de serie vendidos px-next (solo lectura).
      Ruta real /app/reports/serial_sold_report (name serial_sold_report).
      Endpoint GET report/serials/sold sin cambios. Filtro por almacén, búsqueda,
      orden, paginación y exportación (Excel .xlsx + imprimir). Permiso
      serial_numbers_report. No toca flujos operativos de seriales.
    -->
    <div v-if="!can('serial_numbers_report')" class="pxsso__denied">
      <px-empty-state icon="lock" title="No tienes permiso para este reporte"
        description="Pide a un administrador el permiso «serial_numbers_report»." />
    </div>

    <template v-else>
      <px-page-header title="Números de serie vendidos" :breadcrumbs="[{ label: 'Informes' }, { label: 'Seriales vendidos' }]">
        <template #actions>
          <vue-excel-xlsx class="pxsso__xlsx" :data="reports" :columns="xlsxColumns" file-name="seriales_vendidos" file-type="xlsx" sheet-name="seriales_vendidos">
            <px-button variant="secondary" size="sm" icon="file-spreadsheet">Excel</px-button>
          </vue-excel-xlsx>
          <px-button variant="secondary" size="sm" icon="printer" @click="doPrint">Imprimir</px-button>
        </template>
      </px-page-header>

      <px-toolbar
        :search="search"
        search-placeholder="Buscar por nº de serie, producto, cliente…"
        :filter-count="warehouse_id ? 1 : 0"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxsso__filters">
        <px-field label="Almacén">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="warehouse_id" :reduce="o => o.value" placeholder="Todos los almacenes"
              :options="warehouses.map(w => ({ label: w.name, value: w.id }))" @input="applyFilters" />
          </template>
        </px-field>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el reporte" class="pxsso__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxsso__pad">
        <px-skeleton variant="table" :rows="10" :columns="6" />
      </div>

      <template v-else>
        <div class="pxsso__tablewrap" :class="{ 'is-busy': refreshing }">
          <px-table
            v-if="rows.length"
            :columns="columns"
            :rows="rows"
            row-key="rk"
            :sort-key="sort.field"
            :sort-dir="sort.type"
            @sort="onSort"
          >
            <template #cell-serial_number="{ row }"><span class="pxn-mono">{{ row.serial_number }}</span></template>
            <template #cell-client_name="{ row }">{{ row.client_name || '—' }}</template>
            <template #cell-sale_ref="{ row }"><span class="pxn-mono">{{ row.sale_ref || '—' }}</span></template>
          </px-table>

          <px-empty-state v-else icon="scan-barcode" title="Sin seriales vendidos"
            description="No hay números de serie vendidos que coincidan con los filtros." />
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
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";
import { printTableDoc } from "../reportUtils.js";

export default {
  name: "SerialSoldReportNext",
  metaInfo: { title: "Seriales vendidos" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton,
    PxField, PxAlert, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      error: null,
      reports: [],
      warehouses: [],
      totalRows: 0,
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
    columns() {
      return [
        { key: "serial_number", label: "Número de serie", sortable: true, strong: true },
        { key: "product_name", label: "Producto", sortable: false },
        { key: "warehouse_name", label: "Almacén", sortable: false },
        { key: "client_name", label: "Cliente", sortable: false },
        { key: "sale_ref", label: "Venta", sortable: false, width: "140px" },
        { key: "sale_date", label: "Fecha", sortable: false, width: "150px" }
      ];
    },
    xlsxColumns() {
      return this.columns.map(c => ({ label: c.label, field: c.key }));
    },
    rows() {
      return (this.reports || []).map((r, i) => ({ ...r, rk: (r.serial_number || "") + "-" + i }));
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
      window.axios
        .get("report/serials/sold", {
          params: {
            page: this.page,
            SortField: this.sort.field,
            SortType: this.sort.type,
            search: this.search || "",
            warehouse_id: this.warehouse_id || "",
            limit: this.limit
          }
        })
        .then(r => {
          this.reports = r.data.report || [];
          this.totalRows = r.data.totalRows;
          if (r.data.warehouses) this.warehouses = r.data.warehouses;
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
      const rows = (this.reports || []).map(r => this.columns.map(c => (r[c.key] == null ? "" : r[c.key])));
      const ok = printTableDoc({ title: "Informes / Números de serie vendidos", headers, rows, landscape: true });
      if (!ok) this.$root.$bvToast.toast("Permite las ventanas emergentes para imprimir.", { title: "Aviso", variant: "warning", solid: true });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxsso { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxsso { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxsso__denied { padding: var(--pxn-space-12) 0; }
.pxsso__pad { padding: var(--pxn-space-6) 0; }
.pxsso__alert { margin-top: var(--pxn-space-5); }
.pxsso__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); max-width: 420px; }
.pxsso__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxsso__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
.pxsso__xlsx { border: 0; background: none; padding: 0; }
</style>
