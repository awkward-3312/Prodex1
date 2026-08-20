<template>
  <div class="main-content">
    <breadcumb :page="$t('Office_Shift')" :folder="$t('hrm')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-card class="wrapper" v-if="!isLoading">
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="office_shifts"
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
          <button class="btn btn-danger btn-sm" @click="delete_by_selected()">{{ $t('Del') }}</button>
        </div>
        <div slot="table-actions" class="mt-2 mb-3">
          <b-button @click="New_Office_Shift()" class="btn-rounded" variant="btn btn-primary btn-icon m-1">
            <lucide-icon name="plus" /> {{ $t('Add') }}
          </b-button>
        </div>
        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'actions'">
            <a @click="Edit_Office_Shift(props.row)" class="cursor-pointer" title="Editar" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-success" name="pencil" />
            </a>
            <a title="Eliminar" class="cursor-pointer" v-b-tooltip.hover @click="Remove_Office_Shift(props.row.id)">
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>
        </template>
      </vue-good-table>
    </b-card>

    <validation-observer ref="Create_Office_Shift">
      <b-modal hide-footer size="xl" id="New_Office_Shift" modal-class="office-shift-modal" :title="editmode ? 'Editar turno de oficina' : 'Añadir turno de oficina'">
        <b-form @submit.prevent="Submit_Office_Shift">
          <div class="shift-section">
            <div class="shift-section-heading">
              <div>
                <h6>Información del turno</h6>
                <p>Define un nombre y la compañía a la que pertenece este horario.</p>
              </div>
            </div>
            <b-row>
              <b-col md="6">
                <validation-provider name="Nombre" :rules="{ required: true }" v-slot="validationContext">
                  <b-form-group label="Nombre del turno *">
                    <b-form-input placeholder="Ej. Horario administrativo" :state="getValidationState(validationContext)" aria-describedby="Name-feedback" v-model="office_shift.name" />
                    <b-form-invalid-feedback id="Name-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                  </b-form-group>
                </validation-provider>
              </b-col>
              <b-col md="6">
                <validation-provider name="Compañía" :rules="{ required: true }">
                  <b-form-group slot-scope="{ valid, errors }" label="Compañía *">
                    <v-select
                      :class="{ 'is-invalid': !!errors.length }"
                      :state="errors[0] ? false : (valid ? true : null)"
                      v-model="office_shift.company_id"
                      class="required"
                      required
                      @input="Selected_Company"
                      placeholder="Selecciona una compañía"
                      :reduce="label => label.value"
                      :options="companies.map(company => ({ label: company.name, value: company.id }))"
                    />
                    <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                  </b-form-group>
                </validation-provider>
              </b-col>
            </b-row>
          </div>

          <div class="shift-section shift-template-section">
            <div class="shift-section-heading shift-section-heading--split">
              <div>
                <h6>Horario base</h6>
                <p>Escribe el horario una sola vez y aplícalo a los días que correspondan.</p>
              </div>
              <span class="shift-helper-badge">Ahorra tiempo</span>
            </div>

            <div class="base-schedule-grid">
              <div class="base-time-field">
                <label>Hora de entrada</label>
                <vue-clock-picker v-model="baseSchedule.in" placeholder="Entrada" />
              </div>
              <div class="base-time-field">
                <label>Hora de salida</label>
                <vue-clock-picker v-model="baseSchedule.out" placeholder="Salida" />
              </div>
              <div class="quick-presets">
                <label>Selección rápida</label>
                <div class="preset-buttons">
                  <button type="button" class="preset-btn" @click="selectPreset('weekdays')">Lun–Vie</button>
                  <button type="button" class="preset-btn" @click="selectPreset('sixdays')">Lun–Sáb</button>
                  <button type="button" class="preset-btn" @click="selectPreset('all')">Toda la semana</button>
                  <button type="button" class="preset-btn preset-btn--muted" @click="clearSelectedDays">Limpiar</button>
                </div>
              </div>
            </div>

            <div class="day-selector">
              <button
                v-for="day in days"
                :key="'selector-' + day.key"
                type="button"
                class="day-chip"
                :class="{ active: selectedDays.includes(day.key) }"
                @click="toggleSelectedDay(day.key)"
              >
                <span class="day-chip-check"><lucide-icon v-if="selectedDays.includes(day.key)" name="check" /></span>
                {{ day.short }}
              </button>
            </div>

            <div class="schedule-actions">
              <b-button type="button" variant="primary" :disabled="!selectedDays.length || !baseSchedule.in || !baseSchedule.out" @click="applyBaseSchedule">
                <lucide-icon name="copy" class="mr-1" />
                Aplicar horario a {{ selectedDays.length }} {{ selectedDays.length === 1 ? 'día' : 'días' }}
              </b-button>
              <b-button type="button" variant="outline-secondary" :disabled="!selectedDays.length" @click="markSelectedDaysOff">
                <lucide-icon name="calendar" class="mr-1" /> Marcar como días libres
              </b-button>
            </div>
            <p class="schedule-note">Aplicar un horario solo modifica los días seleccionados. Puedes ajustar cualquier día individualmente abajo.</p>
          </div>

          <div class="shift-section">
            <div class="shift-section-heading">
              <div>
                <h6>Semana laboral</h6>
                <p>Confirma los días laborables y ajusta excepciones si algún día tiene un horario diferente.</p>
              </div>
            </div>

            <div class="week-schedule">
              <div v-for="day in days" :key="day.key" class="week-day-row" :class="{ 'is-off': !isDayWorking(day.key) }">
                <div class="week-day-name">
                  <strong>{{ day.label }}</strong>
                  <span :class="['day-status', isDayWorking(day.key) ? 'working' : 'off']">{{ isDayWorking(day.key) ? 'Laborable' : 'Día libre' }}</span>
                </div>
                <div class="week-day-toggle">
                  <b-form-checkbox switch :checked="isDayWorking(day.key)" @change="setDayWorking(day.key, $event)">Trabaja</b-form-checkbox>
                </div>
                <div class="week-time-field">
                  <label>Entrada</label>
                  <vue-clock-picker v-model="office_shift[day.key + '_in']" :disabled="!isDayWorking(day.key)" placeholder="Entrada" />
                </div>
                <div class="week-time-field">
                  <label>Salida</label>
                  <vue-clock-picker v-model="office_shift[day.key + '_out']" :disabled="!isDayWorking(day.key)" placeholder="Salida" />
                </div>
              </div>
            </div>
          </div>

          <div class="shift-form-footer">
            <div class="footer-hint">Los días marcados como libres se guardarán sin hora de entrada ni salida.</div>
            <div class="footer-actions">
              <b-button type="button" variant="outline-secondary" @click="$bvModal.hide('New_Office_Shift')" :disabled="SubmitProcessing">Cancelar</b-button>
              <b-button variant="primary" type="submit" :disabled="SubmitProcessing">
                <span v-if="SubmitProcessing" class="button-spinner"></span>
                <lucide-icon v-else class="mr-1" name="check" />
                {{ SubmitProcessing ? 'Guardando...' : 'Guardar turno' }}
              </b-button>
            </div>
          </div>
        </b-form>
      </b-modal>
    </validation-observer>
  </div>
