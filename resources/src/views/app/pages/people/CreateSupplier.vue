<template>
  <div class="main-content prodex-ui supplier-create-page">
    <div class="px-page">
      <div class="px-page-header">
        <div>
          <div class="px-page-eyebrow">
            <lucide-icon name="briefcase-business" />
            <span>{{ $t('Suppliers') }}</span>
          </div>
          <h1 class="px-page-title">{{ $t('Add') }} {{ $t('Suppliers') }}</h1>
          <p class="px-page-description">Registra la información de contacto, fiscal y comercial del proveedor.</p>
        </div>
        <div class="px-page-actions">
          <b-button variant="outline-secondary" @click="$router.push({ name: 'Suppliers' })">
            <lucide-icon name="arrow-left" /> {{ $t('Cancel') }}
          </b-button>
        </div>
      </div>

      <validation-observer ref="Create_Provider">
        <b-form @submit.prevent="Submit_Provider">
          <section class="px-section">
            <div class="px-section-header">
              <div class="px-section-heading">
                <span class="px-section-icon"><lucide-icon name="briefcase-business" /></span>
                <div>
                  <h2 class="px-section-title">Información principal</h2>
                  <p class="px-section-description">Datos básicos para identificar y contactar al proveedor.</p>
                </div>
              </div>
            </div>
            <div class="px-section-body">
              <b-row>
                <b-col md="6" sm="12">
                  <validation-provider name="Name Provider" :rules="{ required: true}" v-slot="validationContext">
                    <b-form-group :label="$t('SupplierName') + ' *'">
                      <b-form-input :state="getValidationState(validationContext)" aria-describedby="name-feedback" label="name" v-model="provider.name" :placeholder="$t('SupplierName')"></b-form-input>
                      <b-form-invalid-feedback id="name-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>
                <b-col md="6" sm="12">
                  <b-form-group :label="$t('Email')">
                    <b-form-input label="email" v-model="provider.email" :placeholder="$t('Email')"></b-form-input>
                  </b-form-group>
                </b-col>
                <b-col md="6" sm="12">
                  <b-form-group :label="$t('Phone')">
                    <b-form-input label="Phone" v-model="provider.phone" :placeholder="$t('Phone')"></b-form-input>
                  </b-form-group>
                </b-col>
              </b-row>
            </div>
          </section>

          <section class="px-section">
            <div class="px-section-header">
              <div class="px-section-heading">
                <span class="px-section-icon"><lucide-icon name="map-pin" /></span>
                <div>
                  <h2 class="px-section-title">Ubicación y datos fiscales</h2>
                  <p class="px-section-description">Información utilizada para identificar fiscalmente y localizar al proveedor.</p>
                </div>
              </div>
            </div>
            <div class="px-section-body">
              <b-row>
                <b-col md="6" sm="12">
                  <b-form-group :label="$t('Tax_Number')">
                    <b-form-input label="Tax_Number" v-model="provider.tax_number" :placeholder="$t('Tax_Number')"></b-form-input>
                  </b-form-group>
                </b-col>
                <b-col md="6" sm="12">
                  <b-form-group :label="$t('Country')">
                    <b-form-input label="Country" v-model="provider.country" :placeholder="$t('Country')"></b-form-input>
                  </b-form-group>
                </b-col>
                <b-col md="6" sm="12">
                  <b-form-group :label="$t('City')">
                    <b-form-input label="City" v-model="provider.city" :placeholder="$t('City')"></b-form-input>
                  </b-form-group>
                </b-col>
                <b-col md="12" sm="12">
                  <b-form-group :label="$t('Adress')">
                    <textarea label="Adress" class="form-control" rows="4" v-model="provider.adresse" :placeholder="$t('Adress')"></textarea>
                  </b-form-group>
                </b-col>
              </b-row>
            </div>
          </section>

          <section class="px-section">
            <div class="px-section-header">
              <div class="px-section-heading">
                <span class="px-section-icon"><lucide-icon name="wallet" /></span>
                <div>
                  <h2 class="px-section-title">Condiciones comerciales</h2>
                  <p class="px-section-description">Saldos iniciales y límites utilizados en las operaciones con el proveedor.</p>
                </div>
              </div>
            </div>
            <div class="px-section-body">
              <b-row>
                <b-col md="6" sm="12">
                  <b-form-group :label="$t('Opening_Balance_Previous_Dues')">
                    <b-form-input type="number" step="0.01" :label="$t('Opening_Balance')" v-model="provider.opening_balance" placeholder="0.00"></b-form-input>
                    <small class="px-field-help">{{ $t('Enter_the_supplier_previous_outstanding_balance_from_before_system_start') }}</small>
                  </b-form-group>
                </b-col>
                <b-col md="6" sm="12">
                  <b-form-group :label="$t('Credit_Limit')">
                    <b-form-input type="number" step="0.01" :label="$t('Credit_Limit')" v-model="provider.credit_limit" placeholder="0.00"></b-form-input>
                    <small class="px-field-help">{{ $t('Maximum_credit_amount_allowed_for_this_supplier_0_means_No_limit') }}</small>
                  </b-form-group>
                </b-col>
              </b-row>
            </div>
          </section>

          <section class="px-section px-custom-fields-section">
            <div class="px-section-header">
              <div class="px-section-heading">
                <span class="px-section-icon"><lucide-icon name="database-zap" /></span>
                <div>
                  <h2 class="px-section-title">{{ $t('CustomFields') }}</h2>
                  <p class="px-section-description">Información adicional configurada para proveedores.</p>
                </div>
              </div>
            </div>
            <div class="px-section-body">
              <CustomFieldsForm entity-type="provider" v-model="customFieldValues" />
            </div>
          </section>

          <div class="px-action-bar">
            <span class="px-action-hint">Completa la información y guarda cuando esté lista.</span>
            <div class="px-action-buttons">
              <b-button variant="outline-secondary" @click="$router.push({ name: 'Suppliers' })">{{ $t('Cancel') }}</b-button>
              <b-button variant="primary" type="submit" :disabled="SubmitProcessing">
                <lucide-icon :name="SubmitProcessing ? 'loader' : 'check'" :class="{ 'px-spin': SubmitProcessing }" />
                <span>{{ SubmitProcessing ? 'Guardando...' : $t('submit') }}</span>
              </b-button>
            </div>
          </div>
        </b-form>
      </validation-observer>
    </div>
  </div>
