<template>
  <div class="main-content">
    <breadcumb :page="$t('Edit')" :folder="$t('Users')"/>

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <validation-observer ref="Edit_User" v-else>
      <b-form @submit.prevent="Submit_User" enctype="multipart/form-data">
        <b-card class="mb-3">
          <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
            <div>
              <h4 class="mb-1">Editar usuario</h4>
              <p class="text-muted mb-0">Modifica identidad, rol y alcance operativo sin mezclar la sucursal con los almacenes/CD.</p>
            </div>
            <b-button variant="outline-primary" @click="$router.push('/app/organization/role-templates')">
              <lucide-icon name="shield-check" class="mr-1"/> Roles
            </b-button>
          </div>

          <b-row>
            <b-col md="6"><validation-provider name="Nombre" :rules="{ required: true, min:2, max:30 }" v-slot="validationContext"><b-form-group label="Nombre *"><b-form-input v-model="user.firstname" :state="getValidationState(validationContext)"/><b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback></b-form-group></validation-provider></b-col>
            <b-col md="6"><validation-provider name="Apellido" :rules="{ required: true, min:2, max:30 }" v-slot="validationContext"><b-form-group label="Apellido *"><b-form-input v-model="user.lastname" :state="getValidationState(validationContext)"/><b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback></b-form-group></validation-provider></b-col>
            <b-col md="6"><validation-provider name="Usuario" :rules="{ required: true, min:3, max:60 }" v-slot="validationContext"><b-form-group label="Nombre de usuario *"><b-form-input v-model="user.username" :state="getValidationState(validationContext)"/><b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback></b-form-group></validation-provider></b-col>
            <b-col md="6"><b-form-group label="Teléfono"><b-form-input v-model="user.phone"/></b-form-group></b-col>
            <b-col md="6"><validation-provider name="Correo" :rules="{ required: true, email: true }" v-slot="validationContext"><b-form-group label="Correo *"><b-form-input type="email" v-model="user.email" :state="getValidationState(validationContext)"/><b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback></b-form-group></validation-provider></b-col>
            <b-col md="6"><b-form-group label="Nueva contraseña"><b-form-input type="password" v-model="user.password"/><small class="text-muted">Déjala vacía para conservar la actual. Si la cambias, usa mínimo 8 caracteres.</small></b-form-group></b-col>
            <b-col md="6"><validation-provider name="Rol" :rules="{ required: true }" v-slot="{ valid, errors }"><b-form-group label="Rol *"><v-select :class="{'is-invalid': !!errors.length}" :state="errors[0] ? false : (valid ? true : null)" v-model="user.role_id" :reduce="o => o.value" :options="roleOptions" placeholder="Seleccionar rol"/><b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback></b-form-group></validation-provider></b-col>
            <b-col md="6"><b-form-group label="Estado"><v-select v-model="user.statut" :reduce="o => o.value" :options="statusOptions"/></b-form-group></b-col>
            <b-col md="6"><b-form-group label="Imagen de usuario"><input @change="onFileSelected" type="file" accept="image/*" class="form-control-file"/><small class="text-muted" v-if="user.avatar_name">Actual: {{ user.avatar_name }}</small></b-form-group></b-col>
          </b-row>
        </b-card>

        <b-card class="mb-3">
          <h5 class="mb-1">Alcance operativo</h5>
          <p class="text-muted mb-3">El rol determina las acciones. Este bloque limita las sucursales y ubicaciones sobre las que puede operar.</p>
          <b-row>
            <b-col md="6"><b-form-group label="Tipo de alcance *"><v-select v-model="user.scope" :reduce="o => o.value" :options="scopeOptions" @input="scopeChanged"/></b-form-group></b-col>
            <b-col md="6" v-if="user.scope === 'selected'"><b-form-group label="Sucursales permitidas *"><v-select multiple v-model="user.branch_ids" :reduce="o => o.value" :options="branchOptions" placeholder="Seleccionar sucursales" @input="branchesChanged"/></b-form-group></b-col>
            <b-col md="12" v-if="user.scope === 'all'"><div class="alert alert-warning py-2">Alcance global: podrá consultar las ubicaciones de toda la empresa, siempre sujeto a los permisos del rol.</div></b-col>
            <template v-if="user.scope !== 'all'">
              <b-col md="12" v-if="selectedBranchIds.length"><b-form-group label="Ubicaciones de inventario permitidas"><v-select multiple v-model="user.inventory_location_ids" :reduce="o => o.value" :options="allowedLocationOptions" placeholder="Seleccionar ubicaciones"/><small class="text-muted">No es necesario asignar un CD/almacén para que un usuario trabaje en una sucursal.</small></b-form-group></b-col>
              <b-col md="6"><b-form-group label="Sucursal predeterminada *"><v-select v-model="user.default_branch_id" :reduce="o => o.value" :options="defaultBranchOptions" placeholder="Seleccionar" @input="defaultBranchChanged"/></b-form-group></b-col>
              <b-col md="6"><b-form-group label="Ubicación predeterminada"><v-select v-model="user.default_inventory_location_id" :reduce="o => o.value" :options="defaultLocationOptions" placeholder="Seleccionar"/></b-form-group></b-col>
            </template>
            <b-col md="12"><b-form-checkbox v-model="user.record_view">Ver registros de otros usuarios dentro de su propio alcance</b-form-checkbox><small class="text-muted">No concede acceso a otras sucursales.</small></b-col>
          </b-row>
        </b-card>

        <div v-if="form_error" class="alert alert-danger">{{ form_error }}</div>
        <b-button variant="primary" type="submit" :disabled="SubmitProcessing"><lucide-icon class="mr-1" name="check"/> {{ SubmitProcessing ? 'Guardando…' : 'Guardar cambios' }}</b-button>
        <b-button variant="secondary" class="ml-2" @click="$router.push({ name: 'Users' })">Cancelar</b-button>
      </b-form>
    </validation-observer>
  </div>
