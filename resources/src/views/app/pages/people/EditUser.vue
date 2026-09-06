<template>
  <div class="px-next pxcfg">
    <px-page-header
      title="Editar usuario"
      subtitle="Modifica identidad, rol y contexto operativo. Para POS, la caja física habitual debe pertenecer a la misma sucursal y ubicación."
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('Users'), href: '#/app/User_Management/Users' }, { label: $t('Edit') }]"
    >
      <template #actions>
        <px-button variant="ghost" size="sm" icon="building-2" @click="goto('/app/organization/branches')">Sucursales y cajas</px-button>
        <px-button variant="ghost" size="sm" icon="shield-check" @click="goto('/app/organization/role-templates')">Roles</px-button>
      </template>
    </px-page-header>

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="10" />
    </div>

    <validation-observer v-else ref="Edit_User">
      <form @submit.prevent="Submit_User" enctype="multipart/form-data">
        <px-card title="Datos de acceso" class="pxcfg__card">
          <div class="pxcfg__grid">
            <validation-provider ref="firstnameProvider" name="Nombre" :rules="{ required: true, min: 2, max: 30 }" v-slot="v">
              <px-field label="Nombre *" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" :value="user.firstname" :invalid="invalid" @input="val => onTextInput('firstname', 'firstnameProvider', val)" /></template>
              </px-field>
            </validation-provider>
            <validation-provider ref="lastnameProvider" name="Apellido" :rules="{ required: true, min: 2, max: 30 }" v-slot="v">
              <px-field label="Apellido *" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" :value="user.lastname" :invalid="invalid" @input="val => onTextInput('lastname', 'lastnameProvider', val)" /></template>
              </px-field>
            </validation-provider>
            <validation-provider ref="usernameProvider" name="Usuario" :rules="{ required: true, min: 3, max: 60 }" v-slot="v">
              <px-field label="Nombre de usuario *" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" :value="user.username" :invalid="invalid" @input="val => onTextInput('username', 'usernameProvider', val)" /></template>
              </px-field>
            </validation-provider>
            <px-field label="Teléfono">
              <template #default="{ id }"><px-input :id="id" v-model="user.phone" /></template>
            </px-field>
            <validation-provider ref="emailProvider" name="Correo" :rules="{ required: true, email: true }" v-slot="v">
              <px-field label="Correo *" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" type="email" :value="user.email" :invalid="invalid" @input="val => onTextInput('email', 'emailProvider', val)" /></template>
              </px-field>
            </validation-provider>
            <px-field label="Nueva contraseña" hint="Déjala vacía para conservar la actual. Si la cambias, usa mínimo 8 caracteres.">
              <template #default="{ id }"><px-input :id="id" type="password" v-model="user.password" /></template>
            </px-field>
            <validation-provider ref="roleProvider" name="Rol" :rules="{ required: true }" v-slot="v">
              <px-field label="Rol *" :error="v.errors[0]">
                <template #default="{ id }">
                  <vs-px :input-id="id" v-model="user.role_id" :reduce="o => o.value" :options="roleOptions" placeholder="Seleccionar rol"
                    @input="onRoleSelected" />
                </template>
              </px-field>
            </validation-provider>
            <px-field label="Estado">
              <template #default="{ id }">
                <vs-px :input-id="id" v-model="user.statut" :reduce="o => o.value" :options="statusOptions" />
              </template>
            </px-field>
            <px-field label="Imagen de usuario" :hint="user.avatar_name ? `Actual: ${user.avatar_name}` : ''">
              <template #default="{ id }"><input :id="id" class="pxcfg__file" @change="onFileSelected" type="file" accept="image/*" /></template>
            </px-field>
          </div>
        </px-card>

        <px-card title="Alcance operativo" class="pxcfg__card">
          <p class="pxcfg__cardnote">El rol determina las acciones. Este bloque limita la sucursal, ubicación y, cuando corresponde, la caja física habitual.</p>
          <div class="pxcfg__grid">
            <px-field label="Tipo de alcance *">
              <template #default="{ id }">
                <vs-px :input-id="id" v-model="user.scope" :reduce="o => o.value" :options="scopeOptions" @input="scopeChanged" />
              </template>
            </px-field>
            <px-field v-if="user.scope === 'selected'" label="Sucursales permitidas *">
              <template #default="{ id }">
                <vs-px :input-id="id" multiple v-model="user.branch_ids" :reduce="o => o.value" :options="branchOptions" placeholder="Seleccionar sucursales" @input="branchesChanged" />
              </template>
            </px-field>
          </div>

          <px-alert v-if="user.scope === 'all'" tone="warning" class="pxcfg__alert">
            Alcance global: podrá consultar las ubicaciones de toda la empresa, siempre sujeto a los permisos del rol.
          </px-alert>

          <template v-if="user.scope !== 'all'">
            <px-field v-if="selectedBranchIds.length" label="Ubicaciones de inventario permitidas"
              hint="No es necesario asignar un CD/almacén para que un usuario trabaje en una sucursal." class="pxcfg__mt">
              <template #default="{ id }">
                <vs-px :input-id="id" multiple v-model="user.inventory_location_ids" :reduce="o => o.value" :options="allowedLocationOptions" placeholder="Seleccionar ubicaciones" />
              </template>
            </px-field>
            <div class="pxcfg__grid pxcfg__mt">
              <px-field label="Sucursal predeterminada *">
                <template #default="{ id }">
                  <vs-px :input-id="id" v-model="user.default_branch_id" :reduce="o => o.value" :options="defaultBranchOptions" placeholder="Seleccionar" @input="defaultBranchChanged" />
                </template>
              </px-field>
              <px-field label="Ubicación predeterminada">
                <template #default="{ id }">
                  <vs-px :input-id="id" v-model="user.default_inventory_location_id" :reduce="o => o.value" :options="defaultLocationOptions" placeholder="Seleccionar" @input="defaultLocationChanged" />
                </template>
              </px-field>
            </div>
          </template>

          <div v-if="selectedRole && selectedRole.uses_pos" class="pxcfg__subcard">
            <px-field :label="selectedRole.requires_cash_drawer ? 'Caja física predeterminada *' : 'Caja física predeterminada'"
              hint="La caja pertenece a la empresa. Cambiar la asignación habitual no elimina sesiones ni historial anteriores.">
              <template #default="{ id }">
                <vs-px :input-id="id" v-model="user.default_cash_drawer_id" :reduce="o => o.value" :options="defaultCashDrawerOptions"
                  :placeholder="defaultCashDrawerOptions.length ? 'Seleccionar caja física' : 'No hay cajas disponibles en esta ubicación'" />
              </template>
            </px-field>
            <px-alert v-if="selectedRole.requires_cash_drawer && !defaultCashDrawerOptions.length" tone="warning" class="pxcfg__alert">
              Este rol necesita una caja física para operar POS. Crea una en la sucursal y ubicación seleccionadas antes de guardar.
              <template #actions>
                <px-button size="sm" variant="secondary" @click="goto('/app/organization/branches')">Administrar cajas</px-button>
              </template>
            </px-alert>
          </div>

          <px-check :modelValue="!!user.record_view" @change="v => user.record_view = v" class="pxcfg__mt">
            Ver registros de otros usuarios dentro de su propio alcance
          </px-check>
          <div class="pxcfg__cardnote">No concede acceso a otras sucursales.</div>
        </px-card>

        <px-card v-if="operationalLoaded" title="Asignación temporal" class="pxcfg__card">
          <template #actions>
            <px-badge v-if="operational.active_temporary_assignment" tone="warning">Temporal activa</px-badge>
          </template>
          <p class="pxcfg__cardnote">Úsala cuando la persona cubra otra sucursal temporalmente. No modifica su sucursal o caja habitual.</p>

          <div class="pxcfg__grid">
            <div class="pxcfg__mini">
              <strong>Configuración habitual</strong>
              <div>Sucursal: {{ operational.default.branch_name || 'Sin definir' }}</div>
              <div>Inventario: {{ operational.default.inventory_location_name || 'Sin definir' }}</div>
              <div>Caja: {{ operational.default.cash_drawer_name || 'Sin definir' }}</div>
            </div>
            <div class="pxcfg__mini">
              <strong>Contexto efectivo ahora</strong>
              <div>Sucursal: {{ effectiveName('branch') }}</div>
              <div>Inventario: {{ effectiveName('inventory_location') }}</div>
              <div>Caja: {{ effectiveName('cash_drawer') }}</div>
              <small class="pxcfg__cardnote">Fuente: {{ operational.effective && operational.effective.source === 'temporary' ? 'Asignación temporal' : 'Configuración habitual' }}</small>
            </div>
          </div>

          <px-alert v-if="operational.active_temporary_assignment" tone="warning" class="pxcfg__alert">
            <strong>{{ operational.active_temporary_assignment.temporary_branch_name }}</strong>
            <div>{{ operational.active_temporary_assignment.temporary_inventory_location_name || 'Sin ubicación' }}<span v-if="operational.active_temporary_assignment.temporary_cash_drawer_name"> · {{ operational.active_temporary_assignment.temporary_cash_drawer_name }}</span></div>
            <small>{{ operational.active_temporary_assignment.reason }}</small>
            <template #actions>
              <px-button v-if="canTemporaryAssignment" size="sm" variant="danger" @click="endTemporaryAssignment">Finalizar asignación</px-button>
            </template>
          </px-alert>

          <template v-if="canTemporaryAssignment">
            <h6 class="pxcfg__subhead">{{ operational.active_temporary_assignment ? 'Reemplazar asignación temporal' : 'Crear asignación temporal' }}</h6>
            <div class="pxcfg__grid pxcfg__grid--3">
              <px-field label="Sucursal temporal *">
                <template #default="{ id }">
                  <vs-px :input-id="id" v-model="temporary.branch_id" :reduce="o => o.value" :options="temporaryBranchOptions" @input="temporaryBranchChanged" />
                </template>
              </px-field>
              <px-field label="Ubicación de inventario *">
                <template #default="{ id }">
                  <vs-px :input-id="id" v-model="temporary.inventory_location_id" :reduce="o => o.value" :options="temporaryLocationOptions" @input="temporaryLocationChanged" />
                </template>
              </px-field>
              <px-field label="Caja física">
                <template #default="{ id }">
                  <vs-px :input-id="id" v-model="temporary.cash_drawer_id" :reduce="o => o.value" :options="temporaryDrawerOptions" placeholder="Necesaria para personal de caja" />
                </template>
              </px-field>
            </div>
            <div class="pxcfg__grid pxcfg__mt">
              <px-field label="Inicio">
                <template #default="{ id }"><px-input :id="id" v-model="temporary.starts_at" type="datetime-local" /></template>
              </px-field>
              <px-field label="Fin">
                <template #default="{ id }"><px-input :id="id" v-model="temporary.ends_at" type="datetime-local" /></template>
              </px-field>
            </div>
            <px-field label="Motivo *" class="pxcfg__mt">
              <template #default="{ id }">
                <px-textarea :id="id" :value="temporary.reason" @input="val => temporary.reason = val.trim ? val.trim() : val" :rows="2" placeholder="Ej. Cobertura de turno en Sucursal Mall" />
              </template>
            </px-field>
            <px-alert v-if="temporary_error" tone="danger" class="pxcfg__alert">{{ temporary_error }}</px-alert>
            <px-button class="pxcfg__mt" variant="secondary" type="button" :disabled="temporarySaving" @click="saveTemporaryAssignment">
              {{ temporarySaving ? 'Guardando…' : 'Guardar asignación temporal' }}
            </px-button>
          </template>
        </px-card>

        <px-alert v-if="form_error" tone="danger" class="pxcfg__alert">{{ form_error }}</px-alert>

        <div class="pxcfg__actions">
          <px-button variant="primary" icon="check" type="submit" :loading="SubmitProcessing" :disabled="SubmitProcessing">
            {{ SubmitProcessing ? 'Guardando…' : 'Guardar cambios' }}
          </px-button>
          <px-button variant="ghost" @click="$router.push({ name: 'Users' })">Cancelar</px-button>
        </div>
      </form>
    </validation-observer>
  </div>
