<template>
  <div class="px-next pxfl">
    <px-page-header :title="$t('Expense_List')" :breadcrumbs="[{ label: $t('Expenses') }, { label: $t('Expense_List') }]">
      <template #actions>
        <px-menu :items="exportMenu" align="end" @select="onExport">
          <template #trigger>
            <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">Exportar</px-button>
          </template>
        </px-menu>
        <px-button
          v-if="currentUserPermissions && currentUserPermissions.includes('expense_add')"
          variant="primary" icon="plus" @click="$router.push('/app/expenses/store')"
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

    <div v-if="filtersOpen" class="pxfl__filters">
      <div class="pxfl__filters-grid">
        <px-field :label="$t('date')"><template #default="{ id }"><px-input :id="id" type="date" v-model="Filter_date" /></template></px-field>
        <px-field :label="$t('Reference')"><template #default="{ id }"><px-input :id="id" v-model="Filter_Ref" :placeholder="$t('Reference')" /></template></px-field>
        <px-field :label="$t('Paymentchoice')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_Reg" :reduce="o => o.value" :placeholder="$t('PleaseSelect')"
              :options="payment_methods.map(m => ({ label: m.name, value: m.id }))" />
          </template>
        </px-field>
        <px-field :label="$t('Account')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_account" :reduce="o => o.value" :placeholder="$t('Choose_Account')"
              :options="accounts.map(a => ({ label: a.account_name, value: a.id }))" />
          </template>
        </px-field>
        <px-field :label="$t('warehouse')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_warehouse" :reduce="o => o.value" :placeholder="$t('Choose_Warehouse')"
              :options="warehouses.map(w => ({ label: w.name, value: w.id }))" />
          </template>
        </px-field>
        <px-field :label="$t('Expense_Category')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_category" :reduce="o => o.value" :placeholder="$t('Choose_Category')"
              :options="expense_Category.map(c => ({ label: c.name, value: c.id }))" />
          </template>
        </px-field>
      </div>
      <div class="pxfl__filters-act">
        <px-button size="sm" variant="primary" icon="filter" @click="applyFilters">{{ $t('Filter') }}</px-button>
        <px-button size="sm" variant="ghost" icon="x" @click="Reset_Filter">{{ $t('Reset') }}</px-button>
      </div>
    </div>

    <div v-if="isLoading" class="pxfl__pad">
      <px-skeleton variant="table" :rows="10" :columns="6" />
    </div>

    <template v-else>
      <div class="pxfl__tablewrap">
        <px-table
          v-if="expenses.length"
          :columns="columns"
          :rows="expenses"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-date="{ row }">{{ formatDisplayDate(row.date) }}</template>
          <template #cell-amount="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser.currency, row.amount, priceDecimals) }}</span></template>
          <template #cell-documents="{ row }">
            <px-badge v-if="row.documents_count > 0" tone="info" icon="file">{{ row.documents_count }}</px-badge>
            <span v-else class="pxfl__muted">—</span>
          </template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions(row)" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state
          v-else
          icon="receipt"
          :title="$t('No_expenses_yet') || 'Sin gastos todavía'"
          :description="$t('No_expenses_desc') || 'Cuando registres un gasto, aparecerá en esta lista.'"
        >
          <px-button
            v-if="currentUserPermissions && currentUserPermissions.includes('expense_add')"
            size="sm" variant="primary" icon="plus" @click="$router.push('/app/expenses/store')"
          >{{ $t('Add') }}</px-button>
        </px-empty-state>
      </div>

      <px-pagination
        v-if="expenses.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <!-- Manage documents -->
    <px-modal v-model="documentsOpen" size="lg" :title="$t('Attach_Documents')">
      <div class="pxfl__docsupload">
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

      <h5 class="pxfl__docshead">{{ $t('Attached_Documents') }}</h5>
      <div class="pxfl-tbl__wrap pxn-scroll">
        <table class="pxfl-tbl">
          <thead>
            <tr><th>{{ $t('File_Name') }}</th><th>{{ $t('Size') }}</th><th>{{ $t('Uploaded_Date') }}</th><th class="is-right">{{ $t('Action') }}</th></tr>
          </thead>
          <tbody>
            <tr v-if="documents.length <= 0"><td colspan="4" class="pxfl__empty">{{ $t('NodataAvailable') }}</td></tr>
            <tr v-for="document in documents" :key="document.id">
              <td><lucide-icon name="file" :size="14" /> {{ document.name }}</td>
              <td>{{ formatFileSize(document.size) }}</td>
              <td>{{ formatDateTime(document.created_at) }}</td>
              <td class="is-right">
                <div class="pxfl__rowbtns">
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
import Util from '../../../../utils';
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
import PxModal from "@/components/px-next/PxModal.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "Expense"
  },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxKebab,
    PxBadge, PxField, PxInput, PxModal, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      _searchTimer: null,
      isLoading: true,
      filtersOpen: false,
      documentsOpen: false,
      serverParams: {
        columnFilters: {},
        sort: {
          field: "id",
          type: "desc"
        },
        page: 1,
        perPage: 10
      },
      selectedIds: [],
      totalRows: "",
      search: "",
      limit: "10",
      Filter_date: "",
      Filter_Ref: "",
      Filter_warehouse: "",
      Filter_category: "",
      Filter_account: "",
      Filter_Reg: "",
      expenses: [],
      warehouses: [],
      payment_methods: [],
      accounts: [],
      expense_Category: [],
      documents: [],
      selectedFiles: [],
      currentExpenseId: null,
      uploadProcessing: false,
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
      return [this.Filter_date, this.Filter_Ref, this.Filter_Reg, this.Filter_account, this.Filter_warehouse, this.Filter_category]
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
        { key: "payment_method", label: this.$t("ModePaiement"), sortable: true },
        { key: "account_name", label: this.$t("Account"), sortable: true },
        { key: "amount", label: this.$t("Amount"), align: "right", sortable: true },
        { key: "category_name", label: this.$t("Categorie"), sortable: true },
        { key: "warehouse_name", label: this.$t("warehouse"), sortable: true },
        { key: "details", label: this.$t("Details") },
        { key: "documents", label: this.$t("Documents") }
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
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Expenses(1); }, 350);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Expenses(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Expenses(1); } },

    onSort({ key, dir }) {
      let field = key;
      if (key === "warehouse_name") field = "warehouse_id";
      else if (key === "category_name") field = "expense_category_id";
      else if (key === "account_name") field = "account_id";
      this.updateParams({ sort: { type: dir, field: field } });
      this.Get_Expenses(this.serverParams.page);
    },

    applyFilters() { this.updateParams({ page: 1 }); this.Get_Expenses(this.serverParams.page); },

    onExport(item) {
      const k = item && item.key;
      if (k === "pdf") this.Expense_PDF();
      else if (k === "xlsx") this.exportCsv();
    },

    exportCsv() {
      const head = [this.$t("date"), this.$t("Reference"), this.$t("Account"), this.$t("Categorie"), this.$t("warehouse"), this.$t("ModePaiement"), this.$t("Amount")];
      const lines = [head.join(",")].concat(
        (this.expenses || []).map(r =>
          [r.date, r.Ref, r.account_name, r.category_name, r.warehouse_name, r.payment_method, r.amount]
            .map(c => `"${String(c == null ? "" : c).replace(/"/g, '""')}"`).join(",")
        )
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "Expenses.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    rowActions(row) {
      const p = this.currentUserPermissions || [];
      const items = [{ key: "docs", label: this.$t("Attach_Documents"), icon: "file" }];
      if (p.includes("expense_edit")) items.push({ key: "edit", label: this.$t("Edit"), icon: "pencil" });
      if (p.includes("expense_delete")) items.push({ key: "delete", label: this.$t("Del"), icon: "x", tone: "danger" });
      return items;
    },

    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "docs") this.Manage_Documents(row.id);
      else if (k === "edit") this.$router.push("/app/expenses/edit/" + row.id);
      else if (k === "delete") this.Remove_Expense(row.id);
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },

    //---------------------- Expenses PDF -------------------------------\\
    Expense_PDF() {
      var self = this;
      let pdf = new jsPDF("p", "pt");

      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      try { pdf.addFont(fontPath, "Vazirmatn", "normal"); pdf.addFont(fontPath, "Vazirmatn", "bold"); } catch(e){}
      pdf.setFont("Vazirmatn", "normal");

      const headers = [
        self.$t("date"),
        self.$t("Reference"),
        self.$t("Account"),
        self.$t("Categorie"),
        self.$t("warehouse"),
        self.$t("ModePaiement"),
        self.$t("Amount")
      ];

      const body = (self.expenses || []).map(expense => ([
        expense.date,
        expense.Ref,
        expense.account_name,
        expense.category_name,
        expense.warehouse_name,
        expense.payment_method,
        expense.amount
      ]));

      // Calculate totals
      let totalGrandTotal = self.expenses.reduce((sum, expense) => sum + parseFloat(expense.amount || 0), 0);

      const footer = [[
        self.$t("Total"),
        '',
        '',
        '',
        '',
        '',
        totalGrandTotal.toFixed(self.priceDecimals)
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
          const title = self.$t('Expense_List') || 'Expense List';
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

      pdf.save("Expense_List.pdf");

    },

    //------------------------------ Money display formatting -------------------------\\
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
        if (key) this.price_format_key = key;
        return formatPriceDisplayHelper(number, decimals, key || null);
      } catch (e) {
        return this.formatNumber(number, dec);
      }
    },
    formatPriceWithSymbol(symbol, number, dec) {
      const safeSymbol = symbol || "";
      const value = this.formatPriceDisplay(number, dec);
      return safeSymbol ? `${safeSymbol} ${value}` : value;
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
      const date = new Date(value);
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      const hours = String(date.getHours()).padStart(2, '0');
      const minutes = String(date.getMinutes()).padStart(2, '0');
      return `${year}-${month}-${day} ${hours}:${minutes}`;
    },
    //----------------------------------------- Format Display Date (for tables) -------------------------------\\
    formatDisplayDate(value) {
      if (!value) return '';
      const dateFormat = this.$store.getters.getDateFormat || Util.getDateFormat(this.$store);
      return Util.formatDisplayDate(value, dateFormat);
    },

    //------ Reset Filter
    Reset_Filter() {
      this.search = "";
      this.Filter_date = "";
      this.Filter_Ref = "";
      this.Filter_warehouse = "";
      this.Filter_category = "";
      this.Filter_account = "";
      this.Filter_Reg = "";
      this.Get_Expenses(this.serverParams.page);
    },

    // Simply replaces null values with strings=''
    setToStrings() {
      if (this.Filter_warehouse === null) {
        this.Filter_warehouse = "";
      } else if (this.Filter_category === null) {
        this.Filter_category = "";
      } else if (this.Filter_account === null) {
        this.Filter_account = "";
       } else if (this.Filter_Reg === null) {
        this.Filter_Reg = "";
      }
    },

    //------------------------------------------------ Get All Expense -------------------------------\\
    Get_Expenses(page) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      this.setToStrings();
      axios
        .get(
          "expenses?page=" +
            page +
            "&Ref=" +
            this.Filter_Ref +
            "&account_id=" +
            this.Filter_account +
            "&payment_method_id=" +
            this.Filter_Reg +
            "&warehouse_id=" +
            this.Filter_warehouse +
            "&date=" +
            this.Filter_date +
            "&expense_category_id=" +
            this.Filter_category +
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
          this.expenses = response.data.expenses;
          this.expense_Category = response.data.Expenses_category;
          this.warehouses = response.data.warehouses;
          this.accounts = response.data.accounts;
          this.payment_methods = response.data.payment_methods;
          this.totalRows = response.data.totalRows;

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

    //---------------------- Manage Expense Documents -------------------------------\\
    Manage_Documents(expenseId) {
      this.currentExpenseId = expenseId;
      this.selectedFiles = [];
      NProgress.start();
      NProgress.set(0.1);
      this.Get_Documents(expenseId);
    },

    //----------------------------------------- Get Documents -------------------------------\\
    Get_Documents(expenseId) {
      axios
        .get("expenses/" + expenseId + "/documents")
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
      formData.append('expense_id', this.currentExpenseId);

      axios
        .post("expenses/" + this.currentExpenseId + "/documents", formData, {
          headers: {
            'Content-Type': 'multipart/form-data'
          }
        })
        .then(response => {
          this.uploadProcessing = false;
          this.selectedFiles = [];
          this.Get_Documents(this.currentExpenseId);
          this.Get_Expenses(this.serverParams.page);
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
        .get("expenses/documents/" + doc.id + "/download", {
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
            .delete("expenses/documents/" + documentId)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              this.Get_Documents(this.currentExpenseId);
              this.Get_Expenses(this.serverParams.page);
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

    //------------------------------- Remove Expense -------------------------\\

    Remove_Expense(id) {
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
            .delete("expenses/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              Fire.$emit("Delete_Expense");
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

  },

  //----------------------------- Created function-------------------
  created: function() {
    this.Get_Expenses(1);

    Fire.$on("Delete_Expense", () => {
      setTimeout(() => {
        // Complete the animation of theprogress bar.
        NProgress.done();
        this.Get_Expenses(this.serverParams.page);
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxfl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxfl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxfl__pad { padding: var(--pxn-space-6) 0; }
.pxfl__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxfl__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxfl__filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxfl__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxfl__filters-act { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); }
.pxfl__tablewrap { margin-top: var(--pxn-space-5); }
.pxfl__muted { color: var(--pxn-ink-3); }
.pxfl-tbl__wrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); overflow-x: auto; }
.pxfl-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxfl-tbl th { padding: var(--pxn-space-3) var(--pxn-space-4); text-align: left; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap; }
.pxfl-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink); }
.pxfl-tbl tr:last-child td { border-bottom: 0; }
.pxfl-tbl .is-right { text-align: right; }
.pxfl__empty { text-align: center; color: var(--pxn-ink-3); }
.pxfl__rowbtns { display: inline-flex; gap: var(--pxn-space-1); }
.pxfl__docsupload { display: flex; flex-direction: column; gap: var(--pxn-space-3); margin-bottom: var(--pxn-space-6); }
.pxfl__docshead { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); margin: var(--pxn-space-4) 0 var(--pxn-space-3); }
</style>
