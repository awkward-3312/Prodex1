<template>
  <div class="main-content">
    <breadcumb :page="$t('Applications')" :folder="$t('Recruit')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <b-row class="mb-3">
        <b-col md="4">
          <b-form-group :label="$t('Stage')">
            <b-form-select v-model="stage_filter" @change="Get_Applications(1)">
              <b-form-select-option value="">{{ $t('All') }}</b-form-select-option>
              <b-form-select-option v-for="s in stages" :key="s" :value="s">{{ format_label(s) }}</b-form-select-option>
            </b-form-select>
          </b-form-group>
        </b-col>
      </b-row>

      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="applications"
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
          <b-button @click="New_Application()" class="btn-rounded" variant="btn btn-primary btn-icon m-1">
            <lucide-icon name="plus" /> {{ $t('Add') }}
          </b-button>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'candidate'">
            {{ props.row.candidate ? props.row.candidate.first_name + ' ' + props.row.candidate.last_name : '-' }}
          </span>
          <span v-else-if="props.column.field == 'job'">
            {{ props.row.job ? props.row.job.title : '-' }}
          </span>
          <span v-else-if="props.column.field == 'stage'">
            <b-form-select
              size="sm"
              :value="props.row.stage"
              @change="changeStage(props.row.id, $event)"
              style="max-width: 150px;"
            >
              <b-form-select-option v-for="s in stages" :key="s" :value="s">{{ format_label(s) }}</b-form-select-option>
            </b-form-select>
          </span>
          <span v-else-if="props.column.field == 'actions'">
            <a @click="Edit_Application(props.row)" class="cursor-pointer" title="Edit" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-success" name="pencil" />
            </a>
            <a title="Delete" v-b-tooltip.hover class="cursor-pointer" @click="Remove_Application(props.row.id)">
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>
        </template>
      </vue-good-table>
    </b-card>

    <validation-observer ref="Create_Application">
      <b-modal hide-footer size="md" id="New_Application" :title="editmode ? $t('Edit') : $t('Add')">
        <b-form @submit.prevent="Submit_Application">
          <b-row>
            <b-col md="12">
              <validation-provider name="candidate" :rules="{ required: true }">
                <b-form-group slot-scope="{ valid, errors }" :label="$t('Candidate') + ' *'">
                  <v-select
                    :class="{ 'is-invalid': !!errors.length }"
                    v-model="application.candidate_id"
                    :reduce="label => label.value"
                    :placeholder="$t('Choose_Candidate')"
                    :options="candidates.map(c => ({ label: c.first_name + ' ' + c.last_name + ' (' + c.email + ')', value: c.id }))"
                  />
                  <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <b-col md="12">
              <validation-provider name="job" :rules="{ required: true }">
                <b-form-group slot-scope="{ valid, errors }" :label="$t('Job') + ' *'">
                  <v-select
                    :class="{ 'is-invalid': !!errors.length }"
                    v-model="application.job_id"
                    :reduce="label => label.value"
                    :placeholder="$t('Choose_Job')"
                    :options="jobs.map(j => ({ label: j.title, value: j.id }))"
                  />
                  <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <b-col md="6">
              <b-form-group :label="$t('Stage')">
                <b-form-select v-model="application.stage">
                  <b-form-select-option v-for="s in stages" :key="s" :value="s">{{ format_label(s) }}</b-form-select-option>
                </b-form-select>
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group :label="$t('Applied_Date')">
                <b-form-input type="date" v-model="application.applied_date"></b-form-input>
              </b-form-group>
            </b-col>

            <b-col md="12">
              <b-form-group :label="$t('Rating')">
                <b-form-input type="number" min="0" max="5" v-model="application.rating"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="12">
              <b-form-group :label="$t('Cover_Letter')">
                <b-form-textarea v-model="application.cover_letter" rows="2"></b-form-textarea>
              </b-form-group>
            </b-col>
            <b-col md="12">
              <b-form-group :label="$t('Notes')">
                <b-form-textarea v-model="application.notes" rows="2"></b-form-textarea>
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
  metaInfo: { title: "Applications" },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      serverParams: { columnFilters: {}, sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      selectedIds: [],
      totalRows: "",
      search: "",
      limit: "10",
      stage_filter: "",
      stages: ["applied", "screening", "shortlisted", "interview", "offered", "hired", "rejected"],
      editmode: false,
      applications: [],
      jobs: [],
      candidates: [],
      application: this.empty_application()
    };
  },

  computed: {
    columns() {
      return [
        { label: this.$t("Candidate"), field: "candidate", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Job"), field: "job", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Stage"), field: "stage", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Applied_Date"), field: "applied_date", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Rating"), field: "rating", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Action"), field: "actions", tdClass: "text-left", thClass: "text-left", sortable: false }
      ];
    }
  },

  methods: {
    empty_application() {
      return { id: "", candidate_id: "", job_id: "", stage: "applied", applied_date: "", rating: "", cover_letter: "", notes: "" };
    },
    format_label(v) {
      return v ? v.replace(/_/g, " ") : "-";
    },
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) {
        this.updateParams({ page: currentPage });
        this.Get_Applications(currentPage);
      }
    },
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.updateParams({ page: 1, perPage: currentPerPage });
        this.Get_Applications(1);
      }
    },
    selectionChanged({ selectedRows }) {
      this.selectedIds = [];
      selectedRows.forEach(row => this.selectedIds.push(row.id));
    },
    onSortChange(params) {
      this.updateParams({ sort: { type: params[0].type, field: params[0].field } });
      this.Get_Applications(this.serverParams.page);
    },
    onSearch(value) {
      this.search = value.searchTerm;
      this.Get_Applications(this.serverParams.page);
    },
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },

    Submit_Application() {
      this.$refs.Create_Application.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) this.Create_Application();
          else this.Update_Application();
        }
      });
    },

    New_Application() {
      this.reset_Form();
      this.editmode = false;
      this.Get_FormData();
      this.$bvModal.show("New_Application");
    },

    Edit_Application(application) {
      this.reset_Form();
      this.Get_FormData();
      this.application = { ...this.empty_application(), ...application };
      this.editmode = true;
      this.$bvModal.show("New_Application");
    },

    Get_FormData() {
      axios.get("recruit/jobs_all").then(({ data }) => (this.jobs = data));
      axios.get("recruit/candidates_all").then(({ data }) => (this.candidates = data));
    },

    Get_Applications(page) {
      this.serverParams.page = page;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "recruit/applications?page=" + page +
          "&SortField=" + this.serverParams.sort.field +
          "&SortType=" + this.serverParams.sort.type +
          "&search=" + this.search +
          "&stage=" + this.stage_filter +
          "&limit=" + this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.applications = response.data.applications;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    changeStage(id, stage) {
      axios
        .put("recruit/applications/" + id + "/stage", { stage: stage })
        .then(() => {
          Fire.$emit("Event_Application");
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Create_Application() {
      this.SubmitProcessing = true;
      axios
        .post("recruit/applications", this.application)
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Application");
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Application() {
      this.SubmitProcessing = true;
      axios
        .put("recruit/applications/" + this.application.id, this.application)
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Application");
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.application = this.empty_application();
    },

    Remove_Application(id) {
      this.$swal({
        title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning",
        showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios.delete("recruit/applications/" + id).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            Fire.$emit("Event_Application");
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
          axios.post("recruit/applications/delete/by_selection", { selectedIds: this.selectedIds }).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            Fire.$emit("Event_Application");
          }).catch(() => {
            this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
          });
        }
      });
    }
  },

  created: function () {
    this.Get_Applications(1);
    Fire.$on("Event_Application", () => {
      setTimeout(() => {
        this.Get_Applications(this.serverParams.page);
        this.$bvModal.hide("New_Application");
      }, 500);
    });
  }
};
</script>
