<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('Payment_Methods')"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('Payment_Methods') }]"
    >
      <template #actions>
        <px-button variant="primary" size="sm" icon="plus" @click="New_Method()">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="table" :rows="8" :columns="2" />
    </div>

    <template v-else>
      <div class="pxcfg__tablewrap">
        <px-table
          v-if="methods.length"
          :columns="columns"
          :rows="methods"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #row-actions="{ row }">
            <span v-if="[1, 2, 3].includes(row.id)" class="pxcfg__warn">{{ $t('You_cant_edit_or_remove_default_payment_choices') }}</span>
            <div v-else class="pxcfg__rowbtns">
              <px-button variant="ghost" size="sm" icon-only icon="pencil" aria-label="Edit" @click="Edit_Method(row)" />
              <px-button class="pxcfg__del" variant="ghost" size="sm" icon-only icon="trash-2" aria-label="Delete" @click="Remove_Method(row.id)" />
            </div>
          </template>
        </px-table>
        <px-empty-state v-else icon="credit-card" title="Sin métodos de pago" description="Agrega un método para verlo en esta lista." />
      </div>

      <px-pagination
        v-if="methods.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <px-modal v-model="modalOpen" :title="editmode ? $t('Edit') : $t('Add')" size="md">
      <validation-observer ref="ref_create_method">
        <form @submit.prevent="Submit_method">
          <validation-provider ref="nameProvider" name="Name" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Name') + ' *'" :error="v.errors[0]">
              <template #default="{ id, invalid }">
                <px-input :id="id" v-model="method.name" :placeholder="$t('Enter_Payment_Method')" :invalid="invalid" @input="v.validate" />
              </template>
            </px-field>
          </validation-provider>
        </form>
      </validation-observer>
      <template #footer="{ close }">
        <px-button variant="ghost" @click="close">{{ $t('Cancel') }}</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" :disabled="SubmitProcessing" @click="Submit_method">{{ $t('submit') }}</px-button>
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
import PxModal from "@/components/px-next/PxModal.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  metaInfo: {
    title: "Payment Methods"
  },
  components: { PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxModal, PxField, PxInput, PxEmptyState },
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
      totalRows: "",
      search: "",
      limit: "10",
      methods: [],
      editmode: false,
      method: {
        id: "",
        name: "",
      }
    };
  },
  computed: {
    columns() {
      return [
        { key: "name", label: "Payment Method", strong: true }
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
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.get_methods(1); }, 350);
    },
    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.get_methods(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.get_methods(1); } },
    onSort({ key, dir }) {
      this.updateParams({ sort: { type: dir, field: key } });
      this.get_methods(this.serverParams.page);
    },

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    syncValidators() {
      this.$nextTick(() => {
        const p = this.$refs.nameProvider;
        if (p && p.syncValue) p.syncValue(this.method.name);
      });
    },

    Submit_method() {
      this.$refs.ref_create_method.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          if (!this.editmode) {
            this.Store_method();
          } else {
            this.Update_method();
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

    New_Method() {
      this.reset_Form();
      this.editmode = false;
      this.modalOpen = true;
      this.syncValidators();
    },

    Edit_Method(method) {
      this.get_methods(this.serverParams.page);
      this.reset_Form();
      this.method = Object.assign({}, method);
      this.editmode = true;
      this.modalOpen = true;
      this.syncValidators();
    },

    get_methods(page) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "payment_methods?page=" +
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
          this.methods = response.data.methods;
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

    Store_method() {
      this.SubmitProcessing = true;
      axios
        .post("payment_methods", {
          name: this.method.name,
        })
        .then(response => {
          this.SubmitProcessing = false;
          Fire.$emit("event_method");
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

    Update_method() {
      this.SubmitProcessing = true;
      axios
        .put("payment_methods/" + this.method.id, {
          name: this.method.name,
        })
        .then(response => {
          this.SubmitProcessing = false;
          Fire.$emit("event_method");
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
      this.method = {
        id: "",
        name: "",
      };
    },

    Remove_Method(id) {
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
            .delete("payment_methods/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              Fire.$emit("event_delete_method");
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

  created: function() {
    this.get_methods(1);

    Fire.$on("event_method", () => {
      setTimeout(() => {
        this.get_methods(this.serverParams.page);
        this.modalOpen = false;
      }, 500);
    });

    Fire.$on("event_delete_method", () => {
      setTimeout(() => {
        this.get_methods(this.serverParams.page);
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
.pxcfg__rowbtns { display: flex; gap: var(--pxn-space-2); justify-content: flex-end; }
.pxcfg__del ::v-deep .pxn-btn__icon { color: var(--pxn-danger); }
.pxcfg__warn { font-size: var(--pxn-fs-xs); color: var(--pxn-warning); }
</style>
