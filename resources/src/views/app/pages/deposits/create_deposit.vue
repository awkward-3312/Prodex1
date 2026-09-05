<template>
  <div class="px-next pxff">
    <px-page-header :title="$t('Create_deposit')" :breadcrumbs="[{ label: $t('Deposits'), href: '#/app/deposits/list' }, { label: $t('Create_deposit') }]" />

    <div v-if="isLoading" class="pxff__pad">
      <px-skeleton variant="lines" :rows="5" />
    </div>

    <validation-observer v-else ref="Create_Deposit">
      <b-form @submit.prevent="Submit_Deposit">
        <px-card>
          <div class="pxff__grid">
            <validation-provider ref="dateProvider" name="date" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('date')" required :error="v.errors[0]">
                <template #default="{ id }">
                  <px-input :id="id" type="date" v-model="deposit.date" @input="v.validate" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider ref="accountProvider" name="Account" v-slot="v">
              <px-field :label="$t('Account')" :error="v.errors[0]">
                <template #default="{ id }">
                  <vs-px :input-id="id" v-model="deposit.account_id" :reduce="o => o.value"
                    :placeholder="$t('Choose_Account')"
                    :options="accounts.map(a => ({ label: a.account_name, value: a.id }))" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider ref="categoryProvider" name="category" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('Deposit_Category')" required :error="v.errors[0]" :class="{ 'is-invalid': !!v.errors.length }">
                <template #default="{ id }">
                  <vs-px :input-id="id" v-model="deposit.category_id" :reduce="o => o.value" :invalid="!!v.errors.length"
                    :placeholder="$t('Choose_Category')"
                    :options="deposit_category.map(c => ({ label: c.title, value: c.id }))"
                    @input="v.validate" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider ref="amountProvider" name="Amount" :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="v">
              <px-field :label="$t('Amount')" required :error="v.errors[0]">
                <template #default="{ id }">
                  <px-input :id="id" v-model="deposit.amount" :placeholder="$t('Amount')" @input="v.validate" />
                </template>
              </px-field>
            </validation-provider>

            <px-field :label="$t('Details')" class="pxff__wide">
              <template #default="{ id }">
                <px-textarea :id="id" v-model="deposit.description" :rows="4" :placeholder="$t('Afewwords')" />
              </template>
            </px-field>
          </div>

          <template #footer>
            <div class="pxff__actionbar">
              <px-button variant="secondary" type="button" @click="$router.push({ name: 'index_deposit' })">{{ $t('Cancel') }}</px-button>
              <px-button variant="primary" type="submit" icon="check" :loading="SubmitProcessing">{{ $t('submit') }}</px-button>
            </div>
          </template>
        </px-card>
      </b-form>
    </validation-observer>
  </div>
</template>

<script>
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "Create deposit"
  },
  components: {
    PxPageHeader, PxCard, PxField, PxInput, PxTextarea, PxButton, "vs-px": VsPx
  },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      accounts: [],
      deposit_category: [],
      deposit: {
        date: new Date().toISOString().slice(0, 10),
        account_id: "",
        category_id: "",
        description: "",
        amount: ""
      }
    };
  },

  methods: {
    syncValidators() {
      this.$nextTick(() => {
        const map = {
          dateProvider: this.deposit.date,
          categoryProvider: this.deposit.category_id,
          amountProvider: this.deposit.amount
        };
        Object.keys(map).forEach(ref => {
          if (this.$refs[ref] && typeof this.$refs[ref].syncValue === "function") {
            this.$refs[ref].syncValue(map[ref]);
          }
        });
      });
    },

    //------------- Submit Validation Create deposit
    Submit_Deposit() {
      this.$refs.Create_Deposit.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Create_Deposit();
        }
      });
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    //------ Validation State
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    // ---------------------------Create deposit
    Create_Deposit() {
      this.SubmitProcessing = true;
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("deposits", {
          deposit: this.deposit
        })
        .then(response => {
          // Complete the animation of theprogress bar.
          NProgress.done();
          this.SubmitProcessing = false;
          this.$router.push({
            name: "index_deposit"
          });
          this.makeToast(
            "success",
            this.$t("Successfully_Created"),
            this.$t("Success")
          );
        })
        .catch(error => {
          // Complete the animation of theprogress bar.
          NProgress.done();
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
          this.SubmitProcessing = false;
        });
    },

    //---------------------------------------Get deposit Elements ------------------------------\\
    GetElements() {
      axios
        .get("deposits/create")
        .then(response => {
          this.deposit_category = response.data.deposits_category;
          this.accounts = response.data.accounts;
          this.isLoading = false;
          this.syncValidators();
        })
        .catch(response => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    }
  },

  //----------------------------- Created function-------------------
  created() {
    this.GetElements();
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxff { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxff { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxff__pad { padding: var(--pxn-space-6) 0; }
.pxff__grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5) var(--pxn-space-6); }
@media (max-width: 900px) { .pxff__grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxff__grid { grid-template-columns: minmax(0, 1fr); } }
.pxff__wide { grid-column: 1 / -1; }
.pxff__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); }
.pxff ::v-deep .pxn-field.is-invalid .pxn-input,
.pxff ::v-deep .pxn-field.is-invalid .vs__dropdown-toggle { border-color: var(--pxn-danger); }
</style>
