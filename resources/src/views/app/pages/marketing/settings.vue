<template>
  <div class="main-content">
    <breadcumb :page="$t('Marketing_Settings')" :folder="$t('Marketing_Management')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-form @submit.prevent="Save_Settings" v-if="!isLoading">
      <b-row>
        <!-- SMS Provider -->
        <b-col md="6">
          <b-card :title="$t('SMS_Provider_Configuration')">
            <b-form-checkbox v-model="settings.sms_enabled" class="mb-2">{{ $t('Enable_SMS') }}</b-form-checkbox>
            <b-form-group :label="$t('Provider_Name')"><b-form-input v-model="settings.sms_provider"></b-form-input></b-form-group>
            <b-form-group :label="$t('API_URL')"><b-form-input v-model="settings.sms_api_url" placeholder="https://"></b-form-input></b-form-group>
            <b-form-group :label="$t('API_Key')"><b-form-input v-model="settings.sms_api_key"></b-form-input></b-form-group>
            <b-form-group :label="$t('Sender_ID')"><b-form-input v-model="settings.sms_sender_id"></b-form-input></b-form-group>
            <b-row>
              <b-col md="4"><b-form-group :label="$t('HTTP_Method')">
                <b-form-select v-model="settings.sms_http_method"><b-form-select-option value="POST">POST</b-form-select-option><b-form-select-option value="GET">GET</b-form-select-option></b-form-select>
              </b-form-group></b-col>
              <b-col md="4"><b-form-group :label="$t('Recipient_Field_Name')"><b-form-input v-model="settings.sms_to_field"></b-form-input></b-form-group></b-col>
              <b-col md="4"><b-form-group :label="$t('Message_Field_Name')"><b-form-input v-model="settings.sms_message_field"></b-form-input></b-form-group></b-col>
            </b-row>
          </b-card>
        </b-col>

        <!-- WhatsApp -->
        <b-col md="6">
          <b-card :title="$t('WhatsApp_API_Configuration')">
            <b-form-checkbox v-model="settings.whatsapp_enabled" class="mb-2">{{ $t('Enable_WhatsApp') }}</b-form-checkbox>
            <b-form-group :label="$t('Provider_Name')"><b-form-input v-model="settings.whatsapp_provider"></b-form-input></b-form-group>
            <b-form-group :label="$t('API_URL')"><b-form-input v-model="settings.whatsapp_api_url" placeholder="https://"></b-form-input></b-form-group>
            <b-form-group :label="$t('API_Key')"><b-form-input v-model="settings.whatsapp_api_key"></b-form-input></b-form-group>
            <b-form-group :label="$t('Phone_Number_ID')"><b-form-input v-model="settings.whatsapp_phone_id"></b-form-input></b-form-group>
            <b-row>
              <b-col md="4"><b-form-group :label="$t('HTTP_Method')">
                <b-form-select v-model="settings.whatsapp_http_method"><b-form-select-option value="POST">POST</b-form-select-option><b-form-select-option value="GET">GET</b-form-select-option></b-form-select>
              </b-form-group></b-col>
              <b-col md="4"><b-form-group :label="$t('Recipient_Field_Name')"><b-form-input v-model="settings.whatsapp_to_field"></b-form-input></b-form-group></b-col>
              <b-col md="4"><b-form-group :label="$t('Message_Field_Name')"><b-form-input v-model="settings.whatsapp_message_field"></b-form-input></b-form-group></b-col>
            </b-row>
          </b-card>
        </b-col>

        <!-- Email / SMTP -->
        <b-col md="6">
          <b-card :title="$t('Email_SMTP_Configuration')">
            <b-form-checkbox v-model="settings.email_enabled" class="mb-2">{{ $t('Enable_Email') }}</b-form-checkbox>
            <p class="text-muted" style="font-size:0.85rem">
              {{ $t('Email_SMTP_Configuration') }} — SMTP is taken from the system Mail Settings (Servers).
            </p>
            <b-form-group :label="$t('From_Name')"><b-form-input v-model="settings.email_from_name"></b-form-input></b-form-group>
            <b-form-group :label="$t('From_Email')"><b-form-input type="email" v-model="settings.email_from_address"></b-form-input></b-form-group>
          </b-card>
        </b-col>

        <!-- Sender + Scheduling -->
        <b-col md="6">
          <b-card :title="$t('Default_Sender_Information')">
            <b-form-group :label="$t('Default_Sender_Name')"><b-form-input v-model="settings.default_sender_name"></b-form-input></b-form-group>
          </b-card>
          <b-card :title="$t('Campaign_Scheduling_Settings')">
            <b-form-checkbox v-model="settings.scheduling_enabled" class="mb-2">{{ $t('Enable_Scheduling') }}</b-form-checkbox>
            <b-form-group :label="$t('Batch_Size')"><b-form-input type="number" min="1" v-model="settings.batch_size"></b-form-input></b-form-group>
          </b-card>
        </b-col>

        <b-col md="12">
          <b-button variant="primary" type="submit" :disabled="SubmitProcessing">
            <lucide-icon class="me-2 font-weight-bold" name="check" /> {{ $t('Save_Settings') }}
          </b-button>
          <div v-if="SubmitProcessing" class="spinner sm spinner-primary mt-3"></div>
        </b-col>
      </b-row>
    </b-form>
  </div>
</template>

<script>
export default {
  metaInfo: { title: "Marketing Settings" },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      settings: {}
    };
  },
  methods: {
    makeToast(variant, msg, title) { this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true }); },
    Get_Settings() {
      axios.get("marketing/settings").then(({ data }) => {
        this.settings = data.settings || {};
        // normalise booleans coming back as 0/1
        ["sms_enabled", "whatsapp_enabled", "email_enabled", "scheduling_enabled"].forEach(k => {
          this.settings[k] = !!this.settings[k];
        });
        this.isLoading = false;
      }).catch(() => { setTimeout(() => { this.isLoading = false; }, 500); });
    },
    Save_Settings() {
      this.SubmitProcessing = true;
      const payload = { ...this.settings };
      ["sms_enabled", "whatsapp_enabled", "email_enabled", "scheduling_enabled"].forEach(k => {
        payload[k] = this.settings[k] ? 1 : 0;
      });
      axios.post("marketing/settings", payload).then(() => {
        this.SubmitProcessing = false;
        this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
      }).catch(() => {
        this.SubmitProcessing = false;
        this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
      });
    }
  },
  created() {
    this.Get_Settings();
  }
};
</script>
