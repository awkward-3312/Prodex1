<template>
  <div class="px-next pxrl">
    <px-page-header :title="$t('Cash_Flow_Report')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('Cash_Flow_Report') }]">
      <template #actions>
        <px-menu :items="exportMenu" align="end" @select="onExport">
          <template #trigger>
            <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button>
          </template>
        </px-menu>
      </template>
    </px-page-header>

    <px-card class="pxrl__filtercard">
      <div class="pxrl__filterrow">
        <div class="pxrl__field">
          <label class="pxrl__label">{{ $t('DateRange') }}</label>
          <date-range-picker
            v-model="dateRange"
            :locale-data="locale"
            :autoApply="true"
            :showDropdowns="true"
            :opens="isMobile ? 'center' : 'right'"
            :drops="'down'"
            @update="fetchReport"
          >
            <template v-slot:input="picker">
              <button type="button" class="pxrl__daterange pxn-ring">
                <lucide-icon name="calendar-days" :size="14" />
                {{ fmt(picker.startDate) }} — {{ fmt(picker.endDate) }}
              </button>
            </template>
          </date-range-picker>
        </div>
        <div class="pxrl__field pxrl__field--sm">
          <label class="pxrl__label">{{ $t('GroupBy') }}</label>
          <vs-px v-model="groupBy" :reduce="o => o.value" :clearable="false"
            :options="[{ label: $t('Account'), value: 'account' }, { label: $t('PaymentMethod'), value: 'method' }]"
            @input="onGroupChange" />
        </div>
        <div class="pxrl__field pxrl__field--sm">
          <label class="pxrl__label">{{ $t('warehouse') }}</label>
          <vs-px v-model="warehouseId" :reduce="o => o.value" :placeholder="$t('AllWarehouses')"
            :options="warehouses.map(w => ({ label: w.name, value: w.id }))" @input="fetchReport" />
        </div>
        <div class="pxrl__field pxrl__field--sm" v-if="groupBy === 'account'">
          <label class="pxrl__label">{{ $t('Account') }}</label>
          <vs-px v-model="accountId" :reduce="o => o.value" :placeholder="$t('AllAccounts')"
            :options="accounts.map(a => ({ label: a.account_name, value: a.id }))" @input="fetchReport" />
        </div>
        <div class="pxrl__field pxrl__field--sm" v-else>
          <label class="pxrl__label">{{ $t('PaymentMethod') }}</label>
          <vs-px v-model="paymentMethodId" :reduce="o => o.value" :placeholder="$t('AllPaymentMethods')"
            :options="payment_methods.map(m => ({ label: m.name, value: m.id }))" @input="fetchReport" />
        </div>
      </div>
    </px-card>

    <div v-if="isLoading" class="pxrl__pad">
      <px-skeleton variant="lines" :rows="8" />
    </div>

    <div v-else>
      <div class="pxrl__kpis pxrl__kpis--3">
        <px-stat bordered icon="trending-up" :label="$t('TotalInflow')" :value="money(totalInflow)" />
        <px-stat bordered icon="trending-down" :label="$t('TotalOutflow')" :value="money(totalOutflow)" />
        <px-stat bordered icon="wallet" :label="$t('NetCashFlow')" :value="money(netCashFlow)" />
      </div>

      <div class="pxrl__cols pxrl__gap">
        <px-card :title="$t('Inflow_vs_Outflow_by_Group')">
          <apexchart type="bar" height="300" :options="apexBarOptions" :series="apexBarSeries" />
        </px-card>
        <px-card :title="$t('NetCashFlowOverTime')">
          <apexchart type="line" height="300" :options="apexLineOptions" :series="apexLineSeries" />
        </px-card>
      </div>

      <px-toolbar
        :search="search"
        :search-placeholder="$t('Search_this_table')"
        @update:search="onSearchInput"
      />

      <div class="pxrl__tablewrap">
        <px-table
          v-if="filteredRows.length"
          :columns="columns"
          :rows="filteredRows"
          row-key="group"
        >
          <template #cell-inflow="{ row }"><span class="pxn-num">{{ money(row.inflow) }}</span></template>
          <template #cell-outflow="{ row }"><span class="pxn-num">{{ money(row.outflow) }}</span></template>
          <template #cell-net="{ row }"><span class="pxn-num">{{ money(row.net) }}</span></template>
        </px-table>

        <px-empty-state v-else icon="wallet" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
      </div>

      <div v-if="filteredRows.length" class="pxrl__totalrow">
        <span>{{ $t('Totals') }}</span>
        <span>{{ $t('TotalInflow') }}: <b class="pxn-num">{{ money(totalInflow) }}</b></span>
        <span>{{ $t('TotalOutflow') }}: <b class="pxn-num">{{ money(totalOutflow) }}</b></span>
        <span>{{ $t('NetCashFlow') }}: <b class="pxn-num">{{ money(netCashFlow) }}</b></span>
      </div>
    </div>
  </div>
