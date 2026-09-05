<template>
  <div class="px-next pxfl">
    <px-page-header :title="$t('Transfers_Money')" :breadcrumbs="[{ label: $t('Accounting') }, { label: $t('Transfers_Money') }]">
      <template #actions>
        <px-button variant="primary" icon="plus" @click="New_Transfer">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxfl__pad">
      <px-skeleton variant="table" :rows="8" :columns="5" />
    </div>

    <template v-else>
      <div class="pxfl__tablewrap">
        <px-table
          v-if="transfers.length"
          :columns="columns"
          :rows="transfers"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-date="{ row }">{{ formatDisplayDate(row.date) }}</template>
          <template #cell-amount="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser.currency, row.amount, priceDecimals) }}</span></template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state
          v-else
          icon="arrow-left-right"
          :title="$t('No_transfers_yet') || 'Sin transferencias todavía'"
          :description="$t('No_transfers_desc') || 'Cuando registres una transferencia entre cuentas, aparecerá aquí.'"
        >
          <px-button variant="primary" icon="plus" size="sm" @click="New_Transfer">{{ $t('Add') }}</px-button>
        </px-empty-state>
      </div>

      <px-pagination
        v-if="transfers.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <validation-observer ref="Create_transfer_money">
      <px-modal v-model="modalOpen" size="md" :title="editmode ? $t('Edit') : $t('Add')">
        <b-form @submit.prevent="Submit_transfer_money">
          <div class="pxfl__formgrid">
            <validation-provider ref="dateProvider" name="date" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('date')" required :error="v.errors[0]">
                <template #default="{ id }">
                  <px-input :id="id" type="date" v-model="transfer.date" @input="v.validate" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider ref="amountProvider" name="Amount" :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="v">
              <px-field :label="$t('Amount')" required :error="v.errors[0]">
                <template #default="{ id }">
                  <px-input :id="id" type="text" v-model.number="transfer.amount" :placeholder="$t('Amount')" @input="v.validate" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider v-if="!editmode" ref="fromProvider" name="From_Account" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('From_Account')" required :error="v.errors[0]" :class="{ 'is-invalid': !!v.errors.length }">
                <template #default="{ id }">
                  <vs-px :input-id="id" v-model="transfer.from_account_id" :reduce="o => o.value" :invalid="!!v.errors.length"
                    :placeholder="$t('Choose_Account')"
                    :options="accounts.map(a => ({ label: a.account_name, value: a.id }))"
                    @input="v.validate" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider v-if="!editmode" ref="toProvider" name="To_Account" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('To_Account')" required :error="v.errors[0]" :class="{ 'is-invalid': !!v.errors.length }">
                <template #default="{ id }">
                  <vs-px :input-id="id" v-model="transfer.to_account_id" :reduce="o => o.value" :invalid="!!v.errors.length"
                    :placeholder="$t('Choose_Account')"
                    :options="accounts.map(a => ({ label: a.account_name, value: a.id }))"
                    @input="v.validate" />
                </template>
              </px-field>
            </validation-provider>
          </div>

          <div class="pxfl__actionbar">
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
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "Transfer Money"
  },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab,
    PxField, PxInput, PxModal, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      _searchTimer: null,
      isLoading: true,
      SubmitProcessing: false,
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
      totalRows: "",
      search: "",
      limit: "10",
      transfers: [],
      accounts: [],
      editmode: false,
      price_format_key: null,

      transfer: {
        id: "",
        from_account_id: "",
        to_account_id: "",
        amount: "",
        date: new Date().toISOString().slice(0, 10),
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
        { key: "date", label: this.$t("date"), sortable: true },
        { key: "from_account", label: this.$t("From_Account"), sortable: true },
        { key: "to_account", label: this.$t("To_Account"), sortable: true },
        { key: "amount", label: this.$t("Amount"), align: "right", sortable: true }
      ];
    }
  },

  methods: {
    //---- update Params Table
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.get_transfers_money(1); }, 350);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.get_transfers_money(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.get_transfers_money(1); } },

    onSort({ key, dir }) {
      this.updateParams({ sort: { type: dir, field: key } });
      this.get_transfers_money(this.serverParams.page);
    },

    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.Edit_transfer_money(row);
      else if (k === "delete") this.Remove_transfers_money(row.id);
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

    //---- Validation State Form
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    syncValidators() {
      this.$nextTick(() => {
        const map = {
          dateProvider: this.transfer.date,
          amountProvider: this.transfer.amount,
          fromProvider: this.transfer.from_account_id,
          toProvider: this.transfer.to_account_id
        };
        Object.keys(map).forEach(ref => {
          if (this.$refs[ref] && typeof this.$refs[ref].syncValue === "function") {
            this.$refs[ref].syncValue(map[ref]);
          }
        });
      });
    },

    //------------- Submit Validation
    Submit_transfer_money() {
      this.$refs.Create_transfer_money.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          if (!this.editmode) {
            this.Create_transfer_money();
          } else {
            this.Update_transfers_money();
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

    //------------------------------ Modal  (create transfer) -------------------------------\\
    New_Transfer() {
      this.reset_Form();
      this.editmode = false;
      this.modalOpen = true;
      this.syncValidators();
    },

    //------------------------------ Modal (Update transfer) -------------------------------\\
    Edit_transfer_money(transfer) {
      this.get_transfers_money(this.serverParams.page);
      this.reset_Form();
      this.transfer = transfer;
      this.editmode = true;
      this.modalOpen = true;
      this.syncValidators();
    },

    //--------------------------Get ALL Categories & Sub account ---------------------------\\

    get_transfers_money(page) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "transfer_money?page=" +
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
          this.transfers = response.data.transfers;
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

    //----------------------------------Create new transfer ----------------\\
    Create_transfer_money() {
      if (this.transfer.from_account_id === this.transfer.to_account_id) {
        this.makeToast("danger", this.$t("Accounts_cannot_be_the_same"), this.$t("Failed"));
        return;
      }

      this.SubmitProcessing = true;
      axios
        .post("transfer_money", {
          from_account_id: this.transfer.from_account_id,
          to_account_id: this.transfer.to_account_id,
          amount: this.transfer.amount,
          date: this.transfer.date,
        })
        .then(response => {
          this.SubmitProcessing = false;
          Fire.$emit("event_transfers_money");
          this.makeToast(
            "success",
            this.$t("Created_in_successfully"),
            this.$t("Success")
          );
        })
        .catch(error => {
          this.SubmitProcessing = false;
          this.makeToast("danger", error.error, this.$t("Failed"));
        });
    },

    //---------------------------------- Update transfer ----------------\\
    Update_transfers_money() {
      this.SubmitProcessing = true;
      axios
        .put("transfer_money/" + this.transfer.id, {
          from_account_id: this.transfer.from_account_id,
          to_account_id: this.transfer.to_account_id,
          amount: this.transfer.amount,
          date: this.transfer.date,
        })
        .then(response => {
          this.SubmitProcessing = false;
          Fire.$emit("event_transfers_money");
          this.makeToast(
            "success",
            this.$t("Updated_in_successfully"),
            this.$t("Success")
          );
        })
        .catch(error => {
          this.SubmitProcessing = false;
          this.makeToast("danger", error.error, this.$t("Failed"));
        });
    },

    //--------------------------- reset Form ----------------\\

    reset_Form() {
      this.transfer = {
        id: "",
        from_account_id: "",
        to_account_id: "",
        amount: "",
        date: new Date().toISOString().slice(0, 10),
      };
    },

    //--------------------------- Remove transfer----------------\\
    Remove_transfers_money(id) {
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
            .delete("transfer_money/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );

              Fire.$emit("Event_delete_transfer");
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
    this.get_transfers_money(1);

    Fire.$on("event_transfers_money", () => {
      setTimeout(() => {
        this.get_transfers_money(this.serverParams.page);
        this.modalOpen = false;
      }, 500);
    });

    Fire.$on("Event_delete_transfer", () => {
      setTimeout(() => {
        this.get_transfers_money(this.serverParams.page);
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
.pxfl__tablewrap { margin-top: var(--pxn-space-5); }
.pxfl__formgrid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
@media (max-width: 520px) { .pxfl__formgrid { grid-template-columns: minmax(0, 1fr); } }
.pxfl__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
.pxfl ::v-deep .pxn-field.is-invalid .vs__dropdown-toggle { border-color: var(--pxn-danger); }
</style>
