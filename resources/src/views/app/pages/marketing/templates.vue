<template>
  <div class="main-content">
    <breadcumb :page="pageTitle" :folder="$t('Marketing_Management')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="templates"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-sort-change="onSortChange"
        @on-search="onSearch"
        :search-options="{ enabled: true, placeholder: $t('Search_this_table') }"
        :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
        styleClass="table-hover tableOne vgt-table"
      >
        <div slot="table-actions" class="mt-2 mb-3">
          <b-button @click="New_Template()" class="btn-rounded" variant="btn btn-primary btn-icon m-1">
            <lucide-icon name="plus" /> {{ $t('New_Template') }}
          </b-button>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'actions'">
            <a @click="Duplicate_Template(props.row.id)" class="cursor-pointer" :title="$t('Duplicate')" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-info" name="copy" />
            </a>
            <a @click="Edit_Template(props.row)" class="cursor-pointer" title="Edit" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-success" name="pencil" />
            </a>
            <a :title="$t('Delete')" v-b-tooltip.hover class="cursor-pointer" @click="Remove_Template(props.row.id)">
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>
        </template>
      </vue-good-table>
    </b-card>

    <validation-observer ref="Template_Form">
      <b-modal hide-footer size="lg" id="New_Template" :title="editmode ? $t('Edit_Template') : $t('New_Template')">
        <b-form @submit.prevent="Submit_Template">
          <b-row>
            <b-col md="8">
              <validation-provider name="name" :rules="{ required: true }" v-slot="validationContext">
                <b-form-group :label="$t('Template_Name') + ' *'">
                  <b-form-input :state="getValidationState(validationContext)" v-model="template.name"></b-form-input>
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>
            <b-col md="4">
              <b-form-group :label="$t('Category')">
                <b-form-input v-model="template.category"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="12" v-if="templateType === 'email'">
              <b-form-group :label="$t('Email_Subject')">
                <b-form-input v-model="template.subject"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="12">
              <validation-provider name="content" :rules="{ required: true }" v-slot="validationContext">
                <b-form-group :label="$t('Message_Content') + ' *'">
                  <b-form-textarea :rows="templateType === 'email' ? 8 : 4" :state="getValidationState(validationContext)" v-model="template.content"></b-form-textarea>
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
              <small class="text-muted">{{ $t('Available_Variables') }}: {customer_name} {phone} {email} {last_purchase} {total_spent}</small>
            </b-col>
            <b-col md="12" class="mt-3">
              <b-button variant="primary" type="submit" :disabled="SubmitProcessing">
                <lucide-icon class="me-2 font-weight-bold" name="check" /> {{ $t('submit') }}
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
  metaInfo: { title: "Templates" },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      serverParams: { sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      totalRows: "",
      search: "",
      limit: "10",
      editmode: false,
      templates: [],
      template: this.empty_template()
    };
  },
  computed: {
    templateType() { return this.$route.meta.templateType || "sms"; },
    pageTitle() {
      const map = { sms: this.$t("SMS_Templates"), whatsapp: this.$t("WhatsApp_Templates"), email: this.$t("Email_Templates") };
      return map[this.templateType] || this.$t("Marketing_Templates");
    },
    columns() {
      return [
        { label: this.$t("Template_Name"), field: "name", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Category"), field: "category", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Action"), field: "actions", tdClass: "text-left", thClass: "text-left", sortable: false }
      ];
    }
  },
  watch: {
    "$route"() {
      this.isLoading = true;
      this.Get_Templates(1);
    }
  },
  methods: {
    empty_template() { return { id: "", name: "", category: "", subject: "", content: "" }; },
    getValidationState({ dirty, validated, valid = null }) { return dirty || validated ? valid : null; },
    makeToast(variant, msg, title) { this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true }); },
    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) { this.updateParams({ page: currentPage }); this.Get_Templates(currentPage); }
    },
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) { this.limit = currentPerPage; this.updateParams({ page: 1, perPage: currentPerPage }); this.Get_Templates(1); }
    },
    onSortChange(params) {
      this.updateParams({ sort: { type: params[0].type, field: params[0].field } });
      this.Get_Templates(this.serverParams.page);
    },
    onSearch(value) { this.search = value.searchTerm; this.Get_Templates(this.serverParams.page); },

    New_Template() { this.template = this.empty_template(); this.editmode = false; this.$bvModal.show("New_Template"); },
    Edit_Template(row) { this.template = { ...this.empty_template(), ...row }; this.editmode = true; this.$bvModal.show("New_Template"); },

    Duplicate_Template(id) {
      axios.post("marketing/templates/" + id + "/duplicate").then(() => {
        this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
        this.Get_Templates(this.serverParams.page);
      }).catch(() => { this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed")); });
    },

    Get_Templates(page) {
      this.serverParams.page = page;
      NProgress.start();
      NProgress.set(0.1);
      axios.get("marketing/templates?page=" + page +
        "&type=" + this.templateType +
        "&SortField=" + this.serverParams.sort.field +
        "&SortType=" + this.serverParams.sort.type +
        "&search=" + this.search + "&limit=" + this.limit)
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.templates = response.data.templates;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => { NProgress.done(); setTimeout(() => { this.isLoading = false; }, 500); });
    },

    Submit_Template() {
      this.$refs.Template_Form.validate().then(success => {
        if (!success) { this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed")); return; }
        this.SubmitProcessing = true;
        const payload = {
          name: this.template.name,
          type: this.templateType,
          category: this.template.category,
          subject: this.template.subject,
          content: this.template.content
        };
        const req = this.editmode
          ? axios.put("marketing/templates/" + this.template.id, payload)
          : axios.post("marketing/templates", payload);
        req.then(() => {
          this.SubmitProcessing = false;
          this.$bvModal.hide("New_Template");
          this.makeToast("success", this.$t(this.editmode ? "Updated_in_successfully" : "Created_in_successfully"), this.$t("Success"));
          this.Get_Templates(this.serverParams.page);
        }).catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
      });
    },

    Remove_Template(id) {
      this.$swal({
        title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning",
        showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios.delete("marketing/templates/" + id).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            this.Get_Templates(this.serverParams.page);
          }).catch(() => {
            this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
          });
        }
      });
    }
  },
  created() {
    this.Get_Templates(1);
  }
};
</script>
