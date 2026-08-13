<template>
  <div class="main-content">
    <breadcumb :page="editmode ? $t('Edit_Campaign') : $t('Create_Campaign')" :folder="$t('Marketing_Management')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <validation-observer ref="Campaign_Form" v-if="!isLoading">
      <b-form @submit.prevent="Submit_Campaign">
        <b-row>
          <b-col md="8">
            <b-card :title="editmode ? $t('Edit_Campaign') : $t('Create_Campaign')">
              <b-row>
                <b-col md="12">
                  <validation-provider name="title" :rules="{ required: true }" v-slot="validationContext">
                    <b-form-group :label="$t('Campaign_Title') + ' *'">
                      <b-form-input :state="getValidationState(validationContext)" v-model="campaign.title"></b-form-input>
                      <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col md="6">
                  <b-form-group :label="$t('Campaign_Type') + ' *'">
                    <b-form-select v-model="campaign.type" @change="onTypeChange">
                      <b-form-select-option v-for="t in types" :key="t" :value="t">{{ type_label(t) }}</b-form-select-option>
                    </b-form-select>
                  </b-form-group>
                </b-col>
                <b-col md="6">
                  <b-form-group :label="$t('Use_Template')">
                    <b-form-select v-model="selectedTemplate" @change="applyTemplate">
                      <b-form-select-option :value="''">{{ $t('Select_Template') }}</b-form-select-option>
                      <b-form-select-option v-for="tpl in templates" :key="tpl.id" :value="tpl.id">{{ tpl.name }}</b-form-select-option>
                    </b-form-select>
                  </b-form-group>
                </b-col>

                <b-col md="12">
                  <b-form-group :label="$t('Description')">
                    <b-form-input v-model="campaign.description"></b-form-input>
                  </b-form-group>
                </b-col>

                <b-col md="12" v-if="campaign.type === 'email'">
                  <b-form-group :label="$t('Email_Subject')">
                    <b-form-input v-model="campaign.subject"></b-form-input>
                  </b-form-group>
                </b-col>

                <b-col md="12">
                  <validation-provider name="message" :rules="{ required: true }" v-slot="validationContext">
                    <b-form-group :label="$t('Message_Content') + ' *'">
                      <b-form-textarea ref="msgArea" :rows="campaign.type === 'email' ? 8 : 4" :state="getValidationState(validationContext)" v-model="campaign.message_content"></b-form-textarea>
                      <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                  <div class="mb-3">
                    <small class="text-muted">{{ $t('Personalization_Variables') }}:</small>
                    <b-button v-for="v in variables" :key="v" size="sm" variant="outline-secondary" class="m-1" @click="insertVariable(v)">{{ v }}</b-button>
                  </div>
                </b-col>

                <b-col md="12" v-if="campaign.type === 'email'">
                  <b-form-group :label="$t('Upload_Attachment')">
                    <b-form-file v-model="attachmentFile" :placeholder="$t('Upload_Attachment')" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"></b-form-file>
                    <small v-if="campaign.attachment" class="text-muted">{{ campaign.attachment }}</small>
                  </b-form-group>
                </b-col>
              </b-row>
            </b-card>
          </b-col>

          <b-col md="4">
            <b-card :title="$t('Recipients')">
              <b-form-group>
                <b-form-radio v-model="audience" value="all">{{ $t('All_Customers') }}</b-form-radio>
                <b-form-radio v-model="audience" value="segment">{{ $t('Specific_Segment') }}</b-form-radio>
              </b-form-group>
              <b-form-group v-if="audience === 'segment'" :label="$t('Select_Segment')">
                <b-form-select v-model="campaign.segment_id">
                  <b-form-select-option :value="''">{{ $t('Select_Segment') }}</b-form-select-option>
                  <b-form-select-option v-for="seg in segments" :key="seg.id" :value="seg.id">
                    {{ seg.name }} ({{ seg.customers_count }})
                  </b-form-select-option>
                </b-form-select>
              </b-form-group>
            </b-card>

            <b-card :title="$t('Schedule_Campaign')">
              <b-form-group>
                <b-form-radio v-model="sendMode" value="now">{{ $t('Send_Immediately') }}</b-form-radio>
                <b-form-radio v-model="sendMode" value="schedule">{{ $t('Schedule_Campaign') }}</b-form-radio>
              </b-form-group>
              <b-form-group v-if="sendMode === 'schedule'" :label="$t('Schedule_Date_Time')">
                <b-form-input type="datetime-local" v-model="campaign.scheduled_at"></b-form-input>
              </b-form-group>
            </b-card>

            <b-button variant="primary" type="submit" block :disabled="SubmitProcessing">
              <lucide-icon class="me-2 font-weight-bold" name="check" />
              {{ sendMode === 'now' ? $t('Send_Campaign') : $t('submit') }}
            </b-button>
            <b-button variant="outline-secondary" block class="mt-2" @click="goBack()">{{ $t('Cancel') || 'Cancel' }}</b-button>
            <div v-if="SubmitProcessing" class="spinner sm spinner-primary mt-3"></div>
          </b-col>
        </b-row>
      </b-form>
    </validation-observer>
  </div>
