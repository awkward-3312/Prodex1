<template>
  <div class="px-next pxrl">
    <px-page-header :title="$t('PurchasesReport')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('PurchasesReport') }]">
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
          :autoApply="true"
          :showDropdowns="true"
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
        <px-field :label="$t('Reference')">
          <template #default="{ id }"><px-input :id="id" v-model="Filter_Ref" :placeholder="$t('Reference')" /></template>
        </px-field>
        <px-field :label="$t('Supplier')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_Supplier" :reduce="o => o.value" :placeholder="$t('Choose_Supplier')"
              :options="suppliers.map(s => ({ label: s.name, value: s.id }))" />
          </template>
        </px-field>
        <px-field :label="$t('warehouse')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_warehouse" :reduce="o => o.value" :placeholder="$t('Choose_Warehouse')"
              :options="warehouses.map(w => ({ label: w.name, value: w.id }))" />
          </template>
        </px-field>
        <px-field :label="$t('Status')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_status" :reduce="o => o.value" :clearable="false"
              :options="[{label: $t('All') || 'All', value: ''}, {label: $t('received') || 'Received', value: 'received'}, {label: $t('Pending'), value: 'pending'}, {label: $t('Ordered'), value: 'ordered'}]" />
          </template>
        </px-field>
        <px-field :label="$t('PaymentStatus')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_Payment" :reduce="o => o.value" :clearable="false"
              :options="[{label: $t('All') || 'All', value: ''}, {label: $t('Paid'), value: 'paid'}, {label: $t('partial'), value: 'partial'}, {label: $t('Unpaid'), value: 'unpaid'}]" />
          </template>
        </px-field>
      </div>
      <div class="pxrl__filters-act">
        <px-button size="sm" variant="primary" icon="filter" @click="applyFilters">{{ $t('Filter') }}</px-button>
        <px-button size="sm" variant="ghost" icon="x" @click="Reset_Filter">{{ $t('Reset') }}</px-button>
      </div>
    </div>

    <div v-if="isLoading" class="pxrl__pad">
      <px-skeleton variant="table" :rows="10" :columns="8" />
    </div>

    <template v-else>
      <div class="pxrl__tablewrap">
        <px-table
          v-if="purchases.length"
          :columns="columns"
          :rows="purchases"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          @sort="onSort"
        >
          <template #cell-date="{ row }">{{ formatDisplayDate(row.date) }}</template>
          <template #cell-Ref="{ row }">
            <router-link v-if="row.id" :to="{ name: 'detail_purchase', params: { id: row.id } }" class="pxrl__link">{{ row.Ref }}</router-link>
            <span v-else>{{ row.Ref }}</span>
          </template>
          <template #cell-statut="{ row }">
            <px-badge v-if="row.statut === 'received'" tone="success">{{ $t('received') || 'Received' }}</px-badge>
            <px-badge v-else-if="row.statut === 'pending'" tone="info">{{ $t('Pending') }}</px-badge>
            <px-badge v-else tone="warning">{{ $t('Ordered') }}</px-badge>
          </template>
          <template #cell-payment_status="{ row }">
            <px-badge v-if="row.payment_status === 'paid'" tone="success">{{ $t('Paid') }}</px-badge>
            <px-badge v-else-if="row.payment_status === 'partial'" tone="info">{{ $t('partial') }}</px-badge>
            <px-badge v-else tone="warning">{{ $t('Unpaid') }}</px-badge>
          </template>
          <template #cell-GrandTotal="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, row.GrandTotal, 2) }}</span></template>
          <template #cell-paid_amount="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, row.paid_amount, 2) }}</span></template>
          <template #cell-due="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, row.due, 2) }}</span></template>
        </px-table>

        <px-empty-state v-else icon="shopping-cart" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
      </div>

      <div v-if="purchases.length" class="pxrl__totalrow">
        <span>{{ $t('Total') }}</span>
        <span>{{ $t('Total') }}: <b class="pxn-num">{{ money(footerTotals.grandTotal) }}</b></span>
        <span>{{ $t('Paid') }}: <b class="pxn-num">{{ money(footerTotals.paidTotal) }}</b></span>
        <span>{{ $t('Due') }}: <b class="pxn-num">{{ money(footerTotals.dueTotal) }}</b></span>
      </div>

      <px-pagination
        v-if="purchases.length"
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
import DateRangePicker from 'vue2-daterange-picker'
import 'vue2-daterange-picker/dist/vue2-daterange-picker.css'
import moment from 'moment'
import Util from '../../../../utils'
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
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "Report Purchases"
  },
  components: {
    "date-range-picker": DateRangePicker,
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxBadge,
    PxField, PxInput, PxEmptyState, "vs-px": VsPx
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
      limit: "10",
      search: "",
      totalRows: "",
      Filter_Supplier: "",
      Filter_warehouse: "",
      Filter_Ref: "",
      Filter_status: "",
      Filter_Payment: "",
      suppliers: [],
      warehouses: [],
      rows: [{
          statut: 'Total',
          children: [
          ],
      },],
      purchases: [],
      today_mode: true,
      to: "",
      from: "",
      price_format_key: null
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    activeFilterCount() {
      return [this.Filter_Ref, this.Filter_Supplier, this.Filter_warehouse, this.Filter_status, this.Filter_Payment]
        .filter(v => v !== "" && v !== null && v !== undefined).length;
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
        { key: "provider_name", label: this.$t("Supplier") },
        { key: "warehouse_name", label: this.$t("warehouse") },
        { key: "statut", label: this.$t("Status") },
        { key: "GrandTotal", label: this.$t("Total"), align: "right" },
        { key: "paid_amount", label: this.$t("Paid"), align: "right" },
        { key: "due", label: this.$t("Due"), align: "right" },
        { key: "payment_status", label: this.$t("PaymentStatus") },
        { key: "user_name", label: this.$t("AddedBy") }
      ];
    },

    footerTotals() {
      const list = Array.isArray(this.purchases) ? this.purchases : [];
      let grand = 0;
      let paid = 0;
      let due = 0;

      for (let i = 0; i < list.length; i++) {
        const row = list[i] || {};
        const g = parseFloat(row.GrandTotal) || 0;
        const p = parseFloat(row.paid_amount) || 0;
        const d = parseFloat(row.due) || 0;
        if (!isNaN(g)) grand += g;
        if (!isNaN(p)) paid += p;
        if (!isNaN(d)) due += d;
      }

      return { grandTotal: grand, paidTotal: paid, dueTotal: due };
    }
  },

  methods: {
    money(v) {
      return this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, v, 2);
    },

    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Purchases(1); }, 350);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Purchases(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Purchases(1); } },

    onSort({ key, dir }) {
      let field = key;
      if (key === "provider_name") field = "provider_id";
      this.updateParams({ sort: { type: dir, field: field } });
      this.Get_Purchases(this.serverParams.page);
    },

    applyFilters() { this.updateParams({ page: 1 }); this.Get_Purchases(this.serverParams.page); },

    onExport(item) {
      const k = item && item.key;
      if (k === "print") this.printTableOnly();
      else if (k === "pdf") this.Purchase_PDF();
      else if (k === "xlsx") this.exportCsv();
    },

    exportCsv() {
      const head = this.columns.map(c => c.label);
      const lines = [head.join(",")].concat(
        (this.purchases || []).map(r =>
          this.columns.map(c => {
            let v = r[c.key];
            if (c.key === "date") v = this.formatDisplayDate(r.date);
            return `"${String(v == null ? "" : v).replace(/"/g, '""')}"`;
          }).join(",")
        )
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "purchases_report.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    //------ Print Table Only
    printTableOnly() {
      const title = `${this.$t("Reports")} / ${this.$t("PurchasesReport")}`;
      const list = Array.isArray(this.purchases) ? this.purchases : [];

      let tableHTML = '<table style="width: 100%; border-collapse: collapse; font-size: 10px;">';
      tableHTML += '<thead><tr>';

      this.columns.forEach(col => {
        tableHTML += `<th style="border: 1px solid #ddd; padding: 6px 8px; background-color: #f5f5f5; font-weight: bold; text-align: left;">${col.label}</th>`;
      });
      tableHTML += '</tr></thead><tbody>';

      list.forEach(p => {
        tableHTML += '<tr>';
        this.columns.forEach(col => {
          let cellValue = '';

          if (col.key === 'date') {
            cellValue = this.formatDisplayDate(p.date) || '';
          } else if (col.key === 'GrandTotal') {
            cellValue = this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, p.GrandTotal, 2);
          } else if (col.key === 'paid_amount') {
            cellValue = this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, p.paid_amount, 2);
          } else if (col.key === 'due') {
            cellValue = this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, p.due, 2);
          } else {
            cellValue = p[col.key] || '';
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

    //------ Reset Filter
    Reset_Filter() {
      this.search = "";
      this.Filter_Supplier = "";
      this.Filter_status = "";
      this.Filter_Payment = "";
      this.Filter_Ref = "";
      this.Filter_warehouse = "";
      this.Get_Purchases(this.serverParams.page);
    },

    //------------------------------Formetted Numbers -------------------------\\
    formatNumber(number, dec) {
      const value = (typeof number === "string"
        ? number
        : Number(number || 0).toString()
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
        if (number === null || number === undefined || number === '') {
          number = 0;
        }
        const n = Number(number);
        if (isNaN(n) || !isFinite(n)) {
          return this.formatNumber(0, dec || 2);
        }

        const decimals = this.priceDecimals;
        const key = getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        const effectiveKey = key || null;
        return formatPriceDisplayHelper(n, decimals, effectiveKey);
      } catch (e) {
        return this.formatNumber(0, dec || 2);
      }
    },

    formatPriceWithSymbol(symbol, number, dec) {
      try {
        const safeSymbol = symbol || (this.currentUser && this.currentUser.currency) || "";
        const value = this.formatPriceDisplay(number, dec);
        return safeSymbol ? `${safeSymbol} ${value}` : value;
      } catch (e) {
        const safeSymbol = symbol || "";
        const value = this.formatPriceDisplay(number, dec);
        return safeSymbol ? `${safeSymbol} ${value}` : value;
      }
    },

    //----------------------------------- Purchase PDF ------------------------------\\
    Purchase_PDF() {
      var self = this;
      let pdf = new jsPDF("p", "pt");

      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      try {
        pdf.addFont(fontPath, "Vazirmatn", "normal");
        pdf.addFont(fontPath, "Vazirmatn", "bold");
      } catch(e) {}
      pdf.setFont("Vazirmatn", "normal");

      const headers = [
        self.$t("Reference"),
        self.$t("Supplier"),
        self.$t("warehouse"),
        self.$t("Status"),
        self.$t("Total"),
        self.$t("Paid"),
        self.$t("Due"),
        self.$t("PaymentStatus"),
        self.$t("AddedBy"),
      ];

      const body = (self.purchases || []).map(purchase => ([
        purchase.Ref,
        purchase.provider_name,
        purchase.warehouse_name,
        purchase.statut,
        purchase.GrandTotal,
        purchase.paid_amount,
        purchase.due,
        purchase.payment_status,
        purchase.user_name || '---'
      ]));

      let totalGrandTotal = self.purchases.reduce((sum, purchase) => sum + parseFloat(purchase.GrandTotal || 0), 0);
      let totalPaidAmount = self.purchases.reduce((sum, purchase) => sum + parseFloat(purchase.paid_amount || 0), 0);
      let totalDue = self.purchases.reduce((sum, purchase) => sum + parseFloat(purchase.due || 0), 0);

      const footer = [[
        self.$t("Total"),
        '',
        '',
        '',
        totalGrandTotal.toFixed(this.priceDecimals),
        totalPaidAmount.toFixed(this.priceDecimals),
        totalDue.toFixed(this.priceDecimals),
        '',
        ''
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
          const title = 'Purchase report';
          rtl ? pdf.text(title, pageW - marginX, 38, { align: 'right' })
              : pdf.text(title, marginX, 38);

          pdf.setTextColor(33);

          pdf.setFontSize(8);
          const pn = `${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`;
          rtl ? pdf.text(pn, marginX, pageH - 14, { align: 'left' })
              : pdf.text(pn, pageW - marginX, pageH - 14, { align: 'right' });
        }
      });

      pdf.save("Purchase_report.pdf");

    },

    //---------------------------------------- Set To Strings-------------------------\\
    setToStrings() {
      if (this.Filter_Supplier === null) {
        this.Filter_Supplier = "";
      } else if (this.Filter_warehouse === null) {
        this.Filter_warehouse = "";
      }
    },

    //----------------------------- Submit Date Picker -------------------\\
    Submit_filter_dateRange() {
      const start = this.dateRange.startDate ? moment(this.dateRange.startDate) : null;
      const end = this.dateRange.endDate ? moment(this.dateRange.endDate) : null;

      if (start && end) {
        this.startDate = start.format("YYYY-MM-DD");
        this.endDate = end.format("YYYY-MM-DD");
        this.Get_Purchases(1);
      }
    },


    get_data_loaded() {
      var self = this;
      if (self.today_mode) {
        let startDate = new Date("01/01/2000");
        let endDate = new Date();

        self.startDate = moment(startDate).format("YYYY-MM-DD");
        self.endDate = moment(endDate).format("YYYY-MM-DD");

        self.dateRange.startDate = startDate;
        self.dateRange.endDate = endDate;
      }
    },


    //----------------------------------------- Get all Purchases ------------------------------\\
    Get_Purchases(page) {
      NProgress.start();
      NProgress.set(0.1);
      this.setToStrings();
      this.get_data_loaded();
      axios
        .get(
          "/report/purchases?page=" +
            page +
            "&Ref=" +
            this.Filter_Ref +
            "&provider_id=" +
            this.Filter_Supplier +
            "&warehouse_id=" +
            this.Filter_warehouse +
            "&statut=" +
            this.Filter_status +
            "&payment_statut=" +
            this.Filter_Payment +
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
          this.purchases = response.data.purchases;
          this.suppliers = response.data.suppliers;
          this.warehouses = response.data.warehouses;
          this.totalRows = response.data.totalRows;
          this.rows[0].children = this.purchases;

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
    //----------------------------------------- Format Display Date (for tables) -------------------------------\\
    formatDisplayDate(value) {
      if (!value) return '';
      const dateFormat = this.$store.getters.getDateFormat || Util.getDateFormat(this.$store);
      return Util.formatDisplayDate(value, dateFormat);
    },

    fmt(d) {
      return moment(d).format("YYYY-MM-DD");
    }
  },
  //----------------------------- Created function-------------------\\
  created() {
    this.Get_Purchases(1);
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
