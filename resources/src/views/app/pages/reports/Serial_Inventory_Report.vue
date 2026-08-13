<template>
  <div class="main-content">
    <breadcumb :page="$t('Product_Serial_Inventory') || 'Product Serial Inventory'" :folder="$t('Reports')" />
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="reports"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
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
            :file-name="'serial_inventory'" :file-type="'xlsx'" :sheet-name="'serial_inventory'">
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
  metaInfo: { title: "Serial Inventory Report" },
  data() {
    return {
      isLoading: true,
      serverParams: { page: 1, perPage: 10 },
      limit: "10", search: "", totalRows: "", reports: [], warehouses: [], warehouse_id: ""
    };
  },
  computed: {
    columns() {
      return [
        { label: this.$t("Name_product"), field: "product_name", thClass: "text-left", tdClass: "text-left", sortable: false },
        { label: this.$t("ProductCode"), field: "product_code", thClass: "text-left", tdClass: "text-left", sortable: false },
        { label: this.$t("Status_available"), field: "available", thClass: "text-left", tdClass: "text-left", sortable: false },
        { label: this.$t("Status_sold"), field: "sold", thClass: "text-left", tdClass: "text-left", sortable: false },
        { label: this.$t("Status_returned_supplier"), field: "returned_supplier", thClass: "text-left", tdClass: "text-left", sortable: false },
        { label: this.$t("Status_damaged"), field: "damaged", thClass: "text-left", tdClass: "text-left", sortable: false },
        { label: this.$t("Status_reserved"), field: "reserved", thClass: "text-left", tdClass: "text-left", sortable: false },
        { label: this.$t("Total"), field: "total", thClass: "text-left", tdClass: "text-left", sortable: false }
      ];
    }
  },
  methods: {
    updateParams(p) { this.serverParams = Object.assign({}, this.serverParams, p); },
    onPageChange({ currentPage }) { if (this.serverParams.page !== currentPage) { this.updateParams({ page: currentPage }); this.loadItems(currentPage); } },
    onPerPageChange({ currentPerPage }) { if (this.limit !== currentPerPage) { this.limit = currentPerPage; this.updateParams({ page: 1, perPage: currentPerPage }); this.loadItems(1); } },
    onSearch(v) { this.search = v.searchTerm; this.loadItems(this.serverParams.page); },
    loadItems(page) {
      NProgress.start(); NProgress.set(0.1);
      axios.get("report/serials/inventory", { params: {
        page, search: this.search, warehouse_id: this.warehouse_id || "", limit: this.limit
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
