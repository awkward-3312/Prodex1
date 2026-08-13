<template>
  <div class="main-content">
    <breadcumb page="WhatsApp Settings" folder="WhatsApp" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-alert variant="warning" :show="!globallyEnabled">
        WhatsApp is currently disabled by the platform administrator. You can configure your account, but messages will not be sent until it is enabled.
      </b-alert>

      <b-row>
        <b-col md="8">
          <b-card :title="'Connection'" class="mb-4">
            <b-row>
              <b-col md="12" class="mb-3">
                <label class="font-weight-bold">Provider</label>
                <b-form-input value="Meta Cloud API" disabled />
              </b-col>
              <b-col md="6" class="mb-3">
                <label class="font-weight-bold">Phone Number ID</label>
                <b-form-input v-model="form.phone_number_id" placeholder="e.g. 123456789012345" />
              </b-col>
              <b-col md="6" class="mb-3">
                <label class="font-weight-bold">WhatsApp Business Account ID</label>
                <b-form-input v-model="form.business_account_id" placeholder="Optional" />
              </b-col>
              <b-col md="12" class="mb-3">
                <label class="font-weight-bold">Access Token</label>
                <b-form-input v-model="form.access_token" type="password" :placeholder="status.has_access_token ? '••••••••  (leave blank to keep)' : 'Permanent access token'" autocomplete="new-password" />
              </b-col>
              <b-col md="6" class="mb-3">
                <label class="font-weight-bold">App Secret</label>
                <b-form-input v-model="form.app_secret" type="password" :placeholder="status.has_app_secret ? '••••••••  (leave blank to keep)' : 'For webhook signature (optional)'" autocomplete="new-password" />
              </b-col>
              <b-col md="6" class="mb-3">
                <label class="font-weight-bold">Webhook Verify Token</label>
                <b-form-input v-model="form.webhook_verify_token" placeholder="Any secret string you set in Meta" />
              </b-col>
              <b-col md="6" class="mb-3">
                <label class="font-weight-bold">Default Country Code</label>
                <b-form-input v-model="form.default_country_code" placeholder="e.g. 1, 44, 212" />
                <small class="text-muted">Prepended to local numbers starting with 0.</small>
              </b-col>
            </b-row>

            <div class="mt-2">
              <b-button variant="primary" @click="save" :disabled="saving">
                <lucide-icon name="check" /> Save
              </b-button>
              <b-button variant="outline-success" class="ml-2" @click="testConnection" :disabled="testing || saving">
                <lucide-icon name="power" /> {{ testing ? 'Testing...' : 'Test Connection' }}
              </b-button>
            </div>
          </b-card>

          <b-card title="Automated Messages" class="mb-4">
            <p class="text-muted">Automatically send a WhatsApp message when these events happen. Each event uses the matching template (by key).</p>
            <b-form-checkbox
              v-for="key in automationKeys"
              :key="key"
              v-model="form.automations[key]"
              switch
              class="mb-2"
            >
              {{ automationLabels[key] || key }}
            </b-form-checkbox>
          </b-card>
        </b-col>

        <b-col md="4">
          <b-card class="mb-4">
            <div class="text-center">
              <h5>Connection Status</h5>
              <b-badge :variant="status.is_connected ? 'success' : 'secondary'" class="px-3 py-2" style="font-size: 0.9rem;">
                {{ status.is_connected ? 'Connected' : 'Not Connected' }}
              </b-badge>
              <p v-if="status.connected_at" class="text-muted mt-2 mb-0">
                <small>Since {{ status.connected_at }}</small>
              </p>
            </div>
          </b-card>

          <b-card title="Monthly Quota" class="mb-4">
            <div class="d-flex justify-content-between">
              <span>Used</span><strong>{{ quota.used }}</strong>
            </div>
            <div class="d-flex justify-content-between">
              <span>Limit</span><strong>{{ quota.max === null ? 'Unlimited' : quota.max }}</strong>
            </div>
            <div class="d-flex justify-content-between" v-if="quota.remaining !== null">
              <span>Remaining</span><strong>{{ quota.remaining }}</strong>
            </div>
            <b-progress v-if="quota.max" :max="quota.max" class="mt-2">
              <b-progress-bar :value="quota.used" :variant="quotaVariant"></b-progress-bar>
            </b-progress>
          </b-card>

          <b-card title="Webhook URL">
            <p class="text-muted mb-1"><small>Paste this into your Meta app webhook configuration:</small></p>
            <b-form-textarea :value="webhookUrl" readonly rows="2" size="sm" />
          </b-card>
        </b-col>
      </b-row>
    </div>
  </div>
</template>

<script>
export default {
  data() {
    return {
      isLoading: true,
      saving: false,
      testing: false,
      globallyEnabled: false,
      webhookUrl: "",
      automationKeys: [],
      automationLabels: {
        invoice_created: "Invoice created (new sale)",
        payment_received: "Payment received"
      },
      status: { is_connected: false, connected_at: null, has_access_token: false, has_app_secret: false },
      quota: { used: 0, max: null, remaining: null },
      form: {
        phone_number_id: "",
        business_account_id: "",
        access_token: "",
        app_secret: "",
        webhook_verify_token: "",
        default_country_code: "",
        automations: {}
      }
    };
  },
  computed: {
    quotaVariant() {
      if (!this.quota.max) return "success";
      const pct = (this.quota.used / this.quota.max) * 100;
      if (pct >= 100) return "danger";
      if (pct >= 80) return "warning";
      return "success";
    }
  },
  mounted() {
    this.load();
  },
  methods: {
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },
    load() {
      this.isLoading = true;
      axios
        .get("/whatsapp/settings")
        .then(res => {
          const d = res.data;
          this.globallyEnabled = d.globally_enabled;
          this.webhookUrl = d.webhook_url;
          this.automationKeys = d.automation_keys || [];
          this.status = d.settings;
          this.quota = d.quota;
          this.form.phone_number_id = d.settings.phone_number_id || "";
          this.form.business_account_id = d.settings.business_account_id || "";
          this.form.webhook_verify_token = d.settings.webhook_verify_token || "";
          this.form.default_country_code = d.settings.default_country_code || "";
          this.form.automations = Object.assign({}, d.settings.automations || {});
        })
        .catch(() => this.makeToast("danger", "Failed to load WhatsApp settings.", "Error"))
        .finally(() => (this.isLoading = false));
    },
    save() {
      this.saving = true;
      axios
        .post("/whatsapp/settings", this.form)
        .then(() => {
          this.makeToast("success", "WhatsApp settings saved.", "Success");
          this.form.access_token = "";
          this.form.app_secret = "";
          this.load();
        })
        .catch(err => {
          const msg = (err.response && err.response.data && err.response.data.message) || "Failed to save.";
          this.makeToast("danger", msg, "Error");
        })
        .finally(() => (this.saving = false));
    },
    testConnection() {
      this.testing = true;
      // Persist first so the test uses the latest credentials.
      axios
        .post("/whatsapp/settings", this.form)
        .then(() => axios.post("/whatsapp/test"))
        .then(res => {
          this.makeToast(res.data.status === "success" ? "success" : "danger", res.data.message, res.data.status === "success" ? "Success" : "Failed");
          this.form.access_token = "";
          this.form.app_secret = "";
          this.load();
        })
        .catch(err => {
          const msg = (err.response && err.response.data && err.response.data.message) || "Connection test failed.";
          this.makeToast("danger", msg, "Failed");
        })
        .finally(() => (this.testing = false));
    }
  }
};
</script>
