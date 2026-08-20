<template>
  <div class="main-content">
    <breadcumb :page="$t('Attendances')" :folder="$t('hrm')"/>

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="attendances"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-sort-change="onSortChange"
        @on-search="onSearch"
        :search-options="{ enabled: true, placeholder: $t('Search_this_table') }"
        :select-options="{ enabled: true, clearSelectionText: '' }"
        @on-selected-rows-change="selectionChanged"
        :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
        styleClass="table-hover tableOne vgt-table"
      >
        <div slot="selected-row-actions">
          <button class="btn btn-danger btn-sm" @click="delete_by_selected()">{{$t('Del')}}</button>
        </div>

        <div slot="table-actions" class="attendance-actions mt-2 mb-3">
          <b-button variant="outline-secondary" class="btn-rounded m-1" @click="openImportModal">
            <lucide-icon name="upload" class="mr-1" /> Importar marcajes
          </b-button>
          <b-button variant="outline-secondary" class="btn-rounded m-1" @click="openDevicesModal">
            <lucide-icon name="monitor" class="mr-1" /> Dispositivos
          </b-button>
          <b-button @click="New_attendance()" class="btn-rounded m-1" variant="primary">
            <lucide-icon name="plus" class="mr-1" /> Registrar manualmente
          </b-button>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'actions'">
            <a @click="Edit_Attendance(props.row)" class="cursor-pointer" title="Editar" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-success" name="pencil" />
            </a>
            <a title="Eliminar" v-b-tooltip.hover class="cursor-pointer" @click="Remove_Attendance(props.row.id)">
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>
        </template>
      </vue-good-table>
    </b-card>

    <!-- Registro manual -->
    <validation-observer ref="Create_Attendance">
      <b-modal hide-footer size="md" id="Modal_attendance" modal-class="prodex-form-modal" :title="editmode ? 'Editar asistencia' : 'Registrar asistencia'">
        <b-form @submit.prevent="Submit_Attendance">
          <div class="prodex-form-shell">
            <div class="prodex-form-intro">
              <div class="prodex-form-intro__icon"><lucide-icon name="clock" /></div>
              <div>
                <h6>{{ editmode ? 'Actualizar registro de asistencia' : 'Registro manual de asistencia' }}</h6>
                <p>{{ editmode ? 'Corrige la jornada registrada para este empleado.' : 'Úsalo cuando la asistencia no provenga de un reloj, importación u otro sistema de marcaje.' }}</p>
              </div>
            </div>

            <div class="prodex-form-section">
              <div class="prodex-form-section__title">Empleado</div>
              <div class="prodex-form-grid">
                <validation-provider name="Compañía" :rules="{ required: true}">
                  <b-form-group slot-scope="{ errors }" label="Compañía *">
                    <v-select v-model="attendance.company_id" class="required" required @input="Selected_Company" placeholder="Selecciona una compañía" :reduce="label => label.value" :options="companies.map(company => ({label: company.name, value: company.id}))" />
                    <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                  </b-form-group>
                </validation-provider>

                <validation-provider name="Empleado" :rules="{ required: true}">
                  <b-form-group slot-scope="{ errors }" label="Empleado *">
                    <v-select v-model="attendance.employee_id" class="required" required @input="Selected_Employee" placeholder="Selecciona un empleado" :reduce="label => label.value" :options="employees.map(employee => ({label: employee.username, value: employee.id}))" />
                    <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                  </b-form-group>
                </validation-provider>
              </div>
            </div>

            <div class="prodex-form-section">
              <div class="prodex-form-section__title">Jornada</div>
              <div class="prodex-form-grid">
                <div class="prodex-form-field--full">
                  <validation-provider name="Fecha" :rules="{ required: true}">
                    <b-form-group slot-scope="{ errors }" label="Fecha *">
                      <Datepicker id="date" name="date" placeholder="Selecciona la fecha de asistencia" v-model="attendance.date" input-class="form-control back_important" format="yyyy-MM-dd" @closed="attendance.date=formatDate(attendance.date)" />
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </div>

                <validation-provider name="Hora de entrada" :rules="{ required: true}">
                  <b-form-group slot-scope="{ errors }" label="Hora de entrada *">
                    <vue-clock-picker v-model="attendance.clock_in" placeholder="Hora de entrada" name="clock_in" id="clock_in" />
                    <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                  </b-form-group>
                </validation-provider>

                <validation-provider name="Hora de salida" :rules="{ required: true}">
                  <b-form-group slot-scope="{ errors }" label="Hora de salida *">
                    <vue-clock-picker v-model="attendance.clock_out" placeholder="Hora de salida" name="clock_out" id="clock_out" />
                    <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                  </b-form-group>
                </validation-provider>
              </div>
              <span class="prodex-form-source-note"><lucide-icon name="edit" /> Registro manual</span>
            </div>

            <div class="prodex-form-footer">
              <div class="prodex-form-footer__hint">La duración se calculará a partir de la entrada y salida.</div>
              <div class="prodex-form-footer__actions">
                <b-button type="button" variant="outline-secondary" :disabled="SubmitProcessing" @click="$bvModal.hide('Modal_attendance')">Cancelar</b-button>
                <b-button variant="primary" type="submit" :disabled="SubmitProcessing">
                  <lucide-icon v-if="!SubmitProcessing" class="mr-1" name="check" />
                  {{ SubmitProcessing ? 'Guardando...' : (editmode ? 'Guardar cambios' : 'Guardar asistencia') }}
                </b-button>
              </div>
            </div>
          </div>
        </b-form>
      </b-modal>
    </validation-observer>

    <!-- Importar marcajes -->
    <b-modal hide-footer size="lg" id="Attendance_Import" modal-class="prodex-form-modal" title="Importar marcajes">
      <div class="prodex-form-shell">
        <div class="prodex-form-intro">
          <div class="prodex-form-intro__icon"><lucide-icon name="upload" /></div>
          <div>
            <h6>Importar desde un reloj o sistema externo</h6>
            <p>PRODEX conservará cada marcaje original. Si reconoce el código del empleado lo vinculará automáticamente.</p>
          </div>
        </div>

        <div class="attendance-import-notice">
          <lucide-icon name="info" />
          <span>Importar marcajes todavía no crea ni modifica jornadas calculadas. Primero se guardan los eventos originales para evitar perder información.</span>
        </div>

        <div class="prodex-form-section">
          <div class="prodex-form-section__title">Origen</div>
          <div class="prodex-form-grid">
            <b-form-group label="Compañía *">
              <v-select v-model="importForm.company_id" @input="onImportCompanyChanged" placeholder="Selecciona una compañía" :reduce="label => label.value" :options="companies.map(company => ({label: company.name, value: company.id}))" />
            </b-form-group>
            <b-form-group label="Dispositivo">
              <v-select v-model="importForm.device_id" @input="onImportDeviceChanged" placeholder="Opcional" :reduce="label => label.value" :options="filteredDevices.map(device => ({label: deviceLabel(device), value: device.id}))" />
              <small class="text-muted">Si el archivo proviene de un reloj configurado, selecciónalo.</small>
            </b-form-group>
            <b-form-group label="Proveedor / formato">
              <v-select v-model="importForm.provider" :disabled="!!importForm.device_id" :reduce="label => label.value" :options="providerOptions" />
            </b-form-group>
            <b-form-group label="Archivo *">
              <b-form-file v-model="importForm.file" accept=".csv,.txt,.xls,.xlsx" placeholder="Selecciona CSV, XLS o XLSX" drop-placeholder="Suelta el archivo aquí" />
            </b-form-group>
          </div>
        </div>

        <div v-if="importSummary" class="attendance-import-summary">
          <div><strong>{{ importSummary.imported }}</strong><span>importados</span></div>
          <div><strong>{{ importSummary.matched }}</strong><span>vinculados</span></div>
          <div><strong>{{ importSummary.unmatched }}</strong><span>sin vincular</span></div>
          <div><strong>{{ importSummary.duplicates }}</strong><span>duplicados</span></div>
          <div><strong>{{ importSummary.errors }}</strong><span>con error</span></div>
        </div>

        <div class="prodex-form-footer">
          <div class="prodex-form-footer__hint">Columnas reconocidas: ID/código de empleado y fecha+hora, juntas o separadas.</div>
          <div class="prodex-form-footer__actions">
            <b-button variant="outline-secondary" @click="$bvModal.hide('Attendance_Import')">Cerrar</b-button>
            <b-button variant="primary" :disabled="importProcessing || !importForm.company_id || !importForm.file" @click="importPunches">
              {{ importProcessing ? 'Importando...' : 'Importar marcajes' }}
            </b-button>
          </div>
        </div>
      </div>
    </b-modal>

    <!-- Dispositivos -->
    <b-modal hide-footer size="lg" id="Attendance_Devices" modal-class="prodex-form-modal" title="Dispositivos de marcaje">
      <div class="prodex-form-shell">
        <div class="prodex-form-intro">
          <div class="prodex-form-intro__icon"><lucide-icon name="monitor" /></div>
          <div>
            <h6>Relojes y fuentes de marcaje</h6>
            <p>Registra los equipos que ya utiliza la empresa. No es necesario almacenar huellas ni datos biométricos en PRODEX.</p>
          </div>
        </div>

        <div class="prodex-form-section">
          <div class="prodex-form-section__title">Nuevo dispositivo</div>
          <div class="prodex-form-grid">
            <b-form-group label="Compañía *">
              <v-select v-model="deviceForm.company_id" placeholder="Selecciona una compañía" :reduce="label => label.value" :options="companies.map(company => ({label: company.name, value: company.id}))" />
            </b-form-group>
            <b-form-group label="Nombre *">
              <b-form-input v-model.trim="deviceForm.name" placeholder="Ej. Recepción principal" />
            </b-form-group>
            <b-form-group label="Proveedor *">
              <v-select v-model="deviceForm.provider" :reduce="label => label.value" :options="providerOptions" />
            </b-form-group>
            <b-form-group label="Modelo">
              <b-form-input v-model.trim="deviceForm.model" placeholder="Ej. ZKTeco F22" />
            </b-form-group>
            <b-form-group label="Número de serie">
              <b-form-input v-model.trim="deviceForm.serial_number" placeholder="Opcional" />
            </b-form-group>
            <b-form-group label="Modo de conexión">
              <v-select v-model="deviceForm.connection_mode" :reduce="label => label.value" :options="connectionOptions" />
            </b-form-group>
          </div>
          <div class="text-right">
            <b-button variant="primary" :disabled="deviceProcessing || !deviceForm.company_id || !deviceForm.name" @click="createDevice">
              <lucide-icon name="plus" class="mr-1" /> Guardar dispositivo
            </b-button>
          </div>
        </div>

        <div class="prodex-form-section">
          <div class="prodex-form-section__title">Dispositivos configurados</div>
          <div v-if="!devices.length" class="attendance-empty">Todavía no hay dispositivos registrados.</div>
          <div v-for="device in devices" :key="device.id" class="attendance-device-row">
            <div class="attendance-device-icon"><lucide-icon name="monitor" /></div>
            <div class="attendance-device-copy">
              <strong>{{ device.name }}</strong>
              <span>{{ device.company ? device.company.name : '' }} · {{ providerName(device.provider) }}<template v-if="device.model"> · {{ device.model }}</template></span>
            </div>
            <span :class="['attendance-device-status', device.is_active ? 'active' : 'inactive']">{{ device.is_active ? 'Activo' : 'Inactivo' }}</span>
            <b-button size="sm" variant="outline-danger" @click="removeDevice(device)">Eliminar</b-button>
          </div>
        </div>

        <div class="prodex-form-footer">
          <div class="prodex-form-footer__hint">Los códigos de usuario del reloj se vinculan con cada empleado desde su ficha.</div>
          <div class="prodex-form-footer__actions"><b-button variant="outline-secondary" @click="$bvModal.hide('Attendance_Devices')">Cerrar</b-button></div>
        </div>
      </div>
    </b-modal>
  </div>
