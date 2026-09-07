<template>
  <div class="px-next pxrot">
    <!--
      Rotación de inventario px-next (solo lectura). Ruta real
      /app/reports/inventory_turnover (name inventory_turnover). Endpoint
      GET report/inventory_turnover.
      Métrica ÚNICA en UNIDADES (rotación = unidades vendidas netas /
      inventario promedio). No hay rotación financiera: PRODEX no conserva COGS
      histórico y el WAC no se puede acotar de forma fiable al período/sucursal.
      Alcance branch-first / legacy-fallback. Los casos sin dato se muestran
      como "N/A" — nunca infinito ni 0 engañoso. Fórmula y umbrales del backend.
    -->
    <div v-if="!can('inventory_turnover_report')" class="pxrot__denied">
      <px-empty-state icon="lock" title="No tienes permiso para este reporte"
        description="Pide a un administrador el permiso «inventory_turnover_report»." />
    </div>

    <template v-else>
      <px-page-header title="Rotación de inventario" :breadcrumbs="[{ label: 'Informes' }, { label: 'Rotación de inventario' }]">
        <template #actions>
          <px-button variant="secondary" size="sm" icon="printer" @click="doPrint">Imprimir</px-button>
        </template>
      </px-page-header>

      <px-toolbar
        :search="search"
        search-placeholder="Buscar por código o producto…"
        :filter-count="activeFilterCount"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxrot__filters">
        <div class="pxrot__filters-grid">
          <px-field label="Desde">
            <template #default="{ id }"><input :id="id" v-model="from" type="date" class="pxrot__date pxn-ring" @change="refresh" /></template>
          </px-field>
          <px-field label="Hasta">
            <template #default="{ id }"><input :id="id" v-model="to" type="date" class="pxrot__date pxn-ring" @change="refresh" /></template>
          </px-field>
          <px-field label="Sucursal">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="branch_id" :reduce="o => o.value" placeholder="Todas"
                :options="branchOptions" @input="onBranchChange" />
            </template>
          </px-field>
          <px-field label="Ubicación">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="inventory_location_id" :reduce="o => o.value" placeholder="Todas"
                :options="locationOptions" @input="refresh" />
            </template>
          </px-field>
          <px-field label="Categoría">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="category_id" :reduce="o => o.value" placeholder="Todas"
                :options="categoryOptions" @input="refresh" />
            </template>
          </px-field>
        </div>
      </div>

      <px-alert v-if="meta && meta.formula" tone="info" title="Cómo se calcula" class="pxrot__alert">
        {{ meta.formula }}
        <template v-if="meta.thresholds">
          <br>Clasificación por días de inventario: <b>alta</b> ≤ {{ meta.thresholds.alta_max_dias }} ·
          <b>media</b> ≤ {{ meta.thresholds.media_max_dias }} · <b>baja</b> &gt; {{ meta.thresholds.media_max_dias }}.
        </template>
        <br>Rotación en unidades. No se ofrece rotación financiera: PRODEX no conserva COGS histórico por venta/ajuste/daño.
      </px-alert>

      <px-alert v-if="meta && meta.to_clamped" tone="info" title="Fecha ajustada" class="pxrot__alert">
        No se puede calcular stock futuro; la fecha "hasta" se ajustó a hoy ({{ meta.to }}).
      </px-alert>

      <px-alert v-if="meta && meta.capped" tone="warning" title="Reporte acotado" class="pxrot__alert">
        Hay {{ meta.matched_products }} productos en el alcance; se evalúan los primeros
        {{ meta.max_products }} (orden alfabético). Filtra por categoría o busca para acotar el universo.
      </px-alert>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el reporte" class="pxrot__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxrot__pad">
        <px-skeleton variant="table" :rows="10" :columns="7" />
      </div>

      <template v-else>
        <div class="pxrot__tablewrap" :class="{ 'is-busy': refreshing }">
          <px-table
            v-if="rows.length"
            :columns="columns"
            :rows="rows"
            row-key="product_id"
            :sort-key="sort.field"
            :sort-dir="sort.type"
            @sort="onSort"
          >
            <template #cell-code="{ row }"><span class="pxn-mono">{{ row.code }}</span></template>
            <template #cell-units_sold="{ row }"><span class="pxn-num">{{ fmtNum(row.units_sold) }}</span></template>
            <template #cell-stock_initial="{ row }"><span class="pxn-num">{{ na(row.stock_initial) }}</span></template>
            <template #cell-stock_final="{ row }"><span class="pxn-num">{{ na(row.stock_final) }}</span></template>
            <template #cell-avg_stock="{ row }"><span class="pxn-num">{{ na(row.avg_stock) }}</span></template>
            <template #cell-turnover="{ row }">
              <span class="pxn-num" :title="row.turnover == null ? (row.reason || '') : ''">{{ row.turnover == null ? 'N/A' : fmtNum(row.turnover) }}</span>
            </template>
            <template #cell-days_inventory="{ row }">
              <span class="pxn-num">{{ row.days_inventory == null ? '—' : fmtNum(row.days_inventory) }}</span>
            </template>
            <template #cell-classification="{ row }">
              <px-badge v-if="row.classification" :tone="classTone(row.classification)">{{ classLabel(row.classification) }}</px-badge>
              <span v-else class="pxrot__na" :title="row.reason || ''">N/A</span>
            </template>
          </px-table>

          <px-empty-state v-else icon="refresh-cw" title="Sin resultados"
            description="Ningún producto coincide con los filtros seleccionados." />
        </div>

        <px-pagination
          v-if="rows.length"
          :page="page"
          :per-page="Number(limit)"
          :total="Number(totalRows) || 0"
          :per-page-options="['25', '50', '100']"
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
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";
import { printTableDoc } from "../reportUtils.js";

