<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('sms_templates')"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('sms_templates') }]"
    />

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="8" />
    </div>

    <template v-else>
      <px-card class="pxcfg__card">
        <div class="pxcfg__grid pxcfg__grid--3">
          <px-field :label="$t('Template_Language') || 'Template language'"
            :hint="$t('Edit_templates_per_language') || 'Templates are saved per language. When sending SMS, the system default language is used.'">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="selectedLocale" :reduce="o => o.value" :clearable="false"
                :options="languageOptions.map(l => ({ label: l.name, value: l.locale }))" @input="onLocaleChange" />
            </template>
          </px-field>
        </div>
      </px-card>

      <px-card :title="$t('Notification_Client')" class="pxcfg__card">
        <div class="pxcfg__tabbar">
          <button v-for="t in clientTabs" :key="t.key" type="button" class="pxcfg__tab pxn-ring"
            :class="{ 'is-active': clientTab === t.key }" @click="clientTab = t.key">{{ t.label }}</button>
        </div>
        <div v-for="t in clientTabs" v-show="clientTab === t.key" :key="'cp-' + t.key" class="pxcfg__panel">
          <p class="pxcfg__tags"><strong>{{ $t('Available_Tags') }}:</strong> <code>{{ t.tags }}</code></p>
          <form @submit.prevent="update_sms_body(t.key)">
            <px-field :label="$t('sms_body')">
              <template #default="{ id }"><px-textarea :id="id" :value="bodies[t.key]" @input="v => setBody(t.key, v)" :rows="8" :placeholder="$t('sms_body')" /></template>
            </px-field>
            <px-button class="pxcfg__mt" variant="primary" icon="check" type="submit" :loading="Submit_Processing" :disabled="Submit_Processing" @click="update_sms_body(t.key)">{{ $t('submit') }}</px-button>
          </form>
        </div>
      </px-card>

      <px-card :title="$t('Notification_Supplier')" class="pxcfg__card">
        <div class="pxcfg__tabbar">
          <button v-for="t in supplierTabs" :key="t.key" type="button" class="pxcfg__tab pxn-ring"
            :class="{ 'is-active': supplierTab === t.key }" @click="supplierTab = t.key">{{ t.label }}</button>
        </div>
        <div v-for="t in supplierTabs" v-show="supplierTab === t.key" :key="'sp-' + t.key" class="pxcfg__panel">
          <p class="pxcfg__tags"><strong>{{ $t('Available_Tags') }}:</strong> <code>{{ t.tags }}</code></p>
          <form @submit.prevent="update_sms_body(t.key)">
            <px-field :label="$t('sms_body')">
              <template #default="{ id }"><px-textarea :id="id" :value="bodies[t.key]" @input="v => setBody(t.key, v)" :rows="8" :placeholder="$t('sms_body')" /></template>
            </px-field>
            <px-button class="pxcfg__mt" variant="primary" icon="check" type="submit" :loading="Submit_Processing" :disabled="Submit_Processing" @click="update_sms_body(t.key)">{{ $t('submit') }}</px-button>
          </form>
        </div>
      </px-card>
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
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "SMS Templates"
  },
  components: { PxPageHeader, PxButton, PxCard, PxField, PxTextarea, "vs-px": VsPx },
  data() {
    return {
      isLoading: true,
      Submit_Processing: false,
      clientTab: 'sale',
      supplierTab: 'purchase',
      sms_body_sale: '',
      sms_body_quotation: '',
      sms_body_payment_received: '',
      sms_body_subscription_reminder: '',
      sms_body_purchase: '',
      sms_body_payment_sent: '',
      sms_body_asset_validation_due: '',
      sms_body: '',
      selectedLocale: 'en',
      languages: [],
    };
  },

  computed: {
    languageOptions() {
      const list = (this.languages && this.languages.length) ? this.languages : [{ name: 'English', locale: 'en' }];
      return list.filter(l => l.is_active == true || l.is_active === 1 || l.is_active === '1').map(l => ({ name: l.name, locale: l.locale }));
    },
    clientTabs() {
      return [
        { key: 'sale', label: this.$t('Sale'), tags: '{contact_name},{business_name},{invoice_number},{invoice_url},{total_amount},{paid_amount},{due_amount}' },
        { key: 'quotation', label: this.$t('Quote'), tags: '{contact_name},{business_name},{quotation_number},{quotation_url},{total_amount}' },
        { key: 'payment_received', label: this.$t('PaiementsReceived'), tags: '{contact_name},{business_name},{payment_number},{paid_amount}' },
        { key: 'subscription_reminder', label: 'Subscription Reminder', tags: '{client_name}, {business_name}, {next_billing_date}' },
        { key: 'asset_validation_due', label: this.$t('Asset_Validation_Due') || 'Asset validation due', tags: '{asset_name},{asset_tag},{next_validation},{business_name}' }
      ];
    },
    supplierTabs() {
      return [
        { key: 'purchase', label: this.$t('Purchase'), tags: '{contact_name},{business_name},{invoice_number},{invoice_url},{total_amount},{paid_amount},{due_amount}' },
        { key: 'payment_sent', label: this.$t('PaiementsSent'), tags: '{contact_name},{business_name},{payment_number},{paid_amount}' }
      ];
    },
    bodies() {
      return {
        sale: this.sms_body_sale,
        quotation: this.sms_body_quotation,
        payment_received: this.sms_body_payment_received,
        subscription_reminder: this.sms_body_subscription_reminder,
        asset_validation_due: this.sms_body_asset_validation_due,
        purchase: this.sms_body_purchase,
        payment_sent: this.sms_body_payment_sent
      };
    }
  },

  methods: {
    ...mapActions(["refreshUserPermissions"]),

    setBody(key, val) { this['sms_body_' + key] = val; },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },

    update_sms_body(sms_body_type) {
      this.Submit_Processing = true;
      NProgress.start();
      NProgress.set(0.1);

      if (sms_body_type == 'sale') {
        this.sms_body = this.sms_body_sale;
      } else if (sms_body_type == 'quotation') {
        this.sms_body = this.sms_body_quotation;
      } else if (sms_body_type == 'payment_received') {
        this.sms_body = this.sms_body_payment_received;
      } else if (sms_body_type == 'purchase') {
        this.sms_body = this.sms_body_purchase;
      } else if (sms_body_type == 'payment_sent') {
        this.sms_body = this.sms_body_payment_sent;
      } else if (sms_body_type == 'subscription_reminder') {
        this.sms_body = this.sms_body_subscription_reminder;
      } else if (sms_body_type == 'asset_validation_due') {
        this.sms_body = this.sms_body_asset_validation_due;
      }

      axios
        .put("/update_sms_body", {
          sms_body: this.sms_body,
          sms_body_type: sms_body_type,
          locale: this.selectedLocale,
        })
        .then(response => {
          Fire.$emit("Event_sms");
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
          NProgress.done();
          this.Submit_Processing = false;
        })
        .catch(error => {
          NProgress.done();
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
          this.Submit_Processing = false;
        });
    },

    get_sms_template() {
      const locale = this.selectedLocale || 'en';
      axios
        .get("get_sms_template?locale=" + encodeURIComponent(locale))
        .then(response => {
          this.sms_body_sale = response.data.sms_body_sale;
          this.sms_body_quotation = response.data.sms_body_quotation;
          this.sms_body_payment_received = response.data.sms_body_payment_received;
          this.sms_body_purchase = response.data.sms_body_purchase;
          this.sms_body_payment_sent = response.data.sms_body_payment_sent;
          this.sms_body_subscription_reminder = response.data.sms_body_subscription_reminder;
          this.sms_body_asset_validation_due = response.data.sms_body_asset_validation_due || '';

          this.isLoading = false;
        })
        .catch(error => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    fetchLanguages() {
      axios.get("/languages_setting").then(response => {
        this.languages = response.data || [];
        if (!this.selectedLocale && this.languages.length) {
          const defaultLang = this.languages.find(l => l.is_default) || this.languages[0];
          if (defaultLang) this.selectedLocale = defaultLang.locale;
        }
      }).catch(() => {
        this.languages = [];
      });
    },

    onLocaleChange() {
      this.isLoading = true;
      this.get_sms_template();
    },
  }, //end Methods

  created: function() {
    this.fetchLanguages();
    this.get_sms_template();

    Fire.$on("Event_sms", () => {
      this.get_sms_template();
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
.pxcfg__grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
@media (max-width: 640px) { .pxcfg__grid { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__mt { margin-top: var(--pxn-space-4); }
.pxcfg__tabbar { display: flex; gap: var(--pxn-space-2); border-bottom: 1px solid var(--pxn-border); flex-wrap: wrap; }
.pxcfg__tab { appearance: none; background: none; border: 0; border-bottom: 2px solid transparent; white-space: nowrap; padding: var(--pxn-space-3) var(--pxn-space-4); font: inherit; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink-3); cursor: pointer; transition: color 120ms, border-color 120ms; }
.pxcfg__tab:hover { color: var(--pxn-ink); }
.pxcfg__tab.is-active { color: var(--pxn-ink); border-bottom-color: var(--pxn-primary); font-weight: var(--pxn-fw-semibold); }
.pxcfg__panel { margin-top: var(--pxn-space-4); }
.pxcfg__tags { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); margin: 0 0 var(--pxn-space-3); }
.pxcfg__tags code { font-family: var(--pxn-font-mono, monospace); overflow-wrap: anywhere; }
</style>
