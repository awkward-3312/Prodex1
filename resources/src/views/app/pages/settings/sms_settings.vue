<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('sms_settings')"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('sms_settings') }]"
    />

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="8" />
    </div>

    <template v-else>
      <!-- Default gateway -->
      <validation-observer ref="default_form_sms">
        <form @submit.prevent="Submit_Default_SMS">
          <px-card title="Pasarela de SMS predeterminada" class="pxcfg__card">
            <div class="pxcfg__grid pxcfg__grid--3">
              <px-field :label="$t('Default_SMS_Gateway')">
                <template #default="{ id }">
                  <vs-px :input-id="id" v-model="default_sms_gateway" :reduce="label => label.value" :placeholder="$t('Choose_SMS_Gateway')"
                    :options="sms_gateway.map(g => ({ label: g.title, value: g.id }))" />
                </template>
              </px-field>
            </div>
            <template #footer>
              <px-button variant="primary" icon="check" type="submit" @click="Submit_Default_SMS">{{ $t('submit') }}</px-button>
            </template>
          </px-card>
        </form>
      </validation-observer>

      <!-- Termii -->
      <validation-observer ref="termi_form_sms">
        <form @submit.prevent="Submit_Termi_SMS">
          <px-card title="Termii" class="pxcfg__card">
            <div class="pxcfg__grid pxcfg__grid--3">
              <validation-provider ref="termiKeyProvider" name="TERMI_KEY" :rules="{ required: true }" v-slot="v">
                <px-field label="Termii KEY *" :error="v.errors[0]"><template #default="{ id, invalid }"><px-input :id="id" v-model="termi.TERMI_KEY" :invalid="invalid" @input="v.validate" /></template></px-field>
              </validation-provider>
              <validation-provider ref="termiSecretProvider" name="TERMI_SECRET" :rules="{ required: true }" v-slot="v">
                <px-field label="Termii SECRET *" :error="v.errors[0]"><template #default="{ id, invalid }"><px-input :id="id" v-model="termi.TERMI_SECRET" :invalid="invalid" @input="v.validate" /></template></px-field>
              </validation-provider>
              <validation-provider ref="termiSenderProvider" name="TERMI_SENDER" :rules="{ required: true }" v-slot="v">
                <px-field label="Termii Sender *" :error="v.errors[0]"><template #default="{ id, invalid }"><px-input :id="id" v-model="termi.TERMI_SENDER" :invalid="invalid" @input="v.validate" /></template></px-field>
              </validation-provider>
            </div>
            <template #footer><px-button variant="primary" icon="check" type="submit" @click="Submit_Termi_SMS">{{ $t('submit') }}</px-button></template>
          </px-card>
        </form>
      </validation-observer>

      <!-- Twilio -->
      <validation-observer ref="twilio_form_sms">
        <form @submit.prevent="Submit_Twilio_SMS">
          <px-card title="Twilio SMS" class="pxcfg__card">
            <div class="pxcfg__grid pxcfg__grid--3">
              <validation-provider ref="twSidProvider" name="TWILIO_SID" :rules="{ required: true }" v-slot="v">
                <px-field label="TWILIO_SID *" :error="v.errors[0]"><template #default="{ id, invalid }"><px-input :id="id" v-model="twilio.TWILIO_SID" :invalid="invalid" @input="v.validate" /></template></px-field>
              </validation-provider>
              <validation-provider ref="twTokenProvider" name="TWILIO_TOKEN" :rules="{ required: true }" v-slot="v">
                <px-field label="TWILIO_TOKEN *" :error="v.errors[0]"><template #default="{ id, invalid }"><px-input :id="id" v-model="twilio.TWILIO_TOKEN" :invalid="invalid" @input="v.validate" /></template></px-field>
              </validation-provider>
              <validation-provider ref="twFromProvider" name="TWILIO_FROM" :rules="{ required: true }" v-slot="v">
                <px-field label="TWILIO_FROM *" :error="v.errors[0]"><template #default="{ id, invalid }"><px-input :id="id" v-model="twilio.TWILIO_FROM" :invalid="invalid" @input="v.validate" /></template></px-field>
              </validation-provider>
            </div>
            <template #footer><px-button variant="primary" icon="check" type="submit" @click="Submit_Twilio_SMS">{{ $t('submit') }}</px-button></template>
          </px-card>
        </form>
      </validation-observer>

      <!-- InfoBip -->
      <validation-observer ref="infobip_form_sms">
        <form @submit.prevent="Submit_infobip_SMS">
          <px-card title="InfoBip" class="pxcfg__card">
            <div class="pxcfg__grid pxcfg__grid--3">
              <px-field label="BASE URL"><template #default="{ id }"><px-input :id="id" v-model="infobip.base_url" /></template></px-field>
              <px-field label="API KEY"><template #default="{ id }"><px-input :id="id" v-model="infobip.api_key" /></template></px-field>
              <px-field label="SMS sender number Or Name"><template #default="{ id }"><px-input :id="id" v-model="infobip.sender_from" /></template></px-field>
            </div>
            <p class="pxcfg__guide">
              <strong>BASE_URL:</strong> centro de datos de Infobip para el tráfico API. ·
              <strong>API_KEY:</strong> método de autenticación (ver documentación de la API). ·
              <strong>SMS sender:</strong> se muestra como remitente en el dispositivo del destinatario.
            </p>
            <template #footer><px-button variant="primary" icon="check" type="submit" @click="Submit_infobip_SMS">{{ $t('submit') }}</px-button></template>
          </px-card>
        </form>
      </validation-observer>

      <!-- Custom gateway -->
      <validation-observer ref="custom_form_sms">
        <form @submit.prevent="Submit_Custom_SMS">
          <px-card :title="$t('Custom_SMS_Gateway')" class="pxcfg__card">
            <div class="pxcfg__grid">
              <validation-provider ref="customUrlProvider" name="api_url" :rules="{ required: true, url: true }" v-slot="v">
                <px-field :label="$t('Custom_SMS_Api_Url') + ' *'" :error="v.errors[0]">
                  <template #default="{ id, invalid }"><px-input :id="id" v-model="custom.api_url" placeholder="https://api.provider.com/sms/send" :invalid="invalid" @input="v.validate" /></template>
                </px-field>
              </validation-provider>
              <px-field :label="$t('Custom_SMS_Method')">
                <template #default="{ id }"><vs-px :input-id="id" v-model="custom.method" :clearable="false" :options="['POST', 'GET', 'PUT']" /></template>
              </px-field>
              <px-field :label="$t('Custom_SMS_Content_Type')">
                <template #default="{ id }"><vs-px :input-id="id" v-model="custom.content_type" :clearable="false" :options="['json', 'form']" /></template>
              </px-field>
              <px-field :label="$t('Custom_SMS_Sender')">
                <template #default="{ id }"><px-input :id="id" v-model="custom.sender" placeholder="Sender ID or phone" /></template>
              </px-field>
              <px-field :label="$t('Custom_SMS_Success_Keyword')">
                <template #default="{ id }"><px-input :id="id" v-model="custom.success_keyword" placeholder="e.g. success" /></template>
              </px-field>
            </div>

            <div class="pxcfg__kv">
              <div class="pxcfg__kvhead">{{ $t('Custom_SMS_Headers') }}</div>
              <div v-for="(row, idx) in customHeaderRows" :key="'h-' + idx" class="pxcfg__kvrow">
                <px-input v-model="row.key" placeholder="Header name (e.g. Authorization)" />
                <px-input v-model="row.value" placeholder="Header value (e.g. Bearer xxx)" />
                <px-button class="pxcfg__del" variant="ghost" size="sm" icon-only icon="x" aria-label="Quitar" @click="removeHeaderRow(idx)" />
              </div>
              <px-button variant="secondary" size="sm" icon="plus" @click="addHeaderRow">{{ $t('Custom_SMS_Add_Header') }}</px-button>
            </div>

            <div class="pxcfg__kv">
              <div class="pxcfg__kvhead">{{ $t('Custom_SMS_Payload') }}</div>
              <p class="pxcfg__cardnote">{{ $t('Custom_SMS_Payload_Hint') }}</p>
              <div v-for="(row, idx) in customPayloadRows" :key="'p-' + idx" class="pxcfg__kvrow">
                <px-input v-model="row.key" placeholder="Field name (e.g. to)" />
                <px-input v-model="row.value" placeholder="Value (e.g. {phone})" />
                <px-button class="pxcfg__del" variant="ghost" size="sm" icon-only icon="x" aria-label="Quitar" @click="removePayloadRow(idx)" />
              </div>
              <px-button variant="secondary" size="sm" icon="plus" @click="addPayloadRow">{{ $t('Custom_SMS_Add_Field') }}</px-button>
            </div>

            <px-alert tone="info" bare class="pxcfg__alert">
              <strong>{{ $t('Custom_SMS_Placeholders') }}:</strong>
              <code>{phone}</code> — {{ $t('Custom_SMS_Guide_Placeholder_Phone') }},
              <code>{message}</code> — {{ $t('Custom_SMS_Guide_Placeholder_Message') }},
              <code>{sender}</code> — {{ $t('Custom_SMS_Guide_Placeholder_Sender') }}
              <pre class="pxcfg__pre">POST  https://api.provider.com/sms/send      ({{ $t('Custom_SMS_Content_Type') }}: json)

