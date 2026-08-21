<template>
  <div class="main-content">
    <breadcumb :page="$t('Add_Employee')" :folder="$t('Employees')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <validation-observer ref="Create_Employee" v-if="!isLoading">
      <b-form @submit.prevent="Submit_Employee">
        <b-row>
          <b-col lg="9" md="12">
            <b-card>
              <div class="mb-4">
                <h5 class="mb-1">Información del empleado</h5>
                <p class="text-muted mb-0">El empleado pertenece a RRHH. Su cuenta de acceso y permisos se administran después desde Usuarios y accesos.</p>
              </div>

              <b-row>
                <b-col md="6" class="mb-2">
                  <validation-provider name="FirstName" :rules="{required:true}" v-slot="validationContext">
                    <b-form-group :label="$t('FirstName') + ' *'">
                      <b-form-input :state="getValidationState(validationContext)" v-model="employee.firstname" :placeholder="$t('Enter_FirstName')"/>
                      <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col md="6" class="mb-2">
                  <validation-provider name="LastName" :rules="{required:true}" v-slot="validationContext">
                    <b-form-group :label="$t('LastName') + ' *'">
                      <b-form-input :state="getValidationState(validationContext)" v-model="employee.lastname" :placeholder="$t('Enter_LastName')"/>
                      <b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col md="6" class="mb-2">
                  <validation-provider name="Gender" :rules="{required:true}">
                    <b-form-group slot-scope="{ valid, errors }" :label="$t('Gender') + ' *'">
                      <v-select :class="{'is-invalid': !!errors.length}" :state="errors[0] ? false : (valid ? true : null)" v-model="employee.gender" :reduce="o => o.value" :options="genderOptions" :placeholder="$t('Choose_Gender')"/>
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col md="6" class="mb-2">
                  <b-form-group :label="$t('Birth_date')">
                    <Datepicker v-model="employee.birth_date" input-class="form-control back_important" format="yyyy-MM-dd" :placeholder="$t('Enter_Birth_date')" @closed="formatFieldDate('birth_date')"/>
                  </b-form-group>
                </b-col>

                <b-col md="6" class="mb-2">
                  <b-form-group :label="$t('Email_Address')">
                    <b-form-input v-model="employee.email" type="email" :placeholder="$t('Enter_email_address')"/>
                  </b-form-group>
                </b-col>

                <b-col md="6" class="mb-2">
                  <b-form-group :label="$t('Phone')">
                    <b-form-input v-model="employee.phone" :placeholder="$t('Enter_Phone_Number')"/>
                  </b-form-group>
                </b-col>

                <b-col md="6" class="mb-2">
                  <b-form-group :label="$t('Country')">
                    <b-form-input v-model="employee.country" :placeholder="$t('Enter_Country')"/>
                  </b-form-group>
                </b-col>

                <b-col md="6" class="mb-2">
                  <b-form-group :label="$t('joining_date')">
                    <Datepicker v-model="employee.joining_date" input-class="form-control back_important" format="yyyy-MM-dd" :placeholder="$t('Enter_joining_date')" @closed="formatFieldDate('joining_date')"/>
                  </b-form-group>
                </b-col>
              </b-row>

              <hr class="my-4">
              <div class="mb-3">
                <h5 class="mb-1">Ubicación y puesto</h5>
                <p class="text-muted mb-0">Sucursal = dónde trabaja. Puesto = qué función cumple. Los permisos de sistema se asignan posteriormente.</p>
              </div>

              <b-row>
                <b-col md="6" class="mb-2">
                  <validation-provider name="Company" :rules="{required:true}">
                    <b-form-group slot-scope="{ valid, errors }" :label="$t('Company') + ' *'">
                      <v-select :class="{'is-invalid': !!errors.length}" :state="errors[0] ? false : (valid ? true : null)" v-model="employee.company_id" @input="Selected_Company" :reduce="o => o.value" :options="companyOptions" :placeholder="$t('Choose_Company')"/>
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col md="6" class="mb-2">
                  <b-form-group label="Sucursal">
                    <v-select v-model="employee.branch_id" :reduce="o => o.value" :options="branchOptions" placeholder="Seleccionar sucursal"/>
                    <small class="text-muted">Si aún no existe, créala desde Organización → Sucursales.</small>
                  </b-form-group>
                </b-col>

                <b-col md="6" class="mb-2">
                  <validation-provider name="Department" :rules="{required:true}">
                    <b-form-group slot-scope="{ valid, errors }" :label="$t('Department') + ' *'">
                      <v-select :class="{'is-invalid': !!errors.length}" :state="errors[0] ? false : (valid ? true : null)" v-model="employee.department_id" @input="Selected_Department" :reduce="o => o.value" :options="departmentOptions" :placeholder="$t('Department')"/>
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col md="6" class="mb-2">
                  <validation-provider name="Designation" :rules="{required:true}">
                    <b-form-group slot-scope="{ valid, errors }" label="Puesto laboral *">
                      <v-select :class="{'is-invalid': !!errors.length}" :state="errors[0] ? false : (valid ? true : null)" v-model="employee.designation_id" :reduce="o => o.value" :options="designationOptions" placeholder="Seleccionar puesto"/>
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                      <small class="text-muted">Los puestos predeterminados y personalizados se administran en Gestión de personal → Puestos.</small>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col md="6" class="mb-2">
                  <validation-provider name="Office_Shift" :rules="{required:true}">
                    <b-form-group slot-scope="{ valid, errors }" :label="$t('Office_Shift') + ' *'">
                      <v-select :class="{'is-invalid': !!errors.length}" :state="errors[0] ? false : (valid ? true : null)" v-model="employee.office_shift_id" :reduce="o => o.value" :options="shiftOptions" :placeholder="$t('Choose_Office_Shift')"/>
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>
              </b-row>
            </b-card>

            <div class="mt-3 d-flex flex-wrap">
              <b-button variant="primary" type="submit" :disabled="SubmitProcessing"><lucide-icon class="mr-1" name="check"/> {{ SubmitProcessing ? 'Guardando…' : $t('submit') }}</b-button>
              <b-button variant="outline-secondary" class="ml-2" @click="$router.push({name:'employees_list'})">Cancelar</b-button>
            </div>
          </b-col>
        </b-row>
      </b-form>
    </validation-observer>
  </div>
