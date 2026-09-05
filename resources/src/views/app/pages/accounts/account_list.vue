<template>
  <div class="px-next pxal">
    <px-page-header :title="$t('List_accounts')" :breadcrumbs="[{ label: $t('Accounting') }, { label: $t('List_accounts') }]">
      <template #actions>
        <px-button variant="primary" icon="plus" @click="New_Account">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxal__pad">
      <px-skeleton variant="table" :rows="8" :columns="4" />
    </div>

    <template v-else>
      <div class="pxal__tablewrap">
        <px-table
          v-if="accounts.length"
          :columns="columns"
          :rows="accounts"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-balance="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser.currency, row.balance, priceDecimals) }}</span></template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state
          v-else
          icon="wallet"
          :title="$t('No_accounts_yet') || 'Sin cuentas todavía'"
          :description="$t('No_accounts_desc') || 'Crea una cuenta financiera para registrar movimientos.'"
        >
          <px-button variant="primary" icon="plus" size="sm" @click="New_Account">{{ $t('Add') }}</px-button>
        </px-empty-state>
      </div>

      <px-pagination
        v-if="accounts.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <validation-observer ref="Create_Account">
      <px-modal v-model="modalOpen" size="md" :title="editmode ? $t('Edit') : $t('Add')">
        <b-form @submit.prevent="Submit_Account">
          <validation-provider ref="numProvider" name="account_num" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('account_num')" required :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model="account.account_num" :placeholder="$t('Enter_account_num')" @input="v.validate" /></template>
            </px-field>
          </validation-provider>

          <validation-provider ref="nameProvider" name="Name account" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('account_name')" required :error="v.errors[0]" class="pxal__gap">
              <template #default="{ id }"><px-input :id="id" v-model="account.account_name" :placeholder="$t('Enter_account_name')" @input="v.validate" /></template>
            </px-field>
          </validation-provider>

          <validation-provider v-if="!editmode" ref="balProvider" name="initial_balance" :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('initial_balance')" required :error="v.errors[0]" class="pxal__gap">
              <template #default="{ id }"><px-input :id="id" v-model="account.initial_balance" :placeholder="$t('Enter_initial_balance')" @input="v.validate" /></template>
            </px-field>
          </validation-provider>

          <px-field :label="$t('Details')" class="pxal__gap">
            <template #default="{ id }"><px-textarea :id="id" v-model="account.note" :rows="4" :placeholder="$t('Afewwords')" /></template>
          </px-field>

          <div class="pxal__actionbar">
            <px-button variant="secondary" type="button" @click="modalOpen = false">{{ $t('Cancel') }}</px-button>
            <px-button variant="primary" type="submit" icon="check" :loading="SubmitProcessing">{{ $t('submit') }}</px-button>
          </div>
        </b-form>
      </px-modal>
    </validation-observer>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
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
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  metaInfo: {
    title: "Account"
  },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab,
    PxField, PxInput, PxTextarea, PxModal, PxEmptyState
  },
  data() {
    return {
      _searchTimer: null,
      isLoading: true,
      SubmitProcessing:false,
      modalOpen: false,
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
      accounts: [],
      editmode: false,

      price_format_key: null,

      account: {
        id: "",
        account_num: "",
        account_name: "",
        initial_balance: 0,
        note: ""
      }
    };
  },
  computed: {
    ...mapGetters(["currentUser"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    rowActions() {
      return [
        { key: "edit", label: this.$t("Edit"), icon: "pencil" },
        { key: "delete", label: this.$t("Del"), icon: "x", tone: "danger" }
      ];
    },
    columns() {
      return [
        { key: "account_num", label: this.$t("account_num"), sortable: true, strong: true },
        { key: "account_name", label: this.$t("account_name"), sortable: true },
        { key: "balance", label: this.$t("balance"), align: "right", sortable: true },
        { key: "note", label: this.$t("notes") }
      ];
    }
  },

  methods: {
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
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

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Accounts(1); }, 350);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Accounts(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Accounts(1); } },

    onSort({ key, dir }) {
      this.updateParams({ sort: { type: dir, field: key } });
      this.Get_Accounts(this.serverParams.page);
    },

    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.Edit_Account(row);
      else if (k === "delete") this.Remove_Account(row.id);
    },

    //---- Validation State Form
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    syncValidators() {
      this.$nextTick(() => {
        if (this.$refs.numProvider) this.$refs.numProvider.syncValue(this.account.account_num);
        if (this.$refs.nameProvider) this.$refs.nameProvider.syncValue(this.account.account_name);
        if (this.$refs.balProvider) this.$refs.balProvider.syncValue(this.account.initial_balance);
      });
    },

    //------------- Submit Validation Create & Edit account
    Submit_Account() {
      this.$refs.Create_Account.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          if (!this.editmode) {
            this.Create_Account();
          } else {
            this.Update_Category();
          }
        }
      });
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    //------------------------------ Modal  (create account) -------------------------------\\
    New_Account() {
      this.reset_Form();
      this.editmode = false;
      this.modalOpen = true;
      this.syncValidators();
    },

    //------------------------------ Modal (Update account) -------------------------------\\
    Edit_Account(account) {
      this.Get_Accounts(this.serverParams.page);
      this.reset_Form();
      this.account = account;
      this.editmode = true;
      this.modalOpen = true;
      this.syncValidators();
    },

    //--------------------------Get ALL Categories & Sub account ---------------------------\\

    Get_Accounts(page) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "accounts?page=" +
            page +
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
          this.accounts = response.data.accounts;
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

    //----------------------------------Create new account ----------------\\
    Create_Account() {
      this.SubmitProcessing = true;
      axios
        .post("accounts", {
          account_num: this.account.account_num,
          account_name: this.account.account_name,
          initial_balance: this.account.initial_balance,
          note: this.account.note,
        })
        .then(response => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Account");
          this.makeToast(
            "success",
            this.$t("Created_in_successfully"),
            this.$t("Success")
          );
        })
        .catch(error => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    //---------------------------------- Update account ----------------\\
    Update_Category() {
      this.SubmitProcessing = true;
      axios
        .put("accounts/" + this.account.id, {
          account_num: this.account.account_num,
          account_name: this.account.account_name,
          note: this.account.note,
        })
        .then(response => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Account");
          this.makeToast(
            "success",
            this.$t("Updated_in_successfully"),
            this.$t("Success")
          );
        })
        .catch(error => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    //--------------------------- reset Form ----------------\\

    reset_Form() {
      this.account = {
        id: "",
        account_num: "",
        account_name: "",
        initial_balance: 0,
        note: "",
      };
    },

    //--------------------------- Remove account----------------\\
    Remove_Account(id) {
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
          axios
            .delete("accounts/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );

              Fire.$emit("Event_delete_Account");
            })
            .catch(() => {
              this.$swal(
                this.$t("Delete_Failed"),
                this.$t("Delete_Therewassomethingwronge"),
                "warning"
              );
            });
        }
      });
    },


  }, //end Methods

  //----------------------------- Created function-------------------

  created: function() {
    this.Get_Accounts(1);

    Fire.$on("Event_Account", () => {
      setTimeout(() => {
        this.Get_Accounts(this.serverParams.page);
        this.modalOpen = false;
      }, 500);
    });

    Fire.$on("Event_delete_Account", () => {
      setTimeout(() => {
        this.Get_Accounts(this.serverParams.page);
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxal { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxal { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxal__pad { padding: var(--pxn-space-6) 0; }
.pxal__tablewrap { margin-top: var(--pxn-space-5); }
.pxal__gap { margin-top: var(--pxn-space-5); }
.pxal__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
</style>
