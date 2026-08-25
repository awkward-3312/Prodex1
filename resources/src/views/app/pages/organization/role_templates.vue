<template>
  <div class="main-content">
    <breadcumb page="Plantillas de roles" folder="Usuarios y accesos"/>

    <b-card class="mb-3 border-0 shadow-sm">
      <div class="d-flex flex-wrap align-items-start justify-content-between">
        <div>
          <h4 class="mb-1">Roles operativos recomendados</h4>
          <p class="text-muted mb-0">Las plantillas ahora se aplican directamente desde el editor de roles. Son puntos de partida: el rol define qué puede hacer y el usuario define dónde puede hacerlo.</p>
        </div>
        <b-button variant="primary" class="mt-2 mt-md-0" @click="$router.push('/app/User_Management/permissions/store')">
          <lucide-icon name="shield-plus" class="mr-1"/> Crear rol
        </b-button>
      </div>
    </b-card>

    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>
    <div v-else-if="error" class="alert alert-danger">{{ error }}</div>
    <b-row v-else>
      <b-col v-for="template in templates" :key="template.key" lg="4" md="6" sm="12" class="mb-3">
        <b-card class="h-100 role-template-card">
          <h5 class="mb-1">{{ template.name }}</h5>
          <p class="text-muted text-13 mb-3">{{ template.description }}</p>
          <div class="permission-preview mb-3">
            <span v-for="permission in template.permissions.slice(0, 6)" :key="permission" class="permission-pill">{{ friendly(permission) }}</span>
            <span v-if="template.permissions.length > 6" class="permission-pill muted">+{{ template.permissions.length - 6 }}</span>
          </div>
          <small class="text-muted">{{ template.permissions.length }} permisos sugeridos</small>
        </b-card>
      </b-col>
    </b-row>
  </div>
</template>

<script>
export default {
  metaInfo: { title: 'Plantillas de roles' },
  data() { return { loading: true, error: '', templates: [] }; },
  created() { this.load(); },
  methods: {
    async load() {
      try {
        const { data } = await axios.get('/organization/role-permission-templates', { meta: { skipErrorRedirect: true } });
        this.templates = data.templates || [];
      } catch (e) {
        const data = e && e.response && e.response.data;
        this.error = (data && data.message) || 'No se pudieron cargar las plantillas.';
      } finally { this.loading = false; }
    },
    friendly(name) { return name.replace(/_/g, ' '); },
  },
};
</script>

<style scoped>
.role-template-card { border: 1px solid #edf0f4; border-radius: 12px; }
.permission-preview { display: flex; flex-wrap: wrap; gap: 5px; }
.permission-pill { background: #eef2ff; color: #4f46e5; border-radius: 999px; padding: 3px 8px; font-size: 11px; }
.permission-pill.muted { background: #f3f4f6; color: #667085; }
.text-13 { font-size: 13px; }
</style>
