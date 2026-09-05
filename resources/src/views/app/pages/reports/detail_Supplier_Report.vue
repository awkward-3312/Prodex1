<template>
  <div class="px-next pxrl">
    <px-page-header
      :title="provider.name ? `${$t('SuppliersReport')} · ${provider.name}` : $t('SuppliersReport')"
      :breadcrumbs="crumbs"
    />

    <div v-if="isLoading" class="pxrl__pad">
      <px-skeleton variant="table" :rows="10" :columns="8" />
    </div>

    <template v-else>
      <div class="pxrl__stats">
        <px-stat icon="shopping-cart" :label="$t('Purchases')" :value="String(provider.total_purchase || 0)" bordered />
        <px-stat icon="trending-up" :label="$t('TotalAmount')" :value="`${currentUser.currency} ${formatNumber(provider.total_amount, priceDecimals)}`" bordered />
        <px-stat icon="banknote" :label="$t('TotalPaid')" :value="formatPriceWithSymbol(currentUser.currency, provider.total_paid, 2)" bordered />
        <px-stat icon="wallet" :label="$t('Due')" :value="`${currentUser.currency} ${formatNumber(provider.due, priceDecimals)}`" bordered />
      </div>

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

      <!-- Purchases -->
      <div v-show="activeTab === 'purchases'" class="pxrl__panel">
        <px-toolbar :search="search_purchases" :search-placeholder="$t('Search_this_table')" @update:search="v => onSearch('purchases', v)">
          <template #actions>
            <px-menu :items="pdfPrintMenu" align="end" @select="k => onExport('purchases', k)">
              <template #trigger><px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button></template>
            </px-menu>
          </template>
        </px-toolbar>
        <div class="pxrl__tablewrap">
          <px-table v-if="purchases.length" :columns="columns_purchases" :rows="purchases" row-key="__rowkey">
            <template #cell-Ref="{ row }">
              <router-link v-if="row.id" :to="'/app/purchases/detail/' + row.id" class="pxrl__link">{{ row.Ref }}</router-link>
              <span v-else>{{ row.Ref }}</span>
            </template>
            <template #cell-GrandTotal="{ row }"><span class="pxn-num">{{ formatPriceDisplay(row.GrandTotal, 2) }}</span></template>
            <template #cell-paid_amount="{ row }"><span class="pxn-num">{{ formatPriceDisplay(row.paid_amount, 2) }}</span></template>
            <template #cell-due="{ row }"><span class="pxn-num">{{ formatPriceDisplay(row.due, 2) }}</span></template>
            <template #cell-statut="{ row }"><px-badge :tone="purchaseStatutTone(row.statut)">{{ purchaseStatutLabel(row.statut) }}</px-badge></template>
            <template #cell-payment_status="{ row }"><px-badge :tone="payTone(row.payment_status)">{{ payLabel(row.payment_status) }}</px-badge></template>
          </px-table>
          <px-empty-state v-else icon="shopping-cart" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
        </div>
        <px-pagination v-if="purchases.length" :page="Number(purchases_page)" :per-page="Number(limit_purchases)" :total="Number(totalRows_purchases) || 0"
          @update:page="p => onPage('purchases', p)" @update:perPage="v => onLimit('purchases', v)" />
      </div>

      <!-- Returns -->
      <div v-show="activeTab === 'returns'" class="pxrl__panel">
        <px-toolbar :search="search_return_purchases" :search-placeholder="$t('Search_this_table')" @update:search="v => onSearch('returns', v)">
          <template #actions>
            <px-menu :items="pdfPrintMenu" align="end" @select="k => onExport('returns', k)">
              <template #trigger><px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button></template>
            </px-menu>
          </template>
        </px-toolbar>
        <div class="pxrl__tablewrap">
          <px-table v-if="returns_supplier.length" :columns="columns_returns" :rows="returns_supplier" row-key="__rowkey">
            <template #cell-Ref="{ row }">
              <router-link v-if="row.id" :to="'/app/purchase_return/detail/' + row.id" class="pxrl__link">{{ row.Ref }}</router-link>
              <span v-else>{{ row.Ref }}</span>
            </template>
            <template #cell-purchase_ref="{ row }">
              <router-link v-if="row.purchase_id" :to="'/app/purchases/detail/' + row.purchase_id" class="pxrl__link">{{ row.purchase_ref }}</router-link>
              <span v-else>{{ row.purchase_ref }}</span>
            </template>
            <template #cell-GrandTotal="{ row }"><span class="pxn-num">{{ formatPriceDisplay(row.GrandTotal, 2) }}</span></template>
            <template #cell-paid_amount="{ row }"><span class="pxn-num">{{ formatPriceDisplay(row.paid_amount, 2) }}</span></template>
            <template #cell-due="{ row }"><span class="pxn-num">{{ formatPriceDisplay(row.due, 2) }}</span></template>
            <template #cell-statut="{ row }"><px-badge :tone="row.statut === 'completed' ? 'success' : 'info'">{{ row.statut === 'completed' ? $t('complete') : $t('Pending') }}</px-badge></template>
            <template #cell-payment_status="{ row }"><px-badge :tone="payTone(row.payment_status)">{{ payLabel(row.payment_status) }}</px-badge></template>
          </px-table>
          <px-empty-state v-else icon="corner-up-left" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
        </div>
        <px-pagination v-if="returns_supplier.length" :page="Number(Return_page)" :per-page="Number(limit_returns)" :total="Number(totalRows_returns) || 0"
          @update:page="p => onPage('returns', p)" @update:perPage="v => onLimit('returns', v)" />
      </div>

      <!-- Payments -->
      <div v-show="activeTab === 'payments'" class="pxrl__panel">
        <px-toolbar :search="search_payments" :search-placeholder="$t('Search_this_table')" @update:search="v => onSearch('payments', v)">
          <template #actions>
            <px-menu :items="pdfPrintMenu" align="end" @select="k => onExport('payments', k)">
              <template #trigger><px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button></template>
            </px-menu>
          </template>
        </px-toolbar>
        <div class="pxrl__tablewrap">
          <px-table v-if="payments.length" :columns="columns_payments" :rows="payments" row-key="__rowkey">
            <template #cell-montant="{ row }"><span class="pxn-num">{{ formatPriceDisplay(row.montant, 2) }}</span></template>
          </px-table>
          <px-empty-state v-else icon="coins" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
        </div>
        <px-pagination v-if="payments.length" :page="Number(Payment_page)" :per-page="Number(limit_payments)" :total="Number(totalRows_payments) || 0"
          @update:page="p => onPage('payments', p)" @update:perPage="v => onLimit('payments', v)" />
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
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxStat from "@/components/px-next/PxStat.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  metaInfo: {
    title: "Supplier Report Detail"
  },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxBadge, PxStat, PxEmptyState
  },
  data() {
    return {
      activeTab: "purchases",
      totalRows_purchases: "",
      totalRows_returns: "",
      totalRows_payments: "",
      limit_returns: "10",
      limit_purchases: "10",
      limit_payments: "10",
      purchases_page: 1,
      Return_page: 1,
      Payment_page: 1,
      isLoading: true,
      returns_supplier: [],
      payments: [],
      purchases: [],

      search_purchases: "",
      search_payments: "",
      search_return_purchases: "",

      provider: {
        id: "",
        name: "",
        total_purchase: 0,
        total_amount: 0,
        total_paid: 0,
        due: 0
      },
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
        { label: this.$t('SuppliersReport'), href: '#/app/reports/providers_report' }
      ];
      if (this.provider.name) c.push({ label: this.provider.name });
      return c;
    },
    tabs() {
      return [
        { key: "purchases", label: this.$t("Purchases") },
        { key: "returns", label: this.$t("Returns") },
        { key: "payments", label: this.$t("PurchaseInvoice") }
      ];
    },
    pdfPrintMenu() {
      return [
        { key: "print", label: this.$t("print"), icon: "printer" },
        { key: "pdf", label: "PDF", icon: "file-text" }
      ];
    },
    columns_purchases() {
      return [
        { key: "Ref", label: this.$t("Reference"), strong: true, sortable: false },
        { key: "provider_name", label: this.$t("Supplier"), sortable: false },
        { key: "warehouse_name", label: this.$t("warehouse") },
        { key: "GrandTotal", label: this.$t("Total"), align: "right", sortable: false },
        { key: "paid_amount", label: this.$t("Paid"), align: "right", sortable: false },
        { key: "due", label: this.$t("Due"), align: "right", sortable: false },
        { key: "statut", label: this.$t("Status"), sortable: false },
        { key: "payment_status", label: this.$t("PaymentStatus"), sortable: false }
      ];
    },
    columns_returns() {
      return [
        { key: "Ref", label: this.$t("Reference"), strong: true, sortable: false },
        { key: "provider_name", label: this.$t("Supplier"), sortable: false },
        { key: "warehouse_name", label: this.$t("warehouse") },
        { key: "purchase_ref", label: this.$t("Purchase_Ref") },
        { key: "GrandTotal", label: this.$t("Total"), align: "right", sortable: false },
        { key: "paid_amount", label: this.$t("Paid"), align: "right", sortable: false },
        { key: "due", label: this.$t("Due"), align: "right", sortable: false },
        { key: "statut", label: this.$t("Status"), sortable: false },
        { key: "payment_status", label: this.$t("PaymentStatus"), sortable: false }
      ];
    },
    columns_payments() {
      return [
        { key: "date", label: this.$t("date"), sortable: false },
        { key: "Ref", label: this.$t("Reference"), strong: true, sortable: false },
        { key: "purchase_Ref", label: this.$t("Purchase"), sortable: false },
        { key: "payment_method", label: this.$t("ModePaiement"), sortable: false },
        { key: "montant", label: this.$t("Amount"), align: "right", sortable: false }
      ];
    }
  },

  methods: {
    purchaseStatutTone(s) { return s === 'received' ? 'success' : s === 'pending' ? 'info' : 'warning'; },
    purchaseStatutLabel(s) { return s === 'received' ? this.$t('Received') : s === 'pending' ? this.$t('Pending') : this.$t('Ordered'); },
    payTone(s) { return s === 'paid' ? 'success' : s === 'partial' ? 'info' : 'warning'; },
    payLabel(s) { return s === 'paid' ? this.$t('Paid') : s === 'partial' ? this.$t('partial') : this.$t('Unpaid'); },

    onExport(tab, item) {
      const k = item && item.key ? item.key : item;
      if (k === 'print') { this.printTableOnly(tab); return; }
      if (k === 'pdf') {
        if (tab === 'purchases') this.Purchase_PDF();
        else if (tab === 'returns') this.Returns_Purchase_PDF();
        else if (tab === 'payments') this.Payments_PDF();
      }
    },
    onSearch(tab, v) {
      if (tab === 'purchases') { this.search_purchases = v; this.Get_Purchases(1); }
      else if (tab === 'returns') { this.search_return_purchases = v; this.Get_Returns(1); }
      else if (tab === 'payments') { this.search_payments = v; this.Get_Payments(1); }
    },
    onPage(tab, p) {
      if (tab === 'purchases' && this.purchases_page !== p) this.Get_Purchases(p);
      else if (tab === 'returns' && this.Return_page !== p) this.Get_Returns(p);
      else if (tab === 'payments' && this.Payment_page !== p) this.Get_Payments(p);
    },
    onLimit(tab, v) {
      const s = String(v);
      if (tab === 'purchases' && this.limit_purchases !== s) { this.limit_purchases = s; this.Get_Purchases(1); }
      else if (tab === 'returns' && this.limit_returns !== s) { this.limit_returns = s; this.Get_Returns(1); }
      else if (tab === 'payments' && this.limit_payments !== s) { this.limit_payments = s; this.Get_Payments(1); }
    },

    //---------------------- Purchases PDF -------------------------------\\
    Purchase_PDF() {
      var self = this;
      let pdf = new jsPDF("p", "pt");
      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      pdf.addFont(fontPath, "VazirmatnBold", "bold");
      pdf.setFont("VazirmatnBold");
      let columns = [
        { header: self.$t("Reference"), dataKey: "Ref" },
        { header: self.$t("Supplier"), dataKey: "provider_name" },
        { header: self.$t("warehouse"), dataKey: "warehouse_name" },
        { header: self.$t("Total"), dataKey: "GrandTotal" },
        { header: self.$t("Paid"), dataKey: "paid_amount" },
        { header: self.$t("Due"), dataKey: "due" },
        { header: self.$t("Status"), dataKey: "statut" },
        { header: self.$t("PaymentStatus"), dataKey: "payment_status" }
      ];
      autoTable(pdf, {
        columns: columns, body: self.purchases, startY: 70, theme: "grid",
        didDrawPage: () => { pdf.setFont("VazirmatnBold"); pdf.setFontSize(18); pdf.text("Purchase List", 40, 25); },
        styles: { font: "VazirmatnBold", halign: "center" },
        headStyles: { fillColor: [26, 86, 219], textColor: 255, fontStyle: "bold" },
      });
      pdf.save("Purchase_List.pdf");
    },

    //----------------------------------------- Returns Purchase PDF -----------------------\\
    Returns_Purchase_PDF() {
      var self = this;
      let pdf = new jsPDF("p", "pt");
      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      pdf.addFont(fontPath, "VazirmatnBold", "bold");
      pdf.setFont("VazirmatnBold");
      let columns = [
        { header: self.$t("Reference"), dataKey: "Ref" },
        { header: self.$t("Supplier"), dataKey: "provider_name" },
        { header: self.$t("warehouse"), dataKey: "warehouse_name" },
        { header: self.$t("Purchase"), dataKey: "purchase_ref" },
        { header: self.$t("Total"), dataKey: "GrandTotal" },
        { header: self.$t("Paid"), dataKey: "paid_amount" },
        { header: self.$t("Due"), dataKey: "due" },
        { header: self.$t("Status"), dataKey: "statut" },
        { header: self.$t("PaymentStatus"), dataKey: "payment_status" }
      ];
      autoTable(pdf, {
        columns: columns, body: self.returns_supplier, startY: 70, theme: "grid",
        didDrawPage: () => { pdf.setFont("VazirmatnBold"); pdf.setFontSize(18); pdf.text("Purchase Return List", 40, 25); },
        styles: { font: "VazirmatnBold", halign: "center" },
        headStyles: { fillColor: [26, 86, 219], textColor: 255, fontStyle: "bold" },
      });
      pdf.save("purchase_returns.pdf");
    },

    //----------------------------------- Payments PDF ------------------------------\\
    Payments_PDF() {
      var self = this;
      let pdf = new jsPDF("p", "pt");
      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      pdf.addFont(fontPath, "VazirmatnBold", "bold");
      pdf.setFont("VazirmatnBold");
      let columns = [
        { header: self.$t("date"), dataKey: "date" },
        { header: self.$t("Reference"), dataKey: "Ref" },
        { header: self.$t("Purchase"), dataKey: "purchase_Ref" },
        { header: self.$t("ModePaiement"), dataKey: "payment_method" },
        { header: self.$t("Amount"), dataKey: "montant" },
      ];
      autoTable(pdf, {
        columns: columns, body: self.payments, startY: 70, theme: "grid",
        didDrawPage: () => { pdf.setFont("VazirmatnBold"); pdf.setFontSize(18); pdf.text("Payments List", 40, 25); },
        styles: { font: "VazirmatnBold", halign: "center" },
        headStyles: { fillColor: [26, 86, 219], textColor: 255, fontStyle: "bold" },
      });
      pdf.save("Payments_List.pdf");
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
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        const effectiveKey = key || null;
        return formatPriceDisplayHelper(number, decimals, effectiveKey);
      } catch (e) {
        return this.formatNumber(number, dec);
      }
    },

    formatPriceWithSymbol(symbol, number, dec) {
      const safeSymbol = symbol || "";
      const value = this.formatPriceDisplay(number, dec);
      return safeSymbol ? `${safeSymbol} ${value}` : value;
    },

    //------ Print Table Only - Print data with all columns based on table type
    printTableOnly(tableType) {
      let title, rows, columns;

      if (tableType === 'purchases') {
        title = `${this.$t("Reports")} / ${this.$t("SuppliersReport")} / ${this.$t("Purchases")}`;
        rows = Array.isArray(this.purchases) ? this.purchases : [];
        columns = this.columns_purchases;
      } else if (tableType === 'returns') {
        title = `${this.$t("Reports")} / ${this.$t("SuppliersReport")} / ${this.$t("Returns")}`;
        rows = Array.isArray(this.returns_supplier) ? this.returns_supplier : [];
        columns = this.columns_returns;
      } else if (tableType === 'payments') {
        title = `${this.$t("Reports")} / ${this.$t("SuppliersReport")} / ${this.$t("PurchaseInvoice")}`;
        rows = Array.isArray(this.payments) ? this.payments : [];
        columns = this.columns_payments;
      } else {
        return;
      }

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
          if (col.key === 'statut') {
            if (tableType === 'purchases') {
              if (row.statut === 'received') cellValue = this.$t('Received');
              else if (row.statut === 'pending') cellValue = this.$t('Pending');
              else cellValue = this.$t('Ordered');
            } else if (tableType === 'returns') {
              cellValue = row.statut === 'completed' ? this.$t('complete') : this.$t('Pending');
            } else {
              cellValue = row.statut || '';
            }
          } else if (col.key === 'payment_status') {
            if (row.payment_status === 'paid') cellValue = this.$t('Paid');
            else if (row.payment_status === 'partial') cellValue = this.$t('partial');
            else cellValue = this.$t('Unpaid');
          } else if (['GrandTotal', 'paid_amount', 'due', 'montant'].includes(col.key)) {
            cellValue = this.formatPriceDisplay(row[col.key] || 0, 2);
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

    //------------------------------ Show Reports -------------------------\\
    Get_Reports() {
      let id = this.$route.params.id;
      axios
        .get(`report/provider/${id}`)
        .then(response => {
          this.provider = response.data.report;
          this.isLoading = false;
        })
        .catch(response => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    //--------------------------- Get Purchases By Provider -------------\\
    Get_Purchases(page) {
      this.purchases_page = page;
      axios
        .get(
          "report/provider_purchases?page=" + page +
            "&limit=" + this.limit_purchases +
            "&search=" + this.search_purchases +
            "&id=" + this.$route.params.id
        )
        .then(response => {
          this.purchases = (response.data.purchases || []).map((r, i) => Object.assign({ __rowkey: r.id != null ? `pu-${r.id}` : `r-${i}` }, r));
          this.totalRows_purchases = response.data.totalRows;
          this.isLoading = false;
        })
        .catch(response => {
          this.isLoading = false;
        });
    },

    //--------------------------- Get Payments By Provider -------------\\
    Get_Payments(page) {
      this.Payment_page = page;
      axios
        .get(
          "/report/provider_payments?page=" + page +
            "&limit=" + this.limit_payments +
            "&search=" + this.search_payments +
            "&id=" + this.$route.params.id
        )
        .then(response => {
          this.payments = (response.data.payments || []).map((r, i) => Object.assign({ __rowkey: `p-${i}` }, r));
          this.totalRows_payments = response.data.totalRows;
        })
        .catch(response => {});
    },

    //--------------------------- Get Returns By Provider -------------\\
    Get_Returns(page) {
      this.Return_page = page;
      axios
        .get(
          // NOTE: legacy query preserved verbatim — this endpoint reads `limit` from limit_payments.
          "/report/provider_returns?page=" + page +
            "&limit=" + this.limit_payments +
            "&search=" + this.search_return_purchases +
            "&id=" + this.$route.params.id
        )
        .then(response => {
          this.returns_supplier = (response.data.returns_supplier || []).map((r, i) => Object.assign({ __rowkey: r.id != null ? `rt-${r.id}` : `r-${i}` }, r));
          this.totalRows_returns = response.data.totalRows;
        })
        .catch(response => {});
    }
  }, //end Methods

  //----------------------------- Created function-------------------

  created: function() {
    this.Get_Reports();
    this.Get_Purchases(1);
    this.Get_Payments(1);
    this.Get_Returns(1);
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrl__pad { padding: var(--pxn-space-6) 0; }
.pxrl__stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-4); margin-top: var(--pxn-space-5); }
@media (max-width: 900px) { .pxrl__stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 520px) { .pxrl__stats { grid-template-columns: minmax(0, 1fr); } }
.pxrl__tabbar { display: flex; gap: var(--pxn-space-2); margin-top: var(--pxn-space-6); border-bottom: 1px solid var(--pxn-border); }
.pxrl__tab {
  appearance: none; background: none; border: 0; border-bottom: 2px solid transparent;
  padding: var(--pxn-space-3) var(--pxn-space-4); font: inherit; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink-3); cursor: pointer; transition: color 120ms, border-color 120ms;
}
.pxrl__tab:hover { color: var(--pxn-ink); }
.pxrl__tab.is-active { color: var(--pxn-ink); border-bottom-color: var(--pxn-primary); font-weight: var(--pxn-fw-semibold); }
.pxrl__panel { margin-top: var(--pxn-space-5); }
.pxrl__tablewrap { margin-top: var(--pxn-space-4); }
.pxrl__link { color: var(--pxn-primary); }
</style>
