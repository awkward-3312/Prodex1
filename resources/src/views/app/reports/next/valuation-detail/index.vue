<template>
  <div class="px-next pxrvd">
    <!--
      C3.16b — Valoración de inventario · DETALLE px-next (solo lectura). Ruta
      real /app/reports/stock_inventory_valuation (name stock_inventory_valuation).
      Endpoint GET report/stock_inventory_valuation sin cambios. Vista de detalle
      por producto × variante × almacén: precio de venta, valor a coste/venta,
      ganancia potencial y métricas de movimiento del periodo (vendidas,
      trasladadas, ajustadas). Filtros: rango de fechas + almacén. Precisión
      monetaria backend conservada. NO se fusiona con el Resumen
      (inventory_valuation_summary): endpoints, columnas y filtros distintos.
    -->
    <div v-if="!can('Stock_Inventory_Valuation')" class="pxrvd__denied">
      <px-empty-state icon="lock" title="No tienes permiso para este reporte"
        description="Pide a un administrador el permiso «Stock_Inventory_Valuation»." />
    </div>

    <template v-else>
      <px-page-header title="Valoración de inventario · Detalle" :breadcrumbs="[{ label: 'Informes' }, { label: 'Valoración de inventario' }, { label: 'Detalle' }]">
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
        search-placeholder="Buscar por SKU, producto, categoría…"
        :filter-count="warehouse_id ? 1 : 0"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxrvd__filters">
        <div class="pxrvd__filters-grid">
          <px-field label="Desde">
            <template #default="{ id }"><px-input :id="id" type="date" v-model="dateFrom" @change="applyFilters" /></template>
          </px-field>
          <px-field label="Hasta">
            <template #default="{ id }"><px-input :id="id" type="date" v-model="dateTo" @change="applyFilters" /></template>
          </px-field>
          <px-field label="Almacén">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="warehouse_id" :reduce="o => o.value" placeholder="Todos los almacenes"
                :options="warehouseOptions" @input="applyFilters" />
            </template>
          </px-field>
        </div>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el reporte" class="pxrvd__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxrvd__pad">
        <px-skeleton variant="table" :rows="10" :columns="8" />
      </div>

      <template v-else>
        <div class="pxrvd__tablewrap" :class="{ 'is-busy': refreshing }">
          <px-table
            v-if="rows.length"
            :columns="columns"
            :rows="rows"
            row-key="rk"
          >
            <template #cell-sku="{ row }"><span class="pxn-mono">{{ row.sku }}</span></template>
            <template #cell-selling_price="{ row }"><span class="pxn-num">{{ money(row.selling_price) }}</span></template>
            <template #cell-current_quantity="{ row }"><span class="pxn-num">{{ qty(row.current_quantity, row.current_quantity_unit) }}</span></template>
            <template #cell-stock_value_cost="{ row }"><span class="pxn-num">{{ money(row.stock_value_cost) }}</span></template>
            <template #cell-stock_value_selling="{ row }"><span class="pxn-num">{{ money(row.stock_value_selling) }}</span></template>
            <template #cell-potential_profit="{ row }"><span class="pxn-num">{{ money(row.potential_profit) }}</span></template>
            <template #cell-total_units_sold="{ row }"><span class="pxn-num">{{ qty(row.total_units_sold, row.total_units_sold_unit) }}</span></template>
            <template #cell-total_units_transferred="{ row }"><span class="pxn-num">{{ qty(row.total_units_transferred, row.total_units_transferred_unit) }}</span></template>
            <template #cell-total_units_adjusted="{ row }"><span class="pxn-num">{{ qty(row.total_units_adjusted, row.total_units_adjusted_unit) }}</span></template>
          </px-table>

          <px-empty-state v-else icon="pie-chart" title="Sin datos de valoración"
            description="No hay productos que coincidan con los filtros." />
        </div>

        <div v-if="rows.length" class="pxrvd__totals">
          <span>Totales (página)</span>
          <span>Stock <strong class="pxn-num">{{ totals.current_quantity }}</strong></span>
          <span>Valor a coste <strong class="pxn-num">{{ money(totals.stock_value_cost) }}</strong></span>
          <span>Valor a venta <strong class="pxn-num">{{ money(totals.stock_value_selling) }}</strong></span>
          <span>Ganancia potencial <strong class="pxn-num">{{ money(totals.potential_profit) }}</strong></span>
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
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting,
  getPriceDecimals
} from "@/utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxMenu from "@/components/px-next/PxMenu.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";
import { printTableDoc, exportPdf } from "../reportUtils.js";