</template>

<script>
import VueClockPicker from '@pencilpix/vue2-clock-picker';
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Turnos de oficina" },

  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      serverParams: { columnFilters: {}, sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      selectedIds: [],
      totalRows: "",
      search: "",
      limit: "10",
      office_shifts: [],
      companies: [],
      editmode: false,
      days: [
        { key: 'monday', label: 'Lunes', short: 'Lun' },
        { key: 'tuesday', label: 'Martes', short: 'Mar' },
        { key: 'wednesday', label: 'Miércoles', short: 'Mié' },
        { key: 'thursday', label: 'Jueves', short: 'Jue' },
        { key: 'friday', label: 'Viernes', short: 'Vie' },
        { key: 'saturday', label: 'Sábado', short: 'Sáb' },
        { key: 'sunday', label: 'Domingo', short: 'Dom' }
      ],
      selectedDays: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
      baseSchedule: { in: "", out: "" },
      office_shift: {
        id: "", name: "", company_id: "",
        monday_in: "", monday_out: "", tuesday_in: "", tuesday_out: "",
        wednesday_in: "", wednesday_out: "", thursday_in: "", thursday_out: "",
        friday_in: "", friday_out: "", saturday_in: "", saturday_out: "",
        sunday_in: "", sunday_out: ""
      }
    };
  },

  computed: {
    columns() {
      return [
        { label: this.$t("Name"), field: "name", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Company"), field: "company_name", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Action"), field: "actions", tdClass: "text-left", thClass: "text-left", sortable: false }
      ];
    }
  },

  components: { VueClockPicker },

  methods: {
    emptyOfficeShift() {
      return {
        id: "", name: "", company_id: "",
        monday_in: "", monday_out: "", tuesday_in: "", tuesday_out: "",
        wednesday_in: "", wednesday_out: "", thursday_in: "", thursday_out: "",
        friday_in: "", friday_out: "", saturday_in: "", saturday_out: "",
        sunday_in: "", sunday_out: ""
      };
    },
    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onPageChange({ currentPage }) { if (this.serverParams.page !== currentPage) { this.updateParams({ page: currentPage }); this.Get_Office_Shift(currentPage); } },
    onPerPageChange({ currentPerPage }) { if (this.limit !== currentPerPage) { this.limit = currentPerPage; this.updateParams({ page: 1, perPage: currentPerPage }); this.Get_Office_Shift(1); } },
    selectionChanged({ selectedRows }) { this.selectedIds = selectedRows.map(row => row.id); },
    onSortChange(params) { this.updateParams({ sort: { type: params[0].type, field: params[0].field } }); this.Get_Office_Shift(this.serverParams.page); },
    onSearch(value) { this.search = value.searchTerm; this.Get_Office_Shift(this.serverParams.page); },
    getValidationState({ dirty, validated, valid = null }) { return dirty || validated ? valid : null; },

    toggleSelectedDay(dayKey) {
      const index = this.selectedDays.indexOf(dayKey);
      if (index === -1) this.selectedDays.push(dayKey); else this.selectedDays.splice(index, 1);
    },
    selectPreset(preset) {
      const presets = {
        weekdays: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        sixdays: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
        all: this.days.map(day => day.key)
      };
      this.selectedDays = (presets[preset] || []).slice();
    },
    clearSelectedDays() { this.selectedDays = []; },
    applyBaseSchedule() {
      if (!this.baseSchedule.in || !this.baseSchedule.out || !this.selectedDays.length) return;
      this.selectedDays.forEach(dayKey => {
        this.$set(this.office_shift, dayKey + '_in', this.baseSchedule.in);
        this.$set(this.office_shift, dayKey + '_out', this.baseSchedule.out);
      });
    },
    markSelectedDaysOff() {
      this.selectedDays.forEach(dayKey => {
        this.$set(this.office_shift, dayKey + '_in', "");
        this.$set(this.office_shift, dayKey + '_out', "");
      });
    },
    isDayWorking(dayKey) { return !!(this.office_shift[dayKey + '_in'] || this.office_shift[dayKey + '_out']); },
    setDayWorking(dayKey, working) {
      if (!working) {
        this.$set(this.office_shift, dayKey + '_in', "");
        this.$set(this.office_shift, dayKey + '_out', "");
        return;
      }
      if (!this.office_shift[dayKey + '_in'] && this.baseSchedule.in) this.$set(this.office_shift, dayKey + '_in', this.baseSchedule.in);
      if (!this.office_shift[dayKey + '_out'] && this.baseSchedule.out) this.$set(this.office_shift, dayKey + '_out', this.baseSchedule.out);
    },
    hasIncompleteDay() {
      return this.days.some(day => {
        const timeIn = this.office_shift[day.key + '_in'];
        const timeOut = this.office_shift[day.key + '_out'];
        return (!!timeIn && !timeOut) || (!timeIn && !!timeOut);
      });
    },

    Submit_Office_Shift() {
      this.$refs.Create_Office_Shift.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
          return;
        }
        if (this.hasIncompleteDay()) {
          this.makeToast("warning", "Cada día laborable debe tener hora de entrada y hora de salida. Si no se trabaja ese día, márcalo como día libre.", "Revisa el horario");
          return;
        }
        if (!this.editmode) this.Create_Office_Shift(); else this.Update_Office_Shift();
      });
    },
    makeToast(variant, msg, title) { this.$root.$bvToast.toast(msg, { title, variant, solid: true }); },
    New_Office_Shift() { this.reset_Form(); this.Get_Data_Create(); this.editmode = false; this.$bvModal.show("New_Office_Shift"); },
    Edit_Office_Shift(office_shift) {
      this.reset_Form();
      this.editmode = true;
      this.Get_Data_Edit(office_shift.id);
      this.office_shift = Object.assign(this.emptyOfficeShift(), office_shift);
      this.syncBaseScheduleFromShift();
      this.$bvModal.show("New_Office_Shift");
    },
    syncBaseScheduleFromShift() {
      const firstWorkingDay = this.days.find(day => this.office_shift[day.key + '_in'] && this.office_shift[day.key + '_out']);
      this.baseSchedule = firstWorkingDay ? { in: this.office_shift[firstWorkingDay.key + '_in'], out: this.office_shift[firstWorkingDay.key + '_out'] } : { in: "", out: "" };
      this.selectedDays = this.days.filter(day => this.isDayWorking(day.key)).map(day => day.key);
    },
    Get_Data_Create() { axios.get("/office_shift/create").then(response => { this.companies = response.data.companies; }).catch(() => {}); },
    Get_Data_Edit(id) { axios.get("/office_shift/" + id + "/edit").then(response => { this.companies = response.data.companies; }).catch(() => {}); },
    Selected_Company(value) { if (value === null) this.office_shift.company_id = ""; },

    Get_Office_Shift(page) {
      NProgress.start();
      NProgress.set(0.1);
      axios.get("office_shift?page=" + page + "&SortField=" + this.serverParams.sort.field + "&SortType=" + this.serverParams.sort.type + "&search=" + this.search + "&limit=" + this.limit)
        .then(response => {
          this.office_shifts = response.data.office_shifts;
          this.totalRows = response.data.totalRows;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => { NProgress.done(); setTimeout(() => { this.isLoading = false; }, 500); });
    },
    officeShiftPayload() {
      const payload = { name: this.office_shift.name, company_id: this.office_shift.company_id };
      this.days.forEach(day => {
        payload[day.key + '_in'] = this.office_shift[day.key + '_in'] || "";
        payload[day.key + '_out'] = this.office_shift[day.key + '_out'] || "";
      });
      return payload;
    },
    Create_Office_Shift() {
      this.SubmitProcessing = true;
      axios.post("office_shift", this.officeShiftPayload())
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Office_Shift");
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
        })
        .catch(() => { this.SubmitProcessing = false; this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed")); });
    },
    Update_Office_Shift() {
      this.SubmitProcessing = true;
      axios.put("office_shift/" + this.office_shift.id, this.officeShiftPayload())
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Office_Shift");
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => { this.SubmitProcessing = false; this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed")); });
    },
    reset_Form() {
      this.office_shift = this.emptyOfficeShift();
      this.baseSchedule = { in: "", out: "" };
      this.selectedDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
    },
    Remove_Office_Shift(id) {
      this.$swal({
        title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning", showCancelButton: true,
        confirmButtonColor: "#3085d6", cancelButtonColor: "#d33", cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios.delete("office_shift/" + id)
            .then(() => { this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success"); Fire.$emit("Delete_Office_Shift"); })
            .catch(() => { this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning"); });
        }
      });
    },
    delete_by_selected() {
      this.$swal({
        title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning", showCancelButton: true,
        confirmButtonColor: "#3085d6", cancelButtonColor: "#d33", cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          NProgress.start();
          NProgress.set(0.1);
          axios.post("office_shift/delete/by_selection", { selectedIds: this.selectedIds })
            .then(() => { this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success"); Fire.$emit("Delete_Office_Shift"); })
            .catch(() => { setTimeout(() => NProgress.done(), 500); this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning"); });
        }
      });
    }
  },

  created() {
    this.Get_Office_Shift(1);
    Fire.$on("Event_Office_Shift", () => {
      setTimeout(() => { this.Get_Office_Shift(this.serverParams.page); this.$bvModal.hide("New_Office_Shift"); }, 500);
    });
    Fire.$on("Delete_Office_Shift", () => {
      setTimeout(() => { this.Get_Office_Shift(this.serverParams.page); }, 500);
    });
  }
};
</script>

<style scoped>
.shift-section { border: 1px solid #e4eaf1; border-radius: 12px; padding: 20px; margin-bottom: 18px; background: #fff; }
.shift-template-section { background: #fbfdff; }
.shift-section-heading { display: flex; align-items: flex-start; margin-bottom: 18px; }
.shift-section-heading--split { justify-content: space-between; gap: 16px; }
.shift-section-heading h6 { margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #18212f; }
.shift-section-heading p { margin: 0; color: #667085; font-size: 13px; }
.shift-helper-badge { background: rgba(56,191,211,.12); color: #17879a; border-radius: 999px; padding: 5px 10px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.base-schedule-grid { display: grid; grid-template-columns: minmax(160px,1fr) minmax(160px,1fr) minmax(280px,1.5fr); gap: 14px; align-items: end; }
.base-time-field label, .quick-presets > label, .week-time-field label { display: block; margin-bottom: 6px; color: #475467; font-size: 12px; font-weight: 600; }
.preset-buttons { display: flex; flex-wrap: wrap; gap: 7px; }
.preset-btn { border: 1px solid #d6dee8; background: #fff; color: #344054; border-radius: 8px; padding: 8px 10px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all .15s ease; }
.preset-btn:hover { border-color: var(--primary-color,#38bfd3); color: var(--primary-color,#17879a); }
.preset-btn--muted { color: #667085; }
.day-selector { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 18px; }
.day-chip { display: inline-flex; align-items: center; gap: 7px; border: 1px solid #d7e0ea; background: #fff; color: #475467; border-radius: 999px; padding: 7px 12px 7px 8px; font-size: 12px; font-weight: 600; cursor: pointer; }
.day-chip.active { border-color: var(--primary-color,#38bfd3); background: rgba(56,191,211,.10); color: #167b8c; }
.day-chip-check { width: 18px; height: 18px; border-radius: 50%; border: 1px solid #cbd5e1; display: inline-flex; align-items: center; justify-content: center; }
.day-chip.active .day-chip-check { background: var(--primary-color,#38bfd3); border-color: var(--primary-color,#38bfd3); color: #fff; }
.day-chip-check svg { width: 12px; height: 12px; }
.schedule-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
.schedule-note { margin: 10px 0 0; color: #7a8597; font-size: 12px; }
.week-schedule { display: flex; flex-direction: column; gap: 8px; }
.week-day-row { display: grid; grid-template-columns: minmax(145px,1fr) 110px minmax(150px,1fr) minmax(150px,1fr); gap: 14px; align-items: center; padding: 12px 14px; border: 1px solid #e5ebf2; border-radius: 10px; background: #fff; }
.week-day-row.is-off { background: #f8fafc; }
.week-day-name { display: flex; align-items: center; gap: 9px; }
.week-day-name strong { color: #253044; font-size: 13px; }
.day-status { display: inline-flex; border-radius: 999px; padding: 3px 8px; font-size: 10px; font-weight: 700; }
.day-status.working { background: #e7f8ef; color: #18794e; }
.day-status.off { background: #eef2f6; color: #667085; }
.week-day-toggle { font-size: 12px; }
.week-time-field label { margin-bottom: 4px; }
.shift-form-footer { position: sticky; bottom: -16px; z-index: 5; display: flex; align-items: center; justify-content: space-between; gap: 20px; margin: 0 -16px -16px; padding: 14px 20px; border-top: 1px solid #e3e8ef; background: rgba(255,255,255,.97); backdrop-filter: blur(8px); }
.footer-hint { color: #667085; font-size: 12px; }
.footer-actions { display: flex; gap: 9px; flex-shrink: 0; }
.button-spinner { width: 14px; height: 14px; display: inline-block; border: 2px solid rgba(255,255,255,.45); border-top-color: #fff; border-radius: 50%; margin-right: 6px; vertical-align: -2px; animation: shift-spin .7s linear infinite; }
@keyframes shift-spin { to { transform: rotate(360deg); } }
@media (max-width: 991px) {
  .base-schedule-grid { grid-template-columns: 1fr 1fr; }
  .quick-presets { grid-column: 1 / -1; }
  .week-day-row { grid-template-columns: 1fr 110px; }
}
@media (max-width: 575px) {
  .shift-section { padding: 14px; }
  .base-schedule-grid, .week-day-row { grid-template-columns: 1fr; }
  .shift-form-footer { position: static; margin: 0; padding: 14px 0 0; flex-direction: column; align-items: stretch; }
  .footer-actions { justify-content: flex-end; }
}
</style>
