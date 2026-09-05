<template>
  <div class="px-next pxrl">
    <px-page-header :title="$t('Top_customers')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('Top_customers') }]">
      <template #actions>
        <px-menu :items="exportMenu" align="end" @select="onExport">
          <template #trigger>
            <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button>
          </template>
        </px-menu>
      </template>
    </px-page-header>

    <div v-if="isLoading" class="pxrl__pad">
      <px-skeleton variant="table" :rows="10" :columns="5" />
    </div>

    <template v-else>
      <div class="pxrl__tablewrap">
        <px-table
          v-if="customers.length"
          :columns="columns"
          :rows="customers"
          row-key="__rowkey"
        >
          <template #cell-total="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser.currency, row.total, 2) }}</span></template>
          <template #cell-total_sales="{ row }"><span class="pxn-num">{{ row.total_sales }}</span></template>
        </px-table>

        <px-empty-state v-else icon="users" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
      </div>

      <div v-if="customers.length" class="pxrl__totalrow">
        <span>{{ $t('Total') }}</span>
        <span>{{ $t('TotalSales') }}: <b class="pxn-num">{{ totalSales.toLocaleString() }}</b></span>
        <span>{{ $t('TotalAmount') }}: <b class="pxn-num">{{ formatPriceWithSymbol(currentUser.currency, totalAmount, 2) }}</b></span>
      </div>

      <px-pagination
        v-if="customers.length"
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
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting,
  getPriceDecimals
} from "../../../../utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxMenu from "@/components/px-next/PxMenu.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  metaInfo: {
    title: "Top Customers"
  },
  components: {
    PxPageHeader, PxTable, PxPagination, PxButton, PxMenu, PxEmptyState
  },
  data() {
    return {
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
      customers: [],
      rows: [{
        statut: '',
        children: [],
      }],
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
        { key: "name", label: this.$t("Name"), strong: true },
        { key: "phone", label: this.$t("Phone") },
        { key: "email", label: this.$t("Email") },
        { key: "total_sales", label: this.$t("TotalSales"), align: "right" },
        { key: "total", label: this.$t("TotalAmount"), align: "right" },
      ];
    },
    totalSales() {
      return (this.customers || []).reduce((sum, c) => sum + (Number(c.total_sales) || 0), 0);
    },
    totalAmount() {
      return (this.customers || []).reduce((sum, c) => sum + (Number(c.total) || 0), 0);
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

    onExport(item) {
      const k = item && item.key;
      if (k === "print") this.printTableOnly();
      else if (k === "pdf") this.export_PDF();
      else if (k === "xlsx") this.exportCsv();
    },

    exportCsv() {
      const head = this.columns.map(c => c.label);
      const lines = [head.join(",")].concat(
        (this.customers || []).map(r => this.columns.map(c => `"${String(r[c.key] == null ? "" : r[c.key]).replace(/"/g, '""')}"`).join(","))
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "top_customers.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
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
        self.$t("Name"),
        self.$t("Phone"),
        self.$t("Email"),
        self.$t("TotalSales"),
        self.$t("TotalAmount")
      ];

      const body = (self.customers || []).map(customer => ([
        customer.name,
        customer.phone,
        customer.email,
        customer.total_sales,
        customer.total
      ]));

      const marginX = 40;
      const rtl =
        (self.$i18n && ['ar','fa','ur','he'].includes(self.$i18n.locale)) ||
        (typeof document !== 'undefined' && document.documentElement.dir === 'rtl');

      autoTable(pdf, {
        head: [headers],
        body: body,
        startY: 110,
        theme: 'striped',
        margin: { left: marginX, right: marginX },
        styles: { font: 'Vazirmatn', fontSize: 9, cellPadding: 4, halign: rtl ? 'right' : 'left', textColor: 33 },
        headStyles: { font: 'Vazirmatn', fontStyle: 'bold', fillColor: [26,86,219], textColor: 255 },
        alternateRowStyles: { fillColor: [245,247,250] },
        didDrawPage: (d) => {
          const pageW = pdf.internal.pageSize.getWidth();
          const pageH = pdf.internal.pageSize.getHeight();

          pdf.setFillColor(26,86,219);
          pdf.rect(0, 0, pageW, 60, 'F');

          pdf.setTextColor(255);
          pdf.setFont('Vazirmatn', 'bold');
          pdf.setFontSize(16);
          const title = 'Top Customers';
          rtl ? pdf.text(title, pageW - marginX, 38, { align: 'right' })
              : pdf.text(title, marginX, 38);

          pdf.setTextColor(33);

          pdf.setFontSize(8);
          const pn = `${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`;
          rtl ? pdf.text(pn, marginX, pageH - 14, { align: 'left' })
              : pdf.text(pn, pageW - marginX, pageH - 14, { align: 'right' });
        },
        headStyles: {
          fillColor: [26,86,219],
               textColor: [0, 0, 0],
               fontStyle: "bold",
             },

      });

      pdf.save("Top_Customers.pdf");
    },

    //------ Print Table Only
    printTableOnly() {
      const rowsData = (this.rows && this.rows[0] && this.rows[0].children) ? this.rows[0].children : (this.customers || []);

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

      const totalSales = rowsData.reduce((sum, row) => sum + parseFloat(row.total_sales || 0), 0);
      const totalAmount = rowsData.reduce((sum, row) => sum + parseFloat(row.total || 0), 0);
      tableHtml += `<tfoot><tr>`;
      tableHtml += `<td class="text-left font-weight-bold" colspan="3">${this.$t('Total')}</td>`;
      tableHtml += `<td class="text-left font-weight-bold">${totalSales.toLocaleString()}</td>`;
      tableHtml += `<td class="text-left font-weight-bold">${this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, totalAmount, 2)}</td>`;
      tableHtml += `</tr></tfoot>`;
      tableHtml += `</table>`;

      const w = window.open("", "_blank");
      if (!w) {
        window.print();
        return;
      }

      const title = `${this.$t("Reports")} / ${this.$t("Top_customers")}`;

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

    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_top_Customers(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_top_Customers(1); } },

    //----------------------------- Get_top_Customers-------------------\\
    Get_top_Customers(page) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "report/top_customers?page=" +
            page +
            "&limit=" +
            this.limit
        )
        .then(response => {
          this.customers = (response.data.customers || []).map((c, i) => Object.assign({ __rowkey: `${c.name || 'c'}-${i}` }, c));
          this.totalRows = response.data.totalRows;
          this.rows[0].children = this.customers;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(response => {
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    }
  }, //end Methods

  //----------------------------- Created function------------------- \\

  created: function() {
    this.Get_top_Customers(1);
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrl__pad { padding: var(--pxn-space-6) 0; }
.pxrl__tablewrap { margin-top: var(--pxn-space-5); }
.pxrl__totalrow { display: flex; align-items: center; justify-content: flex-end; gap: var(--pxn-space-6); margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.pxrl__totalrow > span:first-child { margin-right: auto; font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
</style>
