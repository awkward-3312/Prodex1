<template>
  <div class="main-content">
    <breadcumb :page="isEdit ? 'Editar rol' : 'Crear rol'" folder="Usuarios y accesos"/>

    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>
    <template v-else>
      <b-card class="mb-3 border-0 shadow-sm">
        <div class="d-flex flex-wrap justify-content-between align-items-start">
          <div>
            <h4 class="mb-1">{{ isEdit ? 'Editar rol y permisos' : 'Nuevo rol y permisos' }}</h4>
            <p class="text-muted mb-0">El rol define <strong>qué puede hacer</strong> una persona. Las sucursales, ubicaciones y cajas se asignan al usuario y definen <strong>dónde puede hacerlo</strong>.</p>
          </div>
          <b-button variant="outline-secondary" size="sm" @click="$router.push('/app/User_Management/permissions')">Volver a roles</b-button>
        </div>
      </b-card>

      <b-card class="mb-3">
        <h5 class="mb-3">Información del rol</h5>
        <b-row>
          <b-col md="6"><b-form-group label="Nombre del rol *"><b-form-input v-model.trim="role.name" maxlength="120"/></b-form-group></b-col>
          <b-col md="6"><b-form-group label="Descripción"><b-form-input v-model.trim="role.description" maxlength="500"/></b-form-group></b-col>
        </b-row>
        <b-form-group v-if="!isEdit && templates.length" label="Comenzar desde una plantilla">
          <v-select v-model="selectedTemplate" :options="templateOptions" :reduce="o => o.value" placeholder="Sin plantilla · configurar manualmente" @input="applyTemplate"/>
          <small class="text-muted">Las plantillas son puntos de partida seguros. Puedes ajustar cualquier permiso antes de guardar.</small>
        </b-form-group>
      </b-card>

      <b-card class="mb-3 permission-toolbar">
        <div class="d-flex flex-wrap align-items-center justify-content-between">
          <div>
            <strong>{{ permissions.length }} permisos seleccionados</strong>
            <div class="text-muted text-12">{{ selectedSensitive.length }} sensibles · {{ catalogCount }} disponibles</div>
          </div>
          <b-form-input v-model.trim="search" class="permission-search" placeholder="Buscar por función, por ejemplo: ventas, pagos, inventario..."/>
        </div>
      </b-card>

      <b-alert v-if="selectedSensitive.length" show variant="warning" class="mb-3">
        <strong>Permisos sensibles activos:</strong>
        {{ selectedSensitive.map(p => p.label).join(', ') }}.
        Estas acciones pueden afectar dinero, inventario, usuarios o configuración. Concédelas solo cuando el puesto realmente las necesite.
      </b-alert>

      <div v-if="error" class="alert alert-danger">{{ error }}</div>

      <b-card v-for="group in filteredGroups" :key="group.key" class="mb-3 module-card">
        <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
          <div class="module-heading">
            <h5 class="mb-1">{{ group.label }}</h5>
            <div v-if="group.description" class="text-muted module-description">{{ group.description }}</div>
            <small class="text-muted">{{ selectedIn(group) }}/{{ group.permissions.length }} seleccionados</small>
          </div>
          <div class="quick-access">
            <div class="quick-access-label">Configuración rápida</div>
            <div class="module-actions">
              <b-button size="sm" variant="outline-secondary" title="Permite consultar información sin modificarla" v-b-tooltip.hover @click="applyPreset(group, 'read_only')">Solo lectura</b-button>
              <b-button size="sm" variant="outline-secondary" title="Permite consultar y registrar operaciones habituales sin permisos sensibles" v-b-tooltip.hover @click="applyPreset(group, 'operator')">Operador</b-button>
              <b-button size="sm" variant="outline-secondary" title="Activa todas las operaciones no sensibles disponibles en este módulo" v-b-tooltip.hover @click="applyPreset(group, 'manager')">Acceso completo</b-button>
              <b-button size="sm" variant="outline-danger" title="Quita todos los permisos seleccionados de este módulo" v-b-tooltip.hover @click="clearGroup(group)">Limpiar</b-button>
            </div>
            <small class="text-muted d-block mt-1">Puedes usar un nivel rápido y luego ajustar permisos individualmente.</small>
          </div>
        </div>

        <div class="permission-matrix">
          <label v-for="permission in group.permissions" :key="permission.name" class="permission-row" :class="{ sensitive: permission.sensitive }">
            <input type="checkbox" :checked="hasPermission(permission.name)" @change="togglePermission(permission, $event.target.checked)">
            <span class="permission-copy">
              <span class="permission-title">
                {{ permission.label }}
                <b-badge v-if="permission.sensitive" variant="warning" class="ml-1" title="Esta acción puede afectar información sensible o procesos críticos" v-b-tooltip.hover>Sensible</b-badge>
              </span>
              <small class="permission-description">{{ permission.description }}</small>
              <small v-if="permission.dependency_labels && permission.dependency_labels.length" class="dependency">
                <strong>También activará:</strong> {{ permission.dependency_labels.join(', ') }}
              </small>
            </span>
            <span class="action-badge">{{ actionLabel(permission.action) }}</span>
          </label>
        </div>
      </b-card>

      <b-card v-if="!filteredGroups.length" class="text-center py-5 text-muted mb-3">No hay permisos que coincidan con la búsqueda.</b-card>

      <b-card class="mb-4 save-card">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
          <div>
            <strong>Resumen antes de guardar</strong>
            <div class="text-muted text-12">{{ permissions.length }} permisos · {{ selectedSensitive.length }} sensibles</div>
          </div>
          <div>
            <b-button variant="secondary" class="mr-2" @click="$router.push('/app/User_Management/permissions')">Cancelar</b-button>
            <b-button variant="primary" :disabled="saving || !role.name" @click="save">{{ saving ? 'Guardando…' : (isEdit ? 'Guardar cambios' : 'Crear rol') }}</b-button>
          </div>
        </div>
      </b-card>
    </template>
  </div>
