<template>
  <div class="main-content">
    <breadcumb page="Puestos laborales" :folder="$t('hrm')"/>

    <b-card class="mb-3">
      <div class="d-flex flex-wrap justify-content-between align-items-start">
        <div>
          <h4 class="mb-1">Puestos laborales</h4>
          <p class="text-muted mb-0">Usa una plantilla común o crea un puesto propio. El puesto puede sugerir un rol, pero nunca concede permisos automáticamente.</p>
        </div>
        <b-button variant="primary" class="mt-2 mt-md-0" @click="openCreate"><lucide-icon name="plus" class="mr-1"/> Nuevo puesto</b-button>
      </div>
    </b-card>

    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-card v-else>
      <div class="d-flex flex-wrap justify-content-between mb-3">
        <b-form-input v-model="search" placeholder="Buscar puesto" style="max-width:360px" @input="debouncedLoad"/>
        <small class="text-muted mt-2 mt-md-0">{{ totalRows }} puesto(s)</small>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th>Puesto</th><th>Empresa</th><th>Departamento</th><th>Tipo</th><th>Rol sugerido</th><th class="text-right">Acciones</th></tr></thead>
          <tbody>
            <tr v-for="row in positions" :key="row.id">
              <td><strong>{{ row.designation }}</strong><div v-if="row.description" class="text-muted text-11">{{ row.description }}</div></td>
              <td>{{ row.company_name || '—' }}</td>
              <td>{{ row.department_name || '—' }}</td>
              <td><span class="badge" :class="row.is_system_default ? 'badge-info' : 'badge-light'">{{ row.is_system_default ? 'Plantilla PRODEX' : 'Personalizado' }}</span></td>
              <td>{{ row.suggested_role_key || '—' }}</td>
              <td class="text-right">
                <a class="cursor-pointer mr-2" @click="openEdit(row)" title="Editar"><lucide-icon name="pencil" class="text-success text-20"/></a>
                <a class="cursor-pointer" @click="remove(row)" title="Desactivar"><lucide-icon name="archive" class="text-danger text-20"/></a>
              </td>
            </tr>
            <tr v-if="!positions.length"><td colspan="6" class="text-center py-5 text-muted">No hay puestos configurados.</td></tr>
          </tbody>
        </table>
      </div>
    </b-card>

    <b-modal id="position-modal" hide-footer size="lg" :title="editing ? 'Editar puesto' : 'Nuevo puesto'">
      <b-form @submit.prevent="save">
        <b-row>
          <b-col md="6">
            <b-form-group :label="$t('Company') + ' *'">
              <v-select v-model="form.company_id" :reduce="o => o.value" :options="companyOptions" @input="loadDepartments" placeholder="Seleccionar empresa"/>
            </b-form-group>
          </b-col>
          <b-col md="6">
            <b-form-group :label="$t('Department') + ' *'">
              <v-select v-model="form.department_id" :reduce="o => o.value" :options="departmentOptions" placeholder="Seleccionar departamento"/>
            </b-form-group>
          </b-col>

          <template v-if="!editing">
            <b-col md="12">
              <b-form-group label="Puesto predeterminado">
                <v-select v-model="form.template_code" :reduce="o => o.value" :options="templateOptions" placeholder="Selecciona una plantilla o escribe un puesto personalizado" @input="applyTemplate"/>
                <small class="text-muted">Las plantillas son solo un punto de partida. Puedes dejar este campo vacío y crear cualquier puesto que tu empresa necesite.</small>
              </b-form-group>
            </b-col>
          </template>

          <b-col md="12">
            <b-form-group label="Nombre del puesto *">
              <b-form-input v-model.trim="form.designation" required maxlength="192" placeholder="Ej. Encargado de bodega nocturna"/>
            </b-form-group>
          </b-col>
          <b-col md="12">
            <b-form-group label="Descripción">
              <b-form-textarea v-model.trim="form.description" rows="3" maxlength="500" placeholder="Responsabilidad general del puesto"/>
            </b-form-group>
          </b-col>
        </b-row>

        <div v-if="selectedTemplate" class="alert alert-light border">
          <strong>Rol sugerido:</strong> {{ selectedTemplate.role }}<br>
          <small class="text-muted">Esto no modifica permisos. El rol real se elige cuando se crea la cuenta del empleado.</small>
        </div>
        <div v-if="error" class="alert alert-danger">{{ error }}</div>
        <div class="d-flex justify-content-end">
          <b-button variant="outline-secondary" class="mr-2" @click="$bvModal.hide('position-modal')">Cancelar</b-button>
          <b-button type="submit" variant="primary" :disabled="saving">{{ saving ? 'Guardando…' : 'Guardar puesto' }}</b-button>
        </div>
      </b-form>
    </b-modal>
  </div>
