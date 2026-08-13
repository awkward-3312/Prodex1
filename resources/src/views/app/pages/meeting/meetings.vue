<template>
  <div class="main-content">
    <breadcumb :page="$t('Meetings')" :folder="$t('Meeting_Management')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <b-row class="mb-3">
        <b-col md="3">
          <b-form-group :label="$t('Status')">
            <b-form-select v-model="status_filter" @change="Get_Meetings(1)">
              <b-form-select-option value="">{{ $t('All') }}</b-form-select-option>
              <b-form-select-option v-for="s in statuses" :key="s" :value="s">{{ format_label(s) }}</b-form-select-option>
            </b-form-select>
          </b-form-group>
        </b-col>
        <b-col md="3">
          <b-form-group :label="$t('Meeting_Type')">
            <b-form-select v-model="type_filter" @change="Get_Meetings(1)">
              <b-form-select-option value="">{{ $t('All') }}</b-form-select-option>
              <b-form-select-option v-for="t in types" :key="t" :value="t">{{ format_label(t) }}</b-form-select-option>
            </b-form-select>
          </b-form-group>
        </b-col>
        <b-col md="3">
          <b-form-group :label="$t('Date_From') || 'Date From'">
            <b-form-input type="date" v-model="date_from" @change="Get_Meetings(1)"></b-form-input>
          </b-form-group>
        </b-col>
        <b-col md="3">
          <b-form-group :label="$t('Date_To') || 'Date To'">
            <b-form-input type="date" v-model="date_to" @change="Get_Meetings(1)"></b-form-input>
          </b-form-group>
        </b-col>
      </b-row>

      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="meetings"
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
          <b-button @click="New_Meeting()" class="btn-rounded" variant="btn btn-primary btn-icon m-1">
            <lucide-icon name="plus" /> {{ $t('New_Meeting') }}
          </b-button>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'title'">
            <a class="cursor-pointer text-primary font-weight-bold" @click="View_Details(props.row.id)">{{ props.row.title }}</a>
          </span>
          <span v-else-if="props.column.field == 'datetime'">
            {{ props.row.meeting_date }} {{ short_time(props.row.start_time) }}
          </span>
          <span v-else-if="props.column.field == 'type'">
            <span class="badge badge-outline-info">{{ format_label(props.row.type) }}</span>
          </span>
          <span v-else-if="props.column.field == 'organizer'">
            {{ user_name(props.row.organizer) }}
          </span>
          <span v-else-if="props.column.field == 'participants_count'">
            <span class="badge badge-outline-secondary">{{ props.row.participants_count }}</span>
          </span>
          <span v-else-if="props.column.field == 'status'">
            <span class="badge" :class="status_class(props.row.status)">{{ format_label(props.row.status) }}</span>
          </span>
          <span v-else-if="props.column.field == 'actions'">
            <a v-if="props.row.type === 'online' && props.row.meeting_link" :href="props.row.meeting_link" target="_blank" rel="noopener" class="cursor-pointer" :title="$t('Join_Meeting')" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-info" name="video" />
            </a>
            <a @click="View_Details(props.row.id)" class="cursor-pointer" :title="$t('View_Details')" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-primary" name="eye" />
            </a>
            <a @click="Edit_Meeting(props.row)" class="cursor-pointer" title="Edit" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-success" name="pencil" />
            </a>
            <a :title="$t('Delete')" v-b-tooltip.hover class="cursor-pointer" @click="Remove_Meeting(props.row.id)">
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>
        </template>
      </vue-good-table>
    </b-card>

    <validation-observer ref="Create_Meeting">
      <b-modal hide-footer size="lg" id="New_Meeting" :title="editmode ? $t('Edit') : $t('New_Meeting')">
        <b-form @submit.prevent="Submit_Meeting">
          <b-row>
            <b-col md="12">
              <validation-provider name="title" :rules="{ required: true }" v-slot="validationContext">
                <b-form-group :label="$t('Meeting_Title') + ' *'">
                  <b-form-input :state="getValidationState(validationContext)" v-model="meeting.title"></b-form-input>
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <b-col md="4">
              <validation-provider name="meeting_date" :rules="{ required: true }" v-slot="validationContext">
                <b-form-group :label="$t('Meeting_Date') + ' *'">
                  <b-form-input type="date" :state="getValidationState(validationContext)" v-model="meeting.meeting_date"></b-form-input>
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>
            <b-col md="4">
              <validation-provider name="start_time" :rules="{ required: true }" v-slot="validationContext">
                <b-form-group :label="$t('Start_Time') + ' *'">
                  <b-form-input type="time" :state="getValidationState(validationContext)" v-model="meeting.start_time"></b-form-input>
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>
            <b-col md="4">
              <b-form-group :label="$t('End_Time')">
                <b-form-input type="time" v-model="meeting.end_time"></b-form-input>
              </b-form-group>
            </b-col>

            <b-col md="6">
              <b-form-group :label="$t('Meeting_Type') + ' *'">
                <b-form-select v-model="meeting.type">
                  <b-form-select-option v-for="t in types" :key="t" :value="t">{{ format_label(t) }}</b-form-select-option>
                </b-form-select>
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group :label="$t('Status')">
                <b-form-select v-model="meeting.status">
                  <b-form-select-option v-for="s in statuses" :key="s" :value="s">{{ format_label(s) }}</b-form-select-option>
                </b-form-select>
              </b-form-group>
            </b-col>

            <b-col md="12" v-if="meeting.type === 'physical'">
              <b-form-group :label="$t('Meeting_Location')">
                <b-form-input v-model="meeting.location"></b-form-input>
              </b-form-group>
            </b-col>

            <template v-if="meeting.type === 'online'">
              <b-col md="4">
                <b-form-group :label="$t('Platform')">
                  <b-form-select v-model="meeting.platform">
                    <b-form-select-option :value="null">-</b-form-select-option>
                    <b-form-select-option v-for="p in platforms" :key="p" :value="p">{{ platform_label(p) }}</b-form-select-option>
                  </b-form-select>
                </b-form-group>
              </b-col>
              <b-col md="8">
                <b-form-group :label="$t('Meeting_Link')">
                  <b-form-input v-model="meeting.meeting_link" placeholder="https://"></b-form-input>
                </b-form-group>
              </b-col>
            </template>

            <b-col md="6">
              <b-form-group :label="$t('Organizer')">
                <v-select
                  v-model="meeting.organizer_id"
                  :reduce="o => o.value"
                  :placeholder="$t('Organizer')"
                  :options="users.map(u => ({ label: user_label(u), value: u.id }))"
                />
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group :label="$t('Reminder_Minutes')">
                <b-form-input type="number" min="0" v-model="meeting.reminder_minutes"></b-form-input>
              </b-form-group>
            </b-col>

            <b-col md="12">
              <b-form-group :label="$t('Select_Participants')">
                <v-select
                  multiple
                  v-model="meeting.participants"
                  :reduce="o => o.value"
                  :placeholder="$t('Add_Participants')"
                  :options="users.map(u => ({ label: user_label(u), value: u.id }))"
                />
              </b-form-group>
            </b-col>

            <b-col md="12">
              <b-form-group :label="$t('Agenda')">
                <b-form-textarea v-model="meeting.agenda" rows="2"></b-form-textarea>
              </b-form-group>
            </b-col>
            <b-col md="12">
              <b-form-group :label="$t('Description')">
                <b-form-textarea v-model="meeting.description" rows="2"></b-form-textarea>
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
  metaInfo: { title: "Meetings" },
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
      type_filter: "",
      date_from: "",
      date_to: "",
      types: ["physical", "online"],
      statuses: ["scheduled", "ongoing", "completed", "cancelled"],
      platforms: ["zoom", "google_meet", "teams", "other"],
      editmode: false,
      meetings: [],
      users: [],
      meeting: this.empty_meeting()
    };
  },

  computed: {
    columns() {
      return [
        { label: this.$t("Meeting_Title"), field: "title", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Meeting_Date"), field: "datetime", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Meeting_Type"), field: "type", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Organizer"), field: "organizer", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Participants"), field: "participants_count", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Status"), field: "status", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Action"), field: "actions", tdClass: "text-left", thClass: "text-left", sortable: false }
      ];
    }
  },

  methods: {
    empty_meeting() {
      return {
        id: "", title: "", description: "", agenda: "", meeting_date: "", start_time: "", end_time: "",
        location: "", type: "physical", status: "scheduled", platform: null, meeting_link: "",
        reminder_minutes: 30, organizer_id: "", participants: []
      };
    },
    format_label(v) {
      return v ? String(v).replace(/_/g, " ") : "-";
    },
    platform_label(p) {
      const map = { zoom: "Zoom", google_meet: "Google Meet", teams: "Microsoft Teams", other: "Other" };
      return map[p] || p;
    },
    short_time(t) {
      return t ? String(t).substring(0, 5) : "";
    },
    status_class(s) {
      const map = { scheduled: "badge-outline-primary", ongoing: "badge-outline-warning", completed: "badge-outline-success", cancelled: "badge-outline-danger" };
      return map[s] || "badge-outline-secondary";
    },
    user_name(u) {
      if (!u) return "-";
      const n = ((u.firstname || "") + " " + (u.lastname || "")).trim();
      return n || u.username || "-";
    },
    user_label(u) {
      const n = ((u.firstname || "") + " " + (u.lastname || "")).trim();
      return (n || u.username) + (u.email ? " (" + u.email + ")" : "");
    },
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) {
        this.updateParams({ page: currentPage });
        this.Get_Meetings(currentPage);
      }
    },
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.updateParams({ page: 1, perPage: currentPerPage });
        this.Get_Meetings(1);
      }
    },
    selectionChanged({ selectedRows }) {
      this.selectedIds = [];
      selectedRows.forEach(row => this.selectedIds.push(row.id));
    },
    onSortChange(params) {
      this.updateParams({ sort: { type: params[0].type, field: params[0].field } });
      this.Get_Meetings(this.serverParams.page);
    },
    onSearch(value) {
      this.search = value.searchTerm;
      this.Get_Meetings(this.serverParams.page);
    },
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },

    View_Details(id) {
      this.$router.push({ name: "meeting_details", params: { id: id } });
    },

    Submit_Meeting() {
      this.$refs.Create_Meeting.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) this.Create_Meeting();
          else this.Update_Meeting();
        }
      });
    },

    New_Meeting() {
      this.reset_Form();
      this.editmode = false;
      this.Get_FormData();
      this.$bvModal.show("New_Meeting");
    },

    Edit_Meeting(meeting) {
      this.reset_Form();
      this.Get_FormData();
      // Fetch the full meeting (with participants) first so the form opens
      // already populated and a quick save cannot wipe the participant list.
      axios.get("meeting/meetings/" + meeting.id).then(({ data }) => {
        const m = data.meeting;
        this.meeting = {
          ...this.empty_meeting(),
          ...m,
          meeting_date: m.meeting_date ? String(m.meeting_date).substring(0, 10) : "",
          start_time: this.short_time(m.start_time),
          end_time: this.short_time(m.end_time),
          participants: (m.participants || []).map(p => p.user_id)
        };
        this.editmode = true;
        this.$bvModal.show("New_Meeting");
      }).catch(() => {
        this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
      });
    },

    Get_FormData() {
      axios.get("users_list_for_select").then(({ data }) => (this.users = data.users || []));
    },

    Get_Meetings(page) {
      this.serverParams.page = page;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "meeting/meetings?page=" + page +
          "&SortField=" + this.serverParams.sort.field +
          "&SortType=" + this.serverParams.sort.type +
          "&search=" + this.search +
          "&status=" + this.status_filter +
          "&type=" + this.type_filter +
          "&date_from=" + this.date_from +
          "&date_to=" + this.date_to +
          "&limit=" + this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.meetings = response.data.meetings;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Create_Meeting() {
      this.SubmitProcessing = true;
      axios
        .post("meeting/meetings", this.meeting)
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Meeting");
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Meeting() {
      this.SubmitProcessing = true;
      axios
        .put("meeting/meetings/" + this.meeting.id, this.meeting)
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Meeting");
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.meeting = this.empty_meeting();
    },

    Remove_Meeting(id) {
      this.$swal({
        title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning",
        showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios.delete("meeting/meetings/" + id).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            Fire.$emit("Event_Meeting");
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
          axios.post("meeting/meetings/delete/by_selection", { selectedIds: this.selectedIds }).then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            Fire.$emit("Event_Meeting");
          }).catch(() => {
            this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
          });
        }
      });
    }
  },

  created: function () {
    this.Get_Meetings(1);
    Fire.$on("Event_Meeting", () => {
      setTimeout(() => {
        this.Get_Meetings(this.serverParams.page);
        this.$bvModal.hide("New_Meeting");
      }, 500);
    });
  }
};
</script>