{{ $t('Custom_SMS_Headers') }}:
  Authorization = Bearer YOUR_API_KEY

{{ $t('Custom_SMS_Payload') }}:
  to      = {phone}
  from    = {sender}
  message = {message}

{{ $t('Custom_SMS_Success_Keyword') }}: "status":"sent"</pre>
            </px-alert>

            <template #footer><px-button variant="primary" icon="check" type="submit" @click="Submit_Custom_SMS">{{ $t('submit') }}</px-button></template>
          </px-card>
        </form>
      </validation-observer>
    </template>
  </div>
</template>

<script>
import { mapActions } from "vuex";
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "SMS Settings"
  },
  components: { PxPageHeader, PxButton, PxCard, PxField, PxInput, PxAlert, "vs-px": VsPx },
  data() {
    return {
      isLoading: true,
      sms_gateway: [],
      default_sms_gateway: '',
      twilio: { TWILIO_SID: '', TWILIO_TOKEN: '', TWILIO_FROM: '' },
      termi: { TERMI_KEY: '', TERMI_SECRET: '', TERMI_SENDER: '' },
      infobip: { base_url: '', api_key: '', sender_from: '' },
      custom: {
        api_url: '', method: 'POST', content_type: 'json', sender: '', success_keyword: '', headers: {}, payload: {},
      },
      customHeaderRows: [],
      customPayloadRows: [],
    };
  },

  methods: {
    ...mapActions(["refreshUserPermissions"]),

    syncValidators() {
      this.$nextTick(() => {
        const map = {
          termiKeyProvider: () => this.termi.TERMI_KEY, termiSecretProvider: () => this.termi.TERMI_SECRET, termiSenderProvider: () => this.termi.TERMI_SENDER,
          twSidProvider: () => this.twilio.TWILIO_SID, twTokenProvider: () => this.twilio.TWILIO_TOKEN, twFromProvider: () => this.twilio.TWILIO_FROM,
          customUrlProvider: () => this.custom.api_url
        };
        Object.keys(map).forEach(ref => {
          const p = this.$refs[ref];
          if (p && p.syncValue) p.syncValue(map[ref]());
        });
      });
    },

    Submit_Default_SMS() {
      this.$refs.default_form_sms.validate().then(success => {
        if (!success) { this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed")); }
        else { this.update_Default_SMS(); }
      });
    },
    Submit_Twilio_SMS() {
      this.$refs.twilio_form_sms.validate().then(success => {
        if (!success) { this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed")); }
        else { this.update_twilio_config(); }
      });
    },
    Submit_Termi_SMS() {
      this.$refs.termi_form_sms.validate().then(success => {
        if (!success) { this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed")); }
        else { this.update_termi_config(); }
      });
    },
    Submit_Custom_SMS() {
      this.$refs.custom_form_sms.validate().then(success => {
        if (!success) { this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed")); }
        else { this.update_custom_config(); }
      });
    },
    Submit_infobip_SMS() {
      this.$refs.infobip_form_sms.validate().then(success => {
        if (!success) { this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed")); }
        else { this.update_infobip_config(); }
      });
    },

    addHeaderRow() { this.customHeaderRows.push({ key: '', value: '' }); },
    removeHeaderRow(idx) { this.customHeaderRows.splice(idx, 1); },
    addPayloadRow() { this.customPayloadRows.push({ key: '', value: '' }); },
    removePayloadRow(idx) { this.customPayloadRows.splice(idx, 1); },
    rowsToObject(rows) {
      const obj = {};
      rows.forEach(r => {
        const key = (r.key || '').trim();
        if (key !== '') { obj[key] = r.value || ''; }
      });
      return obj;
    },
    objectToRows(obj) {
      if (!obj || typeof obj !== 'object') return [];
      return Object.keys(obj).map(k => ({ key: k, value: obj[k] }));
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    update_Default_SMS() {
      NProgress.start(); NProgress.set(0.1);
      axios.put("update_Default_SMS", { default_sms_gateway: this.default_sms_gateway })
        .then(response => { this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success")); NProgress.done(); })
        .catch(error => { NProgress.done(); this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed")); });
    },
    update_twilio_config() {
      NProgress.start(); NProgress.set(0.1);
      axios.post("update_twilio_config", { TWILIO_SID: this.twilio.TWILIO_SID, TWILIO_TOKEN: this.twilio.TWILIO_TOKEN, TWILIO_FROM: this.twilio.TWILIO_FROM })
        .then(response => { Fire.$emit("Event_sms"); this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success")); NProgress.done(); })
        .catch(error => { NProgress.done(); this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed")); });
    },
    update_termi_config() {
      NProgress.start(); NProgress.set(0.1);
      axios.post("update_termi_config", { TERMI_KEY: this.termi.TERMI_KEY, TERMI_SECRET: this.termi.TERMI_SECRET, TERMI_SENDER: this.termi.TERMI_SENDER })
        .then(response => { Fire.$emit("Event_sms"); this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success")); NProgress.done(); })
        .catch(error => { NProgress.done(); this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed")); });
    },
    update_custom_config() {
      NProgress.start(); NProgress.set(0.1);
      axios.post("update_custom_config", {
        api_url: this.custom.api_url, method: this.custom.method, content_type: this.custom.content_type,
        sender: this.custom.sender, success_keyword: this.custom.success_keyword,
        headers: this.rowsToObject(this.customHeaderRows), payload: this.rowsToObject(this.customPayloadRows),
      })
        .then(response => { Fire.$emit("Event_sms"); this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success")); NProgress.done(); })
        .catch(error => { NProgress.done(); this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed")); });
    },
    update_infobip_config() {
      NProgress.start(); NProgress.set(0.1);
      axios.post("update_infobip_config", { base_url: this.infobip.base_url, api_key: this.infobip.api_key, sender_from: this.infobip.sender_from })
        .then(response => { Fire.$emit("Event_sms"); this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success")); NProgress.done(); })
        .catch(error => { NProgress.done(); this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed")); });
    },

    get_sms_config() {
      axios.get("get_sms_config")
        .then(response => {
          this.twilio = response.data.twilio;
          this.termi = response.data.termi;
          this.infobip = response.data.infobip;
          this.sms_gateway = response.data.sms_gateway;
          this.default_sms_gateway = response.data.default_sms_gateway;

          const c = response.data.custom || {};
          this.custom = {
            api_url: c.api_url || '', method: c.method || 'POST', content_type: c.content_type || 'json',
            sender: c.sender || '', success_keyword: c.success_keyword || '', headers: c.headers || {}, payload: c.payload || {},
          };
          this.customHeaderRows = this.objectToRows(this.custom.headers);
          this.customPayloadRows = this.objectToRows(this.custom.payload);

          this.isLoading = false;
          this.syncValidators();
        })
        .catch(error => {
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },
  }, //end Methods

  created: function() {
    this.get_sms_config();
    Fire.$on("Event_sms", () => {
      this.get_sms_config();
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__card { margin-top: var(--pxn-space-5); }
.pxcfg__cardnote { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); margin: var(--pxn-space-1) 0; }
.pxcfg__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
.pxcfg__grid--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
@media (max-width: 900px) { .pxcfg__grid--3 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 640px) { .pxcfg__grid, .pxcfg__grid--3 { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__guide { margin-top: var(--pxn-space-4); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); line-height: 1.6; }
.pxcfg__kv { margin-top: var(--pxn-space-5); }
.pxcfg__kvhead { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); margin-bottom: var(--pxn-space-3); }
.pxcfg__kvrow { display: grid; grid-template-columns: 5fr 6fr auto; gap: var(--pxn-space-3); align-items: center; margin-bottom: var(--pxn-space-2); }
@media (max-width: 640px) { .pxcfg__kvrow { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__alert { margin-top: var(--pxn-space-4); }
.pxcfg__alert code { font-family: var(--pxn-font-mono, monospace); }
.pxcfg__pre { background: var(--pxn-surface-2); padding: var(--pxn-space-3); border-radius: var(--pxn-radius-sm); overflow-x: auto; font-size: var(--pxn-fs-xs); margin-top: var(--pxn-space-3); }
.pxcfg__del ::v-deep .pxn-btn__icon { color: var(--pxn-danger); }
</style>
