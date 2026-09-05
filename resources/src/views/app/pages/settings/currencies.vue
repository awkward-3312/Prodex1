<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('Currencies')"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('Currencies') }]"
    >
      <template #actions>
        <px-button variant="primary" size="sm" icon="plus" @click="New_Currency()">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="selectedIds.length" class="pxcfg__bulk">
      <span>{{ selectedIds.length }} {{ $t('selected') }}</span>
      <px-button variant="danger" size="sm" icon="trash-2" @click="delete_by_selected()">{{ $t('Del') }}</px-button>
    </div>

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="table" :rows="10" :columns="4" />
    </div>

    <template v-else>
      <div class="pxcfg__tablewrap">
        <px-table
          v-if="currencies.length"
          :columns="columns"
          :rows="currencies"
          row-key="id"
          selectable
          :selected.sync="selectedIds"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>
        <px-empty-state v-else icon="coins" title="Sin monedas" description="Agrega una moneda para verla en esta lista." />
      </div>

      <px-pagination
        v-if="currencies.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <px-modal v-model="modalOpen" :title="editmode ? $t('Edit') : $t('Add')" size="md">
      <validation-observer ref="Create_Currency">
        <form @submit.prevent="Submit_Currency">
          <div class="pxcfg__formgrid">
            <validation-provider ref="codeProvider" name="Code Currency" :rules="{ required: true, min: 2, max: 5 }" v-slot="v">
              <px-field :label="$t('CurrencyCode') + ' *'" :error="v.errors[0]">
                <template #default="{ id, invalid }">
                  <px-input :id="id" v-model="currency.code" :placeholder="$t('Enter_Code_Currency')" :invalid="invalid" @input="v.validate" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider ref="nameProvider" name="Name Currency" :rules="{ required: true, min: 3 }" v-slot="v">
              <px-field :label="$t('CurrencyName') + ' *'" :error="v.errors[0]">
                <template #default="{ id, invalid }">
                  <px-input :id="id" v-model="currency.name" :placeholder="$t('Enter_name_Currency')" :invalid="invalid" @input="v.validate" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider ref="symbolProvider" name="Symbole Currency" :rules="{ required: true, max: 5 }" v-slot="v">
              <px-field :label="$t('Symbol') + ' *'" :error="v.errors[0]">
                <template #default="{ id, invalid }">
                  <px-input :id="id" v-model="currency.symbol" :placeholder="$t('Enter_Symbol_Currency')" :invalid="invalid" @input="v.validate" />
                </template>
              </px-field>
            </validation-provider>
          </div>
        </form>
      </validation-observer>
      <template #footer="{ close }">
        <px-button variant="ghost" @click="close">{{ $t('Cancel') }}</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" :disabled="SubmitProcessing" @click="Submit_Currency">{{ $t('submit') }}</px-button>
      </template>
    </px-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  metaInfo: {
    title: "Currency"
  },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab, PxModal,
    PxField, PxInput, PxEmptyState
  },
  data() {
    return {
      _searchTimer: null,
      modalOpen: false,
      isLoading: true,
      SubmitProcessing: false,
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
      currencies: [],
      editmode: false,
      currency: {
        id: "",
        name: "",
        code: "",
        symbol: ""
      }
    };
  },

  computed: {
    rowActions() {
      return [
        { key: "edit", label: this.$t("Edit"), icon: "pencil" },
        { key: "delete", label: this.$t("Delete"), icon: "trash-2", tone: "danger" }
      ];
    },
    columns() {
      return [
        { key: "code", label: this.$t("CurrencyCode"), strong: true },
        { key: "name", label: this.$t("CurrencyName") },
        { key: "symbol", label: this.$t("Symbol") }
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
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Currency(1); }, 350);
    },
    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Currency(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Currency(1); } },
    onSort({ key, dir }) {
      this.updateParams({ sort: { type: dir, field: key } });
      this.Get_Currency(this.serverParams.page);
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.Edit_Currency(row);
      else if (k === "delete") this.Remove_Currency(row.id);
    },

    syncValidators() {
      this.$nextTick(() => {
        ["codeProvider", "nameProvider", "symbolProvider"].forEach(ref => {
          const p = this.$refs[ref];
          if (p && p.syncValue) {
            const map = { codeProvider: "code", nameProvider: "name", symbolProvider: "symbol" };
            p.syncValue(this.currency[map[ref]]);
          }
        });
      });
    },

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    Submit_Currency() {
      this.$refs.Create_Currency.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          if (!this.editmode) {
            this.Create_Currency();
          } else {
            this.Update_Currency();
          }
        }
      });
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    New_Currency() {
      this.reset_Form();
      this.editmode = false;
      this.modalOpen = true;
      this.syncValidators();
    },

    Edit_Currency(currency) {
      this.Get_Currency(this.serverParams.page);
      this.reset_Form();
      this.currency = Object.assign({}, currency);
      this.editmode = true;
      this.modalOpen = true;
      this.syncValidators();
    },

    Get_Currency(page) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "currencies?page=" +
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
          this.currencies = response.data.currencies;
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

    Create_Currency() {
      this.SubmitProcessing = true;
      axios
        .post("currencies", {
          name: this.currency.name,
          code: this.currency.code,
          symbol: this.currency.symbol
        })
        .then(response => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Currency");
          this.makeToast(
            "success",
            this.$t("Successfully_Created"),
            this.$t("Success")
          );
        })
        .catch(error => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Currency() {
      this.SubmitProcessing = true;
      axios
        .put("currencies/" + this.currency.id, {
          name: this.currency.name,
          code: this.currency.code,
          symbol: this.currency.symbol
        })
        .then(response => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Currency");
          this.makeToast(
            "success",
            this.$t("Successfully_Updated"),
            this.$t("Success")
          );
        })
        .catch(error => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.currency = {
        id: "",
        name: "",
        code: "",
        symbol: ""
      };
    },

    Remove_Currency(id) {
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
            .delete("currencies/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              Fire.$emit("Delete_Currency");
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
            .post("currencies/delete/by_selection", {
              selectedIds: this.selectedIds
            })
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              Fire.$emit("Delete_Currency");
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
    }
  }, //end Methods

  created: function() {
    this.Get_Currency(1);

    Fire.$on("Event_Currency", () => {
      setTimeout(() => {
        this.Get_Currency(this.serverParams.page);
        this.modalOpen = false;
      }, 500);
    });

    Fire.$on("Delete_Currency", () => {
      setTimeout(() => {
        this.selectedIds = [];
        this.Get_Currency(this.serverParams.page);
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__tablewrap { margin-top: var(--pxn-space-5); }
.pxcfg__bulk { display: flex; align-items: center; gap: var(--pxn-space-4); margin-top: var(--pxn-space-4); padding: var(--pxn-space-3) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); }
.pxcfg__bulk > span { margin-right: auto; font-weight: var(--pxn-fw-semibold); }
.pxcfg__formgrid { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--pxn-space-4); }
</style>