</template>

<script>
export default {
  metaInfo: { title: "Create Campaign" },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      editmode: false,
      types: ["sms", "email", "whatsapp"],
      variables: ["{customer_name}", "{phone}", "{email}", "{last_purchase}", "{total_spent}"],
      segments: [],
      templates: [],
      selectedTemplate: "",
      attachmentFile: null,
      audience: "all",
      sendMode: "now",
      campaign: this.empty_campaign()
    };
  },
  methods: {
    empty_campaign() {
      return {
        id: "", title: "", description: "", type: "sms", subject: "", message_content: "",
        attachment: "", template_id: "", segment_id: "", all_customers: true,
        send_immediately: true, scheduled_at: ""
      };
    },
    type_label(t) { const map = { sms: "SMS", email: "Email", whatsapp: "WhatsApp" }; return map[t] || t; },
    getValidationState({ dirty, validated, valid = null }) { return dirty || validated ? valid : null; },
    makeToast(variant, msg, title) { this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true }); },

    onTypeChange() {
      this.selectedTemplate = "";
      this.Get_Templates();
    },
    insertVariable(v) {
      this.campaign.message_content = (this.campaign.message_content || "") + v;
    },
    applyTemplate() {
      if (!this.selectedTemplate) return;
      const tpl = this.templates.find(t => t.id === this.selectedTemplate);
      if (tpl) {
        this.campaign.message_content = tpl.content;
        if (tpl.subject) this.campaign.subject = tpl.subject;
        this.campaign.template_id = tpl.id;
      }
    },

    Get_Segments() {
      axios.get("marketing/segments_all").then(({ data }) => { this.segments = data.segments || []; });
    },
    Get_Templates() {
      axios.get("marketing/templates_all?type=" + this.campaign.type).then(({ data }) => { this.templates = data.templates || []; });
    },

    goBack() { this.$router.push({ name: "marketing_campaigns" }); },

    Submit_Campaign() {
      this.$refs.Campaign_Form.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
          return;
        }
        this.SubmitProcessing = true;

        const fd = new FormData();
        fd.append("title", this.campaign.title);
        fd.append("description", this.campaign.description || "");
        fd.append("type", this.campaign.type);
        fd.append("subject", this.campaign.subject || "");
        fd.append("message_content", this.campaign.message_content);
        fd.append("template_id", this.campaign.template_id || "");
        fd.append("all_customers", this.audience === "all" ? 1 : 0);
        fd.append("segment_id", this.audience === "segment" ? (this.campaign.segment_id || "") : "");
        fd.append("send_immediately", this.sendMode === "now" ? 1 : 0);
        if (this.sendMode === "schedule" && this.campaign.scheduled_at) {
          fd.append("scheduled_at", this.campaign.scheduled_at.replace("T", " "));
        }
        if (this.attachmentFile) fd.append("attachment", this.attachmentFile);

        const url = this.editmode ? "marketing/campaigns/" + this.campaign.id : "marketing/campaigns";

        axios.post(url, fd, { headers: { "Content-Type": "multipart/form-data" } })
          .then(() => {
            this.SubmitProcessing = false;
            this.makeToast("success", this.$t(this.editmode ? "Updated_in_successfully" : "Created_in_successfully"), this.$t("Success"));
            this.$router.push({ name: "marketing_campaigns" });
          })
          .catch(() => {
            this.SubmitProcessing = false;
            this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
          });
      });
    },

    Load_Campaign(id) {
      axios.get("marketing/campaigns/" + id).then(({ data }) => {
        const c = data.campaign;
        this.campaign = { ...this.empty_campaign(), ...c };
        this.audience = c.all_customers ? "all" : "segment";
        this.sendMode = c.send_immediately ? "now" : "schedule";
        if (c.scheduled_at) this.campaign.scheduled_at = String(c.scheduled_at).replace(" ", "T").substring(0, 16);
        this.editmode = true;
        this.Get_Templates();
        this.isLoading = false;
      }).catch(() => {
        this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        this.isLoading = false;
      });
    }
  },
  created() {
    this.Get_Segments();
    if (this.$route.params.id) {
      this.Load_Campaign(this.$route.params.id);
    } else {
      this.Get_Templates();
      this.isLoading = false;
    }
  }
};
</script>