const iso = d => {
  const x = d instanceof Date ? d : new Date(d);
  return x.toISOString().slice(0, 10);
};

export default {
  name: "StockInventoryValuationNext",
  metaInfo: { title: "Valoración de inventario · Detalle" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu,
    PxField, PxInput, PxAlert, PxEmptyState, "vs-px": VsPx
  },
  data() {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - 29);
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
      warehouse_id: 0,
      dateFrom: iso(start),
      dateTo: iso(end),
      price_format_key: null
    };
  },
  computed: {
    ...mapGetters(["currentUser", "currentUserPermissions"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    currency() {
      return (this.currentUser && this.currentUser.currency) || "";
    },
    warehouseOptions() {
      return [{ label: "Todos los almacenes", value: 0 }].concat(
        (this.warehouses || []).map(w => ({ label: w.name, value: w.id }))
      );
    },
    columns() {
      return [
        { key: "sku", label: "SKU", sortable: false, strong: true, width: "130px" },
        { key: "product_name", label: "Producto", sortable: false },
        { key: "variant", label: "Variante", sortable: false },
        { key: "category", label: "Categoría", sortable: false },
        { key: "warehouse", label: "Almacén", sortable: false },
        { key: "selling_price", label: "P. venta unit.", align: "right", numeric: true, sortable: false, width: "130px" },
        { key: "current_quantity", label: "Cantidad actual", align: "right", numeric: true, sortable: false, width: "150px" },
        { key: "stock_value_cost", label: "Valor a coste", align: "right", numeric: true, sortable: false, width: "140px" },
        { key: "stock_value_selling", label: "Valor a venta", align: "right", numeric: true, sortable: false, width: "140px" },
        { key: "potential_profit", label: "Ganancia potencial", align: "right", numeric: true, sortable: false, width: "160px" },
        { key: "total_units_sold", label: "Uds. vendidas", align: "right", numeric: true, sortable: false, width: "140px" },
        { key: "total_units_transferred", label: "Uds. trasladadas", align: "right", numeric: true, sortable: false, width: "150px" },
        { key: "total_units_adjusted", label: "Uds. ajustadas", align: "right", numeric: true, sortable: false, width: "140px" }
      ];
    },
    rows() {
      return (this.reports || []).map((r, i) => ({ ...r, rk: (r.sku || "") + "-" + (r.variant || "") + "-" + (r.warehouse || "") + "-" + i }));
    },
    totals() {
      const t = (this.reports || []).reduce(
        (acc, r) => {
          acc.qtyNum += parseFloat(r.current_quantity || 0);
          acc.stock_value_cost += parseFloat(r.stock_value_cost || 0);
          acc.stock_value_selling += parseFloat(r.stock_value_selling || 0);
          acc.potential_profit += parseFloat(r.potential_profit || 0);
          return acc;
        },
        { qtyNum: 0, stock_value_cost: 0, stock_value_selling: 0, potential_profit: 0 }
      );
      t.current_quantity = t.qtyNum.toFixed(3) + " Pcs";
      return t;
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
    priceDisplay(number) {
      try {
        const n = Number(number || 0);
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) this.price_format_key = key;
        return formatPriceDisplayHelper(n, this.priceDecimals, key || null);
      } catch (e) {
        const n = Number(number || 0);
        return n.toLocaleString(undefined, { maximumFractionDigits: this.priceDecimals });
      }
    },
    money(number) {
      const v = this.priceDisplay(number);
      return this.currency ? this.currency + " " + v : String(v);
    },
    qty(number, unit) {
      const n = parseFloat(number || 0);
      return (isNaN(n) ? 0 : n).toFixed(3) + " " + (unit || "Pcs");
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
    onPage(p) { if (p !== this.page) { this.page = p; this.fetch(); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.fetch(); },
    applyFilters() { this.page = 1; this.fetch(); },
    fetch(initial) {
      if (initial) this.initialLoading = true; else this.refreshing = true;
      this.error = null;
      NProgress.start(); NProgress.set(0.1);
      const qs =
        "report/stock_inventory_valuation?page=" + this.page +
        "&SortField=" + encodeURIComponent(this.sort.field) +
        "&SortType=" + encodeURIComponent(this.sort.type) +
        "&warehouse_id=" + encodeURIComponent(this.warehouse_id) +
        "&search=" + encodeURIComponent(this.search || "") +
        "&limit=" + encodeURIComponent(this.limit) +
        "&date_from=" + encodeURIComponent(this.dateFrom || "") +
        "&date_to=" + encodeURIComponent(this.dateTo || "");
      window.axios
        .get(qs)
        .then(response => {
          this.reports = response.data.reports || [];
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
    exportHeaders() {
      return [
        "SKU", "Producto", "Variante", "Categoría", "Almacén", "P. venta unit.", "Cantidad actual",
        "Valor a coste", "Valor a venta", "Ganancia potencial", "Uds. vendidas", "Uds. trasladadas", "Uds. ajustadas"
      ];
    },
    exportRows() {
      return (this.reports || []).map(r => [
        r.sku, r.product_name, r.variant, r.category, r.warehouse,
        this.money(r.selling_price), this.qty(r.current_quantity, r.current_quantity_unit),
        this.money(r.stock_value_cost), this.money(r.stock_value_selling), this.money(r.potential_profit),
        this.qty(r.total_units_sold, r.total_units_sold_unit),
        this.qty(r.total_units_transferred, r.total_units_transferred_unit),
        this.qty(r.total_units_adjusted, r.total_units_adjusted_unit)
      ]);
    },
    onExport(item) {
      const k = item && item.key;
      const headers = this.exportHeaders();
      const rows = this.exportRows();
      const t = this.totals;
      const footer = [
        "Total", "", "", "", "", "", t.current_quantity,
        this.money(t.stock_value_cost), this.money(t.stock_value_selling), this.money(t.potential_profit), "", "", ""
      ];
      const subtitle = "Periodo: " + this.dateFrom + " – " + this.dateTo + "  ·  Almacén: " + this.warehouseName();
      if (k === "pdf") {
        exportPdf({ title: "Valoración de inventario · Detalle", subtitle, filename: "Valoracion_inventario_detalle", headers, rows, footer, landscape: true });
      } else if (k === "print") {
        const ok = printTableDoc({ title: "Informes / Valoración de inventario · Detalle · " + subtitle, headers, rows, footer, landscape: true });
        if (!ok) this.$root.$bvToast.toast("Permite las ventanas emergentes para imprimir.", { title: "Aviso", variant: "warning", solid: true });
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrvd { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrvd { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrvd__denied { padding: var(--pxn-space-12) 0; }
.pxrvd__pad { padding: var(--pxn-space-6) 0; }
.pxrvd__alert { margin-top: var(--pxn-space-5); }
.pxrvd__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxrvd__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 720px) { .pxrvd__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxrvd__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxrvd__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
.pxrvd__totals {
  display: flex; align-items: center; gap: var(--pxn-space-5); flex-wrap: wrap;
  margin-top: var(--pxn-space-4); padding: var(--pxn-space-3) var(--pxn-space-4);
  background: var(--pxn-surface-2); border-radius: var(--pxn-radius-md); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3);
}
.pxrvd__totals strong { color: var(--pxn-ink); margin-left: var(--pxn-space-2); }
</style>
