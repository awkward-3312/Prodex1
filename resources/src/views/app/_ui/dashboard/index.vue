<template>
  <div class="px-next pxn-doc pxb1" :style="fontOverride">
    <!--
      ============================================================================
      DIRECTION CONTRACT · px-next · "Panel de operación" — B1 Dashboard preview
      references: 3 provided dashboards · mode: Operate · seed: brief-pinned
      ----------------------------------------------------------------------------
      THESIS: the operator opens the app and reads the state of the business in
        one screen — money in, money out, what's owed, what it's worth, what
        just happened. Real endpoint (/api/dashboard_data), real numbers.
        Information parity with the current dashboard, NOT layout parity.
      OWN-WORLD: layered light neutrals, hairline structure, one tenant accent
        (--primary-color) for figures-that-matter and active state; charts
        restyled to px-next tokens (ApexCharts kept). Tabular lining figures.
      STORY: scan KPI row → read the sales trend → check recent activity and
        what needs attention (stock alerts, dues). Filter by branch/warehouse
        and date range; everything recomputes.
      FIRST VIEWPORT: page header with branch + range; a row of KPI figures
        rendered immediately (no count-up); the sales trend chart as the anchor.
      FORM: operational panel. #1 of the pinned brief.
      FINISH: unreviewed and undocumented is unfinished; this build ends with the
        finish review, the verdict, DESIGN.md, and every shipping raster carrying
        its provenance.
      ============================================================================
    -->

    <!-- permiso: mismo gate y fallback que el dashboard actual -->
    <div v-if="!hasDashboardPermission" class="pxb1__denied">
      <px-empty-state
        icon="lock"
        :title="tt('No_dashboard_permission', 'No tienes permiso para ver el panel')"
        description="Pide a un administrador el permiso «dashboard»."
      />
    </div>

    <template v-else>
      <px-page-header
        :title="tt('dashboard', 'Panel')"
        :breadcrumbs="[{ label: tt('dashboard', 'Panel') }]"
      >
        <template #title-badge>
          <px-badge tone="info" icon="sparkles">Preview B1</px-badge>
        </template>
        <template #meta>
          <span><lucide-icon name="warehouse" :size="13" /> {{ activeWarehouseLabel }}</span>
          <span><lucide-icon name="calendar" :size="13" /> {{ prettyRange }}</span>
          <span v-if="adapted && adapted.extras.branchesCount"><lucide-icon name="building-2" :size="13" /> {{ adapted.extras.branchesCount }} sucursales</span>
        </template>
        <template #actions>
          <div class="pxb1__filters">
            <label class="pxb1__control">
              <span>Almacén</span>
              <px-select
                :value="String(warehouseId)"
                :options="warehouseOptions"
                @input="onWarehouse"
              />
            </label>
            <label class="pxb1__control">
              <span>Desde</span>
              <input type="date" class="pxb1__date pxn-ring" :value="dateFrom" :max="dateTo" @change="onFrom" />
            </label>
            <label class="pxb1__control">
              <span>Hasta</span>
              <input type="date" class="pxb1__date pxn-ring" :value="dateTo" :min="dateFrom" @change="onTo" />
            </label>
            <px-button class="pxb1__refresh" variant="ghost" icon="refresh-cw" :loading="loading" @click="load">Actualizar</px-button>
          </div>
        </template>
      </px-page-header>

      <!-- error -->
      <px-alert v-if="error" tone="danger" title="No se pudo cargar el panel" class="pxb1__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="load">Reintentar</px-button></template>
      </px-alert>

      <template v-if="loading || adapted">
      <!-- KPI row — cifras inmediatas, sin count-up -->
      <div class="pxb1__kpis">
        <px-card v-for="c in kpiCards" :key="c.key">
          <px-skeleton v-if="loading" variant="lines" :rows="3" />
          <px-stat v-else :label="c.label" :value="c.value" :sub="c.sub" :icon="c.icon" />
        </px-card>
      </div>

      <!-- Valorización de inventario: la cifra "a costo" del KPI, con sus 3 bases -->
      <px-card class="pxb1__valuation" flush>
        <div class="pxb1__valuation-grid">
          <div class="pxb1__valuation-head">
            <lucide-icon name="boxes" :size="15" />
            <span>Valorización de inventario</span>
          </div>
          <div class="pxb1__valuation-item">
            <dt>A costo</dt>
            <dd class="pxn-num">{{ loading ? '—' : money(sv.byCost) }}</dd>
          </div>
          <div class="pxb1__valuation-item">
            <dt>A precio de venta</dt>
            <dd class="pxn-num">{{ loading ? '—' : money(sv.byRetail) }}</dd>
          </div>
          <div class="pxb1__valuation-item">
            <dt>A mayoreo</dt>
            <dd class="pxn-num">{{ loading ? '—' : money(sv.byWholesale) }}</dd>
          </div>
        </div>
      </px-card>

      <!-- gráficos — el mismo set del dashboard actual, recompuesto -->
      <div class="pxb1__charts">
        <px-apex-frame
          class="pxb1__chart pxb1__chart--wide"
          title="Ventas y compras"
          :unit="prettyRange"
          type="bar"
          :series="salesVsPurchasesSeries"
          :options="salesVsPurchasesOptions"
          :legend="[
            { label: 'Ventas', color: 'var(--pxn-chart-1)' },
            { label: 'Compras', color: 'var(--pxn-chart-muted)' }
          ]"
          :height="220"
          empty-title="Sin ventas ni compras en el rango"
        />
        <px-apex-frame
          class="pxb1__chart"
          title="Pagos recibidos y enviados"
          type="area"
          :series="paymentsSeries"
          :options="paymentsOptions"
          :legend="[
            { label: 'Recibidos', color: 'var(--pxn-chart-3)' },
            { label: 'Enviados', color: 'var(--pxn-chart-4)' }
          ]"
          :height="220"
          empty-title="Sin pagos en el rango"
        />
        <px-apex-frame
          class="pxb1__chart"
          title="Productos más vendidos"
          type="donut"
          :series="productChartSeries"
          :options="productChartOptions"
          :legend="productLegend"
          :height="220"
          empty-title="Sin ventas de productos"
        />
        <px-apex-frame
          class="pxb1__chart"
          title="Top clientes"
          type="donut"
          :series="customerChartSeries"
          :options="customerChartOptions"
          :legend="customerLegend"
          :height="220"
          empty-title="Sin clientes con ventas"
        />
        <px-card class="pxb1__chart pxb1__paycard" title="Ventas por método de pago">
          <div v-if="!loading && paymentBreakdown.length" class="pxb1__paylist">
            <div v-for="p in paymentBreakdown" :key="p.name" class="pxb1__payrow">
              <div class="pxb1__payhead">
                <span class="pxb1__payname" :title="p.name">{{ p.name }}</span>
                <span class="pxn-num pxb1__payamount">{{ money(p.amount) }}</span>
              </div>
              <div class="pxb1__paytrack"><span :style="{ width: Math.min(100, p.percentage) + '%' }"></span></div>
              <span class="pxb1__paypct pxn-num">{{ number(p.percentage, 1) }} %</span>
            </div>
          </div>
          <px-skeleton v-else-if="loading" variant="lines" :rows="3" />
          <px-empty-state v-else inline icon="credit-card" title="Sin cobros registrados" description="No hay ventas cobradas en el rango seleccionado." />
        </px-card>
      </div>

      <!-- tablas -->
      <div class="pxb1__tables">
        <px-card title="Ventas recientes" flush class="pxb1__recent">
          <template #actions><span class="pxn-fs-xs pxn-muted pxn-num">{{ (adapted && adapted.recentSales.length) || 0 }}</span></template>
          <px-table
            v-if="!loading && adapted && adapted.recentSales.length"
            :columns="recentColumns"
            :rows="adapted.recentSales"
            row-key="id"
            density="compact"
          >
            <template #cell-ref="{ row }"><span class="pxn-mono">{{ row.ref }}</span></template>
            <template #cell-client="{ row }">
              <px-entity-cell :name="row.client" :secondary="row.warehouse" tight icon="user" />
            </template>
            <template #cell-total="{ row }"><span class="pxn-num">{{ money(row.total) }}</span></template>
            <template #cell-due="{ row }">
              <span class="pxn-num" :class="{ 'pxb1__due': row.due > 0 }">{{ money(row.due) }}</span>
            </template>
            <template #cell-status="{ row }">
              <px-badge :tone="payTone(row.status)" :icon="payIcon(row.status)">{{ payLabel(row.status) }}</px-badge>
            </template>
          </px-table>
          <div v-else class="pxb1__pad">
            <px-skeleton v-if="loading" variant="table" :rows="6" :columns="5" />
            <px-empty-state v-else inline icon="inbox" title="Sin ventas recientes" description="No hay ventas en el rango seleccionado." />
          </div>
        </px-card>

        <div class="pxb1__side">
          <px-card title="Top productos" flush>
            <px-table
              v-if="!loading && topProductsRows.length"
              :columns="topProductColumns"
              :rows="topProductsRows"
              row-key="name"
              density="compact"
            >
              <template #cell-total="{ row }"><span class="pxn-num">{{ money(row.total) }}</span></template>
            </px-table>
            <div v-else class="pxb1__pad">
              <px-skeleton v-if="loading" variant="table" :rows="5" :columns="3" />
              <px-empty-state v-else inline icon="package-search" title="Sin datos" description="No hay ventas de productos en el rango." />
            </div>
          </px-card>

          <px-card title="Alertas de stock" flush>
            <px-table
              v-if="!loading && stockAlertRows.length"
              :columns="stockAlertColumns"
              :rows="stockAlertRows"
              row-key="code"
              density="compact"
            >
              <template #cell-qty="{ row }">
                <span class="pxn-num pxb1__low">{{ number(row.qty) }}</span>
              </template>
              <template #cell-alert="{ row }"><span class="pxn-num pxn-muted">{{ number(row.alert) }}</span></template>
            </px-table>
            <div v-else class="pxb1__pad">
              <px-skeleton v-if="loading" variant="lines" :rows="3" />
              <px-empty-state v-else tone="info" icon="check" title="Todo por encima del umbral" description="Ningún producto por debajo de su alerta de stock." />
            </div>
          </px-card>
        </div>
      </div>
      </template>

      <p class="pxb1__note">
        <lucide-icon name="sparkles" :size="13" />
        Candidato experimental de <code>/app/_ui/dashboard</code>. No reemplaza <code>/app/dashboard</code>.
        Datos reales de <code>/api/dashboard_data</code> · paridad de información, no de layout.
      </p>
    </template>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import {
  PxPageHeader, PxBadge, PxSelect, PxButton, PxAlert, PxCard, PxStat,
  PxTable, PxEntityCell, PxEmptyState
} from "@/components/px-next";
import PxApexFrame from "./widgets/PxApexFrame.vue";
import { adaptDashboard } from "./adapter";
import { makeFormatters } from "./format";

