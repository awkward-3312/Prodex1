<template>
  <div class="px-next pxrl">
    <px-page-header
      :title="$t('User_report')"
      :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('Users_Report'), href: '#/app/reports/users_report' }, { label: $t('User_report') }]"
    />

    <div v-if="isLoading" class="pxrl__pad">
      <px-skeleton variant="table" :rows="10" :columns="9" />
    </div>

    <template v-else>
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
          <template #actions><px-menu :items="pdfPrintMenu" align="end" @select="k => onExport('sales', k)">
            <template #trigger><px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button></template>
          </px-menu></template>
        </px-toolbar>
        <div class="pxrl__tablewrap">
          <px-table v-if="sales.length" :columns="columns_sales" :rows="sales" row-key="__rowkey">
            <template #cell-Ref="{ row }">
              <router-link v-if="row.sale_id" :to="'/app/sales/detail/' + row.sale_id" class="pxrl__link">{{ row.Ref }}</router-link>
              <span v-else>{{ row.Ref }}</span>
            </template>
            <template #cell-GrandTotal="{ row }"><span class="pxn-num">{{ money(row.GrandTotal) }}</span></template>
            <template #cell-paid_amount="{ row }"><span class="pxn-num">{{ money(row.paid_amount) }}</span></template>
            <template #cell-due="{ row }"><span class="pxn-num">{{ money(row.due) }}</span></template>
            <template #cell-statut="{ row }"><px-badge :tone="saleStatutTone(row.statut)">{{ saleStatutLabel(row.statut) }}</px-badge></template>
            <template #cell-payment_status="{ row }"><px-badge :tone="payTone(row.payment_status)">{{ payLabel(row.payment_status) }}</px-badge></template>
            <template #cell-shipping_status="{ row }">
              <px-badge v-if="row.shipping_status" :tone="shipTone(row.shipping_status)">{{ shipLabel(row.shipping_status) }}</px-badge>
              <span v-else>—</span>
            </template>
          </px-table>
          <px-empty-state v-else icon="receipt" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
        </div>
        <div v-if="sales.length" class="pxrl__totalrow">
          <span>{{ $t('Total') }}</span>
          <span>{{ $t('Total') }}: <b class="pxn-num">{{ money(stripSum(sales, 'GrandTotal')) }}</b></span>
          <span>{{ $t('Paid') }}: <b class="pxn-num">{{ money(stripSum(sales, 'paid_amount')) }}</b></span>
          <span>{{ $t('Due') }}: <b class="pxn-num">{{ money(stripSum(sales, 'due')) }}</b></span>
        </div>
        <px-pagination v-if="sales.length" :page="Number(sales_page)" :per-page="Number(limit_sales)" :total="Number(totalRows_sales) || 0"
          @update:page="p => onPage('sales', p)" @update:perPage="v => onLimit('sales', v)" />
      </div>

      <!-- Quotations -->
      <div v-show="activeTab === 'quotations'" class="pxrl__panel">
        <px-toolbar :search="search_quotations" :search-placeholder="$t('Search_this_table')" @update:search="v => onSearch('quotations', v)">
          <template #actions><px-menu :items="pdfPrintMenu" align="end" @select="k => onExport('quotations', k)">
            <template #trigger><px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button></template>
          </px-menu></template>
        </px-toolbar>
        <div class="pxrl__tablewrap">
          <px-table v-if="quotations.length" :columns="columns_quotations" :rows="quotations" row-key="__rowkey">
            <template #cell-Ref="{ row }">
              <router-link v-if="row.id" :to="'/app/quotations/detail/' + row.id" class="pxrl__link">{{ row.Ref }}</router-link>
              <span v-else>{{ row.Ref }}</span>
            </template>
            <template #cell-GrandTotal="{ row }"><span class="pxn-num">{{ money(row.GrandTotal) }}</span></template>
            <template #cell-statut="{ row }"><px-badge :tone="row.statut === 'sent' ? 'success' : 'info'">{{ row.statut === 'sent' ? $t('Sent') : $t('Pending') }}</px-badge></template>
          </px-table>
          <px-empty-state v-else icon="file-text" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
        </div>
        <div v-if="quotations.length" class="pxrl__totalrow">
          <span>{{ $t('Total') }}</span>
          <span>{{ $t('Total') }}: <b class="pxn-num">{{ money(stripSum(quotations, 'GrandTotal')) }}</b></span>
        </div>
        <px-pagination v-if="quotations.length" :page="Number(quotations_page)" :per-page="Number(limit_quotations)" :total="Number(totalRows_quotations) || 0"
          @update:page="p => onPage('quotations', p)" @update:perPage="v => onLimit('quotations', v)" />
      </div>

      <!-- Purchases -->
      <div v-show="activeTab === 'purchases'" class="pxrl__panel">
        <px-toolbar :search="search_purchases" :search-placeholder="$t('Search_this_table')" @update:search="v => onSearch('purchases', v)">
          <template #actions><px-menu :items="pdfPrintMenu" align="end" @select="k => onExport('purchases', k)">
            <template #trigger><px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button></template>
          </px-menu></template>
        </px-toolbar>
        <div class="pxrl__tablewrap">
          <px-table v-if="purchases.length" :columns="columns_purchases" :rows="purchases" row-key="__rowkey">
            <template #cell-Ref="{ row }">
              <router-link v-if="row.purchase_id" :to="'/app/purchases/detail/' + row.purchase_id" class="pxrl__link">{{ row.Ref }}</router-link>
              <span v-else>{{ row.Ref }}</span>
            </template>
            <template #cell-GrandTotal="{ row }"><span class="pxn-num">{{ money(row.GrandTotal) }}</span></template>
            <template #cell-paid_amount="{ row }"><span class="pxn-num">{{ money(row.paid_amount) }}</span></template>
            <template #cell-due="{ row }"><span class="pxn-num">{{ money(row.due) }}</span></template>
            <template #cell-statut="{ row }"><px-badge :tone="purchaseStatutTone(row.statut)">{{ purchaseStatutLabel(row.statut) }}</px-badge></template>
            <template #cell-payment_status="{ row }"><px-badge :tone="payTone(row.payment_status)">{{ payLabel(row.payment_status) }}</px-badge></template>
          </px-table>
          <px-empty-state v-else icon="shopping-cart" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
        </div>
        <div v-if="purchases.length" class="pxrl__totalrow">
          <span>{{ $t('Total') }}</span>
          <span>{{ $t('Total') }}: <b class="pxn-num">{{ money(stripSum(purchases, 'GrandTotal')) }}</b></span>
          <span>{{ $t('Paid') }}: <b class="pxn-num">{{ money(stripSum(purchases, 'paid_amount')) }}</b></span>
          <span>{{ $t('Due') }}: <b class="pxn-num">{{ money(stripSum(purchases, 'due')) }}</b></span>
        </div>
        <px-pagination v-if="purchases.length" :page="Number(purchases_page)" :per-page="Number(limit_purchases)" :total="Number(totalRows_purchases) || 0"
          @update:page="p => onPage('purchases', p)" @update:perPage="v => onLimit('purchases', v)" />
      </div>

      <!-- Sales Return -->
      <div v-show="activeTab === 'sales_return'" class="pxrl__panel">
        <px-toolbar :search="search_return_sales" :search-placeholder="$t('Search_this_table')" @update:search="v => onSearch('sales_return', v)">
          <template #actions><px-menu :items="pdfPrintMenu" align="end" @select="k => onExport('sales_return', k)">
            <template #trigger><px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button></template>
          </px-menu></template>
        </px-toolbar>
        <div class="pxrl__tablewrap">
          <px-table v-if="sales_return.length" :columns="columns_sales_return" :rows="sales_return" row-key="__rowkey">
            <template #cell-Ref="{ row }">
              <router-link v-if="row.return_sale_id" :to="'/app/sale_return/detail/' + row.return_sale_id" class="pxrl__link">{{ row.Ref }}</router-link>
              <span v-else>{{ row.Ref }}</span>
            </template>
            <template #cell-GrandTotal="{ row }"><span class="pxn-num">{{ money(row.GrandTotal) }}</span></template>
            <template #cell-paid_amount="{ row }"><span class="pxn-num">{{ money(row.paid_amount) }}</span></template>
            <template #cell-due="{ row }"><span class="pxn-num">{{ money(row.due) }}</span></template>
            <template #cell-statut="{ row }"><px-badge :tone="row.statut === 'received' ? 'success' : 'info'">{{ row.statut === 'received' ? $t('Received') : $t('Pending') }}</px-badge></template>
            <template #cell-payment_status="{ row }"><px-badge :tone="payTone(row.payment_status)">{{ payLabel(row.payment_status) }}</px-badge></template>
          </px-table>
          <px-empty-state v-else icon="corner-up-left" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
        </div>
        <div v-if="sales_return.length" class="pxrl__totalrow">
          <span>{{ $t('Total') }}</span>
          <span>{{ $t('Total') }}: <b class="pxn-num">{{ money(stripSum(sales_return, 'GrandTotal')) }}</b></span>
          <span>{{ $t('Paid') }}: <b class="pxn-num">{{ money(stripSum(sales_return, 'paid_amount')) }}</b></span>
          <span>{{ $t('Due') }}: <b class="pxn-num">{{ money(stripSum(sales_return, 'due')) }}</b></span>
        </div>
        <px-pagination v-if="sales_return.length" :page="Number(Return_sale_page)" :per-page="Number(limit_sales_return)" :total="Number(totalRows_sales_return) || 0"
          @update:page="p => onPage('sales_return', p)" @update:perPage="v => onLimit('sales_return', v)" />
      </div>

      <!-- Purchases Return -->
      <div v-show="activeTab === 'purchases_return'" class="pxrl__panel">
        <px-toolbar :search="search_return_purchases" :search-placeholder="$t('Search_this_table')" @update:search="v => onSearch('purchases_return', v)">
          <template #actions><px-menu :items="pdfPrintMenu" align="end" @select="k => onExport('purchases_return', k)">
            <template #trigger><px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button></template>
          </px-menu></template>
        </px-toolbar>
        <div class="pxrl__tablewrap">
          <px-table v-if="purchases_return.length" :columns="columns_purchase_return" :rows="purchases_return" row-key="__rowkey">
            <template #cell-Ref="{ row }">
              <router-link v-if="row.return_purchase_id" :to="'/app/purchase_return/detail/' + row.return_purchase_id" class="pxrl__link">{{ row.Ref }}</router-link>
              <span v-else>{{ row.Ref }}</span>
            </template>
            <template #cell-GrandTotal="{ row }"><span class="pxn-num">{{ money(row.GrandTotal) }}</span></template>
            <template #cell-paid_amount="{ row }"><span class="pxn-num">{{ money(row.paid_amount) }}</span></template>
            <template #cell-due="{ row }"><span class="pxn-num">{{ money(row.due) }}</span></template>
            <template #cell-statut="{ row }"><px-badge :tone="row.statut === 'received' ? 'success' : 'info'">{{ row.statut === 'received' ? $t('Received') : $t('Pending') }}</px-badge></template>
            <template #cell-payment_status="{ row }"><px-badge :tone="payTone(row.payment_status)">{{ payLabel(row.payment_status) }}</px-badge></template>
          </px-table>
          <px-empty-state v-else icon="corner-up-left" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
        </div>
        <div v-if="purchases_return.length" class="pxrl__totalrow">
          <span>{{ $t('Total') }}</span>
          <span>{{ $t('Total') }}: <b class="pxn-num">{{ money(stripSum(purchases_return, 'GrandTotal')) }}</b></span>
          <span>{{ $t('Paid') }}: <b class="pxn-num">{{ money(stripSum(purchases_return, 'paid_amount')) }}</b></span>
          <span>{{ $t('Due') }}: <b class="pxn-num">{{ money(stripSum(purchases_return, 'due')) }}</b></span>
        </div>
        <px-pagination v-if="purchases_return.length" :page="Number(Return_purchase_page)" :per-page="Number(limit_purchases_return)" :total="Number(totalRows_purchases_return) || 0"
          @update:page="p => onPage('purchases_return', p)" @update:perPage="v => onLimit('purchases_return', v)" />
      </div>

      <!-- Transfers -->
      <div v-show="activeTab === 'transfers'" class="pxrl__panel">
        <px-toolbar :search="search_transfers" :search-placeholder="$t('Search_this_table')" @update:search="v => onSearch('transfers', v)">
          <template #actions><px-menu :items="pdfPrintMenu" align="end" @select="k => onExport('transfers', k)">
            <template #trigger><px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button></template>
          </px-menu></template>
        </px-toolbar>
        <div class="pxrl__tablewrap">
          <px-table v-if="transfers.length" :columns="columns_transfers" :rows="transfers" row-key="__rowkey">
            <template #cell-items="{ row }"><span class="pxn-num">{{ row.items }}</span></template>
            <template #cell-GrandTotal="{ row }"><span class="pxn-num">{{ money(row.GrandTotal) }}</span></template>
            <template #cell-statut="{ row }"><px-badge :tone="transferStatutTone(row.statut)">{{ transferStatutLabel(row.statut) }}</px-badge></template>
          </px-table>
          <px-empty-state v-else icon="arrow-left-right" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
        </div>
        <div v-if="transfers.length" class="pxrl__totalrow">
          <span>{{ $t('Total') }}</span>
          <span>{{ $t('Items') }}: <b class="pxn-num">{{ stripSumNum(transfers, 'items') }}</b></span>
          <span>{{ $t('Total') }}: <b class="pxn-num">{{ money(stripSum(transfers, 'GrandTotal')) }}</b></span>
        </div>
        <px-pagination v-if="transfers.length" :page="Number(transfers_page)" :per-page="Number(limit_transfers)" :total="Number(totalRows_transfers) || 0"
          @update:page="p => onPage('transfers', p)" @update:perPage="v => onLimit('transfers', v)" />
      </div>

      <!-- Adjustments -->
      <div v-show="activeTab === 'adjustments'" class="pxrl__panel">
        <px-toolbar :search="search_adjustments" :search-placeholder="$t('Search_this_table')" @update:search="v => onSearch('adjustments', v)">
          <template #actions><px-menu :items="pdfPrintMenu" align="end" @select="k => onExport('adjustments', k)">
            <template #trigger><px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button></template>
          </px-menu></template>
        </px-toolbar>
        <div class="pxrl__tablewrap">
          <px-table v-if="adjustments.length" :columns="columns_adjustments" :rows="adjustments" row-key="__rowkey">
            <template #cell-items="{ row }"><span class="pxn-num">{{ row.items }}</span></template>
          </px-table>
          <px-empty-state v-else icon="sliders-horizontal" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
        </div>
        <div v-if="adjustments.length" class="pxrl__totalrow">
          <span>{{ $t('Total') }}</span>
          <span>{{ $t('TotalProducts') }}: <b class="pxn-num">{{ stripSumNum(adjustments, 'items') }}</b></span>
        </div>
        <px-pagination v-if="adjustments.length" :page="Number(adjustments_page)" :per-page="Number(limit_adjustments)" :total="Number(totalRows_adjustments) || 0"
          @update:page="p => onPage('adjustments', p)" @update:perPage="v => onLimit('adjustments', v)" />
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
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  metaInfo: {
    title: "User Report Detail"
  },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxBadge, PxEmptyState
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
    tabs() {
      return [
        { key: "sales", label: this.$t("Sales") },
        { key: "quotations", label: this.$t("Quotations") },
        { key: "purchases", label: this.$t("Purchases") },
        { key: "sales_return", label: this.$t("SalesReturn") },
        { key: "purchases_return", label: this.$t("PurchasesReturn") },
        { key: "transfers", label: this.$t("StockTransfers") },
        { key: "adjustments", label: this.$t("Adjustment") }
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
        { key: "username", label: this.$t("username"), sortable: false },
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
        { key: "username", label: this.$t("username"), sortable: false },
        { key: "Ref", label: this.$t("Reference"), strong: true, sortable: false },
        { key: "client_name", label: this.$t("Customer"), sortable: false },
        { key: "warehouse_name", label: this.$t("warehouse") },
        { key: "statut", label: this.$t("Status"), sortable: false },
        { key: "GrandTotal", label: this.$t("Total"), align: "right", sortable: false },
        { key: "paid_amount", label: this.$t("Paid"), align: "right", sortable: false },
        { key: "due", label: this.$t("Due"), align: "right", sortable: false },
        { key: "payment_status", label: this.$t("PaymentStatus"), sortable: false },
        { key: "shipping_status", label: this.$t("Shipping_status") }
      ];
    },
    columns_sales_return() {
      return [
        { key: "username", label: this.$t("username"), sortable: false },
        { key: "Ref", label: this.$t("Reference"), strong: true, sortable: false },
        { key: "client_name", label: this.$t("Customer"), sortable: false },
        { key: "warehouse_name", label: this.$t("warehouse") },
        { key: "statut", label: this.$t("Status"), sortable: false },
        { key: "GrandTotal", label: this.$t("Total"), align: "right", sortable: false },
        { key: "paid_amount", label: this.$t("Paid"), align: "right", sortable: false },
        { key: "due", label: this.$t("Due"), align: "right", sortable: false },
        { key: "payment_status", label: this.$t("PaymentStatus"), sortable: false }
      ];
    },
    columns_purchases() {
      return [
        { key: "username", label: this.$t("username"), sortable: false },
        { key: "Ref", label: this.$t("Reference"), strong: true, sortable: false },
        { key: "provider_name", label: this.$t("Supplier"), sortable: false },
        { key: "warehouse_name", label: this.$t("warehouse") },
        { key: "statut", label: this.$t("Status"), sortable: false },
        { key: "GrandTotal", label: this.$t("Total"), align: "right", sortable: false },
        { key: "paid_amount", label: this.$t("Paid"), align: "right", sortable: false },
        { key: "due", label: this.$t("Due"), align: "right", sortable: false },
        { key: "payment_status", label: this.$t("PaymentStatus"), sortable: false }
      ];
    },
    columns_purchase_return() {
      return [
        { key: "username", label: this.$t("username"), sortable: false },
        { key: "Ref", label: this.$t("Reference"), strong: true, sortable: false },
        { key: "provider_name", label: this.$t("Supplier"), sortable: false },
        { key: "warehouse_name", label: this.$t("warehouse") },
        { key: "statut", label: this.$t("Status"), sortable: false },
        { key: "GrandTotal", label: this.$t("Total"), align: "right", sortable: false },
        { key: "paid_amount", label: this.$t("Paid"), align: "right", sortable: false },
        { key: "due", label: this.$t("Due"), align: "right", sortable: false },
        { key: "payment_status", label: this.$t("PaymentStatus"), sortable: false }
      ];
    },
    columns_transfers() {
      return [
        { key: "username", label: this.$t("username"), sortable: false },
        { key: "date", label: this.$t("date") },
        { key: "Ref", label: this.$t("Reference") },
        { key: "from_warehouse", label: this.$t("FromWarehouse") },
        { key: "to_warehouse", label: this.$t("ToWarehouse") },
        { key: "items", label: this.$t("Items"), align: "right" },
        { key: "GrandTotal", label: this.$t("Total"), align: "right" },
        { key: "statut", label: this.$t("Status") }
      ];
    },
    columns_adjustments() {
      return [
        { key: "username", label: this.$t("username"), sortable: false },
        { key: "date", label: this.$t("date") },
        { key: "Ref", label: this.$t("Reference") },
        { key: "warehouse_name", label: this.$t("warehouse") },
        { key: "items", label: this.$t("TotalProducts"), align: "right" }
      ];
    }
  },

  methods: {
    saleStatutTone(s) { return s === 'completed' ? 'success' : s === 'pending' ? 'info' : 'warning'; },
    saleStatutLabel(s) { return s === 'completed' ? this.$t('complete') : s === 'pending' ? this.$t('Pending') : this.$t('Ordered'); },
    purchaseStatutTone(s) { return s === 'received' ? 'success' : s === 'pending' ? 'info' : 'warning'; },
    purchaseStatutLabel(s) { return s === 'received' ? this.$t('Received') : s === 'pending' ? this.$t('Pending') : this.$t('Ordered'); },
    transferStatutTone(s) { return s === 'completed' ? 'success' : s === 'sent' ? 'warning' : 'danger'; },
    transferStatutLabel(s) { return s === 'completed' ? this.$t('complete') : s === 'sent' ? this.$t('Sent') : this.$t('Pending'); },
    payTone(s) { return s === 'paid' ? 'success' : s === 'partial' ? 'info' : 'warning'; },
    payLabel(s) { return s === 'paid' ? this.$t('Paid') : s === 'partial' ? this.$t('partial') : this.$t('Unpaid'); },
    shipTone(s) {
      return s === 'delivered' ? 'success' : s === 'cancelled' ? 'danger' : s === 'packed' ? 'info' : s === 'shipped' ? 'neutral' : 'warning';
    },
    shipLabel(s) {
      const map = { ordered: 'Ordered', packed: 'Packed', shipped: 'Shipped', delivered: 'Delivered', cancelled: 'Cancelled' };
      return map[s] ? this.$t(map[s]) : (s || '');
    },

    // Totals-strip helpers — same reduce semantics as the legacy vgt group footers
    stripSum(arr, field) {
      return (arr || []).reduce((acc, r) => {
        const v = Number(r[field]) || 0;
        return Number.isFinite(v) ? acc + v : acc;
      }, 0);
    },
    stripSumNum(arr, field) {
      return this.stripSum(arr, field).toLocaleString();
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
    Sales_PDF() {
      this._pdf("Sale List", [
        { header: this.$t("username"), dataKey: "username" },
        { header: this.$t("Reference"), dataKey: "Ref" },
        { header: this.$t("Customer"), dataKey: "client_name" },
        { header: this.$t("warehouse"), dataKey: "warehouse_name" },
        { header: this.$t("Status"), dataKey: "statut" },
        { header: this.$t("Total"), dataKey: "GrandTotal" },
        { header: this.$t("Paid"), dataKey: "paid_amount" },
        { header: this.$t("Due"), dataKey: "due" },
        { header: this.$t("PaymentStatus"), dataKey: "payment_status" },
        { header: this.$t("Shipping_status"), dataKey: "shipping_status" }
      ], this.sales, "Sale_List.pdf");
    },
    Quotation_PDF() {
      this._pdf("Quotation List", [
        { header: this.$t("username"), dataKey: "username" },
        { header: this.$t("date"), dataKey: "date" },
        { header: this.$t("Reference"), dataKey: "Ref" },
        { header: this.$t("Customer"), dataKey: "client_name" },
        { header: this.$t("warehouse"), dataKey: "warehouse_name" },
        { header: this.$t("Status"), dataKey: "statut" },
        { header: this.$t("Total"), dataKey: "GrandTotal" }
      ], this.quotations, "Quotation_List.pdf");
    },
    Purchase_PDF() {
      this._pdf("Purchase List", [
        { header: this.$t("username"), dataKey: "username" },
        { header: this.$t("Reference"), dataKey: "Ref" },
        { header: this.$t("Supplier"), dataKey: "provider_name" },
        { header: this.$t("warehouse"), dataKey: "warehouse_name" },
        { header: this.$t("Total"), dataKey: "GrandTotal" },
        { header: this.$t("Paid"), dataKey: "paid_amount" },
        { header: this.$t("Due"), dataKey: "due" },
        { header: this.$t("Status"), dataKey: "statut" },
        { header: this.$t("PaymentStatus"), dataKey: "payment_status" }
      ], this.purchases, "Purchase_List.pdf");
    },
    Sale_Return_PDF() {
      this._pdf("Sales Return List", [
        { header: this.$t("username"), dataKey: "username" },
        { header: this.$t("Reference"), dataKey: "Ref" },
        { header: this.$t("Customer"), dataKey: "client_name" },
        { header: this.$t("warehouse"), dataKey: "warehouse_name" },
        { header: this.$t("Total"), dataKey: "GrandTotal" },
        { header: this.$t("Paid"), dataKey: "paid_amount" },
        { header: this.$t("Due"), dataKey: "due" },
        { header: this.$t("Status"), dataKey: "statut" },
        { header: this.$t("PaymentStatus"), dataKey: "payment_status" }
      ], this.sales_return, "Sales Return.pdf");
    },
    Returns_Purchase_PDF() {
      this._pdf("Purchase Return List", [
        { header: this.$t("username"), dataKey: "username" },
        { header: this.$t("Reference"), dataKey: "Ref" },
        { header: this.$t("Supplier"), dataKey: "provider_name" },
        { header: this.$t("warehouse"), dataKey: "warehouse_name" },
        { header: this.$t("Total"), dataKey: "GrandTotal" },
        { header: this.$t("Paid"), dataKey: "paid_amount" },
        { header: this.$t("Due"), dataKey: "due" },
        { header: this.$t("Status"), dataKey: "statut" },
        { header: this.$t("PaymentStatus"), dataKey: "payment_status" }
      ], this.purchases_return, "purchase_returns.pdf");
    },
    Transfer_PDF() {
      this._pdf("Transfer List", [
        { header: this.$t("username"), dataKey: "username" },
        { header: this.$t("Reference"), dataKey: "Ref" },
        { header: this.$t("FromWarehouse"), dataKey: "from_warehouse" },
        { header: this.$t("ToWarehouse"), dataKey: "to_warehouse" },
        { header: this.$t("TotalProducts"), dataKey: "items" },
        { header: this.$t("Status"), dataKey: "statut" }
      ], this.transfers, "Transfer_List.pdf");
    },
    Adjustment_PDF() {
      this._pdf("Adjustment List", [
        { header: this.$t("username"), dataKey: "username" },
        { header: this.$t("date"), dataKey: "date" },
        { header: this.$t("Reference"), dataKey: "Ref" },
        { header: this.$t("warehouse"), dataKey: "warehouse_name" },
        { header: this.$t("TotalProducts"), dataKey: "items" }
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
        sales: [this.$t("Sales"), this.sales, this.columns_sales],
        quotations: [this.$t("Quotations"), this.quotations, this.columns_quotations],
        purchases: [this.$t("Purchases"), this.purchases, this.columns_purchases],
        sales_return: [this.$t("SalesReturn"), this.sales_return, this.columns_sales_return],
        purchases_return: [this.$t("PurchasesReturn"), this.purchases_return, this.columns_purchase_return],
        transfers: [this.$t("StockTransfers"), this.transfers, this.columns_transfers],
        adjustments: [this.$t("Adjustment"), this.adjustments, this.columns_adjustments]
      }[tableType];
      if (!cfg) return;
      const title = `${this.$t("Reports")} / ${this.$t("User_report")} / ${cfg[0]}`;
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
          if (col.key === 'statut') {
            if (tableType === 'sales') {
              if (row.statut === 'completed') cellValue = this.$t('complete');
              else if (row.statut === 'pending') cellValue = this.$t('Pending');
              else cellValue = this.$t('Ordered');
            } else if (tableType === 'quotations') {
              cellValue = row.statut === 'sent' ? this.$t('Sent') : this.$t('Pending');
            } else if (tableType === 'purchases') {
              if (row.statut === 'received') cellValue = this.$t('Received');
              else if (row.statut === 'pending') cellValue = this.$t('Pending');
              else cellValue = this.$t('Ordered');
            } else if (tableType === 'sales_return' || tableType === 'purchases_return') {
              cellValue = row.statut === 'received' ? this.$t('Received') : this.$t('Pending');
            } else if (tableType === 'transfers') {
              if (row.statut === 'completed') cellValue = this.$t('complete');
              else if (row.statut === 'sent') cellValue = this.$t('Sent');
              else cellValue = this.$t('Pending');
            } else {
              cellValue = row.statut || '';
            }
          } else if (col.key === 'payment_status') {
            if (row.payment_status === 'paid') cellValue = this.$t('Paid');
            else if (row.payment_status === 'partial') cellValue = this.$t('partial');
            else cellValue = this.$t('Unpaid');
          } else if (col.key === 'shipping_status') {
            cellValue = this.shipLabel(row.shipping_status);
          } else if (['GrandTotal', 'paid_amount', 'due'].includes(col.key)) {
            cellValue = this.formatPriceWithSymbol(this.currentUser && this.currentUser.currency, row[col.key] || 0, 2);
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

    //--------------------------- get_sales_by_user -------------\\
    Get_Sales(page) {
      this.sales_page = page;
      axios
        .get("/report/get_sales_by_user?page=" + page + "&limit=" + this.limit_sales + "&search=" + this.search_sales + "&id=" + this.$route.params.id)
        .then(response => {
          this.sales = (response.data.sales || []).map((r, i) => Object.assign({ __rowkey: r.sale_id != null ? `s-${r.sale_id}-${i}` : `r-${i}` }, r));
          this.totalRows_sales = response.data.totalRows;
        })
        .catch(response => {});
    },

    //--------------------------- Get Purchases By user -------------\\
    Get_Purchases(page) {
      this.purchases_page = page;
      axios
        .get("report/get_purchases_by_user?page=" + page + "&limit=" + this.limit_purchases + "&search=" + this.search_purchases + "&id=" + this.$route.params.id)
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

    //--------------------------- Get Quotations By user -------------\\
    Get_Quotations(page) {
      this.quotations_page = page;
      axios
        .get("report/get_quotations_by_user?page=" + page + "&limit=" + this.limit_quotations + "&search=" + this.search_quotations + "&id=" + this.$route.params.id)
        .then(response => {
          this.quotations = (response.data.quotations || []).map((r, i) => Object.assign({ __rowkey: r.id != null ? `q-${r.id}` : `r-${i}` }, r));
          this.totalRows_quotations = response.data.totalRows;
        })
        .catch(response => {});
    },

    //--------------------------- Get Transfers By user -------------\\
    Get_Transfers(page) {
      this.transfers_page = page;
      axios
        .get("report/get_transfer_by_user?page=" + page + "&limit=" + this.limit_transfers + "&search=" + this.search_transfers + "&id=" + this.$route.params.id)
        .then(response => {
          this.transfers = (response.data.transfers || []).map((r, i) => Object.assign({ __rowkey: `t-${i}` }, r));
          this.totalRows_transfers = response.data.totalRows;
        })
        .catch(response => {});
    },

    //--------------------------- Get adjustment By user -------------\\
    Get_adjustments(page) {
      this.adjustments_page = page;
      axios
        .get("report/get_adjustment_by_user?page=" + page + "&limit=" + this.limit_adjustments + "&search=" + this.search_adjustments + "&id=" + this.$route.params.id)
        .then(response => {
          this.adjustments = (response.data.adjustments || []).map((r, i) => Object.assign({ __rowkey: `a-${i}` }, r));
          this.totalRows_adjustments = response.data.totalRows;
        })
        .catch(response => {});
    },

    //--------------------------- Get sales Returns By user -------------\\
    Get_Sales_Return(page) {
      this.Return_sale_page = page;
      axios
        .get("/report/get_sales_return_by_user?page=" + page + "&limit=" + this.limit_sales_return + "&search=" + this.search_return_sales + "&id=" + this.$route.params.id)
        .then(response => {
          this.sales_return = (response.data.sales_return || []).map((r, i) => Object.assign({ __rowkey: r.return_sale_id != null ? `sr-${r.return_sale_id}-${i}` : `r-${i}` }, r));
          this.totalRows_sales_return = response.data.totalRows;
        })
        .catch(response => {});
    },

    //--------------------------- Get purchases Returns By user -------------\\
    Get_Purchases_Return(page) {
      this.Return_purchase_page = page;
      axios
        .get("/report/get_purchase_return_by_user?page=" + page + "&limit=" + this.limit_purchases_return + "&search=" + this.search_return_purchases + "&id=" + this.$route.params.id)
        .then(response => {
          this.purchases_return = (response.data.purchases_return || []).map((r, i) => Object.assign({ __rowkey: r.return_purchase_id != null ? `pr-${r.return_purchase_id}-${i}` : `r-${i}` }, r));
          this.totalRows_purchases_return = response.data.totalRows;
        })
        .catch(response => {});
    }
  }, //end Methods

  //----------------------------- Created function------------------- \\

  created: function() {
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
.pxrl__tabbar { display: flex; gap: var(--pxn-space-2); margin-top: var(--pxn-space-5); border-bottom: 1px solid var(--pxn-border); overflow-x: auto; }
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
.pxrl__totalrow { display: flex; align-items: center; justify-content: flex-end; gap: var(--pxn-space-6); margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); flex-wrap: wrap; }
.pxrl__totalrow > span:first-child { margin-right: auto; font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
</style>
