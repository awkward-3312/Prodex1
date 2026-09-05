<template>
  <!-- NEW FEATURE - SAFE ADDITION -->
  <div class="px-next pxac">
    <px-page-header :title="$t('Accounting_Dashboard_Title')" :subtitle="$t('Accounting_Dashboard_Subtitle')" />

    <div class="pxac__kpis">
      <px-stat bordered :label="$t('Accounts')" :value="String(kpi.accounts)" icon="database" />
      <px-stat bordered :label="$t('Journal_Entries_30d')" :value="String(kpi.journals)" icon="clipboard-list" />
      <px-stat bordered :label="$t('Income_30d')" :value="toMoney(kpi.income)" icon="trending-up" />
      <px-stat bordered :label="$t('Expense_30d')" :value="toMoney(kpi.expense)" icon="trending-down" />
    </div>

    <px-card :title="$t('Quick_Links') || 'Accesos rápidos'">
      <div class="pxac__links">
        <router-link class="pxac__link" to="/app/accounting-v2/chart-of-accounts">
          <lucide-icon name="database" :size="16" /> <span>{{ $t('Chart_of_Accounts_Link') }}</span>
        </router-link>
        <router-link class="pxac__link" to="/app/accounting-v2/journal-entries">
          <lucide-icon name="clipboard-list" :size="16" /> <span>{{ $t('Journal_Entries_Link') }}</span>
        </router-link>
        <router-link class="pxac__link" to="/app/accounting-v2/reports/trial-balance">
          <lucide-icon name="scale" :size="16" /> <span>{{ $t('Trial_Balance_Link') }}</span>
        </router-link>
        <router-link class="pxac__link" to="/app/accounting-v2/reports/profit-and-loss">
          <lucide-icon name="wallet" :size="16" /> <span>{{ $t('Profit_Loss_Link') }}</span>
        </router-link>
        <router-link class="pxac__link" to="/app/accounting-v2/reports/balance-sheet">
          <lucide-icon name="pie-chart" :size="16" /> <span>{{ $t('Balance_Sheet_Link') }}</span>
        </router-link>
        <router-link class="pxac__link" to="/app/accounting-v2/reports/tax-report">
          <lucide-icon name="receipt-text" :size="16" /> <span>{{ $t('Tax_Summary_Link') }}</span>
        </router-link>
      </div>
    </px-card>

    <px-card :title="`${$t('Income_30d')} / ${$t('Expense_30d')}`" class="pxac__chartcard">
      <apexchart type="area" height="320" :options="chart.options" :series="chart.series" />
    </px-card>
  </div>
</template>

<script>

import VueApexCharts from 'vue-apexcharts';
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting,
  getPriceDecimals
} from "../../../../utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxStat from "@/components/px-next/PxStat.vue";

export default {
  name: "AccountingV2Dashboard",
  components: { apexchart: VueApexCharts, PxPageHeader, PxCard, PxStat },
  data() {
    return {
      kpi: { accounts: 0, journals: 0, income: 0, expense: 0 },
      // Optional price format key for frontend display (loaded from system settings/Vuex store)
      price_format_key: null,
      chart: {
        options: {
          chart: { type: 'area', toolbar: { show: false } },
          dataLabels: { enabled: false },
          stroke: { curve: 'smooth', width: 2 },
          fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 95, 100] } },
          xaxis: { categories: [], labels: { rotate: -45 } },
          legend: { position: 'top' },
          tooltip: { shared: true, intersect: false, y: { formatter: (val) => Number(val || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) } },
          yaxis: [{ labels: { formatter: (val) => Number(val || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) } }],
          colors: ['#4CAF50', '#EF5350'],
          noData: { text: '...' }
        },
        series: [
          { name: 'Income', data: [] },
          { name: 'Expense', data: [] }
        ]
      }
    };
  },
  created() { this.fetch(); },
  computed: {
    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
  },
  methods: {
    async fetch() {
      try {
        const { data } = await axios.get('/accounting/v2/dashboard');
        const kpi = (data && data.kpi) || {};
        const chart = (data && data.chart) || {};
        this.kpi.accounts = kpi.accounts || 0;
        this.kpi.journals = kpi.journals_30d || 0;
        this.kpi.income = kpi.income_30d || 0;
        this.kpi.expense = kpi.expense_30d || 0;

        const labels = chart.labels || [];
        const incomeSeries = chart.income || [];
        const expenseSeries = chart.expense || [];
        this.chart.series = [
          { name: this.$t('Income_30d'), data: incomeSeries },
          { name: this.$t('Expense_30d'), data: expenseSeries }
        ];
        this.chart.options = Object.assign({}, this.chart.options, {
          xaxis: Object.assign({}, this.chart.options.xaxis, { categories: labels }),
          noData: { text: this.$t('No_Data') || 'No data' },
          tooltip: Object.assign({}, this.chart.options.tooltip, {
            y: { formatter: (val) => this.toMoney(val) }
          }),
          yaxis: [{ labels: { formatter: (val) => this.toMoney(val) } }]
        });
      } catch (e) {}
    },
    // Price formatting for display only (does NOT affect calculations or stored values)
    // Uses the global/system price_format setting when available; otherwise falls back
    // to the existing toLocaleString behavior to preserve current behavior.
    toMoney(v) {
      try {
        const n = parseFloat(v || 0);
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        const effectiveKey = key || null;
        return formatPriceDisplayHelper(n, this.priceDecimals, effectiveKey);
      } catch (e) {
        const n = parseFloat(v || 0);
        return n.toLocaleString(undefined, {minimumFractionDigits:this.priceDecimals, maximumFractionDigits:this.priceDecimals});
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxac { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxac { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxac__kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-5); margin: var(--pxn-space-5) 0; }
@media (max-width: 900px) { .pxac__kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 480px) { .pxac__kpis { grid-template-columns: minmax(0, 1fr); } }
.pxac__links { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-4); }
@media (max-width: 800px) { .pxac__links { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 480px) { .pxac__links { grid-template-columns: minmax(0, 1fr); } }
.pxac__link {
  display: flex; align-items: center; gap: var(--pxn-space-3);
  padding: var(--pxn-space-4) var(--pxn-space-5);
  border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
  font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink-2);
  transition: background-color var(--pxn-dur-1) var(--pxn-ease), border-color var(--pxn-dur-1) var(--pxn-ease), color var(--pxn-dur-1) var(--pxn-ease);
}
.pxac__link:hover { background: var(--pxn-surface-2); border-color: var(--pxn-border-strong); color: var(--pxn-ink); }
.pxac__chartcard { margin-top: var(--pxn-space-5); }
</style>
