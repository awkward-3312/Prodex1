<template>
  <div class="main-content">
    <breadcumb :page="$t('Serial_Management')" :folder="$t('Serial_Numbers')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="serials"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-sort-change="onSortChange"
        @on-search="onSearch"
        :search-options="{ placeholder: $t('Search_this_table'), enabled: true }"
        :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
        styleClass="tableOne table-hover vgt-table mt-3"
      >
        <div slot="table-actions" class="mt-2 mb-3" style="display:flex; gap:10px; flex-wrap:wrap;">
          <b-form-group :label="$t('warehouse')" style="min-width:200px;">
            <v-select
              @input="loadOnFilter"
              v-model="warehouse_id"
              :reduce="label => label.value"
              :placeholder="$t('Choose_Warehouse')"
              :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
            />
          </b-form-group>
          <b-form-group :label="$t('Serial_Status')" style="min-width:200px;">
            <v-select
              @input="loadOnFilter"
              v-model="status"
              :reduce="label => label.value"
              :placeholder="$t('Serial_Status')"
              :options="statusOptions"
            />
          </b-form-group>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'status'">
            <span class="badge" :class="statusBadge(props.row.status)">{{ statusLabel(props.row.status) }}</span>
          </span>
          <span v-else-if="props.column.field == 'actions'">
            <router-link :title="$t('History')" :to="'/app/serial_numbers/detail/' + props.row.id">
              <b-button size="sm" variant="primary"><lucide-icon name="history" /> {{ $t('History') || 'History' }}</b-button>
            </router-link>
          </span>
          <span v-else>{{ props.formattedRow[props.column.field] }}</span>
        </template>
      </vue-good-table>
    </b-card>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Serial Numbers" },
  data() {
    return {
      isLoading: true,
      serverParams: { sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      limit: "10",
      search: "",
      totalRows: "",
      serials: [],
      warehouses: [],
      warehouse_id: "",
      status: ""
    };
  },
  computed: {
    statusOptions() {
      return [
        { label: this.$t("Status_available"), value: "available" },
        { label: this.$t("Status_sold"), value: "sold" },
        { label: this.$t("Status_returned_customer"), value: "returned_customer" },
        { label: this.$t("Status_returned_supplier"), value: "returned_supplier" },
        { label: this.$t("Status_damaged"), value: "damaged" },
        { label: this.$t("Status_reserved"), value: "reserved" }
      ];
    },
    columns() {
      return [
        { label: this.$t("Serial_Number"), field: "serial_number", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Name_product"), field: "product_name", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("warehouse"), field: "warehouse_name", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Serial_Status"), field: "status", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Supplier"), field: "provider_name", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Customer"), field: "client_name", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Action"), field: "actions", tdClass: "text-left", thClass: "text-left", sortable: false }
      ];
    }
  },
  methods: {
    statusLabel(s) {
      return this.$t("Status_" + s) || s;
    },
    statusBadge(s) {
      const map = {
        available: "badge-success",
        sold: "badge-primary",
        returned_customer: "badge-info",
        returned_supplier: "badge-warning",
        damaged: "badge-danger",
        reserved: "badge-secondary"
      };
      return map[s] || "badge-light";
    },
    updateParams(p) { this.serverParams = Object.assign({}, this.serverParams, p); },
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) {
        this.updateParams({ page: currentPage });
        this.loadItems(currentPage);
      }
    },
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.updateParams({ page: 1, perPage: currentPerPage });
        this.loadItems(1);
      }
    },
    onSortChange(params) {
      this.updateParams({ sort: { type: params[0].type, field: params[0].field } });
      this.loadItems(this.serverParams.page);
    },
    onSearch(value) {
      this.search = value.searchTerm;
      this.loadItems(this.serverParams.page);
    },
    loadOnFilter() {
      this.loadItems(1);
    },
    loadItems(page) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("serial_numbers", {
          params: {
            page: page,
            SortField: this.serverParams.sort.field,
            SortType: this.serverParams.sort.type,
            search: this.search,
            status: this.status || "",
            warehouse_id: this.warehouse_id || "",
            limit: this.limit
          }
        })
        .then(response => {
          this.serials = response.data.serials;
          this.totalRows = response.data.totalRows;
          if (response.data.warehouses) this.warehouses = response.data.warehouses;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    }
  },
  created() {
    this.loadItems(1);
  }
};
</script>
