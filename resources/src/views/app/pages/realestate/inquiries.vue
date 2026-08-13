<template>
  <div class="main-content">
    <breadcumb :page="$t('Property_Inquiries')" :folder="$t('Real_Estate')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <b-row class="mb-3">
        <b-col md="3">
          <b-form-group :label="$t('Status')">
            <b-form-select v-model="status_filter" @change="Get_Inquiries(1)">
              <b-form-select-option value="">{{ $t('All') }}</b-form-select-option>
              <b-form-select-option value="new">{{ $t('New') }}</b-form-select-option>
              <b-form-select-option value="read">{{ $t('Read') }}</b-form-select-option>
              <b-form-select-option value="responded">{{ $t('Responded') }}</b-form-select-option>
              <b-form-select-option value="closed">{{ $t('Closed') }}</b-form-select-option>
            </b-form-select>
          </b-form-group>
        </b-col>
      </b-row>

      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="inquiries"
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

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'name'">
            <a class="cursor-pointer text-primary font-weight-bold" @click="View(props.row)">{{ props.row.name }}</a>
          </span>
          <span v-else-if="props.column.field == 'property'">
            {{ props.row.property ? props.row.property.title : '—' }}
          </span>
          <span v-else-if="props.column.field == 'status'">
            <span class="badge" :class="status_class(props.row.status)">{{ status_label(props.row.status) }}</span>
          </span>
          <span v-else-if="props.column.field == 'created_at'">
            {{ short_dt(props.row.created_at) }}
          </span>
          <span v-else-if="props.column.field == 'actions'">
            <a @click="View(props.row)" class="cursor-pointer" :title="$t('View_Details')" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-primary" name="eye" />
            </a>
            <a :title="$t('Delete')" v-b-tooltip.hover class="cursor-pointer" @click="Remove(props.row.id)">
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>
        </template>
      </vue-good-table>
    </b-card>

    <b-modal id="inquiryModal" :title="$t('Inquiry_Details')" hide-footer size="lg">
      <div v-if="current">
        <b-row>
          <b-col md="6"><p><strong>{{ $t('Name') }}:</strong> {{ current.name }}</p></b-col>
          <b-col md="6"><p><strong>{{ $t('Property') }}:</strong> {{ current.property ? current.property.title : '—' }}</p></b-col>
          <b-col md="6"><p><strong>{{ $t('Email') }}:</strong> <a v-if="current.email" :href="'mailto:' + current.email">{{ current.email }}</a><span v-else>—</span></p></b-col>
          <b-col md="6"><p><strong>{{ $t('Phone') }}:</strong> <a v-if="current.phone" :href="'tel:' + current.phone">{{ current.phone }}</a><span v-else>—</span></p></b-col>
        </b-row>
        <p><strong>{{ $t('Message') }}:</strong></p>
        <div class="p-3 bg-light rounded mb-3" style="white-space:pre-line">{{ current.message }}</div>
        <b-form-group :label="$t('Status')">
          <b-form-select v-model="current.status" @change="updateStatus">
            <b-form-select-option value="new">{{ $t('New') }}</b-form-select-option>
            <b-form-select-option value="read">{{ $t('Read') }}</b-form-select-option>
            <b-form-select-option value="responded">{{ $t('Responded') }}</b-form-select-option>
            <b-form-select-option value="closed">{{ $t('Closed') }}</b-form-select-option>
          </b-form-select>
        </b-form-group>
      </div>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Property Inquiries" },
  data() {
    return {
      isLoading: true,
      serverParams: { sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      selectedIds: [],
      totalRows: "",
      search: "",
      limit: "10",
      status_filter: "",
      inquiries: [],
      current: null
    };
  },
  computed: {
    columns() {
      return [
        { label: this.$t("Name"), field: "name", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Property"), field: "property", sortable: false, tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Email"), field: "email", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Phone"), field: "phone", sortable: false, tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Status"), field: "status", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Date"), field: "created_at", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Action"), field: "actions", sortable: false, tdClass: "text-left", thClass: "text-left" }
      ];
    }
  },
  methods: {
    short_dt(d) { return d ? String(d).replace("T", " ").substring(0, 16) : "-"; },
    status_label(s) {
      const map = { new: this.$t("New"), read: this.$t("Read"), responded: this.$t("Responded"), closed: this.$t("Closed") };
      return map[s] || s;
    },
    status_class(s) {
      const map = { new: "badge-outline-primary", read: "badge-outline-info", responded: "badge-outline-success", closed: "badge-outline-secondary" };
      return map[s] || "badge-outline-secondary";
    },
    makeToast(variant, msg, title) { this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true }); },
    updateParams(p) { this.serverParams = Object.assign({}, this.serverParams, p); },
    onPageChange({ currentPage }) { if (this.serverParams.page !== currentPage) { this.updateParams({ page: currentPage }); this.Get_Inquiries(currentPage); } },
    onPerPageChange({ currentPerPage }) { if (this.limit !== currentPerPage) { this.limit = currentPerPage; this.updateParams({ page: 1, perPage: currentPerPage }); this.Get_Inquiries(1); } },
    selectionChanged({ selectedRows }) { this.selectedIds = []; selectedRows.forEach(r => this.selectedIds.push(r.id)); },
    onSortChange(params) { this.updateParams({ sort: { type: params[0].type, field: params[0].field } }); this.Get_Inquiries(this.serverParams.page); },
    onSearch(value) { this.search = value.searchTerm; this.Get_Inquiries(this.serverParams.page); },

    View(row) {
      axios.get("realestate/inquiries/" + row.id).then(res => {
        this.current = res.data.inquiry;
        this.$bvModal.show("inquiryModal");
        // refresh list so the auto "read" status reflects
        this.Get_Inquiries(this.serverParams.page);
      }).catch(() => { this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed")); });
    },

    updateStatus() {
      axios.put("realestate/inquiries/" + this.current.id + "/status", { status: this.current.status })
        .then(() => {
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
          this.Get_Inquiries(this.serverParams.page);
        })
        .catch(() => { this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed")); });
    },

    Get_Inquiries(page) {
      this.serverParams.page = page;
      NProgress.start(); NProgress.set(0.1);
      axios.get("realestate/inquiries?page=" + page +
        "&SortField=" + this.serverParams.sort.field +
        "&SortType=" + this.serverParams.sort.type +
        "&search=" + this.search + "&status=" + this.status_filter + "&limit=" + this.limit)
        .then(res => {
          this.totalRows = res.data.totalRows;
          this.inquiries = res.data.inquiries;
          NProgress.done(); this.isLoading = false;
        })
        .catch(() => { NProgress.done(); setTimeout(() => { this.isLoading = false; }, 500); });
    },

    Remove(id) {
      this.$swal({
        title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning",
        showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios.delete("realestate/inquiries/" + id).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            this.Get_Inquiries(this.serverParams.page);
          }).catch(() => { this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning"); });
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
          axios.post("realestate/inquiries/delete/by_selection", { selectedIds: this.selectedIds }).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            this.Get_Inquiries(this.serverParams.page);
          }).catch(() => { this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning"); });
        }
      });
    }
  },
  created() { this.Get_Inquiries(1); }
};
</script>
