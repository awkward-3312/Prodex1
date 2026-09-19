<template>
  <div class="px-next pxlv">
    <!--
      Migracion px-next — Solicitudes de permiso (Leave). Ruta real sin
      cambios (/app/hrm/leaves/list). Presentación únicamente, auditada a
      fondo contra LeaveController (index/create/store/edit/update/destroy/
      delete_by_selection), LeavePolicy y el modelo Leave antes de tocar
      nada. Vista con más lógica de negocio que un CRUD simple — nada de
      esa lógica se tocó.

      Hallazgos clave, verificados y preservados EXACTOS:

      - NO existen botones dedicados "Aprobar"/"Rechazar". El estado
        (approved/pending/rejected) es un campo más del mismo formulario
        de crear/editar — un select con 3 opciones fijas en el propio
        template legacy (no vienen del backend). Quien tenga permiso de
        editar puede cambiar el estado desde ahí. No se inventó un
        workflow de aprobación separado.
      - El cálculo de días (`days`) es 100% BACKEND
        (`LeaveController@store/update`: `$start->diff($end)->d + 1`) — el
        cliente NUNCA envía `days` en el payload (no está en la lista de
        campos que se appendean al FormData) y tampoco lo calcula. Se
        preserva: no se agregó ningún cálculo de días en el cliente.
      - Reglas de saldo de permisos (`remaining_leave`) viven enteramente
        en el backend: al crear/editar, si los días solicitados exceden
        el saldo disponible del empleado, el backend responde 200 OK con
        `{isvalid:false}` (NO es un error HTTP) y el frontend debe leer
        ese campo para mostrar el toast
        "remaining_leaves_are_insufficient" — esto NO pasa por el
        `.catch()`. Se preservó exactamente esa rama.
      - El objeto `FormData` se crea UNA sola vez en `data()` y NUNCA se
        reinicia entre envíos (a diferencia de candidates.vue, que crea
        uno nuevo por envío) — esto es una peculiaridad/bug real del
        legacy: campos repetidos se van acumulando vía `.append()` en
        sucesivos submits durante la misma carga de página. Se preserva
        tal cual (documentado como bug, no corregido).
      - Adjunto: si se edita SIN elegir un archivo nuevo, el cliente
        reenvía `attachment` como cadena vacía. El backend interpreta
        `"" == null` (comparación floja de PHP) y conserva el archivo
        existente — confirmado que el archivo nunca se pierde al editar
        sin tocar el input de archivo. Al reemplazar, el backend borra el
        archivo físico viejo (`@unlink`) — a diferencia de Candidatos,
        aquí SÍ hay limpieza real de archivos huérfanos.
      - `half_day` existe en el modelo y en el payload (`half_day: 0|1`)
        pero el formulario legacy NUNCA expone un control para él — se
        envía siempre como 0 porque no hay UI que lo cambie. No se
        inventó un checkbox nuevo para esto.
      - Selects en cascada: Company → Department (mismo endpoint
        `/core/get_departments_by_company` que Departamentos/Cargos) →
        Employee, pero aquí Department → Employee usa un endpoint
        DISTINTO: `/get_employees_by_department` (sin prefijo `/core/`,
        de `EmployeesController`) — no confundir con
        `Get_employees_by_company` usado en otras vistas.
      - index() hace JOIN real con alias SQL genuinos
        (`employees.username AS employee_name`, etc.) — igual que
        Departamentos, así que ordenar por columnas alias (Employee,
        Company, Department, Leave_Type) se verificó en vivo que NO
        produce el 500 de Días festivos.
      - Delete es soft-delete (`deleted_at` manual, sin trait SoftDeletes)
        + borrado físico real del adjunto (salvo que sea 'no_image.png').
      - Permiso plano único `leave` para view/create/update/delete — sin
        permiso de aprobación separado, no se inventó ninguno.
    -->
    <px-page-header :title="$t('Leave_request')" :breadcrumbs="[{ label: $t('hrm') }, { label: $t('Leave_request') }]">
      <template #actions>
        <px-button
          v-if="selectedIds.length"
          variant="danger"
          icon="trash-2"
          @click="confirmBulkOpen = true"
        >{{ $t('Del') }} ({{ selectedIds.length }})</px-button>
        <px-button variant="primary" icon="plus" @click="New_Leave">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar :search="search" :search-placeholder="$t('Search_this_table')" @update:search="onSearchInput" />

    <div v-if="isLoading" class="pxlv__pad">
      <px-skeleton variant="table" :rows="8" :columns="8" />
    </div>

    <template v-else>
      <div class="pxlv__tablewrap" :class="{ 'is-busy': refreshing }">
        <px-table
          v-if="leaves.length"
          :columns="columns"
          :rows="leaves"
          row-key="id"
          selectable
          :selected="selectedIds"
          @update:selected="selectedIds = $event"
          :sort-key="sort.field"
          :sort-dir="sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-status="{ value }">
            <px-badge :tone="statusTone(value)">{{ formatLabel(value) }}</px-badge>
          </template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state v-else icon="calendar-clock" :title="$t('Leave_request')" description="Sin solicitudes que coincidan con la búsqueda." />
      </div>

      <px-pagination
        v-if="leaves.length"
        :page="page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        :per-page-options="['10', '25', '50', '100']"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <!-- Crear / editar -->
    <px-modal v-model="modalOpen" :title="editmode ? $t('Edit') : $t('Add')" size="lg">
      <validation-observer ref="Create_Leave">
        <form @submit.prevent="Submit_Leave">
          <div class="pxlv__grid">
            <v-field name="Company" :label="$t('Company')" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <vs-px
                :input-id="id"
                :invalid="invalid"
                v-model="leave.company_id"
                @input="Selected_Company"
                :reduce="o => o.value"
                :placeholder="$t('Choose_Company')"
                :options="companies.map(c => ({ label: c.name, value: c.id }))"
              />
            </v-field>

            <v-field name="Department" :label="$t('Department')" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <vs-px
                :input-id="id"
                :invalid="invalid"
                v-model="leave.department_id"
                @input="Selected_Department"
                :reduce="o => o.value"
                :placeholder="$t('Department')"
                :options="departments.map(d => ({ label: d.department, value: d.id }))"
              />
            </v-field>
          </div>

          <div class="pxlv__grid pxlv__field">
            <v-field name="Employee" :label="$t('Employee')" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <vs-px
                :input-id="id"
                :invalid="invalid"
                v-model="leave.employee_id"
                @input="Selected_Employee"
                :reduce="o => o.value"
                :placeholder="$t('Choose_Employee')"
                :options="employees.map(e => ({ label: e.username, value: e.id }))"
              />
            </v-field>

            <v-field name="leave_type" :label="$t('Leave_Type')" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <vs-px
                :input-id="id"
                :invalid="invalid"
                v-model="leave.leave_type_id"
                @input="Selected_Leave_Type"
                :reduce="o => o.value"
                :placeholder="$t('Choose_leave_type')"
                :options="leave_types.map(t => ({ label: t.title, value: t.id }))"
              />
            </v-field>
          </div>

          <div class="pxlv__grid pxlv__field">
            <v-field name="start_date" :label="$t('start_date')" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <px-input :id="id" type="date" v-model="leave.start_date" :placeholder="$t('Enter_Start_date')" :invalid="invalid" />
            </v-field>

            <v-field name="Finish_Date" :label="$t('Finish_Date')" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <px-input :id="id" type="date" v-model="leave.end_date" :placeholder="$t('Enter_Finish_date')" :invalid="invalid" />
            </v-field>
          </div>

          <div class="pxlv__grid pxlv__field">
            <v-field name="Status" :label="$t('Status')" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <px-select :id="id" v-model="leave.status" :options="statusOptions" :placeholder="$t('Choose_status')" :invalid="invalid" />
            </v-field>

            <validation-provider name="Attachment" ref="Attachment" rules="mimes:image/*|size:2048" v-slot="{ errors }">
              <px-field :label="$t('Attachment')">
                <template #default>
                  <div class="pxlv__file" :class="{ 'is-over': attachmentDragOver }"
                       @dragover.prevent="attachmentDragOver = true"
                       @dragleave.prevent="attachmentDragOver = false"
                       @drop.prevent="onAttachmentDrop">
                    <input ref="attachmentInput" type="file" class="pxlv__file-input" @change="changeAttachement" />
                    <button type="button" class="pxlv__file-btn" @click="$refs.attachmentInput.click()">
                      <lucide-icon name="file-up" :size="15" />
                      {{ $t('Choose_a_file') }}
                    </button>
                    <span class="pxlv__file-name">{{ attachmentFile ? attachmentFile.name : 'Ningún archivo seleccionado' }}</span>
                  </div>
                </template>
              </px-field>
              <px-alert v-if="errors.length" tone="danger" class="pxlv__field">{{ errors[0] }}</px-alert>
            </validation-provider>
          </div>

          <px-field :label="$t('Leave_Reason')" class="pxlv__field">
            <template #default="{ id }">
              <px-textarea :id="id" v-model="leave.reason" :rows="3" :placeholder="$t('Enter_Reason_Leave')" />
            </template>
          </px-field>
        </form>
      </validation-observer>

      <template #footer="{ close }">
        <span class="pxlv__grow" />
        <px-button variant="secondary" :disabled="SubmitProcessing" @click="close">Cancelar</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" @click="Submit_Leave">
          {{ $t('submit') }}
        </px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (una fila) -->
    <px-modal v-model="confirmOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxlv__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxlv__grow" />
        <px-button variant="secondary" :disabled="deleting" @click="close">{{ $t('Delete_cancelButtonText') }}</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deleting" @click="doDelete">{{ $t('Delete_confirmButtonText') }}</px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (selección múltiple) -->
    <px-modal v-model="confirmBulkOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxlv__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxlv__grow" />
        <px-button variant="secondary" :disabled="deletingBulk" @click="close">{{ $t('Delete_cancelButtonText') }}</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deletingBulk" @click="doDeleteBulk">{{ $t('Delete_confirmButtonText') }}</px-button>
      </template>
    </px-modal>
  </div>
