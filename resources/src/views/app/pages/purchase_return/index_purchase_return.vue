<template>
  <div class="px-next pxprl">
    <px-page-header :title="$t('PurchasesReturn')" :breadcrumbs="[{ label: $t('Purchases') }, { label: $t('ListReturns') }, { label: $t('PurchasesReturn') }]">
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

    <div v-if="filtersOpen" class="pxprl__filters">
      <div class="pxprl__filters-grid">
        <px-field :label="$t('date')"><template #default="{ id }"><px-input :id="id" type="date" v-model="Filter_date" /></template></px-field>
        <px-field :label="$t('Reference')"><template #default="{ id }"><px-input :id="id" v-model="Filter_Ref" :placeholder="$t('Reference')" /></template></px-field>
        <px-field :label="$t('Purchase')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_purchase" :reduce="o => o.value" :placeholder="$t('Choose_Purchase_Ref')"
              :options="purchases.map(p => ({ label: p.Ref, value: p.id }))" />
          </template>
        </px-field>
        <px-field :label="$t('Supplier')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_Supplier" :reduce="o => o.value" :placeholder="$t('Choose_Supplier')"
              :options="suppliers.map(s => ({ label: s.name, value: s.id }))" />
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
              :options="[{ label: 'completed', value: 'completed' }, { label: 'Pending', value: 'pending' }]" />
          </template>
        </px-field>
        <px-field :label="$t('PaymentStatus')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_Payment" :reduce="o => o.value" :placeholder="$t('Choose_Status')"
              :options="[{ label: 'Paid', value: 'paid' }, { label: 'partial', value: 'partial' }, { label: 'UnPaid', value: 'unpaid' }]" />
          </template>
        </px-field>
      </div>
      <div class="pxprl__filters-act">
        <px-button size="sm" variant="primary" icon="filter" @click="applyFilters">{{ $t('Filter') }}</px-button>
        <px-button size="sm" variant="ghost" icon="x" @click="Reset_Filter">{{ $t('Reset') }}</px-button>
      </div>
    </div>

    <div v-if="isLoading" class="pxprl__pad">
      <px-skeleton variant="table" :rows="10" :columns="6" />
    </div>

    <template v-else>
      <transition name="pxprl-bulk">
        <div v-if="selectedIds.length" class="pxprl__bulk">
          <span><b class="pxn-num">{{ selectedIds.length }}</b> {{ $t('selected') }}</span>
          <div class="pxprl__bulk-act">
            <px-button v-if="currentUserPermissions.includes('Purchase_Returns_delete')" size="sm" variant="danger" icon="trash-2" @click="delete_by_selected">{{ $t('Del') }}</px-button>
            <px-button size="sm" variant="ghost" @click="selectedIds = []">{{ $t('Cancel') }}</px-button>
          </div>
        </div>
      </transition>

      <div class="pxprl__tablewrap">
        <px-table
          v-if="purchase_returns.length"
          :columns="columns"
          :rows="purchase_returns"
          row-key="id"
          selectable
          :selected.sync="selectedIds"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-date="{ row }">{{ formatDisplayDate(row.date) }}</template>
          <template #cell-Ref="{ row }">
            <router-link class="pxprl__link" :to="'/app/purchase_return/detail/' + row.id">{{ row.Ref }}</router-link>
          </template>
          <template #cell-purchase_ref="{ row }">
            <router-link v-if="row.purchase_id" class="pxprl__link" :to="'/app/purchases/detail/' + row.purchase_id">{{ row.purchase_ref }}</router-link>
            <span v-else class="pxprl__muted">—</span>
          </template>
          <template #cell-statut="{ row }">
            <px-badge :tone="row.statut === 'completed' ? 'success' : 'info'">
              {{ row.statut === 'completed' ? $t('complete') : $t('Pending') }}
            </px-badge>
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
          icon="corner-up-right"
          :title="$t('No_purchase_returns_yet') || 'Sin devoluciones todavía'"
          :description="$t('No_purchase_returns_desc') || 'Cuando registres una devolución a proveedor, aparecerá en esta lista.'"
        />
      </div>

      <px-pagination
        v-if="purchase_returns.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <!-- Show payments -->
    <px-modal v-model="showPaymentOpen" :title="$t('ShowPayment')" size="lg">
      <div class="pxprl-tbl__wrap pxn-scroll">
        <table class="pxprl-tbl">
          <thead>
            <tr>
              <th>{{ $t('date') }}</th><th>{{ $t('Reference') }}</th><th class="is-right">{{ $t('Amount') }}</th>
              <th>{{ $t('PayeBy') }}</th><th class="is-right">{{ $t('Action') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="factures.length <= 0"><td colspan="5" class="pxprl__empty">{{ $t('NodataAvailable') }}</td></tr>
            <tr v-for="facture in factures" :key="facture.id">
              <td>{{ facture.date }}</td>
              <td class="pxn-mono">{{ facture.Ref }}</td>
              <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber((facture.montant), 2) }}</td>
              <td>{{ facture.payment_method ? facture.payment_method.name : '---' }}</td>
              <td class="is-right">
                <div class="pxprl__rowbtns">
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
        <span class="pxprl__grow" />
        <px-button variant="secondary" @click="close">Cerrar</px-button>
      </template>
    </px-modal>

    <!-- Add / edit payment -->
    <validation-observer ref="Add_payment">
      <px-modal v-model="addPaymentOpen" :title="EditPaiementMode ? $t('EditPayment') : $t('AddPayment')" size="lg">
        <b-form @submit.prevent="Submit_Payment">
          <div class="pxprl__grid3">
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
                <template #default="{ id }">
                  <px-input :id="id" v-model.number="facture.received_amount" :placeholder="$t('Received_Amount')"
                    @input="v.validate($event); Verified_Received_Amount(facture.received_amount)" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider ref="pAmtProvider" name="Amount" :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="v">
              <px-field :label="$t('Paying_Amount')" required :error="v.errors[0]">
                <template #default="{ id }">
                  <px-input :id="id" v-model.number="facture.montant" :placeholder="$t('Paying_Amount')"
                    @input="v.validate($event); Verified_paidAmount(facture.montant)" />
                </template>
              </px-field>
            </validation-provider>

            <px-field :label="$t('Change')">
              <template #default>
                <p class="pxprl__change pxn-num">{{ parseFloat(facture.received_amount - facture.montant).toFixed(priceDecimals) }}</p>
              </template>
            </px-field>

            <px-field :label="$t('Account')" class="pxprl__span2">
              <template #default="{ id }">
                <vs-px :input-id="id" v-model="facture.account_id" :reduce="o => o.value" :placeholder="$t('Choose_Account')"
                  :options="accounts.map(a => ({ label: a.account_name, value: a.id }))" />
              </template>
            </px-field>

            <px-field :label="$t('Note')" class="pxprl__span2">
              <template #default="{ id }"><px-textarea :id="id" v-model="facture.notes" :rows="3" /></template>
            </px-field>
          </div>

          <div class="pxprl__actionbar">
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
import Util from "../../../../utils";
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
  metaInfo: {
    title: "Return Purchase"
  },

  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxKebab,
    PxBadge, PxField, PxInput, PxTextarea, PxModal, PxEmptyState, "vs-px": VsPx
  },

  data() {
    return {
      _searchTimer: null,
      paymentProcessing: false,
      isLoading: true,
      filtersOpen: false,
      addPaymentOpen: false,
      showPaymentOpen: false,
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
      totalRows: "",
      submitStatus: null,
      EditPaiementMode: false,
      Filter_Supplier: "",
      Filter_purchase:"",
      Filter_status: "",
      Filter_Payment: "",
      Filter_Ref: "",
      Filter_date: "",
      Filter_warehouse: "",
      purchase_returns: [],
      accounts: [],
      payment_methods:[],
      purchases: [],
      purchase_return: {},
      suppliers: [],
      warehouses: [],
      purchase_return_id: "",
      factures: [],
      purchase_return_due:'',
      due:0,
      limit: "10",
      facture: {
        id: "",
        purchase_return_id: "",
        date: "",
        Ref: "",
        montant: "",
        received_amount: "",
        payment_method_id: "",
        notes: ""
      },
      // Optional price format key for frontend display (loaded from system settings/localStorage)
      price_format_key: null
    };
  },

  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    activeFilterCount() {
      return [this.Filter_date, this.Filter_Ref, this.Filter_purchase, this.Filter_Supplier, this.Filter_warehouse, this.Filter_status, this.Filter_Payment]
        .filter(v => v !== "" && v !== null && v !== undefined).length;
    },
    exportMenu() {
      return [
        { key: "pdf", label: "PDF", icon: "file-text" },
        { key: "xlsx", label: "CSV / Excel", icon: "file-spreadsheet" }
      ];
    },
    columns() {
      return [
        { key: "date", label: this.$t("date"), sortable: true },
        { key: "Ref", label: this.$t("Reference"), sortable: true, strong: true },
        { key: "provider_name", label: this.$t("Supplier"), sortable: true },
        { key: "warehouse_name", label: this.$t("warehouse"), sortable: true },
        { key: "purchase_ref", label: this.$t("Purchase_Ref"), sortable: true },
        { key: "statut", label: this.$t("Status"), sortable: true },
        { key: "GrandTotal", label: this.$t("Total"), align: "right", sortable: true },
        { key: "paid_amount", label: this.$t("Paid"), align: "right", sortable: true },
        { key: "due", label: this.$t("Due"), align: "right" },
        { key: "payment_status", label: this.$t("PaymentStatus"), sortable: true }
      ];
    }
  },

  methods: {

    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_purchase_returns(1); }, 350);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_purchase_returns(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_purchase_returns(1); } },

    onSort({ key, dir }) {
      let field = key;
      if (key === "provider_name") field = "provider_id";
      else if (key === "warehouse_name") field = "warehouse_id";
      this.updateParams({ sort: { type: dir, field: field } });
      this.Get_purchase_returns(this.serverParams.page);
    },

    applyFilters() { this.updateParams({ page: 1 }); this.Get_purchase_returns(this.serverParams.page); },

    onExport(item) {
      const k = item && item.key;
      if (k === "pdf") this.Returns_Purchase_PDF();
      else if (k === "xlsx") this.exportCsv();
    },

    exportCsv() {
      const head = [this.$t("date"), this.$t("Reference"), this.$t("Supplier"), this.$t("warehouse"), this.$t("Purchase_Ref"), this.$t("Status"), this.$t("Total"), this.$t("Paid"), this.$t("Due"), this.$t("PaymentStatus")];
      const lines = [head.join(",")].concat(
        (this.purchase_returns || []).map(r =>
          [r.date, r.Ref, r.provider_name, r.warehouse_name, r.purchase_ref, r.statut, r.GrandTotal, r.paid_amount, r.due, r.payment_status]
            .map(c => `"${String(c == null ? "" : c).replace(/"/g, '""')}"`).join(",")
        )
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "Purchase_Returns.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    rowActions(row) {
      const p = this.currentUserPermissions || [];
      const items = [{ key: "view", label: this.$t("ReturnDetail"), icon: "eye" }];
      if (p.includes("Purchase_Returns_edit")) items.push({ key: "edit", label: this.$t("EditReturn"), icon: "pencil" });
      if (p.includes("payment_returns_view")) items.push({ key: "showpay", label: this.$t("ShowPayment"), icon: "wallet" });
      if (p.includes("payment_returns_add")) items.push({ key: "addpay", label: this.$t("AddPayment"), icon: "plus" });
      items.push({ key: "pdf", label: this.$t("DownloadPdf"), icon: "file-text" });
      if (p.includes("Purchase_Returns_delete")) items.push({ key: "delete", label: this.$t("DeleteReturn"), icon: "x", tone: "danger" });
      return items;
    },

    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "view") this.$router.push("/app/purchase_return/detail/" + row.id);
      else if (k === "edit") this.$router.push("/app/purchase_return/edit/" + row.id + "/" + row.purchase_id);
      else if (k === "showpay") this.Show_Payments(row.id, row);
      else if (k === "addpay") this.New_Payment(row);
      else if (k === "pdf") this.Return_PDF(row, row.id);
      else if (k === "delete") this.Remove_Return(row.id);
    },

    syncPaymentValidators() {
      this.$nextTick(() => {
        if (this.$refs.pDateProvider) this.$refs.pDateProvider.syncValue(this.facture.date);
        if (this.$refs.pMethodProvider) this.$refs.pMethodProvider.syncValue(this.facture.payment_method_id);
        if (this.$refs.pRecvProvider) this.$refs.pRecvProvider.syncValue(this.facture.received_amount);
        if (this.$refs.pAmtProvider) this.$refs.pAmtProvider.syncValue(this.facture.montant);
      });
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
      this.Filter_Supplier = "";
      this.Filter_purchase = "";
      this.Filter_status = "";
      this.Filter_Payment = "";
      this.Filter_Ref = "";
      this.Filter_date = "";
      this.Filter_warehouse = "",
      this.Get_purchase_returns(this.serverParams.page);
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
        // Money formatter: always honour the configured price precision (2 or 3).
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

    formatDisplayDate(value) {
      if (!value) return '';
      const dateFormat = this.$store.getters.getDateFormat || Util.getDateFormat(this.$store);
      return Util.formatDisplayDate(value, dateFormat);
    },

    //-----------------------------  Return purchase pdf------------------------------\\
    Return_PDF(purchase_return, id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);

      axios
        .get("return_purchase_pdf/" + id, {
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
            "purchase_return_" + purchase_return.Ref + ".pdf"
          );
          document.body.appendChild(link);
          link.click();
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(() => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
        });
    },

    //------------------------ Payment Return Purchase Pdf ------------------------------\\
    Payment_Return_PDF(facture, id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);

       axios
        .get("payment_return_purchase_pdf/" + id, {
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
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(() => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
        });
    },

    //----------------------------------------- Returns Purchase PDF -----------------------\\
    Returns_Purchase_PDF() {
      var self = this;
      let pdf = new jsPDF("p", "pt");

      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      try { pdf.addFont(fontPath,'Vazirmatn','normal'); pdf.addFont(fontPath,'Vazirmatn','bold'); } catch(e){}
      pdf.setFont('Vazirmatn','normal');

      const headers = [
        self.$t("Reference"),
        self.$t("Supplier"),
        self.$t("warehouse"),
        self.$t("Purchase_Ref"),
        self.$t("Status"),
        self.$t("Total"),
        self.$t("Paid"),
        self.$t("Due"),
        self.$t("PaymentStatus")
      ];

      const body = (self.purchase_returns || []).map(purchase_return => ([
        purchase_return.Ref,
        purchase_return.provider_name,
        purchase_return.warehouse_name,
        purchase_return.purchase_ref,
        purchase_return.statut,
        purchase_return.GrandTotal,
        purchase_return.paid_amount,
        purchase_return.due,
        purchase_return.payment_status
      ]));

      // Calculate totals
      let totalGrandTotal = self.purchase_returns.reduce((sum, purchase_return) => sum + parseFloat(purchase_return.GrandTotal || 0), 0);
      let totalPaidAmount = self.purchase_returns.reduce((sum, purchase_return) => sum + parseFloat(purchase_return.paid_amount || 0), 0);
      let totalDue = self.purchase_returns.reduce((sum, purchase_return) => sum + parseFloat(purchase_return.due || 0), 0);

      const footer = [[
        self.$t("Total"),
        '',
        '',
        '',
        '',
        totalGrandTotal.toFixed(this.priceDecimals),
        totalPaidAmount.toFixed(this.priceDecimals),
        totalDue.toFixed(this.priceDecimals),
        ''
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
        headStyles: { font: 'Vazirmatn', fontStyle: 'bold', fillColor: [63,81,181], textColor: 255 },
        alternateRowStyles: { fillColor: [245,247,250] },
        footStyles: { font: 'Vazirmatn', fontStyle: 'bold', fillColor: [63,81,181], textColor: 255 },
        didDrawPage: (d) => {
          const pageW = pdf.internal.pageSize.getWidth();
          const pageH = pdf.internal.pageSize.getHeight();

          // Header banner
          pdf.setFillColor(63,81,181);
          pdf.rect(0, 0, pageW, 60, 'F');

          // Title
          pdf.setTextColor(255);
          pdf.setFont('Vazirmatn', 'bold');
          pdf.setFontSize(16);
          const title = self.$t('PurchaseReturnsList') || 'Purchase Returns List';
          rtl ? pdf.text(title, pageW - marginX, 38, { align: 'right' })
              : pdf.text(title, marginX, 38);

          // Reset text color
          pdf.setTextColor(33);

          // Footer page numbers
          pdf.setFontSize(8);
          const pn = `${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`;
          rtl ? pdf.text(pn, marginX, pageH - 14, { align: 'left' })
              : pdf.text(pn, pageW - marginX, pageH - 14, { align: 'right' });
        }
      });

      pdf.save("purchase_returns.pdf");

    },

    Number_Order_Payment() {
      axios
        .get("payment/returns_purchase/Number/Order")
        .then(({ data }) => (this.facture.Ref = data));
    },

    //----------------------------------- Add Payment Return Purchase ------------------------------\\
    New_Payment(purchase_return) {
      if (purchase_return.payment_status == "paid") {
        this.$swal({
          icon: "error",
          title: "Oops...",
          text: this.$t("PaymentComplete")
        });
      } else {
        // Start the progress bar.
        NProgress.start();
        NProgress.set(0.1);
        this.reset_form_payment();
        this.EditPaiementMode = false;
        this.purchase_return = purchase_return;
        this.facture.date = new Date().toISOString().slice(0, 10);
        this.Number_Order_Payment();
        this.facture.montant = purchase_return.due;
        this.facture.payment_method_id = 2;
        this.facture.received_amount = purchase_return.due;
        this.due = parseFloat(this.purchase_return.due);
        setTimeout(() => {
          // Complete the animation of the  progress bar.
          NProgress.done();
          this.addPaymentOpen = true;
          this.syncPaymentValidators();
        }, 500);
      }
    },

    //---- reset form payment

    reset_form_payment() {
      this.due = 0;
      this.facture = {
        id: "",
        purchase_return_id: "",
        account_id: "",
        date: "",
        Ref: "",
        montant: "",
        received_amount: "",
        payment_method_id: "",
        notes: ""
      };
    },

    //------------------------------------Edit Payment ------------------------------\\
    Edit_Payment(facture) {
      // Start the progress bar.
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
      this.facture.montant = facture.montant;
      this.facture.received_amount = parseFloat(facture.montant + facture.change).toFixed(this.priceDecimals);
      this.facture.notes   = facture.notes;

      this.due = parseFloat(this.purchase_return_due) + facture.montant;
      setTimeout(() => {
        // Complete the animation of the  progress bar.
        NProgress.done();
        this.addPaymentOpen = true;
        this.syncPaymentValidators();
      }, 1000);
    },

    //-------------------------------Show All Payment with Return Purchase ---------------------\\
    Show_Payments(id, purchase_return) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      this.reset_form_payment();
      this.purchase_return_id = id;
      this.purchase_return = purchase_return;
      this.Get_Payments(id);
    },

    //----------------------------------------- Get Payments Returns Purchase -------------------------------\\
    Get_Payments(id) {
      axios
        .get("returns/purchase/payment/" + id)
        .then(response => {
          this.factures = response.data.payments;
          this.purchase_return_due = response.data.due;
          setTimeout(() => {
            // Complete the animation of the  progress bar.
            NProgress.done();
            this.showPaymentOpen = true;
          }, 500);
        })
        .catch(() => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
        });
    },



    //---------------------------------------- Set To Strings-------------------------\\
    setToStrings() {
      // Simply replaces null values with strings=''
      if (this.Filter_Supplier === null) {
        this.Filter_Supplier = "";
      } else if (this.Filter_warehouse === null) {
        this.Filter_warehouse = "";
      } else if (this.Filter_status === null) {
        this.Filter_status = "";
      } else if (this.Filter_Payment === null) {
        this.Filter_Payment = "";
      } else if (this.Filter_purchase === null) {
        this.Filter_purchase = "";
      }
    },

    //--------------------- Get All Returns Purchase ------------------------\\
    Get_purchase_returns(page) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      this.setToStrings();
      axios
        .get(
          "returns/purchase?page=" +
            page +
            "&Ref=" +
            this.Filter_Ref +
            "&date=" +
            this.Filter_date +
             "&purchase_id=" +
            this.Filter_purchase +
            "&provider_id=" +
            this.Filter_Supplier +
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
          this.purchase_returns = response.data.purchase_returns;
          this.suppliers = response.data.suppliers;
          this.purchases = response.data.purchases;
          this.warehouses = response.data.warehouses;
          this.accounts = response.data.accounts;
          this.totalRows = response.data.totalRows;
          this.payment_methods =  response.data.payment_methods;

          // Complete the animation of theprogress bar.
          NProgress.done();
          this.isLoading = false;
        })
        .catch(response => {
          // Complete the animation of theprogress bar.
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    //---------------------  Remove Return ------------------------\\
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
          // Start the progress bar.
          NProgress.start();
          NProgress.set(0.1);
          axios
            .delete("returns/purchase/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              Fire.$emit("Delete_purchase_return");
            })
            .catch(() => {
              // Complete the animation of the  progress bar.
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

    //---- Delete purchase return by selection

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
          // Start the progress bar.
          NProgress.start();
          NProgress.set(0.1);
          axios
            .post("returns/purchase/delete/by_selection", {
              selectedIds: this.selectedIds
            })
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );

              Fire.$emit("Delete_purchase_return");
            })
            .catch(() => {
              // Complete the animation of theprogress bar.
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

    //----------------------------------Create Payment Return ------------------------------\\
    Create_Payment() {
     this.paymentProcessing = true;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("payment/returns_purchase", {
          purchase_return_id: this.purchase_return.id,
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
          Fire.$emit("Create_payment_purchase_return");

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

    //---------------------------------------- Update Payment Return ------------------------------\\
    Update_Payment() {
      this.paymentProcessing = true;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .put("payment/returns_purchase/" + this.facture.id, {
          purchase_return_id: this.purchase_return.id,
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
          Fire.$emit("Update_payment_purchase_return");

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

    //----------------------------------------- Delete Payment Return ------------------------------\\
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
          // Start the progress bar.
          NProgress.start();
          NProgress.set(0.1);
          axios
            .delete("payment/returns_purchase/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully")
              );
              Fire.$emit("Delete_payment_purchase_return");
            })
            .catch(() => {
              // Complete the animation of the  progress bar.
              setTimeout(() => NProgress.done(), 500);
              this.$swal(
                this.$t("Delete_Failed"),
                this.$t("Delete_Therewassomethingwronge"),
                "warning"
              );
            });
        }
      });
    }
  }, //End Methods

  created() {
    this.Get_purchase_returns(1);

    Fire.$on("Create_payment_purchase_return", () => {
      setTimeout(() => {
        this.Get_purchase_returns(this.serverParams.page);
        // Complete the animation of the  progress bar.
        NProgress.done();
        this.addPaymentOpen = false;
      }, 800);
    });

    Fire.$on("Update_payment_purchase_return", () => {
      setTimeout(() => {
        this.Get_purchase_returns(this.serverParams.page);
        NProgress.done();
        this.addPaymentOpen = false;
        this.showPaymentOpen = false;
      }, 800);
    });

    Fire.$on("Delete_payment_purchase_return", () => {
      setTimeout(() => {
        this.Get_purchase_returns(this.serverParams.page);
        NProgress.done();
        this.showPaymentOpen = false;
      }, 800);
    });

    Fire.$on("Delete_purchase_return", () => {
      setTimeout(() => {
        this.Get_purchase_returns(this.serverParams.page);
        // Complete the animation of the  progress bar.
        NProgress.done();
      }, 800);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxprl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxprl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxprl__pad { padding: var(--pxn-space-6) 0; }
.pxprl__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxprl__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxprl__filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxprl__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxprl__filters-act { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); }
.pxprl__bulk { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-5); margin-top: var(--pxn-space-4); padding: var(--pxn-space-4) var(--pxn-space-5); background: var(--pxn-primary-soft); border: 1px solid var(--pxn-primary-border); border-radius: var(--pxn-radius-md); font-size: var(--pxn-fs-sm); color: var(--pxn-primary-ink); }
.pxprl__bulk-act { display: flex; gap: var(--pxn-space-3); }
.pxprl-bulk-enter-active, .pxprl-bulk-leave-active { transition: opacity var(--pxn-dur-2) var(--pxn-ease), transform var(--pxn-dur-2) var(--pxn-ease); }
.pxprl-bulk-enter, .pxprl-bulk-leave-to { opacity: 0; transform: translateY(-6px); }
.pxprl__tablewrap { margin-top: var(--pxn-space-5); }
.pxprl__link { color: var(--pxn-primary); text-decoration: none; }
.pxprl__link:hover { text-decoration: underline; }
.pxprl__muted { color: var(--pxn-ink-3); }
.pxprl-tbl__wrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); overflow-x: auto; }
.pxprl-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxprl-tbl th { padding: var(--pxn-space-3) var(--pxn-space-4); text-align: left; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap; }
.pxprl-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink); }
.pxprl-tbl tr:last-child td { border-bottom: 0; }
.pxprl-tbl .is-right { text-align: right; }
.pxprl__empty { text-align: center; color: var(--pxn-ink-3); }
.pxprl__rowbtns { display: inline-flex; gap: var(--pxn-space-1); }
.pxprl__grid3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 720px) { .pxprl__grid3 { grid-template-columns: minmax(0, 1fr); } }
.pxprl__span2 { grid-column: span 2; }
@media (max-width: 720px) { .pxprl__span2 { grid-column: span 1; } }
.pxprl__change { margin: 0; padding: var(--pxn-space-2) 0; font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxprl__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
.pxprl__grow { flex: 1; }
</style>
