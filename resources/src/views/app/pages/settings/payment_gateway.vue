<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('Payment_Gateway')"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('Payment_Gateway') }]"
    />

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="5" />
    </div>

    <validation-observer v-else ref="form_payment">
      <form @submit.prevent="Submit_Payment">
        <px-card :title="$t('Payment_Gateway')" class="pxcfg__card">
          <div class="pxcfg__grid">
            <px-field label="STRIPE_KEY">
              <template #default="{ id }"><px-input :id="id" type="password" v-model="gateway.stripe_key" :placeholder="$t('LeaveBlank')" /></template>
            </px-field>
            <px-field label="STRIPE_SECRET">
              <template #default="{ id }"><px-input :id="id" type="password" v-model="gateway.stripe_secret" :placeholder="$t('LeaveBlank')" /></template>
            </px-field>
            <px-field label="Modo de cobro con tarjeta" hint="Terminal externa permite registrar pagos con tarjeta sin llaves Stripe.">
              <template #default="{ id }">
                <vs-px :input-id="id" v-model="gateway.card_processing_mode" :reduce="o => o.value" :clearable="false"
                  :options="[
                    { label: 'Terminal bancaria externa', value: 'external_terminal' },
                    { label: 'Stripe', value: 'stripe' }
                  ]" />
              </template>
            </px-field>
          </div>
          <px-check :modelValue="!!gateway.deleted" @change="v => gateway.deleted = v" class="pxcfg__mt">
            {{ $t('Remove_Stripe_Key_Secret') }}
          </px-check>
          <template #footer>
            <px-button variant="primary" icon="check" type="submit" @click="Submit_Payment">{{ $t('submit') }}</px-button>
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
import PxCheck from "@/components/px-next/PxCheck.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "Payment Gateway"
  },
  components: { PxPageHeader, PxButton, PxCard, PxField, PxInput, PxCheck, "vs-px": VsPx },
  data() {
    return {
      isLoading: true,
      gateway: {
        stripe_key: "",
        stripe_secret: "",
        card_processing_mode: "external_terminal",
        deleted: false,
      },
    };
  },

  methods: {
    ...mapActions(["refreshUserPermissions"]),

    Submit_Payment() {
      this.$refs.form_payment.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Update_Payment();
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

    Update_Payment() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("payment_gateway", {
          stripe_key: this.gateway.stripe_key,
          stripe_secret: this.gateway.stripe_secret,
          card_processing_mode: this.gateway.card_processing_mode || "external_terminal",
          deleted: this.gateway.deleted,
        })
        .then(response => {
          Fire.$emit("Event_payment");
          this.makeToast(
            "success",
            this.$t("Successfully_Updated"),
            this.$t("Success")
          );
          NProgress.done();
        })
        .catch(error => {
          NProgress.done();
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Get_Payment_Gateway() {
      axios
        .get("get_payment_gateway")
        .then(response => {
          this.gateway = response.data.gateway;
          this.isLoading = false;
        })
        .catch(error => {
          this.isLoading = false;
        });
    },
  }, //end Methods

  created: function() {
    this.Get_Payment_Gateway();

    Fire.$on("Event_payment", () => {
      this.Get_Payment_Gateway();
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
.pxcfg__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
@media (max-width: 640px) { .pxcfg__grid { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__mt { margin-top: var(--pxn-space-4); }
</style>
