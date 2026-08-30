<template>
  <div class="px-next pxrd">
    <!--
      C3.11 — Reporte de stock muerto px-next (solo lectura). Ruta real
      /app/reports/dead_stock_report (name dead_stock_report). Endpoint
      GET report/dead_stock sin cambios: el criterio de "stock muerto" y el
      cálculo de "días sin movimiento" los define el backend. Filtro de periodo
      (30/60/90), búsqueda, orden, paginación (incluye "Todos"), y exportaciones
      (PDF página / PDF completo / imprimir).
    -->
    <div v-if="!can('Dead_Stock_Report')" class="pxrd__denied">
      <px-empty-state icon="lock" title="No tienes permiso para el reporte de stock muerto"
        description="Pide a un administrador el permiso «Dead_Stock_Report»." />
    </div>

    <template v-else>
      <px-page-header title="Reporte de stock muerto" :breadcrumbs="[{ label: 'Informes' }, { label: 'Stock muerto' }]">
        <template #actions>
          <px-menu :items="exportMenu" align="end" @select="onExport">
            <template #trigger>
              <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down" :disabled="exporting || !totalRows">Exportar</px-button>
            </template>
          </px-menu>
        </template>
      </px-page-header>

      <px-toolbar
        :search="search"
        search-placeholder="Buscar por código, producto…"
        :filter-count="period !== 60 ? 1 : 0"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxrd__filters">
        <px-field label="Periodo sin movimiento">
          <template #default="{ id }">
            <px-select :id="id" :value="String(period)" :options="periodOptions" @input="onPeriodChange" />
          </template>
        </px-field>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el reporte" class="pxrd__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxrd__pad">
        <px-skeleton variant="table" :rows="10" :columns="5" />
      </div>

      <template v-else>
        <p class="pxrd__range">Mostrando {{ range.from }} – {{ range.to }} de {{ totalRows }}</p>

        <div class="pxrd__tablewrap" :class="{ 'is-busy': refreshing }">
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
            <template #cell-last_movement_at="{ row }">{{ row.last_movement_at || '—' }}</template>
            <template #cell-days_since_last_movement="{ row }">
              <px-badge v-if="!row.last_movement_at" tone="warning">Nunca movido</px-badge>
              <span v-else class="pxn-num">{{ row.days_since_last_movement }}</span>
            </template>
          </px-table>

          <px-empty-state v-else icon="clock" title="Sin stock muerto"
            description="Ningún producto cumple el criterio de stock muerto para el periodo elegido." />
        </div>

        <px-pagination
          v-if="rows.length"
          :page="page"
          :per-page="perPage === -1 ? (Number(totalRows) || 1) : Number(perPage)"
          :total="Number(totalRows) || 0"
          :per-page-options="['10', '20', '50', '100', 'Todos']"
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
import PxSelect from "@/components/px-next/PxSelect.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import { printTableDoc, exportPdf } from "../reportUtils.js";