</template>

<script>
import { notifications } from "@/platform";
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxSelect from "@/components/px-next/PxSelect.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxSkeleton from "@/components/PxSkeleton.vue";
import VField from "@/views/app/products/next/edit/VField.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  name: "HrmLeaveListNext",
  metaInfo: { title: "Leave" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab,
    PxField, PxInput, PxTextarea, PxSelect, PxBadge, PxAlert, PxEmptyState,
    PxModal, PxSkeleton, "v-field": VField, "vs-px": VsPx
  },
  data() {
    return {
      isLoading: true,
      refreshing: false,
      SubmitProcessing: false,
      // Igual que el legacy: UNA sola instancia de FormData creada aquí y
      // reutilizada (nunca reiniciada) entre envíos — peculiaridad/bug
      // preservado tal cual, no corregido.
      data: new FormData(),
      leaves: [],
      employees: [],
      companies: [],
      departments: [],
      leave_types: [],
      totalRows: "",
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "id", type: "desc" },
      selectedIds: [],
      editmode: false,
      modalOpen: false,
      leave: this.empty_leave(),
      attachmentFile: "",
      attachmentDragOver: false,
      confirmOpen: false,
      pendingDelete: null,
      deleting: false,
      confirmBulkOpen: false,
      deletingBulk: false
    };
  },
  computed: {
    columns() {
      return [
        { key: "employee_name", label: this.$t("Employee"), sortable: true, strong: true },
        { key: "company_name", label: this.$t("Company"), sortable: true },
        { key: "department_name", label: this.$t("Department"), sortable: true },
        { key: "leave_type_title", label: this.$t("Leave_Type"), sortable: true },
        { key: "start_date", label: this.$t("start_date"), sortable: true },
        { key: "end_date", label: this.$t("Finish_Date"), sortable: true },
        { key: "days", label: this.$t("Days"), sortable: true },
        { key: "status", label: this.$t("Status"), sortable: true }
      ];
    },
    rowActions() {
      return [
        { key: "edit", label: "Editar", icon: "pencil" },
        { key: "delete", label: "Eliminar", icon: "trash-2", tone: "danger" }
      ];
    },
    statusOptions() {
      return [
        { label: "Approved", value: "approved" },
        { label: "Pending", value: "pending" },
        { label: "Rejected", value: "rejected" }
      ];
    }
  },
  methods: {
    empty_leave() {
      return {
        id: "", company_id: "", department_id: "", employee_id: "", leave_type_id: "",
        start_date: "", end_date: "", days: "", reason: "", attachment: "", half_day: "", status: ""
      };
    },
    formatLabel(v) { return v ? String(v).replace(/_/g, " ") : "-"; },
    statusTone(s) {
      const map = { approved: "success", pending: "warning", rejected: "danger" };
      return map[s] || "neutral";
    },

    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.Edit_Leave(row);
      else if (k === "delete") { this.pendingDelete = row; this.confirmOpen = true; }
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.Get_Leaves(1); }, 350);
    },
    onSort({ key, dir }) {
      this.sort = { field: key, type: dir };
      this.Get_Leaves(this.page);
    },
    onPage(p) { if (p !== this.page) { this.page = p; this.Get_Leaves(p); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.Get_Leaves(1); },

    makeToast(variant, msg, title) {
      notifications.notify(msg, { title: title, variant: variant, solid: true });
    },

    Selected_Company(value) {
      if (value === null) this.leave.company_id = "";
      this.employees = [];
      this.departments = [];
      this.leave.employee_id = "";
      this.leave.department_id = "";
      this.Get_departments_by_company(value);
    },
    Selected_Department(value) {
      if (value === null) { this.leave.department_id = ""; this.leave.employee_id = ""; }
      this.leave.employee_id = "";
      this.Get_employees_by_department(value);
    },
    Selected_Employee(value) {
      if (value === null) this.leave.employee_id = "";
    },
    Selected_Leave_Type(value) {
      if (value === null) this.leave.leave_type_id = "";
    },

    Get_departments_by_company(value) {
      axios.get("/core/get_departments_by_company?id=" + value).then(({ data }) => (this.departments = data));
    },
    Get_employees_by_department(value) {
      axios.get("/get_employees_by_department?id=" + value).then(({ data }) => (this.employees = data));
    },

    async changeAttachement(e) {
      const { valid } = await this.$refs.Attachment.validate(e);
      if (valid) {
        this.attachmentFile = e.target.files[0];
        this.leave.attachment = e.target.files[0];
      } else {
        this.attachmentFile = "";
        this.leave.attachment = "";
      }
    },
    onAttachmentDrop(e) {
      this.attachmentDragOver = false;
      const f = e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) this.changeAttachement({ target: { files: [f] } });
    },

    Submit_Leave() {
      this.$refs.Create_Leave.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) this.Create_Leave();
          else this.Update_Leave();
        }
      });
    },

    New_Leave() {
      this.reset_Form();
      this.editmode = false;
      this.Get_Data_Create();
    },
    Edit_Leave(leave) {
      this.editmode = true;
      this.reset_Form();
      this.Get_Data_Edit(leave.id);
    },

    Get_Data_Create() {
      axios.get("/leave/create").then(response => {
        this.companies = response.data.companies;
        this.leave_types = response.data.leave_types;
        this.modalOpen = true;
      }).catch(() => {});
    },
    Get_Data_Edit(id) {
      axios.get(`leave/${id}/edit`).then(response => {
        this.leave = response.data.leave;
        this.companies = response.data.companies;
        this.leave_types = response.data.leave_types;
        this.Get_departments_by_company(this.leave.company_id);
        this.Get_employees_by_department(this.leave.department_id);
        this.leave.attachment = "";
        this.attachmentFile = "";
        this.modalOpen = true;
      }).catch(() => {});
    },

    Get_Leaves(page) {
      if (page === 1 || !page) this.refreshing = !this.isLoading;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "leave?page=" + page +
          "&SortField=" + this.sort.field +
          "&SortType=" + this.sort.type +
          "&search=" + this.search +
          "&limit=" + this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.leaves = response.data.leaves;
          NProgress.done();
          this.isLoading = false;
          this.refreshing = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; this.refreshing = false; }, 500);
        });
    },

    Create_Leave() {
      const self = this;
      self.SubmitProcessing = true;
      self.data.append("company_id", self.leave.company_id);
      self.data.append("department_id", self.leave.department_id);
      self.data.append("employee_id", self.leave.employee_id);
      self.data.append("leave_type_id", self.leave.leave_type_id);
      self.data.append("start_date", self.leave.start_date);
      self.data.append("end_date", self.leave.end_date);
      self.data.append("reason", self.leave.reason);
      self.data.append("attachment", self.leave.attachment);
      self.data.append("half_day", self.leave.half_day ? 1 : 0);
      self.data.append("status", self.leave.status);

      axios.post("/leave", self.data)
        .then(response => {
          if (response.data.isvalid == false) {
            self.SubmitProcessing = false;
            this.makeToast("danger", this.$t("remaining_leaves_are_insufficient"), this.$t("Failed"));
          } else {
            self.SubmitProcessing = false;
            Fire.$emit("Event_Leave");
            this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
          }
        })
        .catch(() => {
          self.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Leave() {
      const self = this;
      self.SubmitProcessing = true;
      self.data.append("company_id", self.leave.company_id);
      self.data.append("department_id", self.leave.department_id);
      self.data.append("employee_id", self.leave.employee_id);
      self.data.append("leave_type_id", self.leave.leave_type_id);
      self.data.append("start_date", self.leave.start_date);
      self.data.append("end_date", self.leave.end_date);
      self.data.append("reason", self.leave.reason);
      self.data.append("attachment", self.leave.attachment);
      self.data.append("half_day", self.leave.half_day ? 1 : 0);
      self.data.append("status", self.leave.status);
      self.data.append("_method", "put");

      axios.post("/leave/" + self.leave.id, self.data)
        .then(response => {
          if (response.data.isvalid == false) {
            self.SubmitProcessing = false;
            this.makeToast("danger", this.$t("remaining_leaves_are_insufficient"), this.$t("Failed"));
          } else {
            self.SubmitProcessing = false;
            Fire.$emit("Event_Leave");
            this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
          }
        })
        .catch(() => {
          self.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.leave = this.empty_leave();
      this.attachmentFile = "";
    },

    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.deleting = true;
      axios
        .delete("leave/" + row.id)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          this.pendingDelete = null;
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Delete_Leave");
        })
        .catch(() => {
          this.deleting = false;
          this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
        });
    },

    doDeleteBulk() {
      this.deletingBulk = true;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("leave/delete/by_selection", { selectedIds: this.selectedIds })
        .then(() => {
          this.deletingBulk = false;
          this.confirmBulkOpen = false;
          this.selectedIds = [];
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Delete_Leave");
        })
        .catch(() => {
          this.deletingBulk = false;
          setTimeout(() => NProgress.done(), 500);
          this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
        });
    }
  },

  created: function () {
    this.Get_Leaves(1);
    Fire.$on("Event_Leave", () => {
      setTimeout(() => {
        this.Get_Leaves(this.page);
        this.modalOpen = false;
      }, 500);
    });
    Fire.$on("Delete_Leave", () => {
      setTimeout(() => {
        this.Get_Leaves(this.page);
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxlv { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxlv { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxlv__pad { padding: var(--pxn-space-6) 0; }

.pxlv__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxlv__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }

.pxlv__grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--pxn-space-5); }
@media (max-width: 720px) { .pxlv__grid { grid-template-columns: 1fr; } }

.pxlv__field { margin-top: var(--pxn-space-5); }
.pxlv__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxlv__grow { flex: 1; }

// Reskin local del <input type="file"> nativo — sin componente global
// PxFileInput todavía, mismo patrón que Candidatos.
.pxlv__file {
  position: relative;
  display: flex;
  align-items: center;
  gap: var(--pxn-space-4);
  min-height: var(--pxn-control-h-md);
  padding: var(--pxn-space-2);
  border: 1px dashed var(--pxn-border-control);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
  transition: border-color var(--pxn-dur-1) var(--pxn-ease), background var(--pxn-dur-1) var(--pxn-ease);
}
.pxlv__file.is-over { border-color: var(--pxn-primary); background: var(--pxn-primary-softer); }
.pxlv__file-input { position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer; z-index: 1; }
.pxlv__file-btn {
  position: relative;
  z-index: 2;
  display: inline-flex;
  align-items: center;
  gap: var(--pxn-space-3);
  height: calc(var(--pxn-control-h-md) - 8px);
  padding: 0 var(--pxn-space-5);
  border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-sm);
  background: var(--pxn-surface-2);
  color: var(--pxn-ink-2);
  font: inherit;
  font-size: var(--pxn-fs-sm);
  font-weight: var(--pxn-fw-medium);
  cursor: pointer;
  flex: none;
  pointer-events: none;
}
.pxlv__file-name { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