const PAY = {
  paid:     { tone: "success", icon: "check", label: "Pagada" },
  partial:  { tone: "warning", icon: "clock", label: "Parcial" },
  unpaid:   { tone: "danger",  icon: "x", label: "Sin pagar" }
};

// Fecha local YYYY-MM-DD (no UTC — evita el desfase de un día en el picker).
function iso(d) {
  const p = n => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

function cssVar(name, fallback) {
  try {
    const scope = document.querySelector(".px-next") || document.documentElement;
    const v = getComputedStyle(scope).getPropertyValue(name).trim();
    if (v) return v;
    const rootV = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return rootV || fallback;
  } catch (e) { return fallback; }
}

// Rampa categórica px-next (--pxn-chart-*) resuelta a color real.
// Slot 1 = acento del tenant; 2–8 hex literales de _tokens.scss.
const CHART_FALLBACK = ["#3d4859", "#2e7d5b", "#b9761c", "#b23a5b", "#6a7f2e", "#8a5a44", "#b0568f"];
function chartRamp() {
  const primary = cssVar("--primary-color", "#6d28d9");
  return [primary, ...CHART_FALLBACK.map((f, i) => cssVar(`--pxn-chart-${i + 2}`, f) || f)];
}

export default {
  name: "PxNextDashboardPreview",
  components: {
    PxPageHeader, PxBadge, PxSelect, PxButton, PxAlert, PxCard, PxStat,
    PxTable, PxEntityCell, PxEmptyState, PxApexFrame
  },
  data() {
    const today = new Date();
    const first = new Date(today.getFullYear(), today.getMonth(), 1);
    return {
      loading: true,
      error: null,
      raw: null,
      adapted: null,
      warehouseId: 0,
      dateFrom: iso(first),
      dateTo: iso(today),
      settingsFontFamily: "",
      recentColumns: [
        { key: "ref", label: "Documento" },
        { key: "client", label: "Cliente" },
        { key: "total", label: "Total", align: "right", numeric: true },
        { key: "due", label: "Por cobrar", align: "right", numeric: true },
        { key: "status", label: "Estado" }
      ],
      topProductColumns: [
        { key: "name", label: "Producto", strong: true },
        { key: "qty", label: "Cant.", align: "right", numeric: true },
        { key: "total", label: "Total", align: "right", numeric: true }
      ],
      stockAlertColumns: [
        { key: "name", label: "Producto", strong: true },
        { key: "qty", label: "Stock", align: "right", numeric: true },
        { key: "alert", label: "Alerta", align: "right", numeric: true }
      ]
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    hasDashboardPermission() {
      const p = Array.isArray(this.currentUserPermissions) ? this.currentUserPermissions : [];
      return p.includes("dashboard");
    },
    fmt() {
      return makeFormatters(this.currentUser && this.currentUser.currency);
    },
    fontOverride() {
      return this.settingsFontFamily
        ? { "--pxn-font-sans": `${JSON.stringify(this.settingsFontFamily)}, var(--pxn-font-sans)` }
        : null;
    },
    warehouseOptions() {
      const opts = [{ value: "0", label: "Todos los almacenes" }];
      (this.adapted && this.adapted.warehouses || []).forEach(w =>
        opts.push({ value: String(w.id), label: w.name })
      );
      return opts;
    },
    activeWarehouseLabel() {
      const w = (this.adapted && this.adapted.warehouses || []).find(x => String(x.id) === String(this.warehouseId));
      return w ? w.name : "Todos los almacenes";
    },
    prettyRange() {
      const f = new Date(this.dateFrom + "T00:00:00");
      const t = new Date(this.dateTo + "T00:00:00");
      const o = { day: "2-digit", month: "short", year: "numeric" };
      try {
        return `${new Intl.DateTimeFormat(undefined, o).format(f)} – ${new Intl.DateTimeFormat(undefined, o).format(t)}`;
      } catch (e) { return `${this.dateFrom} – ${this.dateTo}`; }
    },
    k() { return this.adapted ? this.adapted.kpis : {}; },
    sv() { return this.adapted ? this.adapted.stockValue : {}; },
    kpiCards() {
      const k = this.k;
      return [
        { key: "sales", label: "Total ventas", icon: "trending-up",
          value: this.money(k.sales), sub: `${this.number(k.invoices)} facturas` },
        { key: "purch", label: "Total compras", icon: "shopping-cart",
          value: this.money(k.purchases), sub: k.returnPurch ? `Devoluciones ${this.money(k.returnPurch)}` : null },
        { key: "sdue", label: "Por cobrar", icon: "coins",
          value: this.money(k.salesDue), sub: k.purchaseDue ? `Por pagar ${this.money(k.purchaseDue)}` : null },
        { key: "profit", label: "Utilidad", icon: "percent",
          value: this.money(k.profit),
          sub: k.sales ? `Margen ${this.number(k.sales ? (k.profit / k.sales) * 100 : 0, 1)} %` : null },
        { key: "stock", label: "Inventario a costo", icon: "boxes",
          value: this.money(this.sv.byCost), sub: `Valorización a venta y mayoreo abajo` }
      ];
    },

    // ---- series de gráficos (mismo set que dashboard.vue) --------------------
    salesVsPurchasesSeries() {
      const a = this.adapted;
      return [
        { name: "Ventas", data: (a && a.salesSeries.data) || [] },
        { name: "Compras", data: (a && a.purchasesSeries.data) || [] }
      ];
    },
    salesVsPurchasesOptions() {
      const a = this.adapted;
      const days = (a && (a.salesSeries.days.length ? a.salesSeries.days : a.purchasesSeries.days)) || [];
      return {
        colors: [cssVar("--primary-color", "#6d28d9"), cssVar("--pxn-chart-muted", "#8a93a3")],
        plotOptions: { bar: { columnWidth: days.length > 20 ? "80%" : "55%" } },
        xaxis: { categories: this.shortDays(days), tickAmount: Math.min(days.length, 10) }
      };
    },
    paymentsSeries() {
      const p = this.adapted && this.adapted.paymentsSeries;
      return [
        { name: "Recibidos", data: (p && p.received) || [] },
        { name: "Enviados", data: (p && p.sent) || [] }
      ];
    },
    paymentsOptions() {
      const p = this.adapted && this.adapted.paymentsSeries;
      const days = (p && p.days) || [];
      return {
        colors: [cssVar("--pxn-chart-3", "#2e7d5b"), cssVar("--pxn-chart-4", "#b9761c")],
        xaxis: { categories: this.shortDays(days), tickAmount: Math.min(days.length, 10) }
      };
    },
    productChartSeries() {
      return ((this.adapted && this.adapted.productChart) || []).map(p => p.value);
    },
    productChartOptions() {
      return this.pieOptions(
        ((this.adapted && this.adapted.productChart) || []).map(p => p.name),
        "unidades"
      );
    },
    productLegend() {
      const ramp = chartRamp();
      return ((this.adapted && this.adapted.productChart) || [])
        .map((p, i) => ({ label: p.name, color: ramp[i % ramp.length] }));
    },
    customerChartSeries() {
      return ((this.adapted && this.adapted.customerChart) || []).map(c => c.value);
    },
    customerChartOptions() {
      return this.pieOptions(
        ((this.adapted && this.adapted.customerChart) || []).map(c => c.name),
        "ventas"
      );
    },
    customerLegend() {
      const ramp = chartRamp();
      return ((this.adapted && this.adapted.customerChart) || [])
        .map((c, i) => ({ label: c.name, color: ramp[i % ramp.length] }));
    },
    paymentBreakdown() { return (this.adapted && this.adapted.salesByPayment) || []; },
    topProductsRows() { return (this.adapted && this.adapted.topProducts) || []; },
    stockAlertRows() { return (this.adapted && this.adapted.stockAlerts) || []; }
  },
  watch: {
    hasDashboardPermission: {
      immediate: true,
      handler(v) { if (v) this.boot(); else this.loading = false; }
    }
  },
  methods: {
    tt(key, fallback) {
      try {
        if (!this.$t) return fallback;
        const s = this.$t(key);
        return s && s !== key ? s : fallback;
      } catch (e) { return fallback; }
    },
    money(v) { return this.fmt.money(v, 2); },
    number(v, d = 0) { return this.fmt.number(v, d); },
    payTone(s) { return (PAY[s] || {}).tone || "neutral"; },
    payIcon(s) { return (PAY[s] || {}).icon || "circle"; },
    payLabel(s) { return (PAY[s] || {}).label || (s || "—"); },
    shortDays(days) {
      return days.map(d => {
        const p = String(d).split("-");
        return p.length === 3 ? `${p[2]}/${p[1]}` : d;
      });
    },
    // Opciones comunes de tarta/dona. La leyenda la dibuja PxChartFrame en HTML
    // (nombre completo en `title` + recorte visual con ellipsis), NO Apex — así
    // los nombres largos nunca rompen el layout. Color desde la rampa --pxn-chart-*.
    pieOptions(labels, unitWord) {
      return {
        labels,
        colors: chartRamp(),
        stroke: { width: 1, colors: [cssVar("--pxn-surface", "#ffffff")] },
        legend: { show: false },
        dataLabels: {
          enabled: true,
          style: { fontSize: "10px", fontWeight: 600 },
          dropShadow: { enabled: false },
          formatter: v => `${Math.round(v)}%`
        },
        tooltip: { y: { formatter: v => `${this.number(v)} ${unitWord}` } }
      };
    },
    async boot() {
      await this.loadSettings();
      await this.load();
    },
    async loadSettings() {
      try {
        const { data } = await window.axios.get("get_Settings_data", { meta: { skipInitialLoader: true } });
        const s = data && (data.settings || data) || {};
        if (s.dashboard_font_family) this.settingsFontFamily = String(s.dashboard_font_family);
        // default de rango si el tenant lo define
        const preset = s.default_dashboard_date_range || s.default_dashboard_range;
        if (preset) this.applyPreset(String(preset));
      } catch (e) { /* opcional; se sigue con el rango por defecto */ }
    },
    applyPreset(preset) {
      const today = new Date();
      const set = (from, to) => { this.dateFrom = iso(from); this.dateTo = iso(to || today); };
      const p = preset.toLowerCase();
      if (p.includes("today") || p === "day") set(today);
      else if (p.includes("year")) set(new Date(today.getFullYear(), 0, 1));
      else if (p.includes("week")) { const d = new Date(today); d.setDate(d.getDate() - 6); set(d); }
      else if (p.includes("30")) { const d = new Date(today); d.setDate(d.getDate() - 29); set(d); }
      else if (p.includes("month")) set(new Date(today.getFullYear(), today.getMonth(), 1));
    },
    async load() {
      this.loading = true;
      this.error = null;
      try {
        const { data } = await window.axios.get("dashboard_data", {
          params: { warehouse_id: this.warehouseId, from: this.dateFrom, to: this.dateTo },
          meta: { skipInitialLoader: true }
        });
        this.raw = data;
        this.adapted = adaptDashboard(data);
      } catch (e) {
        this.error =
          (e && e.response && e.response.data && (e.response.data.message || e.response.data.error)) ||
          (e && e.message) ||
          "Error de red.";
        this.adapted = null;
      } finally {
        this.loading = false;
      }
    },
    onWarehouse(v) { this.warehouseId = Number(v) || 0; this.load(); },
    onFrom(e) { this.dateFrom = e.target.value; if (this.dateFrom && this.dateTo) this.load(); },
    onTo(e) { this.dateTo = e.target.value; if (this.dateFrom && this.dateTo) this.load(); }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/index.scss"></style>

<style lang="scss" scoped>
.pxb1 { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-11); }
@media (max-width: 620px) { .pxb1 { padding: var(--pxn-space-6) var(--pxn-space-5) var(--pxn-space-10); } }
.pxb1__denied { padding: var(--pxn-space-12) 0; }
.pxb1__alert { margin-top: var(--pxn-space-6); }

// Desktop: almacén + desde + hasta + Actualizar en UNA fila (header más bajo).
.pxb1__filters {
  display: flex; flex-wrap: nowrap; align-items: flex-end;
  gap: var(--pxn-space-4); min-width: 0;
}
.pxb1__control {
  display: inline-flex; flex-direction: column; gap: var(--pxn-space-2);
  flex: 0 1 auto; min-width: 0;
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink-2);
}
.pxb1__filters > .pxb1__control:first-child { width: 172px; }          // almacén
.pxb1__filters > .pxb1__control:not(:first-child) { width: 146px; }    // desde / hasta
.pxb1__date {
  width: 100%;
  height: var(--pxn-control-h-md); padding: 0 var(--pxn-space-4);
  border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); font: inherit; font-size: var(--pxn-fs-body); color: var(--pxn-ink);
}
.pxb1__refresh { flex: 0 0 auto; align-self: flex-end; }

// Tablet: puede envolver a dos filas.
@media (max-width: 860px) {
  .pxb1__filters { flex-wrap: wrap; }
  .pxb1__filters > .pxb1__control,
  .pxb1__filters > .pxb1__control:first-child,
  .pxb1__filters > .pxb1__control:not(:first-child) { flex: 1 1 160px; width: auto; }
}
// Móvil: apilado, ancho completo.
@media (max-width: 620px) {
  .pxb1 :deep(.pxn-pagehead__actions) { width: 100%; }
  .pxb1__filters { width: 100%; }
  .pxb1__filters > .pxb1__control { flex-basis: 100%; }
  .pxb1__refresh { width: 100%; margin-top: var(--pxn-space-2); }
}

.pxb1__kpis {
  display: grid; grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: var(--pxn-space-5); margin-top: var(--pxn-space-8);
}
@media (max-width: 1200px) { .pxb1__kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 720px) { .pxb1__kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 460px) { .pxb1__kpis { grid-template-columns: minmax(0, 1fr); } }

.pxb1__valuation { margin-top: var(--pxn-space-5); }
.pxb1__valuation-grid {
  display: grid; grid-template-columns: auto repeat(3, 1fr);
  align-items: center;
}
.pxb1__valuation-head {
  display: flex; align-items: center; gap: var(--pxn-space-3);
  padding: var(--pxn-space-5) var(--pxn-space-7);
  font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink-2);
  border-right: 1px solid var(--pxn-border);
}
.pxb1__valuation-item {
  padding: var(--pxn-space-5) var(--pxn-space-7);
}
.pxb1__valuation-item + .pxb1__valuation-item { border-left: 1px solid var(--pxn-border); }
.pxb1__valuation-item dt { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxb1__valuation-item dd { margin: 2px 0 0; font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-bold); color: var(--pxn-ink); }
@media (max-width: 720px) {
  .pxb1__valuation-grid { grid-template-columns: 1fr 1fr; }
  .pxb1__valuation-head { grid-column: 1 / -1; border-right: 0; border-bottom: 1px solid var(--pxn-border); }
  .pxb1__valuation-item:nth-child(2), .pxb1__valuation-item:nth-child(3) { border-left: 0; }
  .pxb1__valuation-item:nth-child(4) { grid-column: 1 / -1; border-left: 0; border-top: 1px solid var(--pxn-border); }
}

