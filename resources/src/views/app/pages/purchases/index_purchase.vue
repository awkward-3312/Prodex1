<template>
  <div class="px-next pxpl">
    <px-page-header :title="$t('ListPurchases')" :breadcrumbs="[{ label: $t('Purchases') }, { label: $t('ListPurchases') }]">
      <template #actions>
        <px-menu :items="exportMenu" align="end" @select="onExport">
          <template #trigger>
            <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">Exportar</px-button>
          </template>
        </px-menu>
        <px-button
          v-if="currentUserPermissions && currentUserPermissions.includes('Purchases_add')"
          variant="primary" icon="plus" @click="$router.push('/app/purchases/store')"
        >{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      :filter-count="activeFilterCount"
      @update:search="onSearchInput"
      @open-filters="filtersOpen = !filtersOpen"
    />

    <div v-if="filtersOpen" class="pxpl__filters">
      <div class="pxpl__filters-grid">
        <px-field :label="$t('date')"><template #default="{ id }"><px-input :id="id" type="date" v-model="Filter_date" /></template></px-field>
        <px-field :label="$t('Reference')"><template #default="{ id }"><px-input :id="id" v-model="Filter_Ref" :placeholder="$t('Reference')" /></template></px-field>
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
              :options="[{ label: 'Received', value: 'received' }, { label: 'Pending', value: 'pending' }, { label: 'Ordered', value: 'ordered' }]" />
          </template>
        </px-field>
        <px-field :label="$t('PaymentStatus')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_Payment" :reduce="o => o.value" :placeholder="$t('Choose_Status')"
              :options="[{ label: 'Paid', value: 'paid' }, { label: 'partial', value: 'partial' }, { label: 'UnPaid', value: 'unpaid' }]" />
          </template>
        </px-field>
      </div>
      <div class="pxpl__filters-act">
        <px-button size="sm" variant="primary" icon="filter" @click="applyFilters">{{ $t('Filter') }}</px-button>
        <px-button size="sm" variant="ghost" icon="x" @click="Reset_Filter">{{ $t('Reset') }}</px-button>
      </div>
    </div>

    <div v-if="isLoading" class="pxpl__pad">
      <px-skeleton variant="table" :rows="10" :columns="6" />
    </div>

    <template v-else>
      <transition name="pxpl-bulk">
        <div v-if="selectedIds.length" class="pxpl__bulk">
          <span><b class="pxn-num">{{ selectedIds.length }}</b> {{ $t('selected') }}</span>
          <div class="pxpl__bulk-act">
            <px-button v-if="currentUserPermissions.includes('Purchases_delete')" size="sm" variant="danger" icon="trash-2" @click="delete_by_selected">{{ $t('Del') }}</px-button>
            <px-button size="sm" variant="ghost" @click="selectedIds = []">{{ $t('Cancel') }}</px-button>
          </div>
        </div>
      </transition>

      <div class="pxpl__tablewrap">
        <px-table
          v-if="purchases.length"
          :columns="columns"
          :rows="purchases"
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
            <router-link class="pxpl__link" :to="'/app/purchases/detail/' + row.id">{{ row.Ref }}</router-link>
            <lucide-icon v-if="row.purchase_has_return == 'yes'" name="arrow-left" :size="13" class="pxpl__ret" />
          </template>
          <template #cell-statut="{ row }">
            <px-badge :tone="row.statut === 'received' ? 'success' : (row.statut === 'pending' ? 'info' : 'warning')">
              {{ row.statut === 'received' ? $t('Received') : (row.statut === 'pending' ? $t('Pending') : $t('Ordered')) }}
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
          <template #cell-documents="{ row }">
            <px-badge v-if="row.documents_count > 0" tone="info" icon="file">{{ row.documents_count }}</px-badge>
            <span v-else class="pxpl__muted">—</span>
          </template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions(row)" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state
          v-else
          icon="shopping-cart"
          :title="$t('No_purchases_yet') || 'Sin compras todavía'"
          :description="$t('No_purchases_desc') || 'Cuando registres una compra, aparecerá en esta lista.'"
        >
          <px-button
            v-if="currentUserPermissions && currentUserPermissions.includes('Purchases_add')"
            size="sm" variant="primary" icon="plus" @click="$router.push('/app/purchases/store')"
          >{{ $t('Add') }}</px-button>
        </px-empty-state>
      </div>

      <px-pagination
        v-if="purchases.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <!-- Show payments -->
    <px-modal v-model="showPaymentOpen" :title="$t('ShowPayment')" size="lg">
      <div class="pxpl-tbl__wrap pxn-scroll">
        <table class="pxpl-tbl">
          <thead>
            <tr>
              <th>{{ $t('date') }}</th><th>{{ $t('Reference') }}</th><th class="is-right">{{ $t('Amount') }}</th>
              <th>{{ $t('PayeBy') }}</th><th class="is-right">{{ $t('Action') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="factures.length <= 0"><td colspan="5" class="pxpl__empty">{{ $t('NodataAvailable') }}</td></tr>
            <tr v-for="facture in factures" :key="facture.id">
              <td>{{ facture.date }}</td>
              <td class="pxn-mono">{{ facture.Ref }}</td>
              <td class="is-right pxn-num">{{ formatPriceWithSymbol(currentUser.currency, facture.montant, 2) }}</td>
              <td>{{ facture.payment_method ? facture.payment_method.name : '---' }}</td>
              <td class="is-right">
                <div class="pxpl__rowbtns">
                  <px-button size="sm" variant="ghost" icon-only icon="printer" :title="$t('print')" @click="Payment_Purchase_PDF(facture, facture.id)" />
                  <px-button v-if="currentUserPermissions.includes('payment_purchases_edit')" size="sm" variant="ghost" icon-only icon="pencil" :title="$t('Edit')" @click="Edit_Payment(facture)" />
                  <px-button size="sm" variant="ghost" icon-only icon="mail" title="Email" @click="Send_Email_Payment(facture.id)" />
                  <px-button size="sm" variant="ghost" icon-only icon="message-square" title="SMS" @click="Payment_Purchase_SMS(facture.id)" />
                  <px-button v-if="currentUserPermissions.includes('payment_purchases_delete')" size="sm" variant="ghost" icon-only icon="x" :title="$t('Delete')" @click="Remove_Payment(facture.id)" />
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <template #footer="{ close }">
        <span class="pxpl__grow" />
        <px-button variant="secondary" @click="close">Cerrar</px-button>
      </template>
    </px-modal>

    <!-- Add / edit payment -->
    <validation-observer ref="Add_payment">
      <px-modal v-model="addPaymentOpen" :title="EditPaiementMode ? $t('EditPayment') : $t('AddPayment')" size="lg">
        <b-form @submit.prevent="Submit_Payment">
          <div class="pxpl__grid3">
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
                <p class="pxpl__change pxn-num">{{ parseFloat(facture.received_amount - facture.montant).toFixed(priceDecimals) }}</p>
              </template>
            </px-field>

            <px-field :label="$t('Account')" class="pxpl__span2">
              <template #default="{ id }">
                <vs-px :input-id="id" v-model="facture.account_id" :reduce="o => o.value" :placeholder="$t('Choose_Account')"
                  :options="accounts.map(a => ({ label: a.account_name, value: a.id }))" />
              </template>
            </px-field>

            <px-field :label="$t('Note')" class="pxpl__span2">
              <template #default="{ id }"><px-textarea :id="id" v-model="facture.notes" :rows="3" /></template>
            </px-field>
          </div>

          <div class="pxpl__actionbar">
            <px-button variant="secondary" type="button" @click="addPaymentOpen = false">{{ $t('Cancel') }}</px-button>
            <px-button variant="primary" type="submit" icon="check" :loading="paymentProcessing">{{ $t('submit') }}</px-button>
          </div>
        </b-form>
      </px-modal>
    </validation-observer>

    <!-- Manage documents -->
    <px-modal v-model="documentsOpen" size="lg" :title="$t('Attach_Documents')">
      <div class="pxpl__docsupload">
        <b-form-group :label="$t('Upload_Documents')">
          <b-form-file
            v-model="selectedFiles"
            :placeholder="$t('Choose_files_or_drop_them_here')"
            :drop-placeholder="$t('Drop_files_here')"
            multiple
            accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif"
            @change="onFileChange"
          ></b-form-file>
        </b-form-group>
        <px-button size="sm" variant="primary" icon="upload"
          :loading="uploadProcessing"
          :disabled="!selectedFiles || selectedFiles.length === 0 || uploadProcessing"
          @click="Upload_Documents"
        >{{ $t('Upload') }}</px-button>
      </div>

      <h5 class="pxpl__docshead">{{ $t('Attached_Documents') }}</h5>
      <div class="pxpl-tbl__wrap pxn-scroll">
        <table class="pxpl-tbl">
          <thead>
            <tr><th>{{ $t('File_Name') }}</th><th>{{ $t('Size') }}</th><th>{{ $t('Uploaded_Date') }}</th><th class="is-right">{{ $t('Action') }}</th></tr>
          </thead>
          <tbody>
            <tr v-if="documents.length <= 0"><td colspan="4" class="pxpl__empty">{{ $t('NodataAvailable') }}</td></tr>
            <tr v-for="document in documents" :key="document.id">
              <td><lucide-icon name="file" :size="14" /> {{ document.name }}</td>
              <td>{{ formatFileSize(document.size) }}</td>
              <td>{{ formatDateTime(document.created_at) }}</td>
              <td class="is-right">
                <div class="pxpl__rowbtns">
                  <px-button size="sm" variant="ghost" icon-only icon="download" :title="$t('Download')" @click="Download_Document(document)" />
                  <px-button size="sm" variant="ghost" icon-only icon="x" :title="$t('Delete')" @click="Remove_Document(document.id)" />
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </px-modal>
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
    title: "Purchases"
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
      documentsOpen: false,
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
      EditPaiementMode: false,
      Filter_Supplier: "",
      Filter_status: "",
      Filter_Payment: "",
      Filter_warehouse: "",
      Filter_Ref: "",
      Filter_date: "",
      Purchase_id: "",
      suppliers: [],
      warehouses: [],
      payment_methods: [],
      details: [],
      purchases: [],
      purchase: {},
      factures: [],
      accounts: [],
      purchase_due:'',
      due:0,
      facture: {
        montant: "",
        received_amount: "",
        payment_method_id: "",
        notes: ""
      },
      limit: "10",
      email: {
        to: "",
        subject: "",
        message: "",
        client_name: "",
        purchase_Ref: ""
      },
      emailPayment: {
        id: "",
        to: "",
        subject: "",
        message: "",
        client_name: "",
        Ref: ""
      },
      documents: [],
      selectedFiles: [],
      uploadProcessing: false,
      currentPurchaseId: null,
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
      return [this.Filter_date, this.Filter_Ref, this.Filter_Supplier, this.Filter_warehouse, this.Filter_status, this.Filter_Payment]
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
        { key: "statut", label: this.$t("Status"), sortable: true },
        { key: "GrandTotal", label: this.$t("Total"), align: "right", sortable: true },
        { key: "paid_amount", label: this.$t("Paid"), align: "right", sortable: true },
        { key: "due", label: this.$t("Due"), align: "right" },
        { key: "payment_status", label: this.$t("PaymentStatus"), sortable: true },
        { key: "documents", label: this.$t("Documents") }
      ];
    }
  },

  methods: {

    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    //---- Toolbar search (debounced)
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Purchases(1); }, 350);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Purchases(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Purchases(1); } },

    onSort({ key, dir }) {
      let field = key;
      if (key === "provider_name") field = "provider_id";
      else if (key === "warehouse_name") field = "warehouse_id";
      this.updateParams({ sort: { type: dir, field: field } });
      this.Get_Purchases(this.serverParams.page);
    },

    applyFilters() { this.updateParams({ page: 1 }); this.Get_Purchases(this.serverParams.page); },

    onExport(item) {
      const k = item && item.key;
      if (k === "pdf") this.Purchase_PDF();
      else if (k === "xlsx") this.exportCsv();
    },

    exportCsv() {
      const head = [this.$t("date"), this.$t("Reference"), this.$t("Supplier"), this.$t("warehouse"), this.$t("Status"), this.$t("Total"), this.$t("Paid"), this.$t("Due"), this.$t("PaymentStatus")];
      const lines = [head.join(",")].concat(
        (this.purchases || []).map(r =>
          [r.date, r.Ref, r.provider_name, r.warehouse_name, r.statut, r.GrandTotal, r.paid_amount, r.due, r.payment_status]
            .map(c => `"${String(c == null ? "" : c).replace(/"/g, '""')}"`).join(",")
        )
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "Purchases.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    //---- Kebab row actions
    rowActions(row) {
      const p = this.currentUserPermissions || [];
      const items = [{ key: "view", label: this.$t("PurchaseDetail"), icon: "eye" }];
      if (p.includes("Purchases_edit") && row.purchase_has_return == "no") items.push({ key: "edit", label: this.$t("EditPurchase"), icon: "pencil" });
      if (p.includes("Purchase_Returns_add") && row.purchase_has_return == "no" && row.statut == "received") items.push({ key: "return", label: this.$t("Purchase_Return"), icon: "arrow-left" });
      if (p.includes("Purchase_Returns_add") && row.purchase_has_return == "yes") items.push({ key: "return_edit", label: this.$t("Purchase_Return"), icon: "arrow-left" });
      if (p.includes("payment_purchases_view")) items.push({ key: "showpay", label: this.$t("ShowPayment"), icon: "wallet" });
      if (p.includes("payment_purchases_add") && row.statut == "received") items.push({ key: "addpay", label: this.$t("AddPayment"), icon: "plus" });
      items.push({ key: "pdf", label: this.$t("DownloadPdf"), icon: "file-text" });
      items.push({ key: "barcode", label: this.$t("Printbarcode"), icon: "scan-line" });
      items.push({ key: "whatsapp", label: "WhatsApp", icon: "message-circle" });
      items.push({ key: "email", label: this.$t("email_notification"), icon: "mail" });
      items.push({ key: "sms", label: this.$t("sms_notification"), icon: "message-square" });
      items.push({ key: "docs", label: this.$t("Attach_Documents"), icon: "file" });
      if (p.includes("Purchases_delete")) items.push({ key: "delete", label: this.$t("DeletePurchase"), icon: "x", tone: "danger" });
      return items;
    },

    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "view") this.$router.push("/app/purchases/detail/" + row.id);
      else if (k === "edit") this.$router.push("/app/purchases/edit/" + row.id);
      else if (k === "return") this.$router.push("/app/purchases/purchase_return/" + row.id);
      else if (k === "return_edit") this.$router.push("/app/purchase_return/edit/" + row.purchasereturn_id + "/" + row.id);
      else if (k === "showpay") this.Show_Payments(row.id, row);
      else if (k === "addpay") this.New_Payment(row);
      else if (k === "pdf") this.Invoice_PDF(row, row.id);
      else if (k === "barcode") this.Print_Purchase_Barcode(row.id);
      else if (k === "whatsapp") this.Send_WhatsApp(row.id);
      else if (k === "email") this.Send_Email(row.id);
      else if (k === "sms") this.Purchase_SMS(row.id);
      else if (k === "docs") this.Manage_Documents(row.id);
      else if (k === "delete") this.Remove_Purchase(row.id, row.purchase_has_return);
    },

    //--- vee-validate 3.x: seed prefilled/fetched values into the payment providers.
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
      this.Filter_status = "";
      this.Filter_Payment = "";
      this.Filter_Ref = "";
      this.Filter_date = "";
      this.Filter_warehouse = "";
      this.Get_Purchases(this.serverParams.page);
    },

    //---------------------- Purchases PDF -------------------------------\\
    Purchase_PDF() {
      var self = this;
      let pdf = new jsPDF("p", "pt");

      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      try { pdf.addFont(fontPath,'Vazirmatn','normal'); pdf.addFont(fontPath,'Vazirmatn','bold'); } catch(e){}
      pdf.setFont('Vazirmatn','normal');

      const headers = [
        self.$t("Reference"),
        self.$t("Supplier"),
        self.$t("warehouse"),
        self.$t("Status"),
        self.$t("Total"),
        self.$t("Paid"),
        self.$t("Due"),
        self.$t("PaymentStatus")
      ];

      const body = (self.purchases || []).map(purchase => ([
        purchase.Ref,
        purchase.provider_name,
        purchase.warehouse_name,
        purchase.statut,
        purchase.GrandTotal,
        purchase.paid_amount,
        purchase.due,
        purchase.payment_status
      ]));

      // Calculate totals
      let totalGrandTotal = self.purchases.reduce((sum, purchase) => sum + parseFloat(purchase.GrandTotal || 0), 0);
      let totalPaidAmount = self.purchases.reduce((sum, purchase) => sum + parseFloat(purchase.paid_amount || 0), 0);
      let totalDue = self.purchases.reduce((sum, purchase) => sum + parseFloat(purchase.due || 0), 0);

      const footer = [[
        self.$t("Total"),
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
          const title = self.$t('PurchasesList') || 'Purchases List';
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

      pdf.save("Purchases_List.pdf");
    },

    Send_WhatsApp(id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("purchase_send_whatsapp", {
          id: id,
        })
        .then(response => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);

          var phone = response.data.phone;
          var message = response.data.message;

          // Encode phone number and message
          var encodedPhone = encodeURIComponent(phone);
          var encodedMessage = encodeURIComponent(message);

          // Create WhatsApp URL
          var whatsappUrl = `https://web.whatsapp.com/send/?phone=${encodedPhone}&text=${encodedMessage}`;

          // Open the WhatsApp URL in a new window
          window.open(whatsappUrl, '_blank');

        })
        .catch(error => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", "Failed to send the Message", this.$t("Failed"));
        });
    },

    //--------------------------- Invoice Purchase -------------------------------\\
    Invoice_PDF(purchase, id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);

       axios
        .get("purchase_pdf/" + id, {
          responseType: "blob", // important
          headers: {
            "Content-Type": "application/json"
          }
        })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute("download", "Purchase-" + purchase.Ref + ".pdf");
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

    //------------------------------ Payment Purchase -------------------------------\\
    Payment_Purchase_PDF(facture, id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);

       axios
        .get("payment_purchase_pdf/" + id, {
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
      }
    },

    //------------------------------------------------ Get All Purchases -------------------------------\\
    Get_Purchases(page) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      this.setToStrings();
      axios
        .get(
          "purchases?page=" +
            page +
            "&Ref=" +
            this.Filter_Ref +
            "&date=" +
            this.Filter_date +
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
          this.purchases = response.data.purchases;
          this.suppliers = response.data.suppliers;
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
          this.isLoading = false;
        });
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
    // Uses the global/system price_format setting when available; otherwise falls back
    // to the existing formatNumber helper to preserve current behavior.
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

    //------------------------------- Remove Purchase -------------------------\\

    Remove_Purchase(id , purchase_has_return) {
      if(purchase_has_return == 'yes'){
        this.makeToast("danger", this.$t("Return_exist_for_the_Transaction"), this.$t("Failed"));
      }else{
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
              .delete("purchases/" + id)
              .then(() => {
                this.$swal(
                  this.$t("Delete_Deleted"),
                  this.$t("Deleted_in_successfully"),
                  "success"
                );
                Fire.$emit("Delete_Purchase");
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
    },

    //---- Delete purchases by selection

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
            .post("purchases_delete_by_selection", {
              selectedIds: this.selectedIds
            })
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );

              Fire.$emit("Delete_Purchase");
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

      //---------SMS notification
     Payment_Purchase_SMS(id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("payment_purchase_send_sms", {
          id: id,
        })
        .then(response => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
          this.makeToast(
            "success",
            this.$t("sms_send_successfully"),
            this.$t("Success")
          );
        })
        .catch(error => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("sms_config_invalid"), this.$t("Failed"));
        });
    },


    Send_Email_Payment(id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("payment_purchase_send_email", {
          id: id,
        })
        .then(response => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);

          this.makeToast(
            "success",
            this.$t("SendEmail"),
            this.$t("Success")
          );
        })
        .catch(error => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("SMTPIncorrect"), this.$t("Failed"));
        });
    },

    //--------------------------------------------- Send Purchase to Email -------------------------------\\
    Send_Email(id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("purchase_send_email", {
          id: id,
        })
        .then(response => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
          this.makeToast(
            "success",
            this.$t("SendEmail"),
            this.$t("Success")
          );
        })
        .catch(error => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("SMTPIncorrect"), this.$t("Failed"));
        });
    },

     //---------SMS notification

     Purchase_SMS(id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("purchase_send_sms", {
          id: id,
        })
        .then(response => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
          this.makeToast(
            "success",
            this.$t("Send_SMS"),
            this.$t("Success")
          );
        })
        .catch(error => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("sms_config_invalid"), this.$t("Failed"));
        });
    },

    //----------------------------------------------------- Add Payment to Purchase -------------------------------\\
    New_Payment(purchase) {
      if (purchase.payment_status == "paid") {
        this.makeToast(
          "warning",
          this.$t("PaymentComplete"),
          this.$t("Warning")
        );
      } else {
        // Start the progress bar.
        NProgress.start();
        NProgress.set(0.1);
        this.reset_form_payment();
        this.EditPaiementMode = false;
        this.purchase = purchase;
        this.facture.date = new Date().toISOString().slice(0, 10);
        this.Number_Order_Payment();
        this.facture.montant = purchase.due;
        this.facture.payment_method_id = 2;
        this.facture.received_amount = purchase.due;
        this.due = parseFloat(this.purchase.due);
        setTimeout(() => {
          // Complete the animation of the  progress bar.
          NProgress.done();
          this.addPaymentOpen = true;
          this.syncPaymentValidators();
        }, 500);
      }
    },

    Number_Order_Payment() {
      axios
        .get("payment_purchase_get_number")
        .then(({ data }) => (this.facture.Ref = data));
    },

    //------------------------------------------------ Edit Pyament -------------------------------\\
    Edit_Payment(facture) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      this.reset_form_payment();

      this.facture.id        = facture.id;
      this.facture.Ref       = facture.Ref;
      this.facture.payment_method_id = facture.payment_method_id;
      this.facture.account_id = facture.account_id;
      this.facture.date    = facture.date;
      this.facture.change  = facture.change;
      this.facture.montant = facture.montant;
      this.facture.received_amount = parseFloat(facture.montant + facture.change).toFixed(this.priceDecimals);
      this.facture.notes   = facture.notes;
      this.due = parseFloat(this.purchase_due) + facture.montant;
      this.EditPaiementMode = true;
      setTimeout(() => {
        // Complete the animation of the  progress bar.
        NProgress.done();
        this.addPaymentOpen = true;
        this.syncPaymentValidators();
      }, 500);
    },

    //--------------------------------- Show All Payments by Purchase -------------------------------\\
    Show_Payments(id, purchase) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      this.reset_form_payment();
      this.Purchase_id = id;
      this.purchase = purchase;
      this.Get_Payments(id);
    },

    reset_form_payment() {
      this.facture = {
        id: "",
        purchase_id: "",
        account_id: "",
        date: "",
        Ref: "",
        montant: "",
        received_amount: "",
        payment_method_id: "",
        notes: ""
      };
    },

    //---------------------------------------- Create Payment --------------------------------------\\
    Create_Payment() {
      this.paymentProcessing = true;
      NProgress.start();
      NProgress.set(0.1);
        axios
          .post("payment_purchase", {
            purchase_id: this.purchase.id,
            date: this.facture.date,
            montant: parseFloat(this.facture.montant).toFixed(this.priceDecimals),
            received_amount: parseFloat(this.facture.received_amount).toFixed(this.priceDecimals),
            payment_method_id: this.facture.payment_method_id,
            account_id: this.facture.account_id,
            change: parseFloat(this.facture.received_amount - this.facture.montant).toFixed(this.priceDecimals),
            notes: this.facture.notes
          })
          .then(response => {
            this.paymentProcessing = false;
            Fire.$emit("Create_Facture_purchase");
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

    //------------------------------------------- Update Payment -------------------------------\\
    Update_Payment() {
      this.paymentProcessing = true;
      NProgress.start();
      NProgress.set(0.1);

        axios
          .put("payment_purchase/" + this.facture.id, {
            purchase_id: this.purchase.id,
            date: this.facture.date,
            montant: parseFloat(this.facture.montant).toFixed(this.priceDecimals),
            received_amount: parseFloat(this.facture.received_amount).toFixed(this.priceDecimals),
            payment_method_id: this.facture.payment_method_id,
            account_id: this.facture.account_id,
            change: parseFloat(this.facture.received_amount - this.facture.montant).toFixed(this.priceDecimals),
            notes: this.facture.notes
          })
          .then(response => {
            this.paymentProcessing = false;
            Fire.$emit("Update_Facture_purchase");
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



    //------------------------------------ Remove Payment -------------------------------\\
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
            .delete("payment_purchase/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              Fire.$emit("Delete_Facture_purchase");
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

    //----------------------------------------- Get All Payments  -------------------------------\\
    Get_Payments(id) {
      axios
        .get("get_payments_by_purchase/" + id)
        .then(response => {
          this.factures = response.data.payments;
          this.purchase_due = response.data.due;
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

    //----------------------------------------- Manage Documents -------------------------------\\
    Manage_Documents(purchaseId) {
      this.currentPurchaseId = purchaseId;
      this.selectedFiles = [];
      NProgress.start();
      NProgress.set(0.1);
      this.Get_Documents(purchaseId);
    },

    //----------------------------------------- Get Documents -------------------------------\\
    Get_Documents(purchaseId) {
      axios
        .get("purchases/" + purchaseId + "/documents")
        .then(response => {
          this.documents = response.data.documents || [];
          setTimeout(() => {
            NProgress.done();
            this.documentsOpen = true;
          }, 500);
        })
        .catch(error => {
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("Failed_to_load_documents"), this.$t("Failed"));
        });
    },

    //----------------------------------------- On File Change -------------------------------\\
    onFileChange(event) {
      this.selectedFiles = event.target.files || [];
    },

    //----------------------------------------- Upload Documents -------------------------------\\
    Upload_Documents() {
      if (!this.selectedFiles || this.selectedFiles.length === 0) {
        this.makeToast("warning", this.$t("Please_select_files"), this.$t("Warning"));
        return;
      }

      this.uploadProcessing = true;
      NProgress.start();
      NProgress.set(0.1);

      const formData = new FormData();
      for (let i = 0; i < this.selectedFiles.length; i++) {
        formData.append('documents[]', this.selectedFiles[i]);
      }
      formData.append('purchase_id', this.currentPurchaseId);

      axios
        .post("purchases/" + this.currentPurchaseId + "/documents", formData, {
          headers: {
            'Content-Type': 'multipart/form-data'
          }
        })
        .then(response => {
          this.uploadProcessing = false;
          this.selectedFiles = [];
          this.Get_Documents(this.currentPurchaseId);
          this.Get_Purchases(this.serverParams.page);
          this.makeToast("success", this.$t("Documents_uploaded_successfully"), this.$t("Success"));
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(error => {
          this.uploadProcessing = false;
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("Failed_to_upload_documents"), this.$t("Failed"));
        });
    },

    //----------------------------------------- Download Document -------------------------------\\
    Download_Document(doc) {
      NProgress.start();
      NProgress.set(0.1);

      axios
        .get("purchases/documents/" + doc.id + "/download", {
          responseType: "blob"
        })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = window.document.createElement("a");
          link.href = url;
          link.setAttribute("download", doc.name);
          window.document.body.appendChild(link);
          link.click();
          window.document.body.removeChild(link);
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(error => {
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("Failed_to_download_document"), this.$t("Failed"));
        });
    },

    //----------------------------------------- Remove Document -------------------------------\\
    Remove_Document(documentId) {
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
            .delete("purchases/documents/" + documentId)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              this.Get_Documents(this.currentPurchaseId);
              this.Get_Purchases(this.serverParams.page);
              setTimeout(() => NProgress.done(), 500);
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

    //----------------------------------------- Format File Size -------------------------------\\
    formatFileSize(bytes) {
      if (bytes === 0) return '0 Bytes';
      const k = 1024;
      const sizes = ['Bytes', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    },

    //----------------------------------------- Format Date Time -------------------------------\\
    formatDateTime(value) {
      if (!value) return '';
      const d = new Date(value);
      if (isNaN(d.getTime())) return value; // fallback to raw if invalid

      const pad = n => (n < 10 ? '0' + n : n);
      const year = d.getFullYear();
      const month = pad(d.getMonth() + 1);
      const day = pad(d.getDate());
      const hours = pad(d.getHours());
      const minutes = pad(d.getMinutes());

      // Result: YYYY-MM-DD HH:MM
      return `${year}-${month}-${day} ${hours}:${minutes}`;
    },
    //----------------------------------------- Format Display Date (for tables) -------------------------------\\
    formatDisplayDate(value) {
      if (!value) return '';
      // Get date format from Vuex store (loaded from database) or fallback
      const dateFormat = this.$store.getters.getDateFormat || Util.getDateFormat(this.$store);
      return Util.formatDisplayDate(value, dateFormat);
    },

    // ------------------------- Print Barcodes for Purchase -------------------------\\
    Print_Purchase_Barcode(id) {
      this.$router.push({
        name: "barcode",
        query: { purchase_id: id }
      });
    }
  },

  //-----------------------------Created function-------------------
  created: function() {
    this.Get_Purchases(1);

    Fire.$on("Delete_Purchase", () => {
      setTimeout(() => {
        this.Get_Purchases(this.serverParams.page);
        // Complete the animation of the  progress bar.
        NProgress.done();
      }, 800);
    });

    Fire.$on("Create_Facture_purchase", () => {
      setTimeout(() => {
        this.Get_Purchases(this.serverParams.page);
        // Complete the animation of the  progress bar.
        NProgress.done();
        this.addPaymentOpen = false;
      }, 800);
    });

    Fire.$on("Update_Facture_purchase", () => {

      setTimeout(() => {
        NProgress.done();
        this.addPaymentOpen = false;
        this.showPaymentOpen = false;
        this.Get_Purchases(this.serverParams.page);
      }, 800);
    });

    Fire.$on("Delete_Facture_purchase", () => {
      setTimeout(() => {
        NProgress.done();
        this.showPaymentOpen = false;
        this.Get_Purchases(this.serverParams.page);
      }, 800);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxpl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxpl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxpl__pad { padding: var(--pxn-space-6) 0; }
.pxpl__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxpl__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxpl__filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxpl__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxpl__filters-act { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); }
.pxpl__bulk { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-5); margin-top: var(--pxn-space-4); padding: var(--pxn-space-4) var(--pxn-space-5); background: var(--pxn-primary-soft); border: 1px solid var(--pxn-primary-border); border-radius: var(--pxn-radius-md); font-size: var(--pxn-fs-sm); color: var(--pxn-primary-ink); }
.pxpl__bulk-act { display: flex; gap: var(--pxn-space-3); }
.pxpl-bulk-enter-active, .pxpl-bulk-leave-active { transition: opacity var(--pxn-dur-2) var(--pxn-ease), transform var(--pxn-dur-2) var(--pxn-ease); }
.pxpl-bulk-enter, .pxpl-bulk-leave-to { opacity: 0; transform: translateY(-6px); }
.pxpl__tablewrap { margin-top: var(--pxn-space-5); }
.pxpl__link { color: var(--pxn-primary); text-decoration: none; }
.pxpl__link:hover { text-decoration: underline; }
.pxpl__ret { color: var(--pxn-danger-ink); margin-left: var(--pxn-space-2); vertical-align: -2px; }
.pxpl__muted { color: var(--pxn-ink-3); }
.pxpl-tbl__wrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); overflow-x: auto; }
.pxpl-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxpl-tbl th { padding: var(--pxn-space-3) var(--pxn-space-4); text-align: left; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap; }
.pxpl-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink); }
.pxpl-tbl tr:last-child td { border-bottom: 0; }
.pxpl-tbl .is-right { text-align: right; }
.pxpl__empty { text-align: center; color: var(--pxn-ink-3); }
.pxpl__rowbtns { display: inline-flex; gap: var(--pxn-space-1); }
.pxpl__grid3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 720px) { .pxpl__grid3 { grid-template-columns: minmax(0, 1fr); } }
.pxpl__span2 { grid-column: span 2; }
@media (max-width: 720px) { .pxpl__span2 { grid-column: span 1; } }
.pxpl__change { margin: 0; padding: var(--pxn-space-2) 0; font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxpl__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
.pxpl__grow { flex: 1; }
.pxpl__docsupload { display: flex; flex-direction: column; gap: var(--pxn-space-3); margin-bottom: var(--pxn-space-6); }
.pxpl__docshead { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); margin: var(--pxn-space-4) 0 var(--pxn-space-3); }
</style>
