<template>
  <div class="px-next pxds">
    <!--
      C3.19 — Detalle completo de existencias de un producto px-next
      (solo lectura). Ruta real /app/reports/detail_stock/:id
      (name detail_stock_report). Endpoints sin cambios:
      get_product_detail/{id} (ficha + stock por almacén/variante) y
      report/get_{sales,purchases,quotations,sales_return,purchase_return,
      transfer,adjustment}_by_product?id={id} (7 pestañas paginadas).
      Centro de análisis de inventario del producto — sin inventar un Kardex
      nuevo. Conserva toda la información disponible actualmente.
    -->
    <div v-if="isLoading" class="pxds__pad"><px-skeleton variant="card" :rows="8" /></div>

    <px-alert v-else-if="loadError" tone="danger" title="No se pudo cargar el detalle" class="pxds__alert">
      {{ loadError }}
      <template #actions><px-button size="sm" variant="secondary" @click="loadProduct()">Reintentar</px-button></template>
    </px-alert>

    <template v-else>
      <px-page-header
        :title="product.name || 'Detalle de existencias'"
        :breadcrumbs="[{ label: 'Informes' }, { label: 'Existencias' }, { label: product.name || $route.params.id }]"
      >
        <template #actions>
          <px-button variant="ghost" icon="arrow-left" type="button" @click="goBack">Volver</px-button>
        </template>
      </px-page-header>

      <div class="pxds__qty">
        <px-card v-if="!isVariant" title="Existencias por almacén" class="pxds__qtycard">
          <div class="pxds-mini__wrap pxn-scroll">
            <table class="pxds-mini">
              <thead><tr><th>Almacén</th><th class="is-right">Cantidad</th></tr></thead>
              <tbody>
                <tr v-if="!countQty.length"><td colspan="2" class="pxds-mini__empty">Sin existencias.</td></tr>
                <tr v-for="(r, i) in countQty" :key="'q-' + i">
                  <td>{{ r.mag }}</td>
                  <td class="is-right pxn-num">{{ fmtNum(r.qte) }} {{ product.unit }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </px-card>

        <px-card v-if="isVariant" title="Existencias por almacén y variante" class="pxds__qtycard">
          <div class="pxds-mini__wrap pxn-scroll">
            <table class="pxds-mini">
              <thead><tr><th>Almacén</th><th>Variante</th><th class="is-right">Cantidad</th></tr></thead>
              <tbody>
                <tr v-if="!countQtyVariants.length"><td colspan="3" class="pxds-mini__empty">Sin existencias.</td></tr>
                <tr v-for="(r, i) in countQtyVariants" :key="'qv-' + i">
                  <td>{{ r.mag }}</td>
                  <td>{{ r.variant }}</td>
                  <td class="is-right pxn-num">{{ fmtNum(r.qte) }} {{ product.unit }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </px-card>
      </div>

      <px-tabs :tabs="tabDefs" :value="activeTab" @input="activeTab = $event" class="pxds__tabs" />

      <div class="pxds__tabbody">
        <div class="pxds__tabhead">
          <px-toolbar
            :search="tab.search"
            :search-placeholder="'Buscar en ' + tab.label.toLowerCase() + '…'"
            :filter-count="null"
            @update:search="onTabSearch"
          />
          <px-button variant="secondary" size="sm" icon="printer" @click="printTab">Imprimir</px-button>
        </div>

        <div class="pxds__tablewrap" :class="{ 'is-busy': tab.loading }">
          <px-table
            v-if="tab.rows.length"
            :columns="tab.columns"
            :rows="tab.rows"
            row-key="rk"
          />
          <px-empty-state v-else :icon="tab.icon" :title="'Sin ' + tab.label.toLowerCase()"
            description="No hay registros de este tipo para el producto." />
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
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxTabs from "@/components/px-next/PxTabs.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import { printTableDoc } from "../reportUtils.js";

const COLS_TXN = [
  { key: "date", label: "Fecha", width: "130px" },
  { key: "Ref", label: "Referencia", strong: true },
  { key: "product_name", label: "Producto" },
  { key: "client_name", label: "Cliente" },
  { key: "warehouse_name", label: "Almacén" },
  { key: "quantity", label: "Cantidad", align: "right", numeric: true },
  { key: "total", label: "Subtotal", align: "right", numeric: true }
];
const COLS_TXN_SUPPLIER = COLS_TXN.map(c => (c.key === "client_name" ? { key: "provider_name", label: "Proveedor" } : c));

function tabConfig(k) {
  return {
    sales: { label: "Ventas", icon: "shopping-cart", endpoint: "report/get_sales_by_product", dataKey: "sales", columns: COLS_TXN },
    purchases: { label: "Compras", icon: "shopping-basket", endpoint: "report/get_purchases_by_product", dataKey: "purchases", columns: COLS_TXN_SUPPLIER },
    quotations: { label: "Cotizaciones", icon: "receipt-text", endpoint: "report/get_quotations_by_product", dataKey: "quotations", columns: COLS_TXN },
    sales_return: { label: "Devoluciones de venta", icon: "corner-up-right", endpoint: "report/get_sales_return_by_product", dataKey: "sales_return", columns: COLS_TXN },
    purchases_return: { label: "Devoluciones de compra", icon: "corner-up-left", endpoint: "report/get_purchase_return_by_product", dataKey: "purchases_return", columns: COLS_TXN_SUPPLIER },
    transfers: {
      label: "Traslados", icon: "arrow-left-right", endpoint: "report/get_transfer_by_product", dataKey: "transfers",
      columns: [
        { key: "date", label: "Fecha", width: "130px" }, { key: "Ref", label: "Referencia", strong: true },
        { key: "product_name", label: "Producto" }, { key: "from_warehouse", label: "Almacén origen" }, { key: "to_warehouse", label: "Almacén destino" }
      ]
    },
    adjustments: {
      label: "Ajustes", icon: "pencil", endpoint: "report/get_adjustment_by_product", dataKey: "adjustments",
      columns: [
        { key: "date", label: "Fecha", width: "130px" }, { key: "Ref", label: "Referencia", strong: true },
        { key: "product_name", label: "Producto" }, { key: "warehouse_name", label: "Almacén" }
      ]
    }
  }[k];
}

const TAB_KEYS = ["sales", "purchases", "quotations", "sales_return", "purchases_return", "transfers", "adjustments"];

export default {
  name: "DetailStockReportNext",
  metaInfo() {
    return { title: this.product && this.product.name ? this.product.name : "Detalle de existencias" };
  },
  components: { PxPageHeader, PxToolbar, PxTable, PxPagination, PxTabs, PxCard, PxButton, PxAlert, PxEmptyState },
  data() {
    const tabs = {};
    TAB_KEYS.forEach(k => {
      const cfg = tabConfig(k);
      tabs[k] = {
        key: k, label: cfg.label, icon: cfg.icon, endpoint: cfg.endpoint, dataKey: cfg.dataKey,
        columns: cfg.columns.map(c => ({ ...c })),
        raw: [], totalRows: 0, page: 1, limit: "10", search: "", _searchTimer: null, loading: false, loaded: false
      };
    });
    return {
      isLoading: true,
      loadError: null,
      product: {},
      activeTab: "sales",
      tabs
    };
  },
  computed: {
    ...mapGetters(["currentUser"]),
    isVariant() {
      return this.product && (this.product.is_variant === "yes" || this.product.type === "is_variant");
    },
    countQty() {
      return Array.isArray(this.product.CountQTY) ? this.product.CountQTY : [];
    },
    countQtyVariants() {
      return Array.isArray(this.product.CountQTY_variants) ? this.product.CountQTY_variants : [];
    },
    tabDefs() {
      return TAB_KEYS.map(k => ({ value: k, label: this.tabs[k].label, icon: this.tabs[k].icon }));
    },
    tab() {
      const t = this.tabs[this.activeTab];
      return { ...t, rows: (t.raw || []).map((r, i) => ({ ...r, rk: (r.Ref || r.id || "") + "-" + i })) };
    }
  },
  created() {
    this.loadProduct();
  },
  watch: {
    "$route.params.id"() {
      this.isLoading = true;
      TAB_KEYS.forEach(k => { this.tabs[k].loaded = false; this.tabs[k].page = 1; this.tabs[k].raw = []; });
      this.activeTab = "sales";
      this.loadProduct();
    },
    activeTab(k) {
      if (!this.tabs[k].loaded) this.loadTab(k, 1);
    }
  },
  methods: {
    fmtNum(v) {
      const n = Number(v || 0);
      return Number.isFinite(n) ? n.toLocaleString(undefined, { maximumFractionDigits: 2 }) : String(v == null ? "" : v);
    },
    goBack() {
      this.$router.push({ name: "stock_report" });
    },
    loadProduct() {
      this.loadError = null;
      const id = this.$route.params.id;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get("get_product_detail/" + id)
        .then(response => {
          this.product = response.data || {};
          NProgress.done();
          this.isLoading = false;
          this.loadTab(this.activeTab, 1);
        })
        .catch(err => {
          NProgress.done();
          this.loadError =
            (err && err.response && err.response.data && (err.response.data.message || err.response.data.error)) ||
            (err && err.message) || "Error de red.";
          setTimeout(() => { this.isLoading = false; }, 300);
        });
    },
    loadTab(key, page) {
      const t = this.tabs[key];
      const id = this.$route.params.id;
      t.loading = true;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get(t.endpoint + "?page=" + page + "&limit=" + t.limit +
          "&search=" + encodeURIComponent(t.search || "") + "&id=" + encodeURIComponent(id))
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
    },
    printTab() {
      const t = this.tabs[this.activeTab];
      const headers = t.columns.map(c => c.label);
      const rows = (t.raw || []).map(r => t.columns.map(c => (r[c.key] == null ? "" : r[c.key])));
      const ok = printTableDoc({
        title: "Informes / Detalle de existencias — " + (this.product.name || "") + " · " + t.label,
        headers, rows, landscape: true
      });
      if (!ok) this.$root.$bvToast.toast("Permite las ventanas emergentes para imprimir.", { title: "Aviso", variant: "warning", solid: true });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxds { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxds { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxds__pad { padding: var(--pxn-space-6) 0; }
.pxds__alert { margin-top: var(--pxn-space-5); }
.pxds__qty { margin-top: var(--pxn-space-5); }
.pxds__qtycard { max-width: 640px; }
.pxds-mini__wrap { overflow-x: auto; }
.pxds-mini { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxds-mini th { text-align: left; padding: var(--pxn-space-2) var(--pxn-space-4); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); }
.pxds-mini td { padding: var(--pxn-space-2) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); }
.pxds-mini tr:last-child td { border-bottom: 0; }
.pxds-mini .is-right { text-align: right; }
.pxds-mini__empty { text-align: center; color: var(--pxn-ink-3); }
.pxds__tabs { margin-top: var(--pxn-space-6); }
.pxds__tabbody { margin-top: var(--pxn-space-4); }
.pxds__tabhead { display: flex; align-items: center; gap: var(--pxn-space-4); flex-wrap: wrap; }
.pxds__tabhead > :first-child { flex: 1; }
.pxds__tablewrap { margin-top: var(--pxn-space-4); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxds__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
</style>
