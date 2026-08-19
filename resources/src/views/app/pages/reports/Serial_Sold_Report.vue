<template>
  <div class="main-content">
    <breadcumb page="Números de serie vendidos" :folder="$t('Reports')" />
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
        :search-options="{ placeholder: 'Buscar en esta tabla', enabled: true }"
        :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'Siguiente', prevLabel: 'Anterior' }"
        styleClass="tableOne table-hover vgt-table mt-3"
      >
        <div slot="table-actions" class="mt-2 mb-3" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
          <b-form-group label="Almacén" style="min-width:200px;">
            <v-select @input="loadItems(1)" v-model="warehouse_id" :reduce="l => l.value" placeholder="Selecciona un almacén" :options="warehouses.map(w => ({label:w.name, value:w.id}))" />
          </b-form-group>
          <vue-excel-xlsx class="btn btn-sm btn-outline-danger ripple m-1" :data="reports" :columns="columns" :file-name="'seriales_vendidos'" :file-type="'xlsx'" :sheet-name="'seriales_vendidos'">
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
  metaInfo: { title: "Informe de números de serie vendidos" },
  data() { return { isLoading: true, serverParams: { sort: { field: "id", type: "desc" }, page: 1, perPage: 10 }, limit: "10", search: "", totalRows: "", reports: [], warehouses: [], warehouse_id: "" }; },
  computed: {
    columns() {
      return [
        { label: "Número de serie", field: "serial_number", thClass: "text-left", tdClass: "text-left" },
        { label: "Producto", field: "product_name", thClass: "text-left", tdClass: "text-left", sortable: false },
        { label: "Almacén", field: "warehouse_name", thClass: "text-left", tdClass: "text-left", sortable: false },
        { label: "Cliente", field: "client_name", thClass: "text-left", tdClass: "text-left", sortable: false },
        { label: "Venta", field: "sale_ref", thClass: "text-left", tdClass: "text-left", sortable: false },
        { label: "Fecha", field: "sale_date", thClass: "text-left", tdClass: "text-left", sortable: false }
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
      axios.get("report/serials/sold", { params: { page, SortField: this.serverParams.sort.field, SortType: this.serverParams.sort.type, search: this.search, warehouse_id: this.warehouse_id || "", limit: this.limit }}).then(r => {
        this.reports = r.data.report; this.totalRows = r.data.totalRows; if (r.data.warehouses) this.warehouses = r.data.warehouses; NProgress.done(); this.isLoading = false;
      }).catch(() => { NProgress.done(); setTimeout(() => { this.isLoading = false; }, 500); });
    }
  },
  created() { this.loadItems(1); }
};
</script>