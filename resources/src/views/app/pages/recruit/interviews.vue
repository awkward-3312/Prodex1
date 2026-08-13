<template>
  <div class="main-content">
    <breadcumb :page="$t('Interviews')" :folder="$t('Recruit')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <b-row class="mb-3">
        <b-col md="4">
          <b-form-group :label="$t('Status')">
            <b-form-select v-model="status_filter" @change="Get_Interviews(1)">
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
        :rows="interviews"
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
          <b-button @click="New_Interview()" class="btn-rounded" variant="btn btn-primary btn-icon m-1">
            <lucide-icon name="plus" /> {{ $t('Add') }}
          </b-button>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'candidate'">
            {{ candidate_name(props.row) }}
          </span>
          <span v-else-if="props.column.field == 'job'">
            {{ props.row.application && props.row.application.job ? props.row.application.job.title : '-' }}
          </span>
          <span v-else-if="props.column.field == 'type'">
            <span class="badge badge-outline-info">{{ format_label(props.row.type) }}</span>
          </span>
          <span v-else-if="props.column.field == 'status'">
            <span class="badge" :class="status_class(props.row.status)">{{ format_label(props.row.status) }}</span>
          </span>
          <span v-else-if="props.column.field == 'actions'">
            <a @click="Edit_Interview(props.row)" class="cursor-pointer" title="Edit" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-success" name="pencil" />
            </a>
            <a title="Delete" v-b-tooltip.hover class="cursor-pointer" @click="Remove_Interview(props.row.id)">
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>
        </template>
      </vue-good-table>
    </b-card>

    <validation-observer ref="Create_Interview">
      <b-modal hide-footer size="md" id="New_Interview" :title="editmode ? $t('Edit') : $t('Add')">
        <b-form @submit.prevent="Submit_Interview">
          <b-row>
            <b-col md="12">
              <validation-provider name="application" :rules="{ required: true }">
                <b-form-group slot-scope="{ valid, errors }" :label="$t('Application') + ' *'">
                  <v-select
                    :class="{ 'is-invalid': !!errors.length }"
                    v-model="interview.application_id"
                    :reduce="label => label.value"
                    :placeholder="$t('Choose_Application')"
                    :options="applications.map(a => ({ label: application_label(a), value: a.id }))"
                  />
                  <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <b-col md="6">
              <b-form-group :label="$t('Type') + ' *'">
                <b-form-select v-model="interview.type">
                  <b-form-select-option v-for="t in types" :key="t" :value="t">{{ format_label(t) }}</b-form-select-option>
                </b-form-select>
              </b-form-group>
            </b-col>
            <b-col md="6">
              <validation-provider name="scheduled_at" :rules="{ required: true }" v-slot="validationContext">
                <b-form-group :label="$t('Scheduled_At') + ' *'">
                  <b-form-input type="datetime-local" :state="getValidationState(validationContext)" v-model="interview.scheduled_at"></b-form-input>
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <b-col md="6">
              <b-form-group :label="$t('Duration_Minutes')">
                <b-form-input type="number" min="0" v-model="interview.duration_minutes"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group :label="$t('Status')">
                <b-form-select v-model="interview.status">
                  <b-form-select-option v-for="s in statuses" :key="s" :value="s">{{ format_label(s) }}</b-form-select-option>
                </b-form-select>
              </b-form-group>
            </b-col>

            <b-col md="6">
              <b-form-group :label="$t('Location')">
                <b-form-input v-model="interview.location"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group :label="$t('Meeting_Link')">
                <b-form-input v-model="interview.meeting_link"></b-form-input>
              </b-form-group>
            </b-col>

            <b-col md="12">
              <b-form-group :label="$t('Rating')">
                <b-form-input type="number" min="0" max="5" v-model="interview.rating"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="12">
              <b-form-group :label="$t('Feedback')">
                <b-form-textarea v-model="interview.feedback" rows="2"></b-form-textarea>
              </b-form-group>
            </b-col>
            <b-col md="12">
              <b-form-group :label="$t('Notes')">
                <b-form-textarea v-model="interview.notes" rows="2"></b-form-textarea>
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
  metaInfo: { title: "Interviews" },
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
      types: ["phone", "video", "in_person", "technical", "panel", "group"],
      statuses: ["scheduled", "completed", "cancelled", "no_show", "rescheduled"],
      editmode: false,
      interviews: [],
      applications: [],
      interview: this.empty_interview()
    };
  },

  computed: {
    columns() {
      return [
        { label: this.$t("Candidate"), field: "candidate", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Job"), field: "job", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Type"), field: "type", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Scheduled_At"), field: "scheduled_at", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Status"), field: "status", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Action"), field: "actions", tdClass: "text-left", thClass: "text-left", sortable: false }
      ];
    }
  },

  methods: {
    empty_interview() {
      return {
        id: "", application_id: "", type: "in_person", scheduled_at: "", duration_minutes: 60,
        location: "", meeting_link: "", status: "scheduled", rating: "", feedback: "", notes: ""
      };
    },
    format_label(v) {
      return v ? v.replace(/_/g, " ") : "-";
    },
    status_class(s) {
      const map = { scheduled: "badge-outline-primary", completed: "badge-outline-success", cancelled: "badge-outline-danger", no_show: "badge-outline-secondary", rescheduled: "badge-outline-warning" };
      return map[s] || "badge-outline-secondary";
    },
    candidate_name(row) {
      const c = row.application && row.application.candidate;
      return c ? c.first_name + " " + c.last_name : "-";
    },
    application_label(a) {
      const c = a.candidate ? a.candidate.first_name + " " + a.candidate.last_name : "?";
      const j = a.job ? a.job.title : "?";
      return c + " - " + j;
    },
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) {
        this.updateParams({ page: currentPage });
        this.Get_Interviews(currentPage);
      }
    },
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.updateParams({ page: 1, perPage: currentPerPage });
        this.Get_Interviews(1);
      }
    },
    selectionChanged({ selectedRows }) {
      this.selectedIds = [];
      selectedRows.forEach(row => this.selectedIds.push(row.id));
    },
    onSortChange(params) {
      this.updateParams({ sort: { type: params[0].type, field: params[0].field } });
      this.Get_Interviews(this.serverParams.page);
    },
    onSearch(value) {
      this.search = value.searchTerm;
      this.Get_Interviews(this.serverParams.page);
    },
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },

    Submit_Interview() {
      this.$refs.Create_Interview.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) this.Create_Interview();
          else this.Update_Interview();
        }
      });
    },

    New_Interview() {
      this.reset_Form();
      this.editmode = false;
      this.Get_FormData();
      this.$bvModal.show("New_Interview");
    },

    Edit_Interview(interview) {
      this.reset_Form();
      this.Get_FormData();
      this.interview = { ...this.empty_interview(), ...interview };
      // Normalize datetime for the datetime-local input (expects "YYYY-MM-DDTHH:mm").
      if (this.interview.scheduled_at) {
        this.interview.scheduled_at = this.interview.scheduled_at.replace(" ", "T").substring(0, 16);
      }
      this.editmode = true;
      this.$bvModal.show("New_Interview");
    },

    Get_FormData() {
      axios.get("recruit/applications_all").then(({ data }) => (this.applications = data));
    },

    Get_Interviews(page) {
      this.serverParams.page = page;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "recruit/interviews?page=" + page +
          "&SortField=" + this.serverParams.sort.field +
          "&SortType=" + this.serverParams.sort.type +
          "&search=" + this.search +
          "&status=" + this.status_filter +
          "&limit=" + this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.interviews = response.data.interviews;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Create_Interview() {
      this.SubmitProcessing = true;
      axios
        .post("recruit/interviews", this.interview)
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Interview");
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Interview() {
      this.SubmitProcessing = true;
      axios
        .put("recruit/interviews/" + this.interview.id, this.interview)
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Interview");
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.interview = this.empty_interview();
    },

    Remove_Interview(id) {
      this.$swal({
        title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning",
        showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios.delete("recruit/interviews/" + id).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            Fire.$emit("Event_Interview");
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
          axios.post("recruit/interviews/delete/by_selection", { selectedIds: this.selectedIds }).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            Fire.$emit("Event_Interview");
          }).catch(() => {
            this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
          });
        }
      });
    }
  },

  created: function () {
    this.Get_Interviews(1);
    Fire.$on("Event_Interview", () => {
      setTimeout(() => {
        this.Get_Interviews(this.serverParams.page);
        this.$bvModal.hide("New_Interview");
      }, 500);
    });
  }
};
</script>