.pxb1__charts {
  display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--pxn-space-5); margin-top: var(--pxn-space-6);
  align-items: start;
}
.pxb1__chart--wide { grid-column: 1 / -1; }
@media (max-width: 900px) { .pxb1__charts { grid-template-columns: minmax(0, 1fr); } .pxb1__chart--wide { grid-column: auto; } }

// Leyendas de gráfico: cada ítem cabe a lo sumo a media fila y recorta el
// nombre largo con ellipsis (el nombre completo queda en `title` y en el DOM).
.pxb1__chart :deep(.pxn-chartframe__legenditem) { max-width: calc(50% - var(--pxn-space-3)); }

.pxb1__paycard .pxb1__paylist { display: flex; flex-direction: column; gap: var(--pxn-space-5); }
.pxb1__payrow { display: grid; grid-template-columns: minmax(0, 1fr) auto; column-gap: var(--pxn-space-4); align-items: baseline; }
.pxb1__payhead { display: contents; }
.pxb1__payname {
  min-width: 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink); font-weight: var(--pxn-fw-medium);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.pxb1__payamount { font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); white-space: nowrap; }
.pxb1__paytrack {
  grid-column: 1 / -1; margin-top: var(--pxn-space-2);
  height: 6px; border-radius: var(--pxn-radius-pill);
  background: var(--pxn-surface-3); overflow: hidden;
}
.pxb1__paytrack > span { display: block; height: 100%; border-radius: inherit; background: var(--pxn-chart-1); }
.pxb1__paypct { grid-column: 1 / -1; margin-top: var(--pxn-space-2); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }

.pxb1__tables { display: grid; grid-template-columns: 1.7fr 1fr; gap: var(--pxn-space-5); margin-top: var(--pxn-space-6); align-items: start; }
@media (max-width: 1100px) { .pxb1__tables { grid-template-columns: minmax(0, 1fr); } }
.pxb1__side { display: flex; flex-direction: column; gap: var(--pxn-space-5); }
.pxb1__pad { padding: var(--pxn-space-6); }

.pxb1__due { color: var(--pxn-warning-ink); font-weight: var(--pxn-fw-semibold); }
.pxb1__low { color: var(--pxn-danger-ink); font-weight: var(--pxn-fw-semibold); }

.pxb1__note {
  display: block;
  margin-top: var(--pxn-space-9); padding-top: var(--pxn-space-5);
  border-top: 1px solid var(--pxn-border);
  font-size: var(--pxn-fs-xs); line-height: var(--pxn-lh-normal); color: var(--pxn-ink-3);
}
.pxb1__note code { font-size: 0.92em; white-space: nowrap; }
.pxb1__note :deep(svg) { vertical-align: -2px; margin-right: var(--pxn-space-2); }
</style>
