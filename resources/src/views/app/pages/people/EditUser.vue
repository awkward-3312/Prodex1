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
              <p class="text-muted mb-0">Modifica identidad, rol y contexto operativo. Para POS, la caja física habitual debe pertenecer a la misma sucursal y ubicación.</p>
            </div>
            <div>
              <b-button variant="outline-info" class="mr-2" @click="$router.push('/app/organization/branches')"><lucide-icon name="building-2" class="mr-1"/> Sucursales y cajas</b-button>
              <b-button variant="outline-primary" @click="$router.push('/app/organization/role-templates')"><lucide-icon name="shield-check" class="mr-1"/> Roles</b-button>
            </div>
          </div>

          <b-row>
            <b-col md="6"><validation-provider name="Nombre" :rules="{ required: true, min:2, max:30 }" v-slot="validationContext"><b-form-group label="Nombre *"><b-form-input v-model="user.firstname" :state="getValidationState(validationContext)"/><b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback></b-form-group></validation-provider></b-col>
            <b-col md="6"><validation-provider name="Apellido" :rules="{ required: true, min:2, max:30 }" v-slot="validationContext"><b-form-group label="Apellido *"><b-form-input v-model="user.lastname" :state="getValidationState(validationContext)"/><b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback></b-form-group></validation-provider></b-col>
            <b-col md="6"><validation-provider name="Usuario" :rules="{ required: true, min:3, max:60 }" v-slot="validationContext"><b-form-group label="Nombre de usuario *"><b-form-input v-model="user.username" :state="getValidationState(validationContext)"/><b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback></b-form-group></validation-provider></b-col>
            <b-col md="6"><b-form-group label="Teléfono"><b-form-input v-model="user.phone"/></b-form-group></b-col>
            <b-col md="6"><validation-provider name="Correo" :rules="{ required: true, email: true }" v-slot="validationContext"><b-form-group label="Correo *"><b-form-input type="email" v-model="user.email" :state="getValidationState(validationContext)"/><b-form-invalid-feedback>{{ validationContext.errors[0] }}</b-form-invalid-feedback></b-form-group></validation-provider></b-col>
            <b-col md="6"><b-form-group label="Nueva contraseña"><b-form-input type="password" v-model="user.password"/><small class="text-muted">Déjala vacía para conservar la actual. Si la cambias, usa mínimo 8 caracteres.</small></b-form-group></b-col>
            <b-col md="6"><validation-provider name="Rol" :rules="{ required: true }" v-slot="{ valid, errors }"><b-form-group label="Rol *"><v-select :class="{'is-invalid': !!errors.length}" :state="errors[0] ? false : (valid ? true : null)" v-model="user.role_id" :reduce="o => o.value" :options="roleOptions" placeholder="Seleccionar rol" @input="roleChanged"/><b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback></b-form-group></validation-provider></b-col>
            <b-col md="6"><b-form-group label="Estado"><v-select v-model="user.statut" :reduce="o => o.value" :options="statusOptions"/></b-form-group></b-col>
            <b-col md="6"><b-form-group label="Imagen de usuario"><input @change="onFileSelected" type="file" accept="image/*" class="form-control-file"/><small class="text-muted" v-if="user.avatar_name">Actual: {{ user.avatar_name }}</small></b-form-group></b-col>
          </b-row>
        </b-card>

        <b-card class="mb-3">
          <h5 class="mb-1">Alcance operativo</h5>
          <p class="text-muted mb-3">El rol determina las acciones. Este bloque limita la sucursal, ubicación y, cuando corresponde, la caja física habitual.</p>
          <b-row>
            <b-col md="6"><b-form-group label="Tipo de alcance *"><v-select v-model="user.scope" :reduce="o => o.value" :options="scopeOptions" @input="scopeChanged"/></b-form-group></b-col>
            <b-col md="6" v-if="user.scope === 'selected'"><b-form-group label="Sucursales permitidas *"><v-select multiple v-model="user.branch_ids" :reduce="o => o.value" :options="branchOptions" placeholder="Seleccionar sucursales" @input="branchesChanged"/></b-form-group></b-col>
            <b-col md="12" v-if="user.scope === 'all'"><div class="alert alert-warning py-2">Alcance global: podrá consultar las ubicaciones de toda la empresa, siempre sujeto a los permisos del rol.</div></b-col>
            <template v-if="user.scope !== 'all'">
              <b-col md="12" v-if="selectedBranchIds.length"><b-form-group label="Ubicaciones de inventario permitidas"><v-select multiple v-model="user.inventory_location_ids" :reduce="o => o.value" :options="allowedLocationOptions" placeholder="Seleccionar ubicaciones"/><small class="text-muted">No es necesario asignar un CD/almacén para que un usuario trabaje en una sucursal.</small></b-form-group></b-col>
              <b-col md="6"><b-form-group label="Sucursal predeterminada *"><v-select v-model="user.default_branch_id" :reduce="o => o.value" :options="defaultBranchOptions" placeholder="Seleccionar" @input="defaultBranchChanged"/></b-form-group></b-col>
              <b-col md="6"><b-form-group label="Ubicación predeterminada"><v-select v-model="user.default_inventory_location_id" :reduce="o => o.value" :options="defaultLocationOptions" placeholder="Seleccionar" @input="defaultLocationChanged"/></b-form-group></b-col>
            </template>

            <b-col md="12" v-if="selectedRole && selectedRole.uses_pos">
              <div class="border rounded p-3 mb-3">
                <b-form-group :label="selectedRole.requires_cash_drawer ? 'Caja física predeterminada *' : 'Caja física predeterminada'" class="mb-1">
                  <v-select v-model="user.default_cash_drawer_id" :reduce="o => o.value" :options="defaultCashDrawerOptions" :placeholder="defaultCashDrawerOptions.length ? 'Seleccionar caja física' : 'No hay cajas disponibles en esta ubicación'"/>
                </b-form-group>
                <small class="text-muted">La caja pertenece a la empresa. Cambiar la asignación habitual no elimina sesiones ni historial anteriores.</small>
                <b-alert v-if="selectedRole.requires_cash_drawer && !defaultCashDrawerOptions.length" show variant="warning" class="mt-2 mb-0 py-2">
                  Este rol necesita una caja física para operar POS. Crea una en la sucursal y ubicación seleccionadas antes de guardar.
                  <b-button size="sm" variant="outline-warning" class="ml-2" @click="$router.push('/app/organization/branches')">Administrar cajas</b-button>
                </b-alert>
              </div>
            </b-col>

            <b-col md="12"><b-form-checkbox v-model="user.record_view">Ver registros de otros usuarios dentro de su propio alcance</b-form-checkbox><small class="text-muted">No concede acceso a otras sucursales.</small></b-col>
          </b-row>
        </b-card>

        <b-card v-if="operationalLoaded" class="mb-3">
          <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
            <div><h5 class="mb-1">Asignación temporal</h5><p class="text-muted mb-0">Úsala cuando la persona cubra otra sucursal temporalmente. No modifica su sucursal o caja habitual.</p></div>
            <b-badge v-if="operational.active_temporary_assignment" variant="warning">Temporal activa</b-badge>
          </div>

          <b-row class="mb-3">
            <b-col md="6"><div class="border rounded p-3 h-100"><strong>Configuración habitual</strong><div class="mt-2">Sucursal: {{ operational.default.branch_name || 'Sin definir' }}</div><div>Inventario: {{ operational.default.inventory_location_name || 'Sin definir' }}</div><div>Caja: {{ operational.default.cash_drawer_name || 'Sin definir' }}</div></div></b-col>
            <b-col md="6"><div class="border rounded p-3 h-100"><strong>Contexto efectivo ahora</strong><div class="mt-2">Sucursal: {{ effectiveName('branch') }}</div><div>Inventario: {{ effectiveName('inventory_location') }}</div><div>Caja: {{ effectiveName('cash_drawer') }}</div><small class="text-muted">Fuente: {{ operational.effective && operational.effective.source === 'temporary' ? 'Asignación temporal' : 'Configuración habitual' }}</small></div></b-col>
          </b-row>

          <div v-if="operational.active_temporary_assignment" class="alert alert-warning">
            <div class="d-flex justify-content-between align-items-start flex-wrap">
              <div><strong>{{ operational.active_temporary_assignment.temporary_branch_name }}</strong><div>{{ operational.active_temporary_assignment.temporary_inventory_location_name || 'Sin ubicación' }}<span v-if="operational.active_temporary_assignment.temporary_cash_drawer_name"> · {{ operational.active_temporary_assignment.temporary_cash_drawer_name }}</span></div><small>{{ operational.active_temporary_assignment.reason }}</small></div>
              <b-button v-if="canTemporaryAssignment" size="sm" variant="outline-danger" class="mt-2 mt-md-0" @click="endTemporaryAssignment">Finalizar asignación</b-button>
            </div>
          </div>

          <template v-if="canTemporaryAssignment">
            <hr><h6>{{ operational.active_temporary_assignment ? 'Reemplazar asignación temporal' : 'Crear asignación temporal' }}</h6>
            <b-row>
              <b-col md="4"><b-form-group label="Sucursal temporal *"><v-select v-model="temporary.branch_id" :reduce="o => o.value" :options="temporaryBranchOptions" @input="temporaryBranchChanged"/></b-form-group></b-col>
              <b-col md="4"><b-form-group label="Ubicación de inventario *"><v-select v-model="temporary.inventory_location_id" :reduce="o => o.value" :options="temporaryLocationOptions" @input="temporaryLocationChanged"/></b-form-group></b-col>
              <b-col md="4"><b-form-group label="Caja física"><v-select v-model="temporary.cash_drawer_id" :reduce="o => o.value" :options="temporaryDrawerOptions" placeholder="Necesaria para personal de caja"/></b-form-group></b-col>
              <b-col md="6"><b-form-group label="Inicio"><b-form-input v-model="temporary.starts_at" type="datetime-local"/></b-form-group></b-col>
              <b-col md="6"><b-form-group label="Fin"><b-form-input v-model="temporary.ends_at" type="datetime-local"/></b-form-group></b-col>
              <b-col md="12"><b-form-group label="Motivo *"><b-form-textarea v-model.trim="temporary.reason" rows="2" maxlength="2000" placeholder="Ej. Cobertura de turno en Sucursal Mall"/></b-form-group></b-col>
            </b-row>
            <div v-if="temporary_error" class="alert alert-danger">{{ temporary_error }}</div>
            <b-button type="button" variant="outline-primary" :disabled="temporarySaving" @click="saveTemporaryAssignment">{{ temporarySaving ? 'Guardando…' : 'Guardar asignación temporal' }}</b-button>
          </template>
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
import { mapGetters } from 'vuex';

