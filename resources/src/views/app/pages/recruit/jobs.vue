<template>
  <div class="main-content">
    <breadcumb :page="$t('Jobs')" :folder="$t('Recruit')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <b-row class="mb-3">
        <b-col md="4">
          <b-form-group :label="$t('Status')">
            <b-form-select v-model="status_filter" @change="Get_Jobs(1)">
              <b-form-select-option value="">{{ $t('All') }}</b-form-select-option>
              <b-form-select-option value="draft">{{ $t('Draft') }}</b-form-select-option>
              <b-form-select-option value="open">{{ $t('Open') }}</b-form-select-option>
              <b-form-select-option value="on_hold">{{ $t('On_Hold') }}</b-form-select-option>
              <b-form-select-option value="closed">{{ $t('Closed') }}</b-form-select-option>
            </b-form-select>
          </b-form-group>
        </b-col>
      </b-row>

      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="jobs"
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
          <b-button @click="New_Job()" class="btn-rounded" variant="btn btn-primary btn-icon m-1">
            <lucide-icon name="plus" /> {{ $t('Add') }}
          </b-button>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'job_type'">
            <span class="badge badge-outline-info">{{ format_label(props.row.job_type) }}</span>
          </span>
          <span v-else-if="props.column.field == 'status'">
            <span class="badge" :class="status_class(props.row.status)">{{ format_label(props.row.status) }}</span>
          </span>
          <span v-else-if="props.column.field == 'category'">
            {{ props.row.category ? props.row.category.name : '-' }}
          </span>
          <span v-else-if="props.column.field == 'actions'">
            <a @click="Edit_Job(props.row)" class="cursor-pointer" title="Edit" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-success" name="pencil" />
            </a>
            <a title="Delete" v-b-tooltip.hover class="cursor-pointer" @click="Remove_Job(props.row.id)">
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>
        </template>
      </vue-good-table>
    </b-card>

    <validation-observer ref="Create_Job">
      <b-modal hide-footer size="lg" id="New_Job" :title="editmode ? $t('Edit') : $t('Add')">
        <b-form @submit.prevent="Submit_Job">
          <b-row>
            <b-col md="6">
              <validation-provider name="title" :rules="{ required: true }" v-slot="validationContext">
                <b-form-group :label="$t('Job_Title') + ' *'">
                  <b-form-input :state="getValidationState(validationContext)" v-model="job.title"></b-form-input>
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <b-col md="6">
              <b-form-group :label="$t('Category')">
                <v-select
                  v-model="job.category_id"
                  :reduce="label => label.value"
                  :placeholder="$t('Choose_Category')"
                  :options="categories.map(c => ({ label: c.name, value: c.id }))"
                />
              </b-form-group>
            </b-col>

            <b-col md="4">
              <b-form-group :label="$t('Job_Type') + ' *'">
                <b-form-select v-model="job.job_type">
                  <b-form-select-option value="full_time">{{ $t('Full_Time') }}</b-form-select-option>
                  <b-form-select-option value="part_time">{{ $t('Part_Time') }}</b-form-select-option>
                  <b-form-select-option value="contract">{{ $t('Contract') }}</b-form-select-option>
                  <b-form-select-option value="internship">{{ $t('Internship') }}</b-form-select-option>
                  <b-form-select-option value="remote">{{ $t('Remote') }}</b-form-select-option>
                </b-form-select>
              </b-form-group>
            </b-col>

            <b-col md="4">
              <b-form-group :label="$t('Experience_Level')">
                <b-form-select v-model="job.experience_level">
                  <b-form-select-option value="entry">{{ $t('Entry') }}</b-form-select-option>
                  <b-form-select-option value="mid">{{ $t('Mid') }}</b-form-select-option>
                  <b-form-select-option value="senior">{{ $t('Senior') }}</b-form-select-option>
                  <b-form-select-option value="lead">{{ $t('Lead') }}</b-form-select-option>
                  <b-form-select-option value="manager">{{ $t('Manager') }}</b-form-select-option>
                </b-form-select>
              </b-form-group>
            </b-col>

            <b-col md="4">
              <b-form-group :label="$t('Status')">
                <b-form-select v-model="job.status">
                  <b-form-select-option value="draft">{{ $t('Draft') }}</b-form-select-option>
                  <b-form-select-option value="open">{{ $t('Open') }}</b-form-select-option>
                  <b-form-select-option value="on_hold">{{ $t('On_Hold') }}</b-form-select-option>
                  <b-form-select-option value="closed">{{ $t('Closed') }}</b-form-select-option>
                </b-form-select>
              </b-form-group>
            </b-col>

            <b-col md="4">
              <b-form-group :label="$t('Location')">
                <b-form-input v-model="job.location"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="4">
              <b-form-group :label="$t('Vacancies')">
                <b-form-input type="number" min="1" v-model="job.vacancies"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="4">
              <b-form-group :label="$t('Deadline')">
                <b-form-input type="date" v-model="job.deadline"></b-form-input>
              </b-form-group>
            </b-col>

            <b-col md="4">
              <b-form-group :label="$t('Salary_Min')">
                <b-form-input type="number" v-model="job.salary_min"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="4">
              <b-form-group :label="$t('Salary_Max')">
                <b-form-input type="number" v-model="job.salary_max"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="4">
              <b-form-group :label="$t('Currency')">
                <b-form-input v-model="job.currency"></b-form-input>
              </b-form-group>
            </b-col>

            <b-col md="12">
              <validation-provider name="description" :rules="{ required: true }" v-slot="validationContext">
                <b-form-group :label="$t('Description') + ' *'">
                  <b-form-textarea :state="getValidationState(validationContext)" v-model="job.description" rows="3"></b-form-textarea>
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>
            <b-col md="6">
              <b-form-group :label="$t('Requirements')">
                <b-form-textarea v-model="job.requirements" rows="3"></b-form-textarea>
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group :label="$t('Benefits')">
                <b-form-textarea v-model="job.benefits" rows="3"></b-form-textarea>
              </b-form-group>
            </b-col>

            <b-col md="12" class="mt-3">
              <b-button variant="primary" type="submit" :disabled="SubmitProcessing">
                <lucide-icon class="me-2 font-weight-bold" name="check" /> {{ $t('submit') }}
              </b-button>
              <div v-once class="typo__p" v-if="SubmitProcessing">
                <div class="spinner sm spinner-primary mt-3"></div>
              </div>
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
  metaInfo: { title: "Jobs" },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      serverParams: { columnFilters: {}, sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      selectedIds: [],
      totalRows: "",
      search: "",
      limit: "10",
      status_filter: "",
      editmode: false,
      jobs: [],
      categories: [],
      job: this.empty_job()
    };
  },

  computed: {
    columns() {
      return [
        { label: this.$t("Job_Title"), field: "title", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Category"), field: "category", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Job_Type"), field: "job_type", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Location"), field: "location", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Status"), field: "status", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Applications"), field: "applications_count", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Action"), field: "actions", tdClass: "text-left", thClass: "text-left", sortable: false }
      ];
    }
  },

  methods: {
    empty_job() {
      return {
        id: "", title: "", category_id: "", job_type: "full_time", experience_level: "entry",
        status: "draft", location: "", vacancies: 1, deadline: "", salary_min: "", salary_max: "",
        currency: "USD", description: "", requirements: "", benefits: ""
      };
    },
    format_label(v) {
      return v ? v.replace(/_/g, " ") : "-";
    },
    status_class(s) {
      const map = { open: "badge-outline-success", draft: "badge-outline-warning", on_hold: "badge-outline-info", closed: "badge-outline-secondary" };
      return map[s] || "badge-outline-secondary";
    },
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) {
        this.updateParams({ page: currentPage });
        this.Get_Jobs(currentPage);
      }
    },
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.updateParams({ page: 1, perPage: currentPerPage });
        this.Get_Jobs(1);
      }
    },
    selectionChanged({ selectedRows }) {
      this.selectedIds = [];
      selectedRows.forEach(row => this.selectedIds.push(row.id));
    },
    onSortChange(params) {
      this.updateParams({ sort: { type: params[0].type, field: params[0].field } });
      this.Get_Jobs(this.serverParams.page);
    },
    onSearch(value) {
      this.search = value.searchTerm;
      this.Get_Jobs(this.serverParams.page);
    },
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },

    Submit_Job() {
      this.$refs.Create_Job.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) this.Create_Job();
          else this.Update_Job();
        }
      });
    },

    New_Job() {
      this.reset_Form();
      this.editmode = false;
      this.Get_Categories();
      this.$bvModal.show("New_Job");
    },

    Edit_Job(job) {
      this.reset_Form();
      this.Get_Categories();
      this.job = { ...this.empty_job(), ...job };
      this.editmode = true;
      this.$bvModal.show("New_Job");
    },

    Get_Categories() {
      axios.get("recruit/categories_all").then(({ data }) => (this.categories = data));
    },

    Get_Jobs(page) {
      this.serverParams.page = page;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "recruit/jobs?page=" + page +
          "&SortField=" + this.serverParams.sort.field +
          "&SortType=" + this.serverParams.sort.type +
          "&search=" + this.search +
          "&status=" + this.status_filter +
          "&limit=" + this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.jobs = response.data.jobs;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Create_Job() {
      this.SubmitProcessing = true;
      axios
        .post("recruit/jobs", this.job)
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Job");
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Job() {
      this.SubmitProcessing = true;
      axios
        .put("recruit/jobs/" + this.job.id, this.job)
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Job");
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.job = this.empty_job();
    },

    Remove_Job(id) {
      this.$swal({
        title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning",
        showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios.delete("recruit/jobs/" + id).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            Fire.$emit("Event_Job");
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
          axios.post("recruit/jobs/delete/by_selection", { selectedIds: this.selectedIds }).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            Fire.$emit("Event_Job");
          }).catch(() => {
            this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
          });
        }
      });
    }
  },

  created: function () {
    this.Get_Jobs(1);
    Fire.$on("Event_Job", () => {
      setTimeout(() => {
        this.Get_Jobs(this.serverParams.page);
        this.$bvModal.hide("New_Job");
      }, 500);
    });
  }
};
</script>
