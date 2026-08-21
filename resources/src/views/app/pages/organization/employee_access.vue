<template>
  <div class="main-content">
    <breadcumb page="Acceso de empleados" folder="Usuarios y accesos"/>

    <b-card class="mb-3">
      <div class="d-flex flex-wrap justify-content-between align-items-start">
        <div>
          <h4 class="mb-1">Empleados y acceso a PRODEX</h4>
          <p class="text-muted mb-0">El empleado se crea en Gestión de personal. Aquí se crea o vincula su cuenta, se asigna el rol y se limita el alcance operativo por sucursal y bodega.</p>
        </div>
        <b-button variant="outline-primary" class="mt-2 mt-md-0" @click="$router.push('/app/organization/branches')">
          <lucide-icon name="building-2" class="mr-1"/> Sucursales
        </b-button>
      </div>
    </b-card>

    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-card v-else>
      <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
        <b-form-input v-model="search" placeholder="Buscar empleado, puesto o sucursal" style="max-width:380px"/>
        <div class="text-muted text-12 mt-2 mt-md-0">
          {{ withoutAccess }} sin acceso · {{ withAccess }} con acceso
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Empleado</th>
              <th>Sucursal</th>
              <th>Puesto</th>
              <th>Cuenta</th>
              <th>Estado</th>
              <th class="text-right">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="employee in filteredEmployees" :key="employee.id">
              <td>
                <strong>{{ fullName(employee) }}</strong>
                <div class="text-muted text-11">{{ employee.email || 'Sin correo laboral' }}</div>
              </td>
              <td>{{ employee.branch ? employee.branch.name : 'Sin sucursal' }}</td>
              <td>{{ employee.designation ? employee.designation.designation : 'Sin puesto' }}</td>
              <td>
                <template v-if="employee.user">
                  {{ employee.user.email }}
                  <div class="text-muted text-11">Usuario #{{ employee.user.id }}</div>
                </template>
                <span v-else class="text-warning">No vinculada</span>
              </td>
              <td>
                <span v-if="employee.user" class="badge" :class="employee.user.statut ? 'badge-success' : 'badge-secondary'">
                  {{ employee.user.statut ? 'Activo' : 'Inactivo' }}
                </span>
                <span v-else class="badge badge-light">Sin acceso</span>
              </td>
              <td class="text-right">
                <b-button v-if="!employee.user" size="sm" variant="primary" @click="openCreate(employee)">Crear acceso</b-button>
                <template v-else>
                  <b-button size="sm" variant="outline-primary" class="mr-1" @click="editLegacyUser(employee.user.id)">Administrar permisos</b-button>
                  <b-button size="sm" variant="outline-danger" @click="unlink(employee)">Desvincular</b-button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </b-card>

    <b-modal id="create-access-modal" hide-footer size="lg" title="Crear acceso a PRODEX">
      <template v-if="activeEmployee">
        <div class="alert alert-light border">
          <strong>{{ fullName(activeEmployee) }}</strong>
          <div class="text-muted text-12">
            {{ activeEmployee.designation ? activeEmployee.designation.designation : 'Sin puesto' }} ·
            {{ activeEmployee.branch ? activeEmployee.branch.name : 'Sin sucursal' }}
          </div>
        </div>

        <b-form @submit.prevent="createAccess">
          <b-row>
            <b-col md="6">
              <b-form-group label="Correo de acceso *">
                <b-form-input v-model.trim="form.email" type="email" required/>
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group label="Contraseña temporal *">
                <b-form-input v-model="form.password" type="password" minlength="8" required/>
                <small class="text-muted">Mínimo 8 caracteres. El empleado podrá cambiarla posteriormente.</small>
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group label="Rol *">
                <v-select v-model="form.role_id" :reduce="o => o.value" :options="roleOptions" placeholder="Seleccionar rol" required/>
                <small v-if="activeEmployee.designation && activeEmployee.designation.suggested_role_key" class="text-muted">
                  Puesto sugerido: {{ activeEmployee.designation.suggested_role_key }}. Es una sugerencia, no un permiso automático.
                </small>
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group label="Alcance operativo *">
                <v-select v-model="form.scope" :reduce="o => o.value" :options="scopeOptions"/>
              </b-form-group>
            </b-col>

            <b-col md="12" v-if="form.scope === 'branch'">
              <div class="alert alert-info py-2">
                PRODEX asignará únicamente las bodegas de <strong>{{ activeEmployee.branch ? activeEmployee.branch.name : 'la sucursal' }}</strong>.
              </div>
            </b-col>

            <b-col md="12" v-if="form.scope === 'selected'">
              <b-form-group label="Bodegas permitidas">
                <v-select multiple v-model="form.warehouse_ids" :reduce="o => o.value" :options="warehouseOptionsForEmployee" placeholder="Seleccionar bodegas"/>
              </b-form-group>
            </b-col>

            <b-col md="6" v-if="form.scope !== 'all'">
              <b-form-group label="Bodega predeterminada">
                <v-select v-model="form.default_warehouse_id" :reduce="o => o.value" :options="defaultWarehouseOptions" placeholder="Seleccionar"/>
              </b-form-group>
            </b-col>

            <b-col md="6">
              <b-form-group label="Visibilidad de registros">
                <b-form-checkbox v-model="form.record_view">Ver registros de otros usuarios dentro de su alcance</b-form-checkbox>
                <small class="text-muted">Esto no amplía la sucursal o bodegas asignadas.</small>
              </b-form-group>
            </b-col>
          </b-row>

          <div v-if="error" class="alert alert-danger">{{ error }}</div>
          <div class="d-flex justify-content-end">
            <b-button variant="outline-secondary" class="mr-2" @click="$bvModal.hide('create-access-modal')">Cancelar</b-button>
            <b-button variant="primary" type="submit" :disabled="saving">{{ saving ? 'Creando…' : 'Crear acceso' }}</b-button>
          </div>
        </b-form>
      </template>
    </b-modal>
  </div>
