<template>
  <div class="main-content">
    <breadcumb :page="$t('Sold_Serial_Numbers') || 'Sold Serial Numbers'" :folder="$t('Reports')" />
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="reports"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-sort-change="onSortChange"
        @on-search="onSearch"
        :search-options="{ placeholder: $t('Search_this_table'), enabled: true }"
        :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
        styleClass="tableOne table-hover vgt-table mt-3"
      >
        <div slot="table-actions" class="mt-2 mb-3" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
          <b-form-group :label="$t('warehouse')" style="min-width:200px;">
            <v-select @input="loadItems(1)" v-model="warehouse_id" :reduce="l => l.value"
              :placeholder="$t('Choose_Warehouse')" :options="warehouses.map(w => ({label:w.name, value:w.id}))" />
          </b-form-group>
          <vue-excel-xlsx class="btn btn-sm btn-outline-danger ripple m-1" :data="reports" :columns="columns"
            :file-name="'sold_serials'" :file-type="'xlsx'" :sheet-name="'sold_serials'">
            <lucide-icon name="file-spreadsheet" /> EXCEL
          </vue-excel-xlsx>
        </div>
      </vue-good-table>
    </b-card>
  </div>
</template>

<script>
import NProgress from "nprogress";
export default {
  metaInfo: { title: "Sold Serials Report" },
  data() {
    return {
      isLoading: true,
      serverParams: { sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      limit: "10", search: "", totalRows: "", reports: [], warehouses: [], warehouse_id: ""
    };
  },
  computed: {
    columns() {
      return [
        { label: this.$t("Serial_Number"), field: "serial_number", thClass: "text-left", tdClass: "text-left" },
        { label: this.$t("Name_product"), field: "product_name", thClass: "text-left", tdClass: "text-left", sortable: false },
        { label: this.$t("warehouse"), field: "warehouse_name", thClass: "text-left", tdClass: "text-left", sortable: false },
        { label: this.$t("Customer"), field: "client_name", thClass: "text-left", tdClass: "text-left", sortable: false },
        { label: this.$t("Sale"), field: "sale_ref", thClass: "text-left", tdClass: "text-left", sortable: false },
        { label: this.$t("date"), field: "sale_date", thClass: "text-left", tdClass: "text-left", sortable: false }
      ];
    }
  },
  methods: {
    updateParams(p) { this.serverParams = Object.assign({}, this.serverParams, p); },
    onPageChange({ currentPage }) { if (this.serverParams.page !== currentPage) { this.updateParams({ page: currentPage }); this.loadItems(currentPage); } },
    onPerPageChange({ currentPerPage }) { if (this.limit !== currentPerPage) { this.limit = currentPerPage; this.updateParams({ page: 1, perPage: currentPerPage }); this.loadItems(1); } },
    onSortChange(p) { this.updateParams({ sort: { type: p[0].type, field: p[0].field } }); this.loadItems(this.serverParams.page); },
    onSearch(v) { this.search = v.searchTerm; this.loadItems(this.serverParams.page); },
    loadItems(page) {
      NProgress.start(); NProgress.set(0.1);
      axios.get("report/serials/sold", { params: {
        page, SortField: this.serverParams.sort.field, SortType: this.serverParams.sort.type,
        search: this.search, warehouse_id: this.warehouse_id || "", limit: this.limit
      }}).then(r => {
        this.reports = r.data.report; this.totalRows = r.data.totalRows;
        if (r.data.warehouses) this.warehouses = r.data.warehouses;
        NProgress.done(); this.isLoading = false;
      }).catch(() => { NProgress.done(); setTimeout(() => { this.isLoading = false; }, 500); });
    }
  },
  created() { this.loadItems(1); }
};
</script>
