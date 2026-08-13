<template>
  <div class="main-content">
    <breadcumb :page="$t('Campaign_List')" :folder="$t('Marketing_Management')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <b-row class="mb-3">
        <b-col md="3">
          <b-form-group :label="$t('Campaign_Type')">
            <b-form-select v-model="type_filter" @change="Get_Campaigns(1)">
              <b-form-select-option value="">{{ $t('All') }}</b-form-select-option>
              <b-form-select-option v-for="t in types" :key="t" :value="t">{{ type_label(t) }}</b-form-select-option>
            </b-form-select>
          </b-form-group>
        </b-col>
        <b-col md="3">
          <b-form-group :label="$t('Status')">
            <b-form-select v-model="status_filter" @change="Get_Campaigns(1)">
              <b-form-select-option value="">{{ $t('All') }}</b-form-select-option>
              <b-form-select-option v-for="s in statuses" :key="s" :value="s">{{ format_label(s) }}</b-form-select-option>
            </b-form-select>
          </b-form-group>
        </b-col>
      </b-row>

      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="campaigns"
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
          <b-button @click="New_Campaign()" class="btn-rounded" variant="btn btn-primary btn-icon m-1">
            <lucide-icon name="plus" /> {{ $t('New_Campaign') }}
          </b-button>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'title'">
            <a class="cursor-pointer text-primary font-weight-bold" @click="View_Details(props.row.id)">{{ props.row.title }}</a>
          </span>
          <span v-else-if="props.column.field == 'type'">
            <span class="badge badge-outline-info">{{ type_label(props.row.type) }}</span>
          </span>
          <span v-else-if="props.column.field == 'status'">
            <span class="badge" :class="status_class(props.row.status)">{{ format_label(props.row.status) }}</span>
          </span>
          <span v-else-if="props.column.field == 'progress'">
            {{ props.row.sent_count }} / {{ props.row.total_recipients }}
            <span v-if="props.row.failed_count > 0" class="text-danger">({{ props.row.failed_count }} {{ $t('Failed') }})</span>
          </span>
          <span v-else-if="props.column.field == 'schedule'">
            {{ props.row.send_immediately ? '-' : short_dt(props.row.scheduled_at) }}
          </span>
          <span v-else-if="props.column.field == 'actions'">
            <a @click="View_Details(props.row.id)" class="cursor-pointer" :title="$t('View_Details')" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-primary" name="eye" />
            </a>
            <a v-if="canSend(props.row)" @click="Send_Campaign(props.row.id)" class="cursor-pointer" :title="$t('Send_Now')" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-info" name="send" />
            </a>
            <a v-if="canEdit(props.row)" @click="Edit_Campaign(props.row.id)" class="cursor-pointer" title="Edit" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-success" name="pencil" />
            </a>
            <a :title="$t('Delete')" v-b-tooltip.hover class="cursor-pointer" @click="Remove_Campaign(props.row.id)">
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
  metaInfo: { title: "Campaigns" },
  data() {
    return {
      isLoading: true,
      serverParams: { sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      selectedIds: [],
      totalRows: "",
      search: "",
      limit: "10",
      type_filter: "",
      status_filter: "",
      types: ["sms", "email", "whatsapp"],
      statuses: ["draft", "scheduled", "sending", "sent", "failed", "cancelled"],
      campaigns: []
    };
  },
  computed: {
    columns() {
      return [
        { label: this.$t("Campaign_Title"), field: "title", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Type"), field: "type", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Status"), field: "status", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Delivery_Status"), field: "progress", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Scheduled_At"), field: "schedule", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Action"), field: "actions", tdClass: "text-left", thClass: "text-left", sortable: false }
      ];
    }
  },
  methods: {
    format_label(v) { return v ? String(v).replace(/_/g, " ") : "-"; },
    type_label(t) {
      const map = { sms: "SMS", email: "Email", whatsapp: "WhatsApp" };
      return map[t] || t;
    },
    short_dt(d) { return d ? String(d).replace("T", " ").substring(0, 16) : "-"; },
    status_class(s) {
      const map = {
        draft: "badge-outline-secondary", scheduled: "badge-outline-primary",
        sending: "badge-outline-warning", sent: "badge-outline-success",
        failed: "badge-outline-danger", cancelled: "badge-outline-dark"
      };
      return map[s] || "badge-outline-secondary";
    },
    canEdit(row) { return ["draft", "scheduled", "failed", "cancelled"].includes(row.status); },
    canSend(row) { return ["draft", "scheduled", "failed"].includes(row.status); },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },
    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) { this.updateParams({ page: currentPage }); this.Get_Campaigns(currentPage); }
    },
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) { this.limit = currentPerPage; this.updateParams({ page: 1, perPage: currentPerPage }); this.Get_Campaigns(1); }
    },
    selectionChanged({ selectedRows }) {
      this.selectedIds = [];
      selectedRows.forEach(row => this.selectedIds.push(row.id));
    },
    onSortChange(params) {
      this.updateParams({ sort: { type: params[0].type, field: params[0].field } });
      this.Get_Campaigns(this.serverParams.page);
    },
    onSearch(value) { this.search = value.searchTerm; this.Get_Campaigns(this.serverParams.page); },

    New_Campaign() { this.$router.push({ name: "marketing_create_campaign" }); },
    Edit_Campaign(id) { this.$router.push({ name: "marketing_edit_campaign", params: { id: id } }); },
    View_Details(id) { this.$router.push({ name: "marketing_campaign_details", params: { id: id } }); },

    Get_Campaigns(page) {
      this.serverParams.page = page;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "marketing/campaigns?page=" + page +
          "&SortField=" + this.serverParams.sort.field +
          "&SortType=" + this.serverParams.sort.type +
          "&search=" + this.search +
          "&type=" + this.type_filter +
          "&status=" + this.status_filter +
          "&limit=" + this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.campaigns = response.data.campaigns;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Send_Campaign(id) {
      this.$swal({
        title: this.$t("Send_Campaign"), text: this.$t("Send_Now") + " ?", type: "warning",
        showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33",
        confirmButtonText: this.$t("Send_Now")
      }).then(result => {
        if (result.value) {
          axios.post("marketing/campaigns/" + id + "/send").then(() => {
            this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
            this.Get_Campaigns(this.serverParams.page);
          }).catch(() => {
            this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
          });
        }
      });
    },

    Remove_Campaign(id) {
      this.$swal({
        title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning",
        showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios.delete("marketing/campaigns/" + id).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            this.Get_Campaigns(this.serverParams.page);
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
          axios.post("marketing/campaigns/delete/by_selection", { selectedIds: this.selectedIds }).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            this.Get_Campaigns(this.serverParams.page);
          }).catch(() => {
            this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
          });
        }
      });
    }
  },
  created() {
    this.Get_Campaigns(1);
  }
};
</script>
