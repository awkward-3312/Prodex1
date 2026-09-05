<template>
  <div class="px-next pxrl">
    <px-page-header :title="$t('ProductQuantityAlerts')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('ProductQuantityAlerts') }]">
      <template #actions>
        <px-menu :items="exportMenu" align="end" @select="onExport">
          <template #trigger>
            <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button>
          </template>
        </px-menu>
      </template>
    </px-page-header>

    <px-toolbar :filter-count="warehouse_id ? 1 : 0" @open-filters="filtersOpen = !filtersOpen" :searchable="false" />

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
          row-key="__rowkey"
        >
          <template #cell-stock_alert="{ row }"><px-badge tone="danger">{{ row.stock_alert }}</px-badge></template>
          <template #cell-quantity="{ row }"><span class="pxn-num">{{ row.quantity }}</span></template>
        </px-table>

        <px-empty-state v-else icon="shield" :title="$t('No_report_rows') || 'Sin alertas'" :description="$t('No_report_rows_desc')" />
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
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxMenu from "@/components/px-next/PxMenu.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "Products Alert"
  },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxBadge,
    PxField, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
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
      warehouses: [],
      warehouse_id: ""
    };
  },

  computed: {
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
        { key: "warehouse", label: this.$t("warehouse") },
        { key: "quantity", label: this.$t("Quantity"), align: "right" },
        { key: "stock_alert", label: this.$t("AlertQuantity") }
      ];
    }
  },

  methods: {
    onExport(item) {
      const k = item && item.key;
      if (k === "print") this.printTableOnly();
      else if (k === "pdf") this.stock_alert_PDF();
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
      link.setAttribute("download", "Alerts_report.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    //------ Print Table Only
    printTableOnly() {
      const productsData = this.products || [];

      let tableHtml = `<table class="vgt-table table table-hover tableOne">`;
      tableHtml += `<thead><tr>`;
      this.columns.forEach(col => {
        tableHtml += `<th class="text-left">${col.label}</th>`;
      });
      tableHtml += `</tr></thead>`;
      tableHtml += `<tbody>`;
      productsData.forEach(row => {
        tableHtml += `<tr>`;
        this.columns.forEach(col => {
          let cellContent = row[col.key] || '';
          tableHtml += `<td class="text-left">${cellContent}</td>`;
        });
        tableHtml += `</tr>`;
      });
      tableHtml += `</tbody>`;

      const totalQuantity = productsData.reduce((sum, product) => sum + parseFloat(product.quantity || 0), 0);
      const totalStockAlert = productsData.reduce((sum, product) => sum + parseFloat(product.stock_alert || 0), 0);
      tableHtml += `<tfoot><tr>`;
      tableHtml += `<td class="text-left font-weight-bold">${this.$t('Total')}</td>`;
      tableHtml += `<td colspan="2"></td>`;
      tableHtml += `<td class="text-left font-weight-bold">${totalQuantity.toFixed(2)}</td>`;
      tableHtml += `<td class="text-left font-weight-bold">${totalStockAlert.toFixed(2)}</td>`;
      tableHtml += `</tr></tfoot>`;
      tableHtml += `</table>`;

      const w = window.open("", "_blank");
      if (!w) {
        window.print();
        return;
      }

      const title = `${this.$t("Reports")} / ${this.$t("ProductQuantityAlerts")}`;
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

      //----------------------------------- Alerts PDF ------------------------------\\
    stock_alert_PDF() {
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
        self.$t("warehouse"),
        self.$t("Quantity"),
        self.$t("AlertQuantity")
      ];

      const body = (self.products || []).map(product => ([
        product.code,
        product.name,
        product.warehouse,
        product.quantity,
        product.stock_alert
      ]));

      let totalquantity = self.products.reduce((sum, product) => sum + parseFloat(product.quantity || 0), 0);
      let totalstock_alert = self.products.reduce((sum, product) => sum + parseFloat(product.stock_alert || 0), 0);

      const footer = [[
        self.$t("Total"),
        '',
        '',
        totalquantity.toFixed(2),
        totalstock_alert.toFixed(2)
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
          const title = 'Stock Alert report';
          rtl ? pdf.text(title, pageW - marginX, 38, { align: 'right' })
              : pdf.text(title, marginX, 38);

          pdf.setTextColor(33);

          pdf.setFontSize(8);
          const pn = `${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`;
          rtl ? pdf.text(pn, marginX, pageH - 14, { align: 'left' })
              : pdf.text(pn, pageW - marginX, pageH - 14, { align: 'right' });
        }
      });

      pdf.save("Stock_alert_report.pdf");

    },


    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Stock_Alerts(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Stock_Alerts(1); } },

    //---------------------- Event Select Warehouse ------------------------------\\
    Selected_Warehouse(value) {
      if (value === null || value === undefined) {
        this.warehouse_id = "";
      }
      this.Get_Stock_Alerts(1);
    },

    //----------------------------- Get Stock Alerts-------------------\\
    Get_Stock_Alerts(page) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "get_products_stock_alerts?page=" +
            page +
            "&warehouse=" +
            this.warehouse_id +
            "&limit=" +
            this.limit
        )
        .then(response => {
          this.products = (response.data.products.data || []).map((p, i) => Object.assign({ __rowkey: `${p.code || 'p'}-${p.warehouse || ''}-${i}` }, p));
          this.warehouses = response.data.warehouses;
          this.totalRows = response.data.products.total;
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
    this.Get_Stock_Alerts(1);
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrl__pad { padding: var(--pxn-space-6) 0; }
.pxrl__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxrl__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 560px) { .pxrl__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxrl__tablewrap { margin-top: var(--pxn-space-5); }
</style>
