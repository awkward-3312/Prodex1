<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('mail_settings')"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('mail_settings') }]"
    />

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="6" />
    </div>

    <validation-observer v-else ref="form_config_mail">
      <form @submit.prevent="Submit_config_mail">
        <px-card :title="$t('mail_settings')" class="pxcfg__card">
          <div class="pxcfg__grid pxcfg__grid--3">
            <validation-provider ref="mailerProvider" name="MAIL_MAILER" :rules="{ required: true }" v-slot="v">
              <px-field label="MAIL_MAILER *" hint='Soportados: "smtp", "sendmail", "mailgun", "ses", "postmark", "log"' :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" v-model="server.mail_mailer" placeholder="MAIL_MAILER" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>
            <validation-provider ref="hostProvider" name="HOST" :rules="{ required: true }" v-slot="v">
              <px-field label="MAIL_HOST *" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" v-model="server.host" placeholder="MAIL_HOST" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>
            <validation-provider ref="portProvider" name="PORT" :rules="{ required: true }" v-slot="v">
              <px-field label="MAIL_PORT *" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" v-model="server.port" placeholder="MAIL_PORT" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>
            <validation-provider ref="senderProvider" name="sender" :rules="{ required: true }" v-slot="v">
              <px-field label="Sender Name *" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" v-model="server.sender_name" placeholder="Sender Name" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>
            <validation-provider ref="senderEmailProvider" name="sender_email" :rules="{ required: true, email: true }" v-slot="v">
              <px-field label="Sender Email *" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" type="email" v-model="server.sender_email" placeholder="Sender Email" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>
            <validation-provider ref="usernameProvider" name="Username" :rules="{ required: true }" v-slot="v">
              <px-field label="MAIL_USERNAME *" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" v-model="server.username" placeholder="MAIL_USERNAME" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>
            <validation-provider ref="passwordProvider" name="Password" :rules="{ required: true }" v-slot="v">
              <px-field label="MAIL_PASSWORD *" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" v-model="server.password" placeholder="MAIL_PASSWORD" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>
            <validation-provider ref="encryptionProvider" name="encryption" :rules="{ required: true }" v-slot="v">
              <px-field label="MAIL_ENCRYPTION *" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" v-model="server.encryption" placeholder="MAIL_ENCRYPTION" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>
          </div>
          <template #footer>
            <px-button variant="primary" icon="check" type="submit" @click="Submit_config_mail">{{ $t('submit') }}</px-button>
            <px-button variant="secondary" :disabled="isTesting" @click="Test_config_mail">
              {{ isTesting ? $t('Loading') + '...' : 'Save & Test Mail' }}
            </px-button>
          </template>
        </px-card>
      </form>
    </validation-observer>
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

export default {
  metaInfo: {
    title: "Mail Settings"
  },
  components: { PxPageHeader, PxButton, PxCard, PxField, PxInput },
  data() {
    return {
      isLoading: true,
      isTesting: false,
      server: {
        host: "",
        port: "",
        username: "",
        password: "",
        encryption: "",
        sender_name: "",
        sender_email: "",
        mail_mailer: "",
      }
    };
  },

  methods: {
    ...mapActions(["refreshUserPermissions"]),

    syncValidators() {
      this.$nextTick(() => {
        const map = {
          mailerProvider: "mail_mailer", hostProvider: "host", portProvider: "port",
          senderProvider: "sender_name", senderEmailProvider: "sender_email",
          usernameProvider: "username", passwordProvider: "password", encryptionProvider: "encryption"
        };
        Object.keys(map).forEach(ref => {
          const p = this.$refs[ref];
          if (p && p.syncValue) p.syncValue(this.server[map[ref]]);
        });
      });
    },

    Submit_config_mail() {
      this.$refs.form_config_mail.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Update_config_mail();
        }
      });
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    Update_config_mail(silent = false) {
      NProgress.start();
      NProgress.set(0.1);
      return axios
        .put("update_config_mail/" + this.server.id, {
          mail_mailer: this.server.mail_mailer,
          host: this.server.host,
          port: this.server.port,
          sender_name: this.server.sender_name,
          sender_email: this.server.sender_email,
          username: this.server.username,
          password: this.server.password,
          encryption: this.server.encryption
        })
        .then(response => {
          Fire.$emit("Event_Smtp");
          if (!silent) {
            this.makeToast(
              "success",
              this.$t("Successfully_Updated"),
              this.$t("Success")
            );
          }
          NProgress.done();
          return response;
        })
        .catch(error => {
          NProgress.done();
          if (!silent) {
            this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
          }
          throw error;
        });
    },

    get_config_mail() {
      axios
        .get("get_config_mail")
        .then(response => {
          this.server = response.data.server;
          this.isLoading = false;
          this.syncValidators();
        })
        .catch(error => {
          this.isLoading = false;
        });
    },

    Test_config_mail() {
      if (this.isTesting) return;

      this.$refs.form_config_mail.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
          return;
        }

        this.isTesting = true;
        NProgress.start();
        NProgress.set(0.1);

        this.Update_config_mail(true)
          .then(() => {
            return axios.post("test_config_mail");
          })
          .then(response => {
            const msg =
              (response.data && (response.data.message || response.data.msg)) ||
              this.$t("Successfully_Updated");
            this.makeToast("success", msg, this.$t("Success"));
          })
          .catch(error => {
            const msg =
              (error.response && error.response.data && (error.response.data.message || error.response.data.errors)) ||
              this.$t("InvalidData");
            this.makeToast("danger", msg, this.$t("Failed"));
          })
          .finally(() => {
            this.isTesting = false;
            NProgress.done();
          });
      });
    },
  }, //end Methods

  created: function() {
    this.get_config_mail();

    Fire.$on("Event_Smtp", () => {
      this.get_config_mail();
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
@media (max-width: 900px) { .pxcfg__grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 640px) { .pxcfg__grid { grid-template-columns: minmax(0, 1fr); } }
</style>
