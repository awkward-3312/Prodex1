<template>
  <div class="main-content">
    <breadcumb :page="$t('Edit')" :folder="$t('Employees')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <validation-observer ref="Edit_Employee" v-if="!isLoading">
      <b-form @submit.prevent="Submit_Employee" enctype="multipart/form-data">
        <b-row>
          <b-col lg="8" md="12">
            <b-card>
              <b-row>
                <b-col lg="6" md="6" sm="12" class="mb-2">
                  <validation-provider name="Nombre" :rules="{required:true}" v-slot="validationContext">
                    <b-form-group label="Primer nombre *">
                      <b-form-input :state="getValidationState(validationContext)" aria-describedby="FirstName-feedback" placeholder="Primer nombre" v-model="employee.firstname" />
                      <b-form-invalid-feedback id="FirstName-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col lg="6" md="6" sm="12" class="mb-2">
                  <validation-provider name="Apellido" :rules="{required:true}" v-slot="validationContext">
                    <b-form-group label="Apellido *">
                      <b-form-input :state="getValidationState(validationContext)" aria-describedby="LastName-feedback" placeholder="Apellido" v-model="employee.lastname" />
                      <b-form-invalid-feedback id="LastName-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col lg="6" md="6" sm="12" class="mb-2">
                  <validation-provider name="Género" :rules="{ required: true}">
                    <b-form-group slot-scope="{ errors }" label="Género *">
                      <v-select
                        v-model="employee.gender"
                        :reduce="label => label.value"
                        placeholder="Selecciona el género"
                        :options="[{label: 'Masculino', value: 'male'}, {label: 'Femenino', value: 'female'}]"
                      />
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col lg="6" md="6" sm="12" class="mb-2">
                  <b-form-group label="Fecha de nacimiento">
                    <Datepicker id="birth_date" name="birth_date" placeholder="Selecciona la fecha" v-model="employee.birth_date" input-class="form-control back_important" format="yyyy-MM-dd" @closed="employee.birth_date=formatDate(employee.birth_date)" />
                  </b-form-group>
                </b-col>

                <b-col lg="6" md="6" sm="12" class="mb-2">
                  <b-form-group label="Correo electrónico">
                    <b-form-input placeholder="Correo electrónico" v-model="employee.email" />
                  </b-form-group>
                </b-col>

                <b-col lg="6" md="6" sm="12" class="mb-2">
                  <b-form-group label="País">
                    <b-form-input placeholder="País" v-model="employee.country" />
                  </b-form-group>
                </b-col>

                <b-col lg="6" md="6" sm="12" class="mb-2">
                  <b-form-group label="Teléfono">
                    <b-form-input placeholder="Teléfono" v-model="employee.phone" />
                  </b-form-group>
                </b-col>

                <b-col lg="6" md="6" sm="12" class="mb-2">
                  <b-form-group label="Fecha de ingreso">
                    <Datepicker id="joining_date" name="joining_date" placeholder="Selecciona la fecha" v-model="employee.joining_date" input-class="form-control back_important" format="yyyy-MM-dd" @closed="employee.joining_date=formatDate(employee.joining_date)" />
                  </b-form-group>
                </b-col>

                <b-col lg="6" md="6" sm="12" class="mb-2">
                  <b-form-group label="Fecha de salida">
                    <Datepicker id="leaving_date" name="leaving_date" placeholder="Opcional" v-model="employee.leaving_date" input-class="form-control back_important" format="yyyy-MM-dd" @closed="employee.leaving_date=formatDate(employee.leaving_date)" />
                  </b-form-group>
                </b-col>

                <b-col lg="6" md="6" sm="12" class="mb-2">
                  <validation-provider name="Vacaciones anuales" :rules="{required:true}" v-slot="validationContext">
                    <b-form-group label="Vacaciones anuales *">
                      <b-form-input :state="getValidationState(validationContext)" aria-describedby="total_leave-feedback" placeholder="Días" v-model="employee.total_leave" />
                      <b-form-invalid-feedback id="total_leave-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col lg="6" md="6" sm="12" class="mb-2">
                  <b-form-group label="Vacaciones restantes">
                    <b-form-input disabled v-model="employee.remaining_leave" />
                  </b-form-group>
                </b-col>

                <b-col md="6" class="mb-2">
                  <validation-provider name="Compañía" :rules="{ required: true}">
                    <b-form-group slot-scope="{ errors }" label="Compañía *">
                      <v-select v-model="employee.company_id" class="required" required @input="Selected_Company" placeholder="Selecciona una compañía" :reduce="label => label.value" :options="companies.map(company => ({label: company.name, value: company.id}))" />
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col md="6" class="mb-2">
                  <b-form-group label="Sucursal">
                    <v-select
                      v-model="employee.branch_id"
                      :reduce="label => label.value"
                      placeholder="Selecciona una sucursal"
                      :options="branches.map(branch => ({label: branch.code ? `${branch.name} (${branch.code})` : branch.name, value: branch.id}))"
                    />
                    <small class="text-muted">Define la ubicación organizacional habitual del empleado. El acceso al sistema se controla por separado.</small>
                  </b-form-group>
                </b-col>

                <b-col md="6" class="mb-2">
                  <validation-provider name="Departamento" :rules="{ required: true}">
                    <b-form-group slot-scope="{ errors }" label="Departamento *">
                      <v-select v-model="employee.department_id" class="required" required @input="Selected_Department" placeholder="Selecciona un departamento" :reduce="label => label.value" :options="departments.map(item => ({label: item.department, value: item.id}))" />
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col md="6" class="mb-2">
                  <validation-provider name="Puesto" :rules="{ required: true}">
                    <b-form-group slot-scope="{ errors }" label="Puesto *">
                      <v-select v-model="employee.designation_id" class="required" required @input="Selected_Designation" placeholder="Selecciona un puesto" :reduce="label => label.value" :options="designations.map(item => ({label: item.designation, value: item.id}))" />
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col md="6" class="mb-2">
                  <validation-provider name="Turno de oficina" :rules="{ required: true}">
                    <b-form-group slot-scope="{ errors }" label="Turno de oficina *">
                      <v-select v-model="employee.office_shift_id" class="required" required @input="Selected_Office_shift" placeholder="Selecciona un turno" :reduce="label => label.value" :options="office_shifts.map(item => ({label: item.name, value: item.id}))" />
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>
              </b-row>
            </b-card>
          </b-col>

          <b-col lg="4" md="12">
            <AttendanceIdentifiersCard v-if="employee.id" :employee-id="employee.id" />
          </b-col>

          <b-col md="12" class="mt-3">
            <b-button variant="primary" type="submit" :disabled="SubmitProcessing">
              <lucide-icon class="mr-1" name="check" /> {{ SubmitProcessing ? 'Guardando...' : 'Guardar cambios' }}
            </b-button>
          </b-col>
        </b-row>
      </b-form>
    </validation-observer>
  </div>
