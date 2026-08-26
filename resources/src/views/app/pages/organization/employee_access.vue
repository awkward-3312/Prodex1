<template>
  <div class="main-content">
    <breadcumb page="Acceso de empleados" folder="Usuarios y accesos"/>

    <b-card class="mb-3">
      <div class="d-flex flex-wrap justify-content-between align-items-start">
        <div>
          <h4 class="mb-1">Empleados y acceso a PRODEX</h4>
          <p class="text-muted mb-0">El rol define qué puede hacer el usuario. La sucursal, ubicación y caja física definen dónde opera cuando utiliza POS.</p>
        </div>
        <div class="mt-2 mt-md-0">
          <b-button variant="outline-secondary" class="mr-2" @click="openManual">
            <lucide-icon name="book-open" class="mr-1"/> Ver manual
          </b-button>
          <b-button variant="outline-primary" @click="$router.push('/app/organization/branches')">
            <lucide-icon name="building-2" class="mr-1"/> Sucursales y cajas
          </b-button>
        </div>
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
              <th>Sucursal laboral</th>
              <th>Puesto</th>
              <th>Cuenta</th>
              <th>Contexto operativo</th>
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
                <template v-if="employee.user">
                  <div>{{ branchName(employee.user.default_branch_id) }}</div>
                  <div class="text-muted text-11">{{ locationName(employee.user.default_inventory_location_id) }}</div>
                  <div v-if="employee.user.default_cash_drawer" class="text-info text-11">
                    <lucide-icon name="wallet-cards" class="mr-1"/>{{ employee.user.default_cash_drawer.name }}
                  </div>
                </template>
                <span v-else>—</span>
              </td>
              <td>
                <span v-if="employee.user" class="badge" :class="employee.user.statut ? 'badge-success' : 'badge-secondary'">
                  {{ employee.user.statut ? 'Activo' : 'Inactivo' }}
                </span>
                <span v-else class="badge badge-light">Sin acceso</span>
              </td>
              <td class="text-right text-nowrap">
                <b-button v-if="!employee.user" size="sm" variant="primary" @click="openCreate(employee)">Crear acceso</b-button>
                <template v-else>
                  <b-button size="sm" variant="outline-primary" class="mr-1" @click="editLegacyUser(employee.user.id)">Administrar acceso</b-button>
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
            {{ activeEmployee.branch ? activeEmployee.branch.name : 'Sin sucursal laboral' }}
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
                <v-select v-model="form.role_id" :reduce="o => o.value" :options="roleOptions" placeholder="Seleccionar rol" required @input="onRoleChanged"/>
                <small v-if="activeEmployee.designation && activeEmployee.designation.suggested_role_key" class="text-muted">
                  El puesto sugiere {{ activeEmployee.designation.suggested_role_key }}, pero los permisos reales dependen del rol seleccionado.
                </small>
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group label="Alcance organizacional *">
                <v-select v-model="form.scope" :reduce="o => o.value" :options="scopeOptions" @input="onScopeChanged"/>
              </b-form-group>
            </b-col>

            <b-col md="12" v-if="form.scope === 'branch'">
              <div class="alert alert-info py-2">
                El acceso quedará limitado a <strong>{{ activeEmployee.branch ? activeEmployee.branch.name : 'la sucursal del empleado' }}</strong>. No se asignará un almacén/CD como ubicación laboral.
              </div>
            </b-col>

            <b-col md="12" v-if="form.scope === 'selected'">
              <b-form-group label="Sucursales permitidas *">
                <v-select multiple v-model="form.branch_ids" :reduce="o => o.value" :options="branchOptions" placeholder="Seleccionar una o varias sucursales" @input="onBranchesChanged"/>
                <small class="text-muted">Un supervisor regional puede tener varias sucursales sin crear otra cuenta.</small>
              </b-form-group>
            </b-col>

            <b-col md="12" v-if="form.scope !== 'all' && selectedBranchIds.length">
              <b-form-group label="Ubicaciones de inventario permitidas">
                <v-select multiple v-model="form.inventory_location_ids" :reduce="o => o.value" :options="allowedLocationOptions" placeholder="Seleccionar Piso de venta, Bodega, Cuarentena, etc."/>
                <small class="text-muted">Una cajera normalmente usa solo Piso de venta; otros puestos pueden tener varias ubicaciones según su función.</small>
              </b-form-group>
            </b-col>

            <b-col md="6" v-if="form.scope !== 'all'">
              <b-form-group label="Sucursal predeterminada *">
                <v-select v-model="form.default_branch_id" :reduce="o => o.value" :options="defaultBranchOptions" placeholder="Seleccionar sucursal" @input="onDefaultBranchChanged"/>
              </b-form-group>
            </b-col>

            <b-col md="6" v-if="form.scope !== 'all'">
              <b-form-group label="Ubicación predeterminada de inventario">
                <v-select v-model="form.default_inventory_location_id" :reduce="o => o.value" :options="defaultLocationOptions" placeholder="Seleccionar ubicación" @input="onDefaultLocationChanged"/>
                <small class="text-muted">Para cajeros debe ser normalmente el Piso de venta de su sucursal.</small>
              </b-form-group>
            </b-col>

            <b-col md="12" v-if="selectedRole && selectedRole.uses_pos">
              <div class="border rounded p-3 mb-3">
                <b-form-group :label="selectedRole.requires_cash_drawer ? 'Caja física predeterminada *' : 'Caja física predeterminada'" class="mb-1">
                  <v-select
                    v-model="form.default_cash_drawer_id"
                    :reduce="o => o.value"
                    :options="defaultCashDrawerOptions"
                    :placeholder="defaultCashDrawerOptions.length ? 'Seleccionar caja física' : 'No hay cajas disponibles en esta ubicación'"
                  />
                </b-form-group>
                <small class="text-muted">
                  La caja pertenece a la empresa y a la ubicación; esta asignación solo define la caja habitual del empleado. Puede reasignarse posteriormente sin perder historial.
                </small>
                <b-alert v-if="selectedRole.requires_cash_drawer && !defaultCashDrawerOptions.length" show variant="warning" class="mt-2 mb-0 py-2">
                  Este rol necesita una caja física para operar POS. Crea una en la sucursal y ubicación seleccionadas antes de guardar el acceso.
                  <b-button size="sm" variant="outline-warning" class="ml-2" @click="$router.push('/app/organization/branches')">Administrar cajas</b-button>
                </b-alert>
              </div>
            </b-col>

            <b-col md="12">
              <b-form-group label="Visibilidad de registros">
                <b-form-checkbox v-model="form.record_view">Ver registros de otros usuarios dentro de su propio alcance</b-form-checkbox>
                <small class="text-muted">Esta opción no amplía sucursales ni ubicaciones asignadas.</small>
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
      branches: [],
      inventoryLocations: [],
      cashDrawers: [],
      activeEmployee: null,
      form: this.emptyForm(),
    };
  },
  computed: {
    filteredEmployees() {
      const q = (this.search || '').trim().toLowerCase();
      if (!q) return this.employees;
      return this.employees.filter(e => [
        this.fullName(e), e.email, e.branch && e.branch.name,
        e.designation && e.designation.designation, e.user && e.user.email,
        e.user && e.user.default_cash_drawer && e.user.default_cash_drawer.name,
      ].filter(Boolean).join(' ').toLowerCase().includes(q));
    },
    withoutAccess() { return this.employees.filter(e => !e.user).length; },
    withAccess() { return this.employees.filter(e => !!e.user).length; },
    roleOptions() {
      return this.roles.map(r => ({ label: r.description ? `${r.name} — ${r.description}` : r.name, value: r.id }));
    },
    selectedRole() {
      return this.roles.find(r => Number(r.id) === Number(this.form.role_id)) || null;
    },
    scopeOptions() {
      const options = [
        { label: 'Sucursal del empleado', value: 'branch' },
        { label: 'Sucursales seleccionadas', value: 'selected' },
      ];
      if (!this.activeEmployee || !this.activeEmployee.branch_id) options.shift();
      return options;
    },
    branchOptions() {
      return this.branches.map(b => ({ label: b.code ? `${b.name} · ${b.code}` : b.name, value: b.id }));
    },
    selectedBranchIds() {
      if (this.form.scope === 'branch' && this.activeEmployee && this.activeEmployee.branch_id) return [Number(this.activeEmployee.branch_id)];
      return (this.form.branch_ids || []).map(Number).filter(Boolean);
    },
    defaultBranchOptions() {
      const ids = this.selectedBranchIds;
      return this.branches.filter(b => ids.includes(Number(b.id))).map(b => ({ label: b.name, value: b.id }));
    },
    allowedLocationOptions() {
      const branchIds = this.selectedBranchIds;
      return this.inventoryLocations
        .filter(location => branchIds.includes(Number(location.branch_id)))
        .map(location => ({ label: `${this.branchName(location.branch_id)} · ${location.name}${location.is_default_sales ? ' · Predeterminada' : ''}`, value: location.id }));
    },
    defaultLocationOptions() {
      const branchId = Number(this.form.default_branch_id || 0);
      return this.inventoryLocations
        .filter(location => Number(location.branch_id) === branchId)
        .map(location => ({ label: `${location.name}${location.is_default_sales ? ' · Piso predeterminado' : ''}`, value: location.id }));
    },
    defaultCashDrawerOptions() {
      const branchId = Number(this.form.default_branch_id || 0);
      const locationId = Number(this.form.default_inventory_location_id || 0);
      if (!branchId || !locationId) return [];
      return this.cashDrawers
        .filter(drawer => Number(drawer.branch_id) === branchId && Number(drawer.inventory_location_id) === locationId && !!drawer.is_active)
        .map(drawer => ({ label: drawer.code ? `${drawer.name} · ${drawer.code}` : drawer.name, value: drawer.id }));
    },
  },
  created() { this.load(); },
  methods: {
    emptyForm() {
      return {
        email: '', password: '', role_id: null, scope: 'selected',
        branch_ids: [], inventory_location_ids: [],
        default_branch_id: null, default_inventory_location_id: null,
        default_cash_drawer_id: null,
        record_view: false,
      };
    },
    async load() {
      this.loading = true;
      try {
        const { data } = await axios.get('/organization/employee-access', { meta: { skipErrorRedirect: true } });
        this.employees = data.employees || [];
        this.roles = data.roles || [];
        this.branches = data.branches || [];
        this.inventoryLocations = data.inventory_locations || [];
        this.cashDrawers = data.cash_drawers || [];
      } finally {
        this.loading = false;
      }
    },
    fullName(employee) { return `${employee.firstname || ''} ${employee.lastname || ''}`.trim(); },
    branchName(id) {
      const branch = this.branches.find(b => Number(b.id) === Number(id));
      return branch ? branch.name : 'Sucursal';
    },
    locationName(id) {
      if (!id) return 'Sin ubicación predeterminada';
      const location = this.inventoryLocations.find(l => Number(l.id) === Number(id));
      return location ? location.name : 'Ubicación no disponible';
    },
    defaultLocationId(branchId) {
      const branch = this.branches.find(b => Number(b.id) === Number(branchId));
      if (branch && branch.default_inventory_location_id) return Number(branch.default_inventory_location_id);
      const floor = this.inventoryLocations.find(l => Number(l.branch_id) === Number(branchId) && l.is_default_sales);
      return floor ? Number(floor.id) : null;
    },
    openCreate(employee) {
      this.activeEmployee = employee;
      this.form = this.emptyForm();
      this.form.email = employee.email || '';
      this.form.scope = employee.branch_id ? 'branch' : 'selected';
      if (employee.branch_id) {
        const branchId = Number(employee.branch_id);
        this.form.branch_ids = [branchId];
        this.form.default_branch_id = branchId;
        const locationId = this.defaultLocationId(branchId);
        this.form.default_inventory_location_id = locationId;
        this.form.inventory_location_ids = locationId ? [locationId] : [];
      }
      this.error = '';
      this.$bvModal.show('create-access-modal');
    },
    onRoleChanged() {
      if (!this.selectedRole || !this.selectedRole.uses_pos) {
        this.form.default_cash_drawer_id = null;
        return;
      }
      this.selectSingleDrawerWhenUnambiguous();
    },
    onScopeChanged() {
      if (this.form.scope === 'branch' && this.activeEmployee && this.activeEmployee.branch_id) {
        const branchId = Number(this.activeEmployee.branch_id);
        this.form.branch_ids = [branchId];
        this.form.default_branch_id = branchId;
        const locationId = this.defaultLocationId(branchId);
        this.form.default_inventory_location_id = locationId;
        this.form.inventory_location_ids = locationId ? [locationId] : [];
      } else {
        this.form.branch_ids = [];
        this.form.inventory_location_ids = [];
        this.form.default_branch_id = null;
        this.form.default_inventory_location_id = null;
      }
      this.form.default_cash_drawer_id = null;
    },
    onBranchesChanged() {
      const branchIds = this.selectedBranchIds;
      this.form.inventory_location_ids = (this.form.inventory_location_ids || []).filter(id => {
        const location = this.inventoryLocations.find(l => Number(l.id) === Number(id));
        return location && branchIds.includes(Number(location.branch_id));
      });
      if (!branchIds.includes(Number(this.form.default_branch_id))) {
        this.form.default_branch_id = branchIds.length ? branchIds[0] : null;
        this.onDefaultBranchChanged();
      }
    },
    onDefaultBranchChanged() {
      const branchId = Number(this.form.default_branch_id || 0);
      this.form.default_cash_drawer_id = null;
      if (!branchId) {
        this.form.default_inventory_location_id = null;
        return;
      }
      const current = this.inventoryLocations.find(l => Number(l.id) === Number(this.form.default_inventory_location_id));
      if (!current || Number(current.branch_id) !== branchId) {
        const defaultId = this.defaultLocationId(branchId);
        this.form.default_inventory_location_id = defaultId;
        if (defaultId && !(this.form.inventory_location_ids || []).map(Number).includes(defaultId)) {
          this.form.inventory_location_ids.push(defaultId);
        }
      }
      this.selectSingleDrawerWhenUnambiguous();
    },
    onDefaultLocationChanged() {
      const locationId = Number(this.form.default_inventory_location_id || 0);
      this.form.default_cash_drawer_id = null;
      if (locationId && !(this.form.inventory_location_ids || []).map(Number).includes(locationId)) {
        this.form.inventory_location_ids.push(locationId);
      }
      this.selectSingleDrawerWhenUnambiguous();
    },
    selectSingleDrawerWhenUnambiguous() {
      if (this.selectedRole && this.selectedRole.uses_pos && this.defaultCashDrawerOptions.length === 1) {
        this.form.default_cash_drawer_id = this.defaultCashDrawerOptions[0].value;
      }
    },
    errorMessage(error, fallback) {
      const data = (error && error.response && error.response.data) || (error && typeof error === 'object' ? error : null);
      if (data && data.errors) {
        const first = Object.values(data.errors)[0];
        return Array.isArray(first) ? first[0] : first;
      }
      return (data && (data.message || data.error)) || fallback;
    },
    async createAccess() {
      if (!this.activeEmployee || !this.form.role_id) {
        this.error = 'Selecciona un rol.';
        return;
      }
      if (this.form.scope !== 'all' && !this.selectedBranchIds.length) {
        this.error = 'Selecciona al menos una sucursal.';
        return;
      }
      if (this.selectedRole && this.selectedRole.requires_cash_drawer && !this.form.default_cash_drawer_id) {
        this.error = 'Este rol necesita una caja física predeterminada para operar POS.';
        return;
      }
      this.saving = true;
      this.error = '';
      try {
        const payload = Object.assign({}, this.form, {
          branch_ids: this.selectedBranchIds,
        });
        await axios.post(`/organization/employee-access/${this.activeEmployee.id}/create`, payload, { meta: { skipErrorRedirect: true } });
        this.$bvModal.hide('create-access-modal');
        await this.load();
        this.$root.$bvToast.toast('Cuenta vinculada al empleado correctamente.', { title: 'Éxito', variant: 'success', solid: true });
      } catch (e) {
        this.error = this.errorMessage(e, 'No se pudo crear el acceso.');
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
    editLegacyUser(id) { this.$router.push(`/app/User_Management/users/edit/${id}`); },
    openManual() { this.$router.push({ name: 'KnowledgeBaseList' }).catch(() => {}); },
  },
};
</script>