</template>

<script>
import NProgress from 'nprogress';
import Datepicker from 'vuejs-datepicker';

export default {
  metaInfo: { title: 'Create Employee' },
  components: { Datepicker },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      companies: [],
      branches: [],
      departments: [],
      designations: [],
      office_shifts: [],
      genderOptions: [
        {label: 'Masculino', value: 'male'},
        {label: 'Femenino', value: 'female'},
      ],
      employee: {
        firstname: '', lastname: '', country: 'Honduras', email: '', gender: '', phone: '',
        birth_date: '', company_id: '', branch_id: null, department_id: '', designation_id: '',
        office_shift_id: '', joining_date: '',
      },
    };
  },
  computed: {
    companyOptions() { return this.companies.map(x => ({label:x.name, value:x.id})); },
    branchOptions() { return this.branches.map(x => ({label:x.code ? `${x.name} (${x.code})` : x.name, value:x.id})); },
    departmentOptions() { return this.departments.map(x => ({label:x.department, value:x.id})); },
    designationOptions() { return this.designations.map(x => ({label:x.designation, value:x.id})); },
    shiftOptions() { return this.office_shifts.map(x => ({label:x.name, value:x.id})); },
  },
  created() { this.GetElements(); },
  methods: {
    getValidationState({dirty, validated, valid = null}) { return dirty || validated ? valid : null; },
    makeToast(variant, msg, title) { this.$root.$bvToast.toast(msg, {title, variant, solid:true}); },
    formatFieldDate(field) {
      const d = this.employee[field];
      if (!d || typeof d === 'string') return;
      const m = String(d.getMonth()+1).padStart(2,'0');
      const day = String(d.getDate()).padStart(2,'0');
      this.employee[field] = `${d.getFullYear()}-${m}-${day}`;
    },
    async GetElements() {
      try {
        const response = await axios.get('employees/create');
        this.companies = response.data.companies || [];
        this.branches = response.data.branches || [];
      } catch (e) {
        this.makeToast('danger', this.$t('InvalidData'), this.$t('Failed'));
      } finally {
        this.isLoading = false;
      }
    },
    Selected_Company(value) {
      this.departments = [];
      this.designations = [];
      this.office_shifts = [];
      this.employee.department_id = '';
      this.employee.designation_id = '';
      this.employee.office_shift_id = '';
      if (!value) return;
      this.Get_departments_by_company(value);
      this.Get_office_shift_by_company(value);
    },
    Selected_Department(value) {
      this.designations = [];
      this.employee.designation_id = '';
      if (value) this.Get_designations_by_department(value);
    },
    Get_departments_by_company(value) {
      axios.get('/core/get_departments_by_company?id='+value).then(({data}) => { this.departments = data || []; });
    },
    Get_designations_by_department(value) {
      axios.get('/core/get_designations_by_department?id='+value).then(({data}) => { this.designations = data || []; });
    },
    Get_office_shift_by_company(value) {
      axios.get('/core/get_office_shift_by_company?id='+value).then(({data}) => { this.office_shifts = data || []; });
    },
    Submit_Employee() {
      this.$refs.Create_Employee.validate().then(success => {
        if (!success) return this.makeToast('danger', this.$t('Please_fill_the_form_correctly'), this.$t('Failed'));
        this.Create_Employee();
      });
    },
    async Create_Employee() {
      NProgress.start();
      this.SubmitProcessing = true;
      try {
        const response = await axios.post('/employees', this.employee);
        this.makeToast('success', this.$t('Successfully_Created'), this.$t('Success'));
        const id = response.data && response.data.employee_id;
        if (id) {
          this.$swal({
            title: 'Empleado creado',
            text: '¿Este empleado necesita acceso a PRODEX? Puedes crear su cuenta ahora desde Usuarios y accesos.',
            type: 'success',
            showCancelButton: true,
            confirmButtonText: 'Configurar acceso',
            cancelButtonText: 'Después',
          }).then(result => {
            if (result.value || result.isConfirmed) this.$router.push({name:'organization_employee_access'});
            else this.$router.push({name:'employees_list'});
          });
        } else this.$router.push({name:'employees_list'});
      } catch (e) {
        const data = e && e.response && e.response.data;
        const msg = data && data.message ? data.message : this.$t('InvalidData');
        this.makeToast('danger', msg, this.$t('Failed'));
      } finally {
        NProgress.done();
        this.SubmitProcessing = false;
      }
    },
  },
};
</script>
