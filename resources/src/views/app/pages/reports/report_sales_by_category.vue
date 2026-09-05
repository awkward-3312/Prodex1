<template>
  <div class="px-next pxrl">
    <px-page-header :title="$t('Sales_by_Category')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('Sales_by_Category') }]">
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

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxrl__pad">
      <px-skeleton variant="table" :rows="10" :columns="2" />
    </div>

    <template v-else>
      <div class="pxrl__tablewrap">
        <px-table
          v-if="reports.length"
          :columns="columns"
          :rows="reports"
          row-key="category_name"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          @sort="onSort"
        >
          <template #cell-total_sales="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, row.total_sales, 2) }}</span></template>
        </px-table>

        <px-empty-state v-else icon="copy" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
      </div>

      <div v-if="reports.length" class="pxrl__totalrow">
        <span>{{ $t('Total') }}</span>
        <span class="pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, grandTotal, 2) }}</span>
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
import { mapGetters } from "vuex";
import DateRangePicker from 'vue2-daterange-picker'
import 'vue2-daterange-picker/dist/vue2-daterange-picker.css'
import moment from 'moment'
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
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  components: {
    "date-range-picker": DateRangePicker,
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxEmptyState
  },
  metaInfo: {
    title: "Sales By Category"
  },
  data() {
    return {
      _searchTimer: null,
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
      currency: "",
      reports: [],
      report: {},
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
        { key: "category_name", label: this.$t("Categorie"), strong: true },
        { key: "total_sales", label: this.$t("total_sales"), align: "right" },
      ];
    },
    grandTotal() {
      return (this.reports || []).reduce((sum, r) => sum + (parseFloat(r.total_sales) || 0), 0);
    }
  },

  methods: {
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.get_sales_by_category(1); }, 350);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.get_sales_by_category(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.get_sales_by_category(1); } },

    onSort({ key, dir }) {
      this.updateParams({ sort: { type: dir, field: key } });
      this.get_sales_by_category(this.serverParams.page);
    },

    onExport(item) {
      const k = item && item.key;
      if (k === "print") this.printTableOnly();
      else if (k === "pdf") this.report_pdf();
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
      link.setAttribute("download", "sales_by_category_report.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    //----------------------------------- Sales PDF ------------------------------\\
    report_pdf() {
      var self = this;
      let pdf = new jsPDF("p", "pt");

      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      pdf.addFont(fontPath, "VazirmatnBold", "bold");
      pdf.setFont("VazirmatnBold");

      let columns = [
        { header: self.$t("Categorie"), dataKey: "category_name" },
        { header: self.$t("total_sales"), dataKey: "total_sales" },
      ];

      let totalGrandTotal = self.reports.reduce((sum, report) => sum + parseFloat(report.total_sales || 0), 0);

      let footer = [{
        category_name: self.$t("Total"),
        total_sales: `${totalGrandTotal.toFixed(this.priceDecimals)}`,
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
             pdf.text("Sales by Category", 40, 25);
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

      pdf.save("Sales_by_Category.pdf");

    },

    //------------------------------Formetted Numbers -------------------------\\
    formatNumber(number, dec) {
      const value = (typeof number === "string"
        ? number
        : number.toString()
      ).split(".");
      if (dec <= 0) return value[0];
      let formated = value[1] || "";
      if (formated.length > dec)
        return `${value[0]}.${formated.substr(0, dec)}`;
      while (formated.length < dec) formated += "0";
      return `${value[0]}.${formated}`;
    },

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

    //------ Print Table Only
    printTableOnly() {
      const reportsData = Array.isArray(this.rows[0]?.children) && this.rows[0].children.length > 0
        ? this.rows[0].children
        : (this.reports || []);

      let tableHtml = `<table class="vgt-table table table-hover tableOne">`;
      tableHtml += `<thead><tr>`;
      this.columns.forEach(col => {
        tableHtml += `<th class="text-left">${col.label}</th>`;
      });
      tableHtml += `</tr></thead>`;
      tableHtml += `<tbody>`;
      reportsData.forEach(row => {
        tableHtml += `<tr>`;
        this.columns.forEach(col => {
          let cellContent = row[col.key];
          if (col.key === 'total_sales') {
            cellContent = this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, row.total_sales, 2);
          }
          tableHtml += `<td class="text-left">${cellContent || ''}</td>`;
        });
        tableHtml += `</tr>`;
      });
      tableHtml += `</tbody>`;

      const totalSales = reportsData.reduce((sum, report) => sum + parseFloat(report.total_sales || 0), 0);
      tableHtml += `<tfoot><tr>`;
      tableHtml += `<td class="text-left font-weight-bold">${this.$t('Total')}</td>`;
      tableHtml += `<td class="text-left font-weight-bold">${this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, totalSales, 2)}</td>`;
      tableHtml += `</tr></tfoot>`;
      tableHtml += `</table>`;

      const w = window.open("", "_blank");
      if (!w) {
        window.print();
        return;
      }

      const title = `${this.$t("Reports")} / ${this.$t("Sales_by_Category")}`;
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
      table { width: 100%; border-collapse: collapse; }
      th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
      th { background-color: #f2f2f2; }
    </style>
  </head>
  <body>
    <div class="print-header">${title}</div>
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

    //----------------------------- Submit Date Picker -------------------\\
    Submit_filter_dateRange() {
      const pad = (n) => String(n).padStart(2, "0");
      const formatLocalDate = (d) =>
        `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
      this.startDate = formatLocalDate(new Date(this.dateRange.startDate));
      this.endDate = formatLocalDate(new Date(this.dateRange.endDate));
      this.get_sales_by_category(1);
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
    get_sales_by_category(page) {
      NProgress.start();
      NProgress.set(0.1);
      this.get_data_loaded();
      axios
        .get(
          "report/sales_by_category_report?page=" +
            page +
            "&SortField=" +
            this.serverParams.sort.field +
            "&SortType=" +
            this.serverParams.sort.type +
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
          this.currency = response.data.currency;
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
    this.get_sales_by_category(1);
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
.pxrl__tablewrap { margin-top: var(--pxn-space-5); }
.pxrl__totalrow { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-6); margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxrl ::v-deep .daterangepicker { z-index: 2055 !important; }
</style>
