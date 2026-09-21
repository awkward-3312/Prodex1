<template>
  <div class="px-next pxat">
    <!--
      Migracion px-next — Asistencia (Attendance). Ruta real sin cambios
      (/app/hrm/attendance). Presentación únicamente, auditada a fondo
      contra AttendancesController (index/create/store/edit/update/destroy/
      delete_by_selection), AttendanceIntegrationsController (devices/
      employeeIdentifiers/punches/importPunches), AttendancePolicy y los
      modelos Attendance/AttendanceDevice/AttendancePunch/
      AttendanceEmployeeIdentifier antes de tocar nada.

      Hallazgos clave, verificados y preservados EXACTOS:

      - Permiso plano único `attendance` (AttendancePolicy) cubre view/
        create/update/delete de Attendance Y también gatea todos los
        endpoints de AttendanceIntegrationsController (devices/import/
        punches usan `Attendance::class` como sujeto de la policy, no un
        permiso propio). No se inventó ningún permiso nuevo.
      - No existe "check-in/check-out" en vivo desde esta vista: el único
        flujo de creación es "Registro manual" (fecha + hora de entrada +
        hora de salida ya conocidas, no un reloj corriendo). El cálculo de
        horas (`total_work`, `late_time`, `depart_early`, `overtime`) es
        100% BACKEND (`AttendancesController::buildAttendanceData`) — el
        cliente solo envía company_id/employee_id/date/clock_in/clock_out.
      - Lógica de tardanza/salida-anticipada/overtime (backend, sin tocar):
        compara la entrada/salida real contra el turno (`office_shift`)
        del empleado para ese día de la semana. Si el turno no tiene horas
        configuradas ese día, NO clasifica nada (todo en "00:00") y guarda
        la hora real tal cual — regla legacy preservada, no "mejorada".
      - Importación de marcajes (`Attendance_Import`) NO crea ni modifica
        filas de `attendances`. Solo guarda eventos crudos en
        `attendance_punches` (tabla distinta), vinculados a un empleado
        SI reconoce su código externo vía `attendance_employee_identifiers`.
        Duplicados se detectan por huella SHA-256
        (company+device+provider+external_user_id+occurred_at) y se
        cuentan aparte, sin insertar de nuevo. Ningún dato de asistencia
        calculada se toca — tal cual el aviso que ya mostraba el modal
        legacy. Endpoint distinto: `/attendance-integrations/import`
        (multipart FormData), no `attendances`.
      - Dispositivos (`Attendance_Devices`) es CRUD propio sobre
        `attendance_devices` (compañía, nombre, proveedor, modelo, serie,
        modo de conexión) — no hay integración real con hardware desde
        PRODEX (según el propio texto legacy: "No es necesario almacenar
        huellas ni datos biométricos"). Eliminar un dispositivo conserva
        los marcajes ya importados (`AttendancePunch` no se toca) y
        únicamente desvincula futuros matches — no hay notificación de
        resultado en el legacy tras borrar (solo se refresca la lista), se
        preserva así, sin agregar un toast que no existía.
      - Selects en cascada: Company → Employee usa
        `/core/get_employees_by_company` (igual que otras vistas HRM,
        endpoint distinto al de Solicitudes de permiso que usa
        `/get_employees_by_department`).
      - Fecha: legacy usa `vuejs-datepicker` con `format="yyyy-MM-dd"` y
        reformatea manualmente en `@closed`. El backend solo hace
        `Carbon::parse($validated['date'])->format('Y-m-d')` — acepta
        cualquier fecha parseable, así que un `<input type="date">` nativo
        (que ya emite `YYYY-MM-DD`) produce el mismo valor exacto sin
        cambiar el formato enviado — mismo criterio ya aplicado en
        Solicitudes de permiso.
      - Hora de entrada/salida: legacy usa un clock-picker con carátula de
        reloj (`@pencilpix/vue2-clock-picker`), un widget que NO tiene
        equivalente PX Next. Se conserva EXACTO (mismo componente, mismo
        v-model, mismo formato) — solo se reskinner el input disparador,
        igual regla que "PX Next todavía no tiene file-input global": no
        se reconstruye ni se simula, solo cambia su presentación externa.
      - Archivo de importación: `<b-form-file accept=".csv,.txt,.xls,.xlsx">`
        se conserva funcional (mismo `accept`, mismo FormData, mismo
        endpoint) con un reskin LOCAL scoped — mismo patrón que Candidatos
        y Solicitudes de permiso, sin PxFileInput global todavía.
      - Delete es soft-delete real (`deleted_at` manual, sin trait
        SoftDeletes) tanto individual como por selección — no borra el
        registro físicamente, solo lo oculta del listado.
      - Confirmaciones de eliminar (fila y selección) usaban `$swal`
        directamente en el legacy — se migran a PxModal para la
        confirmación (regla del proyecto), pero el RESULTADO se queda
        exactamente en `$swal` como ya estaba. Crear/editar/importar/
        dispositivo siguen usando `notifications` sin homogenizar.
    -->
    <px-page-header :title="$t('Attendances')" :breadcrumbs="[{ label: $t('hrm') }, { label: $t('Attendances') }]">
      <template #actions>
        <div class="pxat__actions">
          <px-button
            v-if="selectedIds.length"
            variant="danger"
            icon="trash-2"
            @click="confirmBulkOpen = true"
          >{{ $t('Del') }} ({{ selectedIds.length }})</px-button>
          <px-button variant="secondary" icon="upload" @click="openImportModal">Importar marcajes</px-button>
          <px-button variant="secondary" icon="monitor" @click="openDevicesModal">Dispositivos</px-button>
          <px-button variant="primary" icon="plus" @click="New_attendance">Registrar manualmente</px-button>
        </div>
      </template>
    </px-page-header>

    <px-toolbar :search="search" :search-placeholder="$t('Search_this_table')" @update:search="onSearchInput" />

    <div v-if="isLoading" class="pxat__pad">
      <px-skeleton variant="table" :rows="8" :columns="7" />
    </div>

    <template v-else>
      <div class="pxat__tablewrap" :class="{ 'is-busy': refreshing }">
        <px-table
          v-if="attendances.length"
          :columns="columns"
          :rows="attendances"
          row-key="id"
          selectable
          :selected="selectedIds"
          @update:selected="selectedIds = $event"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state v-else icon="clock" :title="$t('Attendances')" description="Sin registros de asistencia que coincidan con la búsqueda." />
      </div>

      <px-pagination
        v-if="attendances.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        :per-page-options="['10', '20', '30', '40', '50']"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <!-- Registro manual -->
    <px-modal v-model="modalOpen" :title="editmode ? 'Editar asistencia' : 'Registrar asistencia'" size="md">
      <px-validation-observer ref="Create_Attendance">
        <form @submit.prevent="Submit_Attendance">
          <div class="pxat__intro">
            <div class="pxat__intro-icon"><lucide-icon name="clock" /></div>
            <div>
              <h6>{{ editmode ? 'Actualizar registro de asistencia' : 'Registro manual de asistencia' }}</h6>
              <p>{{ editmode ? 'Corrige la jornada registrada para este empleado.' : 'Úsalo cuando la asistencia no provenga de un reloj, importación u otro sistema de marcaje.' }}</p>
            </div>
          </div>

          <div class="pxat__section">
            <div class="pxat__section-title">Empleado</div>
            <div class="pxat__grid">
              <v-field name="Compañía" label="Compañía" required :rules="{ required: true }" v-slot="{ invalid, id }">
                <vs-px
                  :input-id="id"
                  :invalid="invalid"
                  v-model="attendance.company_id"
                  @input="Selected_Company"
                  :reduce="o => o.value"
                  placeholder="Selecciona una compañía"
                  :options="companies.map(c => ({ label: c.name, value: c.id }))"
                />
              </v-field>

              <v-field name="Empleado" label="Empleado" required :rules="{ required: true }" v-slot="{ invalid, id }">
                <vs-px
                  :input-id="id"
                  :invalid="invalid"
                  v-model="attendance.employee_id"
                  @input="Selected_Employee"
                  :reduce="o => o.value"
                  placeholder="Selecciona un empleado"
                  :options="employees.map(e => ({ label: e.username, value: e.id }))"
                />
              </v-field>
            </div>
          </div>

          <div class="pxat__section">
            <div class="pxat__section-title">Jornada</div>
            <div class="pxat__grid">
              <v-field name="Fecha" label="Fecha" required :rules="{ required: true }" class="pxat__field--full" v-slot="{ invalid, id }">
                <px-input :id="id" type="date" v-model="attendance.date" placeholder="Selecciona la fecha de asistencia" :invalid="invalid" />
              </v-field>

              <v-field name="Hora de entrada" label="Hora de entrada" required :rules="{ required: true }" v-slot="{ invalid, id }">
                <div class="pxat__clock" :class="{ 'is-invalid': invalid }">
                  <vue-clock-picker v-model="attendance.clock_in" placeholder="Hora de entrada" name="clock_in" :id="id" />
                </div>
              </v-field>

              <v-field name="Hora de salida" label="Hora de salida" required :rules="{ required: true }" v-slot="{ invalid, id }">
                <div class="pxat__clock" :class="{ 'is-invalid': invalid }">
                  <vue-clock-picker v-model="attendance.clock_out" placeholder="Hora de salida" name="clock_out" :id="id" />
                </div>
              </v-field>
            </div>
            <span class="pxat__source-note"><lucide-icon name="edit" :size="13" /> Registro manual</span>
          </div>
        </form>
      </px-validation-observer>

      <template #footer="{ close }">
        <span class="pxat__hint">La duración se calculará a partir de la entrada y salida.</span>
        <px-button variant="secondary" :disabled="SubmitProcessing" @click="close">Cancelar</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" @click="Submit_Attendance">
          {{ editmode ? 'Guardar cambios' : 'Guardar asistencia' }}
        </px-button>
      </template>
    </px-modal>

    <!-- Importar marcajes -->
    <px-modal v-model="importModalOpen" title="Importar marcajes" size="lg">
      <div class="pxat__intro">
        <div class="pxat__intro-icon"><lucide-icon name="upload" /></div>
        <div>
          <h6>Importar desde un reloj o sistema externo</h6>
          <p>PRODEX conservará cada marcaje original. Si reconoce el código del empleado lo vinculará automáticamente.</p>
        </div>
      </div>

      <div class="pxat__notice">
        <lucide-icon name="info" :size="16" />
        <span>Importar marcajes todavía no crea ni modifica jornadas calculadas. Primero se guardan los eventos originales para evitar perder información.</span>
      </div>

      <div class="pxat__section">
        <div class="pxat__section-title">Origen</div>
        <div class="pxat__grid">
          <px-field label="Compañía" required>
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="importForm.company_id" @input="onImportCompanyChanged" placeholder="Selecciona una compañía" :reduce="o => o.value" :options="companies.map(c => ({ label: c.name, value: c.id }))" />
            </template>
          </px-field>

          <px-field label="Dispositivo" hint="Si el archivo proviene de un reloj configurado, selecciónalo.">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="importForm.device_id" @input="onImportDeviceChanged" placeholder="Opcional" :reduce="o => o.value" :options="filteredDevices.map(d => ({ label: deviceLabel(d), value: d.id }))" />
            </template>
          </px-field>

          <px-field label="Proveedor / formato">
            <template #default="{ id }">
              <px-select :id="id" v-model="importForm.provider" :disabled="!!importForm.device_id" :options="providerOptions" />
            </template>
          </px-field>

          <px-field label="Archivo" required>
            <template #default>
              <div class="pxat__file" :class="{ 'is-over': importDragOver }"
                   @dragover.prevent="importDragOver = true"
                   @dragleave.prevent="importDragOver = false"
                   @drop.prevent="onImportFileDrop">
                <input ref="importFileInput" type="file" class="pxat__file-input" accept=".csv,.txt,.xls,.xlsx" @change="onImportFileChange" />
                <button type="button" class="pxat__file-btn" @click="$refs.importFileInput.click()">
                  <lucide-icon name="file-up" :size="15" />
                  Selecciona CSV, XLS o XLSX
                </button>
                <span class="pxat__file-name">{{ importForm.file ? importForm.file.name : 'Ningún archivo seleccionado' }}</span>
              </div>
            </template>
          </px-field>
        </div>
      </div>

      <div v-if="importSummary" class="pxat__summary">
        <div><strong>{{ importSummary.imported }}</strong><span>importados</span></div>
        <div><strong>{{ importSummary.matched }}</strong><span>vinculados</span></div>
        <div><strong>{{ importSummary.unmatched }}</strong><span>sin vincular</span></div>
        <div><strong>{{ importSummary.duplicates }}</strong><span>duplicados</span></div>
        <div><strong>{{ importSummary.errors }}</strong><span>con error</span></div>
      </div>

      <template #footer="{ close }">
        <span class="pxat__hint">Columnas reconocidas: ID/código de empleado y fecha+hora, juntas o separadas.</span>
        <px-button variant="secondary" @click="close">Cerrar</px-button>
        <px-button variant="primary" :loading="importProcessing" :disabled="importProcessing || !importForm.company_id || !importForm.file" @click="importPunches">
          Importar marcajes
        </px-button>
      </template>
    </px-modal>

    <!-- Dispositivos -->
    <px-modal v-model="devicesModalOpen" title="Dispositivos de marcaje" size="lg">
      <div class="pxat__intro">
        <div class="pxat__intro-icon"><lucide-icon name="monitor" /></div>
        <div>
          <h6>Relojes y fuentes de marcaje</h6>
          <p>Registra los equipos que ya utiliza la empresa. No es necesario almacenar huellas ni datos biométricos en PRODEX.</p>
        </div>
      </div>

      <div class="pxat__section">
        <div class="pxat__section-title">Nuevo dispositivo</div>
        <div class="pxat__grid">
          <px-field label="Compañía" required>
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="deviceForm.company_id" placeholder="Selecciona una compañía" :reduce="o => o.value" :options="companies.map(c => ({ label: c.name, value: c.id }))" />
            </template>
          </px-field>

          <px-field label="Nombre" required>
            <template #default="{ id }">
              <px-input :id="id" v-model.trim="deviceForm.name" placeholder="Ej. Recepción principal" />
            </template>
          </px-field>

          <px-field label="Proveedor" required>
            <template #default="{ id }">
              <px-select :id="id" v-model="deviceForm.provider" :options="providerOptions" />
            </template>
          </px-field>

          <px-field label="Modelo" optional>
            <template #default="{ id }">
              <px-input :id="id" v-model.trim="deviceForm.model" placeholder="Ej. ZKTeco F22" />
            </template>
          </px-field>

          <px-field label="Número de serie" optional>
            <template #default="{ id }">
              <px-input :id="id" v-model.trim="deviceForm.serial_number" placeholder="Opcional" />
            </template>
          </px-field>

          <px-field label="Modo de conexión">
            <template #default="{ id }">
              <px-select :id="id" v-model="deviceForm.connection_mode" :options="connectionOptions" />
            </template>
          </px-field>
        </div>
        <div class="pxat__right">
          <px-button variant="primary" icon="plus" :loading="deviceProcessing" :disabled="deviceProcessing || !deviceForm.company_id || !deviceForm.name" @click="createDevice">
            Guardar dispositivo
          </px-button>
        </div>
      </div>

      <div class="pxat__section">
        <div class="pxat__section-title">Dispositivos configurados</div>
        <px-empty-state v-if="!devices.length" icon="monitor" title="Sin dispositivos" description="Todavía no hay dispositivos registrados." />
        <div v-for="device in devices" :key="device.id" class="pxat__device-row">
          <div class="pxat__device-icon"><lucide-icon name="monitor" :size="16" /></div>
          <div class="pxat__device-copy">
            <strong>{{ device.name }}</strong>
            <span>{{ device.company ? device.company.name : '' }} · {{ providerName(device.provider) }}<template v-if="device.model"> · {{ device.model }}</template></span>
          </div>
          <px-badge :tone="device.is_active ? 'success' : 'neutral'">{{ device.is_active ? 'Activo' : 'Inactivo' }}</px-badge>
          <px-button size="sm" variant="danger" @click="removeDevice(device)">Eliminar</px-button>
        </div>
      </div>

      <template #footer="{ close }">
        <span class="pxat__hint">Los códigos de usuario del reloj se vinculan con cada empleado desde su ficha.</span>
        <px-button variant="secondary" @click="close">Cerrar</px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (una fila) -->
    <px-modal v-model="confirmOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxat__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxat__grow" />
        <px-button variant="secondary" :disabled="deleting" @click="close">{{ $t('Delete_cancelButtonText') }}</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deleting" @click="doDelete">{{ $t('Delete_confirmButtonText') }}</px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (selección múltiple) -->
    <px-modal v-model="confirmBulkOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxat__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxat__grow" />
        <px-button variant="secondary" :disabled="deletingBulk" @click="close">{{ $t('Delete_cancelButtonText') }}</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deletingBulk" @click="doDeleteBulk">{{ $t('Delete_confirmButtonText') }}</px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar dispositivo -->
    <px-modal v-model="confirmDeviceOpen" title="Eliminar dispositivo" size="sm">
      <p class="pxat__confirm">Los marcajes existentes se conservarán. Los vínculos de empleados quedarán sin dispositivo.</p>
      <template #footer="{ close }">
        <span class="pxat__grow" />
        <px-button variant="secondary" :disabled="deviceDeleting" @click="close">Cancelar</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deviceDeleting" @click="doRemoveDevice">Eliminar</px-button>
      </template>
    </px-modal>
  </div>
</template>

<script>
import { BFormFile } from "@/platform/bootstrap";
import { notifications } from "@/platform";
import VueClockPicker from '@pencilpix/vue2-clock-picker';
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxSelect from "@/components/px-next/PxSelect.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxSkeleton from "@/components/PxSkeleton.vue";
import VField from "@/views/app/products/next/edit/VField.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  name: "HrmAttendanceNext",
  metaInfo: { title: "Attendance" },
  components: { BFormFile,
    VueClockPicker,
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab,
    PxField, PxInput, PxSelect, PxBadge, PxEmptyState, PxModal, PxSkeleton,
    "v-field": VField, "vs-px": VsPx
  },
  data() {
    return {
      isLoading: true,
      refreshing: false,
      SubmitProcessing: false,
      importProcessing: false,
      deviceProcessing: false,
      deviceDeleting: false,
      serverParams: { columnFilters: {}, sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      selectedIds: [],
      totalRows: "",
      search: "",
      _searchTimer: null,
      limit: "10",
      editmode: false,
      employees: [],
      companies: [],
      attendances: [],
      devices: [],
      importSummary: null,
      attendance: { company_id: "", employee_id: "", date: "", clock_in: "", clock_out: "" },
      importForm: { company_id: "", device_id: null, provider: 'generic', file: null },
      deviceForm: { company_id: "", name: "", provider: 'generic', model: "", serial_number: "", connection_mode: 'import' },
      modalOpen: false,
      importModalOpen: false,
      devicesModalOpen: false,
      importDragOver: false,
      confirmOpen: false,
      pendingDelete: null,
      deleting: false,
      confirmBulkOpen: false,
      deletingBulk: false,
      confirmDeviceOpen: false,
      pendingDevice: null,
      providerOptions: [
        { label: 'Genérico', value: 'generic' },
        { label: 'ZKTeco', value: 'zkteco' },
        { label: 'Hikvision', value: 'hikvision' },
        { label: 'Otro', value: 'other' }
      ],
      connectionOptions: [
        { label: 'Importación de archivo', value: 'import' },
        { label: 'PUSH / ADMS', value: 'push' },
        { label: 'Red local', value: 'network' },
        { label: 'API', value: 'api' }
      ]
    };
  },
  computed: {
    filteredDevices() {
      if (!this.importForm.company_id) return [];
      return this.devices.filter(device => Number(device.company_id) === Number(this.importForm.company_id) && device.is_active);
    },
    columns() {
      return [
        { key: 'employee_username', label: 'Empleado', sortable: true, strong: true },
        { key: 'company_name', label: 'Compañía', sortable: true },
        { key: 'date', label: 'Fecha', sortable: true },
        { key: 'clock_in', label: 'Hora de entrada', sortable: true },
        { key: 'clock_out', label: 'Hora de salida', sortable: true },
        { key: 'total_work', label: 'Duración del trabajo', sortable: true }
      ];
    },
    rowActions() {
      return [
        { key: "edit", label: "Editar", icon: "pencil" },
        { key: "delete", label: "Eliminar", icon: "trash-2", tone: "danger" }
      ];
    }
  },
  methods: {
    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Attendances(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: v }); this.Get_Attendances(1); } },
    onSort({ key, dir }) { this.updateParams({ sort: { type: dir, field: key } }); this.Get_Attendances(this.serverParams.page); },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Attendances(1); }, 350);
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.Edit_Attendance(row);
      else if (k === "delete") { this.pendingDelete = row; this.confirmOpen = true; }
    },
    makeToast(variant, msg, title) { notifications.notify(msg, { title, variant, solid: true }); },

    Submit_Attendance() {
      this.$refs.Create_Attendance.validate().then(success => {
        if (!success) return this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        this.editmode ? this.Update_Attendance() : this.Create_Attendance();
      });
    },
    New_attendance() { this.reset_Form(); this.editmode = false; this.Get_all_companies(); this.modalOpen = true; },
    Edit_Attendance(attendance) { this.editmode = true; this.reset_Form(); this.Get_all_companies(); this.Get_employees_by_company(attendance.company_id); this.attendance = Object.assign({}, attendance); this.modalOpen = true; },
    Selected_Company(value) { if (value === null) this.attendance.company_id = ""; this.employees = []; this.attendance.employee_id = ""; if (value) this.Get_employees_by_company(value); },
    Selected_Employee(value) { if (value === null) this.attendance.employee_id = ""; },
    Get_employees_by_company(value) { axios.get("/core/get_employees_by_company?id=" + value).then(({ data }) => (this.employees = data)); },
    Get_all_companies() { return axios.get("/attendances/create").then(response => { this.companies = response.data.companies; }); },

    Get_Attendances(page) {
      NProgress.start(); NProgress.set(0.1);
      if (page && page !== 1) this.refreshing = true;
      axios.get("attendances?page=" + page + "&SortField=" + this.serverParams.sort.field + "&SortType=" + this.serverParams.sort.type + "&search=" + this.search + "&limit=" + this.limit)
        .then(response => { this.totalRows = response.data.totalRows; this.attendances = response.data.attendances; NProgress.done(); this.isLoading = false; this.refreshing = false; })
        .catch(() => { NProgress.done(); setTimeout(() => { this.isLoading = false; this.refreshing = false; }, 500); });
    },
    Create_Attendance() {
      this.SubmitProcessing = true;
      axios.post("attendances", this.attendance)
        .then(() => { this.SubmitProcessing = false; Fire.$emit("Event_Attendance"); this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success")); })
        .catch(() => { this.SubmitProcessing = false; this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed")); });
    },
    Update_Attendance() {
      this.SubmitProcessing = true;
      axios.put("attendances/" + this.attendance.id, this.attendance)
        .then(() => { this.SubmitProcessing = false; Fire.$emit("Event_Attendance"); this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success")); })
        .catch(() => { this.SubmitProcessing = false; this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed")); });
    },
    reset_Form() { this.attendance = { company_id: "", employee_id: "", date: "", clock_in: "", clock_out: "" }; },

    openImportModal() {
      this.importSummary = null;
      this.importForm = { company_id: "", device_id: null, provider: 'generic', file: null };
      Promise.all([this.Get_all_companies(), this.loadDevices()]).finally(() => { this.importModalOpen = true; });
    },
    onImportCompanyChanged() { this.importForm.device_id = null; },
    onImportDeviceChanged(id) {
      const device = this.devices.find(item => Number(item.id) === Number(id));
      if (device) this.importForm.provider = device.provider;
    },
    onImportFileChange(e) { this.importForm.file = e.target.files[0] || null; },
    onImportFileDrop(e) {
      this.importDragOver = false;
      const f = e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) this.importForm.file = f;
    },
    importPunches() {
      if (!this.importForm.company_id || !this.importForm.file) return;
      const form = new FormData();
      form.append('company_id', this.importForm.company_id);
      if (this.importForm.device_id) form.append('device_id', this.importForm.device_id);
      form.append('provider', this.importForm.provider || 'generic');
      form.append('file', this.importForm.file);
      this.importProcessing = true;
      axios.post('/attendance-integrations/import', form, { headers: { 'Content-Type': 'multipart/form-data' } })
        .then(response => {
          this.importSummary = response.data.summary;
          this.makeToast('success', 'Los marcajes fueron almacenados correctamente.', 'Importación completada');
        })
        .catch(error => this.makeToast('danger', error.response && error.response.data && error.response.data.message ? error.response.data.message : 'No se pudo importar el archivo.', 'Importación fallida'))
        .finally(() => { this.importProcessing = false; });
    },

    openDevicesModal() { Promise.all([this.Get_all_companies(), this.loadDevices()]).finally(() => { this.devicesModalOpen = true; }); },
    loadDevices() { return axios.get('/attendance-integrations/devices').then(response => { this.devices = response.data.devices || []; }); },
    createDevice() {
      this.deviceProcessing = true;
      axios.post('/attendance-integrations/devices', this.deviceForm)
        .then(() => {
          this.makeToast('success', 'Dispositivo registrado.', 'Listo');
          const companyId = this.deviceForm.company_id;
          this.deviceForm = { company_id: companyId, name: "", provider: 'generic', model: "", serial_number: "", connection_mode: 'import' };
          return this.loadDevices();
        })
        .catch(error => this.makeToast('danger', error.response && error.response.data && error.response.data.message ? error.response.data.message : 'No se pudo guardar el dispositivo.', 'Error'))
        .finally(() => { this.deviceProcessing = false; });
    },
    // Legacy usa $swal tanto para confirmar como para el borrado; aquí la
    // confirmación pasa a PxModal (regla del proyecto) y NO se agrega
    // ningún toast de resultado — el legacy tampoco lo tenía, solo
    // refrescaba la lista en silencio.
    removeDevice(device) { this.pendingDevice = device; this.confirmDeviceOpen = true; },
    doRemoveDevice() {
      if (!this.pendingDevice) return;
      this.deviceDeleting = true;
      axios.delete('/attendance-integrations/devices/' + this.pendingDevice.id)
        .then(() => this.loadDevices())
        .finally(() => { this.deviceDeleting = false; this.confirmDeviceOpen = false; this.pendingDevice = null; });
    },
    providerName(provider) { const option = this.providerOptions.find(item => item.value === provider); return option ? option.label : provider; },
    deviceLabel(device) { return device.name + (device.model ? ' · ' + device.model : ''); },

    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.deleting = true;
      axios.delete("attendances/" + row.id)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          this.pendingDelete = null;
          this.$swal(this.$t("Delete_Title"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Delete_Attendance");
        })
        .catch(() => { this.deleting = false; });
    },
    doDeleteBulk() {
      this.deletingBulk = true;
      axios.post("attendances/delete/by_selection", { selectedIds: this.selectedIds })
        .then(() => {
          this.deletingBulk = false;
          this.confirmBulkOpen = false;
          this.selectedIds = [];
          this.$swal(this.$t("Delete_Title"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Delete_Attendance");
        })
        .catch(() => { this.deletingBulk = false; });
    }
  },
  created() {
    this.Get_Attendances(1);
    Fire.$on("Event_Attendance", () => setTimeout(() => { this.Get_Attendances(this.serverParams.page); this.modalOpen = false; }, 500));
    Fire.$on("Delete_Attendance", () => setTimeout(() => this.Get_Attendances(this.serverParams.page), 500));
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxat { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxat { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxat__pad { padding: var(--pxn-space-6) 0; }

.pxat__actions { display: flex; flex-wrap: wrap; gap: var(--pxn-space-3); justify-content: flex-end; max-width: 100%; min-width: 0; }
// PxPageHeader's own actions wrapper is a flex item of the header row with no
// min-width override, so it hugs its content instead of shrinking — force it
// to participate in the row's shrink/wrap so our button group can wrap.
.pxat ::v-deep .pxn-pagehead__actions { min-width: 0; flex: 1 1 auto; }
@media (max-width: 720px) { .pxat__actions { justify-content: flex-start; } }

.pxat__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxat__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }

.pxat__grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--pxn-space-5); margin-top: var(--pxn-space-4); }
@media (max-width: 720px) { .pxat__grid { grid-template-columns: 1fr; } }
.pxat__field--full { grid-column: 1 / -1; }

.pxat__intro { display: flex; gap: var(--pxn-space-4); align-items: flex-start; padding-bottom: var(--pxn-space-5); border-bottom: 1px solid var(--pxn-border); margin-bottom: var(--pxn-space-5); }
.pxat__intro-icon { width: 36px; height: 36px; flex: none; display: flex; align-items: center; justify-content: center; border-radius: var(--pxn-radius-md); background: var(--pxn-primary-softer); color: var(--pxn-primary); }
.pxat__intro h6 { margin: 0 0 var(--pxn-space-2); font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxat__intro p { margin: 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }

.pxat__section { margin-top: var(--pxn-space-6); }
.pxat__section-title { font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); letter-spacing: 0.04em; text-transform: uppercase; color: var(--pxn-ink-3); margin-bottom: var(--pxn-space-2); }
.pxat__source-note { display: inline-flex; align-items: center; gap: var(--pxn-space-2); margin-top: var(--pxn-space-4); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxat__right { text-align: right; margin-top: var(--pxn-space-4); }
.pxat__hint { flex: 1; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxat__grow { flex: 1; }
.pxat__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }

.pxat__notice {
  display: flex; gap: var(--pxn-space-4); align-items: flex-start;
  padding: var(--pxn-space-4) var(--pxn-space-5);
  margin-bottom: var(--pxn-space-5);
  border: 1px solid var(--pxn-info-border, #bae6fd);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-info-soft, #f0f9ff);
  color: var(--pxn-info-ink, #0c4a6e);
  font-size: var(--pxn-fs-xs);
  line-height: var(--pxn-lh-snug);
}
.pxat__notice svg { flex: none; margin-top: 1px; }

.pxat__summary { display: grid; grid-template-columns: repeat(5, minmax(90px, 1fr)); gap: var(--pxn-space-3); margin: var(--pxn-space-5) 0; }
@media (max-width: 620px) { .pxat__summary { grid-template-columns: repeat(2, 1fr); } }
.pxat__summary > div { padding: var(--pxn-space-4); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); text-align: center; background: var(--pxn-surface); }
.pxat__summary strong { display: block; color: var(--pxn-ink); font-size: var(--pxn-fs-lg); }
.pxat__summary span { color: var(--pxn-ink-3); font-size: var(--pxn-fs-xs); }

.pxat__device-row { display: grid; grid-template-columns: 32px 1fr auto auto; gap: var(--pxn-space-4); align-items: center; padding: var(--pxn-space-4) 0; border-bottom: 1px solid var(--pxn-border); }
.pxat__device-row:last-child { border-bottom: 0; }
@media (max-width: 620px) { .pxat__device-row { grid-template-columns: 32px 1fr; } .pxat__device-row .pxn-badge, .pxat__device-row .pxn-btn { grid-column: 2; justify-self: start; } }
.pxat__device-icon { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: var(--pxn-radius-md); background: var(--pxn-primary-softer); color: var(--pxn-primary); }
.pxat__device-copy strong, .pxat__device-copy span { display: block; }
.pxat__device-copy strong { color: var(--pxn-ink); font-size: var(--pxn-fs-sm); }
.pxat__device-copy span { color: var(--pxn-ink-3); font-size: var(--pxn-fs-xs); margin-top: 2px; }

// Reskin local del <input type="file"> nativo — sin componente global
// PxFileInput todavía, mismo patrón que Candidatos / Solicitudes de permiso.
.pxat__file {
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
.pxat__file.is-over { border-color: var(--pxn-primary); background: var(--pxn-primary-softer); }
.pxat__file-input { position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer; z-index: 1; }
.pxat__file-btn {
  position: relative; z-index: 2;
  display: inline-flex; align-items: center; gap: var(--pxn-space-3);
  height: calc(var(--pxn-control-h-md) - 8px);
  padding: 0 var(--pxn-space-5);
  border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-sm);
  background: var(--pxn-surface-2);
  color: var(--pxn-ink-2);
  font: inherit; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium);
  cursor: pointer; flex: none; pointer-events: none;
}
.pxat__file-name { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

// Reskin local del clock-picker legacy — el widget en sí (carátula de
// reloj, popup) no se toca, solo el input disparador visible en el form.
.pxat__clock ::v-deep .vue-clock-picker input,
.pxat__clock ::v-deep input.form-control {
  width: 100%;
  height: var(--pxn-control-h-md);
  padding: 0 var(--pxn-space-5);
  border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
  color: var(--pxn-ink);
  font: inherit;
  font-size: var(--pxn-fs-body);
}
.pxat__clock.is-invalid ::v-deep input.form-control { border-color: var(--pxn-danger); }
</style>
