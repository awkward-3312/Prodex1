<template>
  <div class="px-next pxcfg pxrole">
    <px-page-header
      :title="isEdit ? 'Editar rol y permisos' : 'Nuevo rol y permisos'"
      subtitle="El rol define qué puede hacer una persona. Las sucursales, ubicaciones y cajas se asignan al usuario y definen dónde puede hacerlo."
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: 'Roles y permisos', href: '#/app/User_Management/permissions' }, { label: isEdit ? 'Editar rol' : 'Crear rol' }]"
    >
      <template #actions>
        <px-button variant="ghost" size="sm" icon="arrow-left" @click="$router.push('/app/User_Management/permissions')">Volver a roles</px-button>
      </template>
    </px-page-header>

    <div v-if="loading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="10" />
    </div>

    <template v-else>
      <px-card title="Información del rol" class="pxcfg__card">
        <div class="pxcfg__grid">
          <px-field label="Nombre del rol *">
            <template #default="{ id }"><px-input :id="id" :value="role.name" @input="v => role.name = trimVal(v)" maxlength="120" /></template>
          </px-field>
          <px-field label="Descripción">
            <template #default="{ id }"><px-input :id="id" :value="role.description" @input="v => role.description = trimVal(v)" maxlength="500" /></template>
          </px-field>
        </div>
        <px-field v-if="!isEdit && templates.length" label="Comenzar desde una plantilla"
          hint="Las plantillas son puntos de partida seguros. Puedes ajustar cualquier permiso antes de guardar." class="pxcfg__mt">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="selectedTemplate" :options="templateOptions" :reduce="o => o.value"
              placeholder="Sin plantilla · configurar manualmente" @input="applyTemplate" />
          </template>
        </px-field>
      </px-card>

      <px-card class="pxcfg__card pxrole__toolbar">
        <div class="pxrole__toolbar-row">
          <div>
            <strong>{{ permissions.length }} permisos seleccionados</strong>
            <div class="pxcfg__cardnote">{{ selectedSensitive.length }} sensibles · {{ catalogCount }} disponibles</div>
          </div>
          <px-input class="pxrole__search" :value="search" @input="v => search = trimVal(v)"
            placeholder="Buscar por función, por ejemplo: ventas, pagos, inventario..." icon-lead="search" />
        </div>
      </px-card>

      <px-alert v-if="selectedSensitive.length" tone="warning" title="Permisos sensibles activos:" class="pxcfg__alert">
        {{ selectedSensitive.map(p => p.label).join(', ') }}.
        Estas acciones pueden afectar dinero, inventario, usuarios o configuración. Concédelas solo cuando el puesto realmente las necesite.
      </px-alert>

      <px-alert v-if="error" tone="danger" class="pxcfg__alert">{{ error }}</px-alert>

      <px-card v-for="group in filteredGroups" :key="group.key" class="pxcfg__card">
        <div class="pxrole__modhead">
          <div class="pxrole__modheading">
            <h5>{{ group.label }}</h5>
            <div v-if="group.description" class="pxcfg__cardnote">{{ group.description }}</div>
            <small class="pxcfg__cardnote">{{ selectedIn(group) }}/{{ group.permissions.length }} seleccionados</small>
          </div>
          <div class="pxrole__quick">
            <div class="pxrole__quick-label">Configuración rápida</div>
            <div class="pxrole__quick-actions">
              <px-button size="sm" variant="secondary" @click="applyPreset(group, 'read_only')">Solo lectura</px-button>
              <px-button size="sm" variant="secondary" @click="applyPreset(group, 'operator')">Operador</px-button>
              <px-button size="sm" variant="secondary" @click="applyPreset(group, 'manager')">Acceso completo</px-button>
              <px-button size="sm" variant="ghost" class="pxcfg__del" @click="clearGroup(group)">Limpiar</px-button>
            </div>
            <small class="pxcfg__cardnote">Puedes usar un nivel rápido y luego ajustar permisos individualmente.</small>
          </div>
        </div>

        <div class="pxrole__matrix">
          <label
            v-for="permission in group.permissions"
            :key="permission.name"
            class="pxrole__perm"
            :class="{ 'is-sensitive': permission.sensitive }"
          >
            <input type="checkbox" class="pxrole__perm-cb" :checked="hasPermission(permission.name)" @change="togglePermission(permission, $event.target.checked)" />
            <span class="pxrole__perm-copy">
              <span class="pxrole__perm-title">
                {{ permission.label }}
                <px-badge v-if="permission.sensitive" tone="warning" class="pxrole__perm-badge">Sensible</px-badge>
              </span>
              <small class="pxrole__perm-desc">{{ permission.description }}</small>
              <small v-if="permission.dependency_labels && permission.dependency_labels.length" class="pxrole__perm-dep">
                <strong>También activará:</strong> {{ permission.dependency_labels.join(', ') }}
              </small>
            </span>
            <span class="pxrole__perm-action">{{ actionLabel(permission.action) }}</span>
          </label>
        </div>
      </px-card>

      <px-card v-if="!filteredGroups.length" class="pxcfg__card">
        <px-empty-state icon="search" title="Sin coincidencias" description="No hay permisos que coincidan con la búsqueda." />
      </px-card>

      <px-card class="pxcfg__card">
        <div class="pxrole__save">
          <div>
            <strong>Resumen antes de guardar</strong>
            <div class="pxcfg__cardnote">{{ permissions.length }} permisos · {{ selectedSensitive.length }} sensibles</div>
          </div>
          <div class="pxrole__save-btns">
            <px-button variant="ghost" @click="$router.push('/app/User_Management/permissions')">Cancelar</px-button>
            <px-button variant="primary" icon="check" :disabled="saving || !role.name" @click="save">
              {{ saving ? 'Guardando…' : (isEdit ? 'Guardar cambios' : 'Crear rol') }}
            </px-button>
          </div>
        </div>
      </px-card>
    </template>
  </div>
