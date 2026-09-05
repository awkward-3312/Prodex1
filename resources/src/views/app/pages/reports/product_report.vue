<template>
  <div class="px-next pxrl">
    <px-page-header :title="$t('product_report')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('product_report') }]">
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
              {{ picker.startDate.toJSON().slice(0, 10) }} — {{ picker.endDate.toJSON().slice(0, 10) }}
            </button>
          </template>
        </date-range-picker>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search_products"
      :search-placeholder="$t('Search_this_table')"
      :filter-count="warehouse_id ? 1 : 0"
      @update:search="onSearchInput"
      @open-filters="filtersOpen = !filtersOpen"
    />

    <div v-if="filtersOpen" class="pxrl__filters">
      <div class="pxrl__filters-grid">
        <px-field :label="$t('warehouse')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="warehouse_id" :reduce="o => o.value" :placeholder="$t('Choose_Warehouse')"
              :options="warehouses.map(w => ({ label: w.name, value: w.id }))" @input="Selected_Warehouse" />
          </template>
        </px-field>
      </div>
    </div>

    <div v-if="isLoading" class="pxrl__pad">
      <px-skeleton variant="table" :rows="10" :columns="5" />
    </div>

    <template v-else>
      <div class="pxrl__tablewrap">
        <px-table
          v-if="products.length"
          :columns="columns"
          :rows="products"
          row-key="id"
          has-row-actions
        >
          <template #cell-sold_qty="{ row }"><span class="pxn-num">{{ row.sold_qty }}</span></template>
          <template #cell-sold_amount="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, row.sold_amount, 2) }}</span></template>
          <template #row-actions="{ row }">
            <px-button variant="ghost" size="sm" icon="eye" @click="$router.push('/app/reports/detail_product/' + row.id)">{{ $t('Reports') }}</px-button>
          </template>
        </px-table>

        <px-empty-state v-else icon="package" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
      </div>

      <div v-if="products.length" class="pxrl__totalrow">
        <span>{{ $t('Total') }}</span>
        <span>{{ $t('Sales') }}: <b class="pxn-num">{{ totalSoldQty.toLocaleString() }}</b></span>
        <span>{{ $t('TotalAmount') }}: <b class="pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, totalSoldAmount, 2) }}</b></span>
      </div>

      <px-pagination
        v-if="products.length"
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
import { mapGetters } from "vuex";
import DateRangePicker from 'vue2-daterange-picker'
import 'vue2-daterange-picker/dist/vue2-daterange-picker.css'
import moment from 'moment'
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
import PxField from "@/components/px-next/PxField.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "Products Report"
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
      isLoading: true,
      serverParams: {
        sort: {
          field: "id",
          type: "desc"
        },
        page: 1,
        perPage: 10
      },
      limit: "10",
      totalRows: "",
      products: [],
      rows: [{
        statut: '',
        children: [],
      }],
      warehouses: [],
      warehouse_id: "",
      search_products:"",
      price_format_key: null,
      today_mode: true,
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
        { key: "code", label: this.$t("ProductCode"), strong: true },
        { key: "name", label: this.$t("ProductName") },
        { key: "sold_qty", label: this.$t("Sales"), align: "right" },
        { key: "sold_amount", label: this.$t("TotalAmount"), align: "right" },
      ];
    },
    totalSoldQty() {
      return (this.products || []).reduce((sum, p) => sum + (Number(p.sold_qty) || 0), 0);
    },
    totalSoldAmount() {
      return (this.products || []).reduce((sum, p) => sum + (Number(p.sold_amount) || 0), 0);
    }
  },

  methods: {
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

    onSearchInput(v) {
      this.search_products = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_products_report(1); }, 350);
    },

    onExport(item) {
      const k = item && item.key;
      if (k === "print") this.printTableOnly();
      else if (k === "pdf") this.export_PDF();
      else if (k === "xlsx") this.exportCsv();
    },

    exportCsv() {
      const head = this.columns.map(c => c.label);
      const lines = [head.join(",")].concat(
        (this.products || []).map(r => this.columns.map(c => `"${String(r[c.key] == null ? "" : r[c.key]).replace(/"/g, '""')}"`).join(","))
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "product_report.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    //------ Print Table Only - Print ALL product data with all columns
    printTableOnly() {
      const title = `${this.$t("Reports")} / ${this.$t("product_report")}`;
      const products = Array.isArray(this.rows[0]?.children) ? this.rows[0].children : [];

      let tableHTML = '<table style="width: 100%; border-collapse: collapse; font-size: 10px;">';
      tableHTML += '<thead><tr>';

      this.columns.forEach(col => {
        tableHTML += `<th style="border: 1px solid #ddd; padding: 6px 8px; background-color: #f5f5f5; font-weight: bold; text-align: left;">${col.label}</th>`;
      });
      tableHTML += '</tr></thead><tbody>';

      products.forEach(product => {
        tableHTML += '<tr>';
        this.columns.forEach(col => {
          let cellValue = '';

          if (col.key === 'code') {
            cellValue = product.code || '';
          } else if (col.key === 'name') {
            cellValue = product.name || '';
          } else if (col.key === 'sold_qty') {
            cellValue = product.sold_qty || 0;
          } else if (col.key === 'sold_amount') {
            cellValue = this.formatPriceWithSymbol(this.currentUser?.currency, product.sold_amount, 2);
          } else {
            cellValue = product[col.key] || '';
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

    //----------------------------------- Export PDF ------------------------------\\
    export_PDF() {
      var self = this;
      let pdf = new jsPDF("p", "pt");

      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      try {
        pdf.addFont(fontPath, "Vazirmatn", "normal");
        pdf.addFont(fontPath, "Vazirmatn", "bold");
      } catch(e) {}
      pdf.setFont("Vazirmatn", "normal");

      const headers = [
        self.$t("ProductCode"),
        self.$t("ProductName"),
        self.$t("Sales"),
        self.$t("TotalAmount")
      ];

      const body = (self.products || []).map(product => ([
        product.code,
        product.name,
        product.sold_qty,
        product.sold_amount
      ]));

      let totalsold_qty = self.products.reduce((sum, product) => sum + parseFloat(product.sold_qty || 0), 0);
      let totalsold_amount = self.products.reduce((sum, product) => sum + parseFloat(product.sold_amount || 0), 0);

      const footer = [[
        self.$t("Total"),
        '',
        totalsold_qty.toFixed(2),
        totalsold_amount.toFixed(this.priceDecimals)
      ]];

      const marginX = 40;
      const rtl =
        (self.$i18n && ['ar','fa','ur','he'].includes(self.$i18n.locale)) ||
        (typeof document !== 'undefined' && document.documentElement.dir === 'rtl');

      autoTable(pdf, {
        head: [headers],
        body: body,
        foot: footer,
        startY: 110,
        theme: 'striped',
        margin: { left: marginX, right: marginX },
        styles: { font: 'Vazirmatn', fontSize: 9, cellPadding: 4, halign: rtl ? 'right' : 'left', textColor: 33 },
        headStyles: { font: 'Vazirmatn', fontStyle: 'bold', fillColor: [26,86,219], textColor: 255 },
        alternateRowStyles: { fillColor: [245,247,250] },
        footStyles: { font: 'Vazirmatn', fontStyle: 'bold', fillColor: [26,86,219], textColor: 255 },
        didDrawPage: (d) => {
          const pageW = pdf.internal.pageSize.getWidth();
          const pageH = pdf.internal.pageSize.getHeight();

          pdf.setFillColor(26,86,219);
          pdf.rect(0, 0, pageW, 60, 'F');

          pdf.setTextColor(255);
          pdf.setFont('Vazirmatn', 'bold');
          pdf.setFontSize(16);
          const title = 'Products Report';
          rtl ? pdf.text(title, pageW - marginX, 38, { align: 'right' })
              : pdf.text(title, marginX, 38);

          pdf.setTextColor(33);

          pdf.setFontSize(8);
          const pn = `${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`;
          rtl ? pdf.text(pn, marginX, pageH - 14, { align: 'left' })
              : pdf.text(pn, pageW - marginX, pageH - 14, { align: 'right' });
        }
      });

      pdf.save("Products Report.pdf");
    },

    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_products_report(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_products_report(1); } },

   //----------------------------- Submit Date Picker -------------------\\
   Submit_filter_dateRange() {
      var self = this;
      self.startDate =  self.dateRange.startDate.toJSON().slice(0, 10);
      self.endDate = self.dateRange.endDate.toJSON().slice(0, 10);
      self.Get_products_report(1);
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

    //---------------------- Event Select Warehouse ------------------------------\\
    Selected_Warehouse(value) {
      if (value === null || value === undefined) {
        this.warehouse_id = "";
      }
      this.Get_products_report(1);
    },


    //----------------------------- Get_products_report------------------\\
    Get_products_report(page) {
      NProgress.start();
      NProgress.set(0.1);
      this.get_data_loaded();

      axios
        .get(
          "report/product_report?page=" +
            page +
            "&limit=" +
            this.limit +
            "&warehouse_id=" +
            this.warehouse_id +
            "&to=" +
            this.endDate +
            "&from=" +
            this.startDate +
            "&search=" +
            this.search_products
        )
        .then(response => {
          this.warehouses = response.data.warehouses;
          this.products = response.data.products;
          this.totalRows = response.data.totalRows;
          this.rows[0].children = this.products;
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
    this.Get_products_report(1);
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
@media (max-width: 560px) { .pxrl__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxrl__tablewrap { margin-top: var(--pxn-space-5); }
.pxrl__totalrow { display: flex; align-items: center; justify-content: flex-end; gap: var(--pxn-space-6); margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.pxrl__totalrow > span:first-child { margin-right: auto; font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxrl ::v-deep .daterangepicker { z-index: 2055 !important; }
</style>
