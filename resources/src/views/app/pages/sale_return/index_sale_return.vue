<template>
  <div class="px-next pxsrl">
    <px-page-header :title="$t('SalesReturn')" :breadcrumbs="[{ label: $t('Sales') }, { label: $t('ListReturns') }]">
      <template #actions>
        <px-menu :items="exportMenu" align="end" @select="onExport">
          <template #trigger>
            <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">Exportar</px-button>
          </template>
        </px-menu>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      :filter-count="activeFilterCount"
      @update:search="onSearchInput"
      @open-filters="filtersOpen = !filtersOpen"
    />

    <div v-if="filtersOpen" class="pxsrl__filters">
      <div class="pxsrl__filters-grid">
        <px-field :label="$t('date')"><template #default="{ id }"><px-input :id="id" type="date" v-model="Filter_date" /></template></px-field>
        <px-field :label="$t('Reference')"><template #default="{ id }"><px-input :id="id" v-model="Filter_Ref" :placeholder="$t('Reference')" /></template></px-field>
        <px-field :label="$t('Sale')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_sale" :reduce="o => o.value" :placeholder="$t('Choose_Sale_Ref')"
              :options="sales.map(s => ({ label: s.Ref, value: s.id }))" />
          </template>
        </px-field>
        <px-field :label="$t('Customer')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_Client" :reduce="o => o.value" :placeholder="$t('Choose_Customer')"
              :options="customers.map(c => ({ label: c.name, value: c.id }))" />
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
            <vs-px :input-id="id" v-model="Filter_status" :reduce="o => o.value" :placeholder="$t('Choose_Status')"
              :options="[{ label: 'completed', value: 'completed' }, { label: 'Pending', value: 'pending' }, { label: 'Ordered', value: 'ordered' }]" />
          </template>
        </px-field>
        <px-field :label="$t('PaymentStatus')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_Payment" :reduce="o => o.value" :placeholder="$t('Choose_Status')"
              :options="[{ label: 'Paid', value: 'paid' }, { label: 'partial', value: 'partial' }, { label: 'UnPaid', value: 'unpaid' }]" />
          </template>
        </px-field>
      </div>
      <div class="pxsrl__filters-act">
        <px-button size="sm" variant="primary" icon="filter" @click="applyFilters">{{ $t('Filter') }}</px-button>
        <px-button size="sm" variant="ghost" icon="x" @click="Reset_Filter">{{ $t('Reset') }}</px-button>
      </div>
    </div>

    <div v-if="isLoading" class="pxsrl__pad">
      <px-skeleton variant="table" :rows="10" :columns="6" />
    </div>

    <template v-else>
      <transition name="pxsrl-bulk">
        <div v-if="selectedIds.length" class="pxsrl__bulk">
          <span><b class="pxn-num">{{ selectedIds.length }}</b> {{ $t('selected') }}</span>
          <div class="pxsrl__bulk-act">
            <px-button v-if="currentUserPermissions.includes('Sale_Returns_delete')" size="sm" variant="danger" icon="trash-2" @click="delete_by_selected">{{ $t('Del') }}</px-button>
            <px-button size="sm" variant="ghost" @click="selectedIds = []">{{ $t('Cancel') }}</px-button>
          </div>
        </div>
      </transition>

      <div class="pxsrl__tablewrap">
        <px-table
          v-if="sales_return.length"
          :columns="columns"
          :rows="sales_return"
          row-key="id"
          selectable
          :selected.sync="selectedIds"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-Ref="{ row }">
            <router-link class="pxsrl__link" :to="'/app/sale_return/detail/' + row.id">{{ row.Ref }}</router-link>
          </template>
          <template #cell-sale_ref="{ row }">
            <router-link v-if="row.sale_id" class="pxsrl__link" :to="'/app/sales/detail/' + row.sale_id">{{ row.sale_ref }}</router-link>
            <span v-else>{{ row.sale_ref }}</span>
          </template>
          <template #cell-statut="{ row }">
            <px-badge :tone="row.statut === 'received' ? 'success' : 'info'">{{ row.statut === 'received' ? $t('Received') : $t('Pending') }}</px-badge>
          </template>
          <template #cell-payment_status="{ row }">
            <px-badge :tone="row.payment_status === 'paid' ? 'success' : (row.payment_status === 'partial' ? 'info' : 'warning')">
              {{ row.payment_status === 'paid' ? $t('Paid') : (row.payment_status === 'partial' ? $t('partial') : $t('Unpaid')) }}
            </px-badge>
          </template>
          <template #cell-GrandTotal="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser.currency, row.GrandTotal, 2) }}</span></template>
          <template #cell-paid_amount="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser.currency, row.paid_amount, 2) }}</span></template>
          <template #cell-due="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser.currency, row.due, 2) }}</span></template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions(row)" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state
          v-else
          icon="undo-2"
          :title="$t('No_sale_returns_yet')"
          :description="$t('No_sale_returns_desc')"
        />
      </div>

      <px-pagination
        v-if="sales_return.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <!-- Show payments -->
    <px-modal v-model="showPaymentOpen" :title="$t('ShowPayment')" size="lg">
      <div class="pxsrl-tbl__wrap pxn-scroll">
        <table class="pxsrl-tbl">
          <thead>
            <tr>
              <th>{{ $t('date') }}</th><th>{{ $t('Reference') }}</th><th class="is-right">{{ $t('Amount') }}</th>
              <th>{{ $t('PayeBy') }}</th><th class="is-right">{{ $t('Action') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="factures.length <= 0"><td colspan="5" class="pxsrl__empty">{{ $t('NodataAvailable') }}</td></tr>
            <tr v-for="facture in factures" :key="facture.id">
              <td>{{ facture.date }}</td>
              <td class="pxn-mono">{{ facture.Ref }}</td>
              <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber(facture.montant, 2) }}</td>
              <td>{{ facture.payment_method ? facture.payment_method.name : '---' }}</td>
              <td class="is-right">
                <div class="pxsrl__rowbtns">
                  <px-button size="sm" variant="ghost" icon-only icon="printer" :title="$t('print')" @click="Payment_Return_PDF(facture, facture.id)" />
                  <px-button v-if="currentUserPermissions.includes('payment_returns_edit')" size="sm" variant="ghost" icon-only icon="pencil" :title="$t('Edit')" @click="Edit_Payment(facture)" />
                  <px-button v-if="currentUserPermissions.includes('payment_returns_delete')" size="sm" variant="ghost" icon-only icon="x" :title="$t('Delete')" @click="Remove_Payment(facture.id)" />
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <template #footer="{ close }">
        <span class="pxsrl__grow" />
        <px-button variant="secondary" @click="close">{{ $t('Close') || 'Cerrar' }}</px-button>
      </template>
    </px-modal>

    <!-- Add / edit payment -->
    <validation-observer ref="Add_payment">
      <px-modal v-model="addPaymentOpen" :title="EditPaiementMode ? $t('EditPayment') : $t('AddPayment')" size="lg">
        <b-form @submit.prevent="Submit_Payment">
          <div class="pxsrl__grid3">
            <validation-provider ref="pDateProvider" name="date" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('date')" required :error="v.errors[0]">
                <template #default="{ id }"><px-input :id="id" type="date" v-model="facture.date" @input="v.validate" /></template>
              </px-field>
            </validation-provider>

            <px-field :label="$t('Reference')">
              <template #default="{ id }"><px-input :id="id" v-model="facture.Ref" disabled /></template>
            </px-field>

            <validation-provider ref="pMethodProvider" name="Payment choice" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('Paymentchoice')" required :error="v.errors[0]">
                <template #default="{ id }">
                  <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="facture.payment_method_id" :reduce="o => o.value"
                    :placeholder="$t('PleaseSelect')" @input="v.validate"
                    :options="payment_methods.map(m => ({ label: m.name, value: m.id }))" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider ref="pRecvProvider" name="Received Amount" :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="v">
              <px-field :label="$t('Received_Amount')" required :error="v.errors[0]">
                <template #default="{ id }"><px-input :id="id" v-model.number="facture.received_amount" @input="v.validate($event); Verified_Received_Amount(facture.received_amount)" :placeholder="$t('Received_Amount')" /></template>
              </px-field>
            </validation-provider>

            <validation-provider ref="pAmtProvider" name="Amount" :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="v">
              <px-field :label="$t('Paying_Amount')" required :error="v.errors[0]">
                <template #default="{ id }"><px-input :id="id" v-model.number="facture.montant" @input="v.validate($event); Verified_paidAmount(facture.montant)" :placeholder="$t('Paying_Amount')" /></template>
              </px-field>
            </validation-provider>

            <px-field :label="$t('Change')">
              <template #default>
                <p class="pxsrl__change pxn-num">{{ parseFloat(facture.received_amount - facture.montant).toFixed(priceDecimals) }}</p>
              </template>
            </px-field>

            <px-field :label="$t('Account')" class="pxsrl__span2">
              <template #default="{ id }">
                <vs-px :input-id="id" v-model="facture.account_id" :reduce="o => o.value" :placeholder="$t('Choose_Account')"
                  :options="accounts.map(a => ({ label: a.account_name, value: a.id }))" />
              </template>
            </px-field>

            <px-field :label="$t('Note')" class="pxsrl__span2">
              <template #default="{ id }"><px-textarea :id="id" v-model="facture.notes" :rows="3" /></template>
            </px-field>
          </div>

          <div class="pxsrl__actionbar">
            <px-button variant="secondary" type="button" @click="addPaymentOpen = false">{{ $t('Cancel') }}</px-button>
            <px-button variant="primary" type="submit" icon="check" :loading="paymentProcessing">{{ $t('submit') }}</px-button>
          </div>
        </b-form>
      </px-modal>
    </validation-observer>
  </div>
