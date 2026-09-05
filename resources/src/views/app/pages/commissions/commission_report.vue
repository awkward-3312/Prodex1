<template>
  <div class="px-next pxcm">
    <px-page-header :title="$t('Commission_Report')" :breadcrumbs="[{ label: $t('Commissions') }, { label: $t('Commission_Report') }]" />

    <!-- Filters -->
    <px-card :title="$t('Filters')" class="pxcm__gap">
      <template #actions>
        <px-button variant="secondary" size="sm" icon="refresh-cw" @click="applyFilters">{{ $t('Refresh') }}</px-button>
      </template>
      <div class="pxcm__formgrid pxcm__formgrid--4">
        <px-field :label="$t('Date_From')">
          <template #default="{ id }"><px-input :id="id" type="date" v-model="filterDateFrom" /></template>
        </px-field>
        <px-field :label="$t('Date_To')">
          <template #default="{ id }"><px-input :id="id" type="date" v-model="filterDateTo" /></template>
        </px-field>
        <px-field :label="$t('Sales_Agent')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="filterAgentId" :reduce="a => a.id" :options="agentsList" label="name" :placeholder="$t('All')" />
          </template>
        </px-field>
        <px-field :label="$t('Status')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="filterStatus" :reduce="o => o.value" :clearable="false"
              :options="statusOptions" />
          </template>
        </px-field>
      </div>
    </px-card>

    <!-- Summary stat cards -->
    <div v-if="summary" class="pxcm__kpis pxcm__gap">
      <px-stat bordered :label="$t('Pending')" :value="formatMoney(summary.totals && summary.totals.pending_total)" icon="clock" />
      <px-stat bordered :label="$t('Approved')" :value="formatMoney(summary.totals && summary.totals.approved_total)" icon="check" />
      <px-stat bordered :label="$t('Paid')" :value="formatMoney(summary.totals && summary.totals.paid_total)" icon="wallet" />
      <px-stat bordered :label="$t('Total')" :value="formatMoney(summary.totals && summary.totals.grand_total)" icon="bar-chart" />
    </div>

    <!-- Charts -->
    <div class="pxcm__charts pxcm__gap">
      <px-card :title="`${$t('Commission_Report')} — ${$t('By_Status')}`">
        <div v-if="isChartsLoading" class="pxcm__chartload"><px-skeleton variant="lines" :rows="6" /></div>
        <apexchart
          v-else-if="chartStatus.series.length && chartStatus.series.some(s => s > 0)"
          :key="chartStatusKey"
          type="donut"
          height="320"
          :options="chartStatus.options"
          :series="chartStatus.series"
        />
        <px-empty-state v-else icon="pie-chart" :title="$t('No_Data')" />
      </px-card>
      <px-card :title="`${$t('Commission_Report')} — ${$t('By_Agent')}`">
        <div v-if="isChartsLoading" class="pxcm__chartload"><px-skeleton variant="lines" :rows="6" /></div>
        <apexchart
          v-else-if="chartByAgent.series[0].data.length"
          :key="chartByAgentKey"
          type="bar"
          height="320"
          :options="chartByAgent.options"
          :series="chartByAgent.series"
        />
        <px-empty-state v-else icon="bar-chart-2" :title="$t('No_Data')" />
      </px-card>
    </div>

    <!-- Table -->
    <div v-if="isLoading" class="pxcm__pad">
      <px-skeleton variant="table" :rows="10" :columns="7" />
    </div>
    <template v-else>
      <div v-if="selectedIds.length && (currentUserPermissions && currentUserPermissions.includes('commissions_edit'))" class="pxcm__bulkbar">
        <span class="pxcm__bulkcount">{{ selectedIds.length }} {{ $t('Selected') || 'seleccionados' }}</span>
        <px-button variant="secondary" size="sm" icon="check" @click="approveSelected">{{ $t('Approve') || 'Aprobar' }}</px-button>
        <px-button variant="secondary" size="sm" icon="x" @click="cancelSelected">{{ $t('Cancel') }}</px-button>
      </div>

      <div class="pxcm__tablewrap">
        <px-table
          v-if="commissions.length"
          :columns="columns"
          :rows="commissions"
          row-key="id"
          selectable
          :selected="selectedIds"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          @update:selected="onSelected"
          @sort="onSort"
        >
          <template #cell-sale_ref="{ row }">{{ row.sale ? row.sale.Ref : '—' }}</template>
          <template #cell-agent="{ row }">{{ row.sales_agent ? row.sales_agent.name : '—' }}</template>
          <template #cell-program="{ row }">{{ row.commission_program ? row.commission_program.name : '—' }}</template>
          <template #cell-base_amount="{ row }"><span class="pxn-num">{{ formatMoney(row.base_amount) }}</span></template>
          <template #cell-commission_amount="{ row }"><span class="pxn-num">{{ formatMoney(row.commission_amount) }}</span></template>
          <template #cell-status="{ row }">
            <px-badge :tone="statusTone(row.status)">{{ row.status }}</px-badge>
          </template>
          <template #cell-calculated_at="{ row }">{{ formatDate(row.calculated_at) }}</template>
        </px-table>

        <px-empty-state v-else icon="database" :title="$t('No_Data') || 'Sin datos'" />
      </div>

      <px-pagination
        v-if="commissions.length"
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
import { mapGetters } from 'vuex';
import NProgress from 'nprogress';
import VueApexCharts from 'vue-apexcharts';
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxStat from "@/components/px-next/PxStat.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  components: {
    apexchart: VueApexCharts, PxPageHeader, PxCard, PxStat, PxField, PxInput,
    PxButton, PxTable, PxPagination, PxBadge, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      isLoading: true,
      isChartsLoading: true,
      commissions: [],
      totalRows: 0,
      summary: null,
      byAgent: [],
      serverParams: { sort: { field: 'calculated_at', type: 'desc' }, page: 1, perPage: 10 },
      limit: '10',
      filterDateFrom: '',
      filterDateTo: '',
      filterAgentId: null,
      filterStatus: '',
      agentsList: [],
      selectedIds: [],
      chartStatus: {
        series: [],
        options: {
          chart: { type: 'donut', fontFamily: 'inherit' },
          labels: [],
          colors: ['#f59e0b', '#3b82f6', '#10b981', '#6b7280'],
          legend: { position: 'bottom', fontSize: '14px' },
          dataLabels: { enabled: true, formatter: (val) => `${Number(val || 0).toFixed(1)}%` },
          tooltip: { y: { formatter: (val) => this.money(val) } },
          plotOptions: {
            pie: {
              donut: {
                size: '65%',
                labels: {
                  show: true,
                  name: { show: true, fontSize: '14px', fontWeight: 600 },
                  value: { show: true, fontSize: '18px', fontWeight: 700, formatter: (val) => this.money(val) },
                  total: { show: true, label: this.$t('Total'), formatter: () => this.money(this.statusTotal) },
                },
              },
            },
          },
        },
      },
      chartByAgent: {
        series: [{ name: 'Commission', data: [] }],
        options: {
          chart: { type: 'bar', fontFamily: 'inherit', toolbar: { show: false } },
          plotOptions: { bar: { borderRadius: 4, horizontal: true, barHeight: '70%' } },
          dataLabels: { enabled: true, formatter: (val) => this.money(val) },
          xaxis: { categories: [] },
          colors: ['#6366f1'],
          grid: { xaxis: { lines: { show: false } } },
          tooltip: { y: { formatter: (val) => this.money(val) } },
        },
      },
    };
  },
  computed: {
    ...mapGetters(['currentUserPermissions']),
    statusOptions() {
      return [
        { label: this.$t('All'), value: '' },
        { label: this.$t('Pending'), value: 'pending' },
        { label: this.$t('Approved'), value: 'approved' },
        { label: this.$t('Paid'), value: 'paid' },
        { label: this.$t('Cancelled'), value: 'cancelled' },
      ];
    },
    statusTotal() {
      return (this.chartStatus.series || []).reduce((a, b) => a + (Number(b) || 0), 0);
    },
    chartStatusKey() {
      const parts = [
        this.filterDateFrom || '',
        this.filterDateTo || '',
        this.filterAgentId || '',
        (this.chartStatus.series || []).join(','),
      ];
      return `cs-${parts.join('|')}`;
    },
    chartByAgentKey() {
      const n = (this.byAgent || []).length;
      const names = (this.byAgent || []).slice(0, 10).map(a => a.name || a.code || '').join('|');
      const parts = [this.filterDateFrom || '', this.filterDateTo || '', n, names];
      return `ca-${parts.join('|')}`;
    },
    columns() {
      return [
        { key: 'sale_ref', label: this.$t('sale_ref') },
        { key: 'agent', label: this.$t('Sales_Agent') },
        { key: 'program', label: this.$t('Program') },
        { key: 'base_amount', label: this.$t('Base_Amount'), align: 'right' },
        { key: 'commission_amount', label: this.$t('Commission'), align: 'right' },
        { key: 'status', label: this.$t('Status') },
        { key: 'calculated_at', label: this.$t('Calculated_At') },
      ];
    },
  },
  created() {
    axios.get('sales_agents_list_for_select').then((res) => {
      const d = res.data.data || res.data;
      this.agentsList = Array.isArray(d) ? d : (d.agents || []);
    });
    this.loadAllCharts();
    this.load();
  },
  methods: {
    applyFilters() {
      this.loadAllCharts();
      this.load(1);
    },
    loadAllCharts() {
      this.isChartsLoading = true;
      return Promise.all([this.loadSummary(), this.loadCharts()]).finally(() => {
        this.isChartsLoading = false;
      });
    },
    load(page) {
      page = page || 1;
      NProgress.start();
      const params = { page, limit: this.limit, SortField: this.serverParams.sort.field, SortType: this.serverParams.sort.type };
      if (this.filterDateFrom) params.date_from = this.filterDateFrom;
      if (this.filterDateTo) params.date_to = this.filterDateTo;
      if (this.filterAgentId) params.sales_agent_id = this.filterAgentId;
      if (this.filterStatus) params.status = this.filterStatus;
      axios.get('commission_report', { params }).then((res) => {
        const d = res.data.data || res.data;
        this.commissions = d.commissions || [];
        this.totalRows = d.totalRows || 0;
        NProgress.done();
        this.isLoading = false;
      }).catch(() => { NProgress.done(); this.isLoading = false; });
    },
    loadSummary() {
      const params = {};
      if (this.filterDateFrom) params.date_from = this.filterDateFrom;
      if (this.filterDateTo) params.date_to = this.filterDateTo;
      if (this.filterAgentId) params.sales_agent_id = this.filterAgentId;
      return axios.get('commission_report/summary', { params }).then((res) => {
        this.summary = res.data.data || res.data;
        this.buildStatusChart();
      });
    },
    loadCharts() {
      const params = {};
      if (this.filterDateFrom) params.date_from = this.filterDateFrom;
      if (this.filterDateTo) params.date_to = this.filterDateTo;
      return axios.get('commission_report/by_agent', { params }).then((res) => {
        const d = res.data.data || res.data;
        this.byAgent = d.by_agent || [];
        this.buildByAgentChart();
      });
    },
    buildStatusChart() {
      const t = this.summary && this.summary.totals;
      if (!t) {
        this.chartStatus.series = [];
        this.chartStatus.options.labels = [];
        return;
      }
      const labels = [this.$t('Pending'), this.$t('Approved'), this.$t('Paid'), this.$t('Cancelled')];
      this.chartStatus.series = [
        Number(t.pending_total) || 0,
        Number(t.approved_total) || 0,
        Number(t.paid_total) || 0,
        Number((this.summary.by_status && this.summary.by_status.cancelled && this.summary.by_status.cancelled.total) || 0),
      ];
      this.chartStatus.options = { ...this.chartStatus.options, labels };
    },
    buildByAgentChart() {
      const agents = this.byAgent || [];
      this.chartByAgent.series = [{ name: this.$t('Commission'), data: agents.map(a => Number(a.total_commission) || 0) }];
      this.chartByAgent.options = {
        ...this.chartByAgent.options,
        xaxis: { ...this.chartByAgent.options.xaxis, categories: agents.map(a => a.name || a.code || '—') },
      };
    },
    onSelected(ids) {
      this.selectedIds = ids || [];
    },
    onPage(p) { if (this.serverParams.page !== p) { this.serverParams.page = p; this.load(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.load(1); } },
    onSort({ key, dir }) { this.serverParams.sort = { field: key, type: dir }; this.load(1); },
    formatDate(v) { return v ? new Date(v).toLocaleString() : '—'; },
    formatMoney(v) { return v != null ? Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 }) : '0.00'; },
    money(v) { return this.formatMoney(v); },
    statusTone(s) { return { pending: 'warning', approved: 'info', paid: 'success', cancelled: 'neutral' }[s] || 'neutral'; },
    approveSelected() {
      if (!this.selectedIds.length) return;
      axios.post('commissions/approve', { commission_ids: this.selectedIds }).then(() => { this.makeToast('success', this.$t('Success')); this.selectedIds = []; this.load(this.serverParams.page); this.loadSummary(); this.loadCharts(); }).catch((e) => this.makeToast('danger', (e.response && e.response.data && e.response.data.message) || this.$t('Error')));
    },
    cancelSelected() {
      if (!this.selectedIds.length) return;
      this.$bvModal.msgBoxConfirm(this.$t('Confirm_delete')).then((ok) => {
        if (ok) axios.post('commissions/cancel', { commission_ids: this.selectedIds }).then(() => { this.makeToast('success', this.$t('Success')); this.selectedIds = []; this.load(this.serverParams.page); this.loadSummary(); this.loadCharts(); }).catch((e) => this.makeToast('danger', (e.response && e.response.data && e.response.data.message) || this.$t('Error')));
      });
    },
    makeToast(variant, msg) { this.$bvToast.toast(msg, { title: this.$t('Notice'), variant, solid: true }); },
  },
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcm { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcm { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcm__pad { padding: var(--pxn-space-6) 0; }
.pxcm__gap { margin-top: var(--pxn-space-5); }
.pxcm__tablewrap { margin-top: var(--pxn-space-4); }
.pxcm__formgrid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
.pxcm__formgrid--4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
@media (max-width: 900px) { .pxcm__formgrid--4 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 520px) { .pxcm__formgrid, .pxcm__formgrid--4 { grid-template-columns: minmax(0, 1fr); } }
.pxcm__kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxcm__kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 480px) { .pxcm__kpis { grid-template-columns: minmax(0, 1fr); } }
.pxcm__charts { display: grid; grid-template-columns: 5fr 7fr; gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxcm__charts { grid-template-columns: minmax(0, 1fr); } }
.pxcm__chartload { padding: var(--pxn-space-6) 0; }
.pxcm__bulkbar { display: flex; align-items: center; gap: var(--pxn-space-3); margin-top: var(--pxn-space-5); padding: var(--pxn-space-3) var(--pxn-space-4); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); }
.pxcm__bulkcount { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); margin-right: auto; }
</style>
