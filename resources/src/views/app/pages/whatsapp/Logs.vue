<template>
  <div class="main-content">
    <breadcumb page="Message Logs" folder="WhatsApp" />

    <b-card>
      <b-row class="mb-3">
        <b-col md="4" class="mb-2">
          <b-form-input v-model="search" placeholder="Search recipient / message" @keyup.enter="fetchLogs(1)" />
        </b-col>
        <b-col md="3" class="mb-2">
          <b-form-select v-model="statusFilter" :options="statusOptions" @change="fetchLogs(1)" />
        </b-col>
        <b-col md="5" class="mb-2 text-right">
          <b-button variant="primary" @click="fetchLogs(1)"><lucide-icon name="refresh-cw" /> Refresh</b-button>
        </b-col>
      </b-row>

      <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

      <vue-good-table
        v-if="!isLoading"
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="logs"
        :pagination-options="{ enabled: true, mode: 'records', perPage: perPage }"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        styleClass="table-hover tableOne vgt-table"
      >
        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field === 'status'">
            <b-badge :variant="statusVariant(props.row.status)">{{ props.row.status }}</b-badge>
          </span>
          <span v-else-if="props.column.field === 'error'">
            <span v-if="props.row.error" class="text-danger" :title="props.row.error">
              {{ truncate(props.row.error) }}
            </span>
            <span v-else>—</span>
          </span>
          <span v-else-if="props.column.field === 'actions'">
            <b-button size="sm" variant="outline-danger" @click="remove(props.row.id)">
              <lucide-icon name="trash-2" />
            </b-button>
          </span>
          <span v-else>{{ props.formattedRow[props.column.field] }}</span>
        </template>
      </vue-good-table>
    </b-card>
  </div>
</template>

<script>
export default {
  data() {
    return {
      logs: [],
      totalRows: 0,
      isLoading: true,
      perPage: 15,
      currentPage: 1,
      search: "",
      statusFilter: "",
      statusOptions: [
        { value: "", text: "All statuses" },
        { value: "pending", text: "Pending" },
        { value: "sent", text: "Sent" },
        { value: "delivered", text: "Delivered" },
        { value: "read", text: "Read" },
        { value: "failed", text: "Failed" }
      ],
      columns: [
        { label: "Recipient", field: "recipient" },
        { label: "Type", field: "message_type" },
        { label: "Template", field: "template_key" },
        { label: "Message", field: "body" },
        { label: "Status", field: "status" },
        { label: "Error", field: "error" },
        { label: "Sent At", field: "sent_at" },
        { label: "Actions", field: "actions", sortable: false }
      ]
    };
  },
  mounted() {
    this.fetchLogs();
  },
  methods: {
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },
    truncate(s) {
      s = String(s || "");
      return s.length > 40 ? s.slice(0, 40) + "…" : s;
    },
    statusVariant(status) {
      return { sent: "info", delivered: "primary", read: "success", failed: "danger", pending: "secondary" }[status] || "secondary";
    },
    fetchLogs(page = 1) {
      this.currentPage = page;
      this.isLoading = true;
      axios
        .get("/whatsapp/logs", {
          params: { page: page, per_page: this.perPage, search: this.search, status: this.statusFilter }
        })
        .then(res => {
          this.logs = res.data.logs;
          this.totalRows = res.data.total;
        })
        .catch(() => this.makeToast("danger", "Failed to load logs.", "Error"))
        .finally(() => (this.isLoading = false));
    },
    remove(id) {
      axios
        .delete("/whatsapp/logs/" + id)
        .then(() => {
          this.makeToast("success", "Log deleted.", "Success");
          this.fetchLogs(this.currentPage);
        })
        .catch(() => this.makeToast("danger", "Failed to delete.", "Error"));
    },
    onPageChange(params) {
      this.fetchLogs(params.currentPage);
    },
    onPerPageChange(params) {
      this.perPage = params.currentPerPage;
      this.fetchLogs(1);
    }
  }
};
</script>
