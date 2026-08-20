<template>
  <div class="main-content prodex-ui customer-create-page">
    <div class="px-page">
      <div class="px-page-header">
        <div>
          <div class="px-page-eyebrow">
            <lucide-icon name="users" />
            <span>{{ $t('Customers') }}</span>
          </div>
          <h1 class="px-page-title">{{ $t('Add') }} {{ $t('Customers') }}</h1>
          <p class="px-page-description">
            Registra la información principal, fiscal y comercial del cliente en un solo lugar.
          </p>
        </div>
        <div class="px-page-actions">
          <b-button variant="outline-secondary" @click="$router.push({ name: 'Customers' })">
            <lucide-icon name="arrow-left" />
            {{ $t('Cancel') }}
          </b-button>
        </div>
      </div>

      <validation-observer ref="Create_Customer">
        <b-form @submit.prevent="Submit_Customer">
          <section class="px-section">
            <div class="px-section-header">
              <div class="px-section-heading">
                <span class="px-section-icon"><lucide-icon name="user-round" /></span>
                <div>
                  <h2 class="px-section-title">Información principal</h2>
                  <p class="px-section-description">Datos básicos para identificar y contactar al cliente.</p>
                </div>
              </div>
            </div>
            <div class="px-section-body">
              <b-row>
                <b-col md="6" sm="12">
                  <validation-provider
                    name="Firstname"
                    :rules="{ required: true }"
                    v-slot="validationContext"
                  >
                    <b-form-group :label="$t('Firstname') + ' ' + '*'">
                      <b-form-input
                        :state="getValidationState(validationContext)"
                        aria-describedby="firstname-feedback"
                        label="Firstname"
                        :placeholder="$t('Firstname')"
                        v-model="client.firstname"
                      ></b-form-input>
                      <b-form-invalid-feedback id="firstname-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col md="6" sm="12">
                  <validation-provider
                    name="lastname"
                    :rules="{ required: true }"
                    v-slot="validationContext"
                  >
                    <b-form-group :label="$t('lastname') + ' ' + '*'">
                      <b-form-input
                        :state="getValidationState(validationContext)"
                        aria-describedby="lastname-feedback"
                        label="lastname"
                        :placeholder="$t('lastname')"
                        v-model="client.lastname"
                      ></b-form-input>
                      <b-form-invalid-feedback id="lastname-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col md="6" sm="12">
                  <validation-provider
                    name="Username"
                    :rules="{ required: true}"
                    v-slot="validationContext"
                  >
                    <b-form-group :label="'Username' + ' ' + '*'">
                      <b-form-input
                        :state="getValidationState(validationContext)"
                        aria-describedby="name-feedback"
                        label="name"
                        :placeholder="'Username'"
                        v-model="client.name"
                      ></b-form-input>
                      <b-form-invalid-feedback id="name-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col md="6" sm="12">
                  <validation-provider
                    name="Email"
                    :rules="{ required: true }"
                    v-slot="validationContext"
                  >
                    <b-form-group :label="$t('Email') + ' ' + '*'">
                      <b-form-input
                        :state="getValidationState(validationContext)"
                        aria-describedby="email-feedback"
                        label="email"
                        v-model="client.email"
                        :placeholder="$t('Email')"
                      ></b-form-input>
                      <b-form-invalid-feedback id="email-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col md="6" sm="12">
                  <b-form-group :label="$t('Phone')">
                    <b-form-input
                      label="Phone"
                      v-model="client.phone"
                      :placeholder="$t('Phone')"
                    ></b-form-input>
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
                  <p class="px-section-description">Información de dirección y datos utilizados para documentación fiscal.</p>
                </div>
              </div>
            </div>
            <div class="px-section-body">
              <b-row>
                <b-col md="6" sm="12">
                  <b-form-group :label="$t('Tax_Number')">
                    <b-form-input
                      label="Tax Number"
                      v-model="client.tax_number"
                      :placeholder="$t('Tax_Number')"
                    ></b-form-input>
                  </b-form-group>
                </b-col>

                <b-col md="6" sm="12">
                  <b-form-group :label="$t('Country')">
                    <b-form-input
                      label="Country"
                      v-model="client.country"
                      :placeholder="$t('Country')"
                    ></b-form-input>
                  </b-form-group>
                </b-col>

                <b-col md="4" sm="12">
                  <b-form-group :label="$t('State')">
                    <b-form-input
                      label="State"
                      v-model="client.state"
                      :placeholder="$t('State')"
                    ></b-form-input>
                  </b-form-group>
                </b-col>

                <b-col md="4" sm="12">
                  <b-form-group :label="$t('City')">
                    <b-form-input
                      label="City"
                      v-model="client.city"
                      :placeholder="$t('City')"
                    ></b-form-input>
                  </b-form-group>
                </b-col>

                <b-col md="4" sm="12">
                  <b-form-group :label="$t('Zip')">
                    <b-form-input
                      label="Zip"
                      v-model="client.zip"
                      :placeholder="$t('Zip')"
                    ></b-form-input>
                  </b-form-group>
                </b-col>

                <b-col md="12" sm="12">
                  <b-form-group :label="$t('Adress')">
                    <b-form-input
                      label="Adress"
                      v-model="client.adresse"
                      :placeholder="$t('Adress')"
                    ></b-form-input>
                  </b-form-group>
                </b-col>
              </b-row>
            </div>
          </section>

          <section class="px-section">
            <div class="px-section-header">
              <div class="px-section-heading">
                <span class="px-section-icon"><lucide-icon name="wallet-cards" /></span>
                <div>
                  <h2 class="px-section-title">Crédito y condiciones comerciales</h2>
                  <p class="px-section-description">Configura saldos anteriores, límites de crédito y preferencias comerciales.</p>
                </div>
              </div>
            </div>
            <div class="px-section-body">
              <b-row>
                <b-col md="6" sm="12">
                  <b-form-group :label="$t('Opening_Balance_Previous_Dues')">
                    <b-form-input
                      type="number"
                      step="0.01"
                      :label="$t('Opening_Balance')"
                      v-model="client.opening_balance"
                      placeholder="0.00"
                    ></b-form-input>
                    <small class="px-field-help">Enter the customer's previous outstanding balance from before system start</small>
                  </b-form-group>
                </b-col>

                <b-col md="6" sm="12">
                  <b-form-group :label="$t('Credit_Limit')">
                    <b-form-input
                      type="number"
                      step="0.01"
                      :label="$t('Credit_Limit')"
                      v-model="client.credit_limit"
                      placeholder="0.00"
                    ></b-form-input>
                    <small class="px-field-help">{{ $t('Maximum_credit_amount_allowed_for_this_customer_0_means_No_limit') }}</small>
                  </b-form-group>
                </b-col>

                <b-col md="12" sm="12">
                  <label class="px-choice-card" for="is_royalty_eligible">
                    <span class="px-choice-control">
                      <input
                        type="checkbox"
                        v-model="client.is_royalty_eligible"
                        class="psx-checkbox psx-form-check-input"
                        id="is_royalty_eligible"
                      >
                    </span>
                    <span class="px-choice-content">
                      <strong>{{ $t('Is_Royalty_Eligible') }}</strong>
                      <small>Permite incluir este cliente en las funciones de fidelización disponibles.</small>
                    </span>
                  </label>
                </b-col>
              </b-row>
            </div>
          </section>

          <section class="px-section">
            <div class="px-section-header">
              <div class="px-section-heading">
                <span class="px-section-icon"><lucide-icon name="database-zap" /></span>
                <div>
                  <h2 class="px-section-title">Información adicional</h2>
                  <p class="px-section-description">Campos personalizados definidos para los clientes de tu empresa.</p>
                </div>
              </div>
            </div>
            <div class="px-section-body px-custom-fields-host">
              <CustomFieldsForm
                entity-type="client"
                v-model="customFieldValues"
              />
            </div>
          </section>

          <div class="px-form-footer">
            <div class="px-form-footer-copy">
              <strong>Nuevo cliente</strong>
              <span>Los datos se guardarán cuando confirmes esta acción.</span>
            </div>
            <div class="px-form-footer-actions">
              <b-button variant="outline-secondary" @click="$router.push({ name: 'Customers' })">
                {{ $t('Cancel') }}
              </b-button>
              <b-button variant="primary" type="submit" :disabled="SubmitProcessing">
                <span v-if="SubmitProcessing" class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                <lucide-icon v-else name="check" />
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
    title: "Create Customer"
  },
  data() {
    return {
      SubmitProcessing: false,
      customFieldValues: {},
      client: {
        id: "",
        firstname: "",
        lastname: "",
        name: "",
        email: "",
        phone: "",
        country: "",
        tax_number: "",
        city: "",
        state: "",
        zip: "",
        adresse: "",
        is_royalty_eligible: "",
        opening_balance: 0,
        credit_limit: 0,
      },
    };
  },

  methods: {
    //------------- Submit Validation Create Customer
    Submit_Customer() {
      // Prefer using firstname/lastname to build name when empty
      const fullName = [this.client.firstname, this.client.lastname]
        .map(v => (v || "").trim())
        .filter(Boolean)
        .join(" ")
        .trim();
      if (!this.client.name && fullName) {
        this.client.name = fullName;
      }

      this.$refs.Create_Customer.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Create_Client();
        }
      });
    },

    //---------------------------------------- Create new Client -------------------------------\\
    Create_Client() {
      this.SubmitProcessing = true;
      axios
        .post("clients", {
          firstname: this.client.firstname,
          lastname: this.client.lastname,
          name: this.client.name,
          email: this.client.email,
          phone: this.client.phone,
          tax_number: this.client.tax_number,
          country: this.client.country,
          city: this.client.city,
          state: this.client.state,
          zip: this.client.zip,
          adresse: this.client.adresse,
          is_royalty_eligible: this.client.is_royalty_eligible,
          opening_balance: parseFloat(this.client.opening_balance) || 0,
          credit_limit: parseFloat(this.client.credit_limit) || 0
        })
        .then(response => {
          const clientId = response.data.id || response.data.client?.id;
          
          // Save custom field values if any
          if (clientId && Object.keys(this.customFieldValues).length > 0) {
            return axios.post("custom-field-values", {
              entity_type: "App\\Models\\Client",
              entity_id: clientId,
              values: this.customFieldValues
            }).then(() => {
              this.makeToast(
                "success",
                this.$t("Successfully_Created"),
                this.$t("Success")
              );
              this.SubmitProcessing = false;
              this.$router.push({ name: 'Customers' });
            });
          } else {
            this.makeToast(
              "success",
              this.$t("Successfully_Created"),
              this.$t("Success")
            );
            this.SubmitProcessing = false;
            this.$router.push({ name: 'Customers' });
          }
        })
        .catch(error => {
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
    this.client = {
      id: "",
      firstname: "",
      lastname: "",
      name: "",
      email: "",
      phone: "",
      country: "",
      tax_number: "",
      city: "",
      state: "",
      zip: "",
      adresse: "",
      is_royalty_eligible: "",
      opening_balance: 0,
      credit_limit: 0,
    };
  }
};
</script>
