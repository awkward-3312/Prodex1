<template>
  <div class="main-content">
    <breadcumb :page="$t('Candidates')" :folder="$t('Recruit')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <b-row class="mb-3">
        <b-col md="4">
          <b-form-group :label="$t('Source')">
            <b-form-select v-model="source_filter" @change="Get_Candidates(1)">
              <b-form-select-option value="">{{ $t('All') }}</b-form-select-option>
              <b-form-select-option v-for="s in sources" :key="s" :value="s">{{ format_label(s) }}</b-form-select-option>
            </b-form-select>
          </b-form-group>
        </b-col>
      </b-row>

      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="candidates"
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
          <b-button @click="New_Candidate()" class="btn-rounded" variant="btn btn-primary btn-icon m-1">
            <lucide-icon name="plus" /> {{ $t('Add') }}
          </b-button>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'full_name'">
            {{ props.row.first_name }} {{ props.row.last_name }}
          </span>
          <span v-else-if="props.column.field == 'source'">
            <span class="badge badge-outline-info">{{ format_label(props.row.source) }}</span>
          </span>
          <span v-else-if="props.column.field == 'actions'">
            <a @click="Edit_Candidate(props.row)" class="cursor-pointer" title="Edit" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-success" name="pencil" />
            </a>
            <a title="Delete" v-b-tooltip.hover class="cursor-pointer" @click="Remove_Candidate(props.row.id)">
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>
        </template>
      </vue-good-table>
    </b-card>

    <validation-observer ref="Create_Candidate">
      <b-modal hide-footer size="lg" id="New_Candidate" :title="editmode ? $t('Edit') : $t('Add')">
        <b-form @submit.prevent="Submit_Candidate">
          <b-row>
            <b-col md="6">
              <validation-provider name="first_name" :rules="{ required: true }" v-slot="validationContext">
                <b-form-group :label="$t('First_Name') + ' *'">
                  <b-form-input :state="getValidationState(validationContext)" v-model="candidate.first_name"></b-form-input>
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>
            <b-col md="6">
              <validation-provider name="last_name" :rules="{ required: true }" v-slot="validationContext">
                <b-form-group :label="$t('Last_Name') + ' *'">
                  <b-form-input :state="getValidationState(validationContext)" v-model="candidate.last_name"></b-form-input>
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <b-col md="6">
              <validation-provider name="email" :rules="{ required: true, email: true }" v-slot="validationContext">
                <b-form-group :label="$t('Email') + ' *'">
                  <b-form-input :state="getValidationState(validationContext)" v-model="candidate.email"></b-form-input>
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>
            <b-col md="6">
              <b-form-group :label="$t('Phone')">
                <b-form-input v-model="candidate.phone"></b-form-input>
              </b-form-group>
            </b-col>

            <b-col md="4">
              <b-form-group :label="$t('Source')">
                <b-form-select v-model="candidate.source">
                  <b-form-select-option v-for="s in sources" :key="s" :value="s">{{ format_label(s) }}</b-form-select-option>
                </b-form-select>
              </b-form-group>
            </b-col>
            <b-col md="4">
              <b-form-group :label="$t('Current_Position')">
                <b-form-input v-model="candidate.current_position"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="4">
              <b-form-group :label="$t('Experience_Years')">
                <b-form-input type="number" min="0" v-model="candidate.experience_years"></b-form-input>
              </b-form-group>
            </b-col>

            <b-col md="6">
              <b-form-group :label="$t('Current_Company')">
                <b-form-input v-model="candidate.current_company"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="3">
              <b-form-group :label="$t('Current_Salary')">
                <b-form-input type="number" v-model="candidate.current_salary"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="3">
              <b-form-group :label="$t('Expected_Salary')">
                <b-form-input type="number" v-model="candidate.expected_salary"></b-form-input>
              </b-form-group>
            </b-col>

            <b-col md="6">
              <b-form-group :label="$t('City')">
                <b-form-input v-model="candidate.city"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group :label="$t('Country')">
                <b-form-input v-model="candidate.country"></b-form-input>
              </b-form-group>
            </b-col>

            <b-col md="6">
              <b-form-group :label="$t('LinkedIn_URL')">
                <b-form-input v-model="candidate.linkedin_url"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group :label="$t('Portfolio_URL')">
                <b-form-input v-model="candidate.portfolio_url"></b-form-input>
              </b-form-group>
            </b-col>

            <b-col md="6">
              <b-form-group :label="$t('Resume')">
                <b-form-file accept=".pdf,.doc,.docx" @change="onResumeChange" :placeholder="$t('Choose_a_file')"></b-form-file>
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group :label="$t('Photo')">
                <b-form-file accept="image/*" @change="onPhotoChange" :placeholder="$t('Choose_a_file')"></b-form-file>
              </b-form-group>
            </b-col>

            <b-col md="12">
              <b-form-group :label="$t('Skills')">
                <b-form-textarea v-model="candidate.skills" rows="2"></b-form-textarea>
              </b-form-group>
            </b-col>
            <b-col md="12">
              <b-form-group :label="$t('Notes')">
                <b-form-textarea v-model="candidate.notes" rows="2"></b-form-textarea>
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
  metaInfo: { title: "Candidates" },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      serverParams: { columnFilters: {}, sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      selectedIds: [],
      totalRows: "",
      search: "",
      limit: "10",
      source_filter: "",
      sources: ["website", "referral", "linkedin", "job_board", "agency", "walk_in", "other"],
      editmode: false,
      candidates: [],
      resumeFile: null,
      photoFile: null,
      candidate: this.empty_candidate()
    };
  },

  computed: {
    columns() {
      return [
        { label: this.$t("Name"), field: "full_name", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Email"), field: "email", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Phone"), field: "phone", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Source"), field: "source", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Applications"), field: "applications_count", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Action"), field: "actions", tdClass: "text-left", thClass: "text-left", sortable: false }
      ];
    }
  },

  methods: {
    empty_candidate() {
      return {
        id: "", first_name: "", last_name: "", email: "", phone: "", source: "website",
        current_position: "", current_company: "", current_salary: "", expected_salary: "",
        experience_years: "", city: "", country: "", linkedin_url: "", portfolio_url: "",
        skills: "", notes: ""
      };
    },
    format_label(v) {
      return v ? v.replace(/_/g, " ") : "-";
    },
    onResumeChange(e) {
      this.resumeFile = e.target.files[0];
    },
    onPhotoChange(e) {
      this.photoFile = e.target.files[0];
    },
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) {
        this.updateParams({ page: currentPage });
        this.Get_Candidates(currentPage);
      }
    },
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.updateParams({ page: 1, perPage: currentPerPage });
        this.Get_Candidates(1);
      }
    },
    selectionChanged({ selectedRows }) {
      this.selectedIds = [];
      selectedRows.forEach(row => this.selectedIds.push(row.id));
    },
    onSortChange(params) {
      this.updateParams({ sort: { type: params[0].type, field: params[0].field } });
      this.Get_Candidates(this.serverParams.page);
    },
    onSearch(value) {
      this.search = value.searchTerm;
      this.Get_Candidates(this.serverParams.page);
    },
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },

    build_form_data() {
      const fd = new FormData();
      Object.keys(this.candidate).forEach(key => {
        if (key === "id") return;
        const val = this.candidate[key];
        if (val !== null && val !== undefined && val !== "") fd.append(key, val);
      });
      if (this.resumeFile) fd.append("resume", this.resumeFile);
      if (this.photoFile) fd.append("photo", this.photoFile);
      return fd;
    },

    Submit_Candidate() {
      this.$refs.Create_Candidate.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) this.Create_Candidate();
          else this.Update_Candidate();
        }
      });
    },

    New_Candidate() {
      this.reset_Form();
      this.editmode = false;
      this.$bvModal.show("New_Candidate");
    },

    Edit_Candidate(candidate) {
      this.reset_Form();
      this.candidate = { ...this.empty_candidate(), ...candidate };
      this.editmode = true;
      this.$bvModal.show("New_Candidate");
    },

    Get_Candidates(page) {
      this.serverParams.page = page;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "recruit/candidates?page=" + page +
          "&SortField=" + this.serverParams.sort.field +
          "&SortType=" + this.serverParams.sort.type +
          "&search=" + this.search +
          "&source=" + this.source_filter +
          "&limit=" + this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.candidates = response.data.candidates;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Create_Candidate() {
      this.SubmitProcessing = true;
      axios
        .post("recruit/candidates", this.build_form_data())
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Candidate");
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Candidate() {
      this.SubmitProcessing = true;
      const fd = this.build_form_data();
      fd.append("_method", "PUT");
      axios
        .post("recruit/candidates/" + this.candidate.id, fd)
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Candidate");
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.candidate = this.empty_candidate();
      this.resumeFile = null;
      this.photoFile = null;
    },

    Remove_Candidate(id) {
      this.$swal({
        title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning",
        showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios.delete("recruit/candidates/" + id).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            Fire.$emit("Event_Candidate");
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
          axios.post("recruit/candidates/delete/by_selection", { selectedIds: this.selectedIds }).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            Fire.$emit("Event_Candidate");
          }).catch(() => {
            this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
          });
        }
      });
    }
  },

  created: function () {
    this.Get_Candidates(1);
    Fire.$on("Event_Candidate", () => {
      setTimeout(() => {
        this.Get_Candidates(this.serverParams.page);
        this.$bvModal.hide("New_Candidate");
      }, 500);
    });
  }
};
</script>