</template>

<script>
import VueClockPicker from '@pencilpix/vue2-clock-picker';
import NProgress from "nprogress";
import Datepicker from 'vuejs-datepicker';

export default {
  metaInfo: { title: "Attendance" },
  components: { VueClockPicker, Datepicker },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      importProcessing: false,
      deviceProcessing: false,
      serverParams: { columnFilters: {}, sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      selectedIds: [],
      totalRows: "",
      search: "",
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
        { label: 'Empleado', field: "employee_username", tdClass: "text-left", thClass: "text-left" },
        { label: 'Compañía', field: "company_name", tdClass: "text-left", thClass: "text-left" },
        { label: 'Fecha', field: "date", tdClass: "text-left", thClass: "text-left" },
        { label: 'Hora de entrada', field: "clock_in", tdClass: "text-left", thClass: "text-left" },
        { label: 'Hora de salida', field: "clock_out", tdClass: "text-left", thClass: "text-left" },
        { label: 'Duración del trabajo', field: "total_work", tdClass: "text-left", thClass: "text-left" },
        { label: 'Acción', field: "actions", tdClass: "text-left", thClass: "text-left", sortable: false }
      ];
    }
  },
  methods: {
    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onPageChange({ currentPage }) { if (this.serverParams.page !== currentPage) { this.updateParams({ page: currentPage }); this.Get_Attendances(currentPage); } },
    onPerPageChange({ currentPerPage }) { if (this.limit !== currentPerPage) { this.limit = currentPerPage; this.updateParams({ page: 1, perPage: currentPerPage }); this.Get_Attendances(1); } },
    selectionChanged({ selectedRows }) { this.selectedIds = selectedRows.map(row => row.id); },
    onSortChange(params) { this.updateParams({ sort: { type: params[0].type, field: params[0].field } }); this.Get_Attendances(this.serverParams.page); },
    onSearch(value) { this.search = value.searchTerm; this.Get_Attendances(this.serverParams.page); },
    getValidationState({ dirty, validated, valid = null }) { return dirty || validated ? valid : null; },
    formatDate(d) { const m = d.getMonth() + 1; const day = d.getDate(); return [d.getFullYear(), m < 10 ? '0' + m : m, day < 10 ? '0' + day : day].join('-'); },
    makeToast(variant, msg, title) { this.$root.$bvToast.toast(msg, { title, variant, solid: true }); },

    Submit_Attendance() {
      this.$refs.Create_Attendance.validate().then(success => {
        if (!success) return this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        this.editmode ? this.Update_Attendance() : this.Create_Attendance();
      });
    },
    New_attendance() { this.reset_Form(); this.editmode = false; this.Get_all_companies(); this.$bvModal.show("Modal_attendance"); },
    Edit_Attendance(attendance) { this.editmode = true; this.reset_Form(); this.Get_all_companies(); this.Get_employees_by_company(attendance.company_id); this.attendance = Object.assign({}, attendance); this.$bvModal.show("Modal_attendance"); },
    Selected_Company(value) { if (value === null) this.attendance.company_id = ""; this.employees = []; this.attendance.employee_id = ""; if (value) this.Get_employees_by_company(value); },
    Selected_Employee(value) { if (value === null) this.attendance.employee_id = ""; },
    Get_employees_by_company(value) { axios.get("/core/get_employees_by_company?id=" + value).then(({ data }) => (this.employees = data)); },
    Get_all_companies() { return axios.get("/attendances/create").then(response => { this.companies = response.data.companies; }); },

    Get_Attendances(page) {
      NProgress.start(); NProgress.set(0.1);
      axios.get("attendances?page=" + page + "&SortField=" + this.serverParams.sort.field + "&SortType=" + this.serverParams.sort.type + "&search=" + this.search + "&limit=" + this.limit)
        .then(response => { this.totalRows = response.data.totalRows; this.attendances = response.data.attendances; NProgress.done(); this.isLoading = false; })
        .catch(() => { NProgress.done(); setTimeout(() => { this.isLoading = false; }, 500); });
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
      Promise.all([this.Get_all_companies(), this.loadDevices()]).finally(() => this.$bvModal.show('Attendance_Import'));
    },
    onImportCompanyChanged() { this.importForm.device_id = null; },
    onImportDeviceChanged(id) {
      const device = this.devices.find(item => Number(item.id) === Number(id));
      if (device) this.importForm.provider = device.provider;
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

    openDevicesModal() { Promise.all([this.Get_all_companies(), this.loadDevices()]).finally(() => this.$bvModal.show('Attendance_Devices')); },
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
    removeDevice(device) {
      this.$swal({ title: 'Eliminar dispositivo', text: 'Los marcajes existentes se conservarán. Los vínculos de empleados quedarán sin dispositivo.', type: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar' })
        .then(result => { if (result.value) return axios.delete('/attendance-integrations/devices/' + device.id).then(() => this.loadDevices()); });
    },
    providerName(provider) { const option = this.providerOptions.find(item => item.value === provider); return option ? option.label : provider; },
    deviceLabel(device) { return device.name + (device.model ? ' · ' + device.model : ''); },

    Remove_Attendance(id) {
      this.$swal({ title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning", showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33", cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText") })
        .then(result => { if (result.value) axios.delete("attendances/" + id).then(() => { this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success"); Fire.$emit("Delete_Attendance"); }); });
    },
    delete_by_selected() {
      this.$swal({ title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning", showCancelButton: true, confirmButtonColor: "#3085d6", cancelButtonColor: "#d33", cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText") })
        .then(result => { if (result.value) axios.post("attendances/delete/by_selection", { selectedIds: this.selectedIds }).then(() => { this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success"); Fire.$emit("Delete_Attendance"); }); });
    }
  },
  created() {
    this.Get_Attendances(1);
    Fire.$on("Event_Attendance", () => setTimeout(() => { this.Get_Attendances(this.serverParams.page); this.$bvModal.hide("Modal_attendance"); }, 500));
    Fire.$on("Delete_Attendance", () => setTimeout(() => this.Get_Attendances(this.serverParams.page), 500));
  }
};
</script>

<style scoped>
.attendance-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; }
.attendance-import-notice { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; margin-bottom: 16px; border: 1px solid #bae6fd; border-radius: 10px; background: #f0f9ff; color: #0c4a6e; font-size: 12px; line-height: 1.5; }
.attendance-import-notice svg { width: 17px; height: 17px; flex: 0 0 auto; margin-top: 1px; }
.attendance-import-summary { display: grid; grid-template-columns: repeat(5, minmax(90px,1fr)); gap: 8px; margin: 16px 0; }
.attendance-import-summary > div { padding: 12px; border: 1px solid #e4eaf1; border-radius: 10px; text-align: center; background: #fff; }
.attendance-import-summary strong { display: block; color: #18212f; font-size: 20px; }
.attendance-import-summary span { color: #667085; font-size: 11px; }
.attendance-device-row { display: grid; grid-template-columns: 36px 1fr auto auto; gap: 12px; align-items: center; padding: 12px 0; border-bottom: 1px solid #edf1f5; }
.attendance-device-row:last-child { border-bottom: 0; }
.attendance-device-icon { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 9px; background: #eef9fb; color: var(--primary-color,#38bfd3); }
.attendance-device-copy strong, .attendance-device-copy span { display: block; }
.attendance-device-copy strong { color: #253044; font-size: 13px; }
.attendance-device-copy span { color: #667085; font-size: 11px; margin-top: 2px; }
.attendance-device-status { border-radius: 999px; padding: 4px 8px; font-size: 10px; font-weight: 700; }
.attendance-device-status.active { background: #e7f8ef; color: #18794e; }
.attendance-device-status.inactive { background: #eef2f6; color: #667085; }
.attendance-empty { padding: 24px; text-align: center; color: #667085; background: #f8fafc; border-radius: 10px; }
@media (max-width: 767px) {
  .attendance-import-summary { grid-template-columns: repeat(2,1fr); }
  .attendance-device-row { grid-template-columns: 36px 1fr; }
  .attendance-device-status, .attendance-device-row .btn { grid-column: 2; justify-self: start; }
}
</style>
