<template>
  <div class="main-content">
    <breadcumb page="Templates" folder="WhatsApp" />

    <b-card>
      <b-row class="mb-3">
        <b-col md="6" class="mb-2">
          <b-form-input v-model="search" placeholder="Search templates" @keyup.enter="fetch(1)" />
        </b-col>
        <b-col md="6" class="mb-2 text-right">
          <router-link to="/app/whatsapp/templates/store" class="btn btn-primary">
            <lucide-icon name="plus" /> New Template
          </router-link>
        </b-col>
      </b-row>

      <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

      <vue-good-table
        v-if="!isLoading"
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="templates"
        :pagination-options="{ enabled: true, mode: 'records', perPage: perPage }"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        styleClass="table-hover tableOne vgt-table"
      >
        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field === 'is_active'">
            <b-badge :variant="props.row.is_active ? 'success' : 'secondary'">
              {{ props.row.is_active ? 'Active' : 'Inactive' }}
            </b-badge>
          </span>
          <span v-else-if="props.column.field === 'actions'">
            <router-link :to="'/app/whatsapp/templates/edit/' + props.row.id" class="btn btn-sm btn-outline-primary">
              <lucide-icon name="pencil" />
            </router-link>
            <b-button size="sm" variant="outline-danger" class="ml-1" @click="remove(props.row.id)">
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
      templates: [],
      totalRows: 0,
      isLoading: true,
      perPage: 25,
      currentPage: 1,
      search: "",
      columns: [
        { label: "Name", field: "name" },
        { label: "Key", field: "key" },
        { label: "Language", field: "language" },
        { label: "Status", field: "is_active" },
        { label: "Actions", field: "actions", sortable: false }
      ]
    };
  },
  mounted() {
    this.fetch();
  },
  methods: {
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },
    fetch(page = 1) {
      this.currentPage = page;
      this.isLoading = true;
      axios
        .get("/whatsapp/templates", { params: { page: page, per_page: this.perPage, search: this.search } })
        .then(res => {
          this.templates = res.data.templates;
          this.totalRows = res.data.total;
        })
        .catch(() => this.makeToast("danger", "Failed to load templates.", "Error"))
        .finally(() => (this.isLoading = false));
    },
    remove(id) {
      axios
        .delete("/whatsapp/templates/" + id)
        .then(() => {
          this.makeToast("success", "Template deleted.", "Success");
          this.fetch(this.currentPage);
        })
        .catch(() => this.makeToast("danger", "Failed to delete.", "Error"));
    },
    onPageChange(params) {
      this.fetch(params.currentPage);
    },
    onPerPageChange(params) {
      this.perPage = params.currentPerPage;
      this.fetch(1);
    }
  }
};
</script>
