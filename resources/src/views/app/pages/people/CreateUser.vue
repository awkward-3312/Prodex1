<template>
  <div class="px-next pxcfg">
    <px-page-header
      title="Crear usuario"
      subtitle="El rol define qué puede hacer. La sucursal, ubicación y caja física definen dónde opera cuando utiliza POS."
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('Users'), href: '#/app/User_Management/Users' }, { label: $t('Add') }]"
    >
      <template #actions>
        <px-button variant="ghost" size="sm" icon="building-2" @click="goto('/app/organization/branches')">Sucursales y cajas</px-button>
        <px-button variant="ghost" size="sm" icon="shield-check" @click="goto('/app/organization/role-templates')">Plantillas de roles</px-button>
      </template>
    </px-page-header>

    <validation-observer ref="Create_User">
      <form @submit.prevent="Submit_User" enctype="multipart/form-data">
        <px-card title="Datos de acceso" class="pxcfg__card">
          <div class="pxcfg__grid">
            <validation-provider ref="firstnameProvider" name="Nombre" :rules="{ required: true, min: 2, max: 30 }" v-slot="v">
              <px-field label="Nombre *" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" v-model="user.firstname" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>
            <validation-provider ref="lastnameProvider" name="Apellido" :rules="{ required: true, min: 2, max: 30 }" v-slot="v">
              <px-field label="Apellido *" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" v-model="user.lastname" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>
            <validation-provider ref="usernameProvider" name="Usuario" :rules="{ required: true, min: 3, max: 60 }" v-slot="v">
              <px-field label="Nombre de usuario *" :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" v-model="user.username" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>
            <px-field label="Teléfono">
              <template #default="{ id }"><px-input :id="id" v-model="user.phone" /></template>
            </px-field>
            <validation-provider ref="emailProvider" name="Correo" :rules="{ required: true, email: true }" v-slot="v">
              <px-field label="Correo *" :error="v.errors[0] || email_exist">
                <template #default="{ id, invalid }"><px-input :id="id" type="email" v-model="user.email" :invalid="invalid || !!email_exist" @input="v.validate" /></template>
              </px-field>
            </validation-provider>
            <validation-provider ref="passwordProvider" name="Contraseña" :rules="{ required: true, min: 8 }" v-slot="v">
              <px-field label="Contraseña temporal *" hint="Mínimo 8 caracteres." :error="v.errors[0]">
                <template #default="{ id, invalid }"><px-input :id="id" type="password" v-model="user.password" :invalid="invalid" @input="v.validate" /></template>
              </px-field>
            </validation-provider>
            <validation-provider ref="roleProvider" name="Rol" :rules="{ required: true }" v-slot="v">
              <px-field label="Rol *" hint="Los permisos se administran desde Usuarios y accesos → Roles y permisos." :error="v.errors[0]">
                <template #default="{ id }">
                  <vs-px :input-id="id" v-model="user.role_id" :reduce="o => o.value" :options="roleOptions" placeholder="Seleccionar rol"
                    @input="() => { roleChanged(); if ($refs.roleProvider) $refs.roleProvider.validate(); }" />
                </template>
              </px-field>
            </validation-provider>
            <px-field label="Imagen de usuario">
              <template #default="{ id }"><input :id="id" class="pxcfg__file" @change="onFileSelected" type="file" accept="image/*" /></template>
            </px-field>
          </div>
        </px-card>

        <px-card title="Alcance operativo" class="pxcfg__card">
          <p class="pxcfg__cardnote">Selecciona las sucursales en las que esta cuenta puede trabajar. Los almacenes/CD no representan la ubicación laboral del usuario.</p>
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
            Esta cuenta tendrá alcance organizacional global. El rol seguirá limitando las acciones que puede ejecutar.
          </px-alert>

          <template v-if="user.scope !== 'all'">
            <px-field v-if="selectedBranchIds.length" label="Ubicaciones de inventario permitidas"
              hint="Cajero: normalmente Piso de venta. Otros puestos pueden incluir más ubicaciones según el rol." class="pxcfg__mt">
              <template #default="{ id }">
                <vs-px :input-id="id" multiple v-model="user.inventory_location_ids" :reduce="o => o.value" :options="allowedLocationOptions" placeholder="Piso de venta, Bodega, Cuarentena…" />
              </template>
            </px-field>
            <div class="pxcfg__grid pxcfg__mt">
              <px-field label="Sucursal predeterminada *">
                <template #default="{ id }">
                  <vs-px :input-id="id" v-model="user.default_branch_id" :reduce="o => o.value" :options="defaultBranchOptions" placeholder="Seleccionar" @input="defaultBranchChanged" />
                </template>
              </px-field>
              <px-field label="Ubicación predeterminada de inventario" hint="Para un cajero normalmente será el Piso de venta de su sucursal.">
                <template #default="{ id }">
                  <vs-px :input-id="id" v-model="user.default_inventory_location_id" :reduce="o => o.value" :options="defaultLocationOptions" placeholder="Seleccionar" @input="defaultLocationChanged" />
                </template>
              </px-field>
            </div>
          </template>

          <div v-if="selectedRole && selectedRole.uses_pos" class="pxcfg__subcard">
            <px-field :label="selectedRole.requires_cash_drawer ? 'Caja física predeterminada *' : 'Caja física predeterminada'"
              hint="La caja pertenece a la empresa y a la ubicación. Esta selección solo define la caja habitual de este usuario.">
              <template #default="{ id }">
                <vs-px :input-id="id" v-model="user.default_cash_drawer_id" :reduce="o => o.value" :options="defaultCashDrawerOptions"
                  :placeholder="defaultCashDrawerOptions.length ? 'Seleccionar caja física' : 'No hay cajas disponibles en esta ubicación'" />
              </template>
            </px-field>
            <px-alert v-if="selectedRole.requires_cash_drawer && !defaultCashDrawerOptions.length" tone="warning" class="pxcfg__alert">
              Este rol necesita una caja física para operar POS. Créala primero en la sucursal y ubicación seleccionadas.
              <template #actions>
                <px-button size="sm" variant="secondary" @click="goto('/app/organization/branches')">Administrar cajas</px-button>
              </template>
            </px-alert>
          </div>

          <px-check :modelValue="!!user.record_view" @change="v => user.record_view = v" class="pxcfg__mt">
            Ver registros de otros usuarios dentro de su propio alcance
          </px-check>
          <div class="pxcfg__cardnote">No amplía sucursales ni ubicaciones.</div>
        </px-card>

        <px-alert v-if="form_errors.length" tone="danger" title="No se pudo crear el usuario:" class="pxcfg__alert">
          <ul class="pxcfg__errlist"><li v-for="(message, index) in form_errors" :key="index">{{ message }}</li></ul>
        </px-alert>

        <div class="pxcfg__actions">
          <px-button variant="primary" icon="check" type="submit" :loading="SubmitProcessing" :disabled="SubmitProcessing" @click="Submit_User">
            {{ SubmitProcessing ? 'Guardando…' : 'Crear usuario' }}
          </px-button>
          <px-button variant="ghost" @click="$router.push({ name: 'Users' })">Cancelar</px-button>
        </div>
      </form>
    </validation-observer>
  </div>