</template>

<script>
import { mapActions, mapGetters } from "vuex";
import NProgress from "nprogress";
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
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxKebab, PxBadge,
    PxField, PxInput, PxTextarea, PxModal, PxEmptyState, "vs-px": VsPx
  },
  metaInfo: {
    title: "Devolución de ventas"
  },

  data() {
    return {
      paymentProcessing: false,
      isLoading: true,
      serverParams: {
        sort: {
          field: "id",
          type: "desc"
        },
        page: 1,
        perPage: 10
      },
      selectedIds: [],
      search: "",
      _searchTimer: null,
      totalRows: "",
      submitStatus: null,
      filtersOpen: false,
      showPaymentOpen: false,
      addPaymentOpen: false,
      EditPaiementMode: false,
      Filter_Client: "",
      Filter_sale:"",
      Filter_status: "",
      Filter_Payment: "",
      Filter_Ref: "",
      Filter_date: "",
      Filter_warehouse: "",
      due:0,
      return_sale_due:'',
      sales_return: [],
      payment_methods: [],
      accounts: [],
      sale_return: {},
      customers: [],
      sales:[],
      warehouses: [],
      sale_return_id: "",
      factures: [],
      limit: "10",
      price_format_key: null,
      facture: {
        id: "",
        sale_return_id: "",
        date: "",
        Ref: "",
        montant: "",
        received_amount: "",
        payment_method_id: "",
        notes: ""
      },

    };
  },

  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    columns() {
      return [
        { key: "date", label: this.$t("date"), sortable: true },
        { key: "Ref", label: this.$t("Reference"), sortable: true, strong: true },
        { key: "client_name", label: this.$t("Customer"), sortable: true },
        { key: "warehouse_name", label: this.$t("warehouse"), sortable: true },
        { key: "sale_ref", label: this.$t("Sale_Ref"), sortable: true },
        { key: "statut", label: this.$t("Status"), sortable: true },
        { key: "GrandTotal", label: this.$t("Total"), sortable: true, align: "right" },
        { key: "paid_amount", label: this.$t("Paid"), sortable: true, align: "right" },
        { key: "due", label: this.$t("Due"), sortable: true, align: "right" },
        { key: "payment_status", label: this.$t("PaymentStatus"), sortable: true }
      ];
    },
    activeFilterCount() {
      return [this.Filter_date, this.Filter_Ref, this.Filter_sale, this.Filter_Client, this.Filter_warehouse, this.Filter_status, this.Filter_Payment]
        .filter(v => v !== "" && v != null).length;
    },
    exportMenu() {
      return [
        { key: "pdf", label: "PDF de la lista", icon: "file-text" },
        { key: "xlsx", label: "Excel (CSV)", icon: "file-spreadsheet" }
      ];
    }
  },

  methods: {

    rowActions(row) {
      const p = this.currentUserPermissions || [];
      const items = [{ key: "view", label: this.$t("ReturnDetail"), icon: "eye" }];
      if (p.includes("Sale_Returns_edit")) items.push({ key: "edit", label: this.$t("EditReturn"), icon: "pencil" });
      if (p.includes("payment_returns_view")) items.push({ key: "showpay", label: this.$t("ShowPayment"), icon: "wallet" });
      if (p.includes("payment_returns_add")) items.push({ key: "addpay", label: this.$t("AddPayment"), icon: "plus" });
      items.push({ key: "pdf", label: this.$t("DownloadPdf"), icon: "file-text" });
      if (p.includes("Sale_Returns_delete")) items.push({ key: "delete", label: this.$t("DeleteReturn"), icon: "x", tone: "danger" });
      return items;
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "view") this.$router.push("/app/sale_return/detail/" + row.id);
      else if (k === "edit") this.$router.push("/app/sale_return/edit/" + row.id + "/" + row.sale_id);
      else if (k === "showpay") this.Show_Payments(row.id, row);
      else if (k === "addpay") this.New_Payment(row);
      else if (k === "pdf") this.Return_PDF(row, row.id);
      else if (k === "delete") this.Remove_Return(row.id);
    },
    onExport(item) {
      const k = item && item.key;
      if (k === "pdf") this.Sale_Return_PDF();
      else if (k === "xlsx") this.exportCsv();
    },
    exportCsv() {
      const head = [this.$t("Reference"), this.$t("Customer"), this.$t("warehouse"), this.$t("Sale_Ref"), this.$t("Status"), this.$t("Total"), this.$t("Paid"), this.$t("Due"), this.$t("PaymentStatus")];
      const lines = [head.join(",")].concat(
        (this.sales_return || []).map(r =>
          [r.Ref, r.client_name, r.warehouse_name, r.sale_ref, r.statut, r.GrandTotal, r.paid_amount, r.due, r.payment_status]
            .map(c => `"${String(c == null ? "" : c).replace(/"/g, '""')}"`).join(",")
        )
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "Sales_Return.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    //---- update Params Table
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.GET_Sales_Return(1); }, 350);
    },
    onPage(p) {
      if (this.serverParams.page !== p) {
        this.updateParams({ page: p });
        this.GET_Sales_Return(p);
      }
    },
    onLimit(v) {
      if (this.limit !== String(v)) {
        this.limit = String(v);
        this.updateParams({ page: 1, perPage: Number(v) });
        this.GET_Sales_Return(1);
      }
    },
    onSort({ key, dir }) {
      let field = key;
      if (key === "client_name") field = "client_id";
      else if (key === "warehouse_name") field = "warehouse_id";
      else if (key === "sale_ref") field = "sale_id";
      this.updateParams({ sort: { type: dir, field: field } });
      this.GET_Sales_Return(this.serverParams.page);
    },
    applyFilters() {
      this.updateParams({ page: 1 });
      this.GET_Sales_Return(1);
    },

    //------ Validate Form Submit_Payment
    Submit_Payment() {
      this.$refs.Add_payment.validate().then(success => {
        if (!success) {
          return;
        } else if (this.facture.montant > this.facture.received_amount) {
          this.makeToast(
            "warning",
            this.$t("Paying_amount_is_greater_than_Received_amount"),
            this.$t("Warning")
          );
          this.facture.received_amount = 0;
        }
        else if (this.facture.montant > this.due) {
          this.makeToast(
            "warning",
            this.$t("Paying_amount_is_greater_than_Grand_Total"),
            this.$t("Warning")
          );
          this.facture.montant = 0;

        }else if (!this.EditPaiementMode) {
            this.Create_Payment();
        } else {
            this.Update_Payment();
        }

      });
    },

      //---------- keyup paid Amount

    Verified_paidAmount() {
      if (isNaN(this.facture.montant)) {
        this.facture.montant = 0;
      } else if (this.facture.montant > this.facture.received_amount) {
        this.makeToast(
          "warning",
          this.$t("Paying_amount_is_greater_than_Received_amount"),
          this.$t("Warning")
        );
        this.facture.montant = 0;
      }
      else if (this.facture.montant > this.due) {
        this.makeToast(
          "warning",
          this.$t("Paying_amount_is_greater_than_Grand_Total"),
          this.$t("Warning")
        );
        this.facture.montant = 0;
      }
    },

    //---------- keyup Received Amount

    Verified_Received_Amount() {
      if (isNaN(this.facture.received_amount)) {
        this.facture.received_amount = 0;
      }
    },

    //---Validate State Fields
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    //------ Reset Filter
    Reset_Filter() {
      this.search = "";
      this.Filter_Client = "";
      this.Filter_sale = "";
      this.Filter_status = "";
      this.Filter_Payment = "";
      this.Filter_Ref = "";
      this.Filter_date = "";
      this.Filter_warehouse = "";
      this.GET_Sales_Return(this.serverParams.page);
    },

    //---------------------------------------- Set To Strings-------------------------\\
    setToStrings() {
      // Simply replaces null values with strings=''s
      if (this.Filter_Client === null) {
        this.Filter_Client = "";
      } else if (this.Filter_warehouse === null) {
        this.Filter_warehouse = "";
      } else if (this.Filter_status === null) {
        this.Filter_status = "";
      } else if (this.Filter_Payment === null) {
        this.Filter_Payment = "";
      } else if (this.Filter_sale === null) {
        this.Filter_sale = "";
      }
    },

    //------------------------------Formetted Numbers -------------------------\\
    formatNumber(number, dec) {
      const value = (typeof number === "string"
        ? number
        : number.toString()
      ).split(".");
      if (dec <= 0) return value[0];
      let formated = value[1] || "";
      if (formated.length > dec)
        return `${value[0]}.${formated.substr(0, dec)}`;
      while (formated.length < dec) formated += "0";
      return `${value[0]}.${formated}`;
    },

    // Price formatting for display only (does NOT affect calculations or stored values)
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

    //----------------------------- Invoice Return PDF------------------------------\\
    Return_PDF(sale_return, id) {
      NProgress.start();
      NProgress.set(0.1);

       axios
        .get("return_sale_pdf/" + id, {
          responseType: "blob", // important
          headers: {
            "Content-Type": "application/json"
          }
        })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute(
            "download",
            "Return-Sale-" + sale_return.Ref + ".pdf"
          );
          document.body.appendChild(link);
          link.click();
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(() => {
          setTimeout(() => NProgress.done(), 500);
        });
    },

    //------------------------ Payment Sale Return PDF ------------------------------\\
    Payment_Return_PDF(facture, id) {
      NProgress.start();
      NProgress.set(0.1);

       axios
        .get("payment_return_sale_pdf/" + id, {
          responseType: "blob", // important
          headers: {
            "Content-Type": "application/json"
          }
        })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute("download", "Payment-" + facture.Ref + ".pdf");
          document.body.appendChild(link);
          link.click();
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(() => {
          setTimeout(() => NProgress.done(), 500);
        });
    },

    //----------------------------------------- Sales Return PDF -----------------------\\
    Sale_Return_PDF() {
      const pdf = new jsPDF("p", "pt");
      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      try { pdf.addFont(fontPath, "Vazirmatn", "normal"); pdf.addFont(fontPath, "Vazirmatn", "bold"); } catch(e){}
      pdf.setFont("Vazirmatn", "normal");

      const headers = [ this.$t('Reference'), this.$t('Customer'), this.$t('warehouse'), this.$t('Sale_Ref'), this.$t('Status'), this.$t('Total'), this.$t('Paid'), this.$t('Due'), this.$t('PaymentStatus') ];
      const body = (this.sales_return||[]).map(r => [ r.Ref, r.client_name, r.warehouse_name, r.sale_ref, r.statut, r.GrandTotal, r.paid_amount, r.due, r.payment_status ]);

      const totals = (this.sales_return||[]).reduce((a,r)=>({ t:a.t+parseFloat(r.GrandTotal||0), p:a.p+parseFloat(r.paid_amount||0), d:a.d+parseFloat(r.due||0) }), {t:0,p:0,d:0});
      const foot = [[ this.$t('Total'), '', '', '', '', totals.t.toFixed(this.priceDecimals), totals.p.toFixed(this.priceDecimals), totals.d.toFixed(this.priceDecimals), '' ]];

      const marginX = 40; const rtl = (this.$i18n && ['ar','fa','ur','he'].includes(this.$i18n.locale)) || (typeof document!=='undefined' && document.documentElement.dir==='rtl');

      autoTable(pdf, {
        head:[headers], body, foot:foot,
        startY:110, theme:'striped', margin:{ left:marginX, right:marginX },
        styles:{ font:'Vazirmatn', fontSize:9, cellPadding:4, halign: rtl?'right':'left', textColor:33 },
        headStyles:{ font:'Vazirmatn', fontStyle:'bold', fillColor:[63,81,181], textColor:255 },
        alternateRowStyles:{ fillColor:[245,247,250] },
        columnStyles:{ 5:{halign:'right'}, 6:{halign:'right'}, 7:{halign:'right'} },
        didDrawPage:(d)=>{
          const pageW = pdf.internal.pageSize.getWidth(); const pageH = pdf.internal.pageSize.getHeight();
          pdf.setFillColor(63,81,181); pdf.rect(0,0,pageW,60,'F');
          pdf.setTextColor(255); pdf.setFont('Vazirmatn','bold'); pdf.setFontSize(16);
          const title = this.$t('SalesReturn') || 'Sales Return List';
          rtl ? pdf.text(title, pageW - marginX, 38, { align:'right' }) : pdf.text(title, marginX, 38);
          pdf.setTextColor(33); pdf.setFontSize(8);
          const pn = `${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`;
          rtl ? pdf.text(pn, marginX, pageH - 14, { align: 'left' }) : pdf.text(pn, pageW - marginX, pageH - 14, { align: 'right' });
        }
      });

      pdf.save('Sales_Return_List.pdf');
    },

    Number_Order_Payment() {
      axios
        .get("payment/returns_sale/Number/order")
        .then(({ data }) => (this.facture.Ref = data));
    },

    //----------------------------------- Add Payment Sale Return ------------------------------\\
    New_Payment(sale_return) {
      if (sale_return.payment_status == "paid") {
        this.$swal({
          icon: "error",
          title: "Oops...",
          text: this.$t("PaymentComplete")
        });
      } else {
        NProgress.start();
        NProgress.set(0.1);
        this.reset_form_payment();
        this.EditPaiementMode = false;
        this.sale_return = sale_return;
        this.facture.date = new Date().toISOString().slice(0, 10);
        this.Number_Order_Payment();
        this.facture.montant = parseFloat(sale_return.due);
        this.facture.payment_method_id = 2;
        this.facture.received_amount = parseFloat(sale_return.due);
        this.due = parseFloat(sale_return.due);
        setTimeout(() => {
          NProgress.done();
          this.addPaymentOpen = true;
          this.$nextTick(() => {
            if (this.$refs.pDateProvider) this.$refs.pDateProvider.syncValue(this.facture.date);
            if (this.$refs.pMethodProvider) this.$refs.pMethodProvider.syncValue(this.facture.payment_method_id);
            if (this.$refs.pRecvProvider) this.$refs.pRecvProvider.syncValue(this.facture.received_amount);
            if (this.$refs.pAmtProvider) this.$refs.pAmtProvider.syncValue(this.facture.montant);
          });
        }, 500);
      }
    },

    //------------------------------------Edit Payment ------------------------------\\
    Edit_Payment(facture) {
      NProgress.start();
      NProgress.set(0.1);
      this.reset_form_payment();
      this.EditPaiementMode = true;
      this.facture.id        = facture.id;
      this.facture.Ref       = facture.Ref;
      this.facture.payment_method_id = facture.payment_method_id;
      this.facture.account_id = facture.account_id;
      this.facture.date    = facture.date;
      this.facture.change  = facture.change;
      this.facture.montant = parseFloat(facture.montant);
      this.facture.received_amount = parseFloat(facture.montant + facture.change);
      this.facture.notes   = facture.notes;
      this.due = parseFloat(this.return_sale_due) + facture.montant;
      setTimeout(() => {
        NProgress.done();
        this.addPaymentOpen = true;
        this.$nextTick(() => {
          if (this.$refs.pDateProvider) this.$refs.pDateProvider.syncValue(this.facture.date);
          if (this.$refs.pMethodProvider) this.$refs.pMethodProvider.syncValue(this.facture.payment_method_id);
          if (this.$refs.pRecvProvider) this.$refs.pRecvProvider.syncValue(this.facture.received_amount);
          if (this.$refs.pAmtProvider) this.$refs.pAmtProvider.syncValue(this.facture.montant);
        });
      }, 1000);
    },

    //------------------------------------ reset form payment  ------------------------------\\

    reset_form_payment() {
      this.due = 0;
      this.facture = {
        id: "",
        sale_return_id: "",
        account_id: "",
        date: "",
        Ref: "",
        montant: "",
        received_amount: "",
        payment_method_id: "",
        notes: ""
      };
    },

    //-------------------------------Show All Payment with Sale Return ---------------------\\
    Show_Payments(id, sale_return) {
      NProgress.start();
      NProgress.set(0.1);
      this.reset_form_payment();
      this.sale_return_id = id;
      this.sale_return = sale_return;
      this.Get_Payments(id);
    },

    //----------------------------------------- Get Payments -------------------------------\\
    Get_Payments(id) {
      axios
        .get("returns/sale/payment/" + id)
        .then(response => {
          this.factures = response.data.payments;
          this.return_sale_due = response.data.due;
          setTimeout(() => {
            NProgress.done();
            this.showPaymentOpen = true;
          }, 500);
        })
        .catch(() => {
          setTimeout(() => NProgress.done(), 500);
        });
    },


    //--------------------- Get All Returns ------------------------\\
    GET_Sales_Return(page) {
      NProgress.start();
      NProgress.set(0.1);
      this.setToStrings();
      axios
        .get(
          "returns/sale?page=" +
            page +
            "&Ref=" +
            this.Filter_Ref +
            "&date=" +
            this.Filter_date +
            "&sale_id=" +
            this.Filter_sale +
            "&client_id=" +
            this.Filter_Client +
            "&statut=" +
            this.Filter_status +
            "&warehouse_id=" +
            this.Filter_warehouse +
            "&payment_statut=" +
            this.Filter_Payment +
            "&SortField=" +
            this.serverParams.sort.field +
            "&SortType=" +
            this.serverParams.sort.type +
            "&search=" +
            this.search +
            "&limit=" +
            this.limit
        )
        .then(response => {
          this.sales_return = response.data.sale_Return;
          this.customers = response.data.customers;
          this.sales = response.data.sales;
          this.warehouses = response.data.warehouses;
          this.accounts = response.data.accounts;
          this.payment_methods = response.data.payment_methods;
          this.totalRows = response.data.totalRows;

          NProgress.done();
          this.isLoading = false;
        })
        .catch(response => {
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    //---------------------  Remove Sale Return ------------------------\\
    Remove_Return(id) {
      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "var(--px-primary)",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          NProgress.start();
          NProgress.set(0.1);
          axios
            .delete("returns/sale/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              Fire.$emit("Delete_Return_sale");
            })
            .catch(() => {
              setTimeout(() => NProgress.done(), 500);
              this.$swal(
                this.$t("Delete_Failed"),
                this.$t("Delete_Therewassomethingwronge"),
                "warning"
              );
            });
        }
      });
    },

    //---- Delete Sale Return by selection

    delete_by_selected() {
      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "var(--px-primary)",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          NProgress.start();
          NProgress.set(0.1);
          axios
            .post("returns/sale/delete/by_selection", {
              selectedIds: this.selectedIds
            })
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );

              Fire.$emit("Delete_Return_sale");
            })
            .catch(() => {
              setTimeout(() => NProgress.done(), 500);
              this.$swal(
                this.$t("Delete_Failed"),
                this.$t("Delete_Therewassomethingwronge"),
                "warning"
              );
            });
        }
      });
    },

    //----------------------------------Create Payment Sale Return ------------------------------\\
    Create_Payment() {
      this.paymentProcessing = true;
      NProgress.start();
      NProgress.set(0.1);
        axios
          .post("payment/returns_sale", {
            sale_return_id: this.sale_return.id,
            date: this.facture.date,
            montant: parseFloat(this.facture.montant).toFixed(this.priceDecimals),
            received_amount: parseFloat(this.facture.received_amount).toFixed(this.priceDecimals),
            change: parseFloat(this.facture.received_amount - this.facture.montant).toFixed(this.priceDecimals),
            payment_method_id: this.facture.payment_method_id,
            account_id: this.facture.account_id,
            notes: this.facture.notes
          })
          .then(response => {
            this.paymentProcessing = false;
            Fire.$emit("Create_payment_Return_sale");

            this.makeToast(
              "success",
              this.$t("Successfully_Created"),
              this.$t("Success")
            );
          })
          .catch(error => {
            this.paymentProcessing = false;
            NProgress.done();
          });
    },

    //---------------------------------------- Update Payment Sale Return ------------------------------\\
    Update_Payment() {
      this.paymentProcessing = true;
      NProgress.start();
      NProgress.set(0.1);
        axios
          .put("payment/returns_sale/" + this.facture.id, {
            sale_return_id: this.sale_return.id,
            date: this.facture.date,
            montant: parseFloat(this.facture.montant).toFixed(this.priceDecimals),
            received_amount: parseFloat(this.facture.received_amount).toFixed(this.priceDecimals),
            change: parseFloat(this.facture.received_amount - this.facture.montant).toFixed(this.priceDecimals),
            payment_method_id: this.facture.payment_method_id,
            account_id: this.facture.account_id,
            notes: this.facture.notes
          })
          .then(response => {
            this.paymentProcessing = false;
            Fire.$emit("Update_payment_Return_sale");

            this.makeToast(
              "success",
              this.$t("Successfully_Updated"),
              this.$t("Success")
            );
          })
          .catch(error => {
            this.paymentProcessing = false;
            NProgress.done();
          });
    },


    //----------------------------------------- Remove Payment Return ------------------------------\\
    Remove_Payment(id) {
      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "var(--px-primary)",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          NProgress.start();
          NProgress.set(0.1);
          axios
            .delete("payment/returns_sale/" + id)
            .then(() => {

              this.makeToast(
                "success",
                this.$t("Deleted_in_successfully"),
                this.$t("Delete_Deleted")
              );

              Fire.$emit("Delete_payment_Return_sale");
            })
            .catch(() => {
              setTimeout(() => NProgress.done(), 500);
                this.makeToast(
                "warning",
                this.$t("Delete_Therewassomethingwronge"),
                this.$t("Delete_Failed")
              );

            });
        }
      });
    }
  }, //End Methods

  //---------------------------------- Created Function -----------------------------\\
  created() {
    this.GET_Sales_Return(1);

    Fire.$on("Create_payment_Return_sale", () => {
      setTimeout(() => {
        this.GET_Sales_Return(this.serverParams.page);
        NProgress.done();
      }, 800);
        this.addPaymentOpen = false;
    });

    Fire.$on("Update_payment_Return_sale", () => {
      setTimeout(() => {
        this.GET_Sales_Return(this.serverParams.page);
        NProgress.done();
        this.addPaymentOpen = false;
        this.showPaymentOpen = false;
      }, 800);
    });

    Fire.$on("Delete_payment_Return_sale", () => {
      setTimeout(() => {
        this.GET_Sales_Return(this.serverParams.page);
        NProgress.done();
        this.showPaymentOpen = false;
      }, 800);
    });

    Fire.$on("Delete_Return_sale", () => {
      setTimeout(() => {
        this.GET_Sales_Return(this.serverParams.page);
        NProgress.done();
      }, 800);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxsrl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxsrl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxsrl__pad { padding: var(--pxn-space-6) 0; }

.pxsrl__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxsrl__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxsrl__filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxsrl__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxsrl__filters-act { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); }

.pxsrl__bulk { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-5); margin-top: var(--pxn-space-4); padding: var(--pxn-space-4) var(--pxn-space-5); background: var(--pxn-primary-soft); border: 1px solid var(--pxn-primary-border); border-radius: var(--pxn-radius-md); font-size: var(--pxn-fs-sm); color: var(--pxn-primary-ink); }
.pxsrl__bulk-act { display: flex; gap: var(--pxn-space-3); }
.pxsrl-bulk-enter-active, .pxsrl-bulk-leave-active { transition: opacity var(--pxn-dur-2) var(--pxn-ease), transform var(--pxn-dur-2) var(--pxn-ease); }
.pxsrl-bulk-enter, .pxsrl-bulk-leave-to { opacity: 0; transform: translateY(-6px); }

.pxsrl__tablewrap { margin-top: var(--pxn-space-5); }
.pxsrl__link { color: var(--pxn-primary); text-decoration: none; }
.pxsrl__link:hover { text-decoration: underline; }

.pxsrl-tbl__wrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); overflow-x: auto; }
.pxsrl-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxsrl-tbl th { padding: var(--pxn-space-3) var(--pxn-space-4); text-align: left; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap; }
.pxsrl-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink); }
.pxsrl-tbl tr:last-child td { border-bottom: 0; }
.pxsrl-tbl .is-right { text-align: right; }
.pxsrl__empty { text-align: center; color: var(--pxn-ink-3); }
.pxsrl__rowbtns { display: inline-flex; gap: var(--pxn-space-1); }

.pxsrl__grid3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 720px) { .pxsrl__grid3 { grid-template-columns: minmax(0, 1fr); } }
.pxsrl__span2 { grid-column: span 2; }
@media (max-width: 720px) { .pxsrl__span2 { grid-column: span 1; } }
.pxsrl__change { margin: 0; padding: var(--pxn-space-2) 0; font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxsrl__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
.pxsrl__grow { flex: 1; }
</style>
