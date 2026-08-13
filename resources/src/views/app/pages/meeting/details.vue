<template>
  <div class="main-content">
    <breadcumb :page="$t('Meeting_Details')" :folder="$t('Meeting_Management')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading && meeting">
      <!-- Header -->
      <b-card class="mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start">
          <div>
            <h4 class="mb-1">{{ meeting.title }}</h4>
            <div class="text-muted mb-2">
              <lucide-icon name="calendar" size="15" /> {{ meeting.meeting_date }}
              <lucide-icon name="clock" size="15" class="ml-2" /> {{ short_time(meeting.start_time) }}<span v-if="meeting.end_time"> - {{ short_time(meeting.end_time) }}</span>
              <span class="badge ml-2" :class="status_class(meeting.status)">{{ format_label(meeting.status) }}</span>
              <span class="badge badge-outline-info ml-1">{{ format_label(meeting.type) }}</span>
            </div>
            <div v-if="meeting.type === 'physical' && meeting.location" class="text-muted">
              <lucide-icon name="map-pin" size="15" /> {{ meeting.location }}
            </div>
            <div v-if="meeting.type === 'online'" class="text-muted">
              <lucide-icon name="link" size="15" /> {{ meeting.meeting_link || '-' }}
              <span v-if="meeting.platform" class="badge badge-outline-secondary ml-1">{{ platform_label(meeting.platform) }}</span>
            </div>
            <div class="text-muted mt-1">
              <lucide-icon name="user" size="15" /> {{ $t('Organizer') }}: {{ user_name(meeting.organizer) }}
            </div>
          </div>
          <div class="text-right">
            <a
              v-if="meeting.type === 'online' && meeting.meeting_link"
              :href="meeting.meeting_link"
              target="_blank"
              rel="noopener"
              class="btn btn-info btn-sm m-1"
            >
              <lucide-icon name="video" size="15" /> {{ $t('Join_Meeting') }}
            </a>
            <b-button v-if="canEdit" variant="outline-primary" size="sm" class="m-1" @click="Send_Invitations()" :disabled="SendingInvites">
              <lucide-icon name="send" size="15" /> {{ $t('Send_Invitations') }}
            </b-button>
            <b-dropdown v-if="canEdit" :text="$t('Status')" variant="outline-secondary" size="sm" class="m-1">
              <b-dropdown-item v-for="s in statuses" :key="s" @click="Change_Status(s)">{{ format_label(s) }}</b-dropdown-item>
            </b-dropdown>
          </div>
        </div>

        <hr v-if="meeting.agenda || meeting.description" />
        <div v-if="meeting.agenda" class="mb-2">
          <strong>{{ $t('Agenda') }}:</strong>
          <div class="text-muted" style="white-space: pre-line">{{ meeting.agenda }}</div>
        </div>
        <div v-if="meeting.description">
          <strong>{{ $t('Description') }}:</strong>
          <div class="text-muted" style="white-space: pre-line">{{ meeting.description }}</div>
        </div>
      </b-card>

      <b-row>
        <!-- Participants & Attendance -->
        <b-col lg="6">
          <b-card class="mb-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="mb-0"><lucide-icon name="users" size="16" /> {{ $t('Participants') }} & {{ $t('Attendance') }}</h6>
              <b-button v-if="canManageAttendance" variant="primary" size="sm" @click="Save_Attendance()" :disabled="SavingAttendance">
                <lucide-icon name="check" size="14" /> {{ $t('Save_Attendance') }}
              </b-button>
            </div>
            <div v-if="!meeting.participants || meeting.participants.length === 0" class="text-muted text-center py-3">
              {{ $t('No_data') }}
            </div>
            <table v-else class="table table-sm">
              <thead>
                <tr>
                  <th>{{ $t('Participant') }}</th>
                  <th>{{ $t('Invitation_Status') }}</th>
                  <th>{{ $t('Attendance_Status') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in meeting.participants" :key="p.id">
                  <td>
                    {{ user_name(p.user) }}
                    <lucide-icon v-if="p.is_notified" name="bell" size="12" class="text-success" v-b-tooltip.hover :title="$t('Invitations_Sent')" />
                  </td>
                  <td><span class="badge" :class="invite_class(p.invitation_status)">{{ format_label(p.invitation_status) }}</span></td>
                  <td>
                    <b-form-select v-if="canManageAttendance" v-model="p.attendance_status" size="sm" style="max-width: 130px">
                      <b-form-select-option v-for="a in attendances" :key="a" :value="a">{{ format_label(a) }}</b-form-select-option>
                    </b-form-select>
                    <span v-else class="badge" :class="attend_class(p.attendance_status)">{{ format_label(p.attendance_status) }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </b-card>

          <!-- Attachments -->
          <b-card class="mb-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="mb-0"><lucide-icon name="paperclip" size="16" /> {{ $t('Attachments') }}</h6>
            </div>
            <div v-if="canEdit" class="mb-3">
              <b-form-file v-model="attachmentFile" :placeholder="$t('Upload_Attachment')" size="sm"></b-form-file>
              <b-button variant="outline-primary" size="sm" class="mt-2" @click="Upload_Attachment()" :disabled="!attachmentFile || UploadingFile">
                <lucide-icon name="upload" size="14" /> {{ $t('Upload_Attachment') }}
              </b-button>
            </div>
            <div v-if="!meeting.attachments || meeting.attachments.length === 0" class="text-muted text-center py-3">
              {{ $t('No_data') }}
            </div>
            <ul v-else class="list-unstyled mb-0">
              <li v-for="f in meeting.attachments" :key="f.id" class="d-flex justify-content-between align-items-center py-1 border-bottom">
                <a :href="'/' + f.file_path" target="_blank" rel="noopener">
                  <lucide-icon name="file" size="14" /> {{ f.file_name }}
                </a>
                <a v-if="canEdit" class="cursor-pointer text-danger" @click="Remove_Attachment(f.id)">
                  <lucide-icon name="x" size="16" />
                </a>
              </li>
            </ul>
          </b-card>
        </b-col>

        <!-- Notes & Minutes -->
        <b-col lg="6">
          <b-card class="mb-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="mb-0"><lucide-icon name="file-text" size="16" /> {{ $t('Notes_And_Minutes') }}</h6>
              <b-button v-if="canEdit" variant="primary" size="sm" @click="New_Note()">
                <lucide-icon name="plus" size="14" /> {{ $t('Add_Note') }}
              </b-button>
            </div>
            <div v-if="!meeting.notes || meeting.notes.length === 0" class="text-muted text-center py-3">
              {{ $t('No_data') }}
            </div>
            <div v-for="n in meeting.notes" :key="n.id" class="note-item mb-2 p-2 border rounded">
              <div class="d-flex justify-content-between">
                <span class="badge" :class="note_type_class(n.type)">{{ format_label(n.type) }}</span>
                <span v-if="canEdit">
                  <a class="cursor-pointer text-success mr-1" @click="Edit_Note(n)"><lucide-icon name="pencil" size="14" /></a>
                  <a class="cursor-pointer text-danger" @click="Remove_Note(n.id)"><lucide-icon name="x" size="15" /></a>
                </span>
              </div>
              <div class="mt-1" style="white-space: pre-line">{{ n.content }}</div>
              <div v-if="n.type === 'action_item'" class="text-muted small mt-1">
                <span v-if="n.assignee"><lucide-icon name="user" size="12" /> {{ user_name(n.assignee) }}</span>
                <span v-if="n.due_date" class="ml-2"><lucide-icon name="calendar" size="12" /> {{ n.due_date }}</span>
                <span class="badge ml-2" :class="note_status_class(n.status)">{{ format_label(n.status) }}</span>
              </div>
            </div>
          </b-card>

          <!-- Activity log -->
          <b-card class="mb-3">
            <h6 class="mb-3"><lucide-icon name="history" size="16" /> {{ $t('Activity_Log') }}</h6>
            <div v-if="!meeting.logs || meeting.logs.length === 0" class="text-muted text-center py-3">
              {{ $t('No_data') }}
            </div>
            <ul v-else class="list-unstyled mb-0 activity-log">
              <li v-for="l in meeting.logs" :key="l.id" class="py-1">
                <span class="dot"></span>
                <span>{{ l.description || format_label(l.action) }}</span>
                <span class="text-muted small d-block ml-3">{{ user_name(l.user) }} · {{ format_datetime(l.created_at) }}</span>
              </li>
            </ul>
          </b-card>
        </b-col>
      </b-row>
    </div>

    <!-- Note modal -->
    <validation-observer ref="Create_Note">
      <b-modal hide-footer size="md" id="Note_Modal" :title="note_editmode ? $t('Edit') : $t('Add_Note')">
        <b-form @submit.prevent="Submit_Note">
          <b-row>
            <b-col md="12">
              <b-form-group :label="$t('Type')">
                <b-form-select v-model="note.type">
                  <b-form-select-option value="note">{{ $t('Note') }}</b-form-select-option>
                  <b-form-select-option value="decision">{{ $t('Decision') }}</b-form-select-option>
                  <b-form-select-option value="action_item">{{ $t('Action_Item') }}</b-form-select-option>
                </b-form-select>
              </b-form-group>
            </b-col>
            <b-col md="12">
              <validation-provider name="content" :rules="{ required: true }" v-slot="validationContext">
                <b-form-group :label="$t('Content') + ' *'">
                  <b-form-textarea :state="getValidationState(validationContext)" v-model="note.content" rows="3"></b-form-textarea>
                  <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>
            <template v-if="note.type === 'action_item'">
              <b-col md="12">
                <b-form-group :label="$t('Assigned_To')">
                  <v-select
                    v-model="note.assigned_to"
                    :reduce="o => o.value"
                    :options="participantOptions"
                  />
                </b-form-group>
              </b-col>
              <b-col md="6">
                <b-form-group :label="$t('Due_Date')">
                  <b-form-input type="date" v-model="note.due_date"></b-form-input>
                </b-form-group>
              </b-col>
              <b-col md="6">
                <b-form-group :label="$t('Status')">
                  <b-form-select v-model="note.status">
                    <b-form-select-option value="open">{{ $t('Open') }}</b-form-select-option>
                    <b-form-select-option value="in_progress">{{ $t('In_Progress') }}</b-form-select-option>
                    <b-form-select-option value="done">{{ $t('Done') }}</b-form-select-option>
                  </b-form-select>
                </b-form-group>
              </b-col>
            </template>
            <b-col md="12" class="mt-2">
              <b-button variant="primary" type="submit" :disabled="SubmitProcessing">
                <lucide-icon name="check" /> {{ $t('submit') }}
              </b-button>
            </b-col>
          </b-row>
        </b-form>
      </b-modal>
    </validation-observer>
  </div>
</template>

<script>
import { mapGetters } from "vuex";

export default {
  metaInfo: { title: "Meeting Details" },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      SavingAttendance: false,
      SendingInvites: false,
      UploadingFile: false,
      meeting: null,
      attachmentFile: null,
      statuses: ["scheduled", "ongoing", "completed", "cancelled"],
      attendances: ["pending", "present", "absent", "late"],
      note_editmode: false,
      note: this.empty_note()
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    canEdit() {
      return this.currentUserPermissions && this.currentUserPermissions.includes("meeting");
    },
    canManageAttendance() {
      return this.currentUserPermissions && this.currentUserPermissions.includes("meeting_attendance");
    },
    participantOptions() {
      if (!this.meeting || !this.meeting.participants) return [];
      return this.meeting.participants.map(p => ({ label: this.user_name(p.user), value: p.user_id }));
    }
  },
  methods: {
    empty_note() {
      return { id: "", meeting_id: "", type: "note", content: "", assigned_to: "", due_date: "", status: "open" };
    },
    format_label(v) {
      return v ? String(v).replace(/_/g, " ") : "-";
    },
    format_datetime(v) {
      if (!v) return "-";
      return String(v).replace("T", " ").substring(0, 16);
    },
    short_time(t) {
      return t ? String(t).substring(0, 5) : "";
    },
    platform_label(p) {
      const map = { zoom: "Zoom", google_meet: "Google Meet", teams: "Microsoft Teams", other: "Other" };
      return map[p] || p;
    },
    user_name(u) {
      if (!u) return "-";
      const n = ((u.firstname || "") + " " + (u.lastname || "")).trim();
      return n || u.username || "-";
    },
    status_class(s) {
      const map = { scheduled: "badge-outline-primary", ongoing: "badge-outline-warning", completed: "badge-outline-success", cancelled: "badge-outline-danger" };
      return map[s] || "badge-outline-secondary";
    },
    invite_class(s) {
      const map = { invited: "badge-outline-secondary", accepted: "badge-outline-success", declined: "badge-outline-danger", tentative: "badge-outline-warning" };
      return map[s] || "badge-outline-secondary";
    },
    attend_class(s) {
      const map = { pending: "badge-outline-secondary", present: "badge-outline-success", absent: "badge-outline-danger", late: "badge-outline-warning" };
      return map[s] || "badge-outline-secondary";
    },
    note_type_class(t) {
      const map = { note: "badge-outline-info", decision: "badge-outline-success", action_item: "badge-outline-warning" };
      return map[t] || "badge-outline-secondary";
    },
    note_status_class(s) {
      const map = { open: "badge-outline-secondary", in_progress: "badge-outline-info", done: "badge-outline-success" };
      return map[s] || "badge-outline-secondary";
    },
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },

    Get_Meeting() {
      const id = this.$route.params.id;
      axios
        .get("meeting/meetings/" + id)
        .then(({ data }) => {
          this.meeting = data.meeting;
          this.isLoading = false;
        })
        .catch(() => {
          this.isLoading = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Change_Status(status) {
      axios.put("meeting/meetings/" + this.meeting.id + "/status", { status: status }).then(() => {
        this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        this.Get_Meeting();
      }).catch(() => {
        this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
      });
    },

    Send_Invitations() {
      this.SendingInvites = true;
      axios.post("meeting/meetings/" + this.meeting.id + "/invitations").then(() => {
        this.SendingInvites = false;
        this.makeToast("success", this.$t("Invitations_Sent"), this.$t("Success"));
        this.Get_Meeting();
      }).catch(() => {
        this.SendingInvites = false;
        this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
      });
    },

    Save_Attendance() {
      if (!this.meeting.participants || this.meeting.participants.length === 0) {
        this.makeToast("warning", this.$t("No_data"), this.$t("Failed"));
        return;
      }
      this.SavingAttendance = true;
      const attendance = this.meeting.participants.map(p => ({ id: p.id, attendance_status: p.attendance_status }));
      axios.post("meeting/meetings/" + this.meeting.id + "/attendance", { attendance: attendance }).then(() => {
        this.SavingAttendance = false;
        this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        this.Get_Meeting();
      }).catch(() => {
        this.SavingAttendance = false;
        this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
      });
    },

    Upload_Attachment() {
      if (!this.attachmentFile) return;
      this.UploadingFile = true;
      const form = new FormData();
      form.append("meeting_id", this.meeting.id);
      form.append("file", this.attachmentFile);
      axios.post("meeting/attachments", form, { headers: { "Content-Type": "multipart/form-data" } }).then(() => {
        this.UploadingFile = false;
        this.attachmentFile = null;
        this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
        this.Get_Meeting();
      }).catch(() => {
        this.UploadingFile = false;
        this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
      });
    },

    Remove_Attachment(id) {
      this.$swal({
        title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning",
        showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios.delete("meeting/attachments/" + id).then(() => {
            this.makeToast("success", this.$t("Deleted_in_successfully"), this.$t("Success"));
            this.Get_Meeting();
          });
        }
      });
    },

    New_Note() {
      this.note = this.empty_note();
      this.note.meeting_id = this.meeting.id;
      this.note_editmode = false;
      this.$bvModal.show("Note_Modal");
    },

    Edit_Note(n) {
      this.note = { ...this.empty_note(), ...n };
      if (this.note.due_date) this.note.due_date = String(this.note.due_date).substring(0, 10);
      this.note_editmode = true;
      this.$bvModal.show("Note_Modal");
    },

    Submit_Note() {
      this.$refs.Create_Note.validate().then(success => {
        if (!success) return;
        this.SubmitProcessing = true;
        const req = this.note_editmode
          ? axios.put("meeting/notes/" + this.note.id, this.note)
          : axios.post("meeting/notes", this.note);
        req.then(() => {
          this.SubmitProcessing = false;
          this.$bvModal.hide("Note_Modal");
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
          this.Get_Meeting();
        }).catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
      });
    },

    Remove_Note(id) {
      this.$swal({
        title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning",
        showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios.delete("meeting/notes/" + id).then(() => {
            this.makeToast("success", this.$t("Deleted_in_successfully"), this.$t("Success"));
            this.Get_Meeting();
          });
        }
      });
    }
  },
  created() {
    this.Get_Meeting();
  }
};
</script>

<style scoped>
.note-item { background: #fafbfe; }
body.dark-theme .note-item { background: #1e1e2d; border-color: #2b2b40 !important; }
.activity-log li { position: relative; padding-left: 16px; }
.activity-log .dot {
  position: absolute; left: 0; top: 7px; width: 8px; height: 8px;
  border-radius: 50%; background: #4361ee;
}
.cursor-pointer { cursor: pointer; }
</style>
