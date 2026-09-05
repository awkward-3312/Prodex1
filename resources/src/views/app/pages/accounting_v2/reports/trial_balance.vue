<template>
  <!-- NEW FEATURE - SAFE ADDITION -->
  <div class="px-next pxac">
    <px-page-header :title="$t('Trial_Balance_Title')" :subtitle="$t('Trial_Balance_Subtitle')" />

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
        <px-button variant="secondary" icon="refresh-cw" @click="Get_TB(1)">{{ $t('Apply') }}</px-button>
      </div>
    </px-card>

    <div v-if="isLoading" class="pxac__pad">
      <px-skeleton variant="table" :rows="10" :columns="5" />
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
          <template #cell-debit="{ row }"><span class="pxn-num">{{ toMoney(row.debit) }}</span></template>
          <template #cell-credit="{ row }"><span class="pxn-num">{{ toMoney(row.credit) }}</span></template>
        </px-table>

        <px-empty-state v-else icon="scale" :title="$t('No_Data') || 'Sin datos'" />
      </div>

      <div v-if="rows.length" class="pxac__totalrow">
        <span>{{ $t('Total') }}</span>
        <span class="pxn-num">{{ toMoney(pageTotalDebit) }}</span>
        <span class="pxn-num">{{ toMoney(pageTotalCredit) }}</span>
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
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  name: "TrialBalanceV2",
  components: { PxPageHeader, PxCard, PxField, PxInput, PxButton, PxTable, PxPagination, PxEmptyState, "vs-px": VsPx },
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
        { label: this.$t('Asset'), value: "asset" },
        { label: this.$t('Liability'), value: "liability" },
        { label: this.$t('Equity'), value: "equity" },
        { label: this.$t('Income'), value: "income" },
        { label: this.$t('Expense'), value: "expense" }
      ];
    },
    columns() {
      return [
        { key: 'code', label: this.$t('Code'), sortable: true, strong: true },
        { key: 'name', label: this.$t('Name') },
        { key: 'type', label: this.$t('Type') },
        { key: 'debit', label: this.$t('Debit'), align: 'right', sortable: true },
        { key: 'credit', label: this.$t('Credit'), align: 'right', sortable: true },
      ];
    },
    pageTotalDebit() { return this.rows.reduce((a,b)=>a+Number(b.debit||0),0); },
    pageTotalCredit() { return this.rows.reduce((a,b)=>a+Number(b.credit||0),0); }
  },
  created() { this.Get_TB(1); },
  methods: {
    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_TB(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_TB(1); } },
    onSort({ key, dir }) { this.updateParams({ sort: { type: dir, field: key } }); this.Get_TB(this.serverParams.page); },
    async Get_TB(page) {
      NProgress.start(); NProgress.set(0.1);
      axios.get(
        "/accounting/v2/reports/trial-balance?page=" + page +
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
.pxac__filtercard { margin-top: var(--pxn-space-5); }
.pxac__filterrow { display: flex; align-items: flex-end; gap: var(--pxn-space-5); flex-wrap: wrap; }
.pxac__filterrow ::v-deep .pxn-field { min-width: 160px; }
.pxac__tablewrap { margin-top: var(--pxn-space-5); }
.pxac__totalrow {
  display: grid; grid-template-columns: 1fr auto auto; gap: var(--pxn-space-6);
  align-items: center; justify-items: end;
  margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-5);
  border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2);
  font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink);
}
.pxac__totalrow > span:first-child { justify-self: start; }
.pxac__totalrow .pxn-num { min-width: 96px; text-align: right; }
</style>
