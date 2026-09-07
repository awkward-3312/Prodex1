<template>
  <div class="px-next pxvk">
    <!--
      Kardex valorizado px-next (solo lectura). Ruta real
      /app/reports/valued_kardex (name valued_kardex). Endpoint
      GET report/valued_kardex. Sin producto → selector; con producto →
      libro de movimientos con saldo de unidades y saldo valorizado corridos.
      Costo: promedio ponderado (WAC) reconstruido desde los costos
      documentales reales — ver la nota de calidad de datos que devuelve el
      backend. NUNCA revaloriza con products.cost actual.
    -->
    <div v-if="!can('valued_kardex_report')" class="pxvk__denied">
      <px-empty-state icon="lock" title="No tienes permiso para este reporte"
        description="Pide a un administrador el permiso «valued_kardex_report»." />
    </div>

    <template v-else>
      <px-page-header title="Kardex valorizado" :breadcrumbs="[{ label: 'Informes' }, { label: 'Kardex valorizado' }]">
        <template #actions>
          <px-button v-if="mode === 'ledger'" variant="secondary" size="sm" icon="printer" @click="doPrint">Imprimir</px-button>
        </template>
      </px-page-header>

      <div class="pxvk__filters">
        <div class="pxvk__filters-grid">
          <px-field label="Producto" hint="Elige un producto para ver su kardex">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="product_id" :reduce="o => o.value" placeholder="Buscar por código o nombre…"
                :options="productOptions" @search="onProductSearch" @input="onProductChange" />
            </template>
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
          <px-field label="Desde">
            <template #default="{ id }">
              <input :id="id" v-model="from" type="date" class="pxvk__date pxn-ring" @change="refresh" />
            </template>
          </px-field>
          <px-field label="Hasta">
            <template #default="{ id }">
              <input :id="id" v-model="to" type="date" class="pxvk__date pxn-ring" @change="refresh" />
            </template>
          </px-field>
        </div>
      </div>

      <px-alert v-if="error" tone="danger" title="No se pudo cargar el reporte" class="pxvk__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <div v-if="initialLoading" class="pxvk__pad">
        <px-skeleton variant="table" :rows="8" :columns="6" />
      </div>

      <template v-else>
        <px-empty-state
          v-if="mode !== 'ledger'"
          icon="history"
          title="Elige un producto"
          description="El kardex valorizado muestra cada entrada y salida de un producto con su saldo de unidades y su saldo valorizado." />

        <template v-else>
          <px-alert
            v-if="quantityQuality"
            :tone="(quantityQuality.reconciled && !quantityQuality.went_negative) ? 'info' : 'warning'"
            :title="quantityQuality.reconciled ? 'Existencia reconciliada' : 'La existencia NO reconcilia'"
            class="pxvk__alert"
          >
            {{ quantityQuality.message }}
            <template v-if="!quantityQuality.reconciled">
              <br>Saldo del libro: <b class="pxn-num">{{ fmtNum(reconciliation.ledger_closing_qty) }}</b> ·
              Existencia real: <b class="pxn-num">{{ fmtNum(reconciliation.stock_on_hand) }}</b> ·
              Diferencia: <b class="pxn-num">{{ fmtNum(reconciliation.difference) }}</b>.
            </template>
          </px-alert>

          <px-alert v-if="window && window.to_clamped" tone="info" title="Fecha ajustada" class="pxvk__alert">
            No se puede reconstruir stock futuro; la fecha "hasta" se ajustó a hoy ({{ window.to }}).
          </px-alert>

          <px-alert
            v-if="valuationQuality"
            tone="info"
            :title="valuationTitle"
            class="pxvk__alert"
          >
            {{ valuationQuality.message }}
          </px-alert>

          <div class="pxvk__stats">
            <px-stat label="Saldo inicial (unid.)" :value="fmtNum(summary.opening_qty)" icon="package" />
            <px-stat label="Entradas (unid.)" :value="fmtNum(summary.in_qty)" icon="plus" />
            <px-stat label="Salidas (unid.)" :value="fmtNum(summary.out_qty)" icon="minus" />
            <px-stat label="Saldo final (unid.)" :value="fmtNum(summary.closing_qty)" icon="check-circle" />
            <px-stat label="Saldo valorizado" :value="money(summary.closing_value)" icon="calculator" />
            <px-stat label="Costo prom. final" :value="money(summary.closing_avg_cost)" icon="tag" />
          </div>

          <div class="pxvk__tablewrap" :class="{ 'is-busy': refreshing }">
            <px-table v-if="rows.length" :columns="columns" :rows="rows" row-key="rk">
              <template #cell-date="{ row }">
                <span v-if="row.kind === 'opening' || row.kind === 'period_opening'" class="pxvk__opening">
                  {{ row.movement_type }}
                </span>
                <span v-else>{{ formatDisplayDate(row.date) }}</span>
              </template>
              <template #cell-movement_type="{ row }">
                <span v-if="row.kind === 'movement'">{{ row.movement_type }}</span>
                <span v-else class="pxn-ink-3">—</span>
              </template>
              <template #cell-reference="{ row }">{{ row.reference || '—' }}</template>
              <template #cell-branch_name="{ row }">{{ row.branch_name || '—' }}</template>
              <template #cell-location_name="{ row }">
                <span v-if="row.location_name">{{ row.location_name }}</span>
                <span v-else class="pxn-ink-3">—</span>
                <span v-if="row.location_basis === 'legacy'" class="pxvk__basis" title="Ubicación legacy: almacén (fallback)">almacén</span>
              </template>
              <template #cell-in_qty="{ row }">
                <span class="pxn-num">{{ row.in_qty != null ? fmtNum(row.in_qty) : '' }}</span>
              </template>
              <template #cell-out_qty="{ row }">
                <span class="pxn-num">{{ row.out_qty != null ? fmtNum(row.out_qty) : '' }}</span>
              </template>
              <template #cell-balance_qty="{ row }"><span class="pxn-num">{{ fmtNum(row.balance_qty) }}</span></template>
              <template #cell-unit_cost="{ row }">
                <span class="pxn-num">{{ money(row.unit_cost) }}</span>
                <span v-if="row.cost_basis === 'sin_historial'" class="pxvk__basis" title="Sin historial de costo: costo de referencia del producto">ref.</span>
              </template>
              <template #cell-movement_value="{ row }">
                <span class="pxn-num">{{ row.movement_value != null ? money(row.movement_value) : '' }}</span>
              </template>
              <template #cell-balance_value="{ row }"><span class="pxn-num">{{ money(row.balance_value) }}</span></template>
            </px-table>

            <px-empty-state v-else icon="history" title="Sin movimientos"
              description="Este producto no tiene movimientos de inventario en el alcance y período seleccionados." />
          </div>
        </template>
      </template>
    </template>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import Util from "../../../../../utils";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxStat from "@/components/px-next/PxStat.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";
