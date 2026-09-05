<template>
  <div class="px-next pxfl">
    <px-page-header :title="$t('Expense_Category')" :breadcrumbs="[{ label: $t('Expenses') }, { label: $t('Expense_Category') }]">
      <template #actions>
        <px-button
          v-if="currentUserPermissions && currentUserPermissions.includes('expense_add')"
          variant="primary" icon="plus" @click="New_Category"
        >{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxfl__pad">
      <px-skeleton variant="table" :rows="8" :columns="3" />
    </div>

    <template v-else>
      <div class="pxfl__tablewrap">
        <px-table
          v-if="categories.length"
          :columns="columns"
          :rows="categories"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          :has-row-actions="canRowActions"
          @sort="onSort"
        >
          <template #cell-description="{ row }">
            <span v-if="row.description">{{ row.description }}</span>
            <span v-else class="pxfl__muted">—</span>
          </template>
          <template #row-actions="{ row }">
            <px-kebab v-if="canRowActions" :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state
          v-else
          icon="folder"
          :title="$t('No_expense_categories_yet') || 'Sin categorías de gasto todavía'"
          :description="$t('No_expense_categories_desc') || 'Crea una categoría para clasificar tus gastos.'"
        >
          <px-button
            v-if="currentUserPermissions && currentUserPermissions.includes('expense_add')"
            variant="primary" icon="plus" size="sm" @click="New_Category"
          >{{ $t('Add') }}</px-button>
        </px-empty-state>
      </div>

      <px-pagination
        v-if="categories.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <validation-observer ref="Create_Category">
      <px-modal v-model="modalOpen" size="md" :title="editmode ? $t('Edit') : $t('Add')">
        <b-form @submit.prevent="Submit_Category">
          <validation-provider ref="nameProvider" name="Name category" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Namecategorie')" required :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model="category.name" @input="v.validate" /></template>
            </px-field>
          </validation-provider>

          <px-field :label="$t('Description')" class="pxfl__gap">
            <template #default="{ id }"><px-textarea :id="id" v-model="category.description" :rows="4" :placeholder="$t('Afewwords')" /></template>
          </px-field>

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
    title: "Expense Category"
  },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab,
    PxField, PxInput, PxTextarea, PxModal, PxEmptyState
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
      selectedIds: [],
      totalRows: "",
      search: "",
      categories: [],
      editmode: false,
      limit: "10",
      category: {
        id: "",
        name: "",
        description: ""
      }
    };
  },

  computed: {
    ...mapGetters(["currentUserPermissions"]),
    canRowActions() {
      const p = this.currentUserPermissions || [];
      return p.includes("expense_edit") || p.includes("expense_delete");
    },
    rowActions() {
      const p = this.currentUserPermissions || [];
      const items = [];
      if (p.includes("expense_edit")) items.push({ key: "edit", label: this.$t("Edit"), icon: "pencil" });
      if (p.includes("expense_delete")) items.push({ key: "delete", label: this.$t("Del"), icon: "x", tone: "danger" });
      return items;
    },
    columns() {
      return [
        { key: "name", label: this.$t("Namecategorie"), sortable: true, strong: true },
        { key: "description", label: this.$t("Description") }
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
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Categories(1); }, 350);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Categories(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Categories(1); } },

    onSort({ key, dir }) {
      this.updateParams({ sort: { type: dir, field: key } });
      this.Get_Categories(this.serverParams.page);
    },

    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.Edit_Category(row);
      else if (k === "delete") this.Delete_Category(row.id);
    },

    syncValidators() {
      this.$nextTick(() => {
        if (this.$refs.nameProvider) this.$refs.nameProvider.syncValue(this.category.name);
      });
    },

    //------------- Submit Validation Create & Edit Category
    Submit_Category() {
      this.$refs.Create_Category.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          if (!this.editmode) {
            this.Create_Category();
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

    //------ Event Validation State
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    //--------------------------Show Modal (new Category) ----------------\\
    New_Category() {
      this.reset_Form();
      this.editmode = false;
      this.modalOpen = true;
      this.syncValidators();
    },

    //-------------------------- Show Modal (Edit Category) ----------------\\
    Edit_Category(cat) {
      this.Get_Categories(this.serverParams.page);
      this.reset_Form();
      this.category = cat;
      this.editmode = true;
      this.modalOpen = true;
      this.syncValidators();
    },

    //--------------------------- reset Form ----------------\\
    reset_Form() {
      this.category = {
        id: "",
        name: "",
        description: ""
      };
    },

    //--------------------------Get ALL Categories ---------------------------\\
    Get_Categories(page) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "expenses_category?page=" +
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
          this.categories = response.data.Expenses_category;
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

    //----------------------------------Create new Category ----------------\\
    Create_Category() {
      this.SubmitProcessing = true;
      axios
        .post("expenses_category", {
          name: this.category.name,
          description: this.category.description
        })
        .then(response => {
          Fire.$emit("Create_Category_Expense");

          this.makeToast(
            "success",
            this.$t("Successfully_Created"),
            this.$t("Success")
          );
          this.SubmitProcessing = false;
        })
        .catch(error => {
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
          this.SubmitProcessing = false;
        });
    },

    //---------------------------------- Update Category ----------------\\
    Update_Category() {
      this.SubmitProcessing = true;
      axios
        .put("expenses_category/" + this.category.id, {
          name: this.category.name,
          description: this.category.description
        })
        .then(response => {
          Fire.$emit("Create_Category_Expense");

          this.makeToast(
            "success",
            this.$t("Successfully_Updated"),
            this.$t("Success")
          );
          this.SubmitProcessing = false;
        })
        .catch(error => {
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
          this.SubmitProcessing = false;
        });
    },

    //--------------------------- Delete Category----------------\\
    Delete_Category(id) {
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
            .delete("expenses_category/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );

              Fire.$emit("Delete_Category_Expense");
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
    this.Get_Categories(1);

    Fire.$on("Create_Category_Expense", () => {
      this.Get_Categories(this.serverParams.page);
      this.modalOpen = false;
    });

    Fire.$on("Delete_Category_Expense", () => {
      this.Get_Categories(this.serverParams.page);
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
.pxfl__muted { color: var(--pxn-ink-3); }
.pxfl__gap { margin-top: var(--pxn-space-5); }
.pxfl__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
</style>
