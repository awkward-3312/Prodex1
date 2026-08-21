<template>
  <div class="main-content">
    <breadcumb page="Sucursales" folder="Organización"/>

    <b-card class="mb-3">
      <div class="d-flex flex-wrap align-items-center justify-content-between">
        <div>
          <h4 class="mb-1">Sucursales</h4>
          <p class="text-muted mb-0">Una sucursal es donde opera el negocio. Su inventario vive en ubicaciones como Piso de venta, Bodega de sucursal o Cuarentena; no consume un almacén/CD adicional.</p>
        </div>
        <div class="mt-2 mt-md-0">
          <b-button variant="outline-secondary" class="mr-2" @click="goManual">
            <lucide-icon name="book-open" class="mr-1"/> Ver manual
          </b-button>
          <b-button variant="outline-primary" class="mr-2" @click="goWarehouses">
            <lucide-icon name="warehouse" class="mr-1"/> Almacenes / CD
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
        <b-form-input v-model="search" placeholder="Buscar por nombre, código o ciudad" style="max-width:360px" @input="loadBranches"/>
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
              <th>Ubicaciones de inventario</th>
              <th>Venta predeterminada</th>
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
                <template v-if="branch.inventory_locations && branch.inventory_locations.length">
                  <b-badge v-for="location in branch.inventory_locations" :key="location.id" variant="light" class="mr-1 mb-1">
                    {{ location.name }}
                  </b-badge>
                </template>
                <span v-else class="text-muted">Sin inventario configurado</span>
              </td>
              <td>{{ branch.default_inventory_location ? branch.default_inventory_location.name : '—' }}</td>
              <td class="text-right text-nowrap">
                <a class="cursor-pointer mr-2" title="Agregar ubicación de inventario" @click="openLocation(branch)">
                  <lucide-icon name="map-pin-plus" class="text-primary text-20"/>
                </a>
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
            <div class="border rounded p-3 mb-3">
              <b-form-checkbox v-model="form.inventory_enabled">
                Esta sucursal maneja inventario
              </b-form-checkbox>
              <p class="text-muted text-12 mb-2 mt-1">Al activarlo, PRODEX crea un Piso de venta predeterminado. Esto no crea ni consume otro almacén/CD del plan.</p>
              <b-form-checkbox v-if="form.inventory_enabled" v-model="form.create_storage_location">
                Crear también “Bodega de sucursal”
              </b-form-checkbox>
            </div>
          </b-col>
        </b-row>

        <div v-if="error" class="alert alert-danger">{{ error }}</div>
        <div class="d-flex justify-content-end">
          <b-button variant="outline-secondary" class="mr-2" @click="$bvModal.hide('branch-modal')">Cancelar</b-button>
          <b-button variant="primary" type="submit" :disabled="saving">{{ saving ? 'Guardando…' : 'Guardar sucursal' }}</b-button>
        </div>
      </b-form>
    </b-modal>

    <b-modal id="location-modal" hide-footer title="Nueva ubicación de inventario">
      <div v-if="locationBranch" class="alert alert-light border mb-3">
        <strong>{{ locationBranch.name }}</strong><br>
        <small class="text-muted">La ubicación pertenecerá a esta sucursal.</small>
      </div>
      <b-form @submit.prevent="saveLocation">
        <b-form-group label="Nombre *">
          <b-form-input v-model.trim="locationForm.name" required maxlength="192" placeholder="Ej. Cuarentena"/>
        </b-form-group>
        <b-form-group label="Código *">
          <b-form-input v-model.trim="locationForm.code" required maxlength="64" placeholder="Ej. CUARENTENA"/>
        </b-form-group>
        <b-form-group label="Tipo *">
          <v-select v-model="locationForm.type" :reduce="o => o.value" :options="locationTypes"/>
        </b-form-group>
        <b-form-checkbox v-model="locationForm.is_sellable" class="mb-2" :disabled="locationForm.type === 'quarantine'">
          Inventario disponible para venta
        </b-form-checkbox>
        <b-form-checkbox v-model="locationForm.is_default_sales" class="mb-2" :disabled="locationForm.type === 'quarantine'">
          Usar como ubicación predeterminada de venta
        </b-form-checkbox>
        <div v-if="locationError" class="alert alert-danger mt-3">{{ locationError }}</div>
        <div class="d-flex justify-content-end mt-3">
          <b-button variant="outline-secondary" class="mr-2" @click="$bvModal.hide('location-modal')">Cancelar</b-button>
          <b-button type="submit" variant="primary" :disabled="savingLocation">{{ savingLocation ? 'Guardando…' : 'Crear ubicación' }}</b-button>
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
      savingLocation: false,
      editing: false,
      error: '',
      locationError: '',
      search: '',
      branches: [],
      employees: [],
      locationBranch: null,
      locationTypes: [
        { label: 'Piso de venta', value: 'sales_floor' },
        { label: 'Bodega', value: 'storage' },
        { label: 'Cuarentena', value: 'quarantine' },
        { label: 'Dañados', value: 'damaged' },
        { label: 'Devoluciones', value: 'returns' },
        { label: 'Otra', value: 'other' },
      ],
      types: [
        { label: 'Sucursal', value: 'branch' },
        { label: 'Oficina', value: 'office' },
        { label: 'Otro', value: 'other' },
        { label: 'Centro de distribución (legado)', value: 'distribution_center' },
      ],
      form: this.emptyForm(),
      locationForm: this.emptyLocationForm(),
    };
  },
  computed: {
    employeeOptions() {
      return this.employees.map(e => ({ label: `${e.firstname} ${e.lastname}`.trim(), value: e.id }));
    },
  },
  watch: {
    'locationForm.type'(type) {
      if (type === 'quarantine') {
        this.locationForm.is_sellable = false;
        this.locationForm.is_default_sales = false;
      }
    },
  },
  created() {
    Promise.all([this.loadOptions(), this.loadBranches()]).finally(() => { this.loading = false; });
  },
  methods: {
    emptyForm() {
      return { id: null, name: '', code: '', type: 'branch', phone: '', email: '', country: 'Honduras', city: '', address: '', manager_employee_id: null, inventory_enabled: true, create_storage_location: true, is_active: true };
    },
    emptyLocationForm() {
      return { name: '', code: '', type: 'storage', is_sellable: false, is_default_sales: false, is_quarantine: false };
    },
    apiConfig() {
      return { meta: { skipErrorRedirect: true } };
    },
    async loadOptions() {
      const { data } = await axios.get('/organization/branches/options', this.apiConfig());
      this.employees = data.employees || [];
      if (data.inventory_location_types && data.inventory_location_types.length) this.locationTypes = data.inventory_location_types;
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
        inventory_enabled: !!(branch.inventory_locations && branch.inventory_locations.length),
        create_storage_location: false,
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
    openLocation(branch) {
      this.locationBranch = branch;
      this.locationForm = this.emptyLocationForm();
      this.locationError = '';
      this.$bvModal.show('location-modal');
    },
    async saveLocation() {
      if (!this.locationBranch || !this.locationForm.name || !this.locationForm.code) return;
      this.savingLocation = true;
      this.locationError = '';
      try {
        await axios.post(`/organization/branches/${this.locationBranch.id}/inventory-locations`, this.locationForm, this.apiConfig());
        this.$bvModal.hide('location-modal');
        await this.loadBranches();
        this.$root.$bvToast.toast('Ubicación de inventario creada.', { title: 'Éxito', variant: 'success', solid: true });
      } catch (e) {
        const data = e && e.response && e.response.data;
        this.locationError = (data && (data.message || (data.errors && Object.values(data.errors)[0][0]))) || 'No se pudo crear la ubicación.';
      } finally {
        this.savingLocation = false;
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
    goManual() {
      this.$router.push({ name: 'KnowledgeBaseList' }).catch(() => {});
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