</template>

<script>
export default {
  metaInfo: { title: 'Puestos laborales' },
  data() {
    return {
      loading: true,
      saving: false,
      editing: false,
      search: '',
      totalRows: 0,
      positions: [],
      companies: [],
      departments: [],
      templates: [],
      error: '',
      timer: null,
      form: this.emptyForm(),
    };
  },
  computed: {
    companyOptions() { return this.companies.map(x => ({label:x.name, value:x.id})); },
    departmentOptions() { return this.departments.map(x => ({label:x.department, value:x.id})); },
    templateOptions() { return this.templates.map(x => ({label:x.name, value:x.code})); },
    selectedTemplate() { return this.templates.find(x => x.code === this.form.template_code) || null; },
  },
  created() { this.loadList(); },
  beforeDestroy() { if (this.timer) clearTimeout(this.timer); },
  methods: {
    emptyForm() { return {id:null, designation:'', template_code:null, description:'', company_id:null, department_id:null}; },
    async loadList() {
      this.loading = true;
      try {
        const {data} = await axios.get('designations', {params:{page:1, SortField:'designation', SortType:'asc', search:this.search || '', limit:-1}});
        this.positions = data.designations || [];
        this.totalRows = Number(data.totalRows || 0);
      } finally { this.loading = false; }
    },
    debouncedLoad() { if (this.timer) clearTimeout(this.timer); this.timer = setTimeout(this.loadList, 250); },
    async loadCreateData() {
      const {data} = await axios.get('/designations/create');
      this.companies = data.companies || [];
      this.templates = data.templates || [];
    },
    async loadDepartments(companyId) {
      this.departments = [];
      if (!this.editing) this.form.department_id = null;
      if (!companyId) return;
      const {data} = await axios.get('/core/get_departments_by_company?id='+companyId);
      this.departments = data || [];
    },
    applyTemplate(code) {
      const template = this.templates.find(x => x.code === code);
      if (!template) return;
      this.form.designation = template.name;
      this.form.description = template.description || '';
    },
    async openCreate() {
      this.editing = false;
      this.form = this.emptyForm();
      this.departments = [];
      this.error = '';
      await this.loadCreateData();
      this.$bvModal.show('position-modal');
    },
    async openEdit(row) {
      this.editing = true;
      this.form = {id:row.id, designation:row.designation, template_code:null, description:row.description || '', company_id:row.company_id, department_id:row.department_id};
      this.error = '';
      const {data} = await axios.get(`/designations/${row.id}/edit`);
      this.companies = data.companies || [];
      this.templates = data.templates || [];
      await this.loadDepartments(row.company_id);
      this.form.department_id = row.department_id;
      this.$bvModal.show('position-modal');
    },
    async save() {
      if (!this.form.company_id || !this.form.department_id || !this.form.designation) {
        this.error = 'Completa empresa, departamento y nombre del puesto.';
        return;
      }
      this.saving = true;
      this.error = '';
      const payload = {designation:this.form.designation, template_code:this.editing ? null : this.form.template_code, description:this.form.description, company_id:this.form.company_id, department:this.form.department_id};
      try {
        if (this.editing) await axios.put(`/designations/${this.form.id}`, payload);
        else await axios.post('/designations', payload);
        this.$bvModal.hide('position-modal');
        await this.loadList();
        this.$root.$bvToast.toast('Puesto guardado correctamente.', {title:'Éxito', variant:'success', solid:true});
      } catch (e) {
        const data = e && e.response && e.response.data;
        this.error = (data && data.message) || 'No se pudo guardar el puesto.';
      } finally { this.saving = false; }
    },
    remove(row) {
      this.$swal({title:'Desactivar puesto', text:`Se desactivará ${row.designation}. Los empleados históricos conservarán la referencia.`, type:'warning', showCancelButton:true, confirmButtonText:'Desactivar', cancelButtonText:'Cancelar'}).then(async result => {
        if (!(result.value || result.isConfirmed)) return;
        await axios.delete(`/designations/${row.id}`);
        await this.loadList();
      });
    },
  },
};
</script>
