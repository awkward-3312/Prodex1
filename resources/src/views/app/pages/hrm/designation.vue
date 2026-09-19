<template>
  <div class="px-next pxdesig">
    <!--
      Migracion px-next — Cargos (Puestos laborales). Ruta real sin cambios
      (/app/hrm/designations). Presentación únicamente. Auditado a fondo
      antes de tocar nada — este archivo NO se parece a los anteriores del
      lote (categorías/tipos/festivos/departamentos/empresa) en varios
      puntos clave, verificados contra `DesignationsController.php`:

      - "Eliminar" en realidad DESACTIVA, nunca borra de verdad:
        destroy() hace UPDATE is_active=false + deleted_at=now() dentro de
        una transacción. El comentario del propio controller lo confirma:
        "Positions are deactivated/soft-deleted, never hard deleted,
        because historical employees may still reference them." El copy
        legacy YA decía "Desactivar" (título del icono, texto del swal,
        confirmButtonText) — se conserva ese wording exacto, nunca se usa
        lenguaje de "eliminar permanentemente".
      - index() filtra whereNull(deleted_at) Y where(is_active,true): una
        vez desactivado, el puesto desaparece de la lista para siempre. No
        existe UI de "ver inactivos" ni de reactivar — no se inventa una.
      - Tras desactivar, el legacy NO muestra ninguna notificación de
        resultado (ni $swal ni $bvToast) — solo cierra el diálogo y
        recarga la lista. Se preserva: SIN notificación de resultado.
      - limit=-1 en la petición: el legacy carga TODOS los puestos de una
        vez, sin paginación real. Se preserva: no se agrega PxPagination.
      - SortField/SortType van fijos ('designation'/'asc') — no hay columnas
        ordenables en la UI legacy (no hay listeners de click en los <th>).
        Se preservan las columnas SIN sortable.
      - No hay checkboxes de selección ni acción masiva en el legacy, aunque
        el backend expone `designations/delete/by_selection` (usado por
        otras pantallas, no por esta). No se agrega selección/bulk aquí.
      - Errores de guardar se muestran INLINE dentro del modal (no toast,
        no swal) — se preserva con PxAlert danger dentro del modal.
      - Exito de guardar SÍ usa $bvToast — se preserva igual.
      - is_system_default (Plantilla PRODEX / Personalizado) es un tipo,
        no un estado activo/inactivo, así que se usa PxTag (para
        categorías/tipos) en vez de PxBadge (reservado para estados
        semánticos reales). No existe ningún estado activo/inactivo VISIBLE
        en esta pantalla (los inactivos jamás llegan al listado), así que
        no se inventa un PxBadge de estado que no existe.
      - El selector de plantilla solo aparece al crear (nunca al editar),
        igual que el legacy.
    -->
    <px-page-header
      title="Puestos laborales"
      subtitle="Usa una plantilla común o crea un puesto propio. El puesto puede sugerir un rol, pero nunca concede permisos automáticamente."
      :breadcrumbs="[{ label: $t('hrm') }, { label: 'Puestos laborales' }]"
    >
      <template #actions>
        <px-button variant="primary" icon="plus" @click="openCreate">Nuevo puesto</px-button>
      </template>
    </px-page-header>

    <px-toolbar :search="search" search-placeholder="Buscar puesto" @update:search="onSearchInput">
      <template #trail>
        <span class="pxdesig__count">{{ totalRows }} puesto(s)</span>
      </template>
    </px-toolbar>

    <div v-if="loading" class="pxdesig__pad">
      <px-skeleton variant="table" :rows="8" :columns="5" />
    </div>

    <template v-else>
      <div class="pxdesig__tablewrap">
        <px-table v-if="positions.length" :columns="columns" :rows="positions" row-key="id" has-row-actions>
          <template #cell-designation="{ row }">
            <strong>{{ row.designation }}</strong>
            <div v-if="row.description" class="pxdesig__desc">{{ row.description }}</div>
          </template>
          <template #cell-company_name="{ value }">{{ value || '—' }}</template>
          <template #cell-department_name="{ value }">{{ value || '—' }}</template>
          <template #cell-is_system_default="{ row }">
            <px-tag :label="row.is_system_default ? 'Plantilla PRODEX' : 'Personalizado'" :hue="row.is_system_default ? 'indigo' : 'slate'" />
          </template>
          <template #cell-suggested_role_key="{ value }">{{ value || '—' }}</template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state v-else icon="briefcase-business" title="Puestos laborales" description="No hay puestos configurados." />
      </div>
    </template>

    <!-- Crear / editar -->
    <px-modal v-model="modalOpen" :title="editing ? 'Editar puesto' : 'Nuevo puesto'" size="md">
      <validation-observer ref="Create_Designation">
        <form @submit.prevent="save">
          <v-field name="Company" label="Empresa" required :rules="{ required: true }" v-slot="{ invalid, id }">
            <vs-px
              :input-id="id"
              :invalid="invalid"
              v-model="form.company_id"
              @input="loadDepartments"
              :reduce="o => o.value"
              placeholder="Seleccionar empresa"
              :options="companyOptions"
            />
          </v-field>

          <v-field name="Department" label="Departamento" required :rules="{ required: true }" v-slot="{ invalid, id }" class="pxdesig__field">
            <vs-px
              :input-id="id"
              :invalid="invalid"
              v-model="form.department_id"
              :reduce="o => o.value"
              placeholder="Seleccionar departamento"
              :options="departmentOptions"
            />
          </v-field>

          <px-field v-if="!editing" label="Puesto predeterminado" class="pxdesig__field">
            <template #default="{ id }">
              <vs-px
                :input-id="id"
                v-model="form.template_code"
                @input="applyTemplate"
                :reduce="o => o.value"
                placeholder="Selecciona una plantilla o escribe un puesto personalizado"
                :options="templateOptions"
              />
            </template>
          </px-field>
          <p v-if="!editing" class="pxdesig__hint">Las plantillas son solo un punto de partida. Puedes dejar este campo vacío y crear cualquier puesto que tu empresa necesite.</p>

          <v-field name="Name" label="Nombre del puesto" required :rules="{ required: true }" v-slot="{ invalid, id }" class="pxdesig__field">
            <px-input :id="id" v-model.trim="form.designation" placeholder="Ej. Encargado de bodega nocturna" :invalid="invalid" />
          </v-field>

          <px-field label="Descripción" class="pxdesig__field">
            <template #default="{ id }">
              <px-textarea :id="id" v-model.trim="form.description" :rows="3" placeholder="Responsabilidad general del puesto" />
            </template>
          </px-field>

          <px-alert v-if="selectedTemplate" tone="info" class="pxdesig__field">
            <strong>Rol sugerido:</strong> {{ selectedTemplate.role }}<br>
            <small>Esto no modifica permisos. El rol real se elige cuando se crea la cuenta del empleado.</small>
          </px-alert>

          <px-alert v-if="error" tone="danger" class="pxdesig__field">{{ error }}</px-alert>
        </form>
      </validation-observer>

      <template #footer="{ close }">
        <span class="pxdesig__grow" />
        <px-button variant="secondary" :disabled="saving" @click="close">Cancelar</px-button>
        <px-button variant="primary" icon="check" :loading="saving" @click="save">Guardar puesto</px-button>
      </template>
    </px-modal>

    <!-- Confirmar desactivar -->
    <px-modal v-model="confirmOpen" title="Desactivar puesto" size="sm">
      <p class="pxdesig__confirm">
        Se desactivará <strong v-if="pendingDeactivate">{{ pendingDeactivate.designation }}</strong>. Los empleados históricos conservarán la referencia.
      </p>
      <template #footer="{ close }">
        <span class="pxdesig__grow" />
        <px-button variant="secondary" :disabled="deactivating" @click="close">Cancelar</px-button>
        <px-button variant="danger" icon="archive" :loading="deactivating" @click="doDeactivate">Desactivar</px-button>
      </template>
    </px-modal>
  </div>
