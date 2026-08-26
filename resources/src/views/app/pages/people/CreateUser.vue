<template>
  <div class="main-content">
    <breadcumb :page="$t('Add')" :folder="$t('Users')"/>

    <b-card class="mb-3 border-0 shadow-sm">
      <div class="d-flex flex-wrap justify-content-between align-items-start">
        <div>
          <h4 class="mb-1">Crear usuario</h4>
          <p class="text-muted mb-0">El rol define qué puede hacer. La sucursal, ubicación y caja física definen dónde opera cuando utiliza POS.</p>
        </div>
        <div class="mt-2 mt-md-0">
          <b-button variant="outline-info" class="mr-2" @click="$router.push('/app/organization/branches')">
            <lucide-icon name="building-2" class="mr-1"/> Sucursales y cajas
          </b-button>
          <b-button variant="outline-primary" @click="$router.push('/app/organization/role-templates')">
            <lucide-icon name="shield-check" class="mr-1"/> Plantillas de roles
          </b-button>
        </div>
      </div>
    </b-card>

    <validation-observer ref="Create_User">
      <b-form @submit.prevent="Submit_User" enctype="multipart/form-data">
        <b-card class="mb-3">
          <h5 class="mb-3">Datos de acceso</h5>
          <b-row>
            <b-col md="6"><validation-provider name="Nombre" :rules="{ required: true, min:2, max:30 }" v-slot="validationContext"><b-form-group label="Nombre *"><b-form-input v-model="user.firstname" :state="getValidationState(validationContext)"/><b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback></b-form-group></validation-provider></b-col>
            <b-col md="6"><validation-provider name="Apellido" :rules="{ required: true, min:2, max:30 }" v-slot="validationContext"><b-form-group label="Apellido *"><b-form-input v-model="user.lastname" :state="getValidationState(validationContext)"/><b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback></b-form-group></validation-provider></b-col>
            <b-col md="6"><validation-provider name="Usuario" :rules="{ required: true, min:3, max:60 }" v-slot="validationContext"><b-form-group label="Nombre de usuario *"><b-form-input v-model="user.username" :state="getValidationState(validationContext)"/><b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback></b-form-group></validation-provider></b-col>
            <b-col md="6"><b-form-group label="Teléfono"><b-form-input v-model="user.phone"/></b-form-group></b-col>
            <b-col md="6"><validation-provider name="Correo" :rules="{ required: true, email: true }" v-slot="validationContext"><b-form-group label="Correo *"><b-form-input type="email" v-model="user.email" :state="getValidationState(validationContext)"/><b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback><b-alert show variant="danger" class="error mt-1" v-if="email_exist">{{ email_exist }}</b-alert></b-form-group></validation-provider></b-col>
            <b-col md="6"><validation-provider name="Contraseña" :rules="{ required: true, min:8 }" v-slot="validationContext"><b-form-group label="Contraseña temporal *"><b-form-input type="password" v-model="user.password" :state="getValidationState(validationContext)"/><b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback><small class="text-muted">Mínimo 8 caracteres.</small></b-form-group></validation-provider></b-col>
            <b-col md="6"><validation-provider name="Rol" :rules="{ required: true }" v-slot="{ valid, errors }"><b-form-group label="Rol *"><v-select :class="{'is-invalid': !!errors.length}" :state="errors[0] ? false : (valid ? true : null)" v-model="user.role_id" :reduce="o => o.value" :options="roleOptions" placeholder="Seleccionar rol" @input="roleChanged"/><b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback><small class="text-muted">Los permisos se administran desde Usuarios y accesos → Roles y permisos.</small></b-form-group></validation-provider></b-col>
            <b-col md="6"><b-form-group label="Imagen de usuario"><input @change="onFileSelected" type="file" accept="image/*" class="form-control-file"/></b-form-group></b-col>
          </b-row>
        </b-card>

        <b-card class="mb-3">
          <h5 class="mb-1">Alcance operativo</h5>
          <p class="text-muted mb-3">Selecciona las sucursales en las que esta cuenta puede trabajar. Los almacenes/CD no representan la ubicación laboral del usuario.</p>
          <b-row>
            <b-col md="6"><b-form-group label="Tipo de alcance *"><v-select v-model="user.scope" :reduce="o => o.value" :options="scopeOptions" @input="scopeChanged"/></b-form-group></b-col>
            <b-col md="6" v-if="user.scope === 'selected'"><b-form-group label="Sucursales permitidas *"><v-select multiple v-model="user.branch_ids" :reduce="o => o.value" :options="branchOptions" placeholder="Seleccionar sucursales" @input="branchesChanged"/></b-form-group></b-col>
            <b-col md="12" v-if="user.scope === 'all'"><div class="alert alert-warning py-2 mb-3">Esta cuenta tendrá alcance organizacional global. El rol seguirá limitando las acciones que puede ejecutar.</div></b-col>
            <template v-if="user.scope !== 'all'">
              <b-col md="12" v-if="selectedBranchIds.length"><b-form-group label="Ubicaciones de inventario permitidas"><v-select multiple v-model="user.inventory_location_ids" :reduce="o => o.value" :options="allowedLocationOptions" placeholder="Piso de venta, Bodega, Cuarentena…"/><small class="text-muted">Cajero: normalmente Piso de venta. Otros puestos pueden incluir más ubicaciones según el rol.</small></b-form-group></b-col>
              <b-col md="6"><b-form-group label="Sucursal predeterminada *"><v-select v-model="user.default_branch_id" :reduce="o => o.value" :options="defaultBranchOptions" placeholder="Seleccionar" @input="defaultBranchChanged"/></b-form-group></b-col>
              <b-col md="6"><b-form-group label="Ubicación predeterminada de inventario"><v-select v-model="user.default_inventory_location_id" :reduce="o => o.value" :options="defaultLocationOptions" placeholder="Seleccionar" @input="defaultLocationChanged"/><small class="text-muted">Para un cajero normalmente será el Piso de venta de su sucursal.</small></b-form-group></b-col>
            </template>

            <b-col md="12" v-if="selectedRole && selectedRole.uses_pos">
              <div class="border rounded p-3 mb-3">
                <b-form-group :label="selectedRole.requires_cash_drawer ? 'Caja física predeterminada *' : 'Caja física predeterminada'" class="mb-1">
                  <v-select
                    v-model="user.default_cash_drawer_id"
                    :reduce="o => o.value"
                    :options="defaultCashDrawerOptions"
                    :placeholder="defaultCashDrawerOptions.length ? 'Seleccionar caja física' : 'No hay cajas disponibles en esta ubicación'"
                  />
                </b-form-group>
                <small class="text-muted">La caja pertenece a la empresa y a la ubicación. Esta selección solo define la caja habitual de este usuario.</small>
                <b-alert v-if="selectedRole.requires_cash_drawer && !defaultCashDrawerOptions.length" show variant="warning" class="mt-2 mb-0 py-2">
                  Este rol necesita una caja física para operar POS. Créala primero en la sucursal y ubicación seleccionadas.
                  <b-button size="sm" variant="outline-warning" class="ml-2" @click="$router.push('/app/organization/branches')">Administrar cajas</b-button>
                </b-alert>
              </div>
            </b-col>

            <b-col md="12"><b-form-checkbox v-model="user.record_view">Ver registros de otros usuarios dentro de su propio alcance</b-form-checkbox><small class="text-muted">No amplía sucursales ni ubicaciones.</small></b-col>
          </b-row>
        </b-card>

        <div v-if="form_errors.length" class="alert alert-danger">
          <div class="font-weight-bold mb-1">No se pudo crear el usuario:</div>
          <ul class="mb-0 pl-3"><li v-for="(message, index) in form_errors" :key="index">{{ message }}</li></ul>
        </div>
        <b-button variant="primary" type="submit" :disabled="SubmitProcessing"><lucide-icon class="mr-1" name="check"/> {{ SubmitProcessing ? 'Guardando…' : 'Crear usuario' }}</b-button>
        <b-button variant="secondary" class="ml-2" @click="$router.push({ name: 'Users' })">Cancelar</b-button>
      </b-form>
    </validation-observer>
  </div>
