<template>
  <div class="main-content">
    <breadcumb :page="$t('Customer_Segments')" :folder="$t('Marketing_Management')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="segments"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-sort-change="onSortChange"
        @on-search="onSearch"
        :search-options="{ enabled: true, placeholder: $t('Search_this_table') }"
        :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
        styleClass="table-hover tableOne vgt-table"
      >
        <div slot="table-actions" class="mt-2 mb-3">
          <b-button @click="New_Segment()" class="btn-rounded" variant="btn btn-primary btn-icon m-1">
            <lucide-icon name="plus" /> {{ $t('New_Segment') }}
          </b-button>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'all_customers'">
            <span class="badge" :class="props.row.all_customers ? 'badge-outline-success' : 'badge-outline-secondary'">
              {{ props.row.all_customers ? $t('All_Customers') : $t('Specific_Segment') }}
            </span>
          </span>
          <span v-else-if="props.column.field == 'actions'">
            <a @click="Edit_Segment(props.row)" class="cursor-pointer" title="Edit" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-success" name="pencil" />
            </a>
            <a :title="$t('Delete')" v-b-tooltip.hover class="cursor-pointer" @click="Remove_Segment(props.row.id)">
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>
        </template>
      </vue-good-table>
    </b-card>

    <validation-observer ref="Segment_Form">
      <b-modal hide-footer size="lg" id="New_Segment" :title="editmode ? $t('Edit_Segment') : $t('New_Segment')">
        <b-form @submit.prevent="Submit_Segment">
          <b-row>
            <b-col md="12">
              <validation-provider name="name" :rules="{ required: true }" v-slot="validationContext">
                <b-form-group :label="$t('Segment_Name') + ' *'">
                  <b-form-input :state="getValidationState(validationContext)" v-model="segment.name"></b-form-input>
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>
            <b-col md="12">
              <b-form-group :label="$t('Description')">
                <b-form-input v-model="segment.description"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="12">
              <b-form-checkbox v-model="segment.all_customers" @change="onAllChange">{{ $t('All_Customers') }}</b-form-checkbox>
              <hr>
            </b-col>

            <template v-if="!segment.all_customers">
              <b-col md="6">
                <b-form-group :label="$t('City')">
                  <b-form-input v-model="segment.filters.city"></b-form-input>
                </b-form-group>
              </b-col>
              <b-col md="3">
                <b-form-group :label="$t('Last_Purchase_From')">
                  <b-form-input type="date" v-model="segment.filters.last_purchase_from"></b-form-input>
                </b-form-group>
              </b-col>
              <b-col md="3">
                <b-form-group :label="$t('Last_Purchase_To')">
                  <b-form-input type="date" v-model="segment.filters.last_purchase_to"></b-form-input>
                </b-form-group>
              </b-col>
              <b-col md="3">
                <b-form-group :label="$t('Total_Purchases_Min')">
                  <b-form-input type="number" min="0" v-model="segment.filters.total_purchases_min"></b-form-input>
                </b-form-group>
              </b-col>
              <b-col md="3">
                <b-form-group :label="$t('Total_Purchases_Max')">
                  <b-form-input type="number" min="0" v-model="segment.filters.total_purchases_max"></b-form-input>
                </b-form-group>
              </b-col>
              <b-col md="3">
                <b-form-group :label="$t('Total_Spent_Min')">
                  <b-form-input type="number" min="0" v-model="segment.filters.total_spent_min"></b-form-input>
                </b-form-group>
              </b-col>
              <b-col md="3">
                <b-form-group :label="$t('Total_Spent_Max')">
                  <b-form-input type="number" min="0" v-model="segment.filters.total_spent_max"></b-form-input>
                </b-form-group>
              </b-col>
            </template>

            <b-col md="12" class="mb-3">
              <b-button size="sm" variant="outline-info" @click="Preview_Segment()">
                <lucide-icon name="search" /> {{ $t('Preview_Segment') }}
              </b-button>
              <span v-if="previewCount !== null" class="ml-2 font-weight-bold text-info">
                {{ previewCount }} {{ $t('Matching_Customers') }}
              </span>
            </b-col>

            <b-col md="12">
              <b-button variant="primary" type="submit" :disabled="SubmitProcessing">
                <lucide-icon class="me-2 font-weight-bold" name="check" /> {{ $t('Save_Segment') }}
              </b-button>
              <div v-if="SubmitProcessing" class="spinner sm spinner-primary mt-3"></div>
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
  metaInfo: { title: "Customer Segments" },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      serverParams: { sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      totalRows: "",
      search: "",
      limit: "10",
      editmode: false,
      segments: [],
      previewCount: null,
      segment: this.empty_segment()
    };
  },
  computed: {
    columns() {
      return [
        { label: this.$t("Segment_Name"), field: "name", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Type"), field: "all_customers", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Matching_Customers"), field: "customers_count", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Action"), field: "actions", tdClass: "text-left", thClass: "text-left", sortable: false }
      ];
    }
  },
  methods: {
    empty_segment() {
      return {
        id: "", name: "", description: "", all_customers: false,
        filters: { city: "", last_purchase_from: "", last_purchase_to: "", total_purchases_min: "", total_purchases_max: "", total_spent_min: "", total_spent_max: "" }
      };
    },
    getValidationState({ dirty, validated, valid = null }) { return dirty || validated ? valid : null; },
    makeToast(variant, msg, title) { this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true }); },
    onAllChange() { this.previewCount = null; },
    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) { this.updateParams({ page: currentPage }); this.Get_Segments(currentPage); }
    },
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) { this.limit = currentPerPage; this.updateParams({ page: 1, perPage: currentPerPage }); this.Get_Segments(1); }
    },
    onSortChange(params) {
      this.updateParams({ sort: { type: params[0].type, field: params[0].field } });
      this.Get_Segments(this.serverParams.page);
    },
    onSearch(value) { this.search = value.searchTerm; this.Get_Segments(this.serverParams.page); },

    New_Segment() {
      this.segment = this.empty_segment();
      this.previewCount = null;
      this.editmode = false;
      this.$bvModal.show("New_Segment");
    },
    Edit_Segment(row) {
      this.segment = {
        ...this.empty_segment(),
        ...row,
        filters: Object.assign(this.empty_segment().filters, row.filters || {})
      };
      this.previewCount = null;
      this.editmode = true;
      this.$bvModal.show("New_Segment");
    },

    Preview_Segment() {
      axios.post("marketing/segments/preview", {
        all_customers: this.segment.all_customers ? 1 : 0,
        filters: this.segment.filters
      }).then(({ data }) => {
        this.previewCount = data.count;
      }).catch(() => {
        this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
      });
    },

    Get_Segments(page) {
      this.serverParams.page = page;
      NProgress.start();
      NProgress.set(0.1);
      axios.get("marketing/segments?page=" + page +
        "&SortField=" + this.serverParams.sort.field +
        "&SortType=" + this.serverParams.sort.type +
        "&search=" + this.search + "&limit=" + this.limit)
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.segments = response.data.segments;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => { NProgress.done(); setTimeout(() => { this.isLoading = false; }, 500); });
    },

    Submit_Segment() {
      this.$refs.Segment_Form.validate().then(success => {
        if (!success) { this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed")); return; }
        this.SubmitProcessing = true;
        const payload = {
          name: this.segment.name,
          description: this.segment.description,
          all_customers: this.segment.all_customers ? 1 : 0,
          filters: this.segment.filters
        };
        const req = this.editmode
          ? axios.put("marketing/segments/" + this.segment.id, payload)
          : axios.post("marketing/segments", payload);
        req.then(() => {
          this.SubmitProcessing = false;
          this.$bvModal.hide("New_Segment");
          this.makeToast("success", this.$t(this.editmode ? "Updated_in_successfully" : "Created_in_successfully"), this.$t("Success"));
          this.Get_Segments(this.serverParams.page);
        }).catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
      });
    },

    Remove_Segment(id) {
      this.$swal({
        title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning",
        showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios.delete("marketing/segments/" + id).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            this.Get_Segments(this.serverParams.page);
          }).catch(() => {
            this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
          });
        }
      });
    }
  },
  created() {
    this.Get_Segments(1);
  }
};
</script>