import { printTableDoc } from "../reportUtils.js";

export default {
  name: "ValuedKardexReportNext",
  metaInfo: { title: "Kardex valorizado" },
  components: {
    PxPageHeader, PxButton, PxField, PxTable, PxStat, PxAlert, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      initialLoading: true,
      refreshing: false,
      error: null,
      mode: "picker",
      product_id: "",
      warehouse_id: "",      // sólo compat legacy / deep-link
      branch_id: "",
      inventory_location_id: "",
      from: "",
      to: "",
      products: [],
      branches: [],
      inventoryLocations: [],
      _searchTimer: null,
      productMeta: null,
      window: null,
      summary: {},
      reconciliation: null,
      quantityQuality: null,
      valuationQuality: null,
      report: []
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    productOptions() {
      return (this.products || []).map(p => ({
        label: (p.code ? p.code + " · " : "") + p.name,
        value: Number(p.id)
      }));
    },
    branchOptions() {
      return (this.branches || []).map(b => ({ label: b.name, value: Number(b.id) }));
    },
    locationOptions() {
      const b = (this.branch_id !== "" && this.branch_id != null) ? Number(this.branch_id) : null;
      return (this.inventoryLocations || [])
        .filter(l => b == null || Number(l.branch_id) === b)
        .map(l => ({ label: l.name, value: Number(l.id) }));
    },
    columns() {
      return [
        { key: "date", label: "Fecha", width: "140px" },
        { key: "movement_type", label: "Movimiento", width: "170px" },
        { key: "reference", label: "Referencia", width: "130px" },
        { key: "branch_name", label: "Sucursal" },
        { key: "location_name", label: "Ubicación", width: "160px" },
        { key: "in_qty", label: "Entrada", align: "right", numeric: true, width: "90px" },
        { key: "out_qty", label: "Salida", align: "right", numeric: true, width: "90px" },
        { key: "balance_qty", label: "Saldo unid.", align: "right", numeric: true, width: "100px" },
        { key: "unit_cost", label: "Costo unit.", align: "right", numeric: true, width: "110px" },
        { key: "movement_value", label: "Valor mov.", align: "right", numeric: true, width: "120px" },
        { key: "balance_value", label: "Saldo valorizado", align: "right", numeric: true, width: "140px" }
      ];
    },
    rows() {
      return (this.report || []).map((r, i) => ({ ...r, rk: (r.kind || "m") + "-" + (r.reference || "") + "-" + i }));
    },
    valuationTitle() {
      const b = this.valuationQuality && this.valuationQuality.basis;
      if (b === "exact") return "Valorización exacta";
      if (b === "partially_reconstructed") return "Valorización parcialmente reconstruida";
      return "Valorización aproximada";
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
    money(v) {
      const sym = (this.currentUser && this.currentUser.currency) || "";
      const n = Number(v);
      const num = Number.isFinite(n) ? n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : "0.00";
      return sym ? sym + " " + num : num;
    },
    formatDisplayDate(value) {
      if (!value) return "—";
      const fmt = this.$store.getters.getDateFormat || Util.getDateFormat(this.$store);
      return Util.formatDisplayDate(value, fmt);
    },
    async init() {
      await this.fetch(true);
    },
    onProductSearch(q) {
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => this.fetchPicker(q), 300);
    },
    onProductChange() {
      this.fetch();
    },
    onBranchChange() {
      const ok = this.locationOptions.some(o => o.value === Number(this.inventory_location_id));
      if (!ok) this.inventory_location_id = "";
      this.fetch();
    },
    refresh() {
      this.fetch();
    },
    fetchPicker(search) {
      // NUNCA enviar product_id aquí: forzaría el modo 'ledger' (sin lista de
      // productos) y el typeahead dejaría de traer resultados una vez elegido
      // un producto. Sólo búsqueda + alcance.
      const p = {
        search: search || "",
        branch_id: this.branch_id != null ? this.branch_id : "",
        inventory_location_id: this.inventory_location_id != null ? this.inventory_location_id : ""
      };
      const qs = Object.keys(p)
        .filter(k => p[k] !== "" && p[k] != null)
        .map(k => k + "=" + encodeURIComponent(p[k]))
        .join("&");
      window.axios
        .get("report/valued_kardex?" + qs)
        .then(({ data }) => { this.products = data.products || this.products; })
        .catch(() => { /* mantiene lista previa */ });
    },
    qs(extra) {
      const p = Object.assign(
        {
          product_id: this.product_id || "",
          warehouse_id: this.warehouse_id != null ? this.warehouse_id : "",
          branch_id: this.branch_id != null ? this.branch_id : "",
          inventory_location_id: this.inventory_location_id != null ? this.inventory_location_id : "",
          from: this.from || "",
          to: this.to || ""
        },
        extra || {}
      );
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
        .get("report/valued_kardex?" + this.qs())
        .then(({ data }) => {
          this.mode = data.mode || "picker";
          this.branches = data.branches || [];
          this.inventoryLocations = data.inventory_locations || [];
          if (data.mode === "picker") {
            this.products = data.products || [];
          } else {
            this.productMeta = data.product || null;
            this.window = data.window || null;
            this.summary = data.summary || {};
            this.reconciliation = data.reconciliation || null;
            this.quantityQuality = data.quantity_quality || null;
            this.valuationQuality = data.valuation_quality || null;
            this.report = Array.isArray(data.rows) ? data.rows : [];
            // asegura que el producto elegido aparezca en el selector
            if (this.productMeta && !(this.products || []).some(p => Number(p.id) === Number(this.productMeta.id))) {
              this.products = [{ id: this.productMeta.id, code: this.productMeta.code, name: this.productMeta.name }].concat(this.products || []);
            }
          }
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
          if (c.key === "date") return r.kind === "movement" ? this.formatDisplayDate(r.date) : r.movement_type;
          if (c.key === "movement_type") return r.kind === "movement" ? r.movement_type : "";
          if (c.key === "reference") return r.reference || "";
          if (c.key === "branch_name") return r.branch_name || "";
          if (c.key === "location_name") return (r.location_name || "") + (r.location_basis === "legacy" ? " (almacén)" : "");
          if (c.key === "in_qty") return r.in_qty != null ? this.fmtNum(r.in_qty) : "";
          if (c.key === "out_qty") return r.out_qty != null ? this.fmtNum(r.out_qty) : "";
          if (c.key === "balance_qty") return this.fmtNum(r.balance_qty);
          if (c.key === "unit_cost") return this.money(r.unit_cost);
          if (c.key === "movement_value") return r.movement_value != null ? this.money(r.movement_value) : "";
          if (c.key === "balance_value") return this.money(r.balance_value);
          return "";
        })
      );
      const title = "Informes / Kardex valorizado" + (this.productMeta ? " — " + this.productMeta.name : "");
      const ok = printTableDoc({ title, headers, rows, landscape: true });
      if (!ok && this.$root.$bvToast) {
        this.$root.$bvToast.toast("Permite las ventanas emergentes para imprimir.", { title: "Aviso", variant: "warning", solid: true });
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxvk { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxvk { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxvk__denied { padding: var(--pxn-space-12) 0; }
.pxvk__pad { padding: var(--pxn-space-6) 0; }
.pxvk__alert { margin-top: var(--pxn-space-5); }
.pxvk__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxvk__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxvk__filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 620px) { .pxvk__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxvk__date {
  width: 100%; height: var(--pxn-control-h-sm); padding: 0 var(--pxn-space-4);
  border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); color: var(--pxn-ink); font: inherit; font-size: var(--pxn-fs-sm);
}
.pxvk__stats {
  display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: var(--pxn-space-4);
  margin-top: var(--pxn-space-5);
}
@media (max-width: 1100px) { .pxvk__stats { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 620px) { .pxvk__stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.pxvk__tablewrap { margin-top: var(--pxn-space-5); overflow-x: auto; transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxvk__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
.pxvk__opening { font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink-2); }
.pxvk__basis {
  margin-left: var(--pxn-space-2); padding: 0 4px; border-radius: var(--pxn-radius-sm);
  font-size: 10px; color: var(--pxn-ink-3); background: var(--pxn-surface-3);
}
</style>