export default {
  metaInfo: { title: 'Editar usuario' },
  data() {
    return {
      isLoading: true, SubmitProcessing: false, form_error: '',
      roles: [], branches: [], inventoryLocations: [], cashDrawers: [], canGlobalScope: false,
      statusOptions: [{ label: 'Activo', value: 1 }, { label: 'Inactivo', value: 0 }],
      user: {
        firstname: '', lastname: '', username: '', email: '', phone: '', password: '', role_id: null,
        statut: 1, record_view: false, scope: 'selected', branch_ids: [], inventory_location_ids: [],
        default_branch_id: null, default_inventory_location_id: null, default_cash_drawer_id: null, avatar: null, avatar_name: '',
      },
      operationalLoaded: false,
      operational: { default: {}, effective: null, active_temporary_assignment: null, branches: [], inventory_locations: [], cash_drawers: [] },
      temporary: { branch_id: null, inventory_location_id: null, cash_drawer_id: null, starts_at: '', ends_at: '', reason: '' },
      temporarySaving: false, temporary_error: '',
    };
  },
  computed: {
    ...mapGetters(['currentUserPermissions']),
    canTemporaryAssignment() { return Array.isArray(this.currentUserPermissions) && this.currentUserPermissions.includes('user_temporary_assignment'); },
    roleOptions() { return this.roles.map(r => ({ label: r.description ? `${r.name} — ${r.description}` : r.name, value: r.id })); },
    selectedRole() { return this.roles.find(r => Number(r.id) === Number(this.user.role_id)) || null; },
    scopeOptions() { const options = [{ label: 'Sucursales seleccionadas', value: 'selected' }]; if (this.canGlobalScope) options.push({ label: 'Toda la empresa', value: 'all' }); return options; },
    branchOptions() { return this.branches.map(b => ({ label: b.code ? `${b.name} · ${b.code}` : b.name, value: b.id })); },
    selectedBranchIds() { return (this.user.branch_ids || []).map(Number).filter(Boolean); },
    defaultBranchOptions() { const ids = this.selectedBranchIds; return this.branches.filter(b => ids.includes(Number(b.id))).map(b => ({ label: b.name, value: b.id })); },
    allowedLocationOptions() { const ids = this.selectedBranchIds; return this.inventoryLocations.filter(l => ids.includes(Number(l.branch_id))).map(l => ({ label: `${this.branchName(l.branch_id)} · ${l.name}${l.is_default_sales ? ' · Predeterminada' : ''}`, value: l.id })); },
    defaultLocationOptions() { return this.inventoryLocations.filter(l => Number(l.branch_id) === Number(this.user.default_branch_id)).map(l => ({ label: `${l.name}${l.is_default_sales ? ' · Piso predeterminado' : ''}`, value: l.id })); },
    defaultCashDrawerOptions() {
      const branchId = Number(this.user.default_branch_id || 0); const locationId = Number(this.user.default_inventory_location_id || 0);
      if (!branchId || !locationId) return [];
      return this.cashDrawers.filter(d => Number(d.branch_id) === branchId && Number(d.inventory_location_id) === locationId && !!d.is_active).map(d => ({ label: d.code ? `${d.name} · ${d.code}` : d.name, value: d.id }));
    },
    temporaryBranchOptions() { return (this.operational.branches || []).map(b => ({ label: b.code ? `${b.name} · ${b.code}` : b.name, value: b.id })); },
    temporaryLocationOptions() { return (this.operational.inventory_locations || []).filter(l => Number(l.branch_id) === Number(this.temporary.branch_id)).map(l => ({ label: `${l.name}${l.is_default_sales ? ' · Predeterminada' : ''}`, value: l.id })); },
    temporaryDrawerOptions() { return (this.operational.cash_drawers || []).filter(d => Number(d.branch_id) === Number(this.temporary.branch_id) && (!d.inventory_location_id || Number(d.inventory_location_id) === Number(this.temporary.inventory_location_id))).map(d => ({ label: d.code ? `${d.name} (${d.code})` : d.name, value: d.id })); },
  },
  created() { this.load(); },
  methods: {
    getValidationState({ dirty, validated, valid = null }) { return dirty || validated ? valid : null; },
    makeToast(variant, msg, title) { if (this.$root && this.$root.$bvToast) this.$root.$bvToast.toast(msg, { title, variant, solid: true }); },
    async load() {
      NProgress.start();
      try {
        const { data } = await axios.get(`/organization/user-access/${this.$route.params.id}`, { meta: { skipErrorRedirect: true } });
        this.roles = data.roles || []; this.branches = data.branches || []; this.inventoryLocations = data.inventory_locations || []; this.cashDrawers = data.cash_drawers || []; this.canGlobalScope = !!data.can_global_scope;
        const source = data.user || {};
        this.user = Object.assign({}, this.user, source, { password: '', avatar: null, avatar_name: source.avatar || '', branch_ids: source.branch_ids || [], inventory_location_ids: source.inventory_location_ids || [], record_view: !!source.record_view, statut: Number(source.statut) === 0 ? 0 : 1 });
        await this.loadOperational();
      } catch (e) {
        const data = (e && e.response && e.response.data) || (e && typeof e === 'object' ? e : null);
        this.form_error = (data && (data.message || data.error)) || 'No se pudo cargar el usuario.';
      } finally { this.isLoading = false; NProgress.done(); }
    },
    async loadOperational() {
      try {
        const { data } = await axios.get(`/users/${this.$route.params.id}/operational-assignment`, { meta: { skipErrorRedirect: true } });
        this.operational = Object.assign({ default: {}, effective: null, active_temporary_assignment: null, branches: [], inventory_locations: [], cash_drawers: [] }, data || {}); this.operationalLoaded = true;
      } catch (e) { this.operationalLoaded = false; }
    },
    branchName(id) { const branch = this.branches.find(b => Number(b.id) === Number(id)); return branch ? branch.name : 'Sucursal'; },
    defaultLocationId(branchId) { const branch = this.branches.find(b => Number(b.id) === Number(branchId)); if (branch && branch.default_inventory_location_id) return Number(branch.default_inventory_location_id); const floor = this.inventoryLocations.find(l => Number(l.branch_id) === Number(branchId) && l.is_default_sales); return floor ? Number(floor.id) : null; },
    roleChanged() { if (!this.selectedRole || !this.selectedRole.uses_pos) this.user.default_cash_drawer_id = null; else this.selectSingleDrawerWhenUnambiguous(); },
    scopeChanged() { if (this.user.scope === 'all') { this.user.branch_ids = []; this.user.inventory_location_ids = []; this.user.default_branch_id = null; this.user.default_inventory_location_id = null; this.user.default_cash_drawer_id = null; } },
    branchesChanged() {
      const ids = this.selectedBranchIds;
      this.user.inventory_location_ids = (this.user.inventory_location_ids || []).filter(id => { const location = this.inventoryLocations.find(l => Number(l.id) === Number(id)); return location && ids.includes(Number(location.branch_id)); });
      if (!ids.includes(Number(this.user.default_branch_id))) { this.user.default_branch_id = ids[0] || null; this.defaultBranchChanged(); }
    },
    defaultBranchChanged() {
      const branchId = Number(this.user.default_branch_id || 0); this.user.default_cash_drawer_id = null;
      if (!branchId) { this.user.default_inventory_location_id = null; return; }
      const current = this.inventoryLocations.find(l => Number(l.id) === Number(this.user.default_inventory_location_id));
      if (!current || Number(current.branch_id) !== branchId) { const id = this.defaultLocationId(branchId); this.user.default_inventory_location_id = id; if (id && !(this.user.inventory_location_ids || []).map(Number).includes(id)) this.user.inventory_location_ids.push(id); }
      this.selectSingleDrawerWhenUnambiguous();
    },
    defaultLocationChanged() { const id = Number(this.user.default_inventory_location_id || 0); this.user.default_cash_drawer_id = null; if (id && !(this.user.inventory_location_ids || []).map(Number).includes(id)) this.user.inventory_location_ids.push(id); this.selectSingleDrawerWhenUnambiguous(); },
    selectSingleDrawerWhenUnambiguous() { if (this.selectedRole && this.selectedRole.uses_pos && this.defaultCashDrawerOptions.length === 1) this.user.default_cash_drawer_id = this.defaultCashDrawerOptions[0].value; },
    onFileSelected(e) { this.user.avatar = e.target.files && e.target.files[0] ? e.target.files[0] : null; },
    Submit_User() {
      this.$refs.Edit_User.validate().then(success => {
        if (!success) { this.makeToast('danger', this.$t('Please_fill_the_form_correctly'), this.$t('Failed')); return; }
        if (this.user.password && this.user.password.length < 8) { this.form_error = 'La nueva contraseña debe tener al menos 8 caracteres.'; return; }
        if (this.user.scope !== 'all' && !this.selectedBranchIds.length) { this.form_error = 'Selecciona al menos una sucursal.'; return; }
        if (this.selectedRole && this.selectedRole.requires_cash_drawer && !this.user.default_cash_drawer_id) { this.form_error = 'Este rol necesita una caja física predeterminada para operar POS.'; return; }
        this.Update_User();
      });
    },
    async Update_User() {
      this.SubmitProcessing = true; this.form_error = '';
      const data = new FormData();
      ['firstname','lastname','username','email','phone','password','role_id','statut','scope','default_branch_id','default_inventory_location_id','default_cash_drawer_id'].forEach(key => { const value = this.user[key]; data.append(key, value === null || typeof value === 'undefined' ? '' : value); });
      this.selectedBranchIds.forEach((id, i) => data.append(`branch_ids[${i}]`, id));
      (this.user.inventory_location_ids || []).forEach((id, i) => data.append(`inventory_location_ids[${i}]`, id));
      data.append('record_view', this.user.record_view ? 1 : 0); if (this.user.avatar) data.append('avatar', this.user.avatar); data.append('_method', 'PUT');
      try {
        await axios.post(`/organization/user-access/${this.$route.params.id}`, data, { meta: { skipErrorRedirect: true } }); this.makeToast('success', this.$t('Successfully_Updated'), this.$t('Success')); this.$router.push({ name: 'Users' });
      } catch (e) {
        const response = e && e.response && e.response.data ? e.response.data : (e && typeof e === 'object' ? e : null); const first = response && response.errors ? Object.values(response.errors)[0] : null;
        this.form_error = (Array.isArray(first) ? first[0] : first) || (response && (response.message || response.error)) || 'No se pudo actualizar el usuario.'; this.makeToast('danger', this.form_error, this.$t('Failed'));
      } finally { this.SubmitProcessing = false; }
    },
    effectiveName(kind) { const effective = this.operational.effective || {}; const value = effective[kind]; return value && value.name ? value.name : 'Sin definir'; },
    temporaryBranchChanged() { const branch = (this.operational.branches || []).find(b => Number(b.id) === Number(this.temporary.branch_id)); const locationId = branch && branch.default_inventory_location_id ? Number(branch.default_inventory_location_id) : null; this.temporary.inventory_location_id = locationId; this.temporary.cash_drawer_id = null; },
    temporaryLocationChanged() { this.temporary.cash_drawer_id = null; },
    async saveTemporaryAssignment() {
      if (!this.temporary.branch_id || !this.temporary.inventory_location_id || !this.temporary.reason) { this.temporary_error = 'Selecciona sucursal, ubicación de inventario e indica el motivo.'; return; }
      if (this.selectedRole && this.selectedRole.requires_cash_drawer && !this.temporary.cash_drawer_id) { this.temporary_error = 'Este usuario opera POS y necesita una caja física para la asignación temporal.'; return; }
      this.temporarySaving = true; this.temporary_error = '';
      try {
        await axios.post(`/users/${this.$route.params.id}/temporary-assignment`, { temporary_branch_id: this.temporary.branch_id, temporary_inventory_location_id: this.temporary.inventory_location_id, temporary_cash_drawer_id: this.temporary.cash_drawer_id || null, starts_at: this.temporary.starts_at || null, ends_at: this.temporary.ends_at || null, reason: this.temporary.reason }, { meta: { skipErrorRedirect: true } });
        this.temporary = { branch_id: null, inventory_location_id: null, cash_drawer_id: null, starts_at: '', ends_at: '', reason: '' }; await this.loadOperational(); this.makeToast('success', 'Asignación temporal aplicada.', this.$t('Success'));
      } catch (e) { const response = e && e.response && e.response.data ? e.response.data : (e && typeof e === 'object' ? e : null); const first = response && response.errors ? Object.values(response.errors)[0] : null; this.temporary_error = (Array.isArray(first) ? first[0] : first) || (response && (response.message || response.error)) || 'No se pudo aplicar la asignación temporal.'; }
      finally { this.temporarySaving = false; }
    },
    async endTemporaryAssignment() { const assignment = this.operational.active_temporary_assignment; if (!assignment) return; await axios.post(`/user-operational-assignments/${assignment.id}/end`, {}, { meta: { skipErrorRedirect: true } }); await this.loadOperational(); this.makeToast('success', 'La persona volvió a su configuración operativa habitual.', this.$t('Success')); },
  },
};
</script>