</template>

<script>
import NProgress from "nprogress";
import moment from "moment";
import { mapGetters } from "vuex";
import DateRangePicker from "vue2-daterange-picker";
import "vue2-daterange-picker/dist/vue2-daterange-picker.css";
import VueApexCharts from "vue-apexcharts";
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting,
  getPriceDecimals
} from "../../../../utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxMenu from "@/components/px-next/PxMenu.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxStat from "@/components/px-next/PxStat.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: "Cash Flow Report" },
  components: {
    "date-range-picker": DateRangePicker, apexchart: VueApexCharts,
    PxPageHeader, PxToolbar, PxTable, PxButton, PxMenu, PxCard, PxStat, PxEmptyState, "vs-px": VsPx
  },

  data() {
    const end = new Date(); const start = new Date(); start.setDate(end.getDate() - 29);
    return {
      _searchTimer: null,
      isLoading: true,
      isMobile: false,
      search: '',

      dateRange: { startDate: start, endDate: end },
      locale: {
        Label: this.$t("Apply") || "Apply",
        cancelLabel: this.$t("Cancel") || "Cancel",
        weekLabel: "W",
        customRangeLabel: this.$t("CustomRange") || "Custom Range",
        daysOfWeek: moment.weekdaysMin(),
        monthNames: moment.monthsShort(),
        firstDay: 1
      },

      groupBy: 'account',
      warehouseId: null,
      accountId: null,
      paymentMethodId: null,

      warehouses: [],
      accounts: [],
      payment_methods: [],

      rows: [],
      totalInflow: 0,
      totalOutflow: 0,
      netCashFlow: 0,
      timeseries: [],
      price_format_key: null
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    currency(){ return (this.currentUser && this.currentUser.currency) || "USD"; },
    filteredRows() {
      const q = (this.search || '').toLowerCase().trim();
      if (!q) return this.rows || [];
      return (this.rows || []).filter(r => String(r.group || '').toLowerCase().includes(q));
    },
    exportMenu() {
      return [
        { key: "print", label: this.$t("print"), icon: "printer" },
        { key: "pdf", label: this.$t("Export_PDF") || "PDF", icon: "file-text" },
        { key: "xlsx", label: this.$t("EXCEL") || "CSV / Excel", icon: "file-spreadsheet" }
      ];
    },
    columns(){
      return [
        { key:'group', label: this.$t('Group'), strong: true },
        { key:'inflow', label: this.$t('Inflow'), align: 'right' },
        { key:'outflow', label: this.$t('Outflow'), align: 'right' },
        { key:'net', label: this.$t('Net'), align: 'right' }
      ];
    },

    apexBarOptions(){
      const cats = (this.rows||[]).map(r => r.group);
      return {
        chart: { type:'bar', stacked:true, toolbar:{ show:false } },
        plotOptions: { bar: { horizontal:true } },
        dataLabels: { enabled:false },
        xaxis: { categories: cats, labels:{ formatter:(v)=> this.compact(v) } },
        yaxis: { labels:{ show:true } },
        legend: { position:'top' },
        tooltip: { y: { formatter: (v)=> this.money(v) } },
        grid: { padding: { left: 8, right: 8 } }
      };
    },
    apexBarSeries(){
      const inflow = (this.rows||[]).map(r => Number(r.inflow||0));
      const outflow = (this.rows||[]).map(r => Number(r.outflow||0));
      return [
        { name: this.$t('Inflow'), data: inflow },
        { name: this.$t('Outflow'), data: outflow }
      ];
    },

    apexLineOptions(){
      const dates = (this.timeseries||[]).map(x => x.d);
      return {
        chart: { type:'line', toolbar:{ show:false } },
        stroke: { curve:'smooth', width:3 },
        dataLabels: { enabled:false },
        xaxis: { categories: dates, labels: { rotate:-45 } },
        yaxis: { labels: { formatter: (v) => this.compact(v) } },
        tooltip: { y: { formatter: (v)=> this.money(v) } },
        legend: { show: true }
      };
    },
    apexLineSeries(){
      return [
        { name: this.$t('Inflow'),  data: (this.timeseries||[]).map(x => Number(x.inflow||0)) },
        { name: this.$t('Outflow'), data: (this.timeseries||[]).map(x => Number(x.outflow||0)) },
        { name: this.$t('Net'),     data: (this.timeseries||[]).map(x => Number(x.net||0)) }
      ];
    },

    excelColumns(){
      return [
        { label: 'Group', field: 'group' },
        { label: 'Inflow', field: 'inflow' },
        { label: 'Outflow', field: 'outflow' },
        { label: 'Net', field: 'net' }
      ];
    },
    excelRows(){ return (this.rows||[]).map(r => ({ ...r })); }
  },

  methods: {
    onExport(item) {
      const k = item && item.key;
      if (k === "print") this.printTableOnly();
      else if (k === "pdf") this.exportPDF();
      else if (k === "xlsx") this.exportCsv();
    },

    exportCsv() {
      const head = this.columns.map(c => c.label);
      const lines = [head.join(",")].concat(
        (this.rows || []).map(r => this.columns.map(c => `"${String(r[c.key] == null ? "" : r[c.key]).replace(/"/g, '""')}"`).join(","))
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "Cash_Flow_Report.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    printTableOnly() {
      const title = `${this.$t("Reports")} / ${this.$t("Cash_Flow_Report")}`;
      let tableHTML = '<table style="width:100%; border-collapse:collapse; font-size:11px;">';
      tableHTML += '<thead><tr>';
      this.columns.forEach(col => {
        tableHTML += `<th style="border:1px solid #ddd; padding:6px; background:#f5f5f5; text-align:left;">${col.label}</th>`;
      });
      tableHTML += '</tr></thead><tbody>';
      (this.rows || []).forEach(r => {
        tableHTML += '<tr>';
        this.columns.forEach(col => {
          let v = r[col.key];
          if (['inflow','outflow','net'].includes(col.key)) v = this.money(v);
          tableHTML += `<td style="border:1px solid #ddd; padding:6px;">${v == null ? '' : v}</td>`;
        });
        tableHTML += '</tr>';
      });
      tableHTML += '</tbody>';
      tableHTML += `<tfoot><tr>
        <td style="border:1px solid #ddd; padding:6px; font-weight:bold;">${this.$t('Totals')}</td>
        <td style="border:1px solid #ddd; padding:6px; text-align:right; font-weight:bold;">${this.money(this.totalInflow)}</td>
        <td style="border:1px solid #ddd; padding:6px; text-align:right; font-weight:bold;">${this.money(this.totalOutflow)}</td>
        <td style="border:1px solid #ddd; padding:6px; text-align:right; font-weight:bold;">${this.money(this.netCashFlow)}</td>
      </tr></tfoot>`;
      tableHTML += '</table>';

      const w = window.open("", "_blank");
      if (!w) { window.print(); return; }
      const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map(l => l.outerHTML).join("\n");
      const doc = w.document;
      doc.open();
      doc.write(`<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <base href="${window.location.origin}/" />
    <title>${title}</title>
    ${links}
    <style>
      @media print { body, body * { visibility: visible !important; } @page { size: A4; margin: 1cm; } }
      body { margin: 0.3cm; font-family: Arial, sans-serif; }
      .print-header { font-weight: 600; margin-bottom: 8px; font-size: 14px; }
    </style>
  </head>
  <body>
    <div class="print-header">${title}</div>
    ${tableHTML}
  </body>
</html>`);
      doc.close();
      w.focus();
      setTimeout(() => { w.print(); w.close(); }, 400);
    },
    handleResize(){ this.isMobile = window.innerWidth < 576; },
    fmt(d){ return moment(d).format('YYYY-MM-DD'); },
    fmtShort(d){ return moment(d).format('MMM D'); },
    compact(v){
      try { return new Intl.NumberFormat(undefined,{ notation:'compact', maximumFractionDigits:1 }).format(Number(v||0)); }
      catch { return v; }
    },
    money(v){
      try {
        const n = Number(v || 0);
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        const effectiveKey = key || null;
        const formatted = formatPriceDisplayHelper(n, this.priceDecimals, effectiveKey);
        return `${this.currency} ${formatted}`;
      } catch(e) {
        try {
          return new Intl.NumberFormat(undefined,{ style:'currency', currency:this.currency }).format(Number(v||0));
        } catch(e2) {
          return `${this.currency} ${(Number(v||0)).toLocaleString()}`;
        }
      }
    },

    onSearchInput(v) { this.search = v; },

    onGroupChange(){ this.accountId = null; this.paymentMethodId = null; this.fetchReport(); },

    exportPDF(){
      const doc = new jsPDF({ orientation:'portrait', unit:'pt', format:'a4' });
      const pageW = doc.internal.pageSize.getWidth(); const marginX = 40;
      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      try { doc.addFont(fontPath, "Vazirmatn", "normal"); doc.addFont(fontPath, "Vazirmatn", "bold"); } catch(_) {}
      doc.setFont("Vazirmatn", "normal");
      const rtl = (this.$i18n && ['ar','fa','ur','he'].includes(this.$i18n.locale)) || (typeof document !== 'undefined' && document.documentElement.dir === 'rtl');
      const title = 'Cash Flow Report'; const range = `${this.fmt(this.dateRange.startDate)} — ${this.fmt(this.dateRange.endDate)}`;
      doc.setFont("Vazirmatn", "bold"); doc.setFontSize(14);
      rtl ? doc.text(title, pageW - marginX, 40, { align:'right' }) : doc.text(title, marginX, 40);
      doc.setFont("Vazirmatn", "normal"); doc.setFontSize(10);
      rtl ? doc.text(range, pageW - marginX, 58, { align:'right' }) : doc.text(range, marginX, 58);

      const head = [[ this.$t('Group'), this.$t('Inflow'), this.$t('Outflow'), this.$t('Net') ]];
      const body = (this.rows||[]).map(r => ([ r.group, Number(r.inflow||0).toFixed(this.priceDecimals), Number(r.outflow||0).toFixed(this.priceDecimals), Number(r.net||0).toFixed(this.priceDecimals) ]));

      autoTable(doc, {
        startY: 80,
        head, body,
        styles: { font:"Vazirmatn", fontSize:9, cellPadding:6, halign: rtl ? 'right':'left' },
        headStyles: { font:"Vazirmatn", fontStyle:'bold', fillColor:[26,86,219], textColor:255, halign: rtl ? 'right':'left' },
        columnStyles: { 1:{ halign:'right' }, 2:{ halign:'right' }, 3:{ halign:'right' } },
        foot: [[
          { content: this.$t('Totals'), styles:{ font:'Vazirmatn', fontStyle:'bold', halign: rtl ? 'right':'left' } },
          { content: Number(this.totalInflow||0).toFixed(this.priceDecimals), styles:{ halign:'right', fontStyle:'bold' } },
          { content: Number(this.totalOutflow||0).toFixed(this.priceDecimals), styles:{ halign:'right', fontStyle:'bold' } },
          { content: Number(this.netCashFlow||0).toFixed(this.priceDecimals), styles:{ halign:'right', fontStyle:'bold' } },
        ]],
        margin: { left: marginX, right: marginX }
      });

      doc.save(`cash-flow_${this.fmt(this.dateRange.startDate)}_${this.fmt(this.dateRange.endDate)}.pdf`);
    },

    fetchReport(){
      NProgress.start(); NProgress.set(0.1); this.isLoading = true;
      const qs = new URLSearchParams({
        from: this.fmt(this.dateRange.startDate),
        to:   this.fmt(this.dateRange.endDate),
        group_by: this.groupBy,
        warehouse_id: this.warehouseId || '',
        account_id: this.groupBy==='account' ? (this.accountId || '') : '',
        payment_method_id: this.groupBy==='method' ? (this.paymentMethodId || '') : ''
      }).toString();

      axios.get(`report/cash_flow_report?${qs}`).then(({data}) => {
        this.rows = Array.isArray(data.rows) ? data.rows : [];
        this.totalInflow  = Number(data.total_inflow || 0);
        this.totalOutflow = Number(data.total_outflow || 0);
        this.netCashFlow  = Number(data.net_cash_flow || 0);
        this.timeseries   = Array.isArray(data.timeseries) ? data.timeseries : [];
        this.warehouses   = Array.isArray(data.warehouses) ? data.warehouses : [];
        this.payment_methods = Array.isArray(data.payment_methods) ? data.payment_methods : [];
        this.accounts     = Array.isArray(data.accounts) ? data.accounts : [];
        this.isLoading = false; NProgress.done();
      }).catch(() => { this.isLoading = false; NProgress.done(); });
    }
  },

  mounted(){ this.handleResize(); window.addEventListener('resize', this.handleResize); },
  beforeDestroy(){ window.removeEventListener('resize', this.handleResize); },
  created(){ this.fetchReport(); }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrl__pad { padding: var(--pxn-space-6) 0; }
.pxrl__filtercard { margin-top: var(--pxn-space-5); }
.pxrl__filterrow { display: flex; flex-wrap: wrap; gap: var(--pxn-space-5); align-items: flex-end; }
.pxrl__field { display: flex; flex-direction: column; gap: var(--pxn-space-2); }
.pxrl__field--sm { min-width: 180px; }
.pxrl__label { font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); }
.pxrl__daterange {
  display: inline-flex; align-items: center; gap: var(--pxn-space-3);
  height: var(--pxn-control-h-md); padding: 0 var(--pxn-space-5);
  border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); font: inherit; font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink); cursor: pointer;
}
.pxrl__daterange:hover { background: var(--pxn-surface-2); }
.pxrl__kpis { display: grid; gap: var(--pxn-space-5); margin-top: var(--pxn-space-5); }
.pxrl__kpis--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
@media (max-width: 720px) { .pxrl__kpis--3 { grid-template-columns: minmax(0, 1fr); } }
.pxrl__cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxrl__cols { grid-template-columns: minmax(0, 1fr); } }
.pxrl__gap { margin-top: var(--pxn-space-5); margin-bottom: var(--pxn-space-5); }
.pxrl__tablewrap { margin-top: var(--pxn-space-5); }
.pxrl__totalrow { display: flex; align-items: center; justify-content: flex-end; gap: var(--pxn-space-6); margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); flex-wrap: wrap; }
.pxrl__totalrow > span:first-child { margin-right: auto; font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxrl ::v-deep .daterangepicker { z-index: 2055 !important; }
</style>
