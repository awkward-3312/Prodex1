<template>
  <div class="px-next pxrl">
    <px-page-header
      :title="client.name ? `${$t('CustomersReport')} · ${client.name}` : $t('CustomersReport')"
      :breadcrumbs="crumbs"
    />

    <div v-if="isLoading" class="pxrl__pad">
      <px-skeleton variant="table" :rows="10" :columns="8" />
    </div>

    <template v-else>
      <div class="pxrl__stats">
        <px-stat icon="shopping-cart" :label="$t('Sales')" :value="String(client.total_sales || 0)" bordered />
        <px-stat icon="trending-up" :label="$t('TotalAmount')" :value="formatPriceWithSymbol(currentUser.currency, client.total_amount, 2)" bordered />
        <px-stat icon="banknote" :label="$t('TotalPaid')" :value="`${currentUser.currency} ${formatNumber(client.total_paid, priceDecimals)}`" bordered />
        <px-stat icon="wallet" :label="$t('Due')" :value="formatPriceWithSymbol(currentUser.currency, client.due, 2)" bordered />
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

      <!-- Sales -->
      <div v-show="activeTab === 'sales'" class="pxrl__panel">
        <px-toolbar :search="search_sales" :search-placeholder="$t('Search_this_table')" @update:search="v => onSearch('sales', v)">
          <template #actions>
            <px-menu :items="pdfPrintMenu" align="end" @select="k => onExport('sales', k)">
              <template #trigger><px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button></template>
            </px-menu>
          </template>
        </px-toolbar>
        <div class="pxrl__tablewrap">
          <px-table v-if="sales.length" :columns="columns_sales" :rows="sales" row-key="__rowkey">
            <template #cell-Ref="{ row }">
              <router-link v-if="row.id" :to="'/app/sales/detail/' + row.id" class="pxrl__link">{{ row.Ref }}</router-link>
              <span v-else>{{ row.Ref }}</span>
            </template>
            <template #cell-GrandTotal="{ row }"><span class="pxn-num">{{ formatPriceDisplay(row.GrandTotal, 2) }}</span></template>
            <template #cell-paid_amount="{ row }"><span class="pxn-num">{{ formatPriceDisplay(row.paid_amount, 2) }}</span></template>
            <template #cell-due="{ row }"><span class="pxn-num">{{ formatPriceDisplay(row.due, 2) }}</span></template>
            <template #cell-statut="{ row }"><px-badge :tone="saleStatutTone(row.statut)">{{ saleStatutLabel(row.statut) }}</px-badge></template>
            <template #cell-payment_status="{ row }"><px-badge :tone="payTone(row.payment_status)">{{ payLabel(row.payment_status) }}</px-badge></template>
            <template #cell-shipping_status="{ row }">
              <px-badge v-if="row.shipping_status" :tone="shipTone(row.shipping_status)">{{ shipLabel(row.shipping_status) }}</px-badge>
              <span v-else>—</span>
            </template>
          </px-table>
          <px-empty-state v-else icon="receipt" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
        </div>
        <px-pagination v-if="sales.length" :page="Number(sales_page)" :per-page="Number(limit_sales)" :total="Number(totalRows_sales) || 0"
          @update:page="p => onPage('sales', p)" @update:perPage="v => onLimit('sales', v)" />
      </div>

      <!-- Quotations -->
      <div v-show="activeTab === 'quotations'" class="pxrl__panel">
        <px-toolbar :search="search_quotations" :search-placeholder="$t('Search_this_table')" @update:search="v => onSearch('quotations', v)">
          <template #actions>
            <px-menu :items="pdfPrintMenu" align="end" @select="k => onExport('quotations', k)">
              <template #trigger><px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button></template>
            </px-menu>
          </template>
        </px-toolbar>
        <div class="pxrl__tablewrap">
          <px-table v-if="quotations.length" :columns="columns_quotations" :rows="quotations" row-key="__rowkey">
            <template #cell-Ref="{ row }">
              <router-link v-if="row.id" :to="'/app/quotations/detail/' + row.id" class="pxrl__link">{{ row.Ref }}</router-link>
              <span v-else>{{ row.Ref }}</span>
            </template>
            <template #cell-GrandTotal="{ row }"><span class="pxn-num">{{ formatPriceDisplay(row.GrandTotal, 2) }}</span></template>
            <template #cell-statut="{ row }"><px-badge :tone="row.statut === 'sent' ? 'success' : 'info'">{{ row.statut === 'sent' ? $t('Sent') : $t('Pending') }}</px-badge></template>
          </px-table>
          <px-empty-state v-else icon="file-text" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
        </div>
        <px-pagination v-if="quotations.length" :page="Number(quotations_page)" :per-page="Number(limit_quotations)" :total="Number(totalRows_quotations) || 0"
          @update:page="p => onPage('quotations', p)" @update:perPage="v => onLimit('quotations', v)" />
      </div>

      <!-- Returns -->
      <div v-show="activeTab === 'returns'" class="pxrl__panel">
        <px-toolbar :search="search_return_sales" :search-placeholder="$t('Search_this_table')" @update:search="v => onSearch('returns', v)">
          <template #actions>
            <px-menu :items="pdfPrintMenu" align="end" @select="k => onExport('returns', k)">
              <template #trigger><px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button></template>
            </px-menu>
          </template>
        </px-toolbar>
        <div class="pxrl__tablewrap">
          <px-table v-if="returns_customer.length" :columns="columns_returns" :rows="returns_customer" row-key="__rowkey">
            <template #cell-Ref="{ row }">
              <router-link v-if="row.id" :to="'/app/sale_return/detail/' + row.id" class="pxrl__link">{{ row.Ref }}</router-link>
              <span v-else>{{ row.Ref }}</span>
            </template>
            <template #cell-sale_ref="{ row }">
              <router-link v-if="row.sale_id" :to="'/app/sales/detail/' + row.sale_id" class="pxrl__link">{{ row.sale_ref }}</router-link>
              <span v-else>{{ row.sale_ref }}</span>
            </template>
            <template #cell-GrandTotal="{ row }"><span class="pxn-num">{{ formatPriceDisplay(row.GrandTotal, 2) }}</span></template>
            <template #cell-paid_amount="{ row }"><span class="pxn-num">{{ formatPriceDisplay(row.paid_amount, 2) }}</span></template>
            <template #cell-due="{ row }"><span class="pxn-num">{{ formatPriceDisplay(row.due, 2) }}</span></template>
            <template #cell-statut="{ row }"><px-badge :tone="row.statut === 'received' ? 'success' : 'info'">{{ row.statut === 'received' ? $t('Received') : $t('Pending') }}</px-badge></template>
            <template #cell-payment_status="{ row }"><px-badge :tone="payTone(row.payment_status)">{{ payLabel(row.payment_status) }}</px-badge></template>
          </px-table>
          <px-empty-state v-else icon="corner-up-left" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
        </div>
        <px-pagination v-if="returns_customer.length" :page="Number(Return_sale_page)" :per-page="Number(limit_returns)" :total="Number(totalRows_returns) || 0"
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
        <px-pagination v-if="payments.length" :page="Number(Payment_sale_page)" :per-page="Number(limit_payments)" :total="Number(totalRows_payments) || 0"
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
    title: "Customer Report Detail"
  },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxBadge, PxStat, PxEmptyState
  },
  data() {
    return {
      activeTab: "sales",
      totalRows_quotations: "",
      totalRows_sales: "",
      totalRows_returns: "",
      totalRows_payments: "",
      limit_quotations: "10",
      limit_returns: "10",
      limit_sales: "10",
      limit_payments: "10",
      sales_page: 1,
      quotations_page: 1,
      Return_sale_page: 1,
      Payment_sale_page: 1,
      isLoading: true,
      payments: [],
      sales: [],
      quotations: [],
      returns_customer: [],

      search_sales: "",
      search_payments: "",
      search_quotations: "",
      search_return_sales: "",

      client: {
        id: "",
        name: "",
        total_sales: 0,
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
        { label: this.$t('CustomersReport'), href: '#/app/reports/customers_report' }
      ];
      if (this.client.name) c.push({ label: this.client.name });
      return c;
    },
    tabs() {
      return [
        { key: "sales", label: this.$t("Sales") },
        { key: "quotations", label: this.$t("Quotations") },
        { key: "returns", label: this.$t("Returns") },
        { key: "payments", label: this.$t("SalesInvoice") }
      ];
    },
    pdfPrintMenu() {
      return [
        { key: "print", label: this.$t("print"), icon: "printer" },
        { key: "pdf", label: "PDF", icon: "file-text" }
      ];
    },
    columns_quotations() {
      return [
        { key: "date", label: this.$t("date"), sortable: false },
        { key: "Ref", label: this.$t("Reference"), strong: true, sortable: false },
        { key: "client_name", label: this.$t("Customer"), sortable: false },
        { key: "warehouse_name", label: this.$t("warehouse") },
        { key: "GrandTotal", label: this.$t("Total"), align: "right", sortable: false },
        { key: "statut", label: this.$t("Status"), sortable: false }
      ];
    },
    columns_sales() {
      return [
        { key: "Ref", label: this.$t("Reference"), strong: true, sortable: false },
        { key: "client_name", label: this.$t("Customer"), sortable: false },
        { key: "warehouse_name", label: this.$t("warehouse") },
        { key: "GrandTotal", label: this.$t("Total"), align: "right", sortable: false },
        { key: "paid_amount", label: this.$t("Paid"), align: "right", sortable: false },
        { key: "due", label: this.$t("Due"), align: "right", sortable: false },
        { key: "statut", label: this.$t("Status"), sortable: false },
        { key: "payment_status", label: this.$t("PaymentStatus"), sortable: false },
        { key: "shipping_status", label: this.$t("Shipping_status") }
      ];
    },
    columns_returns() {
      return [
        { key: "Ref", label: this.$t("Reference"), strong: true, sortable: false },
        { key: "client_name", label: this.$t("Customer"), sortable: false },
        { key: "sale_ref", label: this.$t("Sale_Ref") },
        { key: "warehouse_name", label: this.$t("warehouse") },
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
        { key: "Sale_Ref", label: this.$t("Sale"), sortable: false },
        { key: "payment_method", label: this.$t("ModePaiement"), sortable: false },
        { key: "montant", label: this.$t("Amount"), align: "right", sortable: false }
      ];
    }
  },

  methods: {
    saleStatutTone(s) { return s === 'completed' ? 'success' : s === 'pending' ? 'info' : 'warning'; },
    saleStatutLabel(s) { return s === 'completed' ? this.$t('complete') : s === 'pending' ? this.$t('Pending') : this.$t('Ordered'); },
    payTone(s) { return s === 'paid' ? 'success' : s === 'partial' ? 'info' : 'warning'; },
    payLabel(s) { return s === 'paid' ? this.$t('Paid') : s === 'partial' ? this.$t('partial') : this.$t('Unpaid'); },
    shipTone(s) {
      return s === 'delivered' ? 'success' : s === 'cancelled' ? 'danger' : s === 'packed' ? 'info' : s === 'shipped' ? 'neutral' : 'warning';
    },
    shipLabel(s) {
      const map = { ordered: 'Ordered', packed: 'Packed', shipped: 'Shipped', delivered: 'Delivered', cancelled: 'Cancelled' };
      return map[s] ? this.$t(map[s]) : (s || '');
    },

    onExport(tab, item) {
      const k = item && item.key ? item.key : item;
      if (k === 'print') { this.printTableOnly(tab); return; }
      if (k === 'pdf') {
        if (tab === 'sales') this.Sales_PDF();
        else if (tab === 'quotations') this.Quotation_PDF();
        else if (tab === 'returns') this.Sale_Return_PDF();
        else if (tab === 'payments') this.Payments_PDF();
      }
    },
    onSearch(tab, v) {
      if (tab === 'sales') { this.search_sales = v; this.Get_Sales(1); }
      else if (tab === 'quotations') { this.search_quotations = v; this.Get_Quotations(1); }
      else if (tab === 'returns') { this.search_return_sales = v; this.Get_Returns(1); }
      else if (tab === 'payments') { this.search_payments = v; this.Get_Payments(1); }
    },
    onPage(tab, p) {
      if (tab === 'sales' && this.sales_page !== p) this.Get_Sales(p);
      else if (tab === 'quotations' && this.quotations_page !== p) this.Get_Quotations(p);
      else if (tab === 'returns' && this.Return_sale_page !== p) this.Get_Returns(p);
      else if (tab === 'payments' && this.Payment_sale_page !== p) this.Get_Payments(p);
    },
    onLimit(tab, v) {
      const s = String(v);
      if (tab === 'sales' && this.limit_sales !== s) { this.limit_sales = s; this.Get_Sales(1); }
      else if (tab === 'quotations' && this.limit_quotations !== s) { this.limit_quotations = s; this.Get_Quotations(1); }
      else if (tab === 'returns' && this.limit_returns !== s) { this.limit_returns = s; this.Get_Returns(1); }
      else if (tab === 'payments' && this.limit_payments !== s) { this.limit_payments = s; this.Get_Payments(1); }
    },

    //----------------------------------- Sales PDF ------------------------------\\
    Sales_PDF() {
      var self = this;
      let pdf = new jsPDF("p", "pt");
      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      pdf.addFont(fontPath, "VazirmatnBold", "bold");
      pdf.setFont("VazirmatnBold");
      let columns = [
        { header: self.$t("Reference"), dataKey: "Ref" },
        { header: self.$t("Customer"), dataKey: "client_name" },
        { header: self.$t("warehouse"), dataKey: "warehouse_name" },
        { header: self.$t("Status"), dataKey: "statut" },
        { header: self.$t("Total"), dataKey: "GrandTotal" },
        { header: self.$t("Paid"), dataKey: "paid_amount" },
        { header: self.$t("Due"), dataKey: "due" },
        { header: self.$t("PaymentStatus"), dataKey: "payment_status" },
        { header: self.$t("Shipping_status"), dataKey: "shipping_status" }
      ];
      autoTable(pdf, {
        columns: columns, body: self.sales, startY: 70, theme: "grid",
        didDrawPage: () => { pdf.setFont("VazirmatnBold"); pdf.setFontSize(18); pdf.text("Sale List", 40, 25); },
        styles: { font: "VazirmatnBold", halign: "center" },
        headStyles: { fillColor: [26, 86, 219], textColor: 255, fontStyle: "bold" },
      });
      pdf.save("Sale_List.pdf");
    },

    //------------------------------------- Quotations PDF -------------------------\\
    Quotation_PDF() {
      var self = this;
      let pdf = new jsPDF("p", "pt");
      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      pdf.addFont(fontPath, "VazirmatnBold", "bold");
      pdf.setFont("VazirmatnBold");
      let columns = [
        { header: self.$t("date"), dataKey: "date" },
        { header: self.$t("Reference"), dataKey: "Ref" },
        { header: self.$t("Customer"), dataKey: "client_name" },
        { header: self.$t("warehouse"), dataKey: "warehouse_name" },
        { header: self.$t("Status"), dataKey: "statut" },
        { header: self.$t("Total"), dataKey: "GrandTotal" }
      ];
      autoTable(pdf, {
        columns: columns, body: self.quotations, startY: 70, theme: "grid",
        didDrawPage: () => { pdf.setFont("VazirmatnBold"); pdf.setFontSize(18); pdf.text("Quotation List", 40, 25); },
        styles: { font: "VazirmatnBold", halign: "center" },
        headStyles: { fillColor: [26, 86, 219], textColor: 255, fontStyle: "bold" },
      });
      pdf.save("Quotation_List.pdf");
    },

    //----------------------------------------- Sales Return PDF -----------------------\\
    Sale_Return_PDF() {
      var self = this;
      let pdf = new jsPDF("p", "pt");
      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      pdf.addFont(fontPath, "VazirmatnBold", "bold");
      pdf.setFont("VazirmatnBold");
      let columns = [
        { header: self.$t("Reference"), dataKey: "Ref" },
        { header: self.$t("Customer"), dataKey: "client_name" },
        { header: self.$t("Sale"), dataKey: "sale_ref" },
        { header: self.$t("warehouse"), dataKey: "warehouse_name" },
        { header: self.$t("Total"), dataKey: "GrandTotal" },
        { header: self.$t("Paid"), dataKey: "paid_amount" },
        { header: self.$t("Due"), dataKey: "due" },
        { header: self.$t("Status"), dataKey: "statut" },
        { header: self.$t("PaymentStatus"), dataKey: "payment_status" }
      ];
      autoTable(pdf, {
        columns: columns, body: self.returns_customer, startY: 70, theme: "grid",
        didDrawPage: () => { pdf.setFont("VazirmatnBold"); pdf.setFontSize(18); pdf.text("Sales Return List", 40, 25); },
        styles: { font: "VazirmatnBold", halign: "center" },
        headStyles: { fillColor: [26, 86, 219], textColor: 255, fontStyle: "bold" },
      });
      pdf.save("Sales Return.pdf");
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
        { header: self.$t("Sale"), dataKey: "Sale_Ref" },
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

      if (tableType === 'sales') {
        title = `${this.$t("Reports")} / ${this.$t("CustomersReport")} / ${this.$t("Sales")}`;
        rows = Array.isArray(this.sales) ? this.sales : [];
        columns = this.columns_sales;
      } else if (tableType === 'quotations') {
        title = `${this.$t("Reports")} / ${this.$t("CustomersReport")} / ${this.$t("Quotations")}`;
        rows = Array.isArray(this.quotations) ? this.quotations : [];
        columns = this.columns_quotations;
      } else if (tableType === 'returns') {
        title = `${this.$t("Reports")} / ${this.$t("CustomersReport")} / ${this.$t("Returns")}`;
        rows = Array.isArray(this.returns_customer) ? this.returns_customer : [];
        columns = this.columns_returns;
      } else if (tableType === 'payments') {
        title = `${this.$t("Reports")} / ${this.$t("CustomersReport")} / ${this.$t("SalesInvoice")}`;
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
            if (tableType === 'sales') {
              if (row.statut === 'completed') cellValue = this.$t('complete');
              else if (row.statut === 'pending') cellValue = this.$t('Pending');
              else cellValue = this.$t('Ordered');
            } else if (tableType === 'quotations') {
              cellValue = row.statut === 'sent' ? this.$t('Sent') : this.$t('Pending');
            } else if (tableType === 'returns') {
              cellValue = row.statut === 'received' ? this.$t('Received') : this.$t('Pending');
            } else {
              cellValue = row.statut || '';
            }
          } else if (col.key === 'payment_status') {
            if (row.payment_status === 'paid') cellValue = this.$t('Paid');
            else if (row.payment_status === 'partial') cellValue = this.$t('partial');
            else cellValue = this.$t('Unpaid');
          } else if (col.key === 'shipping_status') {
            cellValue = this.shipLabel(row.shipping_status);
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
        .get(`report/client/${id}`)
        .then(response => {
          this.client = response.data.report;
        })
        .catch(response => {});
    },

    //--------------------------- Get sales By Customer -------------\\
    Get_Sales(page) {
      this.sales_page = page;
      axios
        .get(
          "/report/client_sales?page=" + page +
            "&limit=" + this.limit_sales +
            "&search=" + this.search_sales +
            "&id=" + this.$route.params.id
        )
        .then(response => {
          this.sales = (response.data.sales || []).map((r, i) => Object.assign({ __rowkey: r.id != null ? `s-${r.id}` : `r-${i}` }, r));
          this.totalRows_sales = response.data.totalRows;
        })
        .catch(response => {});
    },

    //--------------------------- Get Payments By Customer -------------\\
    Get_Payments(page) {
      this.Payment_sale_page = page;
      axios
        .get(
          "report/client_payments?page=" + page +
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

    //--------------------------- Get Quotations By Customer -------------\\
    Get_Quotations(page) {
      this.quotations_page = page;
      axios
        .get(
          "report/client_quotations?page=" + page +
            "&limit=" + this.limit_quotations +
            "&search=" + this.search_quotations +
            "&id=" + this.$route.params.id
        )
        .then(response => {
          this.quotations = (response.data.quotations || []).map((r, i) => Object.assign({ __rowkey: r.id != null ? `q-${r.id}` : `r-${i}` }, r));
          this.totalRows_quotations = response.data.totalRows;
          this.isLoading = false;
        })
        .catch(response => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    //--------------------------- Get Returns By Customer -------------\\
    Get_Returns(page) {
      this.Return_sale_page = page;
      axios
        .get(
          "/report/client_returns?page=" + page +
            "&limit=" + this.limit_returns +
            "&search=" + this.search_return_sales +
            "&id=" + this.$route.params.id
        )
        .then(response => {
          this.returns_customer = (response.data.returns_customer || []).map((r, i) => Object.assign({ __rowkey: r.id != null ? `rt-${r.id}` : `r-${i}` }, r));
          this.totalRows_returns = response.data.totalRows;
        })
        .catch(response => {});
    }
  }, //end Methods

  //----------------------------- Created function------------------- \\

  created: function() {
    this.Get_Reports();
    this.Get_Sales(1);
    this.Get_Payments(1);
    this.Get_Quotations(1);
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
