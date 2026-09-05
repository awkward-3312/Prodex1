<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('email_templates') || 'Email templates'"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('email_templates') || 'Email templates' }]"
    />

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="10" />
    </div>

    <template v-else>
      <px-card class="pxcfg__card">
        <div class="pxcfg__grid pxcfg__grid--3">
          <px-field :label="$t('Template_Language') || 'Template language'"
            :hint="$t('Edit_templates_per_language') || 'Templates are saved per language.'">
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
          <form @submit.prevent="update_custom_email(t.key)">
            <px-field :label="$t('Subject') || 'Subject'">
              <template #default="{ id }"><px-input :id="id" v-model="models[t.key].subject" /></template>
            </px-field>
            <px-field :label="$t('Body') || 'Body'" class="pxcfg__mt">
              <template #default>
                <VueEditor :id="'editor_' + t.key" v-model="models[t.key].body" :editor-toolbar="customToolbar" />
              </template>
            </px-field>
            <px-button class="pxcfg__mt" variant="primary" icon="check" type="submit" :loading="Submit_Processing" :disabled="Submit_Processing" @click="update_custom_email(t.key)">{{ $t('submit') }}</px-button>
          </form>
        </div>
      </px-card>

      <px-card :title="$t('Asset_Validation_Due') || 'Asset validation due'" class="pxcfg__card">
        <div class="pxcfg__panel">
          <p class="pxcfg__tags"><strong>{{ $t('Available_Tags') }}:</strong> <code>{asset_name},{asset_tag},{next_validation},{asset_edit_url},{business_name}</code></p>
          <form @submit.prevent="update_custom_email('asset_validation_due')">
            <px-field :label="$t('Subject') || 'Subject'">
              <template #default="{ id }"><px-input :id="id" v-model="asset_validation_due.subject" /></template>
            </px-field>
            <px-field :label="$t('Body') || 'Body'" class="pxcfg__mt">
              <template #default>
                <VueEditor id="editor_asset_validation_due" v-model="asset_validation_due.body" :editor-toolbar="customToolbar" />
              </template>
            </px-field>
            <px-button class="pxcfg__mt" variant="primary" icon="check" type="submit" :loading="Submit_Processing" :disabled="Submit_Processing" @click="update_custom_email('asset_validation_due')">{{ $t('submit') }}</px-button>
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
          <form @submit.prevent="update_custom_email(t.key)">
            <px-field :label="$t('Subject') || 'Subject'">
              <template #default="{ id }"><px-input :id="id" v-model="models[t.key].subject" /></template>
            </px-field>
            <px-field :label="$t('Body') || 'Body'" class="pxcfg__mt">
              <template #default>
                <VueEditor :id="'editor_' + t.key" v-model="models[t.key].body" :editor-toolbar="customToolbar" />
              </template>
            </px-field>
            <px-button class="pxcfg__mt" variant="primary" icon="check" type="submit" :loading="Submit_Processing" :disabled="Submit_Processing" @click="update_custom_email(t.key)">{{ $t('submit') }}</px-button>
          </form>
        </div>
      </px-card>
    </template>
  </div>
</template>

