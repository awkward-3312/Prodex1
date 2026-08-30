<template>
  <div class="px-next pxrtr">
    <!--
      C3.14 — Reporte de traslados de stock px-next (SOLO reporte). Ruta real
      /app/reports/stock_transfer_report (name stock_transfer_report). Endpoint
      GET report/stock_transfer sin cambios. Filtros: rango de fechas, atajos,
      almacén, dirección (todos/entrada/salida). No toca /app/transfers/* ni las
      operaciones location-aware. Se conservan KPIs, tabla y exportaciones (PDF /
      imprimir). Se omiten los dos gráficos apex del legacy (visualización
      derivada, fuera del contrato de datos).
    -->
    <div v-if="!can('Stock_Transfer_Report')" class="pxrtr__denied">
      <px-empty-state icon="lock" title="No tienes permiso para este reporte"
        description="Pide a un administrador el permiso «Stock_Transfer_Report»." />
    </div>

    <template v-else>
      <px-page-header title="Reporte de traslados de stock" :breadcrumbs="[{ label: 'Informes' }, { label: 'Traslados de stock' }]">
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
        search-placeholder="Buscar por ID, origen, destino…"
        :filter-count="activeFilterCount"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxrtr__filters">
        <div class="pxrtr__filters-grid">
          <px-field label="Desde">
            <template #default="{ id }"><px-input :id="id" type="date" v-model="from" @change="refresh" /></template>
          </px-field>
          <px-field label="Hasta">
            <template #default="{ id }"><px-input :id="id" type="date" v-model="to" @change="refresh" /></template>
          </px-field>
          <px-field label="Almacén">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="warehouse_id" :reduce="o => o.value" placeholder="Todos"
                :options="warehouses.map(w => ({ label: w.name, value: w.id }))" @input="refresh" />
            </template>
          </px-field>
          <px-field label="Dirección">
            <template #default="{ id }">
              <px-select :id="id" :value="direction" :options="directionOptions" @input="v => { direction = v; refresh(); }" />
            </template>
          </px-field>
        </div>
        <div class="pxrtr__quick">
          <span>Atajos:</span>
          <px-button size="sm" variant="ghost" @click="quick(7)">7 días</px-button>
          <px-button size="sm" variant="ghost" @click="quick(30)">30 días</px-button>
          <px-button size="sm" variant="ghost" @click="quick(90)">90 días</px-button>
          <px-button size="sm" variant="ghost" @click="quickMTD">Mes actual</px-button>
          <px-button size="sm" variant="ghost" @click="quickYTD">Año actual</px-button>
        </div>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el reporte" class="pxrtr__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxrtr__pad">
        <px-skeleton variant="table" :rows="10" :columns="7" />
      </div>

      <template v-else>
        <div class="pxrtr__kpis">
          <px-stat label="Traslados" :value="num(kpis.transfers_count)" icon="arrow-left-right" sub="Documentos" bordered />
          <px-stat label="Líneas" :value="num(kpis.lines_count)" icon="file-text" bordered />
          <px-stat label="Cantidad movida" :value="fmtQty(kpis.qty_sum)" icon="package" bordered />
          <px-stat label="Valor movido" :value="money(kpis.value_sum)" icon="banknote" bordered />
          <px-stat label="Media artículos / traslado" :value="fmtQty(kpis.avg_items_per_transfer)" icon="trending-up" bordered />
          <px-stat label="Media valor / traslado" :value="money(kpis.avg_value_per_transfer)" icon="coins" bordered />
        </div>

        <div class="pxrtr__tablewrap" :class="{ 'is-busy': refreshing }">
          <px-table
            v-if="rows.length"
            :columns="columns"
            :rows="rows"
            row-key="rk"
            :sort-key="sort.field"
            :sort-dir="sort.type"
            @sort="onSort"
          >
            <template #cell-qty="{ row }"><span class="pxn-num">{{ fmtQty(row.qty) }}</span></template>
            <template #cell-value="{ row }"><span class="pxn-num">{{ money(row.value) }}</span></template>
          </px-table>

          <px-empty-state v-else icon="arrow-left-right" title="Sin traslados"
            description="No hay traslados en el rango y filtros seleccionados." />
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
import PxInput from "@/components/px-next/PxInput.vue";
import PxSelect from "@/components/px-next/PxSelect.vue";
import PxStat from "@/components/px-next/PxStat.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";
import { printTableDoc, exportPdf } from "../reportUtils.js";

const iso = d => {
  const x = d instanceof Date ? d : new Date(d);
  return x.toISOString().slice(0, 10);
};

export default {
  name: "StockTransferReportNext",
  metaInfo: { title: "Reporte de traslados de stock" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu,
    PxField, PxInput, PxSelect, PxStat, PxAlert, PxEmptyState, "vs-px": VsPx
  },
  data() {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - 6);
    return {
      initialLoading: true,
      refreshing: false,
      error: null,
      from: iso(start),
      to: iso(end),
      warehouse_id: "",
      direction: "all",
      warehouses: [],
      kpis: { transfers_count: 0, lines_count: 0, qty_sum: 0, value_sum: 0, avg_items_per_transfer: 0, avg_value_per_transfer: 0 },
      report: [],
      totalRows: 0,
      page: 1,
      perPage: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "dt", type: "desc" },
      filtersOpen: false
    };
  },
  computed: {
    ...mapGetters(["currentUser", "currentUserPermissions"]),
    currency() {
      return (this.currentUser && this.currentUser.currency) || "USD";
    },
    directionOptions() {
      return [
        { value: "all", label: "Todos" },
        { value: "inbound", label: "Entrada" },
        { value: "outbound", label: "Salida" }
      ];
    },
    columns() {
      return [
        { key: "transfer_id", label: "ID", sortable: true, strong: true, width: "80px" },
        { key: "date_time", label: "Fecha", sortable: true, width: "150px" },
        { key: "from", label: "Origen", sortable: true },
        { key: "to", label: "Destino", sortable: true },
        { key: "qty", label: "Cantidad", align: "right", numeric: true, sortable: true, width: "110px" },
        { key: "value", label: "Valor", align: "right", numeric: true, sortable: true, width: "130px" },
        { key: "statut", label: "Estado", sortable: true, width: "120px" }
      ];
    },
    rows() {
      return (this.report || []).map((r, i) => ({ ...r, rk: (r.transfer_id != null ? r.transfer_id : "") + "-" + i }));
    },
    activeFilterCount() {
      let n = 0;
      if (this.warehouse_id !== "" && this.warehouse_id != null) n++;
      if (this.direction !== "all") n++;
      return n;
    },
    exportMenu() {
      return [
        { key: "pdf", label: "PDF", icon: "file-text" },
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
    num(v) {
      const n = parseFloat(v || 0);
      return isNaN(n) ? 0 : n.toLocaleString();
    },
    fmtQty(v) {
      const n = parseFloat(v || 0);
      return isNaN(n) ? "0" : n.toLocaleString(undefined, { maximumFractionDigits: 2 });
    },
    money(v) {
      const n = parseFloat(v || 0);
      const safe = isNaN(n) ? 0 : n;
      try {
        return new Intl.NumberFormat(undefined, { style: "currency", currency: this.currency }).format(safe);
      } catch (e) {
        return this.currency + " " + safe.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      }
    },
    warehouseName() {
      if (!this.warehouse_id) return "Todos";
      const w = (this.warehouses || []).find(x => Number(x.id) === Number(this.warehouse_id));
      return w ? w.name : "#" + this.warehouse_id;
    },
    directionLabel() {
      const o = this.directionOptions.find(x => x.value === this.direction);
      return o ? o.label : this.direction;
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.fetch(); }, 350);
    },
    onSort({ key, dir }) { this.sort = { field: key, type: dir }; this.fetch(); },
    onPage(p) { if (p !== this.page) { this.page = p; this.fetch(); } },
    onPerPage(v) { this.perPage = String(v); this.page = 1; this.fetch(); },
    refresh() { this.page = 1; this.fetch(); },
    quick(days) {
      const end = new Date();
      const start = new Date();
      start.setDate(end.getDate() - (days - 1));
      this.from = iso(start);
      this.to = iso(end);
      this.refresh();
    },
    quickMTD() {
      const now = new Date();
      this.from = iso(new Date(now.getFullYear(), now.getMonth(), 1));
      this.to = iso(now);
      this.refresh();
    },
    quickYTD() {
      const now = new Date();
      this.from = iso(new Date(now.getFullYear(), 0, 1));
      this.to = iso(now);
      this.refresh();
    },
    buildQs(pageOverride, limitOverride) {
      return new URLSearchParams({
        from: this.from,
        to: this.to,
        warehouse_id: this.warehouse_id || "",
        direction: this.direction,
        page: String(pageOverride == null ? this.page : pageOverride),
        limit: String(limitOverride == null ? this.perPage : limitOverride),
        SortField: this.sort.field || "dt",
        SortType: this.sort.type || "desc",
        search: this.search || ""
      }).toString();
    },
    fetch(initial) {
      if (initial) this.initialLoading = true; else this.refreshing = true;
      this.error = null;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get("report/stock_transfer?" + this.buildQs())
        .then(({ data }) => {
          const d = data.data || {};
          this.kpis = d.kpis || this.kpis;
          this.report = d.rows || [];
          this.totalRows = d.totalRows || 0;
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
    async fetchAll() {
      const per = 500;
      let page = 1;
      let total = Infinity;
      const all = [];
      while (all.length < total) {
        const { data } = await window.axios.get("report/stock_transfer?" + this.buildQs(page, per));
        const d = data && data.data ? data.data : {};
        const rows = d.rows || [];
        total = Number(d.totalRows || rows.length || 0);
        all.push(...rows);
        if (rows.length < per) break;
        page += 1;
      }
      return all;
    },
    exportHeaders() {
      return ["ID", "Fecha", "Origen", "Destino", "Cantidad", "Valor", "Estado"];
    },
    exportRows(items) {
      return (items || []).map(r => [r.transfer_id, r.date_time, r.from, r.to, this.fmtQty(r.qty), this.money(r.value), r.statut]);
    },
    async onExport(item) {
      const k = item && item.key;
      if (k === "print") {
        const ok = printTableDoc({
          title: "Informes / Reporte de traslados de stock · " + this.from + " – " + this.to + " · Almacén: " + this.warehouseName() + " · " + this.directionLabel(),
          headers: this.exportHeaders(),
          rows: this.exportRows(this.report),
          landscape: true
        });
        if (!ok) this.$root.$bvToast.toast("Permite las ventanas emergentes para imprimir.", { title: "Aviso", variant: "warning", solid: true });
        return;
      }
      if (k === "pdf") {
        NProgress.start(); NProgress.set(0.2);
        try {
          const all = await this.fetchAll();
          exportPdf({
            title: "Reporte de traslados de stock",
            subtitle: this.from + " – " + this.to + "  ·  Almacén: " + this.warehouseName() + "  ·  " + this.directionLabel(),
            filename: "Traslados_" + this.from + "_" + this.to,
            headers: this.exportHeaders(),
            rows: this.exportRows(all),
            landscape: true
          });
        } catch (e) {
          this.$root.$bvToast.toast("No se pudo exportar el PDF.", { title: "Error", variant: "danger", solid: true });
        } finally {
          NProgress.done();
        }
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrtr { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrtr { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrtr__denied { padding: var(--pxn-space-12) 0; }
.pxrtr__pad { padding: var(--pxn-space-6) 0; }
.pxrtr__alert { margin-top: var(--pxn-space-5); }
.pxrtr__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxrtr__filters-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxrtr__filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 620px) { .pxrtr__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxrtr__quick { display: flex; align-items: center; flex-wrap: wrap; gap: var(--pxn-space-2); margin-top: var(--pxn-space-4); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxrtr__kpis { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: var(--pxn-space-4); margin-top: var(--pxn-space-5); }
@media (max-width: 1100px) { .pxrtr__kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 620px) { .pxrtr__kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.pxrtr__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxrtr__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
</style>