</template>

<script>
import NProgress from 'nprogress';
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: 'Crear usuario' },
  components: { PxPageHeader, PxButton, PxCard, PxField, PxInput, PxCheck, PxAlert, "vs-px": VsPx },
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
    goto(path) { this.$router.push(path).catch(() => {}); },
    getValidationState({ dirty, validated, valid = null }) { return dirty || validated ? valid : null; },
    makeToast(variant, msg, title) { if (this.$root && this.$root.$bvToast) this.$root.$bvToast.toast(msg, { title, variant, solid: true }); },
    syncValidators() {
      this.$nextTick(() => {
        const map = {
          firstnameProvider: 'firstname', lastnameProvider: 'lastname', usernameProvider: 'username',
          emailProvider: 'email', passwordProvider: 'password', roleProvider: 'role_id'
        };
        Object.keys(map).forEach(ref => {
          const p = this.$refs[ref];
          if (p && p.syncValue) p.syncValue(this.user[map[ref]]);
        });
      });
    },
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

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__card { margin-top: var(--pxn-space-5); }
.pxcfg__cardnote { margin: 0 0 var(--pxn-space-2); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxcfg__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
@media (max-width: 640px) { .pxcfg__grid { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__mt { margin-top: var(--pxn-space-4); }
.pxcfg__file { width: 100%; font: inherit; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); padding: var(--pxn-space-2) 0; }
.pxcfg__alert { margin-top: var(--pxn-space-4); }
.pxcfg__errlist { margin: 0; padding-left: var(--pxn-space-6); }
.pxcfg__subcard { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); }
.pxcfg__actions { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-5); }
</style>
