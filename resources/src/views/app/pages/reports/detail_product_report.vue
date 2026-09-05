<template>
  <div class="px-next pxrl">
    <px-page-header :title="product.name ? `${$t('product_report')} · ${product.name}` : $t('product_report')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('product_report') }]">
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
          :locale-data="locale"
          :autoApply="true"
          :showDropdowns="true"
          @update="Submit_filter_dateRange"
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
      <px-skeleton variant="table" :rows="10" :columns="8" />
    </div>

    <template v-else>
      <px-card v-if="product.type == 'is_variant' && (product.products_variants_data || []).length" :title="$t('Variant_Name')" class="pxrl__chartcard">
        <table class="pxrl__minitable">
          <thead>
            <tr>
              <th>{{ $t('Variant_code') }}</th>
              <th>{{ $t('Variant_Name') }}</th>
              <th class="pxrl__tr">{{ $t('Variant_price') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(v, i) in product.products_variants_data" :key="i">
              <td>{{ v.code }}</td>
              <td>{{ v.name }}</td>
              <td class="pxrl__tr pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, v.price, 2) }}</td>
            </tr>
          </tbody>
        </table>
      </px-card>

      <px-toolbar
        :search="search_sales"
        :search-placeholder="$t('Search_this_table')"
        :filter-count="activeFilterCount"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxrl__filters">
        <div class="pxrl__filters-grid">
          <px-field :label="$t('Reference')">
            <template #default="{ id }"><px-input :id="id" v-model="Filter_Ref" :placeholder="$t('Reference')" /></template>
          </px-field>
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
          <px-field :label="$t('User') || 'Vendedor'">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="Filter_user" :reduce="o => o.value" :placeholder="$t('PleaseSelect')"
                :options="users.map(u => ({ label: u.username, value: u.id }))" />
            </template>
          </px-field>
        </div>
        <div class="pxrl__filters-act">
          <px-button size="sm" variant="primary" icon="filter" @click="Get_Sales(1)">{{ $t('Filter') }}</px-button>
          <px-button size="sm" variant="ghost" icon="x" @click="Reset_Filter">{{ $t('Reset') }}</px-button>
        </div>
      </div>

      <div class="pxrl__tablewrap">
        <px-table
          v-if="sales.length"
          :columns="columns_sales"
          :rows="sales"
          row-key="__rowkey"
        >
          <template #cell-Ref="{ row }">
            <router-link v-if="row.sale_id" :to="'/app/sales/detail/' + row.sale_id" class="pxrl__link">{{ row.Ref }}</router-link>
            <span v-else>{{ row.Ref }}</span>
          </template>
          <template #cell-total="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, row.total, 2) }}</span></template>
        </px-table>

        <px-empty-state v-else icon="package" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
      </div>

      <div v-if="sales.length" class="pxrl__totalrow">
        <span>{{ $t('Total') }}</span>
        <span class="pxn-num">{{ sumSalesTotal(rows_sales[0]) }}</span>
      </div>

      <px-pagination
        v-if="sales.length"
        :page="Number(sales_page)"
        :per-page="Number(limit_sales)"
        :total="Number(totalRows_sales) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>
  </div>
</template>


<script>
import { mapGetters } from "vuex";
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";
import DateRangePicker from 'vue2-daterange-picker'
import 'vue2-daterange-picker/dist/vue2-daterange-picker.css'
import moment from 'moment'
import NProgress from "nprogress";
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
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "Products Report"
  },
  components: {
    "date-range-picker": DateRangePicker,
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxCard,
    PxField, PxInput, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      _searchTimer: null,
      filtersOpen: false,
      totalRows_sales: "",
      limit_sales: "10",
      sales_page: 1,
      search_sales: "",

      Filter_Client: "",
      Filter_Ref: "",
      Filter_warehouse: "",
      Filter_user: "",

      isLoading: true,
      sales: [],
      rows_sales: [{ statut: '', children: [] }],
      warehouses: [],
      customers: [],
      users: [],
      product: {},
      today_mode: true,
      startDate: "",
      endDate: "",
      dateRange: {
        startDate: "",
        endDate: ""
      },
      locale: {
        Label: "Apply",
        cancelLabel: "Cancel",
        weekLabel: "W",
        customRangeLabel: "Custom Range",
        daysOfWeek: moment.weekdaysMin(),
        monthNames: moment.monthsShort(),
        firstDay: 1
      },
      price_format_key: null
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    activeFilterCount() {
      return [this.Filter_Ref, this.Filter_Client, this.Filter_warehouse, this.Filter_user]
        .filter(v => v !== "" && v !== null && v !== undefined).length;
    },
    exportMenu() {
      return [
        { key: "print", label: this.$t("print"), icon: "printer" },
        { key: "pdf", label: "PDF", icon: "file-text" },
        { key: "xlsx", label: "CSV / Excel", icon: "file-spreadsheet" }
      ];
    },
    columns_sales() {
      return [
        { key: "date", label: this.$t("date") },
        { key: "Ref", label: this.$t("Reference"), strong: true },
        { key: "created_by", label: this.$t("Created_by"), sortable: false },
        { key: "product_name", label: this.$t("product_name"), sortable: false },
        { key: "client_name", label: this.$t("Customer"), sortable: false },
        { key: "warehouse_name", label: this.$t("warehouse"), sortable: false },
        { key: "quantity", label: this.$t("Quantity"), align: "right", sortable: false },
        { key: "total", label: this.$t("Total"), align: "right", sortable: false },
      ];
    },
  },

  methods: {
    fmt(d) { try { return moment(d).format("YYYY-MM-DD"); } catch (e) { return ""; } },

    //------ Reset Filter
    Reset_Filter() {
      this.search_sales = "";
      this.Filter_Client = "";
      this.Filter_Ref = "";
      this.Filter_warehouse = "";
      this.Filter_user = "";
      this.Get_Sales(1);
    },

    onSearchInput(v) {
      this.search_sales = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.Get_Sales(1); }, 350);
    },
    onPage(p) { if (this.sales_page !== p) { this.Get_Sales(p); } },
    onLimit(v) { if (this.limit_sales !== String(v)) { this.limit_sales = String(v); this.Get_Sales(1); } },

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
        { header: self.$t("Created_by"), dataKey: "created_by" },
        { header: self.$t("product_name"), dataKey: "product_name" },
        { header: self.$t("Customer"), dataKey: "client_name" },
        { header: self.$t("warehouse"), dataKey: "warehouse_name" },
        { header: self.$t("Quantity"), dataKey: "quantity" },
        { header: self.$t("Total"), dataKey: "total" },
      ];

      autoTable(pdf, {
        columns: columns,
        body: self.sales,
        startY: 70,
        theme: "grid",
        didDrawPage: (data) => {
          pdf.setFont("VazirmatnBold");
          pdf.setFontSize(18);
          pdf.text("Sale List", 40, 25);
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
      });

      pdf.save("Sale_List.pdf");
    },

    onExport(item) {
      const k = item && item.key;
      if (k === "print") this.printTableOnly();
      else if (k === "pdf") this.Sales_PDF();
      else if (k === "xlsx") this.exportCsv();
    },

    exportCsv() {
      const cols = this.columns_sales;
      const head = cols.map(c => c.label);
      const lines = [head.join(",")].concat(
        (this.sales || []).map(r =>
          cols.map(c => {
            let v = r[c.key];
            if (c.key === "total") v = this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, r.total, 2);
            return `"${String(v == null ? "" : v).replace(/"/g, '""')}"`;
          }).join(",")
        )
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "product_report.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
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

    //------ Print Table Only - Print ALL sales data with all columns
    printTableOnly() {
      const title = `${this.$t("Reports")} / ${this.$t("product_report")}`;
      const sales = Array.isArray(this.sales) ? this.sales : [];

      let tableHTML = '<table style="width: 100%; border-collapse: collapse; font-size: 10px;">';
      tableHTML += '<thead><tr>';

      this.columns_sales.forEach(col => {
        tableHTML += `<th style="border: 1px solid #ddd; padding: 6px 8px; background-color: #f5f5f5; font-weight: bold; text-align: left;">${col.label}</th>`;
      });
      tableHTML += '</tr></thead><tbody>';

      sales.forEach(sale => {
        tableHTML += '<tr>';
        this.columns_sales.forEach(col => {
          let cellValue = '';

          if (col.key === 'date') {
            cellValue = sale.date || '';
          } else if (col.key === 'Ref') {
            cellValue = sale.Ref || '';
          } else if (col.key === 'created_by') {
            cellValue = sale.created_by || '';
          } else if (col.key === 'product_name') {
            cellValue = sale.product_name || '';
          } else if (col.key === 'client_name') {
            cellValue = sale.client_name || '';
          } else if (col.key === 'warehouse_name') {
            cellValue = sale.warehouse_name || '';
          } else if (col.key === 'quantity') {
            cellValue = sale.quantity || 0;
          } else if (col.key === 'total') {
            cellValue = this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, sale.total, 2);
          } else {
            cellValue = sale[col.key] || '';
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

    // Group footer helpers preserved verbatim
    sumSalesQuantity(rowObj) {
      if (!rowObj || !Array.isArray(rowObj.children)) {
        return '0';
      }
      let sum = 0;
      for (let i = 0; i < rowObj.children.length; i++) {
        const value = Number(rowObj.children[i].quantity) || 0;
        if (Number.isFinite(value)) {
          sum += value;
        }
      }
      return sum.toLocaleString();
    },

    sumSalesTotal(rowObj) {
      if (!rowObj || !Array.isArray(rowObj.children)) {
        return this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, 0, 2);
      }
      let sum = 0;
      for (let i = 0; i < rowObj.children.length; i++) {
        const value = Number(rowObj.children[i].total) || 0;
        if (Number.isFinite(value)) {
          sum += value;
        }
      }
      return this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, sum, 2);
    },

    //----------------------------- Submit Date Picker -------------------\\
    Submit_filter_dateRange() {
      var self = this;
      self.startDate = self.dateRange.startDate.toJSON().slice(0, 10);
      self.endDate = self.dateRange.endDate.toJSON().slice(0, 10);
      self.Get_Sales(1);
    },

    get_data_loaded() {
      var self = this;
      if (self.today_mode) {
        let startDate = new Date("01/01/2000");
        let endDate = new Date();

        self.startDate = startDate.toISOString();
        self.endDate = endDate.toISOString();

        self.dateRange.startDate = startDate.toISOString();
        self.dateRange.endDate = endDate.toISOString();
      }
    },

    //----------------------------------- Get Details Product ------------------------------\\
    showDetails() {
      let id = this.$route.params.id;
      axios
        .get(`get_product_detail/${id}`)
        .then(response => {
          this.product = response.data;
        })
        .catch(response => {
        });
    },

    //--------------------------- sale_products_details -------------\\
    Get_Sales(page) {
      NProgress.start();
      NProgress.set(0.1);
      this.get_data_loaded();
      this.sales_page = page;

      axios
        .get(
          "/report/sale_products_details?page=" +
            page +
            "&Ref=" +
            this.Filter_Ref +
            "&client_id=" +
            this.Filter_Client +
            "&warehouse_id=" +
            this.Filter_warehouse +
            "&user_id=" +
            this.Filter_user +
            "&limit=" +
            this.limit_sales +
            "&to=" +
            this.endDate +
            "&from=" +
            this.startDate +
            "&search=" +
            this.search_sales +
            "&id=" +
            this.$route.params.id
        )
        .then(response => {
          this.sales = (response.data.sales || []).map((s, i) => Object.assign({ __rowkey: s.sale_id != null ? `s-${s.sale_id}-${i}` : `r-${i}` }, s));
          this.totalRows_sales = response.data.totalRows;
          this.rows_sales[0].children = this.sales;
          this.customers = response.data.customers;
          this.warehouses = response.data.warehouses;
          this.users = response.data.users;

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
  }, //end Methods

  //----------------------------- Created function------------------- \\

  created: function() {
    this.showDetails();
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
.pxrl__chartcard { margin-top: var(--pxn-space-5); }
.pxrl__minitable { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxrl__minitable th, .pxrl__minitable td { padding: var(--pxn-space-2) var(--pxn-space-3); border-bottom: 1px solid var(--pxn-border); text-align: left; }
.pxrl__minitable th { font-size: var(--pxn-fs-xs); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); }
.pxrl__tr { text-align: right; }
.pxrl__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxrl__filters-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 560px) { .pxrl__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxrl__filters-act { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); }
.pxrl__tablewrap { margin-top: var(--pxn-space-5); }
.pxrl__link { color: var(--pxn-primary); }
.pxrl__totalrow { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-6); margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxrl ::v-deep .daterangepicker { z-index: 2055 !important; }
</style>