</template>

<script>
import NProgress from "nprogress";
import Datepicker from 'vuejs-datepicker';
import AttendanceIdentifiersCard from './AttendanceIdentifiersCard.vue';

export default {
  metaInfo: { title: "Editar empleado" },
  components: { Datepicker, AttendanceIdentifiersCard },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      companies: [],
      branches: [],
      departments: [],
      designations: [],
      office_shifts: [],
      employee: {}
    };
  },
  methods: {
    Submit_Employee() {
      this.$refs.Edit_Employee.validate().then(success => {
        if (!success) return this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        this.Edit_Employee();
      });
    },
    makeToast(variant, msg, title) { this.$root.$bvToast.toast(msg, { title, variant, solid: true }); },
    getValidationState({ dirty, validated, valid = null }) { return dirty || validated ? valid : null; },
    formatDate(d) {
      if (!d || typeof d.getMonth !== 'function') return d;
      const m = d.getMonth() + 1;
      const day = d.getDate();
      return [d.getFullYear(), m < 10 ? '0' + m : m, day < 10 ? '0' + day : day].join('-');
    },
    GetElements() {
      const id = this.$route.params.id;
      axios.get(`employees/${id}/edit`)
        .then(response => {
          this.employee = response.data.employee;
          this.companies = response.data.companies;
          this.branches = response.data.branches || [];
          this.departments = response.data.departments;
          this.designations = response.data.designations;
          this.office_shifts = response.data.office_shifts;
          this.isLoading = false;
        })
        .catch(() => {
          setTimeout(() => { this.isLoading = false; }, 500);
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },
    Selected_Company(value) {
      if (value === null) this.employee.company_id = "";
      this.departments = [];
      this.designations = [];
      this.office_shifts = [];
      this.employee.department_id = "";
      this.employee.designation_id = "";
      this.employee.office_shift_id = "";
      if (value) {
        this.Get_departments_by_company(value);
        this.Get_office_shift_by_company(value);
      }
    },
    Selected_Department(value) {
      if (value === null) this.employee.department_id = "";
      this.designations = [];
      this.employee.designation_id = "";
      if (value) this.Get_designations_by_department(value);
    },
    Selected_Designation(value) { if (value === null) this.employee.designation_id = ""; },
    Selected_Office_shift(value) { if (value === null) this.employee.office_shift_id = ""; },
    Get_departments_by_company(value) { axios.get("/core/get_departments_by_company?id=" + value).then(({ data }) => (this.departments = data)); },
    Get_designations_by_department(value) { axios.get("/core/get_designations_by_department?id=" + value).then(({ data }) => (this.designations = data)); },
    Get_office_shift_by_company(value) { axios.get("/core/get_office_shift_by_company?id=" + value).then(({ data }) => (this.office_shifts = data)); },
    Edit_Employee() {
      NProgress.start();
      NProgress.set(0.1);
      this.SubmitProcessing = true;
      axios.put("employees/" + this.employee.id, {
        firstname: this.employee.firstname,
        lastname: this.employee.lastname,
        country: this.employee.country,
        email: this.employee.email,
        gender: this.employee.gender,
        phone: this.employee.phone,
        birth_date: this.employee.birth_date,
        company_id: this.employee.company_id,
        branch_id: this.employee.branch_id || null,
        department_id: this.employee.department_id,
        designation_id: this.employee.designation_id,
        office_shift_id: this.employee.office_shift_id,
        joining_date: this.employee.joining_date,
        leaving_date: this.employee.leaving_date,
        total_leave: this.employee.total_leave,
        remaining_leave: this.employee.remaining_leave,
        marital_status: this.employee.marital_status,
        employment_type: this.employee.employment_type,
        city: this.employee.city,
        province: this.employee.province,
        zipcode: this.employee.zipcode,
        address: this.employee.address,
        basic_salary: this.employee.basic_salary,
        hourly_rate: this.employee.hourly_rate,
        role_users_id: this.employee.role_users_id
      })
        .then(() => {
          NProgress.done();
          this.SubmitProcessing = false;
          this.$router.push({ name: "employees_list" });
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          NProgress.done();
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    }
  },
  created() { this.GetElements(); }
};
</script>
