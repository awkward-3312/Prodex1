<template>
  <div class="px-next pxrl">
    <px-page-header :title="`${$t('Attendance')} · ${$t('Report')}`" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('Attendance') }]">
      <template #actions>
        <px-button variant="secondary" size="sm" icon="printer" @click="printTableOnly()">{{ $t('print') }}</px-button>
      </template>
      <template #meta>
        <span v-if="rangeText">{{ rangeText }}</span>
      </template>
    </px-page-header>

    <px-card class="pxrl__filtercard">
      <div class="pxrl__filtergrid">
        <px-field :label="$t('Report')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="filter.scope" :reduce="o => o.value" :clearable="false"
              :options="scopeOptions" @input="fetchReport(1)" />
          </template>
        </px-field>

        <px-field v-if="filter.scope === 'daily'" :label="$t('date')">
          <template #default="{ id }"><px-input :id="id" type="date" v-model="filter.date" @input="fetchReport(1)" /></template>
        </px-field>

        <px-field v-if="filter.scope === 'monthly'" label="Mes">
          <template #default="{ id }"><px-input :id="id" type="month" v-model="filter.month" @input="fetchReport(1)" /></template>
        </px-field>

        <px-field :label="$t('Company')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="filter.company_id" :reduce="o => o.value" :placeholder="$t('Company')"
              :options="companies.map(c => ({ label: c.name, value: c.id }))" @input="fetchReport(1)" />
          </template>
        </px-field>

        <px-field :label="$t('Employee')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="filter.employee_id" :reduce="o => o.value" :placeholder="$t('Choose_Employee')"
              :options="employees.map(e => ({ label: e.username, value: e.id }))" @input="fetchReport(1)" />
          </template>
        </px-field>

        <div class="pxrl__filteract">
          <px-button variant="primary" icon="filter" @click="fetchReport(1)">{{ $t('Filter') }}</px-button>
          <px-button variant="ghost" icon="x" @click="resetFilter">{{ $t('Reset') }}</px-button>
        </div>
      </div>
    </px-card>

    <div v-if="isLoading" class="pxrl__pad">
      <px-skeleton variant="lines" :rows="8" />
    </div>

    <template v-else>
      <px-card :title="`${$t('Attendance')} · ${$t('Report')}`" class="pxrl__chartcard">
        <apexchart type="bar" height="300" :options="apexOptions" :series="apexSeries" />
      </px-card>

      <px-toolbar
        :search="search"
        :search-placeholder="$t('Search_this_table')"
        @update:search="onSearchInput"
      />

      <div class="pxrl__tablewrap">
        <px-table
          v-if="rows.length"
          :columns="columns"
          :rows="rows"
          row-key="__rowkey"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          @sort="onSort"
        />

        <px-empty-state v-else icon="clock" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
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
import NProgress from 'nprogress';
import VueApexCharts from 'vue-apexcharts';
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: 'Attendance Report' },
  components: {
    apexchart: VueApexCharts,
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxCard,
    PxField, PxInput, PxEmptyState, "vs-px": VsPx
  },
  data() {
    const today = new Date().toISOString().slice(0, 10);
    return {
      _searchTimer: null,
      isLoading: true,
      companies: [],
      employees: [],
      rows: [],
      totalRows: 0,
      limit: '10',
      search: '',
      from: '',
      to: '',
      serverParams: {
        sort: { field: 'employee_username', type: 'asc' },
        page: 1,
        perPage: 10,
      },
      filter: {
        scope: 'daily',
        date: today,
        month: '',
        company_id: '',
        employee_id: '',
      },
      scopeOptions: [
        { value: 'daily', label: this.$t('Daily') },
        { value: 'monthly', label: 'Mensual' },
      ],
      apexOptions: {
        chart: { toolbar: { show: false } },
        plotOptions: { bar: { horizontal: false, columnWidth: '45%', endingShape: 'rounded' } },
        dataLabels: { enabled: false },
        stroke: { show: true, width: 2, colors: ['transparent'] },
        xaxis: { categories: [] },
        yaxis: { labels: { formatter: (val) => `${Number(val).toFixed(1)}h` } },
        tooltip: { y: { formatter: (val) => `${Number(val).toFixed(2)} h` } },
        legend: { show: false },
      },
      apexSeries: [{ name: 'Hours', data: [] }],
    };
  },
  computed: {
    columns() {
      return [
        { key: 'employee_username', label: this.$t('Employee'), strong: true },
        { key: 'company_name', label: this.$t('Company') },
        { key: 'total_work', label: this.$t('Work_Duration') },
      ];
    },
    rangeText() {
      if (this.from && this.to) return `${this.from} → ${this.to}`;
      return '';
    }
  },
  methods: {
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.fetchReport(1); }, 350);
    },
    onPage(p) {
      if (this.serverParams.page !== p) {
        this.updateParams({ page: p });
        this.fetchReport(p);
      }
    },
    onLimit(v) {
      if (this.limit !== String(v)) {
        this.limit = String(v);
        this.updateParams({ page: 1, perPage: Number(v) });
        this.fetchReport(1);
      }
    },
    onSort({ key, dir }) {
      this.updateParams({ sort: { type: dir, field: key } });
      this.fetchReport(this.serverParams.page);
    },
    resetFilter() {
      const today = new Date().toISOString().slice(0, 10);
      this.filter.scope = 'daily';
      this.filter.date = today;
      this.filter.month = '';
      this.filter.company_id = '';
      this.filter.employee_id = '';
      this.fetchReport(1);
    },

    //------ Print Table Only - Print ALL attendance data with all columns
    printTableOnly() {
      const title = `${this.$t("Reports")} / ${this.$t("Attendance")}`;
      const rows = Array.isArray(this.rows) ? this.rows : [];

      let tableHTML = '<table style="width: 100%; border-collapse: collapse; font-size: 10px;">';
      tableHTML += '<thead><tr>';

      this.columns.forEach(col => {
        tableHTML += `<th style="border: 1px solid #ddd; padding: 6px 8px; background-color: #f5f5f5; font-weight: bold; text-align: left;">${col.label}</th>`;
      });
      tableHTML += '</tr></thead><tbody>';

      rows.forEach(row => {
        tableHTML += '<tr>';
        this.columns.forEach(col => {
          const cellValue = row[col.key] || '';
          tableHTML += `<td style="border: 1px solid #ddd; padding: 6px 8px; text-align: left;">${cellValue}</td>`;
        });
        tableHTML += '</tr>';
      });

      tableHTML += '</tbody></table>';

      const w = window.open("", "_blank");
      if (!w) {
        alert("Please allow popups to print");
        return;
      }

      const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
        .map(l => l.outerHTML)
        .join("\n");

      const doc = w.document;
      doc.open();
      doc.write(`<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <base href="${window.location.origin}/" />
    <title>${title}</title>
    ${links}
    <style>
      @media print {
        body, body * { visibility: visible !important; }
        @page { size: A4 landscape; margin: 0.3cm; }
      }
      body { margin: 0.3cm; font-family: Arial, sans-serif; }
      .print-header { font-weight: 600; margin-bottom: 10px; font-size: 14px; }
      table { width: 100%; border-collapse: collapse; }
      th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; font-size: 10px; }
      th { background-color: #f5f5f5; font-weight: bold; }
      tr:nth-child(even) { background-color: #f9f9f9; }
    </style>
  </head>
  <body>
    <div class="print-header">${title}${this.rangeText ? ' - ' + this.rangeText : ''}</div>
    ${tableHTML}
  </body>
</html>`);
      doc.close();

      w.focus();
      setTimeout(() => {
        w.print();
        w.close();
      }, 400);
    },
    fetchReport(page) {
      NProgress.start();
      NProgress.set(0.1);

      const params = new URLSearchParams();
      params.set('page', page);
      params.set('limit', this.limit);
      params.set('SortField', this.serverParams.sort.field);
      params.set('SortType', this.serverParams.sort.type);
      params.set('scope', this.filter.scope);
      if (this.filter.scope === 'daily') {
        params.set('date', this.filter.date);
      } else {
        if (this.filter.month) params.set('month', this.filter.month);
      }
      if (this.filter.company_id) params.set('company_id', this.filter.company_id);
      if (this.filter.employee_id) params.set('employee_id', this.filter.employee_id);
      if (this.search) params.set('search', this.search);

      this.isLoading = true;
      axios
        .get(`/report/attendance_summary?${params.toString()}`)
        .then(({ data }) => {
          this.rows = (data.report || []).map((r, i) => Object.assign({ __rowkey: `a-${i}` }, r));
          this.totalRows = data.totalRows || 0;
          this.companies = data.companies || [];
          this.employees = data.employees || [];
          this.from = data.from || '';
          this.to = data.to || '';

          const categories = (this.rows || []).map(r => r.employee_username);
          const values = (this.rows || []).map(r => Number(r.total_hours || 0));
          this.apexOptions = Object.assign({}, this.apexOptions, { xaxis: { categories } });
          this.apexSeries = [{ name: 'Hours', data: values }];
        })
        .finally(() => {
          NProgress.done();
          this.isLoading = false;
        });
    },
  },
  created() {
    this.fetchReport(1);
  },
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrl__pad { padding: var(--pxn-space-6) 0; }
.pxrl__filtercard { margin-top: var(--pxn-space-5); }
.pxrl__filtergrid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); align-items: end; }
@media (max-width: 1000px) { .pxrl__filtergrid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 520px) { .pxrl__filtergrid { grid-template-columns: minmax(0, 1fr); } }
.pxrl__filteract { display: flex; align-items: flex-end; gap: var(--pxn-space-3); }
.pxrl__chartcard { margin-top: var(--pxn-space-5); }
.pxrl__tablewrap { margin-top: var(--pxn-space-5); }
</style>
