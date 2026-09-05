<template>
  <div class="px-next pxrl">
    <px-page-header
      :title="product.name ? `${$t('stock_report')} · ${product.name}` : $t('stock_report')"
      :breadcrumbs="crumbs"
    />

    <div v-if="isLoading" class="pxrl__pad">
      <px-skeleton variant="table" :rows="10" :columns="7" />
    </div>

    <template v-else>
      <px-card v-if="product.type == 'is_single' && (product.CountQTY || []).length" :title="$t('Quantity')" class="pxrl__chartcard">
        <table class="pxrl__minitable">
          <thead>
            <tr><th>{{ $t('warehouse') }}</th><th class="pxrl__tr">{{ $t('Quantity') }}</th></tr>
          </thead>
          <tbody>
            <tr v-for="(w, i) in product.CountQTY" :key="i">
              <td>{{ w.mag }}</td>
              <td class="pxrl__tr pxn-num">{{ formatNumber(w.qte, 2) }} {{ product.unit }}</td>
            </tr>
          </tbody>
        </table>
      </px-card>

      <px-card v-if="product.is_variant == 'yes' && (product.CountQTY_variants || []).length" :title="$t('Variant')" class="pxrl__chartcard">
        <table class="pxrl__minitable">
          <thead>
            <tr><th>{{ $t('warehouse') }}</th><th>{{ $t('Variant') }}</th><th class="pxrl__tr">{{ $t('Quantity') }}</th></tr>
          </thead>
          <tbody>
            <tr v-for="(v, i) in product.CountQTY_variants" :key="i">
              <td>{{ v.mag }}</td>
              <td>{{ v.variant }}</td>
              <td class="pxrl__tr pxn-num">{{ formatNumber(v.qte, 2) }} {{ product.unit }}</td>
            </tr>
          </tbody>
        </table>
      </px-card>

      <div class="pxrl__tabbar">
        <button
          v-for="t in tabs"
          :key="t.key"
          type="button"
          class="pxrl__tab pxn-ring"
          :class="{ 'is-active': activeTab === t.key }"
          @click="activeTab = t.key"
        >{{ t.label }}</button>
      </div>

      <div v-for="t in tabs" v-show="activeTab === t.key" :key="'panel-' + t.key" class="pxrl__panel">
        <px-toolbar :search="searchOf(t.key)" :search-placeholder="$t('Search_this_table')" @update:search="v => onSearch(t.key, v)">
          <template #actions><px-menu :items="pdfPrintMenu" align="end" @select="k => onExport(t.key, k)">
            <template #trigger><px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button></template>
          </px-menu></template>
        </px-toolbar>

        <div class="pxrl__tablewrap">
          <px-table v-if="rowsOf(t.key).length" :columns="columnsOf(t.key)" :rows="rowsOf(t.key)" row-key="__rowkey">
            <template #cell-Ref="{ row }">
              <router-link v-if="refLink(t.key, row)" :to="refLink(t.key, row)" class="pxrl__link">{{ row.Ref }}</router-link>
              <span v-else>{{ row.Ref }}</span>
            </template>
            <template #cell-total="{ row }"><span class="pxn-num">{{ money(row.total) }}</span></template>
            <template #cell-quantity="{ row }"><span class="pxn-num">{{ row.quantity }}</span></template>
          </px-table>
          <px-empty-state v-else icon="package" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
        </div>

        <div v-if="rowsOf(t.key).length && t.hasTotal" class="pxrl__totalrow">
          <span>{{ $t('Total') }}</span>
          <span>{{ $t('SubTotal') }}: <b class="pxn-num">{{ money(stripSum(rowsOf(t.key), 'total')) }}</b></span>
        </div>

        <px-pagination v-if="rowsOf(t.key).length" :page="Number(pageOf(t.key))" :per-page="Number(limitOf(t.key))" :total="Number(totalOf(t.key)) || 0"
          @update:page="p => onPage(t.key, p)" @update:perPage="v => onLimit(t.key, v)" />
      </div>
    </template>
  </div>
</template>


