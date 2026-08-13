<template>
  <div class="main-content">
    <breadcumb :page="$t('Job_Categories')" :folder="$t('Recruit')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="categories"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-sort-change="onSortChange"
        @on-search="onSearch"
        :search-options="{ enabled: true, placeholder: $t('Search_this_table') }"
        :select-options="{ enabled: true, clearSelectionText: '' }"
        @on-selected-rows-change="selectionChanged"
        :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
        styleClass="table-hover tableOne vgt-table"
      >
        <div slot="selected-row-actions">
          <button class="btn btn-danger btn-sm" @click="delete_by_selected()">{{ $t('Del') }}</button>
        </div>
        <div slot="table-actions" class="mt-2 mb-3">
          <b-button @click="New_Category()" class="btn-rounded" variant="btn btn-primary btn-icon m-1">
            <lucide-icon name="plus" /> {{ $t('Add') }}
          </b-button>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'is_active'">
            <span class="badge" :class="props.row.is_active ? 'badge-outline-success' : 'badge-outline-secondary'">
              {{ props.row.is_active ? $t('Active') : $t('Inactive') }}
            </span>
          </span>
          <span v-else-if="props.column.field == 'actions'">
            <a @click="Edit_Category(props.row)" class="cursor-pointer" title="Edit" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-success" name="pencil" />
            </a>
            <a title="Delete" v-b-tooltip.hover class="cursor-pointer" @click="Remove_Category(props.row.id)">
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>
        </template>
      </vue-good-table>
    </b-card>

    <validation-observer ref="Create_Category">
      <b-modal hide-footer size="md" id="New_Category" :title="editmode ? $t('Edit') : $t('Add')">
        <b-form @submit.prevent="Submit_Category">
          <b-row>
            <b-col md="12">
              <validation-provider name="name" :rules="{ required: true }" v-slot="validationContext">
                <b-form-group :label="$t('Category_Name') + ' *'">
                  <b-form-input
                    :placeholder="$t('Category_Name')"
                    :state="getValidationState(validationContext)"
                    v-model="category.name"
                  ></b-form-input>
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <b-col md="12">
              <b-form-group :label="$t('Description')">
                <b-form-textarea v-model="category.description" rows="3"></b-form-textarea>
              </b-form-group>
            </b-col>

            <b-col md="12">
              <b-form-checkbox v-model="category.is_active" :value="true" :unchecked-value="false">
                {{ $t('Active') }}
              </b-form-checkbox>
            </b-col>

            <b-col md="12" class="mt-3">
              <b-button variant="primary" type="submit" :disabled="SubmitProcessing">
                <lucide-icon class="me-2 font-weight-bold" name="check" /> {{ $t('submit') }}
              </b-button>
              <div v-once class="typo__p" v-if="SubmitProcessing">
                <div class="spinner sm spinner-primary mt-3"></div>
              </div>
            </b-col>
          </b-row>
        </b-form>
      </b-modal>
    </validation-observer>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Job Categories" },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      serverParams: {
        columnFilters: {},
        sort: { field: "id", type: "desc" },
        page: 1,
        perPage: 10
      },
      selectedIds: [],
      totalRows: "",
      search: "",
      limit: "10",
      editmode: false,
      categories: [],
      category: { id: "", name: "", description: "", is_active: true }
    };
  },

  computed: {
    columns() {
      return [
        { label: this.$t("Category_Name"), field: "name", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Jobs"), field: "jobs_count", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Status"), field: "is_active", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Action"), field: "actions", tdClass: "text-left", thClass: "text-left", sortable: false }
      ];
    }
  },

  methods: {
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) {
        this.updateParams({ page: currentPage });
        this.Get_Categories(currentPage);
      }
    },
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.updateParams({ page: 1, perPage: currentPerPage });
        this.Get_Categories(1);
      }
    },
    selectionChanged({ selectedRows }) {
      this.selectedIds = [];
      selectedRows.forEach(row => this.selectedIds.push(row.id));
    },
    onSortChange(params) {
      this.updateParams({ sort: { type: params[0].type, field: params[0].field } });
      this.Get_Categories(this.serverParams.page);
    },
    onSearch(value) {
      this.search = value.searchTerm;
      this.Get_Categories(this.serverParams.page);
    },
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },

    Submit_Category() {
      this.$refs.Create_Category.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) this.Create_Category();
          else this.Update_Category();
        }
      });
    },

    New_Category() {
      this.reset_Form();
      this.editmode = false;
      this.$bvModal.show("New_Category");
    },

    Edit_Category(category) {
      this.reset_Form();
      this.category = { ...category, is_active: !!category.is_active };
      this.editmode = true;
      this.$bvModal.show("New_Category");
    },

    Get_Categories(page) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "recruit/categories?page=" + page +
          "&SortField=" + this.serverParams.sort.field +
          "&SortType=" + this.serverParams.sort.type +
          "&search=" + this.search +
          "&limit=" + this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.categories = response.data.categories;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Create_Category() {
      this.SubmitProcessing = true;
      axios
        .post("recruit/categories", {
          name: this.category.name,
          description: this.category.description,
          is_active: this.category.is_active
        })
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Category");
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Category() {
      this.SubmitProcessing = true;
      axios
        .put("recruit/categories/" + this.category.id, {
          name: this.category.name,
          description: this.category.description,
          is_active: this.category.is_active
        })
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Category");
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.category = { id: "", name: "", description: "", is_active: true };
    },

    Remove_Category(id) {
      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios
            .delete("recruit/categories/" + id)
            .then(() => {
              this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
              Fire.$emit("Event_Category");
            })
            .catch(() => {
              this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
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
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios
            .post("recruit/categories/delete/by_selection", { selectedIds: this.selectedIds })
            .then(() => {
              this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
              Fire.$emit("Event_Category");
            })
            .catch(() => {
              this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
            });
        }
      });
    }
  },

  created: function () {
    this.Get_Categories(1);
    Fire.$on("Event_Category", () => {
      setTimeout(() => {
        this.Get_Categories(this.serverParams.page);
        this.$bvModal.hide("New_Category");
      }, 500);
    });
  }
};
</script>
