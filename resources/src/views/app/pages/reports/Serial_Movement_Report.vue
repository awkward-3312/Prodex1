<template>
  <div class="main-content">
    <breadcumb :page="$t('Serial_Movement_Log') || 'Serial Movement Log'" :folder="$t('Reports')" />
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
          <b-form-group :label="$t('Action')" style="min-width:200px;">
            <v-select @input="loadItems(1)" v-model="action" :reduce="l => l.value"
              :placeholder="$t('Action')" :options="actionOptions" />
          </b-form-group>
          <b-form-group :label="$t('From') || 'From'" style="min-width:160px;">
            <input type="date" class="form-control" v-model="from_date" @change="loadItems(1)" />
          </b-form-group>
          <b-form-group :label="$t('To') || 'To'" style="min-width:160px;">
            <input type="date" class="form-control" v-model="to_date" @change="loadItems(1)" />
          </b-form-group>
          <vue-excel-xlsx class="btn btn-sm btn-outline-danger ripple m-1" :data="reports" :columns="columns"
            :file-name="'serial_movements'" :file-type="'xlsx'" :sheet-name="'serial_movements'">
            <lucide-icon name="file-spreadsheet" /> EXCEL
          </vue-excel-xlsx>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'action'">{{ actionLabel(props.row.action) }}</span>
          <span v-else-if="props.column.field == 'transition'">
            <span v-if="props.row.from_status" class="text-muted">{{ statusLabel(props.row.from_status) }} &rarr; </span>
            <span>{{ statusLabel(props.row.to_status) }}</span>
          </span>
          <span v-else-if="props.column.field == 'reference'">
            <span v-if="props.row.reference_type">{{ props.row.reference_type }} #{{ props.row.reference_id }}</span>
            <span v-else>—</span>
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
  metaInfo: { title: "Serial Movement Report" },
  data() {
    return {
      isLoading: true,
      serverParams: { page: 1, perPage: 10 },
      limit: "10", search: "", totalRows: "", reports: [],
      action: "", from_date: "", to_date: ""
    };
  },
  computed: {
    actionOptions() {
      return [
        { label: this.$t("Purchased"), value: "purchased" },
        { label: this.$t("Status_sold"), value: "sold" },
        { label: this.$t("Status_returned_customer"), value: "sale_returned" },
        { label: this.$t("Status_returned_supplier"), value: "purchase_returned" },
        { label: this.$t("Status_changed"), value: "status_changed" }
      ];
    },
    columns() {
      return [
        { label: this.$t("date"), field: "created_at", thClass: "text-left", tdClass: "text-left" },
        { label: this.$t("Serial_Number"), field: "serial_number", thClass: "text-left", tdClass: "text-left" },
        { label: this.$t("Action"), field: "action", thClass: "text-left", tdClass: "text-left" },
        { label: this.$t("Serial_Status"), field: "transition", thClass: "text-left", tdClass: "text-left", sortable: false },
        { label: this.$t("Reference") || "Reference", field: "reference", thClass: "text-left", tdClass: "text-left", sortable: false }
      ];
    }
  },
  methods: {
    statusLabel(s) { return this.$t("Status_" + s) || s; },
    actionLabel(a) {
      const map = {
        purchased: this.$t("Purchased"), sold: this.$t("Status_sold"),
        sale_returned: this.$t("Status_returned_customer"), purchase_returned: this.$t("Status_returned_supplier"),
        status_changed: this.$t("Status_changed"), adjusted: this.$t("Adjusted")
      };
      return map[a] || a;
    },
    updateParams(p) { this.serverParams = Object.assign({}, this.serverParams, p); },
    onPageChange({ currentPage }) { if (this.serverParams.page !== currentPage) { this.updateParams({ page: currentPage }); this.loadItems(currentPage); } },
    onPerPageChange({ currentPerPage }) { if (this.limit !== currentPerPage) { this.limit = currentPerPage; this.updateParams({ page: 1, perPage: currentPerPage }); this.loadItems(1); } },
    onSearch(v) { this.search = v.searchTerm; this.loadItems(this.serverParams.page); },
    loadItems(page) {
      NProgress.start(); NProgress.set(0.1);
      axios.get("report/serials/movements", { params: {
        page, search: this.search, action: this.action || "",
        from_date: this.from_date || "", to_date: this.to_date || "", limit: this.limit
      }}).then(r => {
        this.reports = r.data.report; this.totalRows = r.data.totalRows;
        NProgress.done(); this.isLoading = false;
      }).catch(() => { NProgress.done(); setTimeout(() => { this.isLoading = false; }, 500); });
    }
  },
  created() { this.loadItems(1); }
};
</script>
