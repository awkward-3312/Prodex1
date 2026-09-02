<template>
  <div class="px-next pxrad">
    <!--
      C3.15 — Reporte de ajustes px-next (solo lectura). Ruta real
      /app/reports/stock_adjustment_report (name stock_adjustment_report).
      Endpoint GET report/stock_adjustment sin cambios. Filtros: rango de fechas
      + almacén. KPIs (nº ajustes, cantidad añadida/retirada -> tipo add/sub,
      cantidad neta), tabla con referencia, cantidades y costes, totales, y
      exportaciones (PDF / imprimir). Se omiten los dos gráficos apex del legacy.
    -->
    <div v-if="!can('Stock_Adjustment_Report')" class="pxrad__denied">
      <px-empty-state icon="lock" title="No tienes permiso para este reporte"
        description="Pide a un administrador el permiso «Stock_Adjustment_Report»." />
    </div>

    <template v-else>
      <px-page-header title="Reporte de ajustes" :breadcrumbs="[{ label: 'Informes' }, { label: 'Ajustes' }]">
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
        search-placeholder="Buscar por referencia, almacén…"
        :filter-count="warehouse_id ? 1 : 0"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxrad__filters">
        <div class="pxrad__filters-grid">
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
        </div>
        <div class="pxrad__quick">
          <span>Atajos:</span>
          <px-button size="sm" variant="ghost" @click="quick(7)">7 días</px-button>
          <px-button size="sm" variant="ghost" @click="quick(30)">30 días</px-button>
          <px-button size="sm" variant="ghost" @click="quick(90)">90 días</px-button>
        </div>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el reporte" class="pxrad__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxrad__pad">
        <px-skeleton variant="table" :rows="10" :columns="8" />
      </div>

      <template v-else>
        <div class="pxrad__kpis">
          <px-stat label="Ajustes" :value="num(kpis.adjustments_count)" icon="pencil" bordered />
          <px-stat label="Cantidad añadida (add)" :value="fmtQty(kpis.qty_added)" icon="plus" bordered />
          <px-stat label="Cantidad retirada (sub)" :value="fmtQty(kpis.qty_removed)" icon="minus" bordered />
          <px-stat label="Cantidad neta" :value="fmtQty(kpis.net_qty)" icon="refresh-cw" bordered />
        </div>

        <div class="pxrad__tablewrap" :class="{ 'is-busy': refreshing }">
          <px-table
            v-if="rows.length"
            :columns="columns"
            :rows="rows"
            row-key="rk"
            :sort-key="sort.field"
            :sort-dir="sort.type"
            @sort="onSort"
          >
            <template #cell-ref="{ row }"><span class="pxn-mono">{{ row.ref }}</span></template>
            <template #cell-qty="{ row }"><span class="pxn-num">{{ fmtQty(row.qty) }}</span></template>
            <template #cell-net_qty="{ row }"><span class="pxn-num">{{ fmtQty(row.net_qty) }}</span></template>
            <template #cell-purchase_cost="{ row }"><span class="pxn-num">{{ money(row.purchase_cost) }}</span></template>
            <template #cell-sale_price="{ row }"><span class="pxn-num">{{ money(row.sale_price) }}</span></template>
          </px-table>

          <px-empty-state v-else icon="pencil" title="Sin ajustes"
            description="No hay ajustes en el rango y filtros seleccionados." />
        </div>

        <div v-if="rows.length" class="pxrad__totals">
          <div><span>Totales (página)</span></div>
          <div class="pxrad__totals-vals">
            <span>Cantidad <strong class="pxn-num">{{ fmtQty(totals.qty) }}</strong></span>
            <span>Neto <strong class="pxn-num">{{ fmtQty(totals.net_qty) }}</strong></span>
            <span>Coste <strong class="pxn-num">{{ money(totals.purchase_cost) }}</strong></span>
            <span>P. venta <strong class="pxn-num">{{ money(totals.sale_price) }}</strong></span>
          </div>
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
  name: "StockAdjustmentReportNext",
  metaInfo: { title: "Reporte de ajustes" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu,
    PxField, PxInput, PxStat, PxAlert, PxEmptyState, "vs-px": VsPx
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
      warehouses: [],
      kpis: { adjustments_count: 0, qty_added: 0, qty_removed: 0, net_qty: 0 },
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
    columns() {
      return [
        { key: "adj_id", label: "ID", sortable: true, strong: true, width: "80px" },
        { key: "ref", label: "Referencia", sortable: true },
        { key: "date", label: "Fecha", sortable: true, width: "150px" },
        { key: "warehouse", label: "Almacén", sortable: true },
        { key: "qty", label: "Cantidad", align: "right", numeric: true, sortable: true, width: "110px" },
        { key: "net_qty", label: "Neto", align: "right", numeric: true, sortable: true, width: "110px" },
        { key: "purchase_cost", label: "Coste", align: "right", numeric: true, sortable: false, width: "130px" },
        { key: "sale_price", label: "P. venta", align: "right", numeric: true, sortable: false, width: "130px" }
      ];
    },
    rows() {
      return (this.report || []).map((r, i) => ({ ...r, rk: (r.adj_id != null ? r.adj_id : "") + "-" + i }));
    },
    totals() {
      return (this.report || []).reduce(
        (acc, r) => {
          acc.qty += Number(r.qty || 0);
          acc.net_qty += Number(r.net_qty || 0);
          acc.purchase_cost += Number(r.purchase_cost || 0);
          acc.sale_price += Number(r.sale_price || 0);
          return acc;
        },
        { qty: 0, net_qty: 0, purchase_cost: 0, sale_price: 0 }
      );
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
      const n = Number(v || 0);
      return isNaN(n) ? "0" : n.toLocaleString();
    },
    fmtQty(v) {
      const n = Number(v || 0);
      return isNaN(n) ? "0" : n.toLocaleString(undefined, { maximumFractionDigits: 2 });
    },
    money(v) {
      const n = Number(v || 0);
      const safe = isNaN(n) ? 0 : n;
      return this.currency + " " + safe.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    warehouseName() {
      if (!this.warehouse_id) return "Todos";
      const w = (this.warehouses || []).find(x => Number(x.id) === Number(this.warehouse_id));
      return w ? w.name : "#" + this.warehouse_id;
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
    buildQs(pageOverride, limitOverride) {
      return new URLSearchParams({
        from: this.from,
        to: this.to,
        warehouse_id: this.warehouse_id || "",
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
        .get("report/stock_adjustment?" + this.buildQs())
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
        const { data } = await window.axios.get("report/stock_adjustment?" + this.buildQs(page, per));
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
      return ["ID", "Referencia", "Fecha", "Almacén", "Cantidad", "Neto", "Coste", "P. venta"];
    },
    exportRows(items) {
      return (items || []).map(r => [
        r.adj_id, r.ref, r.date, r.warehouse,
        this.fmtQty(r.qty), this.fmtQty(r.net_qty), this.money(r.purchase_cost), this.money(r.sale_price)
      ]);
    },
    exportFooter(items) {
      const t = (items || []).reduce(
        (acc, r) => {
          acc.qty += Number(r.qty || 0);
          acc.net += Number(r.net_qty || 0);
          acc.cost += Number(r.purchase_cost || 0);
          acc.sale += Number(r.sale_price || 0);
          return acc;
        },
        { qty: 0, net: 0, cost: 0, sale: 0 }
      );
      return ["Totales", "", "", "", this.fmtQty(t.qty), this.fmtQty(t.net), this.money(t.cost), this.money(t.sale)];
    },
    async onExport(item) {
      const k = item && item.key;
      if (k === "print") {
        const ok = printTableDoc({
          title: "Informes / Reporte de ajustes · " + this.from + " – " + this.to + " · Almacén: " + this.warehouseName(),
          headers: this.exportHeaders(),
          rows: this.exportRows(this.report),
          footer: this.exportFooter(this.report),
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
            title: "Reporte de ajustes",
            subtitle: this.from + " – " + this.to + "  ·  Almacén: " + this.warehouseName(),
            filename: "Ajustes_" + this.from + "_" + this.to,
            headers: this.exportHeaders(),
            rows: this.exportRows(all),
            footer: this.exportFooter(all),
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
.pxrad { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrad { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrad__denied { padding: var(--pxn-space-12) 0; }
.pxrad__pad { padding: var(--pxn-space-6) 0; }
.pxrad__alert { margin-top: var(--pxn-space-5); }
.pxrad__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxrad__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 720px) { .pxrad__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxrad__quick { display: flex; align-items: center; flex-wrap: wrap; gap: var(--pxn-space-2); margin-top: var(--pxn-space-4); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxrad__kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-4); margin-top: var(--pxn-space-5); }
@media (max-width: 720px) { .pxrad__kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.pxrad__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxrad__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
.pxrad__totals {
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--pxn-space-3);
  margin-top: var(--pxn-space-4); padding: var(--pxn-space-3) var(--pxn-space-4);
  background: var(--pxn-surface-2); border-radius: var(--pxn-radius-md); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3);
}
.pxrad__totals-vals { display: flex; flex-wrap: wrap; gap: var(--pxn-space-5); }
.pxrad__totals-vals strong { color: var(--pxn-ink); margin-left: var(--pxn-space-2); }
</style>
