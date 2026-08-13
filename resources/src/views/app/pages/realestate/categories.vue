<template>
  <div class="main-content">
    <breadcumb :page="$t('Property_Categories')" :folder="$t('Real_Estate')" />

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
        :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
        styleClass="table-hover tableOne vgt-table"
      >
        <div slot="table-actions" class="mt-2 mb-3">
          <b-button @click="New_Category()" class="btn-rounded" variant="btn btn-primary btn-icon m-1">
            <lucide-icon name="plus" /> {{ $t('Add_Category') }}
          </b-button>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'image'">
            <img v-if="props.row.image" :src="'/' + props.row.image" class="rounded" style="width:48px;height:38px;object-fit:cover" />
            <span v-else class="text-muted">—</span>
          </span>
          <span v-else-if="props.column.field == 'properties_count'">
            <span class="badge badge-outline-info">{{ props.row.properties_count }}</span>
          </span>
          <span v-else-if="props.column.field == 'actions'">
            <a @click="Edit_Category(props.row)" class="cursor-pointer" :title="$t('Edit')" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-success" name="pencil" />
            </a>
            <a :title="$t('Delete')" v-b-tooltip.hover class="cursor-pointer" @click="Remove_Category(props.row.id)">
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>
        </template>
      </vue-good-table>
    </b-card>

    <b-modal id="categoryModal" :title="editMode ? $t('Edit_Category') : $t('Add_Category')" hide-footer>
      <b-form @submit.prevent="save">
        <b-form-group :label="$t('Name') + ' *'">
          <b-form-input v-model="form.name" required />
        </b-form-group>
        <b-form-group :label="$t('Description')">
          <b-form-textarea v-model="form.description" rows="3" />
        </b-form-group>
        <b-form-group :label="$t('Image')">
          <div v-if="imagePreview" class="mb-2"><img :src="imagePreview" class="img-fluid rounded" style="max-height:120px" /></div>
          <b-form-file accept="image/*" @change="onImageChange" :placeholder="$t('Choose_file')" />
        </b-form-group>
        <div class="text-right">
          <b-button variant="outline-secondary" @click="$bvModal.hide('categoryModal')">{{ $t('Cancel') }}</b-button>
          <b-button variant="primary" type="submit" :disabled="saving">{{ editMode ? $t('Update') : $t('Save') }}</b-button>
        </div>
      </b-form>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Property Categories" },
  data() {
    return {
      isLoading: true,
      saving: false,
      editMode: false,
      serverParams: { sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      totalRows: "",
      search: "",
      limit: "10",
      categories: [],
      imageFile: null,
      imagePreview: null,
      form: { id: null, name: "", description: "" }
    };
  },
  computed: {
    columns() {
      return [
        { label: this.$t("Image"), field: "image", sortable: false, tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Name"), field: "name", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Properties"), field: "properties_count", sortable: false, tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Action"), field: "actions", sortable: false, tdClass: "text-left", thClass: "text-left" }
      ];
    }
  },
  methods: {
    makeToast(variant, msg, title) { this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true }); },
    updateParams(p) { this.serverParams = Object.assign({}, this.serverParams, p); },
    onPageChange({ currentPage }) { if (this.serverParams.page !== currentPage) { this.updateParams({ page: currentPage }); this.Get_Categories(currentPage); } },
    onPerPageChange({ currentPerPage }) { if (this.limit !== currentPerPage) { this.limit = currentPerPage; this.updateParams({ page: 1, perPage: currentPerPage }); this.Get_Categories(1); } },
    onSortChange(params) { this.updateParams({ sort: { type: params[0].type, field: params[0].field } }); this.Get_Categories(this.serverParams.page); },
    onSearch(value) { this.search = value.searchTerm; this.Get_Categories(this.serverParams.page); },

    onImageChange(e) {
      const f = e.target.files[0];
      if (!f) return;
      this.imageFile = f;
      this.imagePreview = URL.createObjectURL(f);
    },

    New_Category() {
      this.editMode = false;
      this.form = { id: null, name: "", description: "" };
      this.imageFile = null; this.imagePreview = null;
      this.$bvModal.show("categoryModal");
    },
    Edit_Category(row) {
      this.editMode = true;
      this.form = { id: row.id, name: row.name, description: row.description || "" };
      this.imageFile = null;
      this.imagePreview = row.image ? "/" + row.image : null;
      this.$bvModal.show("categoryModal");
    },

    Get_Categories(page) {
      this.serverParams.page = page || 1;
      NProgress.start(); NProgress.set(0.1);
      axios.get("realestate/categories?page=" + this.serverParams.page +
        "&SortField=" + this.serverParams.sort.field +
        "&SortType=" + this.serverParams.sort.type +
        "&search=" + this.search + "&limit=" + this.limit)
        .then(res => {
          this.totalRows = res.data.totalRows;
          this.categories = res.data.categories;
          NProgress.done(); this.isLoading = false;
        })
        .catch(() => { NProgress.done(); setTimeout(() => { this.isLoading = false; }, 500); });
    },

    save() {
      if (!this.form.name) { this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed")); return; }
      this.saving = true;
      const fd = new FormData();
      fd.append("name", this.form.name);
      if (this.form.description) fd.append("description", this.form.description);
      if (this.imageFile) fd.append("image_file", this.imageFile);
      const url = this.editMode ? "realestate/categories/" + this.form.id : "realestate/categories";
      axios.post(url, fd, { headers: { "Content-Type": "multipart/form-data" } })
        .then(() => {
          this.saving = false;
          this.$bvModal.hide("categoryModal");
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
          this.Get_Categories(this.serverParams.page);
        })
        .catch(() => { this.saving = false; this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed")); });
    },

    Remove_Category(id) {
      this.$swal({
        title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning",
        showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios.delete("realestate/categories/" + id).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            this.Get_Categories(this.serverParams.page);
          }).catch(() => { this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning"); });
        }
      });
    }
  },
  created() { this.Get_Categories(1); }
};
</script>
