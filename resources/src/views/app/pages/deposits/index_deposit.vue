<template>
  <div class="px-next pxfl">
    <px-page-header :title="$t('List_Deposit')" :breadcrumbs="[{ label: $t('Deposits') }, { label: $t('List_Deposit') }]">
      <template #actions>
        <px-menu :items="exportMenu" align="end" @select="onExport">
          <template #trigger>
            <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">Exportar</px-button>
          </template>
        </px-menu>
        <px-button
          v-if="currentUserPermissions && currentUserPermissions.includes('deposit_add')"
          variant="primary" icon="plus" @click="$router.push('/app/deposits/store')"
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
        <px-field :label="$t('Account')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_account" :reduce="o => o.value" :placeholder="$t('Choose_Account')"
              :options="accounts.map(a => ({ label: a.account_name, value: a.id }))" />
          </template>
        </px-field>
        <px-field :label="$t('Deposit_Category')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_category" :reduce="o => o.value" :placeholder="$t('Choose_Category')"
              :options="deposit_Category.map(c => ({ label: c.title, value: c.id }))" />
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
          v-if="deposits.length"
          :columns="columns"
          :rows="deposits"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-date="{ row }">{{ formatDisplayDate(row.date) }}</template>
          <template #cell-amount="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser.currency, row.amount, priceDecimals) }}</span></template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions(row)" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state
          v-else
          icon="landmark"
          :title="$t('No_deposits_yet') || 'Sin depósitos todavía'"
          :description="$t('No_deposits_desc') || 'Cuando registres un depósito, aparecerá en esta lista.'"
        >
          <px-button
            v-if="currentUserPermissions && currentUserPermissions.includes('deposit_add')"
            size="sm" variant="primary" icon="plus" @click="$router.push('/app/deposits/store')"
          >{{ $t('Add') }}</px-button>
        </px-empty-state>
      </div>

      <div v-if="deposits.length" class="pxfl__total">
        <span>{{ $t('Total') }}</span>
        <span class="pxn-num">{{ formatPriceWithSymbol(currentUser.currency, depositsTotal, priceDecimals) }}</span>
      </div>

      <px-pagination
        v-if="deposits.length"
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
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "Deposit"
  },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxKebab,
    PxField, PxInput, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      _searchTimer: null,
      isLoading: true,
      filtersOpen: false,
      serverParams: {
        columnFilters: {},
        sort: {
          field: "id",
          type: "desc"
        },
        page: 1,
        perPage: 10
      },
      rows: [{ date: 'Total', children: [] }],
      selectedIds: [],
      totalRows: "",
      search: "",
      limit: "10",
      Filter_date: "",
      Filter_Ref: "",
      Filter_account: "",
      Filter_category: "",
      deposits: [],
      accounts: [],
      deposit_Category: [],
      price_format_key: null
    };
  },

  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    depositsTotal() {
      return (this.deposits || []).reduce((sum, d) => sum + (parseFloat(d.amount) || 0), 0);
    },
    activeFilterCount() {
      return [this.Filter_date, this.Filter_Ref, this.Filter_account, this.Filter_category]
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
        { key: "deposit_ref", label: this.$t("Reference"), sortable: true, strong: true },
        { key: "amount", label: this.$t("Amount"), align: "right", sortable: true },
        { key: "category_name", label: this.$t("Categorie"), sortable: true },
        { key: "account_name", label: this.$t("Account"), sortable: true },
        { key: "description", label: this.$t("Details") }
      ];
    }
  },

  methods: {

    sumCount(rowObj) {
      let sum = 0;
      for (let i = 0; i < rowObj.children.length; i++) {
        sum += rowObj.children[i].amount;
      }
      return sum;
    },

    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Deposits(1); }, 350);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Deposits(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Deposits(1); } },

    onSort({ key, dir }) {
      let field = key;
      if (key === "account_name") field = "account_id";
      else if (key === "category_name") field = "deposit_category_id";
      this.updateParams({ sort: { type: dir, field: field } });
      this.Get_Deposits(this.serverParams.page);
    },

    applyFilters() { this.updateParams({ page: 1 }); this.Get_Deposits(this.serverParams.page); },

    onExport(item) {
      const k = item && item.key;
      if (k === "pdf") this.Deposit_PDF();
      else if (k === "xlsx") this.exportCsv();
    },

    exportCsv() {
      const head = [this.$t("date"), this.$t("Reference"), this.$t("Amount"), this.$t("Categorie"), this.$t("Account"), this.$t("Details")];
      const lines = [head.join(",")].concat(
        (this.deposits || []).map(r =>
          [r.date, r.deposit_ref, r.amount, r.category_name, r.account_name, r.description]
            .map(c => `"${String(c == null ? "" : c).replace(/"/g, '""')}"`).join(",")
        )
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "Deposits.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    rowActions(row) {
      const p = this.currentUserPermissions || [];
      const items = [];
      if (p.includes("deposit_edit")) items.push({ key: "edit", label: this.$t("Edit"), icon: "pencil" });
      if (p.includes("deposit_delete")) items.push({ key: "delete", label: this.$t("Del"), icon: "x", tone: "danger" });
      return items;
    },

    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.$router.push("/app/deposits/edit/" + row.id);
      else if (k === "delete") this.Remove_Deposit(row.id);
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },

    //---------------------- Deposit_PDF -------------------------------\\
    Deposit_PDF() {
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
        self.$t("Amount")
      ];

      const body = (self.deposits || []).map(deposit => ([
        deposit.date,
        deposit.deposit_ref,
        deposit.account_name,
        deposit.category_name,
        deposit.amount
      ]));

      let totalGrandTotal = self.deposits.reduce((sum, deposit) => sum + parseFloat(deposit.amount || 0), 0);

      const footer = [[
        self.$t("Total"),
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

          pdf.setFillColor(63,81,181);
          pdf.rect(0, 0, pageW, 60, 'F');

          pdf.setTextColor(255);
          pdf.setFont('Vazirmatn', 'bold');
          pdf.setFontSize(16);
          const title = self.$t('List_Deposit') || 'Deposit List';
          rtl ? pdf.text(title, pageW - marginX, 38, { align: 'right' })
              : pdf.text(title, marginX, 38);

          pdf.setTextColor(33);

          pdf.setFontSize(8);
          const pn = `${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`;
          rtl ? pdf.text(pn, marginX, pageH - 14, { align: 'left' })
              : pdf.text(pn, pageW - marginX, pageH - 14, { align: 'right' });
        }
      });

      pdf.save("Deposit_List.pdf");

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
      this.Filter_account = "";
      this.Filter_category = "";
      this.Get_Deposits(this.serverParams.page);
    },

    // Simply replaces null values with strings=''
    setToStrings() {
      if (this.Filter_account === null) {
        this.Filter_account = "";
      } else if (this.Filter_category === null) {
        this.Filter_category = "";
      }
    },

    //------------------------------------------------ Get All Deposits -------------------------------\\
    Get_Deposits(page) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      this.setToStrings();
      axios
        .get(
          "deposits?page=" +
            page +
            "&deposit_ref=" +
            this.Filter_Ref +
            "&account_id=" +
            this.Filter_account +
            "&date=" +
            this.Filter_date +
            "&deposit_category_id=" +
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
          this.deposits = response.data.deposits;
          this.deposit_Category = response.data.Deposits_category;
          this.accounts = response.data.accounts;
          this.totalRows = response.data.totalRows;
          this.rows[0].children = this.deposits;

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

    //------------------------------- Remove Deposit -------------------------\\
    Remove_Deposit(id) {
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
            .delete("deposits/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              Fire.$emit("event_delete_deposit");
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
    this.Get_Deposits(1);

    Fire.$on("event_delete_deposit", () => {
      setTimeout(() => {
        // Complete the animation of theprogress bar.
        NProgress.done();
        this.Get_Deposits(this.serverParams.page);
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
.pxfl__total { display: flex; align-items: center; justify-content: flex-end; gap: var(--pxn-space-6); margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-4); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
</style>