<script>
import { mapActions } from "vuex";
import NProgress from "nprogress";
import RichTextEditor from "@/components/RichTextEditor.vue";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  components: {
    VueEditor: RichTextEditor,
    PxPageHeader, PxButton, PxCard, PxField, PxInput, "vs-px": VsPx
  },
  metaInfo: {
    title: "Email Templates"
  },

  data() {
    return {
      isLoading: true,
      Submit_Processing: false,
      clientTab: 'sale',
      supplierTab: 'purchase',
      sale: { subject: '', body: '' },
      quotation: { subject: '', body: '' },
      payment_received: { subject: '', body: '' },
      purchase: { subject: '', body: '' },
      payment_sent: { subject: '', body: '' },
      booking: { subject: '', body: '' },
      asset_validation_due: { subject: '', body: '' },

      custom_email_body: '',
      custom_email_subject: '',
      customToolbar: [
        ["bold", "italic", "underline"],
        [{ list: "ordered" }, { list: "bullet" }],
      ],

      selectedLocale: 'en',
      languages: [],
    };
  },

  computed: {
    languageOptions() {
      const list = (this.languages && this.languages.length) ? this.languages : [{ name: 'English', locale: 'en' }];
      return list.filter(l => l.is_active == true || l.is_active === 1 || l.is_active === '1').map(l => ({ name: l.name, locale: l.locale }));
    },
    models() {
      return {
        sale: this.sale,
        quotation: this.quotation,
        booking: this.booking,
        payment_received: this.payment_received,
        purchase: this.purchase,
        payment_sent: this.payment_sent,
        asset_validation_due: this.asset_validation_due
      };
    },
    clientTabs() {
      return [
        { key: 'sale', label: this.$t('Sale'), tags: '{contact_name},{business_name},{invoice_number},{invoice_url},{total_amount},{paid_amount},{due_amount}' },
        { key: 'quotation', label: this.$t('Quote'), tags: '{contact_name},{business_name},{quotation_number},{quotation_url},{total_amount}' },
        { key: 'booking', label: this.$t('Custom_Template_Booking') || 'Custom Template for Booking', tags: '{contact_name},{business_name},{booking_number},{booking_date},{start_time},{end_time},{service_name}' },
        { key: 'payment_received', label: this.$t('PaiementsReceived'), tags: '{contact_name},{business_name},{payment_number},{paid_amount}' }
      ];
    },
    supplierTabs() {
      return [
        { key: 'purchase', label: this.$t('Purchase'), tags: '{contact_name},{business_name},{invoice_number},{invoice_url},{total_amount},{paid_amount},{due_amount}' },
        { key: 'payment_sent', label: this.$t('PaiementsSent'), tags: '{contact_name},{business_name},{payment_number},{paid_amount}' }
      ];
    }
  },

  methods: {
    ...mapActions(["refreshUserPermissions"]),

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },

    update_custom_email(email_type) {
      this.Submit_Processing = true;
      NProgress.start();
      NProgress.set(0.1);

      if (email_type == 'sale') {
        this.custom_email_body = this.sale.body;
        this.custom_email_subject = this.sale.subject;
      } else if (email_type == 'quotation') {
        this.custom_email_body = this.quotation.body;
        this.custom_email_subject = this.quotation.subject;
      } else if (email_type == 'payment_received') {
        this.custom_email_body = this.payment_received.body;
        this.custom_email_subject = this.payment_received.subject;
      } else if (email_type == 'purchase') {
        this.custom_email_body = this.purchase.body;
        this.custom_email_subject = this.purchase.subject;
      } else if (email_type == 'payment_sent') {
        this.custom_email_body = this.payment_sent.body;
        this.custom_email_subject = this.payment_sent.subject;
      } else if (email_type == 'booking') {
        this.custom_email_body = this.booking.body;
        this.custom_email_subject = this.booking.subject;
      } else if (email_type == 'asset_validation_due') {
        this.custom_email_body = this.asset_validation_due.body;
        this.custom_email_subject = this.asset_validation_due.subject;
      }

      axios.put("/update_custom_email", {
        custom_email_body: this.custom_email_body,
        custom_email_subject: this.custom_email_subject,
        email_type: email_type,
        locale: this.selectedLocale
      }, {
        headers: {
          'Content-Type': 'application/json'
        }
      })
        .then(response => {
          Fire.$emit("Event_email");
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

    get_emails_template() {
      const locale = this.selectedLocale || 'en';
      axios
        .get("get_emails_template?locale=" + encodeURIComponent(locale))
        .then(response => {
          this.sale = response.data.sale;
          this.quotation = response.data.quotation;
          this.payment_received = response.data.payment_received;
          this.purchase = response.data.purchase;
          this.payment_sent = response.data.payment_sent;
          this.booking = response.data.booking || { subject: '', body: '' };
          this.asset_validation_due = response.data.asset_validation_due || { subject: '', body: '' };

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
      this.get_emails_template();
    },
  }, //end Methods

  created: function() {
    this.fetchLanguages();
    this.get_emails_template();

    Fire.$on("Event_email", () => {
      this.get_emails_template();
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
.pxcfg__panel ::v-deep .ql-toolbar { border-color: var(--pxn-border); border-radius: var(--pxn-radius-md) var(--pxn-radius-md) 0 0; }
.pxcfg__panel ::v-deep .ql-container { border-color: var(--pxn-border); border-radius: 0 0 var(--pxn-radius-md) var(--pxn-radius-md); min-height: 160px; }
</style>
