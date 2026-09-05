<template>
  <div class="px-next pxrl">
    <px-page-header :title="$t('Payment_Purchases')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('Payment_Purchases') }]">
      <template #actions>
        <px-menu :items="exportMenu" align="end" @select="onExport">
          <template #trigger>
            <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button>
          </template>
        </px-menu>
        <px-button variant="primary" size="sm" icon="refresh-cw" @click="Payments_Purchases(serverParams.page)">{{ $t('Refresh') }}</px-button>
      </template>
      <template #meta>
        <date-range-picker
          v-model="dateRange"
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
      <div class="pxrl__cols pxrl__cols--87">
        <px-card :title="$t('PaymentsOverTime')">
          <apexchart type="line" height="320" :options="apexTimeOptions" :series="apexTimeSeries" />
        </px-card>
        <px-card :title="$t('PaymentsByMethod')">
          <apexchart type="bar" height="320" :options="apexMethodOptions" :series="apexMethodSeries" />
        </px-card>
      </div>

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
        <div class="pxrl__filters-act">
          <px-button size="sm" variant="primary" icon="filter" @click="Payments_Purchases(1)">{{ $t('Filter') }}</px-button>
          <px-button size="sm" variant="ghost" icon="x" @click="Reset_Filter">{{ $t('Reset') }}</px-button>
        </div>
      </div>

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
          <template #cell-date="{ row }">{{ row.date ? fmt(row.date) : '' }}</template>
          <template #cell-Ref_Purchase="{ row }">
            <router-link v-if="row.purchase_id" :to="{ name: 'detail_purchase', params: { id: row.purchase_id } }" class="pxrl__link">{{ row.Ref_Purchase }}</router-link>
            <span v-else>{{ row.Ref_Purchase }}</span>
          </template>
          <template #cell-montant="{ row }"><span class="pxn-num">{{ formatPriceDisplay(row.montant, 2) }}</span></template>
        </px-table>

        <px-empty-state v-else icon="coins" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
      </div>

      <div v-if="payments.length" class="pxrl__totalrow">
        <span>{{ $t('Total') }}</span>
        <span class="pxn-num">{{ formatPriceDisplay(totalMontant, 2) }}</span>
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
import VueApexCharts from "vue-apexcharts";
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting,
  getPriceDecimals
} from "../../../../../utils/priceFormat";
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
  metaInfo: { title: "Payment Purchases" },
  components: {
    "date-range-picker": DateRangePicker, apexchart: VueApexCharts,
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxCard,
    PxField, PxInput, PxEmptyState, "vs-px": VsPx
  },

  data() {
    const end = new Date();
    const start = new Date(); start.setDate(end.getDate() - 29);
    return {
      _searchTimer: null,
      filtersOpen: false,
      isLoading: true,

      serverParams: { sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      limit: "10",
      search: "",
      totalRows: 0,

      Filter_Supplier: "",
      Filter_Ref: "",
      Filter_purchase: "",
      Filter_Reg: "",

      payments: [],
      suppliers: [],
      purchases: [],
      payment_methods: [],

      rows: [{ children: [] }],

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
      price_format_key: null
    };
  },

  computed: {
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    activeFilterCount() {
      return [this.Filter_Ref, this.Filter_Supplier, this.Filter_purchase, this.Filter_Reg]
        .filter(v => v !== "" && v !== null && v !== undefined).length;
    },
    exportMenu() {
      return [
        { key: "print", label: this.$t("print"), icon: "printer" },
        { key: "pdf", label: "PDF", icon: "file-text" },
        { key: "xlsx", label: "CSV / Excel", icon: "file-spreadsheet" }
      ];
    },
    totalMontant() {
      return (this.payments || []).reduce((a, b) => a + (Number(b.montant) || 0), 0);
    },
    columns() {
      return [
        { key: "date",           label: this.$t("date") },
        { key: "Ref",            label: this.$t("Reference"), strong: true },
        { key: "Ref_Purchase",   label: this.$t("Purchase") },
        { key: "provider_name",  label: this.$t("Supplier") },
        { key: "payment_method", label: this.$t("ModePaiement") },
        { key: "account_name",   label: this.$t("Account") },
        { key: "montant",        label: this.$t("Amount"), align: "right" },
        { key: "user_name",      label: this.$t("AddedBy") },
      ];
    },

    excelColumns(){
      return this.columns.map(c => ({ label: c.label, field: c.key }));
    },

    apexTimeOptions(){
      const map = new Map();
      (this.payments || []).forEach(p => {
        const d = p.date ? String(p.date).slice(0,10) : "";
        const amt = Number(p.montant || 0);
        if (!d) return;
        map.set(d, (map.get(d) || 0) + amt);
      });
      const dates = Array.from(map.keys()).sort();
      return {
        chart: { type: 'line', toolbar: { show: false } },
        stroke: { curve: 'smooth', width: 3 },
        dataLabels: { enabled: false },
        xaxis: { categories: dates, labels: { rotate: -45 } },
        yaxis: { labels: { formatter: (v) => {
          try { return new Intl.NumberFormat(undefined,{ notation:'compact', maximumFractionDigits:1 }).format(Number(v||0)); }
          catch { return v; }
        } } },
        tooltip: { y: { formatter: (v) => {
          try { return this.formatPriceDisplay(v, 2); }
          catch { return v; }
        } } },
        grid: { padding: { left: 10, right: 10, top: 10, bottom: 10 } }
      };
    },
    apexTimeSeries(){
      const map = new Map();
      (this.payments || []).forEach(p => {
        const d = p.date ? String(p.date).slice(0,10) : "";
        const amt = Number(p.montant || 0);
        if (!d) return;
        map.set(d, (map.get(d) || 0) + amt);
      });
      const dates = Array.from(map.keys()).sort();
      const vals = dates.map(d => map.get(d));
      return [{ name: this.$t('Amount'), data: vals }];
    },

    apexMethodOptions(){
      const map = new Map();
      (this.payments || []).forEach(p => {
        const k = p.payment_method || this.$t('Unknown');
        map.set(k, (map.get(k) || 0) + Number(p.montant || 0));
      });
      const cats = Array.from(map.keys());
      return {
        chart: { type: 'bar', toolbar: { show: false } },
        plotOptions: { bar: { horizontal: true } },
        dataLabels: { enabled: false },
        xaxis: { categories: cats },
        tooltip: { y: { formatter: (v) => {
          try { return this.formatPriceDisplay(v, 2); }
          catch { return v; }
        } } },
        grid: { padding: { left: 10, right: 10, top: 10, bottom: 10 } }
      };
    },
    apexMethodSeries(){
      const map = new Map();
      (this.payments || []).forEach(p => {
        const k = p.payment_method || this.$t('Unknown');
        map.set(k, (map.get(k) || 0) + Number(p.montant || 0));
      });
      const cats = Array.from(map.keys());
      const vals = cats.map(k => map.get(k));
      return [{ name: this.$t('Amount'), data: vals }];
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
    fmt(d){ return moment(d).format("YYYY-MM-DD"); },
    findLabel(list, id, key='name'){
      if (!id) return this.$t('All');
      const x = (list||[]).find(i => String(i.id) === String(id));
      return x ? (x[key] ?? this.$t('All')) : this.$t('All');
    },
    findPurchaseRef(id){
      if (!id) return this.$t('All');
      const x = (this.purchases||[]).find(i => String(i.id) === String(id));
      return x ? (x.Ref || this.$t('All')) : this.$t('All');
    },

    quick(kind){
      const now = moment(); let s, e = now.clone();
      if (kind==='7d')  s = now.clone().subtract(6,'days');
      if (kind==='30d') s = now.clone().subtract(29,'days');
      if (kind==='90d') s = now.clone().subtract(89,'days');
      if (kind==='mtd'){ s = now.clone().startOf('month'); e = now; }
      if (kind==='ytd'){ s = now.clone().startOf('year');  e = now; }
      this.dateRange = { startDate: s.toDate(), endDate: e.toDate() };
      this.Payments_Purchases(1);
    },

    updateParams(newProps){ this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Payments_Purchases(1); }, 350);
    },
    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Payments_Purchases(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Payments_Purchases(1); } },
    onSort({ key, dir }) {
      const field = key === 'Ref_Purchase' ? 'purchase_id' : key;
      this.updateParams({ sort: { type: dir, field } });
      this.Payments_Purchases(this.serverParams.page);
    },

    Submit_filter_dateRange(){
      this.Payments_Purchases(1);
    },

    Reset_Filter(){
      this.search = "";
      this.Filter_Supplier = "";
      this.Filter_Ref = "";
      this.Filter_purchase = "";
      this.Filter_Reg = "";
      this.Payments_Purchases(1);
    },

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
            if (c.key === "date") v = r.date ? this.fmt(r.date) : "";
            return `"${String(v == null ? "" : v).replace(/"/g, '""')}"`;
          }).join(",")
        )
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "payments_purchases.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    //------ Print Table Only - Print ALL payments data with all columns
    printTableOnly() {
      const title = `${this.$t("Reports")} / ${this.$t("SalesInvoice")}`;
      const payments = Array.isArray(this.payments) ? this.payments : [];

      let tableHTML = '<table style="width: 100%; border-collapse: collapse; font-size: 10px;">';
      tableHTML += '<thead><tr>';

      this.columns.forEach(col => {
        tableHTML += `<th style="border: 1px solid #ddd; padding: 6px 8px; background-color: #f5f5f5; font-weight: bold; text-align: left;">${col.label}</th>`;
      });
      tableHTML += '</tr></thead><tbody>';

      payments.forEach(payment => {
        tableHTML += '<tr>';
        this.columns.forEach(col => {
          let cellValue = '';

          if (col.key === 'date') {
            cellValue = payment.date ? this.fmt(payment.date) : '';
          } else if (col.key === 'montant') {
            cellValue = this.formatPriceDisplay(payment.montant, 2);
          } else {
            cellValue = payment[col.key] || '';
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

    // ---------- fetch ----------
    Payments_Purchases(page){
      NProgress.start(); NProgress.set(0.1);

      const provider_id = this.Filter_Supplier || '';
      const purchase_id = this.Filter_purchase || '';
      const method_id  = this.Filter_Reg     || '';
      const ref        = this.Filter_Ref     || '';
      const from       = this.fmt(this.dateRange.startDate);
      const to         = this.fmt(this.dateRange.endDate);

      const url = "payment_purchase?" + new URLSearchParams({
        page: String(page),
        Ref: ref,
        provider_id,
        purchase_id,
        payment_method_id: method_id,
        SortField: this.serverParams.sort.field,
        SortType: this.serverParams.sort.type,
        search: this.search || '',
        limit: this.limit,
        to, from
      }).toString();

      axios.get(url)
        .then(({data})=>{
          this.payments = (data.payments || []).map((p, i) => Object.assign({ __rowkey: p.id != null ? `p-${p.id}-${i}` : `r-${i}` }, p));
          this.suppliers = data.suppliers || [];
          this.purchases = data.purchases || [];
          this.payment_methods = data.payment_methods || [];
          this.totalRows = Number(data.totalRows || 0);
          this.rows[0].children = this.payments;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(()=>{ NProgress.done(); setTimeout(()=>{ this.isLoading=false; }, 300); });
    },


    // ---------- shared font + RTL helpers ----------
    useVazirmatn(pdf){
      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      try {
        pdf.addFont(fontPath, "Vazirmatn", "normal");
        pdf.addFont(fontPath, "Vazirmatn", "bold");
      } catch(e){ /* ignore if already added */ }
      pdf.setFont("Vazirmatn", "normal");
    },
    isRTL(){
      return (this.$i18n && ['ar','fa','ur','he'].includes(this.$i18n.locale))
          || (typeof document !== 'undefined' && document.documentElement.dir === 'rtl');
    },

    // ---------- EXPORT PDF (Payments Sales) ----------
    async Payment_PDF(){
      NProgress.start(); NProgress.set(0.2);
      try{
        const fmtLocal = (d) => {
          if (!d) return '';
          if (this.fmt) return this.fmt(d);
          return (d instanceof Date) ? d.toISOString().slice(0,10) : String(d);
        };
        const from = this.startDate || fmtLocal(this.dateRange?.startDate);
        const to   = this.endDate   || fmtLocal(this.dateRange?.endDate);

        const qs = new URLSearchParams({
          page: '1',
          limit: '-1',
          SortField: this.serverParams?.sort?.field || 'id',
          SortType:  this.serverParams?.sort?.type  || 'desc',
          search: this.search || '',
          from, to,
          Ref: this.Filter_Ref || '',
          provider_id: this.Filter_Supplier || '',
          purchase_id: this.Filter_purchase || '',
          payment_method_id: this.Filter_Reg || ''
        }).toString();

        const { data } = await axios.get(`payment_purchase?${qs}`).catch(()=>({data:{}}));
        const items = Array.isArray(data?.payments) ? data.payments : [];

        const pdf = new jsPDF({ orientation:'landscape', unit:'pt', format:'a4' });
        this.useVazirmatn(pdf);
        const rtl = this.isRTL();
        const margin = 40;
        const pageW = pdf.internal.pageSize.getWidth();

        pdf.setFont('Vazirmatn','bold'); pdf.setFontSize(16);
        const title = 'Payment Purchases';
        rtl ? pdf.text(title, pageW - margin, 40, { align:'right' })
            : pdf.text(title, margin, 40);

        pdf.setFont('Vazirmatn','normal'); pdf.setFontSize(10);
        const customerLabel = this.findLabel(this.suppliers, this.Filter_Supplier, 'name');
        const saleLabel     = this.findPurchaseRef(this.Filter_purchase);
        const methodLabel   = this.findLabel(this.payment_methods, this.Filter_Reg, 'name');
        const refFilter     = this.Filter_Ref || this.$t('All');
        const range         = `${from || '—'} — ${to || '—'}`;

        const headerText = [
          `${this.$t('DateRange')}: ${range}`,
          `${this.$t('Reference')}: ${refFilter}`,
          `${this.$t('Supplier')}: ${customerLabel}`,
          `${this.$t('Purchase')}: ${saleLabel}`,
          `${this.$t('ModePaiement')}: ${methodLabel}`
        ].join('   •   ');

        const wrapped = pdf.splitTextToSize(headerText, pageW - margin*2);
        rtl ? pdf.text(wrapped, pageW - margin, 58, { align:'right' })
            : pdf.text(wrapped, margin, 58);

        const head = [[
          this.$t('date'),
          this.$t('Reference'),
          this.$t('Sale'),
          this.$t('Supplier'),
          this.$t('ModePaiement'),
          this.$t('Account'),
          this.$t('Amount'),
          this.$t('AddedBy'),
        ]];

        const body = items.map(r => ([
          r.date || '',
          r.Ref || '',
          r.Ref_Purchase || '',
          r.provider_name || '',
          r.payment_method || '',
          r.account_name || '',
          Number(r.montant || 0).toFixed(this.priceDecimals),
          r.user_name || '---'
        ]));

        const total = items.reduce((a,b)=> a + Number(b.montant || 0), 0);

        autoTable(pdf, {
          startY: 80,
          head, body,
          margin: { left: margin, right: margin },
          theme: 'striped',
          styles: {
            font: 'Vazirmatn',
            fontStyle: 'normal',
            fontSize: 9,
            cellPadding: 6,
            overflow: 'linebreak',
            halign: rtl ? 'right' : 'left',
          },
          headStyles: {
            font: 'Vazirmatn',
            fontStyle: 'bold',
            fillColor: [26,86,219],
            textColor: 255,
            halign: rtl ? 'right' : 'left',
          },
          columnStyles: {
            6: { halign:'right' },
          },
          foot: [[
            { content: this.$t('Totals'), colSpan: 7, styles:{ halign:'right', fontStyle:'bold' } },
            { content: total.toFixed(this.priceDecimals),  styles:{ halign:'right', fontStyle:'bold' } }
          ]],
          didDrawPage: (d) => {
            pdf.setFont('Vazirmatn','normal'); pdf.setFontSize(8);
            pdf.text(`${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`,
                    pageW - margin, pdf.internal.pageSize.getHeight() - 14, { align:'right' });
          }
        });

        pdf.save(`payments_purchases_${from || 'all'}_${to || 'all'}.pdf`);
      } finally {
        NProgress.done();
      }
    },
  },

  created(){ this.Payments_Purchases(1); }
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
.pxrl__cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); margin-top: var(--pxn-space-5); }
.pxrl__cols--87 { grid-template-columns: 2fr 1fr; }
@media (max-width: 900px) { .pxrl__cols, .pxrl__cols--87 { grid-template-columns: minmax(0, 1fr); } }
.pxrl__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxrl__filters-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 560px) { .pxrl__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxrl__filters-act { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); }
.pxrl__tablewrap { margin-top: var(--pxn-space-5); }
.pxrl__link { color: var(--pxn-primary); }
.pxrl__totalrow { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-6); margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxrl ::v-deep .daterangepicker { z-index: 2055 !important; }
</style>