</template>

<script>
import { notifications } from "@/platform";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxTag from "@/components/px-next/PxTag.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxSkeleton from "@/components/PxSkeleton.vue";
import VField from "@/views/app/products/next/edit/VField.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  name: "HrmDesignationNext",
  metaInfo: { title: "Puestos laborales" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxButton, PxKebab, PxField, PxInput,
    PxTextarea, PxTag, PxAlert, PxEmptyState, PxModal, PxSkeleton,
    "v-field": VField, "vs-px": VsPx
  },
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
      modalOpen: false,
      confirmOpen: false,
      pendingDeactivate: null,
      deactivating: false
    };
  },
  computed: {
    columns() {
      return [
        { key: "designation", label: "Puesto", strong: true },
        { key: "company_name", label: "Empresa" },
        { key: "department_name", label: "Departamento" },
        { key: "is_system_default", label: "Tipo" },
        { key: "suggested_role_key", label: "Rol sugerido" }
      ];
    },
    rowActions() {
      return [
        { key: "edit", label: "Editar", icon: "pencil" },
        { key: "deactivate", label: "Desactivar", icon: "archive", tone: "danger" }
      ];
    },
    companyOptions() { return this.companies.map(x => ({ label: x.name, value: x.id })); },
    departmentOptions() { return this.departments.map(x => ({ label: x.department, value: x.id })); },
    templateOptions() { return this.templates.map(x => ({ label: x.name, value: x.code })); },
    selectedTemplate() { return this.templates.find(x => x.code === this.form.template_code) || null; }
  },
  created() { this.loadList(); },
  beforeDestroy() { if (this.timer) clearTimeout(this.timer); },
  methods: {
    emptyForm() { return { id: null, designation: '', template_code: null, description: '', company_id: null, department_id: null }; },

    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.openEdit(row);
      else if (k === "deactivate") { this.pendingDeactivate = row; this.confirmOpen = true; }
    },

    onSearchInput(v) {
      this.search = v;
      this.debouncedLoad();
    },

    async loadList() {
      this.loading = true;
      try {
        const { data } = await axios.get('designations', { params: { page: 1, SortField: 'designation', SortType: 'asc', search: this.search || '', limit: -1 } });
        this.positions = data.designations || [];
        this.totalRows = Number(data.totalRows || 0);
      } finally { this.loading = false; }
    },
    debouncedLoad() { if (this.timer) clearTimeout(this.timer); this.timer = setTimeout(this.loadList, 250); },

    async loadCreateData() {
      const { data } = await axios.get('/designations/create');
      this.companies = data.companies || [];
      this.templates = data.templates || [];
    },
    async loadDepartments(companyId) {
      this.departments = [];
      if (!this.editing) this.form.department_id = null;
      if (!companyId) return;
      const { data } = await axios.get('/core/get_departments_by_company?id=' + companyId);
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
      this.modalOpen = true;
    },
    async openEdit(row) {
      this.editing = true;
      this.form = { id: row.id, designation: row.designation, template_code: null, description: row.description || '', company_id: row.company_id, department_id: row.department_id };
      this.error = '';
      const { data } = await axios.get(`/designations/${row.id}/edit`);
      this.companies = data.companies || [];
      this.templates = data.templates || [];
      await this.loadDepartments(row.company_id);
      this.form.department_id = row.department_id;
      this.modalOpen = true;
    },

    async save() {
      if (!this.form.company_id || !this.form.department_id || !this.form.designation) {
        this.error = 'Completa empresa, departamento y nombre del puesto.';
        return;
      }
      this.saving = true;
      this.error = '';
      const payload = { designation: this.form.designation, template_code: this.editing ? null : this.form.template_code, description: this.form.description, company_id: this.form.company_id, department: this.form.department_id };
      try {
        if (this.editing) await axios.put(`/designations/${this.form.id}`, payload);
        else await axios.post('/designations', payload);
        this.modalOpen = false;
        await this.loadList();
        notifications.notify('Puesto guardado correctamente.', { title: 'Éxito', variant: 'success', solid: true });
      } catch (e) {
        const data = e && e.response && e.response.data;
        this.error = (data && data.message) || 'No se pudo guardar el puesto.';
      } finally { this.saving = false; }
    },

    async doDeactivate() {
      const row = this.pendingDeactivate;
      if (!row) return;
      this.deactivating = true;
      try {
        await axios.delete(`/designations/${row.id}`);
        this.confirmOpen = false;
        this.pendingDeactivate = null;
        await this.loadList();
      } finally {
        this.deactivating = false;
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxdesig { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxdesig { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxdesig__pad { padding: var(--pxn-space-6) 0; }

.pxdesig__tablewrap { margin-top: var(--pxn-space-5); }
.pxdesig__count { color: var(--pxn-ink-3); font-size: var(--pxn-fs-sm); }
.pxdesig__desc { margin-top: 2px; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }

.pxdesig__field { margin-top: var(--pxn-space-5); }
.pxdesig__hint { margin: var(--pxn-space-3) 0 0; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxdesig__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxdesig__grow { flex: 1; }
</style>
