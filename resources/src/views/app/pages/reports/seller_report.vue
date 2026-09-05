<template>
  <div class="px-next pxrl">
    <px-page-header :title="$t('Seller_report')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('Seller_report') }]">
      <template #actions>
        <px-menu :items="exportMenu" align="end" @select="onExport">
          <template #trigger>
            <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button>
          </template>
        </px-menu>
        <px-button variant="primary" size="sm" icon="refresh-cw" @click="Seller_report(serverParams.page)">{{ $t('Refresh') || 'Actualizar' }}</px-button>
      </template>
      <template #meta>
        <date-range-picker
          v-model="dateRange"
          :locale-data="locale"
          :time-picker="true"
          :time-picker-seconds="true"
          :autoApply="true"
          :showDropdowns="true"
          @update="Submit_filter_dateRange"
        >
          <template v-slot:input="picker">
            <button type="button" class="pxrl__daterange pxn-ring">
              <lucide-icon name="calendar-days" :size="14" />
              {{ formatDateTime(picker.startDate) }} — {{ formatDateTime(picker.endDate) }}
            </button>
          </template>
        </date-range-picker>
      </template>
    </px-page-header>

    <px-card class="pxrl__filtercard">
      <div class="pxrl__filterrow">
        <px-field :label="$t('warehouse')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="warehouse_id" :reduce="o => o.value" :placeholder="$t('Choose_Warehouse')"
              :options="warehouses.map(w => ({ label: w.name, value: w.id }))" @input="Selected_Warehouse" />
          </template>
        </px-field>
      </div>
    </px-card>

    <div v-if="isLoading" class="pxrl__pad">
      <px-skeleton variant="lines" :rows="8" />
    </div>

    <template v-else>
      <div class="pxrl__stats">
        <px-stat icon="users" :label="$t('Sellers') || 'Vendedores'" :value="num(sellerStats.totalSellers)" bordered />
        <px-stat icon="banknote" :label="$t('TotalSales')" :value="money(sellerStats.totalSales)" bordered />
        <px-stat icon="bar-chart-3" :label="$t('AvgPerSeller') || 'Promedio por vendedor'" :value="money(sellerStats.avgSales)" bordered />
        <px-stat icon="star" :label="$t('TopSeller') || 'Mejor vendedor'" :value="sellerStats.topSellerName || '-'" :sub="sellerStats.topSellerName ? money(sellerStats.topSellerSales) : ''" bordered />
      </div>

      <div class="pxrl__cols pxrl__cols--87">
        <px-card :title="`${$t('Seller')} · ${$t('TotalSales')}`">
          <template #actions>
            <span v-if="topSellersChartData.length" class="pxrl__cardmeta">{{ topSellersChartData.length }} {{ $t('Sellers') || 'vendedores' }}</span>
          </template>
          <apexchart
            v-if="topSellersChartData.length"
            type="bar"
            height="320"
            :options="topSellersChartOptions"
            :series="topSellersChartSeries"
          />
          <px-empty-state v-else icon="bar-chart-3" :title="$t('No_Data') || 'Sin datos'" description="" />

          <div v-if="topSellersChartData.length" class="pxrl__ranklist">
            <div class="pxrl__rankhead">
              <span>{{ $t('TopSeller') || 'Mejor vendedor' }}</span>
              <span class="pxn-num">{{ sellerStats.topSellerName }} · {{ money(sellerStats.topSellerSales) }}</span>
            </div>
            <div v-for="(s, idx) in topSellersChartData.slice(0, 5)" :key="s.name" class="pxrl__rankrow">
              <span>{{ idx + 1 }}. {{ s.name }}</span>
              <span class="pxn-num">{{ money(s.sales) }}</span>
            </div>
          </div>
        </px-card>

        <px-card :title="$t('SalesByPaymentMethod') || 'Ventas por método de pago'">
          <apexchart
            v-if="paymentMethodsChartSeries.length && paymentMethodsChartSeries[0].data.length"
            type="bar"
            height="320"
            :options="paymentMethodsChartOptions"
            :series="paymentMethodsChartSeries"
          />
          <px-empty-state v-else icon="pie-chart" :title="$t('No_Data') || 'Sin datos'" description="" />
        </px-card>
      </div>

      <px-toolbar
        :search="search"
        :search-placeholder="$t('Search_this_table')"
        @update:search="onSearchInput"
      />

      <div class="pxrl__tablewrap">
        <px-table
          v-if="payments.length"
          :columns="columns"
          :rows="payments"
          row-key="__rowkey"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          @sort="onSort"
        >
          <template #cell-total_sales="{ row }"><span class="pxn-num">{{ formatPriceDisplay(row.total_sales, 2) }}</span></template>
          <template v-for="col in dynamicColumns" #[`cell-${col.key}`]="{ row }">
            <span :key="col.key" class="pxn-num">{{ formatPriceDisplay(row[col.key], 2) }}</span>
          </template>
        </px-table>

        <px-empty-state v-else icon="user-check" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
      </div>

      <div v-if="payments.length" class="pxrl__totalrow">
        <span>{{ $t('Total') }}</span>
        <span>{{ $t('TotalSales') }}: <b class="pxn-num">{{ sumTotalSales(rows[0]) }}</b></span>
        <span v-for="method in paymentMethods" :key="method">{{ method }}: <b class="pxn-num">{{ sumPaymentMethod(rows[0], method) }}</b></span>
      </div>

      <px-pagination
        v-if="payments.length"
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
import DateRangePicker from "vue2-daterange-picker";
import "vue2-daterange-picker/dist/vue2-daterange-picker.css";
import moment from "moment";
import { mapGetters } from "vuex";
import VueApexCharts from "vue-apexcharts";
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
import PxStat from "@/components/px-next/PxStat.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "Report Seller",
  },
  components: {
    apexchart: VueApexCharts,
    "date-range-picker": DateRangePicker,
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxCard,
    PxField, PxStat, PxEmptyState, "vs-px": VsPx
  },

  data() {
    return {
      _searchTimer: null,
      isLoading: true,
      serverParams: {
        sort: {
          field: "id",
          type: "desc",
        },
        page: 1,
        perPage: 10,
      },
      limit: "10",
      search: "",
      totalRows: "",
      start_time: "",
      end_time: "",
      payments: [],
      rows: [{
        statut: '',
        children: [],
      }],
      paymentMethods: [],
      warehouses: [],
      warehouse_id: "",
      today_mode: true,
      startDate: "",
      endDate: "",
      dateRange: {
        startDate: "",
        endDate: "",
      },
      locale: {
        Label: "Apply",
        cancelLabel: "Cancel",
        weekLabel: "W",
        customRangeLabel: "Custom Range",
        daysOfWeek: moment.weekdaysMin(),
        monthNames: moment.monthsShort(),
        firstDay: 1,
      },
      price_format_key: null
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),

    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },

    currency() {
      return (this.currentUser && this.currentUser.currency) || "";
    },

    exportMenu() {
      return [
        { key: "print", label: this.$t("print"), icon: "printer" },
        { key: "pdf", label: "PDF", icon: "file-text" },
        { key: "xlsx", label: "CSV / Excel", icon: "file-spreadsheet" }
      ];
    },

    dynamicColumns() {
      return (this.paymentMethods || []).map((method) => ({ key: method, label: method }));
    },

    columns() {
      const base = [
        {
          label: this.$t("Seller"),
          key: "username",
          strong: true,
          sortable: true,
        },
        {
          label: this.$t("TotalSales"),
          key: "total_sales",
          align: "right",
          sortable: false,
        },
      ];

      const dynamic = this.dynamicColumns.map((c) => ({
        label: c.label,
        key: c.key,
        align: "right",
        sortable: false,
      }));

      return [...base, ...dynamic];
    },

    sellerStats() {
      const rows = Array.isArray(this.payments) ? this.payments : [];
      if (!rows.length) {
        return {
          totalSellers: 0,
          totalSales: 0,
          avgSales: 0,
          topSellerName: "",
          topSellerSales: 0,
        };
      }

      let totalSales = 0;
      let topSeller = null;

      rows.forEach((row) => {
        const sales = this.toNumber(row.total_sales);
        totalSales += sales;
        if (!topSeller || sales > topSeller.sales) {
          topSeller = {
            name: row.username,
            sales,
          };
        }
      });

      const totalSellers = rows.length;
      const avgSales = totalSellers ? totalSales / totalSellers : 0;

      return {
        totalSellers,
        totalSales,
        avgSales,
        topSellerName: topSeller ? topSeller.name : "",
        topSellerSales: topSeller ? topSeller.sales : 0,
      };
    },

    topSellersChartData() {
      const rows = Array.isArray(this.payments) ? this.payments : [];
      const mapped = rows
        .map((r) => ({
          name: r.username,
          sales: this.toNumber(r.total_sales),
        }))
        .filter((r) => r.sales > 0);

      mapped.sort((a, b) => b.sales - a.sales);
      return mapped.slice(0, 10);
    },

    topSellersChartOptions() {
      return {
        chart: { toolbar: { show: false } },
        xaxis: {
          categories: this.topSellersChartData.map((x) => x.name),
          labels: { rotate: -45 },
        },
        yaxis: {
          labels: {
            formatter: (v) => this.shortMoney(v),
          },
        },
        dataLabels: { enabled: false },
        tooltip: {
          y: {
            formatter: (v) => this.money(v),
          },
        },
      };
    },

    topSellersChartSeries() {
      return [
        {
          name: this.$t("TotalSales"),
          data: this.topSellersChartData.map((x) => x.sales),
        },
      ];
    },

    paymentMethodTotals() {
      const methods = Array.isArray(this.paymentMethods)
        ? this.paymentMethods
        : [];
      const rows = Array.isArray(this.payments) ? this.payments : [];

      return methods.map((method) => {
        let total = 0;
        rows.forEach((row) => {
          total += this.toNumber(row[method]);
        });
        return { method, total };
      });
    },

    paymentMethodsChartOptions() {
      const categories = this.paymentMethodTotals.map((x) => x.method);
      return {
        chart: { toolbar: { show: false } },
        xaxis: {
          categories,
        },
        yaxis: {
          labels: {
            formatter: (v) => this.shortMoney(v),
          },
        },
        dataLabels: { enabled: false },
        tooltip: {
          y: {
            formatter: (v) => this.money(v),
          },
        },
      };
    },

    paymentMethodsChartSeries() {
      return [
        {
          name: this.$t("TotalSales"),
          data: this.paymentMethodTotals.map((x) => x.total),
        },
      ];
    },
  },

  methods: {
    isPriceField(field) {
      return field === 'total_sales' || (this.paymentMethods && this.paymentMethods.includes(field));
    },

    // Group footer helpers preserved verbatim
    sumTotalSales(rowObj) {
      if (!rowObj || !Array.isArray(rowObj.children)) {
        return this.formatPriceDisplay(0, 2);
      }
      let sum = 0;
      for (let i = 0; i < rowObj.children.length; i++) {
        const value = this.toNumber(rowObj.children[i].total_sales) || 0;
        if (Number.isFinite(value)) {
          sum += value;
        }
      }
      return this.formatPriceDisplay(sum, 2);
    },

    sumPaymentMethod(rowObj, method) {
      if (!rowObj || !Array.isArray(rowObj.children)) {
        return this.formatPriceDisplay(0, 2);
      }
      let sum = 0;
      for (let i = 0; i < rowObj.children.length; i++) {
        const value = this.toNumber(rowObj.children[i][method]) || 0;
        if (Number.isFinite(value)) {
          sum += value;
        }
      }
      return this.formatPriceDisplay(sum, 2);
    },

    Selected_Warehouse(value) {
      if (value === null) {
        this.warehouse_id = "";
      }
      this.Seller_report(1);
    },

    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Seller_report(1); }, 350);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Seller_report(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Seller_report(1); } },

    onSort({ key, dir }) {
      this.updateParams({ sort: { type: dir, field: key } });
      this.Seller_report(this.serverParams.page);
    },

    onExport(item) {
      const k = item && item.key;
      if (k === "print") this.printTableOnly();
      else if (k === "pdf") this.Seller_report_pdf();
      else if (k === "xlsx") this.exportCsv();
    },

    exportCsv() {
      const cols = this.columns;
      const head = cols.map(c => c.label);
      const lines = [head.join(",")].concat(
        (this.payments || []).map(r =>
          cols.map(c => {
            let v = r[c.key];
            if (this.isPriceField(c.key)) v = this.formatPriceDisplay(r[c.key], 2);
            return `"${String(v == null ? "" : v).replace(/"/g, '""')}"`;
          }).join(",")
        )
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "Seller_report.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    //------ Print Table Only
    printTableOnly() {
      const paymentsData = Array.isArray(this.rows[0] && this.rows[0].children) && this.rows[0].children.length > 0
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
          if (this.isPriceField(col.key)) {
            cellContent = this.formatPriceDisplay(row[col.key], 2);
          }
          tableHtml += `<td class="text-left">${cellContent || ''}</td>`;
        });
        tableHtml += `</tr>`;
      });
      tableHtml += `</tbody>`;

      const totalSales = this.sumTotalSales(this.rows[0]);
      tableHtml += `<tfoot><tr>`;
      tableHtml += `<td class="text-left font-weight-bold">${this.$t('Total')}</td>`;
      tableHtml += `<td class="text-left font-weight-bold">${totalSales}</td>`;
      (this.paymentMethods || []).forEach(method => {
        const total = this.sumPaymentMethod(this.rows[0], method);
        tableHtml += `<td class="text-left font-weight-bold">${total}</td>`;
      });
      tableHtml += `</tr></tfoot>`;
      tableHtml += `</table>`;

      const w = window.open("", "_blank");
      if (!w) {
        window.print();
        return;
      }

      const title = `${this.$t("Reports")} / ${this.$t("Seller_report")}`;
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

    Seller_report_pdf() {
      const doc = new jsPDF("p", "pt");

      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      try {
        doc.addFont(fontPath, "VazirmatnBold", "bold");
        doc.setFont("VazirmatnBold");
      } catch (e) {
        // Fallback silently if font is already registered or missing
      }

      const headers = [
        { title: this.$t("Seller"), dataKey: "username" },
        { title: this.$t("TotalSales"), dataKey: "total_sales" },
        ...(this.paymentMethods || []).map((method) => ({
          title: method,
          dataKey: method,
        })),
      ];

      const rows = Array.isArray(this.payments) ? this.payments : [];

      autoTable(doc, {
        head: [headers.map((h) => h.title)],
        body: rows.map((row) => headers.map((h) => (row[h.dataKey] != null ? row[h.dataKey] : ""))),
        startY: 70,
        theme: "grid",
        didDrawPage: () => {
          doc.setFontSize(18);
          doc.text("Seller Payment Report", 40, 25);
        },
        styles: {
          halign: "center",
        },
        headStyles: {
          fillColor: [200, 200, 200],
          textColor: [0, 0, 0],
          fontStyle: "bold",
        },
      });

      doc.save("Seller_Payment_Report.pdf");
    },

    //----------------------------- Submit Date Picker -------------------\\
    Submit_filter_dateRange() {
      const start = this.dateRange.startDate
        ? moment(this.dateRange.startDate)
        : null;
      const end = this.dateRange.endDate
        ? moment(this.dateRange.endDate)
        : null;

      if (start && end) {
        this.startDate = start.format("YYYY-MM-DD");
        this.endDate = end.format("YYYY-MM-DD");

        this.start_time = start.format("HH:mm:ss");
        this.end_time = end.format("HH:mm:ss");

        this.Seller_report(1);
      }
    },

    get_data_loaded() {
      const self = this;
      if (self.today_mode) {
        const startDate = new Date("2000-01-01");
        const endDate = new Date();

        self.startDate = moment(startDate).format("YYYY-MM-DD");
        self.endDate = moment(endDate).format("YYYY-MM-DD");

        self.dateRange.startDate = startDate;
        self.dateRange.endDate = endDate;
      }
    },

    formatDateTime(date) {
      return date ? moment(date).format("YYYY-MM-DD HH:mm:ss") : "";
    },

    toNumber(val) {
      if (typeof val === "number") {
        return isNaN(val) ? 0 : val;
      }
      if (!val) return 0;
      const num = parseFloat(val.toString().replace(/,/g, ""));
      return isNaN(num) ? 0 : num;
    },

    num(v) {
      const n = Number(this.toNumber(v) || 0);
      return isNaN(n) ? "0" : n.toLocaleString();
    },

    formatPriceDisplay(number, dec) {
      try {
        const decimals = this.priceDecimals;
        const n = this.toNumber(number);
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        const effectiveKey = key || null;
        return formatPriceDisplayHelper(n, decimals, effectiveKey);
      } catch (e) {
        const n = this.toNumber(number);
        return n.toLocaleString(undefined, { maximumFractionDigits: dec || 2 });
      }
    },

    money(v) {
      try {
        const n = this.toNumber(v);
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        const effectiveKey = key || null;
        const formatted = formatPriceDisplayHelper(n, this.priceDecimals, effectiveKey);
        return `${this.currency} ${formatted}`;
      } catch (e) {
        const n = this.toNumber(v);
        try {
          const currency = this.currency || "USD";
          return new Intl.NumberFormat(undefined, {
            style: "currency",
            currency,
          }).format(n);
        } catch (e2) {
          return `${this.currency} ${n.toLocaleString()}`;
        }
      }
    },

    shortMoney(v) {
      const n = this.toNumber(v);
      return new Intl.NumberFormat(undefined, {
        notation: "compact",
        maximumFractionDigits: 1,
      }).format(n);
    },

    //-------------------------------- Get All Payments Sales ---------------------\\
    Seller_report(page) {
      NProgress.start();
      NProgress.set(0.1);

      this.get_data_loaded();
      this.isLoading = true;

      axios
        .get("report/seller_report", {
          params: {
            page: page,
            SortField: this.serverParams.sort.field,
            SortType: this.serverParams.sort.type,
            search: this.search,
            limit: this.limit,
            warehouse_id: this.warehouse_id,
            end_date: this.endDate,
            start_date: this.startDate,
            start_time: this.start_time,
            end_time: this.end_time,
          },
        })
        .then((response) => {
          this.payments = (response.data.report || []).map((p, i) =>
            Object.assign({ __rowkey: p.id != null ? `s-${p.id}-${i}` : `r-${i}` }, p)
          );
          this.paymentMethods = response.data.paymentMethods || [];
          this.warehouses = response.data.warehouses || [];
          this.totalRows = response.data.totalRows;
          this.rows[0].children = this.payments;

          NProgress.done();
          this.isLoading = false;
          this.today_mode = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
            this.today_mode = false;
          }, 500);
        });
    },
  },

  //----------------------------- Lifecycle hooks -------------------\\
  created() {
    this.Seller_report(1);
  },
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrl__pad { padding: var(--pxn-space-6) 0; }
.pxrl__filtercard { margin-top: var(--pxn-space-5); }
.pxrl__filterrow { display: grid; grid-template-columns: minmax(0, 320px); gap: var(--pxn-space-4) var(--pxn-space-5); }
.pxrl__daterange {
  display: inline-flex; align-items: center; gap: var(--pxn-space-3);
  height: var(--pxn-control-h-sm); padding: 0 var(--pxn-space-4);
  border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); font: inherit; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink); cursor: pointer;
}
.pxrl__daterange:hover { background: var(--pxn-surface-2); }
.pxrl__stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-4); margin-top: var(--pxn-space-5); }
@media (max-width: 900px) { .pxrl__stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 520px) { .pxrl__stats { grid-template-columns: minmax(0, 1fr); } }
.pxrl__cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); margin-top: var(--pxn-space-5); }
.pxrl__cols--87 { grid-template-columns: 2fr 1fr; }
@media (max-width: 900px) { .pxrl__cols, .pxrl__cols--87 { grid-template-columns: minmax(0, 1fr); } }
.pxrl__cardmeta { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxrl__ranklist { margin-top: var(--pxn-space-4); font-size: var(--pxn-fs-sm); }
.pxrl__rankhead { display: flex; justify-content: space-between; align-items: center; padding-bottom: var(--pxn-space-2); border-bottom: 1px solid var(--pxn-border); text-transform: uppercase; letter-spacing: 0.04em; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxrl__rankhead span:last-child { font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); text-transform: none; letter-spacing: 0; }
.pxrl__rankrow { display: flex; justify-content: space-between; padding: var(--pxn-space-2) 0; }
.pxrl__tablewrap { margin-top: var(--pxn-space-5); }
.pxrl__totalrow { display: flex; align-items: center; justify-content: flex-end; gap: var(--pxn-space-6); margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); flex-wrap: wrap; }
.pxrl__totalrow > span:first-child { margin-right: auto; font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxrl ::v-deep .daterangepicker { z-index: 2055 !important; }
</style>
