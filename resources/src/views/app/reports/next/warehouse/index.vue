<template>
  <div class="px-next pxwr">
    <!--
      C3.18 — Dashboard / Reporte por almacén px-next. Ruta real
      /app/reports/warehouse_report (name warehouse_report). Endpoints sin
      cambios: report/warehouse_report (KPIs), report/warhouse_count_stock
      (resúmenes por almacén), y report/{sales,purchases,quotations,
      returns_sale,returns_purchase,expenses}_warehouse (6 pestañas paginadas).
      Los 2 gráficos de dona del legacy se sustituyen por dos tablas resumen
      px-next equivalentes — no se pierde información. No cambia cálculos backend.
      Permiso Warehouse_report.
    -->
    <div v-if="!can('Warehouse_report')" class="pxwr__denied">
      <px-empty-state icon="lock" title="No tienes permiso para este reporte"
        description="Pide a un administrador el permiso «Warehouse_report»." />
    </div>

    <template v-else>
      <px-page-header title="Reporte por almacén" :breadcrumbs="[{ label: 'Informes' }, { label: 'Reporte por almacén' }]" />

      <div class="pxwr__filter">
        <px-field label="Almacén">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="warehouse_id" :reduce="o => o.value" placeholder="Todos los almacenes"
              :options="warehouses.map(w => ({ label: w.name, value: w.id }))" @input="onWarehouseChange" />
          </template>
        </px-field>
      </div>

      <div v-if="initialLoading" class="pxwr__pad">
        <px-skeleton variant="card" :rows="4" />
        <px-skeleton variant="table" :rows="8" :columns="6" />
      </div>

      <template v-else>
        <div class="pxwr__kpis">
          <px-stat label="Ventas" :value="money(total.sales)" icon="shopping-cart" bordered />
          <px-stat label="Compras" :value="money(total.purchases)" icon="shopping-basket" bordered />
          <px-stat label="Devoluciones de compra" :value="money(total.ReturnPurchase)" icon="corner-up-left" bordered />
          <px-stat label="Devoluciones de venta" :value="money(total.ReturnSale)" icon="corner-up-right" bordered />
        </div>

        <div class="pxwr__summaries">
          <px-card title="Existencias por almacén — recuento" class="pxwr__summary">
            <div class="pxwr-mini__wrap pxn-scroll">
              <table class="pxwr-mini">
                <thead><tr><th>Almacén</th><th class="is-right">Artículos</th><th class="is-right">Cantidad</th></tr></thead>
                <tbody>
                  <tr v-if="!countSummary.length"><td colspan="3" class="pxwr-mini__empty">Sin datos.</td></tr>
                  <tr v-for="(r, i) in countSummary" :key="'c-' + i">
                    <td>{{ r.name }}</td>
                    <td class="is-right pxn-num">{{ fmtNum(r.items) }}</td>
                    <td class="is-right pxn-num">{{ fmtNum(r.qty) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </px-card>

          <px-card title="Existencias por almacén — valor" class="pxwr__summary">
            <div class="pxwr-mini__wrap pxn-scroll">
              <table class="pxwr-mini">
                <thead><tr><th>Almacén</th><th class="is-right">Valor a precio</th><th class="is-right">Valor a coste</th></tr></thead>
                <tbody>
                  <tr v-if="!valueSummary.length"><td colspan="3" class="pxwr-mini__empty">Sin datos.</td></tr>
                  <tr v-for="(r, i) in valueSummary" :key="'v-' + i">
                    <td>{{ r.name }}</td>
                    <td class="is-right pxn-num">{{ money(r.price) }}</td>
                    <td class="is-right pxn-num">{{ money(r.cost) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </px-card>
        </div>

        <px-tabs :tabs="tabDefs" :value="activeTab" @input="activeTab = $event" class="pxwr__tabs" />

        <div class="pxwr__tabbody">
          <px-toolbar
            :search="tab.search"
            :search-placeholder="'Buscar en ' + tab.label.toLowerCase() + '…'"
            :filter-count="null"
            @update:search="onTabSearch"
          />

          <div class="pxwr__tablewrap" :class="{ 'is-busy': tab.loading }">
            <px-table
              v-if="tab.rows.length"
              :columns="tab.columns"
              :rows="tab.rows"
              row-key="rk"
            />

            <px-empty-state v-else :icon="tab.icon" :title="'Sin ' + tab.label.toLowerCase()"
              description="No hay registros para este almacén y búsqueda." />
          </div>

          <px-pagination
            v-if="tab.rows.length"
            :page="tab.page"
            :per-page="Number(tab.limit)"
            :total="Number(tab.totalRows) || 0"
            :per-page-options="['10', '25', '50', '100']"
            @update:page="onTabPage"
            @update:perPage="onTabLimit"
          />
        </div>
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
import PxTabs from "@/components/px-next/PxTabs.vue";
import PxStat from "@/components/px-next/PxStat.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

// Config de las 6 pestañas: endpoint, clave de datos en la respuesta, columnas.
function tabConfig(t) {
  const M = "money"; // marca de columna monetaria
  return {
    quotations: {
      label: "Cotizaciones", icon: "receipt-text", endpoint: "report/quotations_warehouse", dataKey: "quotations",
      columns: [
        { key: "date", label: "Fecha", width: "130px" }, { key: "Ref", label: "Referencia", strong: true },
        { key: "client_name", label: "Cliente" }, { key: "warehouse_name", label: "Almacén" },
        { key: "GrandTotal", label: "Total", align: "right", numeric: true, [M]: true }, { key: "statut", label: "Estado" }
      ]
    },
    sales: {
      label: "Ventas", icon: "shopping-cart", endpoint: "report/sales_warehouse", dataKey: "sales",
      columns: [
        { key: "Ref", label: "Referencia", strong: true }, { key: "client_name", label: "Cliente" },
        { key: "warehouse_name", label: "Almacén" },
        { key: "GrandTotal", label: "Total", align: "right", numeric: true, [M]: true },
        { key: "paid_amount", label: "Pagado", align: "right", numeric: true, [M]: true },
        { key: "due", label: "Adeudado", align: "right", numeric: true, [M]: true },
        { key: "statut", label: "Estado" }, { key: "payment_status", label: "Pago" }, { key: "shipping_status", label: "Envío" }
      ]
    },
    purchases: {
      label: "Compras", icon: "shopping-basket", endpoint: "report/purchases_warehouse", dataKey: "purchases",
      columns: [
        { key: "date", label: "Fecha", width: "130px" }, { key: "Ref", label: "Referencia", strong: true },
        { key: "provider_name", label: "Proveedor" }, { key: "warehouse_name", label: "Almacén" },
        { key: "GrandTotal", label: "Total", align: "right", numeric: true, [M]: true },
        { key: "paid_amount", label: "Pagado", align: "right", numeric: true, [M]: true },
        { key: "due", label: "Adeudado", align: "right", numeric: true, [M]: true },
        { key: "statut", label: "Estado" }, { key: "payment_status", label: "Pago" }
      ]
    },
    returns_sale: {
      label: "Devoluciones de venta", icon: "corner-up-right", endpoint: "report/returns_sale_warehouse", dataKey: "returns_sale",
      columns: [
        { key: "Ref", label: "Referencia", strong: true }, { key: "client_name", label: "Cliente" },
        { key: "sale_ref", label: "Ref. venta" }, { key: "warehouse_name", label: "Almacén" },
        { key: "GrandTotal", label: "Total", align: "right", numeric: true, [M]: true },
        { key: "paid_amount", label: "Pagado", align: "right", numeric: true, [M]: true },
        { key: "due", label: "Adeudado", align: "right", numeric: true, [M]: true },
        { key: "statut", label: "Estado" }, { key: "payment_status", label: "Pago" }
      ]
    },
    returns_purchase: {
      label: "Devoluciones de compra", icon: "corner-up-left", endpoint: "report/returns_purchase_warehouse", dataKey: "returns_purchase",
      columns: [
        { key: "Ref", label: "Referencia", strong: true }, { key: "provider_name", label: "Proveedor" },
        { key: "warehouse_name", label: "Almacén" }, { key: "purchase_ref", label: "Ref. compra" },
        { key: "GrandTotal", label: "Total", align: "right", numeric: true, [M]: true },
        { key: "paid_amount", label: "Pagado", align: "right", numeric: true, [M]: true },
        { key: "due", label: "Adeudado", align: "right", numeric: true, [M]: true },
        { key: "statut", label: "Estado" }, { key: "payment_status", label: "Pago" }
      ]
    },
    expenses: {
      label: "Gastos", icon: "banknote", endpoint: "report/expenses_warehouse", dataKey: "expenses",
      columns: [
        { key: "date", label: "Fecha", width: "130px" }, { key: "Ref", label: "Referencia", strong: true },
        { key: "warehouse_name", label: "Almacén" }, { key: "details", label: "Detalles" },
        { key: "amount", label: "Importe", align: "right", numeric: true, [M]: true }, { key: "category_name", label: "Categoría" }
      ]
    }
  }[t];
}

const TAB_KEYS = ["quotations", "sales", "purchases", "returns_sale", "returns_purchase", "expenses"];

export default {
  name: "WarehouseReportNext",
  metaInfo: { title: "Reporte por almacén" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxTabs, PxStat, PxCard,
    PxField, PxEmptyState, "vs-px": VsPx
  },
  data() {
    const tabs = {};
    TAB_KEYS.forEach(k => {
      const cfg = tabConfig(k);
      tabs[k] = {
        key: k, label: cfg.label, icon: cfg.icon, endpoint: cfg.endpoint, dataKey: cfg.dataKey,
        columns: cfg.columns.map(c => ({ ...c })),
        moneyCols: cfg.columns.filter(c => c.money).map(c => c.key),
        raw: [], totalRows: 0, page: 1, limit: "10", search: "", _searchTimer: null, loading: false
      };
    });
    return {
      initialLoading: true,
      warehouse_id: "",
      warehouses: [],
      total: { sales: "", purchases: "", ReturnPurchase: "", ReturnSale: "" },
      countSummary: [],
      valueSummary: [],
      activeTab: "quotations",
      tabs,
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
    tabDefs() {
      return TAB_KEYS.map(k => ({ value: k, label: this.tabs[k].label, icon: this.tabs[k].icon }));
    },
    tab() {
      const t = this.tabs[this.activeTab];
      const mcols = t.moneyCols || [];
      return {
        ...t,
        rows: (t.raw || []).map((r, i) => {
          const row = { ...r, rk: (r.Ref || r.id || "") + "-" + i };
          // Formatea las columnas monetarias para render tabular (mismo helper que el legacy).
          mcols.forEach(k => { row[k] = this.money(r[k]); });
          return row;
        })
      };
    }
  },
  created() {
    this.bootstrap();
  },
  watch: {
    activeTab(k) {
      if (!this.tabs[k].loaded) this.loadTab(k, 1);
    }
  },
  methods: {
    can(p) {
      const list = Array.isArray(this.currentUserPermissions) ? this.currentUserPermissions : [];
      return list.includes(p);
    },
    money(v) {
      const n = Number(v || 0);
      const safe = Number.isFinite(n) ? n : 0;
      let out;
      try {
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) this.price_format_key = key;
        out = formatPriceDisplayHelper(safe, this.priceDecimals, key || null);
      } catch (e) {
        out = safe.toLocaleString(undefined, { minimumFractionDigits: this.priceDecimals, maximumFractionDigits: this.priceDecimals });
      }
      return this.currency ? this.currency + " " + out : out;
    },
    fmtNum(v) {
      const n = Number(v || 0);
      return Number.isFinite(n) ? n.toLocaleString(undefined, { maximumFractionDigits: 2 }) : String(v == null ? "" : v);
    },
    onWarehouseChange() {
      this.loadKpis();
      TAB_KEYS.forEach(k => { this.tabs[k].loaded = false; this.tabs[k].page = 1; });
      this.loadTab(this.activeTab, 1);
    },
    bootstrap() {
      this.initialLoading = true;
      Promise.all([this.loadKpis(), this.loadCountStock(), this.loadTab("quotations", 1)])
        .then(() => { this.initialLoading = false; })
        .catch(() => { setTimeout(() => { this.initialLoading = false; }, 400); });
    },
    loadKpis() {
      return window.axios
        .get("report/warehouse_report?warehouse_id=" + encodeURIComponent(this.warehouse_id || ""))
        .then(response => {
          this.total = response.data.data || this.total;
          this.warehouses = response.data.warehouses || this.warehouses;
        })
        .catch(() => {});
    },
    loadCountStock() {
      return window.axios
        .get("report/warhouse_count_stock")
        .then(response => {
          const d = response.data || {};
          const cLabels = Array.isArray(d.count_labels) && d.count_labels.length
            ? d.count_labels : (Array.isArray(d.stock_count) ? d.stock_count.map(x => x.name) : []);
          const cItems = Array.isArray(d.count_items) && d.count_items.length
            ? d.count_items : (Array.isArray(d.stock_count) ? d.stock_count.map(x => Number((x && x.value) || 0)) : []);
          const cQty = Array.isArray(d.count_qty) && d.count_qty.length
            ? d.count_qty : (Array.isArray(d.stock_count) ? d.stock_count.map(x => Number((x && x.value1) || 0)) : []);
          const vLabels = Array.isArray(d.value_labels) && d.value_labels.length
            ? d.value_labels : (Array.isArray(d.stock_value) ? d.stock_value.map(x => x.name) : []);
          const vPrice = Array.isArray(d.value_price) && d.value_price.length
            ? d.value_price : (Array.isArray(d.stock_value) ? d.stock_value.map(x => Number((x && (x.value != null ? x.value : x.price)) || 0)) : []);
          const vCost = Array.isArray(d.value_cost) && d.value_cost.length
            ? d.value_cost : (Array.isArray(d.stock_value) ? d.stock_value.map(x => Number((x && (x.value1 != null ? x.value1 : x.cost)) || 0)) : []);
          this.countSummary = cLabels.map((name, i) => ({ name, items: cItems[i] || 0, qty: cQty[i] || 0 }));
          this.valueSummary = vLabels.map((name, i) => ({ name, price: vPrice[i] || 0, cost: vCost[i] || 0 }));
        })
        .catch(() => {});
    },
    loadTab(key, page) {
      const t = this.tabs[key];
      t.loading = true;
      NProgress.start(); NProgress.set(0.1);
      return window.axios
        .get(t.endpoint + "?page=" + page + "&limit=" + t.limit +
          "&warehouse_id=" + encodeURIComponent(this.warehouse_id || "") +
          "&search=" + encodeURIComponent(t.search || ""))
        .then(response => {
          t.raw = response.data[t.dataKey] || [];
          t.totalRows = response.data.totalRows || 0;
          t.page = page;
          t.loaded = true;
          t.loading = false;
          NProgress.done();
        })
        .catch(() => {
          t.loading = false;
          NProgress.done();
        });
    },
    onTabSearch(v) {
      const t = this.tabs[this.activeTab];
      t.search = v;
      if (t._searchTimer) clearTimeout(t._searchTimer);
      t._searchTimer = setTimeout(() => this.loadTab(this.activeTab, 1), 350);
    },
    onTabPage(p) {
      if (p !== this.tabs[this.activeTab].page) this.loadTab(this.activeTab, p);
    },
    onTabLimit(v) {
      this.tabs[this.activeTab].limit = String(v);
      this.loadTab(this.activeTab, 1);
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxwr { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxwr { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxwr__denied { padding: var(--pxn-space-12) 0; }
.pxwr__pad { padding: var(--pxn-space-6) 0; }
.pxwr__filter { margin-top: var(--pxn-space-4); max-width: 380px; }
.pxwr__kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-4); margin-top: var(--pxn-space-5); }
@media (max-width: 720px) { .pxwr__kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.pxwr__summaries { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); margin-top: var(--pxn-space-5); }
@media (max-width: 900px) { .pxwr__summaries { grid-template-columns: minmax(0, 1fr); } }
.pxwr-mini__wrap { overflow-x: auto; }
.pxwr-mini { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxwr-mini th { text-align: left; padding: var(--pxn-space-2) var(--pxn-space-4); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); }
.pxwr-mini td { padding: var(--pxn-space-2) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); }
.pxwr-mini tr:last-child td { border-bottom: 0; }
.pxwr-mini .is-right { text-align: right; }
.pxwr-mini__empty { text-align: center; color: var(--pxn-ink-3); }
.pxwr__tabs { margin-top: var(--pxn-space-6); }
.pxwr__tabbody { margin-top: var(--pxn-space-4); }
.pxwr__tablewrap { margin-top: var(--pxn-space-4); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxwr__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
</style>