</template>

<script>
import NProgress from 'nprogress';

export default {
  metaInfo: { title: 'Crear usuario' },
  data() {
    return {
      SubmitProcessing: false,
      email_exist: '',
      form_error: '',
      form_errors: [],
      roles: [], branches: [], inventoryLocations: [], cashDrawers: [], canGlobalScope: false,
      user: {
        firstname: '', lastname: '', username: '', email: '', phone: '', password: '', role_id: null,
        avatar: null, record_view: false, scope: 'selected', branch_ids: [], inventory_location_ids: [],
        default_branch_id: null, default_inventory_location_id: null, default_cash_drawer_id: null,
      },
    };
  },
  computed: {
    roleOptions() { return this.roles.map(r => ({ label: r.description ? `${r.name} — ${r.description}` : r.name, value: r.id })); },
    selectedRole() { return this.roles.find(r => Number(r.id) === Number(this.user.role_id)) || null; },
    scopeOptions() {
      const options = [{ label: 'Sucursales seleccionadas', value: 'selected' }];
      if (this.canGlobalScope) options.push({ label: 'Toda la empresa', value: 'all' });
      return options;
    },
    branchOptions() { return this.branches.map(b => ({ label: b.code ? `${b.name} · ${b.code}` : b.name, value: b.id })); },
    selectedBranchIds() { return (this.user.branch_ids || []).map(Number).filter(Boolean); },
    defaultBranchOptions() { const ids = this.selectedBranchIds; return this.branches.filter(b => ids.includes(Number(b.id))).map(b => ({ label: b.name, value: b.id })); },
    allowedLocationOptions() {
      const branchIds = this.selectedBranchIds;
      return this.inventoryLocations.filter(l => branchIds.includes(Number(l.branch_id))).map(l => ({ label: `${this.branchName(l.branch_id)} · ${l.name}${l.is_default_sales ? ' · Predeterminada' : ''}`, value: l.id }));
    },
    defaultLocationOptions() { return this.inventoryLocations.filter(l => Number(l.branch_id) === Number(this.user.default_branch_id)).map(l => ({ label: `${l.name}${l.is_default_sales ? ' · Piso predeterminado' : ''}`, value: l.id })); },
    defaultCashDrawerOptions() {
      const branchId = Number(this.user.default_branch_id || 0);
      const locationId = Number(this.user.default_inventory_location_id || 0);
      if (!branchId || !locationId) return [];
      return this.cashDrawers
        .filter(d => Number(d.branch_id) === branchId && Number(d.inventory_location_id) === locationId && !!d.is_active)
        .map(d => ({ label: d.code ? `${d.name} · ${d.code}` : d.name, value: d.id }));
    },
  },
  created() { this.getOptions(); },
  methods: {
    getValidationState({ dirty, validated, valid = null }) { return dirty || validated ? valid : null; },
    makeToast(variant, msg, title) { if (this.$root && this.$root.$bvToast) this.$root.$bvToast.toast(msg, { title, variant, solid: true }); },
    async getOptions() {
      NProgress.start();
      try {
        const { data } = await axios.get('/organization/user-access/options', { meta: { skipErrorRedirect: true } });
        this.roles = data.roles || [];
        this.branches = data.branches || [];
        this.inventoryLocations = data.inventory_locations || [];
        this.cashDrawers = data.cash_drawers || [];
        this.canGlobalScope = !!data.can_global_scope;
        if (this.branches.length === 1) {
          this.user.branch_ids = [this.branches[0].id];
          this.user.default_branch_id = this.branches[0].id;
          this.defaultBranchChanged();
        }
      } finally { NProgress.done(); }
    },
    branchName(id) { const branch = this.branches.find(b => Number(b.id) === Number(id)); return branch ? branch.name : 'Sucursal'; },
    defaultLocationId(branchId) {
      const branch = this.branches.find(b => Number(b.id) === Number(branchId));
      if (branch && branch.default_inventory_location_id) return Number(branch.default_inventory_location_id);
      const floor = this.inventoryLocations.find(l => Number(l.branch_id) === Number(branchId) && l.is_default_sales);
      return floor ? Number(floor.id) : null;
    },
    roleChanged() {
      if (!this.selectedRole || !this.selectedRole.uses_pos) this.user.default_cash_drawer_id = null;
      else this.selectSingleDrawerWhenUnambiguous();
    },
    scopeChanged() {
      if (this.user.scope === 'all') {
        this.user.branch_ids = []; this.user.inventory_location_ids = [];
        this.user.default_branch_id = null; this.user.default_inventory_location_id = null; this.user.default_cash_drawer_id = null;
      }
    },
    branchesChanged() {
      const branchIds = this.selectedBranchIds;
      this.user.inventory_location_ids = (this.user.inventory_location_ids || []).filter(id => {
        const location = this.inventoryLocations.find(l => Number(l.id) === Number(id));
        return location && branchIds.includes(Number(location.branch_id));
      });
      if (!branchIds.includes(Number(this.user.default_branch_id))) {
        this.user.default_branch_id = branchIds[0] || null;
        this.defaultBranchChanged();
      }
    },
    defaultBranchChanged() {
      const branchId = Number(this.user.default_branch_id || 0);
      this.user.default_cash_drawer_id = null;
      if (!branchId) { this.user.default_inventory_location_id = null; return; }
      const id = this.defaultLocationId(branchId);
      this.user.default_inventory_location_id = id;
      if (id && !(this.user.inventory_location_ids || []).map(Number).includes(id)) this.user.inventory_location_ids.push(id);
      this.selectSingleDrawerWhenUnambiguous();
    },
    defaultLocationChanged() {
      const id = Number(this.user.default_inventory_location_id || 0);
      this.user.default_cash_drawer_id = null;
      if (id && !(this.user.inventory_location_ids || []).map(Number).includes(id)) this.user.inventory_location_ids.push(id);
      this.selectSingleDrawerWhenUnambiguous();
    },
    selectSingleDrawerWhenUnambiguous() {
      if (this.selectedRole && this.selectedRole.uses_pos && this.defaultCashDrawerOptions.length === 1) this.user.default_cash_drawer_id = this.defaultCashDrawerOptions[0].value;
    },
    onFileSelected(e) { this.user.avatar = e.target.files && e.target.files[0] ? e.target.files[0] : null; },
    Submit_User() {
      this.form_errors = [];
      this.$refs.Create_User.validate().then(success => {
        if (!success) {
          const message = this.$t('Please_fill_the_form_correctly');
          this.form_errors = [message]; this.makeToast('danger', message, this.$t('Failed')); return;
        }
        if (this.user.scope !== 'all' && !this.selectedBranchIds.length) { this.form_errors = ['Selecciona al menos una sucursal.']; return; }
        if (this.selectedRole && this.selectedRole.requires_cash_drawer && !this.user.default_cash_drawer_id) {
          this.form_errors = ['Este rol necesita una caja física predeterminada para operar POS.']; return;
        }
        this.Create_User();
      });
    },
    async Create_User() {
      this.SubmitProcessing = true; this.email_exist = ''; this.form_error = ''; this.form_errors = [];
      const data = new FormData();
      Object.keys(this.user).forEach(key => {
        if (['branch_ids', 'inventory_location_ids', 'avatar', 'record_view'].includes(key)) return;
        const value = this.user[key]; data.append(key, value === null || typeof value === 'undefined' ? '' : value);
      });
      this.selectedBranchIds.forEach((id, i) => data.append(`branch_ids[${i}]`, id));
      (this.user.inventory_location_ids || []).forEach((id, i) => data.append(`inventory_location_ids[${i}]`, id));
      data.append('record_view', this.user.record_view ? 1 : 0);
      if (this.user.avatar) data.append('avatar', this.user.avatar);
      try {
        await axios.post('/organization/user-access', data, { meta: { skipErrorRedirect: true } });
        this.makeToast('success', this.$t('Successfully_Created'), this.$t('Success'));
        this.$router.push({ name: 'Users' });
      } catch (error) {
        const response = error && error.response && error.response.data ? error.response.data : (error && typeof error === 'object' ? error : null);
        const errors = response && response.errors ? response.errors : null;
        if (errors && errors.email) this.email_exist = Array.isArray(errors.email) ? errors.email[0] : errors.email;
        if (errors) {
          this.form_errors = Object.values(errors).reduce((messages, value) => {
            if (Array.isArray(value)) return messages.concat(value.filter(Boolean));
            if (value) messages.push(value); return messages;
          }, []);
        }
        if (!this.form_errors.length && response && response.field && response.message) this.form_errors = [`${response.message} (${response.field})`];
        if (!this.form_errors.length) {
          const message = response && (response.message || response.error) ? (response.message || response.error) : (typeof error === 'string' ? error : 'No se pudo crear el usuario.');
          this.form_errors = [message];
        }
        this.form_error = this.form_errors[0]; this.makeToast('danger', this.form_error, this.$t('Failed'));
      } finally { this.SubmitProcessing = false; }
    },
  },
};
</script>
