<template>
  <div class="main-content">
    <breadcumb :page="$t('Properties')" :folder="$t('Real_Estate')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <b-row class="mb-3">
        <b-col md="3">
          <b-form-group :label="$t('Property_Type')">
            <b-form-select v-model="category_filter" @change="Get_Properties(1)">
              <b-form-select-option value="">{{ $t('All') }}</b-form-select-option>
              <b-form-select-option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</b-form-select-option>
            </b-form-select>
          </b-form-group>
        </b-col>
        <b-col md="3">
          <b-form-group :label="$t('Purpose')">
            <b-form-select v-model="purpose_filter" @change="Get_Properties(1)">
              <b-form-select-option value="">{{ $t('All') }}</b-form-select-option>
              <b-form-select-option value="sale">{{ $t('For_Sale') }}</b-form-select-option>
              <b-form-select-option value="rent">{{ $t('For_Rent') }}</b-form-select-option>
            </b-form-select>
          </b-form-group>
        </b-col>
        <b-col md="3">
          <b-form-group :label="$t('Status')">
            <b-form-select v-model="status_filter" @change="Get_Properties(1)">
              <b-form-select-option value="">{{ $t('All') }}</b-form-select-option>
              <b-form-select-option value="available">{{ $t('Available') }}</b-form-select-option>
              <b-form-select-option value="sold">{{ $t('Sold') }}</b-form-select-option>
              <b-form-select-option value="rented">{{ $t('Rented') }}</b-form-select-option>
            </b-form-select>
          </b-form-group>
        </b-col>
      </b-row>

      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="properties"
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
          <b-button @click="New_Property()" class="btn-rounded" variant="btn btn-primary btn-icon m-1">
            <lucide-icon name="plus" /> {{ $t('Add_Property') }}
          </b-button>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'featured_image'">
            <img v-if="props.row.featured_image" :src="'/' + props.row.featured_image" class="rounded" style="width:54px;height:42px;object-fit:cover" />
            <span v-else class="text-muted">—</span>
          </span>
          <span v-else-if="props.column.field == 'title'">
            <a class="cursor-pointer text-primary font-weight-bold" @click="Edit_Property(props.row.id)">{{ props.row.title }}</a>
            <div class="text-small text-muted">{{ [props.row.city, props.row.region].filter(Boolean).join(', ') }}</div>
          </span>
          <span v-else-if="props.column.field == 'category'">
            {{ props.row.category ? props.row.category.name : '—' }}
          </span>
          <span v-else-if="props.column.field == 'purpose'">
            <span class="badge" :class="props.row.purpose === 'rent' ? 'badge-outline-info' : 'badge-outline-primary'">
              {{ props.row.purpose === 'rent' ? $t('For_Rent') : $t('For_Sale') }}
            </span>
          </span>
          <span v-else-if="props.column.field == 'price'">
            {{ format_price(props.row.price) }}
          </span>
          <span v-else-if="props.column.field == 'featured'">
            <lucide-icon v-if="props.row.featured" name="star" class="text-warning" />
            <span v-else class="text-muted">—</span>
          </span>
          <span v-else-if="props.column.field == 'status'">
            <span class="badge" :class="status_class(props.row.status)">{{ status_label(props.row.status) }}</span>
          </span>
          <span v-else-if="props.column.field == 'actions'">
            <a @click="Edit_Property(props.row.id)" class="cursor-pointer" :title="$t('Edit')" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-success" name="pencil" />
            </a>
            <a :title="$t('Delete')" v-b-tooltip.hover class="cursor-pointer" @click="Remove_Property(props.row.id)">
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>
        </template>
      </vue-good-table>
    </b-card>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Properties" },
  data() {
    return {
      isLoading: true,
      serverParams: { sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      selectedIds: [],
      totalRows: "",
      search: "",
      limit: "10",
      category_filter: "",
      purpose_filter: "",
      status_filter: "",
      categories: [],
      properties: []
    };
  },
  computed: {
    columns() {
      return [
        { label: this.$t("Image"), field: "featured_image", sortable: false, tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Property_Title"), field: "title", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Property_Type"), field: "category", sortable: false, tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Purpose"), field: "purpose", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Price"), field: "price", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Featured"), field: "featured", sortable: false, tdClass: "text-center", thClass: "text-center" },
        { label: this.$t("Status"), field: "status", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Action"), field: "actions", sortable: false, tdClass: "text-left", thClass: "text-left" }
      ];
    }
  },
  methods: {
    format_price(v) { return new Intl.NumberFormat().format(Number(v || 0)); },
    status_label(s) {
      const map = { available: this.$t("Available"), sold: this.$t("Sold"), rented: this.$t("Rented") };
      return map[s] || s;
    },
    status_class(s) {
      const map = { available: "badge-outline-success", sold: "badge-outline-danger", rented: "badge-outline-warning" };
      return map[s] || "badge-outline-secondary";
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },
    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) { this.updateParams({ page: currentPage }); this.Get_Properties(currentPage); }
    },
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) { this.limit = currentPerPage; this.updateParams({ page: 1, perPage: currentPerPage }); this.Get_Properties(1); }
    },
    selectionChanged({ selectedRows }) {
      this.selectedIds = [];
      selectedRows.forEach(row => this.selectedIds.push(row.id));
    },
    onSortChange(params) {
      this.updateParams({ sort: { type: params[0].type, field: params[0].field } });
      this.Get_Properties(this.serverParams.page);
    },
    onSearch(value) { this.search = value.searchTerm; this.Get_Properties(this.serverParams.page); },

    New_Property() { this.$router.push({ name: "realestate_create_property" }); },
    Edit_Property(id) { this.$router.push({ name: "realestate_edit_property", params: { id: id } }); },

    Get_Categories() {
      axios.get("realestate/categories_all").then(res => { this.categories = res.data.categories; }).catch(() => {});
    },

    Get_Properties(page) {
      this.serverParams.page = page;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "realestate/properties?page=" + page +
          "&SortField=" + this.serverParams.sort.field +
          "&SortType=" + this.serverParams.sort.type +
          "&search=" + this.search +
          "&category=" + this.category_filter +
          "&purpose=" + this.purpose_filter +
          "&status=" + this.status_filter +
          "&limit=" + this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.properties = response.data.properties;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Remove_Property(id) {
      this.$swal({
        title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning",
        showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios.delete("realestate/properties/" + id).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            this.Get_Properties(this.serverParams.page);
          }).catch(() => {
            this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
          });
        }
      });
    },

    delete_by_selected() {
      this.$swal({
        title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning",
        showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios.post("realestate/properties/delete/by_selection", { selectedIds: this.selectedIds }).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            this.Get_Properties(this.serverParams.page);
          }).catch(() => {
            this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
          });
        }
      });
    }
  },
  created() {
    this.Get_Categories();
    this.Get_Properties(1);
  }
};
</script>