</template>

<script>
import CustomFieldsForm from "../../../../components/CustomFieldsForm.vue";

export default {
  components: {
    CustomFieldsForm
  },
  metaInfo: {
    title: "Create Supplier"
  },
  data() {
    return {
      SubmitProcessing: false,
      customFieldValues: {},
      provider: {
        id: "",
        name: "",
        phone: "",
        email: "",
        tax_number: "",
        country: "",
        city: "",
        adresse: "",
        opening_balance: 0,
        credit_limit: 0
      },
    };
  },

  methods: {
    //------------- Submit Validation Create Provider
    Submit_Provider() {
      this.$refs.Create_Provider.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Create_Provider();
        }
      });
    },

    //---------------------------- Create Provider  -----------------------\\
    Create_Provider() {
      this.SubmitProcessing = true;
      axios
        .post("providers", {
          name: this.provider.name,
          email: this.provider.email,
          phone: this.provider.phone,
          tax_number: this.provider.tax_number,
          country: this.provider.country,
          city: this.provider.city,
          adresse: this.provider.adresse,
          opening_balance: parseFloat(this.provider.opening_balance) || 0,
          credit_limit: parseFloat(this.provider.credit_limit) || 0
        })
        .then(response => {
          const providerId = response.data.id || response.data.provider?.id;
          
          if (!providerId) {
            this.makeToast("danger", this.$t("Failed_to_get_provider_id"), this.$t("Failed"));
            this.SubmitProcessing = false;
            return;
          }

          // Save custom field values if any (even if empty, to save default values)
          if (Object.keys(this.customFieldValues).length > 0) {
            return axios.post("custom-field-values", {
              entity_type: "App\\Models\\Provider",
              entity_id: providerId,
              values: this.customFieldValues
            }).then(() => {
              this.makeToast(
                "success",
                this.$t("Successfully_Created"),
                this.$t("Success")
              );
              this.SubmitProcessing = false;
              this.$router.push({ name: 'Suppliers' });
            }).catch(cfError => {
              console.error('Error saving custom field values:', cfError);
              // Still show success for provider creation, but log the error
              this.makeToast(
                "success",
                this.$t("Successfully_Created"),
                this.$t("Success")
              );
              this.SubmitProcessing = false;
              this.$router.push({ name: 'Suppliers' });
            });
          } else {
            this.makeToast(
              "success",
              this.$t("Successfully_Created"),
              this.$t("Success")
            );
            this.SubmitProcessing = false;
            this.$router.push({ name: 'Suppliers' });
          }
        })
        .catch(error => {
          console.error('Error creating provider:', error);
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
          this.SubmitProcessing = false;
        });
    },

    //------ Event Validation State
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },
  },

  //----------------------------- Created function-------------------
  created: function() {
    // Reset form on component creation
    this.provider = {
      id: "",
      name: "",
      phone: "",
      email: "",
      tax_number: "",
      country: "",
      city: "",
      adresse: "",
      opening_balance: 0,
      credit_limit: 0
    };
  }
};
</script>
