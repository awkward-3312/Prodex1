<template>
  <div class="main-content">
    <breadcumb page="Sucursales" folder="Organización"/>

    <b-card class="mb-3">
      <div class="d-flex flex-wrap align-items-center justify-content-between">
        <div>
          <h4 class="mb-1">Sucursales y centros operativos</h4>
          <p class="text-muted mb-0">Organiza empleados y bodegas por ubicación. Los permisos definen qué puede hacer cada usuario; la sucursal y sus bodegas definen dónde puede hacerlo.</p>
        </div>
        <div class="mt-2 mt-md-0">
          <b-button variant="outline-primary" class="mr-2" @click="goWarehouses">
            <lucide-icon name="warehouse" class="mr-1"/> Bodegas
          </b-button>
          <b-button variant="primary" @click="openCreate">
            <lucide-icon name="plus" class="mr-1"/> Nueva sucursal
          </b-button>
        </div>
      </div>
    </b-card>

    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-card v-else>
      <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
        <b-form-input v-model="search" placeholder="Buscar por nombre, código o ciudad" style="max-width:360px" @input="loadBranches" />
        <small class="text-muted mt-2 mt-md-0">{{ branches.length }} sucursal(es)</small>
      </div>

      <div v-if="!branches.length" class="text-center py-5 text-muted">
        No hay sucursales configuradas todavía.
      </div>

      <div v-else class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Sucursal</th>
              <th>Tipo</th>
              <th>Ciudad</th>
              <th>Responsable</th>
              <th>Bodegas</th>
              <th>Bodega predeterminada</th>
              <th class="text-right">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="branch in branches" :key="branch.id">
              <td>
                <strong>{{ branch.name }}</strong>
                <div class="text-muted text-11" v-if="branch.code">{{ branch.code }}</div>
              </td>
              <td>{{ typeLabel(branch.type) }}</td>
              <td>{{ branch.city || '—' }}</td>
              <td>{{ managerName(branch.manager) }}</td>
              <td>
                <span v-if="branch.warehouses && branch.warehouses.length">{{ branch.warehouses.map(w => w.name).join(', ') }}</span>
                <span v-else class="text-warning">Sin bodegas</span>
              </td>
              <td>{{ branch.default_warehouse ? branch.default_warehouse.name : '—' }}</td>
              <td class="text-right">
                <a class="cursor-pointer mr-2" title="Editar" @click="openEdit(branch)">
                  <lucide-icon name="pencil" class="text-success text-20"/>
                </a>
                <a class="cursor-pointer" title="Desactivar" @click="removeBranch(branch)">
                  <lucide-icon name="archive" class="text-danger text-20"/>
                </a>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </b-card>

    <b-modal id="branch-modal" hide-footer size="lg" :title="editing ? 'Editar sucursal' : 'Nueva sucursal'">
      <b-form @submit.prevent="saveBranch">
        <b-row>
          <b-col md="8">
            <b-form-group label="Nombre *">
              <b-form-input v-model.trim="form.name" required maxlength="192" placeholder="Ej. Sucursal Mall Multiplaza"/>
            </b-form-group>
          </b-col>
          <b-col md="4">
            <b-form-group label="Código">
              <b-form-input v-model.trim="form.code" maxlength="40" placeholder="Ej. SPS-MALL"/>
            </b-form-group>
          </b-col>
          <b-col md="6">
            <b-form-group label="Tipo *">
              <v-select v-model="form.type" :reduce="o => o.value" :options="types"/>
            </b-form-group>
          </b-col>
          <b-col md="6">
            <b-form-group label="Responsable">
              <v-select v-model="form.manager_employee_id" :reduce="o => o.value" :options="employeeOptions" placeholder="Seleccionar empleado"/>
            </b-form-group>
          </b-col>
          <b-col md="6">
            <b-form-group label="Ciudad">
              <b-form-input v-model.trim="form.city" maxlength="120"/>
            </b-form-group>
          </b-col>
          <b-col md="6">
            <b-form-group label="País">
              <b-form-input v-model.trim="form.country" maxlength="120"/>
            </b-form-group>
          </b-col>
          <b-col md="12">
            <b-form-group label="Dirección">
              <b-form-input v-model.trim="form.address" maxlength="255"/>
            </b-form-group>
          </b-col>
          <b-col md="6">
            <b-form-group label="Teléfono">
              <b-form-input v-model.trim="form.phone" maxlength="80"/>
            </b-form-group>
          </b-col>
          <b-col md="6">
            <b-form-group label="Correo">
              <b-form-input v-model.trim="form.email" type="email" maxlength="192"/>
            </b-form-group>
          </b-col>
          <b-col md="12">
            <b-form-group label="Bodegas de esta sucursal">
              <v-select v-model="form.warehouse_ids" multiple :reduce="o => o.value" :options="warehouseOptions" placeholder="Selecciona una o varias bodegas"/>
              <small class="text-muted">Una bodega solo debe pertenecer a una sucursal. Al reasignarla, PRODEX actualizará su pertenencia operativa.</small>
            </b-form-group>
          </b-col>
          <b-col md="12">
            <b-form-group label="Bodega predeterminada">
              <v-select v-model="form.default_warehouse_id" :reduce="o => o.value" :options="selectedWarehouseOptions" placeholder="Selecciona la bodega principal de la sucursal"/>
            </b-form-group>
          </b-col>
        </b-row>

        <div v-if="error" class="alert alert-danger">{{ error }}</div>
        <div class="d-flex justify-content-end">
          <b-button variant="outline-secondary" class="mr-2" @click="$bvModal.hide('branch-modal')">Cancelar</b-button>
          <b-button variant="primary" type="submit" :disabled="saving">{{ saving ? 'Guardando…' : 'Guardar sucursal' }}</b-button>
        </div>
      </b-form>
    </b-modal>
  </div>