</template>

<script>
export default {
  metaInfo: { title: 'Acceso de empleados' },
  data() {
    return {
      loading: true,
      saving: false,
      error: '',
      search: '',
      employees: [],
      roles: [],
      warehouses: [],
      activeEmployee: null,
      form: this.emptyForm(),
    };
  },
  computed: {
    filteredEmployees() {
      const q = (this.search || '').trim().toLowerCase();
      if (!q) return this.employees;
      return this.employees.filter(e => [
        this.fullName(e),
        e.email,
        e.branch && e.branch.name,
        e.designation && e.designation.designation,
        e.user && e.user.email,
      ].filter(Boolean).join(' ').toLowerCase().includes(q));
    },
    withoutAccess() { return this.employees.filter(e => !e.user).length; },
    withAccess() { return this.employees.filter(e => !!e.user).length; },
    roleOptions() {
      return this.roles.map(r => ({ label: r.description ? `${r.name} — ${r.description}` : r.name, value: r.id }));
    },
    scopeOptions() {
      const options = [
        { label: 'Sucursal del empleado', value: 'branch' },
        { label: 'Bodegas seleccionadas', value: 'selected' },
      ];
      if (!this.activeEmployee || !this.activeEmployee.branch_id) options.shift();
      return options;
    },
    warehouseOptionsForEmployee() {
      if (!this.activeEmployee) return [];
      const branchId = this.activeEmployee.branch_id;
      return this.warehouses
        .filter(w => !branchId || Number(w.branch_id) === Number(branchId))
        .map(w => ({ label: w.branch ? `${w.branch.name} · ${w.name}` : w.name, value: w.id }));
    },
    defaultWarehouseOptions() {
      if (this.form.scope === 'branch' && this.activeEmployee) {
        return this.warehouses
          .filter(w => Number(w.branch_id) === Number(this.activeEmployee.branch_id))
          .map(w => ({ label: w.name, value: w.id }));
      }
      const ids = (this.form.warehouse_ids || []).map(Number);
      return this.warehouses.filter(w => ids.includes(Number(w.id))).map(w => ({ label: w.name, value: w.id }));
    },
  },
  created() { this.load(); },
  methods: {
    emptyForm() {
      return { email: '', password: '', role_id: null, scope: 'selected', warehouse_ids: [], default_warehouse_id: null, record_view: false };
    },
    async load() {
      this.loading = true;
      try {
        const { data } = await axios.get('/organization/employee-access', { meta: { skipErrorRedirect: true } });
        this.employees = data.employees || [];
        this.roles = data.roles || [];
        this.warehouses = data.warehouses || [];
      } finally {
        this.loading = false;
      }
    },
    fullName(employee) {
      return `${employee.firstname || ''} ${employee.lastname || ''}`.trim();
    },
    openCreate(employee) {
      this.activeEmployee = employee;
      this.form = this.emptyForm();
      this.form.email = employee.email || '';
      this.form.scope = employee.branch_id ? 'branch' : 'selected';
      const branchWarehouses = this.warehouses.filter(w => Number(w.branch_id) === Number(employee.branch_id));
      if (branchWarehouses.length === 1) this.form.default_warehouse_id = branchWarehouses[0].id;
      this.error = '';
      this.$bvModal.show('create-access-modal');
    },
    async createAccess() {
      if (!this.activeEmployee || !this.form.role_id) {
        this.error = 'Selecciona un rol.';
        return;
      }
      this.saving = true;
      this.error = '';
      try {
        await axios.post(`/organization/employee-access/${this.activeEmployee.id}/create`, this.form, { meta: { skipErrorRedirect: true } });
        this.$bvModal.hide('create-access-modal');
        await this.load();
        this.$root.$bvToast.toast('Cuenta vinculada al empleado correctamente.', { title: 'Éxito', variant: 'success', solid: true });
      } catch (e) {
        const data = e && e.response && e.response.data;
        if (data && data.errors) {
          const first = Object.values(data.errors)[0];
          this.error = Array.isArray(first) ? first[0] : first;
        } else this.error = (data && data.message) || 'No se pudo crear el acceso.';
      } finally {
        this.saving = false;
      }
    },
    unlink(employee) {
      this.$swal({ title: 'Desvincular cuenta', text: 'La cuenta seguirá existiendo, pero dejará de estar asociada al empleado.', type: 'warning', showCancelButton: true, confirmButtonText: 'Desvincular', cancelButtonText: 'Cancelar' }).then(async result => {
        if (!(result.value || result.isConfirmed)) return;
        await axios.delete(`/organization/employee-access/${employee.id}/link`, { meta: { skipErrorRedirect: true } });
        await this.load();
      });
    },
    editLegacyUser(id) {
      this.$router.push(`/app/User_Management/users/edit/${id}`);
    },
  },
};
</script>
