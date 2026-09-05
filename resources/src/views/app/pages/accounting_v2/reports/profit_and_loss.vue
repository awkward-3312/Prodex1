<template>
  <!-- NEW FEATURE - SAFE ADDITION -->
  <div class="px-next pxac">
    <px-page-header :title="$t('Profit_Loss_Title')" :subtitle="$t('Profit_Loss_Subtitle')" />

    <div class="pxac__kpis pxac__kpis--3">
      <px-stat bordered :label="$t('Income')" :value="toMoney(summary.income)" />
      <px-stat bordered :label="$t('Expense')" :value="toMoney(summary.expense)" />
      <px-stat bordered :label="$t('Net_Profit')" :value="toMoney(summary.net_profit)"
        :sub="summary.net_profit >= 0 ? ($t('Profit') || 'Ganancia') : ($t('Loss') || 'Pérdida')" />
    </div>

    <px-card class="pxac__filtercard">
      <div class="pxac__filterrow">
        <px-field :label="$t('From')">
          <template #default="{ id }"><px-input :id="id" type="date" v-model="filters.from" /></template>
        </px-field>
        <px-field :label="$t('To')">
          <template #default="{ id }"><px-input :id="id" type="date" v-model="filters.to" /></template>
        </px-field>
        <px-field :label="$t('Type')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="filters.type" :reduce="o => o.value" :clearable="false"
              :options="typeOptions" />
          </template>
        </px-field>
        <px-button variant="secondary" icon="refresh-cw" @click="Get_PL(1)">{{ $t('Apply') }}</px-button>
      </div>
    </px-card>

    <div v-if="isLoading" class="pxac__pad">
      <px-skeleton variant="table" :rows="10" :columns="4" />
    </div>

    <template v-else>
      <div class="pxac__tablewrap">
        <px-table
          v-if="rows.length"
          :columns="columns"
          :rows="rows"
          row-key="code"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          @sort="onSort"
        >
          <template #cell-amount="{ row }"><span class="pxn-num">{{ toMoney(row.amount) }}</span></template>
        </px-table>

        <px-empty-state v-else icon="wallet" :title="$t('No_Data') || 'Sin datos'" />
      </div>

      <div v-if="rows.length" class="pxac__pltotals">
        <div class="pxac__pltotal"><span>{{ $t('Total_Income') }}</span><span class="pxn-num">{{ toMoney(summary.income) }}</span></div>
        <div class="pxac__pltotal"><span>{{ $t('Total_Expense') }}</span><span class="pxn-num">{{ toMoney(summary.expense) }}</span></div>
        <div class="pxac__pltotal pxac__pltotal--net"><span>{{ $t('Net_Profit') }}</span><span class="pxn-num">{{ toMoney(summary.net_profit) }}</span></div>
      </div>

      <px-pagination
        v-if="rows.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>
  </div>
</template>

<script>
import NProgress from "nprogress";
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting,
  getPriceDecimals
} from "../../../../../utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxStat from "@/components/px-next/PxStat.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  name: "ProfitLossV2",
  components: { PxPageHeader, PxCard, PxStat, PxField, PxInput, PxButton, PxTable, PxPagination, PxEmptyState, "vs-px": VsPx },
  data() {
    return {
      isLoading: true,
      rows: [],
      totalRows: "",
      serverParams: {
        columnFilters: {},
        sort: { field: "code", type: "asc" },
        page: 1,
        perPage: 10
      },
      search: "",
      limit: "10",
      filters: { from: "", to: "", type: "" },
      summary: { income: 0, expense: 0, net_profit: 0 },
      // Optional price format key for frontend display (loaded from system settings/localStorage)
      price_format_key: null
    };
  },
  computed: {
    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    typeOptions() {
      return [
        { label: this.$t('All'), value: "" },
        { label: this.$t('Income'), value: "income" },
        { label: this.$t('Expense'), value: "expense" }
      ];
    },
    columns() {
      return [
        { key: 'code', label: this.$t('Code'), sortable: true, strong: true },
        { key: 'name', label: this.$t('Name') },
        { key: 'type', label: this.$t('Type') },
        { key: 'amount', label: this.$t('Amount'), align: 'right', sortable: true },
      ];
    }
  },
  created() { this.Get_PL(1); },
  methods: {
    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_PL(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_PL(1); } },
    onSort({ key, dir }) { this.updateParams({ sort: { type: dir, field: key } }); this.Get_PL(this.serverParams.page); },
    async Get_PL(page) {
      NProgress.start(); NProgress.set(0.1);
      axios.get(
        "/accounting/v2/reports/profit-loss?page=" + page +
        "&SortField=" + this.serverParams.sort.field +
        "&SortType=" + this.serverParams.sort.type +
        "&search=" + this.search +
        "&limit=" + this.limit +
        "&from=" + (this.filters.from || "") +
        "&to=" + (this.filters.to || "") +
        "&type=" + (this.filters.type || "")
      )
      .then(({data}) => {
        this.rows = (data && (data.data || [])) || [];
        this.totalRows = (data && (data.totalRows ?? data.total ?? this.rows.length)) || 0;
        if (data && data.summary) this.summary = data.summary;
        NProgress.done(); this.isLoading = false;
      })
      .catch(() => { NProgress.done(); this.isLoading = false; });
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
        return n.toLocaleString(undefined, { minimumFractionDigits: this.priceDecimals, maximumFractionDigits: this.priceDecimals });
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxac { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxac { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxac__pad { padding: var(--pxn-space-6) 0; }
.pxac__kpis { display: grid; gap: var(--pxn-space-5); margin: var(--pxn-space-5) 0; }
.pxac__kpis--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
@media (max-width: 720px) { .pxac__kpis--3 { grid-template-columns: minmax(0, 1fr); } }
.pxac__filtercard { margin-top: var(--pxn-space-5); }
.pxac__filterrow { display: flex; align-items: flex-end; gap: var(--pxn-space-5); flex-wrap: wrap; }
.pxac__filterrow ::v-deep .pxn-field { min-width: 160px; }
.pxac__tablewrap { margin-top: var(--pxn-space-5); }
.pxac__pltotals { margin-top: var(--pxn-space-3); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); overflow: hidden; }
.pxac__pltotal { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-4); padding: var(--pxn-space-3) var(--pxn-space-5); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); border-bottom: 1px solid var(--pxn-border); }
.pxac__pltotal:last-child { border-bottom: 0; }
.pxac__pltotal--net { background: var(--pxn-surface); }
</style>