</template>

<script>
export default {
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

<style scoped>
.permission-toolbar, .module-card, .save-card { border: 1px solid #edf0f4; border-radius: 12px; }
.permission-search { max-width: 420px; }
.module-heading { max-width: 52%; }
.module-description { font-size: 13px; margin-bottom: 2px; }
.quick-access { text-align: right; }
.quick-access-label { font-size: 11px; font-weight: 700; color: #667085; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 5px; }
.module-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 6px; }
.permission-matrix { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.permission-row { display: flex; align-items: flex-start; gap: 10px; border: 1px solid #e7eaf0; border-radius: 10px; padding: 12px; margin: 0; cursor: pointer; background: #fff; min-height: 92px; }
.permission-row:hover { border-color: #cdd5df; box-shadow: 0 1px 3px rgba(16, 24, 40, .05); }
.permission-row.sensitive { border-color: #f3d7a3; background: #fffaf0; }
.permission-row input { margin-top: 4px; }
.permission-copy { display: flex; flex: 1; min-width: 0; flex-direction: column; }
.permission-title { font-weight: 600; color: #344054; }
.permission-copy small { overflow-wrap: anywhere; }
.permission-description { color: #667085; margin-top: 4px; line-height: 1.35; }
.permission-copy .dependency { color: #475467; margin-top: 6px; }
.action-badge { background: #f2f4f7; border-radius: 999px; padding: 2px 8px; font-size: 10px; color: #667085; white-space: nowrap; }
.text-12 { font-size: 12px; }
@media (max-width: 991px) {
  .permission-matrix { grid-template-columns: 1fr; }
  .permission-search { max-width: none; width: 100%; margin-top: 10px; }
  .module-heading { max-width: 100%; width: 100%; }
  .quick-access { width: 100%; text-align: left; margin-top: 12px; }
  .module-actions { justify-content: flex-start; }
}
</style>
