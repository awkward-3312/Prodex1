<template>
  <div class="px-next pxrt">
    <px-page-header :title="$t('Report_Transactions')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('Report_Transactions') }]">
      <template #actions>
        <px-menu :items="exportMenu" align="end" @select="onExport">
          <template #trigger>
            <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') || 'Exportar' }}</px-button>
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
            <button type="button" class="pxrt__daterange pxn-ring">
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

    <div v-if="filtersOpen" class="pxrt__filters">
      <div class="pxrt__filters-grid">
        <px-field :label="$t('Customer')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_client" :reduce="o => o.value" :placeholder="$t('Choose_Customer')"
              :options="clients.map(c => ({ label: c.name, value: c.id }))" />
          </template>
        </px-field>
        <px-field :label="$t('Sale')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_sale" :reduce="o => o.value" :placeholder="$t('PleaseSelect')"
              :options="sales.map(s => ({ label: s.Ref, value: s.id }))" />
          </template>
        </px-field>
        <px-field :label="$t('Supplier')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_provider" :reduce="o => o.value" :placeholder="$t('Choose_Supplier')"
              :options="suppliers.map(s => ({ label: s.name, value: s.id }))" />
          </template>
        </px-field>
        <px-field :label="$t('Purchase')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_purchase" :reduce="o => o.value" :placeholder="$t('PleaseSelect')"
              :options="purchases.map(p => ({ label: p.Ref, value: p.id }))" />
          </template>
        </px-field>
        <px-field :label="$t('Paymentchoice')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_Reg" :reduce="o => o.value" :placeholder="$t('PleaseSelect')"
              :options="payment_methods.map(m => ({ label: m.name, value: m.id }))" />
          </template>
        </px-field>
      </div>
      <div class="pxrt__filters-act">
        <px-button size="sm" variant="primary" icon="filter" @click="applyFilters">{{ $t('Filter') }}</px-button>
        <px-button size="sm" variant="ghost" icon="x" @click="Reset_Filter">{{ $t('Reset') }}</px-button>
      </div>
    </div>

    <div v-if="isLoading" class="pxrt__pad">
      <px-skeleton variant="table" :rows="10" :columns="8" />
    </div>

    <template v-else>
      <div class="pxrt__tablewrap">
        <px-table
          v-if="payments.length"
          :columns="columns"
          :rows="payments"
          row-key="__rowkey"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
        >
          <template #cell-date="{ row }">{{ formatDisplayDate(row.date) }}</template>
          <template #cell-Ref_Sale="{ row }">
            <router-link v-if="row.type === 'sale' && row.sale_id" :to="{ name: 'detail_sale', params: { id: row.sale_id } }" class="pxrt__link">{{ row.Ref_Sale }}</router-link>
            <router-link v-else-if="row.type === 'purchase' && row.purchase_id" :to="{ name: 'detail_purchase', params: { id: row.purchase_id } }" class="pxrt__link">{{ row.Ref_Sale }}</router-link>
            <span v-else>{{ row.Ref_Sale }}</span>
          </template>
          <template #cell-montant="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, row.montant, 2) }}</span></template>
        </px-table>

        <px-empty-state v-else icon="files" :title="$t('NodataAvailable') || 'Sin transacciones'" />
      </div>

      <div v-if="payments.length" class="pxrt__totalrow">
        <span>{{ $t('Total') }}</span>
        <span class="pxn-num">{{ sumCount(rows[0]) }}</span>
      </div>

      <px-pagination
        v-if="payments.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />

      <px-card :title="$t('Summary_by_Payment_Method') || 'Resumen por método de pago'" class="pxrt__summary">
        <template #actions>
          <px-button size="sm" variant="secondary" icon="file-text" @click="Payment_Summary_PDF()">{{ $t('Summary_PDF') || 'PDF resumen' }}</px-button>
        </template>
        <div class="pxrt-tbl__wrap pxn-scroll">
          <table class="pxrt-tbl">
            <thead>
              <tr>
                <th>{{ $t('Payment_Method') }}</th>
                <th class="is-right">{{ $t('Total_Sales') || 'Total ventas' }}</th>
                <th class="is-right">{{ $t('Sale_Refunds') || 'Reembolsos venta' }}</th>
                <th class="is-right">{{ $t('Total_Purchases') || 'Total compras' }}</th>
                <th class="is-right">{{ $t('Purchase_Refunds') || 'Reembolsos compra' }}</th>
                <th class="is-right">{{ $t('Total_Expenses') || 'Total gastos' }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!payment_summary.length"><td colspan="6" class="pxrt-tbl__empty">{{ $t('NodataAvailable') }}</td></tr>
              <tr v-for="(item, i) in payment_summary" :key="i">
                <td>{{ item.payment_method }}</td>
                <td class="is-right pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, item.sale_total, 2) }}</td>
                <td class="is-right pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, item.sale_return_total || 0, 2) }}</td>
                <td class="is-right pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, item.purchase_total, 2) }}</td>
                <td class="is-right pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, item.purchase_return_total || 0, 2) }}</td>
                <td class="is-right pxn-num">{{ formatPriceWithSymbol(currentUser && currentUser.currency, item.expense_total, 2) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </px-card>
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
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "Report Transactions"
  },
  components: {
    "date-range-picker": DateRangePicker,
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxCard,
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
      search: "",
      totalRows: "",
      Filter_client: "",
      Filter_sale: "",
      Filter_provider: "",
      Filter_purchase: "",
      Filter_Reg: "",
      payments: [],
      payment_methods:[],
      clients: [],
      suppliers: [],
      payment_summary: [],
      rows: [{
        payment_method: 'Total',
          children: [
          ],
      },],
      sales: [],
      purchases: [],
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
      price_format_key: null
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    activeFilterCount() {
      return [this.Filter_client, this.Filter_sale, this.Filter_provider, this.Filter_purchase, this.Filter_Reg]
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
        { key: "date", label: this.$t("Date") },
        { key: "Ref", label: this.$t("Reference") },
        { key: "Ref_Sale", label: this.$t("Sale_Purchase_Ref") },
        { key: "client_name", label: this.$t("Customer_Provider") },
        { key: "payment_method", label: this.$t("Payment_Method") },
        { key: "account_name", label: this.$t("Account") },
        { key: "montant", label: this.$t("Amount"), align: "right" },
        { key: "user_name", label: this.$t("AddedBy") }
      ];
    }

  },
  methods: {

    sumCount(rowObj) {
      if (!rowObj || !Array.isArray(rowObj.children)) {
        return this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, 0, 2);
      }
      let sum = 0;
      for (let i = 0; i < rowObj.children.length; i++) {
        const value = Number(rowObj.children[i].montant) || 0;
        if (Number.isFinite(value)) {
          sum += value;
        }
      }
      return this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, sum, 2);
    },

    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Payments_Sales(1); }, 350);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Payments_Sales(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Payments_Sales(1); } },

    applyFilters() { this.updateParams({ page: 1 }); this.Payments_Sales(this.serverParams.page); },

    onExport(item) {
      const k = item && item.key;
      if (k === "print") this.printTableOnly();
      else if (k === "pdf") this.Payment_PDF();
      else if (k === "xlsx") this.exportCsv();
    },

    exportCsv() {
      const head = this.columns.map(c => c.label);
      const lines = [head.join(",")].concat(
        (this.payments || []).map(r =>
          this.columns.map(c => {
            let v = r[c.key];
            if (c.key === "date") v = this.formatDisplayDate(r.date);
            else if (c.key === "montant") v = r.montant;
            else if (c.key === "Ref_Sale") v = r.Ref_Sale || "";
            return `"${String(v == null ? "" : v).replace(/"/g, '""')}"`;
          }).join(",")
        )
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "payments.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    formatDisplayDate(value) {
      if (!value) return '';
      const dateFormat = this.$store.getters.getDateFormat || Util.getDateFormat(this.$store);
      return Util.formatDisplayDate(value, dateFormat);
    },

    fmt(d) {
      return moment(d).format("YYYY-MM-DD");
    },

    formatPriceDisplay(number, dec) {
      try {
        const n = Number(number || 0);
        if (isNaN(n)) {
          const n2 = Number(number || 0);
          return n2.toLocaleString(undefined, { maximumFractionDigits: dec || 2 });
        }

        const decimals = this.priceDecimals;
        const key = getPriceFormatSetting({ store: this.$store });
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

    //------ Reset Filter
    Reset_Filter() {
      this.search = "";
      this.Filter_client = "";
      this.Filter_sale = "";
      this.Filter_provider = "";
      this.Filter_purchase = "";
      this.Filter_Reg = "";
      this.Payments_Sales(this.serverParams.page);
    },

    //------ Print Table Only
    printTableOnly() {
      // Get payments data from rows[0].children or this.payments
      const paymentsData = Array.isArray(this.rows[0]?.children) && this.rows[0].children.length > 0
        ? this.rows[0].children
        : (this.payments || []);

      let tableHtml = `<table class="vgt-table table table-hover tableOne">`;
      tableHtml += `<thead><tr>`;
      this.columns.forEach(col => {
        tableHtml += `<th class="text-left">${col.label}</th>`;
      });
      tableHtml += `</tr></thead>`;
      tableHtml += `<tbody>`;
      paymentsData.forEach(row => {
        tableHtml += `<tr>`;
        this.columns.forEach(col => {
          let cellContent = row[col.key];
          if (col.key === 'date') {
            cellContent = this.formatDisplayDate(row.date);
          } else if (col.key === 'montant') {
            cellContent = this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, row.montant, 2);
          } else if (col.key === 'Ref_Sale') {
            cellContent = row.Ref_Sale || '';
          }
          tableHtml += `<td class="text-left">${cellContent || ''}</td>`;
        });
        tableHtml += `</tr>`;
      });
      tableHtml += `</tbody>`;

      const totalAmount = this.sumCount(this.rows[0]);
      tableHtml += `<tfoot><tr>`;
      tableHtml += `<td class="text-left font-weight-bold">${this.$t('Total')}</td>`;
      tableHtml += `<td colspan="5"></td>`;
      tableHtml += `<td class="text-left font-weight-bold">${totalAmount}</td>`;
      tableHtml += `<td colspan="1"></td>`;
      tableHtml += `</tr></tfoot>`;
      tableHtml += `</table>`;

      const w = window.open("", "_blank");
      if (!w) {
        window.print();
        return;
      }

      const title = `${this.$t("Reports")} / ${this.$t("Report_Transactions")}`;
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

    //---------------------------------------- Set To Strings-------------------------\\
    setToStrings() {
      if (this.Filter_client === null) {
        this.Filter_client = "";
      } else if (this.Filter_sale === null) {
        this.Filter_sale = "";
      } else if (this.Filter_purchase === null) {
        this.Filter_purchase = "";
      } else if (this.Filter_provider === null) {
        this.Filter_provider = "";
      }
    },

    Payment_PDF() {
      const pdf = new jsPDF("p", "pt");

      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      pdf.addFont(fontPath, "VazirmatnBold", "bold");
      pdf.setFont("VazirmatnBold");

      const columns = [
        { header: "Date", dataKey: "date" },
        { header: "Reference", dataKey: "Ref" },
        { header: "Sale / Purchase Ref", dataKey: "Ref_Sale" },
        { header: "Customer / Provider", dataKey: "client_name" },
        { header: "Payment Method", dataKey: "payment_method" },
        { header: "Account", dataKey: "account_name" },
        { header: "Amount", dataKey: "montant" },
        { header: "Added By", dataKey: "user_name" }
      ];

      const totalGrandTotal = this.payments.reduce(
        (sum, payment) => sum + parseFloat(payment.montant || 0),
        0
      );

      const footer = [{
        date: "Total",
        Ref: '',
        Ref_Sale: '',
        client_name: '',
        payment_method: '',
        account_name: '',
        montant: `${totalGrandTotal.toFixed(this.priceDecimals)}`,
        user_name: ''
      }];

      autoTable(pdf, {
        columns: columns,
        body: this.payments,
        foot: footer,
        startY: 70,
        theme: "grid",
        didDrawPage: (data) => {
          pdf.setFont("VazirmatnBold");
          pdf.setFontSize(18);
          pdf.text("Report Transactions", 40, 25);
        },
        styles: {
          font: "VazirmatnBold",
          halign: "center"
        },
        headStyles: {
          fillColor: [26, 86, 219],
          textColor: 255,
          fontStyle: "bold"
        },
        footStyles: {
          fillColor: [26, 86, 219],
          textColor: 255,
          fontStyle: "bold"
        }
      });

      pdf.save("Report_Transactions.pdf");
    },


    Payment_Summary_PDF() {
      const pdf = new jsPDF("p", "pt");

      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      pdf.addFont(fontPath, "VazirmatnBold", "bold");
      pdf.setFont("VazirmatnBold");

      const summaryColumns = [
        { header: "Payment Method", dataKey: "payment_method" },
        { header: "Total Sales", dataKey: "sale_total" },
        { header: "Total Purchases", dataKey: "purchase_total" },
        { header: "Total Expenses", dataKey: "expense_total" }
      ];

      const summaryBody = this.payment_summary.map(item => ({
        payment_method: item.payment_method,
        sale_total: item.sale_total.toFixed(this.priceDecimals),
        purchase_total: item.purchase_total.toFixed(this.priceDecimals),
        expense_total: item.expense_total.toFixed(this.priceDecimals)
      }));

      autoTable(pdf, {
        columns: summaryColumns,
        body: summaryBody,
        startY: 70,
        theme: "grid",
        didDrawPage: () => {
          pdf.setFontSize(18);
          pdf.text("Payment Summary Report", 40, 25);
        },
        styles: {
          font: "VazirmatnBold",
          halign: "center"
        },
        headStyles: {
          fillColor: [26, 86, 219],
          textColor: 255,
          fontStyle: "bold"
        }
      });

      pdf.save("Payment_Summary_Report.pdf");
    },


     //----------------------------- Submit Date Picker -------------------\\
    Submit_filter_dateRange() {
      const pad = (n) => String(n).padStart(2, "0");
      const formatLocalDate = (d) =>
        `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
      this.startDate = formatLocalDate(new Date(this.dateRange.startDate));
      this.endDate = formatLocalDate(new Date(this.dateRange.endDate));
      this.Payments_Sales(1);
    },


    get_data_loaded() {
      const self = this;
      if (self.today_mode) {
        const pad = (n) => String(n).padStart(2, "0");
        const today = new Date();
        const todayStr = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;
        const startOfDay = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        const endOfDay = new Date(today.getFullYear(), today.getMonth(), today.getDate(), 23, 59, 59, 999);

        self.startDate = todayStr;
        self.endDate = todayStr;
        self.dateRange.startDate = startOfDay;
        self.dateRange.endDate = endOfDay;
      }
    },

    //-------------------------------- Get All Payments Sales ---------------------\\
    Payments_Sales(page) {
      NProgress.start();
      NProgress.set(0.1);

      this.isLoading = true;
      this.get_data_loaded();

      axios
        .get("report/report_transactions", {
          params: {
            page: page,
            client_id: this.Filter_client,
            sale_id: this.Filter_sale,
            provider_id: this.Filter_provider,
            purchase_id: this.Filter_purchase,
            payment_method_id: this.Filter_Reg,
            SortField: this.serverParams.sort.field,
            SortType: this.serverParams.sort.type,
            search: this.search,
            limit: this.limit,
            to: this.endDate,
            from: this.startDate,
          }
        })
        .then(response => {
          this.payments = (response.data.payments || []).map((p, i) => Object.assign({ __rowkey: p.id != null ? `${p.type || 'x'}-${p.id}-${i}` : `r-${i}` }, p));
          this.clients = response.data.clients;
          this.suppliers = response.data.suppliers;
          this.sales = response.data.sales;
          this.purchases = response.data.purchases;
          this.payment_methods = response.data.payment_methods;
          this.payment_summary = response.data.payment_summary;
          this.totalRows = response.data.totalRows;

          if (this.rows && this.rows[0]) {
            this.rows[0].children = this.payments;
          }

          NProgress.done();
          this.isLoading = false;
          this.today_mode = false;
        })
        .catch(error => {
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
            this.today_mode = false;
          }, 500);
        });
    }

  },

  //----------------------------- Created function-------------------\\
  created: function() {
    try {
      const key = getPriceFormatSetting({ store: this.$store });
      if (key) {
        this.price_format_key = key;
      }
    } catch (e) {
      // ignore
    }
    this.Payments_Sales(1);
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrt { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrt { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrt__pad { padding: var(--pxn-space-6) 0; }
.pxrt__daterange {
  display: inline-flex; align-items: center; gap: var(--pxn-space-3);
  height: var(--pxn-control-h-sm); padding: 0 var(--pxn-space-4);
  border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); font: inherit; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink); cursor: pointer;
}
.pxrt__daterange:hover { background: var(--pxn-surface-2); }
.pxrt__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxrt__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxrt__filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxrt__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxrt__filters-act { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); }
.pxrt__tablewrap { margin-top: var(--pxn-space-5); }
.pxrt__link { color: var(--pxn-primary); }
.pxrt__totalrow { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-6); margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxrt__summary { margin-top: var(--pxn-space-6); }
.pxrt-tbl__wrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); overflow-x: auto; }
.pxrt-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxrt-tbl th { padding: var(--pxn-space-3) var(--pxn-space-4); text-align: left; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap; }
.pxrt-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink); }
.pxrt-tbl tr:last-child td { border-bottom: 0; }
.pxrt-tbl .is-right { text-align: right; }
.pxrt-tbl__empty { text-align: center; color: var(--pxn-ink-3); }
.pxrt ::v-deep .daterangepicker { z-index: 2055 !important; }
</style>
