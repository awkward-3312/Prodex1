<template>
  <div class="px-next pxrl">
    <px-page-header :title="$t('Tax_Summary_Report')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('Tax_Summary_Report') }]">
      <template #actions>
        <px-menu :items="exportMenu" align="end" @select="onExport">
          <template #trigger>
            <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button>
          </template>
        </px-menu>
        <px-button variant="primary" size="sm" icon="refresh-cw" @click="fetchReport">{{ $t('Refresh') }}</px-button>
      </template>
      <template #meta>
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
      </template>
    </px-page-header>

    <div class="pxrl__quickbar">
      <span class="pxrl__quicklabel">{{ $t('QuickRanges') }}</span>
      <px-button size="sm" variant="subtle" @click="quick('7d')">7D</px-button>
      <px-button size="sm" variant="subtle" @click="quick('30d')">30D</px-button>
      <px-button size="sm" variant="subtle" @click="quick('90d')">90D</px-button>
      <px-button size="sm" variant="subtle" @click="quick('mtd')">{{ $t('MTD') }}</px-button>
      <px-button size="sm" variant="subtle" @click="quick('ytd')">{{ $t('YTD') }}</px-button>
    </div>

    <div v-if="isLoading" class="pxrl__pad">
      <px-skeleton variant="lines" :rows="8" />
    </div>

    <template v-else>
      <px-card :title="$t('TaxesOverTime')" class="pxrl__chartcard">
        <apexchart type="line" height="300" :options="apexLineOptions" :series="apexLineSeries" />
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
          row-key="sale_id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          @sort="onSort"
        >
          <template #cell-taxable_base="{ row }"><span class="pxn-num">{{ money(row.taxable_base) }}</span></template>
          <template #cell-tax_collected="{ row }"><span class="pxn-num">{{ money(row.tax_collected) }}</span></template>
          <template #cell-effective_rate="{ row }"><span class="pxn-num">{{ formatRate(row.effective_rate) }}</span></template>
        </px-table>

        <px-empty-state v-else icon="file-text" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
      </div>

      <div v-if="rows.length" class="pxrl__totalrow">
        <span>{{ $t('Totals') }}</span>
        <span>{{ $t('Taxable_Base') }}: <b class="pxn-num">{{ money(totals.base) }}</b></span>
        <span>{{ $t('Tax_Collected') }}: <b class="pxn-num">{{ money(totals.tax) }}</b></span>
      </div>

      <px-pagination
        v-if="rows.length"
        :page="serverParams.page"
        :per-page="Number(serverParams.perPage)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>
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
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxMenu from "@/components/px-next/PxMenu.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  metaInfo: { title: "Tax Summary Report" },
  components: {
    "date-range-picker": DateRangePicker, apexchart: VueApexCharts,
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxCard, PxEmptyState
  },

  data() {
    const end = new Date(); const start = new Date(); start.setDate(end.getDate() - 29);
    return {
      _searchTimer: null,
      isLoading: true,

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

      isMobile: false,

      serverParams: { page: 1, perPage: 10, sort: { field: "date_time", type: "desc" } },
      limit: 10,
      search: "",
      totalRows: 0,
      rows: [],
      totals: { base: 0, tax: 0 },

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
    exportMenu() {
      return [
        { key: "print", label: this.$t("print"), icon: "printer" },
        { key: "pdf", label: this.$t("Export_PDF") || "PDF", icon: "file-text" }
      ];
    },
    columns() {
      return [
        { key:'sale_id',       label: this.$t('ID'),            sortable:true, strong:true },
        { key:'date_time',     label: this.$t('date'),          sortable:true },
        { key:'user_name',     label: this.$t('User'),          sortable:true },
        { key:'taxable_base',  label: this.$t('Taxable_Base'),  sortable:true, align:'right' },
        { key:'tax_collected', label: this.$t('Tax_Collected'), sortable:true, align:'right' },
        { key:'effective_rate', label: this.$t('Effective_Rate') + ' %', sortable:true, align:'right' },
      ];
    },

    apexLineOptions(){
      const dates = this.timeseries.map(x => x.d);
      return {
        chart: { type: 'line', toolbar: { show: false } },
        stroke: { curve: 'smooth', width: 3 },
        dataLabels: { enabled: false },
        xaxis: { categories: dates, labels: { rotate: -45 } },
        yaxis: { labels: { formatter: (v) => {
          try { return new Intl.NumberFormat(undefined,{notation:'compact',maximumFractionDigits:1}).format(Number(v||0)); }
          catch { return v; }
        } } },
        tooltip: { y: { formatter: (v) => {
          try { return new Intl.NumberFormat(undefined,{style:'currency',currency:this.currency}).format(Number(v||0)); }
          catch { return `${this.currency} ${(Number(v||0)).toLocaleString()}`; }
        } } },
        legend: { show: true },
        grid: { padding: { left: 10, right: 10, top: 10, bottom: 10 } }
      };
    },
    apexLineSeries(){
      const base = this.timeseries.map(x => Number(x.taxable_base || 0));
      const tax  = this.timeseries.map(x => Number(x.tax_collected || 0));
      return [
        { name: this.$t('Taxable_Base'),  data: base },
        { name: this.$t('Tax_Collected'), data: tax  }
      ];
    }
  },

  methods: {
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
          return new Intl.NumberFormat(undefined,{style:'currency',currency:this.currency}).format(Number(v||0));
        } catch(e2) {
          return `${this.currency} ${(Number(v||0)).toLocaleString()}`;
        }
      }
    },
    formatRate(v){ if(v === null || v === undefined) return '—'; const n = Number(v); return isNaN(n) ? '—' : n.toFixed(2); },
    handleResize(){ this.isMobile = window.innerWidth < 576; },
    fmt(d){ return moment(d).format('YYYY-MM-DD'); },
    fmtShort(d){ return moment(d).format('MMM D'); },

    quick(kind){
      const now = moment(); let s, e = now.clone();
      if(kind==='7d')  s = now.clone().subtract(6,'days');
      if(kind==='30d') s = now.clone().subtract(29,'days');
      if(kind==='90d') s = now.clone().subtract(89,'days');
      if(kind==='mtd') s = now.clone().startOf('month');
      if(kind==='ytd') s = now.clone().startOf('year');
      this.dateRange = { startDate: s.toDate(), endDate: e.toDate() };
      this.fetchReport();
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.serverParams.page = 1; this.fetchReport(); }, 350);
    },
    onPage(p) { if (this.serverParams.page !== p) { this.serverParams.page = p; this.fetchReport(); } },
    onLimit(v) { this.serverParams.perPage = Number(v); this.limit = Number(v); this.serverParams.page = 1; this.fetchReport(); },
    onSort({ key, dir }) { this.serverParams.sort = { field: key, type: dir }; this.fetchReport(); },

    onExport(item) {
      const k = item && item.key;
      if (k === "print") this.printTableOnly();
      else if (k === "pdf") this.exportPDF();
    },

    // Export PDF (RTL + Vazirmatn + safe totals)
    exportPDF() {
      const items =
        Array.isArray(this.rows?.[0]?.children) && this.rows.length === 1
          ? this.rows[0].children
          : (this.rows || []);

      const tBase = (this.totals && typeof this.totals.base === 'number')
        ? this.totals.base
        : items.reduce((a,b)=> a + Number(b.taxable_base || 0), 0);

      const tTax  = (this.totals && typeof this.totals.tax === 'number')
        ? this.totals.tax
        : items.reduce((a,b)=> a + Number(b.tax_collected || 0), 0);

      const overallRate = tBase > 0 ? (tTax / tBase) * 100 : null;
      const fmtRate = (v) => this.formatRate ? this.formatRate(v) : (v == null ? '' : `${Number(v).toFixed(2)}%`);

      const doc = new jsPDF({ orientation:'portrait', unit:'pt', format:'a4' });
      const pageW = doc.internal.pageSize.getWidth();
      const marginX = 40;

      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      try {
        doc.addFont(fontPath, "Vazirmatn", "normal");
        doc.addFont(fontPath, "Vazirmatn", "bold");
      } catch(_) { /* ignore if already added */ }
      doc.setFont("Vazirmatn", "normal");

      const rtl =
        (this.$i18n && ['ar','fa','ur','he'].includes(this.$i18n.locale)) ||
        (typeof document !== 'undefined' && document.documentElement.dir === 'rtl');

      const title = 'Tax Summary Report';
      const range = `${this.fmt(this.dateRange.startDate)} — ${this.fmt(this.dateRange.endDate)}`;

      doc.setFont("Vazirmatn", "bold"); doc.setFontSize(14);
      rtl ? doc.text(title, pageW - marginX, 40, { align:'right' })
          : doc.text(title, marginX, 40);

      doc.setFont("Vazirmatn", "normal"); doc.setFontSize(10);
      rtl ? doc.text(range, pageW - marginX, 58, { align:'right' })
          : doc.text(range, marginX, 58);

      const head = [[
        this.$t('ID'),
        this.$t('date'),
        this.$t('User'),
        this.$t('Taxable_Base'),
        this.$t('Tax_Collected'),
        this.$t('Effective_Rate') + ' %'
      ]];

      const body = items.map(r => ([
        r.sale_id ?? '',
        r.date_time ?? '',
        r.user_name ?? '',
        Number(r.taxable_base  || 0).toFixed(this.priceDecimals),
        Number(r.tax_collected || 0).toFixed(this.priceDecimals),
        fmtRate(r.effective_rate)
      ]));

      autoTable(doc, {
        startY: 80,
        head, body,
        styles: {
          font: "Vazirmatn",
          fontSize: 9,
          cellPadding: 6,
          halign: rtl ? 'right' : 'left'
        },
        headStyles: {
          font: "Vazirmatn",
          fontStyle: "bold",
          fillColor: [26,86,219],
          textColor: 255,
          halign: rtl ? 'right' : 'left'
        },
        columnStyles: {
          3: { halign:'right' },
          4: { halign:'right' },
          5: { halign:'right' },
        },
        foot: [[
          { content: this.$t('Totals'), styles:{ font: 'Vazirmatn', fontStyle:'bold', halign: rtl ? 'right' : 'left' } },
          '', '',
          { content: this.money ? this.money(tBase) : Number(tBase).toFixed(this.priceDecimals), styles:{ halign:'right', fontStyle:'bold' } },
          { content: this.money ? this.money(tTax)  : Number(tTax).toFixed(this.priceDecimals),  styles:{ halign:'right', fontStyle:'bold' } },
          { content: overallRate != null ? fmtRate(overallRate) : '', styles:{ halign:'right', fontStyle:'bold' } }
        ]],
        margin: { left: marginX, right: marginX }
      });

      doc.save(`tax-summary_${this.fmt(this.dateRange.startDate)}_${this.fmt(this.dateRange.endDate)}.pdf`);
    },

    //------ Print Table Only
    printTableOnly() {
      const rowsData = this.rows || [];

      let tableHtml = `<table class="vgt-table table table-hover tableOne">`;
      tableHtml += `<thead><tr>`;
      this.columns.forEach(col => {
        tableHtml += `<th class="text-left">${col.label}</th>`;
      });
      tableHtml += `</tr></thead>`;
      tableHtml += `<tbody>`;
      rowsData.forEach(row => {
        tableHtml += `<tr>`;
        this.columns.forEach(col => {
          let cellContent = '';
          if (col.key === 'taxable_base' || col.key === 'tax_collected') {
            cellContent = this.money(row[col.key]);
          } else if (col.key === 'effective_rate') {
            cellContent = this.formatRate(row.effective_rate);
          } else {
            cellContent = row[col.key] || '';
          }
          tableHtml += `<td class="text-left">${cellContent}</td>`;
        });
        tableHtml += `</tr>`;
      });
      tableHtml += `</tbody>`;

      const totalBase = this.totals?.base || rowsData.reduce((sum, row) => sum + parseFloat(row.taxable_base || 0), 0);
      const totalTax = this.totals?.tax || rowsData.reduce((sum, row) => sum + parseFloat(row.tax_collected || 0), 0);
      const overallRate = totalBase > 0 ? (totalTax / totalBase) * 100 : null;

      tableHtml += `<tfoot><tr>`;
      tableHtml += `<td class="text-left font-weight-bold" colspan="3">${this.$t('Totals')}</td>`;
      tableHtml += `<td class="text-left font-weight-bold">${this.money(totalBase)}</td>`;
      tableHtml += `<td class="text-left font-weight-bold">${this.money(totalTax)}</td>`;
      tableHtml += `<td class="text-left font-weight-bold">${this.formatRate(overallRate)}</td>`;
      tableHtml += `</tr></tfoot>`;

      tableHtml += `</table>`;

      const w = window.open("", "_blank");
      if (!w) {
        window.print();
        return;
      }

      const title = `${this.$t("Reports")} / ${this.$t("Tax_Summary_Report")}`;
      const dateRangeText = `${this.fmt(this.dateRange.startDate)} — ${this.fmt(this.dateRange.endDate)}`;

      const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
        .map(l => l.outerHTML)
        .join("\n");

      const inlineStyles = Array.from(document.querySelectorAll("style"))
        .filter(s => !((s.textContent || "").includes("@media print")))
        .map(s => s.outerHTML)
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
    ${inlineStyles}
    <style>
      @media print { body, body * { visibility: visible !important; } }
      body { margin: 0.3cm; }
      .print-header { font-weight: 600; margin-bottom: 8px; }
      .print-meta { font-size: 10px; margin-bottom: 15px; color: #666; }
      table { width: 100%; border-collapse: collapse; }
      th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
      th { background-color: #f2f2f2; }
    </style>
  </head>
  <body>
    <div class="print-header">${title}</div>
    <div class="print-meta">${dateRangeText}</div>
    ${tableHtml}
  </body>
</html>`);
      doc.close();

      w.focus();
      setTimeout(() => {
        w.print();
        w.close();
      }, 400);
    },

    // data
    fetchReport(){
      NProgress.start(); NProgress.set(0.1); this.isLoading = true;

      const qs = new URLSearchParams({
        from: this.fmt(this.dateRange.startDate),
        to:   this.fmt(this.dateRange.endDate),
        page: String(this.serverParams.page),
        limit: String(this.serverParams.perPage || this.limit),
        SortField: this.serverParams.sort?.field || 'date_time',
        SortType:  this.serverParams.sort?.type || 'desc',
        search: this.search || ''
      }).toString();

      axios.get(`report/tax_summary?${qs}`)
        .then(({data})=>{
          this.rows = Array.isArray(data.report) ? data.report : [];
          this.totalRows = Number(data.totalRows || 0);
          const t = data.totals || { base:0, tax:0 };
          this.totals = { base: Number(t.base||0), tax: Number(t.tax||0) };
          this.timeseries = Array.isArray(data.timeseries) ? data.timeseries : [];
          this.isLoading = false; NProgress.done();
        })
        .catch(()=>{ this.isLoading = false; NProgress.done(); });
    }
  },

  mounted(){
    this.handleResize();
    window.addEventListener('resize', this.handleResize);
  },
  beforeDestroy(){
    window.removeEventListener('resize', this.handleResize);
  },

  created(){ this.fetchReport(); }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrl__pad { padding: var(--pxn-space-6) 0; }
.pxrl__daterange {
  display: inline-flex; align-items: center; gap: var(--pxn-space-3);
  height: var(--pxn-control-h-sm); padding: 0 var(--pxn-space-4);
  border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); font: inherit; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink); cursor: pointer;
}
.pxrl__daterange:hover { background: var(--pxn-surface-2); }
.pxrl__quickbar { display: flex; align-items: center; gap: var(--pxn-space-2); margin-top: var(--pxn-space-4); flex-wrap: wrap; }
.pxrl__quicklabel { font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); margin-right: var(--pxn-space-2); }
.pxrl__chartcard { margin-top: var(--pxn-space-5); margin-bottom: var(--pxn-space-5); }
.pxrl__tablewrap { margin-top: var(--pxn-space-5); }
.pxrl__totalrow { display: flex; align-items: center; justify-content: flex-end; gap: var(--pxn-space-6); margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); flex-wrap: wrap; }
.pxrl__totalrow > span:first-child { margin-right: auto; font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxrl ::v-deep .daterangepicker { z-index: 2055 !important; }
</style>
