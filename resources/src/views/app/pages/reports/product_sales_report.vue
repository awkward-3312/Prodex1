<template>
  <div class="px-next pxrl">
    <px-page-header :title="$t('product_sales_report')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('product_sales_report') }]">
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
      :filter-count="activeFilterCount"
      @update:search="onSearchInput"
      @open-filters="filtersOpen = !filtersOpen"
    />

    <div v-if="filtersOpen" class="pxrl__filters">
      <div class="pxrl__filters-grid">
        <px-field :label="$t('Customer')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_Client" :reduce="o => o.value" :placeholder="$t('Choose_Customer')"
              :options="customers.map(c => ({ label: c.name, value: c.id }))" />
          </template>
        </px-field>
        <px-field :label="$t('warehouse')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_warehouse" :reduce="o => o.value" :placeholder="$t('Choose_Warehouse')"
              :options="warehouses.map(w => ({ label: w.name, value: w.id }))" />
          </template>
        </px-field>
      </div>
      <div class="pxrl__filters-act">
        <px-button size="sm" variant="primary" icon="filter" @click="applyFilters">{{ $t('Filter') }}</px-button>
        <px-button size="sm" variant="ghost" icon="x" @click="Reset_Filter">{{ $t('Reset') }}</px-button>
      </div>
    </div>

    <div v-if="isLoading" class="pxrl__pad">
      <px-skeleton variant="table" :rows="10" :columns="7" />
    </div>

    <template v-else>
      <div class="pxrl__tablewrap">
        <px-table
          v-if="sales.length"
          :columns="columns"
          :rows="sales"
          row-key="__rowkey"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          @sort="onSort"
        >
          <template #cell-total="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, row.total, 2) }}</span></template>
          <template #cell-quantity="{ row }"><span class="pxn-num">{{ row.quantity }}</span></template>
        </px-table>

        <px-empty-state v-else icon="package" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
      </div>

      <div v-if="sales.length" class="pxrl__totalrow">
        <span>{{ $t('Total') }}</span>
        <span>{{ $t('Qty_sold') }}: <b class="pxn-num">{{ totalQuantity }}</b></span>
        <span>{{ $t('Total') }}: <b class="pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, totalTotal, 2) }}</b></span>
      </div>

      <px-pagination
        v-if="sales.length"
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
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";
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
import PxField from "@/components/px-next/PxField.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {

  metaInfo: {
    title: "Product Sales Report"
  },
  components: {
    "date-range-picker": DateRangePicker,
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu,
    PxField, PxEmptyState, "vs-px": VsPx
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
      isLoading: true,
      serverParams: {
        sort: {
          field: "id",
          type: "desc"
        },
        page: 1,
        perPage: 10
      },
      rows: [{
          statut: 'Total',
          children: [
          ],
      },],
      search: "",
      totalRows: "",
      Filter_Client: "",
      Filter_warehouse: "",
      customers: [],
      warehouses: [],
      sales: [],
      limit: "10",
      today_mode: true,
      to: "",
      from: "",
      price_format_key: null
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    activeFilterCount() {
      return [this.Filter_Client, this.Filter_warehouse].filter(v => v !== "" && v !== null && v !== undefined).length;
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
        { key: "date", label: this.$t("date") },
        { key: "Ref", label: this.$t("Reference"), strong: true },
        { key: "client_name", label: this.$t("Customer") },
        { key: "warehouse_name", label: this.$t("warehouse") },
        { key: "product_name", label: this.$t("Name_product") },
        { key: "quantity", label: this.$t("Qty_sold"), align: "right" },
        { key: "total", label: this.$t("Total"), align: "right" },
      ];
    },
    totalQuantity() {
      return (this.sales || []).reduce((sum, s) => sum + (parseFloat(s.quantity) || 0), 0);
    },
    totalTotal() {
      return (this.sales || []).reduce((sum, s) => sum + (parseFloat(s.total) || 0), 0);
    }
  },
  methods: {
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Sales(1); }, 350);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Sales(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Sales(1); } },

    onSort({ key, dir }) {
      let field = key;
      if (key === "client_name") field = "client_id";
      else if (key === "warehouse_name") field = "warehouse_id";
      this.updateParams({ sort: { type: dir, field: field } });
      this.Get_Sales(this.serverParams.page);
    },

    applyFilters() { this.updateParams({ page: 1 }); this.Get_Sales(this.serverParams.page); },

    onExport(item) {
      const k = item && item.key;
      if (k === "print") this.printTableOnly();
      else if (k === "pdf") this.Sales_PDF();
      else if (k === "xlsx") this.exportCsv();
    },

    exportCsv() {
      const head = this.columns.map(c => c.label);
      const lines = [head.join(",")].concat(
        (this.sales || []).map(r => this.columns.map(c => `"${String(r[c.key] == null ? "" : r[c.key]).replace(/"/g, '""')}"`).join(","))
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "product_sales_report.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },

    //------ Reset Filter
    Reset_Filter() {
      this.search = "";
      this.Filter_Client = "";
      this.Filter_warehouse = "";
      this.Get_Sales(this.serverParams.page);
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
      const salesData = Array.isArray(this.rows[0]?.children) && this.rows[0].children.length > 0
        ? this.rows[0].children
        : (this.sales || []);

      let tableHtml = `<table class="vgt-table table table-hover tableOne">`;
      tableHtml += `<thead><tr>`;
      this.columns.forEach(col => {
        tableHtml += `<th class="text-left">${col.label}</th>`;
      });
      tableHtml += `</tr></thead>`;
      tableHtml += `<tbody>`;
      salesData.forEach(row => {
        tableHtml += `<tr>`;
        this.columns.forEach(col => {
          let cellContent = row[col.key];
          if (col.key === 'total') {
            cellContent = this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, row.total, 2);
          } else {
            cellContent = row[col.key] || '';
          }
          tableHtml += `<td class="text-left">${cellContent}</td>`;
        });
        tableHtml += `</tr>`;
      });
      tableHtml += `</tbody>`;

      const totalQuantity = salesData.reduce((sum, sale) => sum + parseFloat(sale.quantity || 0), 0);
      const totalTotal = salesData.reduce((sum, sale) => sum + parseFloat(sale.total || 0), 0);
      tableHtml += `<tfoot><tr>`;
      tableHtml += `<td class="text-left font-weight-bold">${this.$t('Total')}</td>`;
      tableHtml += `<td colspan="4"></td>`;
      tableHtml += `<td class="text-left font-weight-bold">${totalQuantity}</td>`;
      tableHtml += `<td class="text-left font-weight-bold">${this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, totalTotal, 2)}</td>`;
      tableHtml += `</tr></tfoot>`;
      tableHtml += `</table>`;

      const w = window.open("", "_blank");
      if (!w) {
        window.print();
        return;
      }

      const title = `${this.$t("Reports")} / ${this.$t("product_sales_report")}`;
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

    //----------------------------------- Sales PDF ------------------------------\\
    Sales_PDF() {
      var self = this;
      let pdf = new jsPDF("p", "pt");

      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      pdf.addFont(fontPath, "VazirmatnBold", "bold");
      pdf.setFont("VazirmatnBold");

      let columns = [
        { header: self.$t("date"), dataKey: "date" },
        { header: self.$t("Reference"), dataKey: "Ref" },
        { header: self.$t("Customer"), dataKey: "client_name" },
        { header: self.$t("warehouse"), dataKey: "warehouse_name" },
        { header: self.$t("Name_product"), dataKey: "product_name" },
        { header: self.$t("Qty_sold"), dataKey: "quantity" },
        { header: self.$t("Total"), dataKey: "total" },
      ];

      let totalquantity = self.sales.reduce((sum, sale) => sum + parseFloat(sale.quantity || 0), 0);
      let totaltotal= self.sales.reduce((sum, sale) => sum + parseFloat(sale.total || 0), 0);

      let footer = [{
        date: self.$t("Total"),
        Ref: '',
        client_name: '',
        warehouse_name: '',
        product_name: '',
        quantity: `${totalquantity.toFixed(2)}`,
        total: `${totaltotal.toFixed(this.priceDecimals)}`,
      }];

      autoTable(pdf, {
             columns: columns,
             body: self.sales,
             foot: footer,
             startY: 70,
             theme: "grid",
             didDrawPage: (data) => {
               pdf.setFont("VazirmatnBold");
               pdf.setFontSize(18);
               pdf.text("Product Sales Report", 40, 25);
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

      pdf.save("Product_sales_report.pdf");

    },

    //---------------------------------------- Set To Strings-------------------------\\
    setToStrings() {
      if (this.Filter_Client === null) {
        this.Filter_Client = "";
      } else if (this.Filter_warehouse === null) {
        this.Filter_warehouse = "";
      }
    },

    //----------------------------- Submit Date Picker -------------------\\
    Submit_filter_dateRange() {
      const pad = (n) => String(n).padStart(2, "0");
      const formatLocalDate = (d) =>
        `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
      this.startDate = formatLocalDate(new Date(this.dateRange.startDate));
      this.endDate = formatLocalDate(new Date(this.dateRange.endDate));
      this.Get_Sales(1);
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


    //----------------------------------------- Get all Sales ------------------------------\\
    Get_Sales(page) {
      NProgress.start();
      NProgress.set(0.1);
      this.setToStrings();
      this.get_data_loaded();
      axios
        .get(
          "report/product_sales_report?page=" +
            page +
            "&client_id=" +
            this.Filter_Client +
            "&warehouse_id=" +
            this.Filter_warehouse +
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
          this.sales = (response.data.sales || []).map((s, i) => Object.assign({ __rowkey: `${s.Ref || 'r'}-${s.product_name || ''}-${i}` }, s));
          this.customers = response.data.customers;
          this.warehouses = response.data.warehouses;
          this.totalRows = response.data.totalRows;
          this.rows[0].children = this.sales;
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
    },
  },
  //----------------------------- Created function-------------------\\
  created() {
    this.Get_Sales(1);
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
.pxrl__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxrl__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxrl__filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxrl__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxrl__filters-act { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); }
.pxrl__tablewrap { margin-top: var(--pxn-space-5); }
.pxrl__link { color: var(--pxn-primary); }
.pxrl__totalrow { display: flex; align-items: center; justify-content: flex-end; gap: var(--pxn-space-6); margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.pxrl__totalrow > span:first-child { margin-right: auto; font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxrl ::v-deep .daterangepicker { z-index: 2055 !important; }
</style>
