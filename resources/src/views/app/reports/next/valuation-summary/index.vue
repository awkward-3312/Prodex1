<template>
  <div class="px-next pxrvs">
    <!--
      C3.16a — Valoración de inventario · RESUMEN px-next (solo lectura). Ruta
      real /app/reports/inventory_valuation_summary (name inventory_valuation_summary).
      Endpoint GET report/inventory_valuation_summary sin cambios. Vista agregada
      por producto: SKU, variantes, stock, coste y valor activo. Las celdas
      pueden traer varias líneas (una por variante) separadas por \n desde el
      backend; se muestran tal cual. Precisión monetaria backend conservada.
      Es la vista RESUMEN; el DETALLE es stock_inventory_valuation (endpoint,
      columnas y filtros distintos). No se fusionan.
    -->
    <div v-if="!can('inventory_valuation') && !can('Stock_Inventory_Valuation')" class="pxrvs__denied">
      <px-empty-state icon="lock" title="No tienes permiso para este reporte"
        description="Pide a un administrador el permiso «inventory_valuation»." />
    </div>

    <template v-else>
      <px-page-header title="Valoración de inventario · Resumen" :breadcrumbs="[{ label: 'Informes' }, { label: 'Valoración de inventario' }, { label: 'Resumen' }]">
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
        search-placeholder="Buscar por SKU, producto…"
        :filter-count="warehouse_id ? 1 : 0"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxrvs__filters">
        <px-field label="Almacén">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="warehouse_id" :reduce="o => o.value" placeholder="Todos los almacenes"
              :options="warehouseOptions" @input="applyFilters" />
          </template>
        </px-field>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el reporte" class="pxrvs__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxrvs__pad">
        <px-skeleton variant="table" :rows="10" :columns="6" />
      </div>

      <template v-else>
        <div class="pxrvs__tablewrap" :class="{ 'is-busy': refreshing }">
          <px-table
            v-if="rows.length"
            :columns="columns"
            :rows="rows"
            row-key="rk"
          >
            <template #cell-code="{ row }"><span class="pxn-mono">{{ row.code }}</span></template>
            <template #cell-variant_name="{ row }"><span class="pxrvs__multi">{{ row.variant_name || '—' }}</span></template>
            <template #cell-stock_hand="{ row }"><span class="pxrvs__multi pxn-num">{{ row.stock_hand }}</span></template>
            <template #cell-cost="{ row }"><span class="pxrvs__multi pxn-num">{{ fmtMoneyMulti(row.cost) }}</span></template>
            <template #cell-inventory_value="{ row }"><span class="pxrvs__multi pxn-num">{{ fmtMoneyMulti(row.inventory_value) }}</span></template>
          </px-table>

          <px-empty-state v-else icon="pie-chart" title="Sin datos de valoración"
            description="No hay productos que coincidan con los filtros." />
        </div>

        <div v-if="rows.length" class="pxrvs__totals">
          <span>Totales (página)</span>
          <span>Stock <strong class="pxn-num">{{ totalStock }}</strong></span>
          <span>Valor activo <strong class="pxn-num">{{ totalValue }}</strong></span>
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
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";
import { printTableDoc, exportPdf } from "../reportUtils.js";

export default {
  name: "InventoryValuationSummaryNext",
  metaInfo: { title: "Valoración de inventario · Resumen" },
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
      totalRows: 0,
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "id", type: "desc" },
      filtersOpen: false,
      warehouse_id: 0,
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
        { key: "name", label: "Artículo", sortable: false, strong: true },
        { key: "code", label: "SKU", sortable: false, width: "140px" },
        { key: "variant_name", label: "Variante", sortable: false },
        { key: "stock_hand", label: "Stock disponible", align: "right", sortable: false, width: "150px" },
        { key: "cost", label: "Coste", align: "right", sortable: false, width: "150px" },
        { key: "inventory_value", label: "Valor activo", align: "right", sortable: false, width: "160px" }
      ];
    },
    rows() {
      return (this.reports || []).map((r, i) => ({ ...r, rk: (r.code || "") + "-" + i }));
    },
    totalStock() {
      let t = 0;
      (this.reports || []).forEach(r => {
        String(r.stock_hand || "").split("\n").forEach(v => {
          const n = parseFloat(v);
          if (!isNaN(n)) t += n;
        });
      });
      return t.toFixed(2);
    },
    totalValue() {
      let t = 0;
      (this.reports || []).forEach(r => {
        String(r.inventory_value || "").split("\n").forEach(v => {
          const n = parseFloat(v);
          if (!isNaN(n)) t += n;
        });
      });
      return this.moneyPrefix(t.toFixed(this.priceDecimals));
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
    moneyPrefix(value) {
      return this.currency ? this.currency + " " + value : String(value);
    },
    // El backend puede entregar varias líneas separadas por \n (una por variante).
    fmtMoneyMulti(value) {
      if (value == null || value === "") return "";
      const str = String(value);
      const render = line => {
        const n = parseFloat(line);
        return isNaN(n) ? line : this.moneyPrefix(this.priceDisplay(n));
      };
      return str.includes("\n") ? str.split("\n").map(render).join("\n") : render(str);
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
        "report/inventory_valuation_summary?page=" + this.page +
        "&SortField=" + encodeURIComponent(this.sort.field) +
        "&SortType=" + encodeURIComponent(this.sort.type) +
        "&warehouse_id=" + encodeURIComponent(this.warehouse_id) +
        "&search=" + encodeURIComponent(this.search || "") +
        "&limit=" + encodeURIComponent(this.limit);
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
      return ["Artículo", "SKU", "Variante", "Stock disponible", "Coste", "Valor activo"];
    },
    exportRows() {
      const flat = s => String(s == null ? "" : s).replace(/\n/g, " / ");
      return (this.reports || []).map(r => [
        r.name, r.code, flat(r.variant_name), flat(r.stock_hand), flat(this.fmtMoneyMulti(r.cost)), flat(this.fmtMoneyMulti(r.inventory_value))
      ]);
    },
    onExport(item) {
      const k = item && item.key;
      const headers = this.exportHeaders();
      const rows = this.exportRows();
      const footer = ["Total", "", "", this.totalStock, "", this.totalValue];
      if (k === "pdf") {
        exportPdf({ title: "Valoración de inventario · Resumen", filename: "Valoracion_inventario_resumen", headers, rows, footer, landscape: true });
      } else if (k === "print") {
        const ok = printTableDoc({ title: "Informes / Valoración de inventario · Resumen", headers, rows, footer, landscape: true });
        if (!ok) this.$root.$bvToast.toast("Permite las ventanas emergentes para imprimir.", { title: "Aviso", variant: "warning", solid: true });
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrvs { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrvs { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrvs__denied { padding: var(--pxn-space-12) 0; }
.pxrvs__pad { padding: var(--pxn-space-6) 0; }
.pxrvs__alert { margin-top: var(--pxn-space-5); }
.pxrvs__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); max-width: 420px; }
.pxrvs__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxrvs__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
.pxrvs__multi { white-space: pre-line; }
.pxrvs__totals {
  display: flex; align-items: center; gap: var(--pxn-space-5); flex-wrap: wrap;
  margin-top: var(--pxn-space-4); padding: var(--pxn-space-3) var(--pxn-space-4);
  background: var(--pxn-surface-2); border-radius: var(--pxn-radius-md); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3);
}
.pxrvs__totals strong { color: var(--pxn-ink); margin-left: var(--pxn-space-2); }
</style>