</template>

<script>
export default {
  metaInfo: { title: 'Sucursales' },
  data() {
    return {
      loading: true,
      saving: false,
      editing: false,
      error: '',
      search: '',
      branches: [],
      warehouses: [],
      employees: [],
      types: [
        { label: 'Sucursal', value: 'branch' },
        { label: 'Centro de distribución', value: 'distribution_center' },
        { label: 'Oficina', value: 'office' },
        { label: 'Otro', value: 'other' },
      ],
      form: this.emptyForm(),
    };
  },
  computed: {
    warehouseOptions() {
      return this.warehouses.map(w => ({ label: w.branch_id && (!this.form.id || Number(w.branch_id) !== Number(this.form.id)) ? `${w.name} · asignada a otra sucursal` : w.name, value: w.id }));
    },
    selectedWarehouseOptions() {
      const ids = (this.form.warehouse_ids || []).map(Number);
      return this.warehouses.filter(w => ids.includes(Number(w.id))).map(w => ({ label: w.name, value: w.id }));
    },
    employeeOptions() {
      return this.employees.map(e => ({ label: `${e.firstname} ${e.lastname}`.trim(), value: e.id }));
    },
  },
  created() {
    Promise.all([this.loadOptions(), this.loadBranches()]).finally(() => { this.loading = false; });
  },
  methods: {
    emptyForm() {
      return { id: null, name: '', code: '', type: 'branch', phone: '', email: '', country: 'Honduras', city: '', address: '', manager_employee_id: null, default_warehouse_id: null, warehouse_ids: [], is_active: true };
    },
    apiConfig() {
      return { meta: { skipErrorRedirect: true } };
    },
    async loadOptions() {
      const { data } = await axios.get('/organization/branches/options', this.apiConfig());
      this.warehouses = data.warehouses || [];
      this.employees = data.employees || [];
    },
    async loadBranches() {
      try {
        const { data } = await axios.get('/organization/branches', { params: { search: this.search || '' }, meta: { skipErrorRedirect: true } });
        this.branches = data.branches || [];
      } catch (e) {
        this.branches = [];
      }
    },
    openCreate() {
      this.form = this.emptyForm();
      this.editing = false;
      this.error = '';
      this.$bvModal.show('branch-modal');
    },
    openEdit(branch) {
      this.editing = true;
      this.error = '';
      this.form = {
        id: branch.id,
        name: branch.name || '',
        code: branch.code || '',
        type: branch.type || 'branch',
        phone: branch.phone || '',
        email: branch.email || '',
        country: branch.country || '',
        city: branch.city || '',
        address: branch.address || '',
        manager_employee_id: branch.manager_employee_id || null,
        default_warehouse_id: branch.default_warehouse_id || null,
        warehouse_ids: (branch.warehouses || []).map(w => w.id),
        is_active: !!branch.is_active,
      };
      this.$bvModal.show('branch-modal');
    },
    async saveBranch() {
      if (!this.form.name) return;
      this.saving = true;
      this.error = '';
      try {
        if (this.editing) await axios.put(`/organization/branches/${this.form.id}`, this.form, this.apiConfig());
        else await axios.post('/organization/branches', this.form, this.apiConfig());
        this.$bvModal.hide('branch-modal');
        await Promise.all([this.loadOptions(), this.loadBranches()]);
        this.$root.$bvToast.toast('Sucursal guardada correctamente.', { title: 'Éxito', variant: 'success', solid: true });
      } catch (e) {
        const data = e && e.response && e.response.data;
        this.error = (data && (data.message || (data.errors && Object.values(data.errors)[0][0]))) || 'No se pudo guardar la sucursal.';
      } finally {
        this.saving = false;
      }
    },
    removeBranch(branch) {
      this.$swal({ title: 'Desactivar sucursal', text: `Se desactivará ${branch.name}. No se eliminará su historial.`, type: 'warning', showCancelButton: true, confirmButtonText: 'Desactivar', cancelButtonText: 'Cancelar' }).then(async result => {
        if (!(result.value || result.isConfirmed)) return;
        try {
          await axios.delete(`/organization/branches/${branch.id}`, this.apiConfig());
          await Promise.all([this.loadOptions(), this.loadBranches()]);
        } catch (e) {}
      });
    },
    goWarehouses() {
      window.location.href = '/app/settings/warehouses';
    },
    managerName(manager) {
      if (!manager) return '—';
      return `${manager.firstname || ''} ${manager.lastname || ''}`.trim() || '—';
    },
    typeLabel(type) {
      const found = this.types.find(t => t.value === type);
      return found ? found.label : type;
    },
  },
};
</script>