export default {
  name: "DeadStockReportNext",
  metaInfo: { title: "Reporte de stock muerto" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu,
    PxField, PxSelect, PxBadge, PxAlert, PxEmptyState
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      exporting: false,
      error: null,
      report: [],
      totalRows: 0,
      range: { from: 0, to: 0 },
      page: 1,
      perPage: 10, // -1 = Todos
      search: "",
      _searchTimer: null,
      sort: { field: "days_since_last_movement", type: "desc" },
      filtersOpen: false,
      period: 60
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    periodOptions() {
      return [
        { value: "30", label: "Últimos 30 días" },
        { value: "60", label: "Últimos 60 días" },
        { value: "90", label: "Últimos 90 días" }
      ];
    },
    columns() {
      return [
        { key: "code", label: "Código", sortable: true, strong: true },
        { key: "product_name", label: "Producto", sortable: true },
        { key: "on_hand", label: "En stock", align: "right", numeric: true, sortable: true, width: "110px" },
        { key: "last_movement_at", label: "Último movimiento", sortable: true, width: "170px" },
        { key: "days_since_last_movement", label: "Días sin movimiento", align: "right", numeric: true, sortable: true, width: "160px" }
      ];
    },
    rows() {
      return (this.report || []).map((r, i) => ({ ...r, rk: (r.code || "") + "-" + i }));
    },
    exportMenu() {
      return [
        { key: "pdf", label: "PDF (página actual)", icon: "file-text" },
        { key: "pdf_all", label: "PDF (todo)", icon: "file-text" },
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
    fmtNum(n) {
      const v = Number(n);
      return Number.isFinite(v) ? String(v) : String(n == null ? "" : n);
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.fetch(); }, 350);
    },
    onSort({ key, dir }) { this.sort = { field: key, type: dir }; this.fetch(); },
    onPage(p) { if (p !== this.page) { this.page = p; this.fetch(); } },
    onPerPage(v) {
      this.perPage = v === "Todos" || v === -1 || v === "-1" ? -1 : Number(v);
      this.page = 1;
      this.fetch();
    },
    onPeriodChange(v) {
      this.period = Number(v);
      this.page = 1;
      this.fetch();
    },
    buildQuery({ page, limitOverride = null } = {}) {
      const qp = new URLSearchParams({
        page: String(page == null ? this.page : page),
        SortField: this.sort.field || "days_since_last_movement",
        SortType: this.sort.type || "desc",
        search: this.search || "",
        limit: String(limitOverride != null ? limitOverride : this.perPage),
        period: String(this.period)
      });
      return qp.toString();
    },
    fetch(initial) {
      if (initial) this.initialLoading = true; else this.refreshing = true;
      this.error = null;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get("report/dead_stock?" + this.buildQuery({ page: this.page }))
        .then(({ data }) => {
          const items = Array.isArray(data.report) ? data.report : [];
          this.report = items;
          this.totalRows = Number(data.totalRows || 0);
          const r = data.range || {};
          const per = this.perPage === -1 ? (this.totalRows || items.length) : this.perPage;
          this.range = {
            from: Number(r.from || (this.totalRows ? (this.page - 1) * per + 1 : 0)),
            to: Number(r.to || Math.min(this.totalRows, (this.page - 1) * per + items.length))
          };
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
    exportHeaders() {
      return ["Código", "Producto", "En stock", "Último movimiento", "Días sin movimiento"];
    },
    exportRowsFrom(items) {
      return (items || []).map(r => [
        r.code || "",
        r.product_name || r.product || "",
        typeof r.on_hand === "number" ? String(r.on_hand) : (r.on_hand == null ? "" : r.on_hand),
        r.last_movement_at || "—",
        r.last_movement_at ? (r.days_since_last_movement == null ? "" : r.days_since_last_movement) : "Nunca movido"
      ]);
    },
    periodLabel() {
      const o = this.periodOptions.find(x => String(x.value) === String(this.period));
      return o ? o.label : String(this.period) + " días";
    },
    onExport(item) {
      const k = item && item.key;
      if (k === "print") {
        const ok = printTableDoc({
          title: "Informes / Reporte de stock muerto (" + this.periodLabel() + ")",
          headers: this.exportHeaders(),
          rows: this.exportRowsFrom(this.report),
          landscape: true
        });
        if (!ok) this.$root.$bvToast.toast("Permite las ventanas emergentes para imprimir.", { title: "Aviso", variant: "warning", solid: true });
        return;
      }
      if (k === "pdf") {
        exportPdf({
          title: "Reporte de stock muerto",
          subtitle: "Periodo: " + this.periodLabel(),
          filename: "stock_muerto_" + this.period + "_pagina",
          headers: this.exportHeaders(),
          rows: this.exportRowsFrom(this.report),
          landscape: true
        });
        return;
      }
      if (k === "pdf_all") {
        this.exporting = true;
        NProgress.start(); NProgress.set(0.2);
        window.axios
          .get("report/dead_stock?" + this.buildQuery({ page: 1, limitOverride: -1 }))
          .then(({ data }) => {
            const items = Array.isArray(data.report) ? data.report : [];
            exportPdf({
              title: "Reporte de stock muerto",
              subtitle: "Periodo: " + this.periodLabel() + " · Todos los registros",
              filename: "stock_muerto_" + this.period + "_todo",
              headers: this.exportHeaders(),
              rows: this.exportRowsFrom(items),
              landscape: true
            });
          })
          .catch(() => {
            this.$root.$bvToast.toast("No se pudo exportar el reporte completo.", { title: "Error", variant: "danger", solid: true });
          })
          .then(() => {
            this.exporting = false;
            NProgress.done();
          });
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrd { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrd { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrd__denied { padding: var(--pxn-space-12) 0; }
.pxrd__pad { padding: var(--pxn-space-6) 0; }
.pxrd__alert { margin-top: var(--pxn-space-5); }
.pxrd__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); max-width: 420px; }
.pxrd__range { margin: var(--pxn-space-4) 0 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxrd__tablewrap { margin-top: var(--pxn-space-3); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxrd__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
</style>
