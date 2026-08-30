<template>
  <div class="px-next pxsmv">
    <!--
      C3.23 — Registro de movimientos de números de serie px-next (solo lectura).
      Ruta real /app/reports/serial_movement_report (name serial_movement_report).
      Endpoint GET report/serials/movements sin cambios. Filtros: acción, rango
      de fechas. Búsqueda, paginación y exportación (Excel .xlsx + imprimir).
      Estados/acciones técnicos español-first con el mapa de C3.6; valores raw
      intactos. Permiso serial_numbers_report.
    -->
    <div v-if="!can('serial_numbers_report')" class="pxsmv__denied">
      <px-empty-state icon="lock" title="No tienes permiso para este reporte"
        description="Pide a un administrador el permiso «serial_numbers_report»." />
    </div>

    <template v-else>
      <px-page-header title="Movimientos de números de serie" :breadcrumbs="[{ label: 'Informes' }, { label: 'Movimientos de seriales' }]">
        <template #actions>
          <vue-excel-xlsx class="pxsmv__xlsx" :data="exportData" :columns="xlsxColumns" file-name="serial_movements" file-type="xlsx" sheet-name="serial_movements">
            <px-button variant="secondary" size="sm" icon="file-spreadsheet">Excel</px-button>
          </vue-excel-xlsx>
          <px-button variant="secondary" size="sm" icon="printer" @click="doPrint">Imprimir</px-button>
        </template>
      </px-page-header>

      <px-toolbar
        :search="search"
        search-placeholder="Buscar por nº de serie, referencia…"
        :filter-count="activeFilterCount"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxsmv__filters">
        <div class="pxsmv__filters-grid">
          <px-field label="Acción">
            <template #default="{ id }">
              <px-select :id="id" :value="action" :options="actionOptions" @input="v => { action = v; applyFilters(); }" />
            </template>
          </px-field>
          <px-field label="Desde">
            <template #default="{ id }"><px-input :id="id" type="date" v-model="from_date" @change="applyFilters" /></template>
          </px-field>
          <px-field label="Hasta">
            <template #default="{ id }"><px-input :id="id" type="date" v-model="to_date" @change="applyFilters" /></template>
          </px-field>
        </div>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el reporte" class="pxsmv__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxsmv__pad">
        <px-skeleton variant="table" :rows="10" :columns="5" />
      </div>

      <template v-else>
        <div class="pxsmv__tablewrap" :class="{ 'is-busy': refreshing }">
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
            <template #cell-action="{ row }">{{ actionLabel(row.action) }}</template>
            <template #cell-transition="{ row }">
              <span v-if="row.from_status" class="pxn-muted">{{ statusLabel(row.from_status) }} → </span>
              <span>{{ statusLabel(row.to_status) }}</span>
            </template>
            <template #cell-reference="{ row }">
              <span v-if="row.reference_type">{{ row.reference_type }} #{{ row.reference_id }}</span>
              <span v-else class="pxn-muted">—</span>
            </template>
          </px-table>

          <px-empty-state v-else icon="history" title="Sin movimientos"
            description="No hay movimientos de seriales que coincidan con los filtros." />
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
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import { printTableDoc } from "../reportUtils.js";
import { SERIAL_STATUS_LABELS, SERIAL_ACTION_LABELS } from "@/views/app/inventory/next/serial-numbers/status.js";

export default {
  name: "SerialMovementReportNext",
  metaInfo: { title: "Movimientos de números de serie" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton,
    PxField, PxInput, PxSelect, PxAlert, PxEmptyState
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      error: null,
      reports: [],
      totalRows: 0,
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "created_at", type: "desc" },
      filtersOpen: false,
      action: "",
      from_date: "",
      to_date: ""
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    actionOptions() {
      return [
        { value: "", label: "Todas las acciones" },
        { value: "purchased", label: "Comprado" },
        { value: "sold", label: "Vendido" },
        { value: "sale_returned", label: "Devolución de venta" },
        { value: "purchase_returned", label: "Devolución de compra" },
        { value: "status_changed", label: "Cambio de estado" }
      ];
    },
    columns() {
      return [
        { key: "created_at", label: "Fecha", sortable: true, width: "170px" },
        { key: "serial_number", label: "Número de serie", sortable: true, strong: true },
        { key: "action", label: "Acción", sortable: true, width: "170px" },
        { key: "transition", label: "Estado", sortable: false },
        { key: "reference", label: "Documento", sortable: false }
      ];
    },
    xlsxColumns() {
      return [
        { label: "Fecha", field: "created_at" },
        { label: "Número de serie", field: "serial_number" },
        { label: "Acción", field: "action_label" },
        { label: "Estado", field: "transition_label" },
        { label: "Documento", field: "reference_label" }
      ];
    },
    rows() {
      return (this.reports || []).map((r, i) => ({ ...r, rk: (r.serial_number || "") + "-" + (r.created_at || "") + "-" + i }));
    },
    exportData() {
      return (this.reports || []).map(r => ({
        created_at: r.created_at,
        serial_number: r.serial_number,
        action_label: this.actionLabel(r.action),
        transition_label: (r.from_status ? this.statusLabel(r.from_status) + " → " : "") + this.statusLabel(r.to_status),
        reference_label: r.reference_type ? r.reference_type + " #" + r.reference_id : "—"
      }));
    },
    activeFilterCount() {
      return [this.action, this.from_date, this.to_date].filter(v => v !== "" && v != null).length;
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
    statusLabel(s) {
      return SERIAL_STATUS_LABELS[s] || s;
    },
    actionLabel(a) {
      return SERIAL_ACTION_LABELS[a] || a;
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
        .get("report/serials/movements", {
          params: {
            page: this.page,
            search: this.search || "",
            action: this.action || "",
            from_date: this.from_date || "",
            to_date: this.to_date || "",
            limit: this.limit
          }
        })
        .then(r => {
          this.reports = r.data.report || [];
          this.totalRows = r.data.totalRows;
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
      const headers = ["Fecha", "Número de serie", "Acción", "Estado", "Documento"];
      const rows = this.exportData.map(r => [r.created_at, r.serial_number, r.action_label, r.transition_label, r.reference_label]);
      const ok = printTableDoc({ title: "Informes / Movimientos de números de serie", headers, rows, landscape: true });
      if (!ok) this.$root.$bvToast.toast("Permite las ventanas emergentes para imprimir.", { title: "Aviso", variant: "warning", solid: true });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxsmv { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxsmv { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxsmv__denied { padding: var(--pxn-space-12) 0; }
.pxsmv__pad { padding: var(--pxn-space-6) 0; }
.pxsmv__alert { margin-top: var(--pxn-space-5); }
.pxsmv__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxsmv__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 720px) { .pxsmv__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxsmv__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxsmv__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
.pxsmv__xlsx { border: 0; background: none; padding: 0; }
</style>
