<template>
  <div class="px-next pxrbh">
    <!--
      C3.17b — Historial / movimientos de un lote px-next (solo lectura). Ruta
      real /app/reports/batch_history_report/:id (name batch_history_report).
      Endpoint GET report/batches/{id}/history sin cambios. Muestra ficha del
      lote, movimientos filtrables por tipo, y la conciliación (in/out/computed/
      actual/drift). Los enlaces a documentos origen usan las rutas existentes.
      Estado español-first; precisión monetaria backend conservada.
    -->
    <div v-if="!can('Batch_Register_Report')" class="pxrbh__denied">
      <px-empty-state icon="lock" title="No tienes permiso para este reporte"
        description="Pide a un administrador el permiso «Batch_Register_Report»." />
    </div>

    <template v-else>
      <div v-if="isLoading" class="pxrbh__pad"><px-skeleton variant="card" :rows="8" /></div>

      <px-alert v-else-if="loadError" tone="danger" title="No se pudo cargar el historial" class="pxrbh__alert">
        {{ loadError }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <template v-else>
        <px-page-header
          :title="'Historial de lote · ' + (batch.batch_no || '—')"
          :breadcrumbs="[{ label: 'Informes' }, { label: 'Registro de lotes' }, { label: batch.batch_no || $route.params.id }]"
        >
          <template #actions>
            <px-button variant="ghost" icon="arrow-left" type="button" @click="goBack">Volver</px-button>
            <px-button variant="secondary" icon="printer" type="button" @click="doPrint">Imprimir</px-button>
          </template>
        </px-page-header>

        <px-card class="pxrbh__hero">
          <dl class="pxrbh__dl">
            <div><dt>Producto</dt><dd>{{ batch.product_name }}<span v-if="batch.product_code" class="pxrbh__muted"> [{{ batch.product_code }}]</span></dd></div>
            <div v-if="batch.generic_name"><dt>Genérico</dt><dd>{{ batch.generic_name }}<span v-if="batch.strength"> · {{ batch.strength }}</span><span v-if="batch.dosage_form"> · {{ batch.dosage_form }}</span></dd></div>
            <div v-if="batch.variant_name"><dt>Variante</dt><dd>{{ batch.variant_name }}</dd></div>
            <div><dt>Almacén</dt><dd>{{ batch.warehouse_name || '—' }}</dd></div>
            <div><dt>Estado</dt><dd><px-badge :tone="statusTone(batch.status)">{{ statusLabel(batch.status) }}</px-badge></dd></div>
            <div><dt>Fabricación</dt><dd>{{ batch.mfg_date || '—' }}</dd></div>
            <div><dt>Caducidad</dt><dd>
              <span v-if="batch.expiry_date" class="pxrbh-pill" :class="'is-' + expiryTone(batch.expiry_bucket)">{{ batch.expiry_date }}</span>
              <span v-else class="pxn-muted">—</span>
            </dd></div>
            <div><dt>Cantidad actual</dt><dd class="pxn-num pxrbh__strong">{{ fmtNum(batch.qty) }}</dd></div>
          </dl>
          <p v-if="batch.notes" class="pxrbh__notes">{{ batch.notes }}</p>
        </px-card>

        <px-card class="pxrbh__log">
          <div class="pxrbh__log-head">
            <h3 class="pxrbh__log-title">Movimientos <span class="pxrbh__muted">({{ filteredTransactions.length }})</span></h3>
            <px-select :value="typeFilter" :options="typeOptions" @input="v => typeFilter = v" />
          </div>

          <div v-if="!filteredTransactions.length" class="pxrbh__empty">Sin movimientos registrados para este lote.</div>

          <div v-else class="pxrbh-tbl__wrap pxn-scroll">
            <table class="pxrbh-tbl">
              <thead>
                <tr>
                  <th>Tipo</th><th>Fecha</th><th>Referencia</th><th>Parte</th>
                  <th class="is-right">Entrada</th><th class="is-right">Salida</th>
                  <th class="is-right">Valor unit.</th><th class="is-right">Saldo</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(t, idx) in filteredTransactions" :key="idx">
                  <td><px-badge :tone="typeTone(t.type)">{{ typeLabel(t.type) }}</px-badge></td>
                  <td>{{ t.date || '—' }}</td>
                  <td>
                    <router-link v-if="sourceLink(t)" class="pxrbh-link" :to="sourceLink(t)">{{ t.ref }}</router-link>
                    <span v-else>{{ t.ref }}</span>
                  </td>
                  <td>
                    <span class="pxrbh__party">{{ t.party_name || '—' }}</span>
                    <small v-if="t.party_label" class="pxrbh__muted">{{ t.party_label }}</small>
                  </td>
                  <td class="is-right">
                    <span v-if="t.qty_in" class="pxrbh__pos pxn-num">+{{ fmtNum(t.qty_in) }}</span>
                    <span v-else-if="t.type === 'quotation' && t.reserved_qty" class="pxn-muted">({{ fmtNum(t.reserved_qty) }})</span>
                    <span v-else class="pxn-muted">—</span>
                  </td>
                  <td class="is-right">
                    <span v-if="t.qty_out" class="pxrbh__neg pxn-num">-{{ fmtNum(t.qty_out) }}</span>
                    <span v-else class="pxn-muted">—</span>
                  </td>
                  <td class="is-right">
                    <span v-if="t.unit_value !== null && t.unit_value !== undefined" class="pxn-num">{{ money(t.unit_value) }}</span>
                    <span v-else class="pxn-muted">—</span>
                  </td>
                  <td class="is-right pxn-num pxrbh__strong">{{ fmtNum(t.running_balance) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="pxrbh__recon">
            <div class="pxrbh__recon-title"><lucide-icon name="receipt-text" :size="14" /> Conciliación</div>
            <div class="pxrbh__recon-grid">
              <div><span>Total entradas</span><strong class="pxrbh__pos pxn-num">+ {{ fmtNum(totals.in) }}</strong></div>
              <div><span>Total salidas</span><strong class="pxrbh__neg pxn-num">- {{ fmtNum(totals.out) }}</strong></div>
              <div><span>Cantidad calculada</span><strong class="pxn-num">{{ fmtNum(totals.computed_qty) }}</strong></div>
              <div><span>Cantidad real</span><strong class="pxn-num">{{ fmtNum(totals.actual_qty) }}</strong></div>
            </div>
            <px-alert :tone="hasDrift ? 'danger' : 'success'" class="pxrbh__recon-msg">
              <template v-if="hasDrift">Descuadre detectado en el libro: {{ fmtNum(totals.drift) }}</template>
              <template v-else>Libro cuadrado — la cantidad real coincide con la suma de movimientos.</template>
            </px-alert>
          </div>
        </px-card>
      </template>
    </template>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import { getPriceDecimals } from "@/utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxSelect from "@/components/px-next/PxSelect.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import { printTableDoc } from "../reportUtils.js";
import { batchStatusLabel, batchStatusTone, expiryBucketTone } from "../batchStatus.js";

const TYPE_LABELS = {
  purchase: "Compra",
  sale: "Venta",
  sale_return: "Devolución de venta",
  purchase_return: "Devolución de compra",
  adjustment: "Ajuste",
  transfer_in: "Traslado entrada",
  transfer_out: "Traslado salida",
  damage: "Daño",
  quotation: "Cotización"
};

export default {
  name: "BatchHistoryReportNext",
  metaInfo: { title: "Historial de lote" },
  components: { PxPageHeader, PxCard, PxButton, PxSelect, PxBadge, PxAlert, PxEmptyState },
  data() {
    return {
      isLoading: true,
      loadError: null,
      batch: {},
      transactions: [],
      totals: { in: 0, out: 0, computed_qty: 0, actual_qty: 0, drift: 0 },
      typeFilter: "all"
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
    typeOptions() {
      return [
        { value: "all", label: "Todos" },
        { value: "in", label: "↑ Entradas" },
        { value: "out", label: "↓ Salidas" },
        { value: "purchase", label: "Compra" },
        { value: "sale", label: "Venta" },
        { value: "sale_return", label: "Devolución de venta" },
        { value: "purchase_return", label: "Devolución de compra" },
        { value: "adjustment", label: "Ajuste" },
        { value: "transfer_in", label: "Traslado entrada" },
        { value: "transfer_out", label: "Traslado salida" },
        { value: "damage", label: "Daño" },
        { value: "quotation", label: "Cotización" }
      ];
    },
    filteredTransactions() {
      if (this.typeFilter === "all") return this.transactions;
      if (this.typeFilter === "in") return this.transactions.filter(t => t.direction === "in");
      if (this.typeFilter === "out") return this.transactions.filter(t => t.direction === "out");
      return this.transactions.filter(t => t.type === this.typeFilter);
    },
    hasDrift() {
      return Math.abs(Number(this.totals.drift) || 0) > 0.0001;
    }
  },
  created() {
    this.fetch();
  },
  watch: {
    "$route.params.id"() {
      this.isLoading = true;
      this.fetch();
    }
  },
  methods: {
    can(p) {
      const list = Array.isArray(this.currentUserPermissions) ? this.currentUserPermissions : [];
      return list.includes(p);
    },
    statusLabel: batchStatusLabel,
    statusTone: batchStatusTone,
    expiryTone: expiryBucketTone,
    typeLabel(t) {
      return TYPE_LABELS[t] || t;
    },
    typeTone(t) {
      switch (t) {
        case "purchase": return "success";
        case "sale": return "danger";
        case "sale_return": return "info";
        case "purchase_return": return "warning";
        case "adjustment": return "info";
        case "transfer_in": return "info";
        case "transfer_out": return "neutral";
        case "damage": return "danger";
        default: return "neutral";
      }
    },
    fmtNum(v) {
      if (v === null || v === undefined || v === "") return "0";
      const n = Number(v);
      if (Number.isNaN(n)) return "0";
      return Number.isInteger(n) ? n.toString() : n.toFixed(2);
    },
    money(v) {
      const n = Number(v);
      if (Number.isNaN(n)) return String(v);
      const s = n.toFixed(this.priceDecimals);
      return this.currency ? this.currency + " " + s : s;
    },
    sourceLink(t) {
      if (!t || !t.ref_id) return null;
      switch (t.type) {
        case "purchase": return { name: "detail_purchase", params: { id: t.ref_id } };
        case "sale": return { name: "detail_sale", params: { id: t.ref_id } };
        case "sale_return": return { name: "detail_sale_return", params: { id: t.ref_id } };
        case "purchase_return": return { name: "detail_purchase_return", params: { id: t.ref_id } };
        case "adjustment": return { name: "detail_adjustment", params: { id: t.ref_id } };
        case "transfer_in":
        case "transfer_out": return { name: "detail_transfer", params: { id: t.ref_id } };
        case "damage": return { name: "edit_damage", params: { id: t.ref_id } };
        case "quotation": return { name: "detail_quotation", params: { id: t.ref_id } };
        default: return null;
      }
    },
    goBack() {
      this.$router.push({ name: "batch_register_report" });
    },
    fetch() {
      const id = this.$route.params.id;
      if (!id) return;
      this.loadError = null;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get("report/batches/" + id + "/history")
        .then(response => {
          const data = response.data || {};
          this.batch = data.batch || {};
          this.transactions = data.transactions || [];
          this.totals = data.totals || this.totals;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(err => {
          NProgress.done();
          this.loadError =
            (err && err.response && err.response.data && (err.response.data.message || err.response.data.error)) ||
            (err && err.message) || "Error de red.";
          setTimeout(() => { this.isLoading = false; }, 300);
        });
    },
    doPrint() {
      const headers = ["Tipo", "Fecha", "Referencia", "Parte", "Entrada", "Salida", "Valor unit.", "Saldo"];
      const rows = (this.filteredTransactions || []).map(t => [
        this.typeLabel(t.type),
        t.date || "—",
        t.ref || "",
        t.party_name || "",
        t.qty_in ? "+" + this.fmtNum(t.qty_in) : "—",
        t.qty_out ? "-" + this.fmtNum(t.qty_out) : "—",
        t.unit_value !== null && t.unit_value !== undefined ? this.money(t.unit_value) : "—",
        this.fmtNum(t.running_balance)
      ]);
      const title =
        "Informes / Historial de lote — " + (this.batch.batch_no || "") +
        " · " + (this.batch.product_name || "") +
        " · Cantidad actual: " + this.fmtNum(this.batch.qty) +
        " · Conciliación: entradas " + this.fmtNum(this.totals.in) +
        " / salidas " + this.fmtNum(this.totals.out) +
        " / calculada " + this.fmtNum(this.totals.computed_qty) +
        " / real " + this.fmtNum(this.totals.actual_qty) +
        (this.hasDrift ? " / DESCUADRE " + this.fmtNum(this.totals.drift) : " / cuadrado");
      const ok = printTableDoc({ title, headers, rows });
      if (!ok) this.$root.$bvToast.toast("Permite las ventanas emergentes para imprimir.", { title: "Aviso", variant: "warning", solid: true });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrbh { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrbh { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrbh__denied { padding: var(--pxn-space-12) 0; }
.pxrbh__pad { padding: var(--pxn-space-6) 0; }
.pxrbh__alert { margin-top: var(--pxn-space-5); }
.pxrbh__muted { color: var(--pxn-ink-3); }
.pxrbh__strong { font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxrbh__pos { color: var(--pxn-success-ink); }
.pxrbh__neg { color: var(--pxn-danger-ink); }

.pxrbh__hero { margin-top: var(--pxn-space-5); }
.pxrbh__dl { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-4); margin: 0; }
@media (max-width: 900px) { .pxrbh__dl { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxrbh__dl { grid-template-columns: minmax(0, 1fr); } }
.pxrbh__dl dt { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); text-transform: uppercase; letter-spacing: 0.04em; }
.pxrbh__dl dd { margin: var(--pxn-space-1) 0 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink); }
.pxrbh__notes { margin: var(--pxn-space-4) 0 0; padding: var(--pxn-space-3) var(--pxn-space-4); background: var(--pxn-surface-2); border-radius: var(--pxn-radius-md); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }

.pxrbh__log { margin-top: var(--pxn-space-5); }
.pxrbh__log-head { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-4); flex-wrap: wrap; margin-bottom: var(--pxn-space-4); }
.pxrbh__log-title { margin: 0; font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxrbh__empty { padding: var(--pxn-space-6); text-align: center; color: var(--pxn-ink-3); font-size: var(--pxn-fs-sm); }
.pxrbh-tbl__wrap { overflow-x: auto; border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); }
.pxrbh-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxrbh-tbl th {
  text-align: left; padding: var(--pxn-space-3) var(--pxn-space-4);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
  text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3);
  background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap;
}
.pxrbh-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); vertical-align: middle; }
.pxrbh-tbl tr:last-child td { border-bottom: 0; }
.pxrbh-tbl .is-right { text-align: right; }
.pxrbh__party { display: block; }
.pxrbh-pill { display: inline-block; padding: 2px 8px; border-radius: var(--pxn-radius-pill); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); }
.pxrbh-pill.is-danger { background: var(--pxn-danger-soft); color: var(--pxn-danger-ink); }
.pxrbh-pill.is-warning { background: var(--pxn-warning-soft); color: var(--pxn-warning-ink); }
.pxrbh-pill.is-success { background: var(--pxn-success-soft); color: var(--pxn-success-ink); }
.pxrbh-pill.is-neutral { background: var(--pxn-surface-3); color: var(--pxn-ink-3); }
.pxrbh-link { color: var(--pxn-primary-ink); font-weight: var(--pxn-fw-medium); text-decoration: none; }
.pxrbh-link:hover { text-decoration: underline; }

.pxrbh__recon { margin-top: var(--pxn-space-5); padding: var(--pxn-space-5); border: 1px solid var(--pxn-primary-border); border-radius: var(--pxn-radius-md); background: var(--pxn-primary-soft); }
.pxrbh__recon-title { display: flex; align-items: center; gap: var(--pxn-space-2); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-primary-ink); margin-bottom: var(--pxn-space-4); }
.pxrbh__recon-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-4); }
@media (max-width: 720px) { .pxrbh__recon-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.pxrbh__recon-grid > div { display: flex; flex-direction: column; gap: 2px; text-align: center; }
.pxrbh__recon-grid span { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxrbh__recon-grid strong { font-size: var(--pxn-fs-h3); }
.pxrbh__recon-msg { margin-top: var(--pxn-space-4); }
</style>