</template>

<script>
import NProgress from 'nprogress';

export default {
  metaInfo: { title: 'Editar usuario' },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      form_error: '',
      roles: [], branches: [], inventoryLocations: [], canGlobalScope: false,
      statusOptions: [{ label: 'Activo', value: 1 }, { label: 'Inactivo', value: 0 }],
      user: {
        firstname: '', lastname: '', username: '', email: '', phone: '', password: '', role_id: null,
        statut: 1, record_view: false, scope: 'selected', branch_ids: [], inventory_location_ids: [],
        default_branch_id: null, default_inventory_location_id: null, avatar: null, avatar_name: '',
      },
    };
  },
  computed: {
    roleOptions() { return this.roles.map(r => ({ label: r.description ? `${r.name} — ${r.description}` : r.name, value: r.id })); },
    scopeOptions() {
      const options = [{ label: 'Sucursales seleccionadas', value: 'selected' }];
      if (this.canGlobalScope) options.push({ label: 'Toda la empresa', value: 'all' });
      return options;
    },
    branchOptions() { return this.branches.map(b => ({ label: b.code ? `${b.name} · ${b.code}` : b.name, value: b.id })); },
    selectedBranchIds() { return (this.user.branch_ids || []).map(Number).filter(Boolean); },
    defaultBranchOptions() { const ids = this.selectedBranchIds; return this.branches.filter(b => ids.includes(Number(b.id))).map(b => ({ label: b.name, value: b.id })); },
    allowedLocationOptions() {
      const ids = this.selectedBranchIds;
      return this.inventoryLocations.filter(l => ids.includes(Number(l.branch_id))).map(l => ({ label: `${this.branchName(l.branch_id)} · ${l.name}${l.is_default_sales ? ' · Predeterminada' : ''}`, value: l.id }));
    },
    defaultLocationOptions() { return this.inventoryLocations.filter(l => Number(l.branch_id) === Number(this.user.default_branch_id)).map(l => ({ label: `${l.name}${l.is_default_sales ? ' · Piso predeterminado' : ''}`, value: l.id })); },
  },
  created() { this.load(); },
  methods: {
    getValidationState({ dirty, validated, valid = null }) { return dirty || validated ? valid : null; },
    async load() {
      NProgress.start();
      try {
        const { data } = await axios.get(`/organization/user-access/${this.$route.params.id}`, { meta: { skipErrorRedirect: true } });
        this.roles = data.roles || [];
        this.branches = data.branches || [];
        this.inventoryLocations = data.inventory_locations || [];
        this.canGlobalScope = !!data.can_global_scope;
        const source = data.user || {};
        this.user = Object.assign({}, this.user, source, {
          password: '',
          avatar: null,
          avatar_name: source.avatar || '',
          branch_ids: source.branch_ids || [],
          inventory_location_ids: source.inventory_location_ids || [],
          record_view: !!source.record_view,
          statut: Number(source.statut) === 0 ? 0 : 1,
        });
      } catch (e) {
        this.form_error = (e.response && e.response.data && e.response.data.message) || 'No se pudo cargar el usuario.';
      } finally { this.isLoading = false; NProgress.done(); }
    },
    branchName(id) { const branch = this.branches.find(b => Number(b.id) === Number(id)); return branch ? branch.name : 'Sucursal'; },
    defaultLocationId(branchId) {
      const branch = this.branches.find(b => Number(b.id) === Number(branchId));
      if (branch && branch.default_inventory_location_id) return Number(branch.default_inventory_location_id);
      const floor = this.inventoryLocations.find(l => Number(l.branch_id) === Number(branchId) && l.is_default_sales);
      return floor ? Number(floor.id) : null;
    },
    scopeChanged() {
      if (this.user.scope === 'all') {
        this.user.branch_ids = []; this.user.inventory_location_ids = [];
        this.user.default_branch_id = null; this.user.default_inventory_location_id = null;
      }
    },
    branchesChanged() {
      const ids = this.selectedBranchIds;
      this.user.inventory_location_ids = (this.user.inventory_location_ids || []).filter(id => {
        const location = this.inventoryLocations.find(l => Number(l.id) === Number(id));
        return location && ids.includes(Number(location.branch_id));
      });
      if (!ids.includes(Number(this.user.default_branch_id))) {
        this.user.default_branch_id = ids[0] || null;
        this.defaultBranchChanged();
      }
    },
    defaultBranchChanged() {
      const branchId = Number(this.user.default_branch_id || 0);
      if (!branchId) { this.user.default_inventory_location_id = null; return; }
      const current = this.inventoryLocations.find(l => Number(l.id) === Number(this.user.default_inventory_location_id));
      if (!current || Number(current.branch_id) !== branchId) {
        const id = this.defaultLocationId(branchId);
        this.user.default_inventory_location_id = id;
        if (id && !(this.user.inventory_location_ids || []).map(Number).includes(id)) this.user.inventory_location_ids.push(id);
      }
    },
    onFileSelected(e) { this.user.avatar = e.target.files && e.target.files[0] ? e.target.files[0] : null; },
    Submit_User() {
      this.$refs.Edit_User.validate().then(success => {
        if (!success) { this.makeToast('danger', this.$t('Please_fill_the_form_correctly'), this.$t('Failed')); return; }
        if (this.user.password && this.user.password.length < 8) { this.form_error = 'La nueva contraseña debe tener al menos 8 caracteres.'; return; }
        if (this.user.scope !== 'all' && !this.selectedBranchIds.length) { this.form_error = 'Selecciona al menos una sucursal.'; return; }
        this.Update_User();
      });
    },
    async Update_User() {
      this.SubmitProcessing = true; this.form_error = '';
      const data = new FormData();
      ['firstname','lastname','username','email','phone','password','role_id','statut','scope','default_branch_id','default_inventory_location_id'].forEach(key => {
        const value = this.user[key]; data.append(key, value === null || typeof value === 'undefined' ? '' : value);
      });
      this.selectedBranchIds.forEach((id, i) => data.append(`branch_ids[${i}]`, id));
      (this.user.inventory_location_ids || []).forEach((id, i) => data.append(`inventory_location_ids[${i}]`, id));
      data.append('record_view', this.user.record_view ? 1 : 0);
      if (this.user.avatar) data.append('avatar', this.user.avatar);
      data.append('_method', 'PUT');

      try {
        await axios.post(`/organization/user-access/${this.$route.params.id}`, data, { meta: { skipErrorRedirect: true } });
        this.makeToast('success', this.$t('Successfully_Updated'), this.$t('Success'));
        this.$router.push({ name: 'Users' });
      } catch (e) {
        const response = e && e.response && e.response.data;
        const first = response && response.errors ? Object.values(response.errors)[0] : null;
        this.form_error = (Array.isArray(first) ? first[0] : first) || (response && response.message) || 'No se pudo actualizar el usuario.';
        this.makeToast('danger', this.form_error, this.$t('Failed'));
      } finally { this.SubmitProcessing = false; }
    },
  },
};
</script>