</template>

<script>
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  components: { PxPageHeader, PxButton, PxCard, PxField, PxInput, PxBadge, PxAlert, PxEmptyState, "vs-px": VsPx },
  props: {
    mode: { type: String, default: 'create' },
    roleId: { type: [String, Number], default: null },
  },
  data() {
    return {
      loading: true, saving: false, error: '', search: '', groups: [], templates: [], selectedTemplate: null,
      role: { name: '', description: '' }, permissions: [],
    };
  },
  computed: {
    isEdit() { return this.mode === 'edit'; },
    resolvedRoleId() { return this.roleId || this.$route.params.id; },
    templateOptions() { return this.templates.map(t => ({ label: `${t.name} — ${t.description}`, value: t.key })); },
    catalogCount() { return this.groups.reduce((n, g) => n + g.permissions.length, 0); },
    allPermissions() { return this.groups.reduce((all, g) => all.concat(g.permissions), []); },
    selectedSensitive() { return this.allPermissions.filter(p => p.sensitive && this.hasPermission(p.name)); },
    filteredGroups() {
      const q = this.search.toLowerCase();
      if (!q) return this.groups;
      return this.groups.map(group => ({
        ...group,
        permissions: group.permissions.filter(p =>
          group.label.toLowerCase().includes(q) ||
          (group.description || '').toLowerCase().includes(q) ||
          p.label.toLowerCase().includes(q) ||
          (p.description || '').toLowerCase().includes(q) ||
          p.name.toLowerCase().includes(q)
        ),
      })).filter(group => group.permissions.length);
    },
  },
  created() { this.load(); },
  methods: {
    trimVal(v) { return typeof v === 'string' ? v.trim() : v; },
    async load() {
      this.loading = true; this.error = '';
      try {
        const requests = [
          axios.get('/organization/permission-catalog', { meta: { skipErrorRedirect: true } }),
          axios.get('/organization/role-permission-templates', { meta: { skipErrorRedirect: true } }).catch(() => ({ data: { templates: [] } })),
        ];
        if (this.isEdit) requests.push(axios.get(`/roles/${this.resolvedRoleId}/edit`, { meta: { skipErrorRedirect: true } }));
        const responses = await Promise.all(requests);
        this.groups = responses[0].data.groups || [];
        this.templates = responses[1].data.templates || [];
        if (this.isEdit) {
          this.role = responses[2].data.role || this.role;
          this.permissions = responses[2].data.permissions || [];
        }
      } catch (e) {
        this.error = this.errorMessage(e, 'No se pudo cargar el catálogo de permisos.');
      } finally { this.loading = false; }
    },
    hasPermission(name) { return this.permissions.includes(name); },
    selectedIn(group) { return group.permissions.filter(p => this.hasPermission(p.name)).length; },
    togglePermission(permission, checked) {
      if (checked) {
        this.addPermission(permission.name);
        (permission.dependencies || []).forEach(this.addPermission);
      } else {
        this.permissions = this.permissions.filter(name => name !== permission.name);
      }
    },
    addPermission(name) { if (!this.permissions.includes(name)) this.permissions.push(name); },
    clearGroup(group) {
      const names = new Set(group.permissions.map(p => p.name));
      this.permissions = this.permissions.filter(name => !names.has(name));
    },
    applyPreset(group, preset) {
      this.clearGroup(group);
      group.permissions.forEach(permission => {
        let include = false;
        if (preset === 'read_only') include = permission.action === 'view';
        if (preset === 'operator') include = !permission.sensitive && ['view', 'create', 'update'].includes(permission.action);
        if (preset === 'manager') include = !permission.sensitive;
        if (include) this.togglePermission(permission, true);
      });
    },
    applyTemplate(key) {
      const template = this.templates.find(t => t.key === key);
      if (!template) return;
      this.role.name = template.name;
      this.role.description = template.description;
      this.permissions = [...template.permissions];
      this.allPermissions.filter(p => this.hasPermission(p.name)).forEach(p => (p.dependencies || []).forEach(this.addPermission));
    },
    actionLabel(action) {
      return { view: 'Ver', create: 'Crear', update: 'Editar', delete: 'Eliminar', special: 'Especial' }[action] || action;
    },
    async save() {
      if (!this.role.name) return;
      this.saving = true; this.error = '';
      const payload = { role: this.role, permissions: this.permissions };
      try {
        if (this.isEdit) await axios.put(`/roles/${this.resolvedRoleId}`, payload, { meta: { skipErrorRedirect: true } });
        else await axios.post('/roles', payload, { meta: { skipErrorRedirect: true } });
        this.$root.$bvToast.toast(this.isEdit ? 'Rol actualizado correctamente.' : 'Rol creado correctamente.', { title: 'Éxito', variant: 'success', solid: true });
        this.$router.push('/app/User_Management/permissions');
      } catch (e) {
        this.error = this.errorMessage(e, 'No se pudo guardar el rol.');
      } finally { this.saving = false; }
    },
    errorMessage(e, fallback) {
      const data = e && e.response && e.response.data;
      if (data && data.errors) {
        const first = Object.values(data.errors)[0];
        return Array.isArray(first) ? first[0] : first;
      }
      return (data && (data.message || data.error)) || fallback;
    },
  },
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__card { margin-top: var(--pxn-space-5); }
.pxcfg__cardnote { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxcfg__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
@media (max-width: 640px) { .pxcfg__grid { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__mt { margin-top: var(--pxn-space-4); }
.pxcfg__alert { margin-top: var(--pxn-space-4); }
.pxcfg__del ::v-deep .pxn-btn__label { color: var(--pxn-danger); }

.pxrole__toolbar-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--pxn-space-4); }
.pxrole__search { max-width: 420px; width: 100%; }
@media (max-width: 720px) { .pxrole__search { max-width: none; } }
.pxrole__modhead { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: var(--pxn-space-5); margin-bottom: var(--pxn-space-4); }
.pxrole__modheading { max-width: 52%; }
.pxrole__modheading h5 { margin: 0 0 var(--pxn-space-1); font-size: var(--pxn-fs-md); font-weight: var(--pxn-fw-semibold); }
@media (max-width: 900px) { .pxrole__modheading { max-width: 100%; } }
.pxrole__quick { text-align: right; }
.pxrole__quick-label { font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-bold); color: var(--pxn-ink-3); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: var(--pxn-space-2); }
.pxrole__quick-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: var(--pxn-space-2); }
@media (max-width: 900px) { .pxrole__quick { text-align: left; width: 100%; } .pxrole__quick-actions { justify-content: flex-start; } }
.pxrole__matrix { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-3); }
@media (max-width: 991px) { .pxrole__matrix { grid-template-columns: minmax(0, 1fr); } }
.pxrole__perm { display: flex; align-items: flex-start; gap: var(--pxn-space-4); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); padding: var(--pxn-space-4); margin: 0; cursor: pointer; background: var(--pxn-surface); transition: border-color 120ms, background 120ms; }
.pxrole__perm:hover { border-color: var(--pxn-border-strong, var(--pxn-border)); background: var(--pxn-surface-2); }
.pxrole__perm.is-sensitive { border-color: var(--pxn-warning); background: color-mix(in srgb, var(--pxn-warning) 8%, var(--pxn-surface)); }
.pxrole__perm-cb { margin-top: 3px; accent-color: var(--pxn-primary); width: 16px; height: 16px; flex: none; }
.pxrole__perm-copy { display: flex; flex: 1; min-width: 0; flex-direction: column; }
.pxrole__perm-title { font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxrole__perm-badge { margin-left: var(--pxn-space-2); }
.pxrole__perm-desc { color: var(--pxn-ink-3); margin-top: var(--pxn-space-1); line-height: 1.35; overflow-wrap: anywhere; }
.pxrole__perm-dep { color: var(--pxn-ink-2); margin-top: var(--pxn-space-2); overflow-wrap: anywhere; }
.pxrole__perm-action { background: var(--pxn-surface-2); border-radius: 999px; padding: 2px var(--pxn-space-3); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); white-space: nowrap; align-self: flex-start; }
.pxrole__save { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: var(--pxn-space-4); }
.pxrole__save-btns { display: flex; gap: var(--pxn-space-3); }
</style>
