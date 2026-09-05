<template>
  <div class="px-next pxrl">
    <px-page-header :title="$t('Expense_Report')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('Expense_Report') }]">
      <template #actions>
        <px-menu :items="exportMenu" align="end" @select="onExport">
          <template #trigger>
            <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button>
          </template>
        </px-menu>
      </template>
      <template #meta>
        <date-range-picker
          v-model="dateRange"
          :startDate="startDate"
          :endDate="endDate"
          @update="Submit_filter_dateRange"
          :locale-data="locale"
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

    <div v-if="isLoading" class="pxrl__pad">
      <px-skeleton variant="lines" :rows="8" />
    </div>

    <template v-else>
      <div class="pxrl__kpis pxrl__kpis--3">
        <px-stat bordered icon="receipt" :label="$t('Total_Expenses')" :value="formatPriceWithSymbol(currentUser && currentUser.currency, totalExpenses, 2)" />
        <px-stat bordered icon="bar-chart" :label="$t('Expense_Category')" :value="String(categoryCount)" />
        <px-stat bordered icon="banknote" :label="$t('Top_Category') || 'Top Category'" :value="topCategoryName || '—'"
          :sub="topCategoryAmount != null ? formatPriceWithSymbol(currentUser && currentUser.currency, topCategoryAmount, 2) : null" />
      </div>

      <div class="pxrl__cols pxrl__gap">
        <px-card :title="`${$t('Expenses_by_Category') || 'Expenses by Category'} (${$t('Distribution') || 'Distribution'})`">
          <apexchart v-if="chartDataLength" type="donut" height="320" :options="apexPieOptions" :series="apexPieSeries" />
          <px-empty-state v-else icon="pie-chart" :title="$t('No_Data') || 'No data'" />
        </px-card>
        <px-card :title="`${$t('Expenses_by_Category') || 'Expenses by Category'} (${$t('Total_Expenses')})`">
          <apexchart v-if="chartDataLength" type="bar" height="320" :options="apexBarOptions" :series="apexBarSeries" />
          <px-empty-state v-else icon="bar-chart" :title="$t('No_Data') || 'No data'" />
        </px-card>
      </div>

      <px-toolbar
        :search="search"
        :search-placeholder="$t('Search_this_table')"
        :filter-count="warehouse_id ? 1 : 0"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxrl__filters">
        <div class="pxrl__filters-grid">
          <px-field :label="$t('warehouse')">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="warehouse_id" :reduce="o => o.value" :clearable="false"
                :options="[{ label: $t('All_Warehouses'), value: 0 }].concat(warehouses.map(w => ({ label: w.name, value: w.id })))"
                @input="Selected_Warehouse" />
            </template>
          </px-field>
        </div>
      </div>

      <div class="pxrl__tablewrap">
        <px-table
          v-if="reports.length"
          :columns="columns"
          :rows="reports"
          row-key="category_name"
        >
          <template #cell-total_expenses="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, row.total_expenses, 2) }}</span></template>
        </px-table>

        <px-empty-state v-else icon="receipt" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
      </div>

      <div v-if="reports.length" class="pxrl__totalrow">
        <span>{{ $t('Total') }}</span>
        <span class="pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, totalExpenses, 2) }}</span>
      </div>

      <px-pagination
        v-if="reports.length"
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
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";
import VueApexCharts from "vue-apexcharts";
import DateRangePicker from 'vue2-daterange-picker'
import 'vue2-daterange-picker/dist/vue2-daterange-picker.css'
import moment from 'moment'
import { mapGetters } from "vuex";
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
import PxStat from "@/components/px-next/PxStat.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  components: {
    "date-range-picker": DateRangePicker, apexchart: VueApexCharts,
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxCard, PxStat,
    PxField, PxEmptyState, "vs-px": VsPx
  },
  metaInfo: {
    title: "Expenses Report"
  },
  data() {
    return {
      _searchTimer: null,
      filtersOpen: false,
      startDate: "",
      endDate: "",
      dateRange: {
        startDate: "",
        endDate: ""
      },
      locale:{
          Label: "Apply",
          cancelLabel: "Cancel",
          weekLabel: "W",
          customRangeLabel: "Custom Range",
          daysOfWeek: moment.weekdaysMin(),
          monthNames: moment.monthsShort(),
          firstDay: 1
        },
        today_mode: true,
        to: "",
        from: "",
      isLoading: true,
      rows: [{
        category_name: 'Total',
          children: [
          ],
      },],
      serverParams: {
        sort: {
          field: "id",
          type: "desc"
        },
        page: 1,
        perPage: 10
      },
      limit: "10",
      search: "",
      totalRows: "",
      reports: [],
      report: {},
      warehouses: [],
      warehouse_id: 0,
      price_format_key: null
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    exportMenu() {
      return [
        { key: "print", label: this.$t("print"), icon: "printer" },
        { key: "pdf", label: "PDF", icon: "file-text" },
        { key: "xlsx", label: "CSV / Excel", icon: "file-spreadsheet" }
      ];
    },
    columns() {
      return [
        { key: "category_name", label: this.$t("Expense_Category"), strong: true },
        { key: "total_expenses", label: this.$t("Total_Expenses"), align: "right" },
      ];
    },
    totalExpenses() {
      return (this.reports || []).reduce((sum, r) => sum + parseFloat(r.total_expenses || 0), 0);
    },
    categoryCount() {
      return (this.reports || []).length;
    },
    topCategoryName() {
      const reports = this.reports || [];
      if (!reports.length) return '';
      const top = reports.reduce((best, r) => {
        const val = parseFloat(r.total_expenses || 0);
        return val > (best ? parseFloat(best.total_expenses || 0) : 0) ? r : best;
      }, null);
      return top ? top.category_name : '';
    },
    topCategoryAmount() {
      const reports = this.reports || [];
      if (!reports.length) return null;
      const top = reports.reduce((best, r) => {
        const val = parseFloat(r.total_expenses || 0);
        return val > (best ? parseFloat(best.total_expenses || 0) : 0) ? r : best;
      }, null);
      return top ? parseFloat(top.total_expenses || 0) : null;
    },
    chartDataLength() {
      const list = this.reports || [];
      return list.length;
    },
    apexPieSeries() {
      return (this.reports || []).map(r => parseFloat(r.total_expenses || 0));
    },
    apexPieOptions() {
      const totalStr = this.currentUser && this.currentUser.currency
        ? `${this.currentUser.currency} ${Number(this.totalExpenses).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
        : String(this.totalExpenses);
      return {
        chart: { type: 'donut', toolbar: { show: false } },
        labels: (this.reports || []).map(r => r.category_name || ''),
        legend: { position: 'bottom', fontSize: '12px' },
        colors: ['#6366f1', '#8b5cf6', '#a855f7', '#d946ef', '#ec4899', '#f43f5e', '#f97316', '#eab308', '#22c55e', '#14b8a6'],
        dataLabels: { enabled: true, formatter(val) { return val ? Number(val).toFixed(1) + '%' : ''; } },
        plotOptions: { pie: { donut: { size: '55%', labels: { show: true, total: { show: true, label: this.$t('Total_Expenses'), formatter: () => totalStr } } } } },
      };
    },
    apexBarSeries() {
      return [{ name: this.$t('Total_Expenses'), data: (this.reports || []).map(r => parseFloat(r.total_expenses || 0)) }];
    },
    apexBarOptions() {
      return {
        chart: { type: 'bar', toolbar: { show: false }, stacked: false },
        plotOptions: { bar: { horizontal: false, columnWidth: '55%', borderRadius: 4 } },
        dataLabels: { enabled: true },
        xaxis: { categories: (this.reports || []).map(r => r.category_name || ''), labels: { rotate: -45, style: { fontSize: '11px' } } },
        yaxis: { labels: { formatter: (val) => (this.currentUser && this.currentUser.currency ? `${this.currentUser.currency} ${Number(val).toLocaleString(undefined, { maximumFractionDigits: 0 })}` : String(val)) } },
        colors: ['#6366f1'],
        legend: { show: false },
        grid: { borderColor: '#e5e7eb', strokeDashArray: 4, xaxis: { lines: { show: false } } },
      };
    },
  },

  methods: {
    onExport(item) {
      const k = item && item.key;
      if (k === "print") this.printTableOnly();
      else if (k === "pdf") this.Expenses_report_pdf();
      else if (k === "xlsx") this.exportCsv();
    },

    exportCsv() {
      const head = this.columns.map(c => c.label);
      const lines = [head.join(",")].concat(
        (this.reports || []).map(r => this.columns.map(c => `"${String(r[c.key] == null ? "" : r[c.key]).replace(/"/g, '""')}"`).join(","))
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "Expenses_report.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.get_expenses_report(1); }, 350);
    },

    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.get_expenses_report(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.get_expenses_report(1); } },

    sumCount(rowObj) {
      let sum = 0;
      for (let i = 0; i < rowObj.children.length; i++) {
        sum += parseFloat(rowObj.children[i].total_expenses) || 0;
      }
      return sum;
    },

    //------------------------------Formetted Numbers -------------------------\\
    formatPriceDisplay(number, dec) {
      try {
        const decimals = this.priceDecimals;
        const n = Number(number || 0);
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        const effectiveKey = key || null;
        return formatPriceDisplayHelper(n, decimals, effectiveKey);
      } catch (e) {
        const n = Number(number || 0);
        return n.toLocaleString(undefined, { maximumFractionDigits: dec || 2 });
      }
    },

    formatPriceWithSymbol(symbol, number, dec) {
      const safeSymbol = symbol || "";
      const value = this.formatPriceDisplay(number, dec);
      return safeSymbol ? `${safeSymbol} ${value}` : value;
    },

     //----------------------------------- Expenses PDF ------------------------------\\
    Expenses_report_pdf() {
      var self = this;
      let pdf = new jsPDF("p", "pt");

      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      pdf.addFont(fontPath, "VazirmatnBold", "bold");
      pdf.setFont("VazirmatnBold");

      let columns = [
        { header: self.$t("Expense_Category"), dataKey: "category_name" },
        { header: self.$t("Total_Expenses"), dataKey: "total_expenses" },
      ];

      let totalGrandTotal = self.reports.reduce((sum, report) => sum + parseFloat(report.total_expenses || 0), 0);

      let footer = [{
        category_name: self.$t("Total"),
        total_expenses: `${totalGrandTotal.toFixed(this.priceDecimals)}`,
      }];

      autoTable(pdf, {
           columns: columns,
           body: self.reports,
           foot: footer,
           startY: 70,
           theme: "grid",
           didDrawPage: (data) => {
             pdf.setFont("VazirmatnBold");
             pdf.setFontSize(18);
             pdf.text("Expenses Report", 40, 25);
           },
           styles: {
             font: "VazirmatnBold",
             halign: "center",
           },
           headStyles: {
             fillColor: [26, 86, 219],
             textColor: 255,
             fontStyle: "bold",
           },
           footStyles: {
             fillColor: [26, 86, 219],
             textColor: 255,
             fontStyle: "bold",
           },
      });

      pdf.save("Expenses_Report.pdf");

    },

    //------ Print Table Only
    printTableOnly() {
      const title = `${this.$t("Reports")} / ${this.$t("Expense_Report")}`;
      const reports = Array.isArray(this.rows[0]?.children) ? this.rows[0].children : (this.reports || []);

      let tableHTML = '<table style="width: 100%; border-collapse: collapse; font-size: 10px;">';
      tableHTML += '<thead><tr>';
      this.columns.forEach(col => {
        tableHTML += `<th style="border: 1px solid #ddd; padding: 6px 8px; background-color: #f5f5f5; font-weight: bold; text-align: left;">${col.label}</th>`;
      });
      tableHTML += '</tr></thead><tbody>';

      reports.forEach(report => {
        tableHTML += '<tr>';
        this.columns.forEach(col => {
          let cellValue = '';

          if (col.key === 'category_name') {
            cellValue = report.category_name || '';
          } else if (col.key === 'total_expenses') {
            cellValue = this.formatPriceWithSymbol(this.currentUser?.currency, report.total_expenses, 2);
          } else {
            cellValue = report[col.key] || '';
          }

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
    <div class="print-header">${title}</div>
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

     //---------------------- Event Select Warehouse ------------------------------\\
    Selected_Warehouse(value) {
      if (value === null || value === undefined) {
        this.warehouse_id = 0;
      }
      this.get_expenses_report(1);
    },


    //----------------------------- Submit Date Picker -------------------\\
    Submit_filter_dateRange() {
      const pad = (n) => String(n).padStart(2, "0");
      const formatLocalDate = (d) =>
        `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
      this.startDate = formatLocalDate(new Date(this.dateRange.startDate));
      this.endDate = formatLocalDate(new Date(this.dateRange.endDate));
      this.get_expenses_report(1);
    },


    get_data_loaded() {
      const self = this;
      if (self.today_mode) {
        const startDate = new Date("01/01/2000");
        const endDate = new Date();
        const pad = (n) => String(n).padStart(2, "0");
        const formatLocalDate = (d) =>
          `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
        self.startDate = formatLocalDate(startDate);
        self.endDate = formatLocalDate(endDate);
        self.dateRange.startDate = startDate;
        self.dateRange.endDate = endDate;
      }
    },

    fmt(d) {
      return moment(d).format("YYYY-MM-DD");
    },


    //--------------------------- Get Report -------------\\
    get_expenses_report(page) {
      NProgress.start();
      NProgress.set(0.1);
      this.get_data_loaded();
      axios
        .get(
          "report/expenses_report?page=" +
            page +
            "&SortField=" +
            this.serverParams.sort.field +
            "&SortType=" +
            this.serverParams.sort.type +
            "&warehouse_id=" +
            this.warehouse_id +
            "&search=" +
            this.search +
            "&limit=" +
            this.limit +
            "&to=" +
          this.endDate +
          "&from=" +
          this.startDate
        )
        .then(response => {
          this.reports = response.data.reports;
          this.totalRows = response.data.totalRows;
          this.warehouses = response.data.warehouses;
          this.rows[0].children = this.reports;
          NProgress.done();
          this.isLoading = false;
          this.today_mode = false;
        })
        .catch(response => {
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
            this.today_mode = false;
          }, 500);
        });
    }
  }, //end Methods

  //----------------------------- Created function------------------- \\
  created: function() {
    this.get_expenses_report(1);
  }
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
.pxrl__kpis { display: grid; gap: var(--pxn-space-5); margin-top: var(--pxn-space-5); }
.pxrl__kpis--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
@media (max-width: 720px) { .pxrl__kpis--3 { grid-template-columns: minmax(0, 1fr); } }
.pxrl__cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxrl__cols { grid-template-columns: minmax(0, 1fr); } }
.pxrl__gap { margin-top: var(--pxn-space-5); margin-bottom: var(--pxn-space-5); }
.pxrl__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxrl__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 560px) { .pxrl__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxrl__tablewrap { margin-top: var(--pxn-space-5); }
.pxrl__totalrow { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-6); margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxrl ::v-deep .daterangepicker { z-index: 2055 !important; }
</style>