</template>

<script>
import NProgress from 'nprogress';
import { mapGetters } from 'vuex';
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: 'Editar usuario' },
  components: { PxPageHeader, PxButton, PxCard, PxField, PxInput, PxTextarea, PxCheck, PxBadge, PxAlert, "vs-px": VsPx },
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
    goto(path) { this.$router.push(path).catch(() => {}); },
    getValidationState({ dirty, validated, valid = null }) { return dirty || validated ? valid : null; },
    makeToast(variant, msg, title) { if (this.$root && this.$root.$bvToast) this.$root.$bvToast.toast(msg, { title, variant, solid: true }); },
    // user.* is the single source of truth. The model-carrying control sits
    // inside PxField's scoped slot, which VeeValidate 3.4.15 cannot auto-detect,
    // so every required provider is fed its real value explicitly. (No password
    // here — it is optional on edit.)
    providerFieldMap() {
      return {
        firstnameProvider: 'firstname', lastnameProvider: 'lastname', usernameProvider: 'username',
        emailProvider: 'email', roleProvider: 'role_id'
      };
    },
    syncProvidersFromUser() {
      const map = this.providerFieldMap();
      Object.keys(map).forEach(ref => {
        const p = this.$refs[ref];
        if (p && p.syncValue) p.syncValue(this.user[map[ref]]);
      });
    },
    syncValidators() { this.$nextTick(() => this.syncProvidersFromUser()); },
    validateProvider(ref, value) {
      const p = this.$refs[ref];
      if (p && p.validate) p.validate(value);
    },
    onRoleSelected(value) {
      this.user.role_id = value;
      this.roleChanged();
      this.validateProvider('roleProvider', this.user.role_id);
    },
    onTextInput(field, providerRef, value) {
      this.user[field] = value;
      this.validateProvider(providerRef, value);
    },
    accessFieldLabels() {
      return {
        firstnameProvider: 'Nombre', lastnameProvider: 'Apellido', usernameProvider: 'Usuario',
        emailProvider: 'Correo', roleProvider: 'Rol'
      };
    },
    invalidAccessFields() {
      const labels = this.accessFieldLabels();
      return Object.keys(labels).filter(ref => {
        const p = this.$refs[ref];
        return p && p.flags && p.flags.invalid;
      }).map(ref => labels[ref]);
    },
    focusFirstInvalid() {
      const root = this.$refs.Edit_User && this.$refs.Edit_User.$el;
      if (!root) return;
      const el = root.querySelector('.pxn-field.is-invalid input, .pxn-field.is-invalid .vs__search, input[aria-invalid="true"]');
      if (el && typeof el.focus === 'function') el.focus();
    },
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
      } finally { this.isLoading = false; NProgress.done(); this.syncValidators(); }
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
      // Feed every provider its live value before the observer aggregates them —
      // the role select and any field that never emitted an input event.
      this.syncProvidersFromUser();
      this.$refs.Edit_User.validate().then(success => {
        // Group A — access data (VeeValidate). Name the offending fields and
        // focus the first one instead of a blank "complete the form".
        if (!success) {
          const fields = this.invalidAccessFields();
          const message = fields.length
            ? `Revisa estos campos obligatorios: ${fields.join(', ')}.`
            : this.$t('Please_fill_the_form_correctly');
          this.form_error = message;
          this.makeToast('danger', message, this.$t('Failed'));
          this.$nextTick(() => this.focusFirstInvalid());
          return;
        }
        // Group B — operational rules. Specific message per rule; never mixed
        // with the required-field errors above.
        if (this.user.password && this.user.password.length < 8) {
          this.form_error = 'La nueva contraseña debe tener al menos 8 caracteres.';
          this.makeToast('warning', this.form_error, 'Contraseña'); return;
        }
        if (this.user.scope !== 'all' && !this.selectedBranchIds.length) {
          this.form_error = 'Selecciona al menos una sucursal para el alcance operativo.';
          this.makeToast('warning', this.form_error, 'Alcance operativo'); return;
        }
        if (this.user.scope !== 'all' && !this.user.default_branch_id) {
          this.form_error = 'Selecciona la sucursal predeterminada.';
          this.makeToast('warning', this.form_error, 'Alcance operativo'); return;
        }
        if (this.selectedRole && this.selectedRole.requires_cash_drawer && !this.user.default_cash_drawer_id) {
          this.form_error = 'Este rol necesita una caja física predeterminada para operar POS.';
          this.makeToast('warning', this.form_error, 'Alcance operativo'); return;
        }
        this.Update_User();
      });
    },
    async Update_User() {
      this.SubmitProcessing = true; this.form_error = '';
      const data = new FormData();
      ['firstname', 'lastname', 'username', 'email', 'phone', 'password', 'role_id', 'statut', 'scope', 'default_branch_id', 'default_inventory_location_id', 'default_cash_drawer_id'].forEach(key => { const value = this.user[key]; data.append(key, value === null || typeof value === 'undefined' ? '' : value); });
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

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__card { margin-top: var(--pxn-space-5); }
.pxcfg__cardnote { margin: 0 0 var(--pxn-space-2); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxcfg__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
.pxcfg__grid--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
@media (max-width: 900px) { .pxcfg__grid--3 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 640px) { .pxcfg__grid, .pxcfg__grid--3 { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__mt { margin-top: var(--pxn-space-4); }
.pxcfg__file { width: 100%; font: inherit; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); padding: var(--pxn-space-2) 0; }
.pxcfg__alert { margin-top: var(--pxn-space-4); }
.pxcfg__subcard { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); }
.pxcfg__mini { padding: var(--pxn-space-4); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); font-size: var(--pxn-fs-sm); }
.pxcfg__mini strong { display: block; margin-bottom: var(--pxn-space-2); }
.pxcfg__subhead { margin: var(--pxn-space-5) 0 var(--pxn-space-3); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); }
.pxcfg__actions { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-5); }
</style>