<script>
import { mapGetters } from "vuex";
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
import PxCard from "@/components/px-next/PxCard.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  metaInfo: {
    title: "Stock Report Detail"
  },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxCard, PxEmptyState
  },
  data() {
    return {
      activeTab: "sales",
      totalRows_quotations: "",
      totalRows_sales: "",
      totalRows_sales_return: "",
      totalRows_purchases_return: "",
      totalRows_purchases: "",
      totalRows_transfers: "",
      totalRows_adjustments: "",

      limit_quotations: "10",
      limit_sales_return: "10",
      limit_purchases_return: "10",
      limit_sales: "10",
      limit_purchases: "10",
      limit_transfers: "10",
      limit_adjustments: "10",

      sales_page: 1,
      quotations_page: 1,
      Return_sale_page: 1,
      Return_purchase_page: 1,
      purchases_page: 1,
      transfers_page: 1,
      adjustments_page: 1,

      search_sales: "",
      search_purchases: "",
      search_quotations: "",
      search_return_sales: "",
      search_return_purchases: "",
      search_transfers: "",
      search_adjustments: "",

      isLoading: true,
      product: {},
      purchases: [],
      sales: [],
      quotations: [],
      sales_return: [],
      purchases_return: [],
      transfers: [],
      adjustments: [],
      price_format_key: null
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    crumbs() {
      const c = [
        { label: this.$t('Reports'), href: '#/app/reports/all' },
        { label: this.$t('stock_report'), href: '#/app/reports/stock_report' }
      ];
      if (this.product.name) c.push({ label: this.product.name });
      return c;
    },
    tabs() {
      return [
        { key: "sales", label: this.$t("Sales"), hasTotal: true },
        { key: "quotations", label: this.$t("Quotations"), hasTotal: true },
        { key: "purchases", label: this.$t("Purchases"), hasTotal: true },
        { key: "sales_return", label: this.$t("SalesReturn"), hasTotal: true },
        { key: "purchases_return", label: this.$t("PurchasesReturn"), hasTotal: true },
        { key: "transfers", label: this.$t("StockTransfers"), hasTotal: false },
        { key: "adjustments", label: this.$t("Adjustment"), hasTotal: false }
      ];
    },
    pdfPrintMenu() {
      return [
        { key: "print", label: this.$t("print"), icon: "printer" },
        { key: "pdf", label: "PDF", icon: "file-text" }
      ];
    },
    txnColumns() {
      return [
        { key: "date", label: this.$t("date") },
        { key: "Ref", label: this.$t("Reference"), strong: true },
        { key: "product_name", label: this.$t("product_name"), sortable: false },
        { key: "client_name", label: this.$t("Customer"), sortable: false },
        { key: "warehouse_name", label: this.$t("warehouse"), sortable: false },
        { key: "quantity", label: this.$t("Quantity"), align: "right", sortable: false },
        { key: "total", label: this.$t("SubTotal"), align: "right", sortable: false }
      ];
    },
    supplierTxnColumns() {
      return [
        { key: "date", label: this.$t("date") },
        { key: "Ref", label: this.$t("Reference"), strong: true },
        { key: "product_name", label: this.$t("product_name"), sortable: false },
        { key: "provider_name", label: this.$t("Supplier"), sortable: false },
        { key: "warehouse_name", label: this.$t("warehouse"), sortable: false },
        { key: "quantity", label: this.$t("Quantity"), align: "right", sortable: false },
        { key: "total", label: this.$t("SubTotal"), align: "right", sortable: false }
      ];
    },
    columns_transfers() {
      return [
        { key: "date", label: this.$t("date") },
        { key: "Ref", label: this.$t("Reference") },
        { key: "product_name", label: this.$t("product_name"), sortable: false },
        { key: "from_warehouse", label: this.$t("FromWarehouse") },
        { key: "to_warehouse", label: this.$t("ToWarehouse") }
      ];
    },
    columns_adjustments() {
      return [
        { key: "date", label: this.$t("date") },
        { key: "Ref", label: this.$t("Reference") },
        { key: "product_name", label: this.$t("product_name"), sortable: false },
        { key: "warehouse_name", label: this.$t("warehouse") }
      ];
    }
  },

  methods: {
    rowsOf(k) {
      return { sales: this.sales, quotations: this.quotations, purchases: this.purchases, sales_return: this.sales_return, purchases_return: this.purchases_return, transfers: this.transfers, adjustments: this.adjustments }[k] || [];
    },
    columnsOf(k) {
      if (k === 'purchases' || k === 'purchases_return') return this.supplierTxnColumns;
      if (k === 'transfers') return this.columns_transfers;
      if (k === 'adjustments') return this.columns_adjustments;
      return this.txnColumns;
    },
    searchOf(k) {
      return { sales: this.search_sales, quotations: this.search_quotations, purchases: this.search_purchases, sales_return: this.search_return_sales, purchases_return: this.search_return_purchases, transfers: this.search_transfers, adjustments: this.search_adjustments }[k];
    },
    pageOf(k) {
      return { sales: this.sales_page, quotations: this.quotations_page, purchases: this.purchases_page, sales_return: this.Return_sale_page, purchases_return: this.Return_purchase_page, transfers: this.transfers_page, adjustments: this.adjustments_page }[k];
    },
    limitOf(k) {
      return { sales: this.limit_sales, quotations: this.limit_quotations, purchases: this.limit_purchases, sales_return: this.limit_sales_return, purchases_return: this.limit_purchases_return, transfers: this.limit_transfers, adjustments: this.limit_adjustments }[k];
    },
    totalOf(k) {
      return { sales: this.totalRows_sales, quotations: this.totalRows_quotations, purchases: this.totalRows_purchases, sales_return: this.totalRows_sales_return, purchases_return: this.totalRows_purchases_return, transfers: this.totalRows_transfers, adjustments: this.totalRows_adjustments }[k];
    },
    refLink(k, row) {
      if (k === 'sales') return row.sale_id ? '/app/sales/detail/' + row.sale_id : null;
      if (k === 'quotations') return row.quotation_id ? '/app/quotations/detail/' + row.quotation_id : null;
      if (k === 'purchases') return row.purchase_id ? '/app/purchases/detail/' + row.purchase_id : null;
      if (k === 'sales_return') return row.return_sale_id ? '/app/sale_return/detail/' + row.return_sale_id : null;
      if (k === 'purchases_return') return row.return_purchase_id ? '/app/purchase_return/detail/' + row.return_purchase_id : null;
      return null;
    },

    stripSum(arr, field) {
      return (arr || []).reduce((acc, r) => {
        const v = Number(r[field]) || 0;
        return Number.isFinite(v) ? acc + v : acc;
      }, 0);
    },

    onExport(tab, item) {
      const k = item && item.key ? item.key : item;
      if (k === 'print') { this.printTableOnly(tab); return; }
      if (k === 'pdf') {
        const map = {
          sales: 'Sales_PDF', quotations: 'Quotation_PDF', purchases: 'Purchase_PDF',
          sales_return: 'Sale_Return_PDF', purchases_return: 'Returns_Purchase_PDF',
          transfers: 'Transfer_PDF', adjustments: 'Adjustment_PDF'
        };
        if (map[tab] && typeof this[map[tab]] === 'function') this[map[tab]]();
      }
    },
    onSearch(tab, v) {
      const m = {
        sales: ['search_sales', 'Get_Sales'], quotations: ['search_quotations', 'Get_Quotations'],
        purchases: ['search_purchases', 'Get_Purchases'], sales_return: ['search_return_sales', 'Get_Sales_Return'],
        purchases_return: ['search_return_purchases', 'Get_Purchases_Return'], transfers: ['search_transfers', 'Get_Transfers'],
        adjustments: ['search_adjustments', 'Get_adjustments']
      }[tab];
      if (!m) return;
      this[m[0]] = v;
      this[m[1]](1);
    },
    onPage(tab, p) {
      const m = {
        sales: ['sales_page', 'Get_Sales'], quotations: ['quotations_page', 'Get_Quotations'],
        purchases: ['purchases_page', 'Get_Purchases'], sales_return: ['Return_sale_page', 'Get_Sales_Return'],
        purchases_return: ['Return_purchase_page', 'Get_Purchases_Return'], transfers: ['transfers_page', 'Get_Transfers'],
        adjustments: ['adjustments_page', 'Get_adjustments']
      }[tab];
      if (!m) return;
      if (this[m[0]] !== p) this[m[1]](p);
    },
    onLimit(tab, v) {
      const s = String(v);
      const m = {
        sales: ['limit_sales', 'Get_Sales'], quotations: ['limit_quotations', 'Get_Quotations'],
        purchases: ['limit_purchases', 'Get_Purchases'], sales_return: ['limit_sales_return', 'Get_Sales_Return'],
        purchases_return: ['limit_purchases_return', 'Get_Purchases_Return'], transfers: ['limit_transfers', 'Get_Transfers'],
        adjustments: ['limit_adjustments', 'Get_adjustments']
      }[tab];
      if (!m) return;
      if (this[m[0]] !== s) { this[m[0]] = s; this[m[1]](1); }
    },

    _pdf(title, columns, body, fileName) {
      let pdf = new jsPDF("p", "pt");
      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      pdf.addFont(fontPath, "VazirmatnBold", "bold");
      pdf.setFont("VazirmatnBold");
      autoTable(pdf, {
        columns: columns, body: body, startY: 70, theme: "grid",
        didDrawPage: () => { pdf.setFont("VazirmatnBold"); pdf.setFontSize(18); pdf.text(title, 40, 25); },
        styles: { font: "VazirmatnBold", halign: "center" },
        headStyles: { fillColor: [26, 86, 219], textColor: 255, fontStyle: "bold" },
      });
      pdf.save(fileName);
    },
    _txnPdfCols(clientKey, clientHeader) {
      return [
        { header: this.$t("date"), dataKey: "date" },
        { header: this.$t("Reference"), dataKey: "Ref" },
        { header: this.$t("product_name"), dataKey: "product_name" },
        { header: clientHeader, dataKey: clientKey },
        { header: this.$t("warehouse"), dataKey: "warehouse_name" },
        { header: this.$t("Quantity"), dataKey: "quantity" },
        { header: this.$t("SubTotal"), dataKey: "total" }
      ];
    },
    Sales_PDF() { this._pdf("Sales List", this._txnPdfCols("client_name", this.$t("Customer")), this.sales, "Sale_List.pdf"); },
    Quotation_PDF() { this._pdf("Quotation List", this._txnPdfCols("client_name", this.$t("Customer")), this.quotations, "Quotation_List.pdf"); },
    Purchase_PDF() { this._pdf("Purchase List", this._txnPdfCols("provider_name", this.$t("Supplier")), this.purchases, "Purchase_List.pdf"); },
    Sale_Return_PDF() { this._pdf("Sales Return List", this._txnPdfCols("client_name", this.$t("Customer")), this.sales_return, "Sales Return.pdf"); },
    Returns_Purchase_PDF() { this._pdf("Purchase Return List", this._txnPdfCols("provider_name", this.$t("Supplier")), this.purchases_return, "purchase_returns.pdf"); },
    Transfer_PDF() {
      this._pdf("Transfer List", [
        { header: this.$t("date"), dataKey: "date" },
        { header: this.$t("Reference"), dataKey: "Ref" },
        { header: this.$t("product_name"), dataKey: "product_name" },
        { header: this.$t("FromWarehouse"), dataKey: "from_warehouse" },
        { header: this.$t("ToWarehouse"), dataKey: "to_warehouse" }
      ], this.transfers, "Transfer_List.pdf");
    },
    Adjustment_PDF() {
      this._pdf("Adjustment List", [
        { header: this.$t("date"), dataKey: "date" },
        { header: this.$t("Reference"), dataKey: "Ref" },
        { header: this.$t("product_name"), dataKey: "product_name" },
        { header: this.$t("warehouse"), dataKey: "warehouse_name" }
      ], this.adjustments, "Adjustment_List.pdf");
    },

    //------------------------------Formetted Numbers -------------------------\\
    formatNumber(number, dec) {
      const value = (typeof number === "string" ? number : Number(number || 0).toString()).split(".");
      if (dec <= 0) return value[0];
      let formated = value[1] || "";
      if (formated.length > dec) return `${value[0]}.${formated.substr(0, dec)}`;
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
    money(v) {
      return this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, v, 2);
    },

    //------ Print Table Only - Print data with all columns based on table type
    printTableOnly(tableType) {
      const cfg = {
        sales: [this.$t("Sales"), this.sales, this.columnsOf('sales')],
        quotations: [this.$t("Quotations"), this.quotations, this.columnsOf('quotations')],
        purchases: [this.$t("Purchases"), this.purchases, this.columnsOf('purchases')],
        sales_return: [this.$t("SalesReturn"), this.sales_return, this.columnsOf('sales_return')],
        purchases_return: [this.$t("PurchasesReturn"), this.purchases_return, this.columnsOf('purchases_return')],
        transfers: [this.$t("StockTransfers"), this.transfers, this.columnsOf('transfers')],
        adjustments: [this.$t("Adjustment"), this.adjustments, this.columnsOf('adjustments')]
      }[tableType];
      if (!cfg) return;
      const title = `${this.$t("Reports")} / ${this.$t("stock_report")} / ${cfg[0]}`;
      const rows = Array.isArray(cfg[1]) ? cfg[1] : [];
      const columns = cfg[2];

      let tableHTML = '<table style="width: 100%; border-collapse: collapse; font-size: 10px;">';
      tableHTML += '<thead><tr>';
      columns.forEach(col => {
        tableHTML += `<th style="border: 1px solid #ddd; padding: 6px 8px; background-color: #f5f5f5; font-weight: bold; text-align: left;">${col.label}</th>`;
      });
      tableHTML += '</tr></thead><tbody>';

      rows.forEach(row => {
        tableHTML += '<tr>';
        columns.forEach(col => {
          let cellValue = '';
          if (col.key === 'total') {
            cellValue = this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, row.total, 2);
          } else {
            cellValue = row[col.key] || '';
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

    //----------------------------------- Get Details Product ------------------------------\\
    showDetails() {
      let id = this.$route.params.id;
      axios
        .get(`get_product_detail/${id}`)
        .then(response => {
          this.product = response.data;
        })
        .catch(response => {});
    },

    //--------------------------- get_sales_by_product -------------\\
    Get_Sales(page) {
      this.sales_page = page;
      axios
        .get("/report/get_sales_by_product?page=" + page + "&limit=" + this.limit_sales + "&search=" + this.search_sales + "&id=" + this.$route.params.id)
        .then(response => {
          this.sales = (response.data.sales || []).map((r, i) => Object.assign({ __rowkey: r.sale_id != null ? `s-${r.sale_id}-${i}` : `r-${i}` }, r));
          this.totalRows_sales = response.data.totalRows;
        })
        .catch(response => {});
    },

    //--------------------------- Get Purchases By product -------------\\
    Get_Purchases(page) {
      this.purchases_page = page;
      axios
        .get("report/get_purchases_by_product?page=" + page + "&limit=" + this.limit_purchases + "&search=" + this.search_purchases + "&id=" + this.$route.params.id)
        .then(response => {
          this.purchases = (response.data.purchases || []).map((r, i) => Object.assign({ __rowkey: r.purchase_id != null ? `pu-${r.purchase_id}-${i}` : `r-${i}` }, r));
          this.totalRows_purchases = response.data.totalRows;
          this.isLoading = false;
        })
        .catch(response => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    //--------------------------- Get Quotations By product -------------\\
    Get_Quotations(page) {
      this.quotations_page = page;
      axios
        .get("report/get_quotations_by_product?page=" + page + "&limit=" + this.limit_quotations + "&search=" + this.search_quotations + "&id=" + this.$route.params.id)
        .then(response => {
          this.quotations = (response.data.quotations || []).map((r, i) => Object.assign({ __rowkey: r.quotation_id != null ? `q-${r.quotation_id}-${i}` : `r-${i}` }, r));
          this.totalRows_quotations = response.data.totalRows;
        })
        .catch(response => {});
    },

    //--------------------------- Get Transfers By product -------------\\
    Get_Transfers(page) {
      this.transfers_page = page;
      axios
        .get("report/get_transfer_by_product?page=" + page + "&limit=" + this.limit_transfers + "&search=" + this.search_transfers + "&id=" + this.$route.params.id)
        .then(response => {
          this.transfers = (response.data.transfers || []).map((r, i) => Object.assign({ __rowkey: `t-${i}` }, r));
          this.totalRows_transfers = response.data.totalRows;
        })
        .catch(response => {});
    },

    //--------------------------- Get adjustment By product -------------\\
    Get_adjustments(page) {
      this.adjustments_page = page;
      axios
        .get("report/get_adjustment_by_product?page=" + page + "&limit=" + this.limit_adjustments + "&search=" + this.search_adjustments + "&id=" + this.$route.params.id)
        .then(response => {
          this.adjustments = (response.data.adjustments || []).map((r, i) => Object.assign({ __rowkey: `a-${i}` }, r));
          this.totalRows_adjustments = response.data.totalRows;
        })
        .catch(response => {});
    },

    //--------------------------- Get sales Returns By product -------------\\
    Get_Sales_Return(page) {
      this.Return_sale_page = page;
      axios
        .get("/report/get_sales_return_by_product?page=" + page + "&limit=" + this.limit_sales_return + "&search=" + this.search_return_sales + "&id=" + this.$route.params.id)
        .then(response => {
          this.sales_return = (response.data.sales_return || []).map((r, i) => Object.assign({ __rowkey: r.return_sale_id != null ? `sr-${r.return_sale_id}-${i}` : `r-${i}` }, r));
          this.totalRows_sales_return = response.data.totalRows;
        })
        .catch(response => {});
    },

    //--------------------------- Get purchases Returns By product -------------\\
    Get_Purchases_Return(page) {
      this.Return_purchase_page = page;
      axios
        .get("/report/get_purchase_return_by_product?page=" + page + "&limit=" + this.limit_purchases_return + "&search=" + this.search_return_purchases + "&id=" + this.$route.params.id)
        .then(response => {
          this.purchases_return = (response.data.purchases_return || []).map((r, i) => Object.assign({ __rowkey: r.return_purchase_id != null ? `pr-${r.return_purchase_id}-${i}` : `r-${i}` }, r));
          this.totalRows_purchases_return = response.data.totalRows;
        })
        .catch(response => {});
    }
  }, //end Methods

  //----------------------------- Created function------------------- \\

  created: function() {
    this.showDetails();
    this.Get_Sales(1);
    this.Get_Purchases(1);
    this.Get_Quotations(1);
    this.Get_Sales_Return(1);
    this.Get_Purchases_Return(1);
    this.Get_Transfers(1);
    this.Get_adjustments(1);
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrl__pad { padding: var(--pxn-space-6) 0; }
.pxrl__chartcard { margin-top: var(--pxn-space-5); }
.pxrl__minitable { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxrl__minitable th, .pxrl__minitable td { padding: var(--pxn-space-2) var(--pxn-space-3); border-bottom: 1px solid var(--pxn-border); text-align: left; }
.pxrl__minitable th { font-size: var(--pxn-fs-xs); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); }
.pxrl__tr { text-align: right; }
.pxrl__tabbar { display: flex; gap: var(--pxn-space-2); margin-top: var(--pxn-space-6); border-bottom: 1px solid var(--pxn-border); overflow-x: auto; }
.pxrl__tab {
  appearance: none; background: none; border: 0; border-bottom: 2px solid transparent; white-space: nowrap;
  padding: var(--pxn-space-3) var(--pxn-space-4); font: inherit; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink-3); cursor: pointer; transition: color 120ms, border-color 120ms;
}
.pxrl__tab:hover { color: var(--pxn-ink); }
.pxrl__tab.is-active { color: var(--pxn-ink); border-bottom-color: var(--pxn-primary); font-weight: var(--pxn-fw-semibold); }
.pxrl__panel { margin-top: var(--pxn-space-5); }
.pxrl__tablewrap { margin-top: var(--pxn-space-4); }
.pxrl__link { color: var(--pxn-primary); }
.pxrl__totalrow { display: flex; align-items: center; justify-content: flex-end; gap: var(--pxn-space-6); margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
</style>
