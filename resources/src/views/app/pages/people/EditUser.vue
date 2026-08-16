<template>
  <div class="main-content">
    <breadcumb :page="$t('Edit')" :folder="$t('Users')"/>
    
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    
    <validation-observer ref="Create_User" v-if="!isLoading">
      <b-card>
        <b-form @submit.prevent="Submit_User" enctype="multipart/form-data">
          <b-row>
            <!-- First name -->
            <b-col md="6" sm="12">
              <validation-provider
                name="Firstname"
                :rules="{ required: true , min:3 , max:30}"
                v-slot="validationContext"
              >
                <b-form-group :label="$t('Firstname') + ' ' + '*'">
                  <b-form-input
                    :state="getValidationState(validationContext)"
                    aria-describedby="Firstname-feedback"
                    label="Firstname"
                    v-model="user.firstname"
                    :placeholder="$t('Firstname')"
                  ></b-form-input>
                  <b-form-invalid-feedback id="Firstname-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Last name -->
            <b-col md="6" sm="12">
              <validation-provider
                name="lastname"
                :rules="{ required: true , min:3 , max:30}"
                v-slot="validationContext"
              >
                <b-form-group :label="$t('lastname') + ' ' + '*'">
                  <b-form-input
                    :state="getValidationState(validationContext)"
                    aria-describedby="lastname-feedback"
                    label="lastname"
                    v-model="user.lastname"
                    :placeholder="$t('lastname')"
                  ></b-form-input>
                  <b-form-invalid-feedback id="lastname-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Username -->
            <b-col md="6" sm="12">
              <validation-provider
                name="username"
                :rules="{ required: true , min:3 , max:30}"
                v-slot="validationContext"
              >
                <b-form-group :label="$t('username') + ' ' + '*'">
                  <b-form-input
                    :state="getValidationState(validationContext)"
                    aria-describedby="username-feedback"
                    label="username"
                    v-model="user.username"
                    :placeholder="$t('username')"
                  ></b-form-input>
                  <b-form-invalid-feedback id="username-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Phone -->
            <b-col md="6" sm="12">
              <validation-provider
                name="Phone"
                :rules="{ required: true}"
                v-slot="validationContext"
              >
                <b-form-group :label="$t('Phone') + ' ' + '*'">
                  <b-form-input
                    :state="getValidationState(validationContext)"
                    aria-describedby="Phone-feedback"
                    label="Phone"
                    v-model="user.phone"
                    :placeholder="$t('Phone')"
                  ></b-form-input>
                  <b-form-invalid-feedback id="Phone-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Email -->
            <b-col md="6" sm="12">
              <validation-provider
                name="Email"
                :rules="{ required: true}"
                v-slot="validationContext"
              >
                <b-form-group :label="$t('Email') + ' ' + '*'">
                  <b-form-input
                    :state="getValidationState(validationContext)"
                    aria-describedby="Email-feedback"
                    label="Email"
                    v-model="user.email"
                    :placeholder="$t('Email')"
                  ></b-form-input>
                  <b-form-invalid-feedback id="Email-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                  <b-alert
                    show
                    variant="danger"
                    class="error mt-1"
                    v-if="email_exist !=''"
                  >{{email_exist}}</b-alert>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- role -->
            <b-col md="6" sm="12" class="mb-3">
              <validation-provider name="role" :rules="{ required: true}">
                <b-form-group slot-scope="{ valid, errors }" :label="$t('RoleName') + ' ' + '*'">
                  <v-select
                    :class="{'is-invalid': !!errors.length}"
                    :state="errors[0] ? false : (valid ? true : null)"
                    v-model="user.role_id"
                    :reduce="label => label.value"
                    :placeholder="$t('PleaseSelect')"
                    :options="roles.map(roles => ({label: roles.name, value: roles.id}))"
                  />
                  <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Avatar -->
            <b-col md="6" sm="12" class="mb-3">
              <validation-provider name="Avatar" ref="Avatar" rules="mimes:image/*|size:200">
                <b-form-group slot-scope="{validate, valid, errors }" :label="$t('UserImage')">
                  <input
                    :state="errors[0] ? false : (valid ? true : null)"
                    :class="{'is-invalid': !!errors.length}"
                    @change="onFileSelected"
                    label="Choose Avatar"
                    type="file"
                  >
                  <b-form-invalid-feedback id="Avatar-feedback">{{ errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- New Password -->
            <b-col md="6" class="mb-3">
              <validation-provider
                name="New password"
                :rules="{min:6}"
                v-slot="validationContext"
              >
                <b-form-group :label="$t('Newpassword')">
                  <b-form-input
                    :state="getValidationState(validationContext)"
                    aria-describedby="Nawpassword-feedback"
                    :placeholder="$t('LeaveBlank')"
                    label="New password"
                    type="password"
                    v-model="user.NewPassword"
                  ></b-form-input>
                  <b-form-invalid-feedback
                    id="Nawpassword-feedback"
                  >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Record View -->
            <b-col md="6" sm="12" class="mb-3">
              <b-card class="h-100">
                <b-card-header class="pb-2">
                  <h6 class="mb-0">{{$t('ShowAll')}}</h6>
                </b-card-header>
                <b-card-body class="pt-3">
                  <div class="psx-form-check">
                    <input type="checkbox" v-model="user.record_view" class="psx-checkbox psx-form-check-input" id="record_view">
                    <label class="psx-form-check-label" for="record_view">
                      <span class="font-weight-normal">{{$t('ShowAll')}} <lucide-icon class="text-info font-weight-bold" name="help-circle" v-b-tooltip.hover.bottom title="Allow user to view all records, not just their own" /></span>
                    </label>
                  </div>
                  <small class="text-muted d-block mt-2">Allow user to view all records, not just their own</small>
                </b-card-body>
              </b-card>
            </b-col>

            <!-- Access Warehouses -->
            <b-col md="6" sm="12" class="mb-3">
              <b-card class="h-100">
                <b-card-header class="pb-2">
                  <h6 class="mb-0">{{$t('Assigned_warehouses')}}</h6>
                </b-card-header>
                <b-card-body class="pt-3">
                  <div class="psx-form-check mb-3">
                    <input type="checkbox" v-model="user.is_all_warehouses" class="psx-checkbox psx-form-check-input" id="is_all_warehouses">
                    <label class="psx-form-check-label" for="is_all_warehouses">
                      <span class="font-weight-normal">{{$t('All_Warehouses')}} <lucide-icon class="text-info font-weight-bold" name="help-circle" v-b-tooltip.hover.bottom title="If 'All Warehouses' Selected , User Can access all data for the selected Warehouses" /></span>
                    </label>
                  </div>
                  
                  <b-form-group :label="$t('Some_warehouses')" class="mb-0" v-if="!user.is_all_warehouses">
                    <v-select
                      multiple
                      v-model="assigned_warehouses"
                      @input="Selected_Warehouse"
                      :reduce="label => label.value"
                      :placeholder="$t('PleaseSelect')"
                      :options="warehouses.map(warehouses => ({label: warehouses.name, value: warehouses.id}))"
                    />
                  </b-form-group>
                </b-card-body>
              </b-card>
            </b-col>

            <b-col md="6" sm="12" class="mb-3">
              <b-card class="h-100">
                <b-card-header class="pb-2">
                  <h6 class="mb-0">{{ $t('Operational_Assignment') || 'Asignación operativa' }}</h6>
                </b-card-header>
                <b-card-body class="pt-3">
                  <b-form-group :label="$t('Default_Warehouse') || 'Almacén habitual'">
                    <v-select
                      v-model="user.default_warehouse_id"
                      :reduce="label => label.value"
                      :placeholder="$t('PleaseSelect')"
                      :options="operationalWarehouseOptions()"
                      @input="onDefaultWarehouseChange"
                    />
                  </b-form-group>
                  <b-form-group :label="$t('Default_Cash_Drawer') || 'Caja física habitual'" class="mb-0">
                    <v-select
                      v-model="user.default_cash_drawer_id"
                      :reduce="label => label.value"
                      :placeholder="$t('PleaseSelect')"
                      :options="cashDrawerOptions(user.default_warehouse_id)"
                    />
                  </b-form-group>
                </b-card-body>
              </b-card>
            </b-col>

            <b-col md="6" sm="12" class="mb-3">
              <b-card class="h-100">
                <b-card-header class="pb-2 d-flex align-items-center justify-content-between">
                  <h6 class="mb-0">{{ $t('Temporary_Assignment') || 'Asignación temporal' }}</h6>
                  <b-badge v-if="activeTemporaryAssignment" variant="warning">{{ $t('Active') || 'Activa' }}</b-badge>
                </b-card-header>
                <b-card-body class="pt-3">
                  <div v-if="activeTemporaryAssignment" class="mb-3">
                    <div class="text-muted small">{{ $t('Current_Assignment') || 'Asignación actual' }}</div>
                    <div>{{ activeTemporaryAssignment.temporary_warehouse_name || '-' }} / {{ activeTemporaryAssignment.temporary_cash_drawer_name || '-' }}</div>
                    <b-button size="sm" variant="outline-danger" class="mt-2" @click="End_Temporary_Assignment">
                      {{ $t('End') || 'Finalizar' }}
                    </b-button>
                  </div>
                  <b-form-group :label="$t('Warehouse') || 'Almacén'">
                    <v-select
                      v-model="temporaryAssignment.temporary_warehouse_id"
                      :reduce="label => label.value"
                      :placeholder="$t('PleaseSelect')"
                      :options="operationalWarehouseOptions()"
                      @input="onTemporaryWarehouseChange"
                    />
                  </b-form-group>
                  <b-form-group :label="$t('Cash_Drawer') || 'Caja física'">
                    <v-select
                      v-model="temporaryAssignment.temporary_cash_drawer_id"
                      :reduce="label => label.value"
                      :placeholder="$t('PleaseSelect')"
                      :options="cashDrawerOptions(temporaryAssignment.temporary_warehouse_id)"
                    />
                  </b-form-group>
                  <b-form-group :label="$t('Ends_At') || 'Termina en'">
                    <b-form-input type="datetime-local" v-model="temporaryAssignment.ends_at"></b-form-input>
                  </b-form-group>
                  <b-form-group :label="$t('Reason') || 'Motivo'">
                    <b-form-textarea rows="2" v-model="temporaryAssignment.reason"></b-form-textarea>
                  </b-form-group>
                  <b-button size="sm" variant="primary" :disabled="temporaryBusy" @click="Save_Temporary_Assignment">
                    {{ $t('Assign') || 'Asignar temporalmente' }}
                  </b-button>
                </b-card-body>
              </b-card>
            </b-col>

            <b-col md="12" class="mt-3">
                <b-button variant="primary" type="submit" :disabled="SubmitProcessing"><lucide-icon class="me-2 font-weight-bold" name="check" /> {{$t('submit')}}</b-button>
                <b-button variant="secondary" class="ml-2" @click="$router.push({ name: 'Users' })">{{$t('Cancel')}}</b-button>
                  <div v-once class="typo__p" v-if="SubmitProcessing">
                    <div class="spinner sm spinner-primary mt-3"></div>
                  </div>
            </b-col>

          </b-row>
        </b-form>
      </b-card>
    </validation-observer>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: {
    title: "Edit User"
  },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      email_exist: "",
      roles: [],
      warehouses: [],
      cash_drawers: [],
      activeTemporaryAssignment: null,
      temporaryBusy: false,
      temporaryAssignment: {
        temporary_warehouse_id: "",
        temporary_cash_drawer_id: "",
        ends_at: "",
        reason: "",
      },
      data: new FormData(),
      user: {
        id: "",
        firstname: "",
        lastname: "",
        username: "",
        NewPassword: null,
        email: "",
        phone: "",
        statut: "",
        role_id: "",
        avatar: "",
        is_all_warehouses: 1,
        record_view: false,
        default_warehouse_id: "",
        default_cash_drawer_id: "",
      },
      assigned_warehouses: [],
    };
  },

  methods: {
    //------------- Submit Validation Edit User
    Submit_User() {
      this.$refs.Create_User.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Update_User();
        }
      });
    },

    //----------------------- Update User ---------------------------\\
    Update_User() {
      var self = this;
      self.SubmitProcessing = true;
      self.data = new FormData();
      self.data.append("firstname", self.user.firstname);
      self.data.append("lastname", self.user.lastname);
      self.data.append("username", self.user.username);
      self.data.append("email", self.user.email);
      self.data.append("NewPassword", self.user.NewPassword);
      self.data.append("phone", self.user.phone);
      self.data.append("role", self.user.role_id);
      self.data.append("statut", self.user.statut);
      self.data.append("is_all_warehouses", self.user.is_all_warehouses);
      self.data.append("record_view", self.user.record_view ? 1 : 0);
      self.data.append("default_warehouse_id", self.user.default_warehouse_id || "");
      self.data.append("default_cash_drawer_id", self.user.default_cash_drawer_id || "");
      self.data.append("avatar", self.user.avatar);

      // append array assigned_warehouses
      if (self.assigned_warehouses.length) {
        for (var i = 0; i < self.assigned_warehouses.length; i++) {
          self.data.append("assigned_to[" + i + "]", self.assigned_warehouses[i]);
        }
      } else {
        self.data.append("assigned_to", []);
      }
      self.data.append("_method", "put");

      axios
        .post("users/" + this.user.id, self.data)
        .then(response => {
          this.makeToast(
            "success",
            this.$t("Successfully_Updated"),
            this.$t("Success")
          );
          self.SubmitProcessing = false;
          // Redirect to users list after successful update
          this.$router.push({ name: 'Users' });
        })
        .catch(error => {
          if (error.response && error.response.data && error.response.data.errors && error.response.data.errors.email) {
            self.email_exist = error.response.data.errors.email[0];
          }
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
          self.SubmitProcessing = false;
        });
    },

    //----------------------------------- Get User Data ---------------------------\\
    Get_User() {
      NProgress.start();
      NProgress.set(0.1);
      let id = this.$route.params.id;
      axios
        .get("users/" + id + "/edit")
        .then(response => {
          this.user = response.data.user;
          this.roles = response.data.roles;
          this.warehouses = response.data.warehouses;
          this.cash_drawers = response.data.cash_drawers || [];
          this.assigned_warehouses = response.data.assigned_warehouses || [];
          this.user.NewPassword = null;
          this.Get_Operational_Assignment();
          NProgress.done();
          this.isLoading = false;
        })
        .catch(error => {
          NProgress.done();
          this.makeToast("danger", this.$t("Failed_to_load_user"), this.$t("Failed"));
          setTimeout(() => {
            this.isLoading = false;
            this.$router.push({ name: 'Users' });
          }, 500);
        });
    },

    Selected_Warehouse(value) {
      if (!value.length) {
        this.assigned_warehouses = [];
      }
      if (!this.user.is_all_warehouses && this.user.default_warehouse_id && !this.assigned_warehouses.includes(this.user.default_warehouse_id)) {
        this.user.default_warehouse_id = "";
        this.user.default_cash_drawer_id = "";
      }
    },

    operationalWarehouseOptions() {
      const ids = this.user.is_all_warehouses ? null : this.assigned_warehouses.map(id => String(id));
      return (this.warehouses || [])
        .filter(warehouse => !ids || ids.includes(String(warehouse.id)))
        .map(warehouse => ({ label: warehouse.name, value: warehouse.id }));
    },

    cashDrawerOptions(warehouseId) {
      return (this.cash_drawers || [])
        .filter(drawer => !warehouseId || String(drawer.warehouse_id) === String(warehouseId))
        .map(drawer => ({
          label: drawer.code ? `${drawer.name} (${drawer.code})` : drawer.name,
          value: drawer.id,
        }));
    },

    onDefaultWarehouseChange() {
      const drawers = this.cashDrawerOptions(this.user.default_warehouse_id);
      if (!drawers.some(drawer => String(drawer.value) === String(this.user.default_cash_drawer_id))) {
        this.user.default_cash_drawer_id = drawers.length === 1 ? drawers[0].value : "";
      }
    },

    onTemporaryWarehouseChange() {
      const drawers = this.cashDrawerOptions(this.temporaryAssignment.temporary_warehouse_id);
      if (!drawers.some(drawer => String(drawer.value) === String(this.temporaryAssignment.temporary_cash_drawer_id))) {
        this.temporaryAssignment.temporary_cash_drawer_id = drawers.length === 1 ? drawers[0].value : "";
      }
    },

    Get_Operational_Assignment() {
      if (!this.user.id) return;
      axios.get(`users/${this.user.id}/operational-assignment`)
        .then(response => {
          this.activeTemporaryAssignment = response.data.active_temporary_assignment || null;
        })
        .catch(() => {});
    },

    Save_Temporary_Assignment() {
      if (!this.temporaryAssignment.temporary_warehouse_id || !this.temporaryAssignment.temporary_cash_drawer_id) {
        this.makeToast("warning", this.$t("PleaseSelect"), this.$t("Warning"));
        return;
      }
      this.temporaryBusy = true;
      axios.post(`users/${this.user.id}/temporary-assignment`, this.temporaryAssignment)
        .then(() => {
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
          this.temporaryAssignment = { temporary_warehouse_id: "", temporary_cash_drawer_id: "", ends_at: "", reason: "" };
          this.Get_Operational_Assignment();
        })
        .catch(() => {
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        })
        .finally(() => {
          this.temporaryBusy = false;
        });
    },

    End_Temporary_Assignment() {
      if (!this.activeTemporaryAssignment) return;
      this.temporaryBusy = true;
      axios.post(`user-operational-assignments/${this.activeTemporaryAssignment.id}/end`)
        .then(() => {
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
          this.Get_Operational_Assignment();
        })
        .catch(() => {
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        })
        .finally(() => {
          this.temporaryBusy = false;
        });
    },

    //------------------------------ Event Upload Avatar -------------------------------\\
    async onFileSelected(e) {
      const { valid } = await this.$refs.Avatar.validate(e);

      if (valid) {
        this.user.avatar = e.target.files[0];
      } else {
        this.user.avatar = "";
      }
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
    this.Get_User();
  }
};
</script>
