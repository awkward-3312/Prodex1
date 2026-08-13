<template>
  <div class="main-content">
    <breadcumb :page="$t('Campaign_Details')" :folder="$t('Marketing_Management')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading && campaign">
      <b-row>
        <b-col md="8">
          <b-card>
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div>
                <h4 class="mb-1">{{ campaign.title }}</h4>
                <span class="badge badge-outline-info mr-1">{{ type_label(campaign.type) }}</span>
                <span class="badge" :class="status_class(campaign.status)">{{ format_label(campaign.status) }}</span>
              </div>
              <div>
                <b-button v-if="canSend" size="sm" variant="info" @click="Send_Campaign()">
                  <lucide-icon name="send" /> {{ $t('Send_Now') }}
                </b-button>
                <b-button v-if="canEdit" size="sm" variant="success" @click="Edit_Campaign()">
                  <lucide-icon name="pencil" /> {{ $t('Edit') }}
                </b-button>
              </div>
            </div>

            <p v-if="campaign.description" class="text-muted">{{ campaign.description }}</p>

            <b-row class="mb-2" v-if="campaign.type === 'email' && campaign.subject">
              <b-col md="3" class="font-weight-bold">{{ $t('Email_Subject') }}</b-col>
              <b-col md="9">{{ campaign.subject }}</b-col>
            </b-row>
            <b-row class="mb-2">
              <b-col md="3" class="font-weight-bold">{{ $t('Message_Content') }}</b-col>
              <b-col md="9"><pre class="mkt-msg">{{ campaign.message_content }}</pre></b-col>
            </b-row>
            <b-row class="mb-2" v-if="campaign.attachment">
              <b-col md="3" class="font-weight-bold">{{ $t('Upload_Attachment') }}</b-col>
              <b-col md="9"><a :href="'/' + campaign.attachment" target="_blank">{{ campaign.attachment }}</a></b-col>
            </b-row>
            <b-row class="mb-2">
              <b-col md="3" class="font-weight-bold">{{ $t('Scheduled_At') }}</b-col>
              <b-col md="9">{{ campaign.send_immediately ? $t('Send_Immediately') : short_dt(campaign.scheduled_at) }}</b-col>
            </b-row>
            <b-row class="mb-2" v-if="campaign.segment">
              <b-col md="3" class="font-weight-bold">{{ $t('Segment') }}</b-col>
              <b-col md="9">{{ campaign.segment.name }}</b-col>
            </b-row>
          </b-card>
        </b-col>

        <b-col md="4">
          <b-card :title="$t('Delivery_Status')">
            <ul class="list-unstyled mkt-stats">
              <li><span>{{ $t('Total_Recipients') }}</span><strong>{{ campaign.total_recipients }}</strong></li>
              <li><span class="text-success">{{ $t('Sent') }}</span><strong>{{ campaign.sent_count }}</strong></li>
              <li><span class="text-danger">{{ $t('Failed') }}</span><strong>{{ campaign.failed_count }}</strong></li>
              <li><span class="text-warning">{{ $t('Pending') }}</span><strong>{{ campaign.pending_count }}</strong></li>
            </ul>
          </b-card>
        </b-col>
      </b-row>

      <b-card :title="$t('Recipients')">
        <div class="table-responsive">
          <table class="table table-hover">
            <thead>
              <tr>
                <th>{{ $t('Name') || 'Name' }}</th>
                <th v-if="campaign.type === 'email'">{{ $t('Email') }}</th>
                <th v-else>{{ $t('Phone') || 'Phone' }}</th>
                <th>{{ $t('Status') }}</th>
                <th>{{ $t('Sent') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="recipients.length === 0">
                <td colspan="4" class="text-center text-muted">-</td>
              </tr>
              <tr v-for="r in recipients" :key="r.id">
                <td>{{ r.name }}</td>
                <td v-if="campaign.type === 'email'">{{ r.email }}</td>
                <td v-else>{{ r.phone }}</td>
                <td><span class="badge" :class="recipient_class(r.status)">{{ format_label(r.status) }}</span>
                  <small v-if="r.error_message" class="text-danger d-block">{{ r.error_message }}</small>
                </td>
                <td>{{ short_dt(r.sent_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>
    </div>
  </div>
</template>

<script>
export default {
  metaInfo: { title: "Campaign Details" },
  data() {
    return {
      isLoading: true,
      campaign: null,
      recipients: [],
      recipientStats: {}
    };
  },
  computed: {
    canEdit() { return this.campaign && ["draft", "scheduled", "failed", "cancelled"].includes(this.campaign.status); },
    canSend() { return this.campaign && ["draft", "scheduled", "failed"].includes(this.campaign.status); }
  },
  methods: {
    format_label(v) { return v ? String(v).replace(/_/g, " ") : "-"; },
    type_label(t) { const map = { sms: "SMS", email: "Email", whatsapp: "WhatsApp" }; return map[t] || t; },
    short_dt(d) { return d ? String(d).replace("T", " ").substring(0, 16) : "-"; },
    status_class(s) {
      const map = {
        draft: "badge-outline-secondary", scheduled: "badge-outline-primary",
        sending: "badge-outline-warning", sent: "badge-outline-success",
        failed: "badge-outline-danger", cancelled: "badge-outline-dark"
      };
      return map[s] || "badge-outline-secondary";
    },
    recipient_class(s) {
      const map = {
        pending: "badge-outline-warning", sent: "badge-outline-success",
        delivered: "badge-outline-success", failed: "badge-outline-danger",
        skipped: "badge-outline-secondary"
      };
      return map[s] || "badge-outline-secondary";
    },
    makeToast(variant, msg, title) { this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true }); },

    Edit_Campaign() { this.$router.push({ name: "marketing_edit_campaign", params: { id: this.campaign.id } }); },

    Send_Campaign() {
      this.$swal({
        title: this.$t("Send_Campaign"), text: this.$t("Send_Now") + " ?", type: "warning",
        showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33",
        confirmButtonText: this.$t("Send_Now")
      }).then(result => {
        if (result.value) {
          axios.post("marketing/campaigns/" + this.campaign.id + "/send").then(() => {
            this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
            this.Get_Campaign();
          }).catch(() => {
            this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
          });
        }
      });
    },

    Get_Campaign() {
      axios.get("marketing/campaigns/" + this.$route.params.id).then(({ data }) => {
        this.campaign = data.campaign;
        this.recipients = data.recipients || [];
        this.recipientStats = data.recipient_stats || {};
        this.isLoading = false;
      }).catch(() => {
        this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        this.isLoading = false;
      });
    }
  },
  created() {
    this.Get_Campaign();
  }
};
</script>

<style scoped>
.mkt-msg { white-space: pre-wrap; background: #f7f8fa; padding: 10px; border-radius: 6px; font-family: inherit; }
.mkt-stats li { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
</style>