export default {
  name: "InventoryTurnoverReportNext",
  metaInfo: { title: "Rotación de inventario" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton,
    PxField, PxBadge, PxAlert, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      error: null,
      filtersOpen: false,
      search: "",
      _searchTimer: null,
      from: "",
      to: "",
      warehouse_id: "",        // sólo compat legacy / deep-link
      branch_id: "",
      inventory_location_id: "",
      category_id: "",
      sort: { field: "turnover", type: "desc" },
      page: 1,
      limit: "25",
      report: [],
      totalRows: 0,
      meta: null,
      branches: [],
      inventoryLocations: [],
      categories: []
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    branchOptions() { return (this.branches || []).map(b => ({ label: b.name, value: Number(b.id) })); },
    categoryOptions() { return (this.categories || []).map(c => ({ label: c.name, value: Number(c.id) })); },
    locationOptions() {
      const b = (this.branch_id !== "" && this.branch_id != null) ? Number(this.branch_id) : null;
      return (this.inventoryLocations || [])
        .filter(l => b == null || Number(l.branch_id) === b)
        .map(l => ({ label: l.name, value: Number(l.id) }));
    },
    // Whitelist de campos ordenables (debe coincidir con el backend).
    sortableFields() {
      return ["code", "name", "category", "units_sold", "stock_initial", "stock_final", "avg_stock", "turnover", "days_inventory"];
    },
    activeFilterCount() {
      let n = 0;
      if (this.from) n++;
      if (this.to) n++;
      if (this.branch_id !== "" && this.branch_id != null) n++;
      if (this.inventory_location_id !== "" && this.inventory_location_id != null) n++;
      if (this.category_id !== "" && this.category_id != null) n++;
      return n;
    },
    columns() {
      return [
        { key: "code", label: "Código", sortable: true, strong: true, width: "120px" },
        { key: "name", label: "Producto", sortable: true },
        { key: "category", label: "Categoría", sortable: true },
        { key: "units_sold", label: "Unid. vendidas", align: "right", numeric: true, sortable: true, width: "120px" },
        { key: "stock_initial", label: "Stock inicio período", align: "right", numeric: true, sortable: true, width: "150px" },
        { key: "stock_final", label: "Stock fin período", align: "right", numeric: true, sortable: true, width: "140px" },
        { key: "avg_stock", label: "Inv. promedio", align: "right", numeric: true, sortable: true, width: "120px" },
        { key: "turnover", label: "Rotación", align: "right", numeric: true, sortable: true, width: "100px" },
        { key: "days_inventory", label: "Días inv.", align: "right", numeric: true, sortable: true, width: "100px" },
        { key: "classification", label: "Clasificación", width: "120px" }
      ];
    },
    rows() {
      return this.report || [];
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
      return Number.isFinite(v) ? v.toLocaleString(undefined, { maximumFractionDigits: 3 }) : String(n == null ? "" : n);
    },
    na(v) { return v == null ? "N/A" : this.fmtNum(v); },
    classTone(c) { return c === "alta" ? "success" : (c === "media" ? "info" : "warning"); },
    classLabel(c) { return c === "alta" ? "Alta" : (c === "media" ? "Media" : "Baja"); },
    init() { this.fetch(true); },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.fetch(); }, 350);
    },
    onSort({ key, dir }) {
      // Cada columna ordenable ordena por SÍ MISMA (texto o numérico); sólo se
      // ignora una clave fuera de la whitelist.
      const field = this.sortableFields.includes(key) ? key : this.sort.field;
      this.sort = { field, type: dir };
      this.fetch();
    },
    onPage(p) { if (p !== this.page) { this.page = p; this.fetch(); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.fetch(); },
    onBranchChange() {
      // Al cambiar de sucursal, una ubicación de otra sucursal deja de aplicar.
      const ok = this.locationOptions.some(o => o.value === Number(this.inventory_location_id));
      if (!ok) this.inventory_location_id = "";
      this.refresh();
    },
    refresh() { this.page = 1; this.fetch(); },
    qs() {
      const p = {
        page: this.page,
        limit: this.limit,
        SortField: this.sort.field,
        SortType: this.sort.type,
        search: this.search || "",
        from: this.from || "",
        to: this.to || "",
        warehouse_id: this.warehouse_id != null ? this.warehouse_id : "",
        branch_id: this.branch_id != null ? this.branch_id : "",
        inventory_location_id: this.inventory_location_id != null ? this.inventory_location_id : "",
        category_id: this.category_id != null ? this.category_id : ""
      };
      return Object.keys(p)
        .filter(k => p[k] !== "" && p[k] != null)
        .map(k => k + "=" + encodeURIComponent(p[k]))
        .join("&");
    },
    fetch(initial) {
      if (initial) this.initialLoading = true; else this.refreshing = true;
      this.error = null;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get("report/inventory_turnover?" + this.qs())
        .then(({ data }) => {
          this.report = Array.isArray(data.rows) ? data.rows : [];
          this.totalRows = Number(data.totalRows || 0);
          this.meta = data.meta || null;
          this.branches = data.branches || [];
          this.inventoryLocations = data.inventory_locations || [];
          this.categories = data.categories || [];
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
          switch (c.key) {
            case "units_sold": return this.fmtNum(r.units_sold);
            case "stock_initial": return this.na(r.stock_initial);
            case "stock_final": return this.fmtNum(r.stock_final);
            case "avg_stock": return this.na(r.avg_stock);
            case "turnover": return r.turnover == null ? "N/A" : this.fmtNum(r.turnover);
            case "days_inventory": return r.days_inventory == null ? "—" : this.fmtNum(r.days_inventory);
            case "classification": return r.classification ? this.classLabel(r.classification) : "N/A";
            default: return r[c.key] == null ? "" : r[c.key];
          }
        })
      );
      const sub = this.meta ? `Período ${this.meta.from} — ${this.meta.to}` : "";
      const ok = printTableDoc({ title: "Informes / Rotación de inventario", headers, rows, landscape: true, subtitle: sub });
      if (!ok && this.$root.$bvToast) {
        this.$root.$bvToast.toast("Permite las ventanas emergentes para imprimir.", { title: "Aviso", variant: "warning", solid: true });
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrot { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrot { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrot__denied { padding: var(--pxn-space-12) 0; }
.pxrot__pad { padding: var(--pxn-space-6) 0; }
.pxrot__alert { margin-top: var(--pxn-space-5); }
.pxrot__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxrot__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxrot__filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 620px) { .pxrot__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxrot__date {
  width: 100%; height: var(--pxn-control-h-sm); padding: 0 var(--pxn-space-4);
  border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); color: var(--pxn-ink); font: inherit; font-size: var(--pxn-fs-sm);
}
.pxrot__tablewrap { margin-top: var(--pxn-space-5); overflow-x: auto; transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxrot__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
.pxrot__na { color: var(--pxn-ink-3); font-size: var(--pxn-fs-sm); }
</style>
