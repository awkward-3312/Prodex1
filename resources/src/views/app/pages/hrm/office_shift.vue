<template>
  <div class="px-next pxos">
    <!--
      Migracion px-next — Turnos de oficina (Office Shift). Ruta real sin
      cambios (/app/hrm/office_shift). Presentación únicamente, auditada a
      fondo contra OfficeShiftController (index/create/store/edit/update/
      destroy/delete_by_selection), Office_ShiftPolicy y el modelo
      OfficeShift antes de tocar nada.

      Hallazgos clave, verificados y preservados EXACTOS:

      - Estructura del turno: `name`, `company_id` + 14 columnas string
        nullable (`{day}_in`/`{day}_out` para monday..sunday). Sin
        `is_active` — solo `deleted_at` (soft-delete manual, sin trait
        SoftDeletes, igual patrón que el resto de HRM).
      - El clock-picker (`@pencilpix/vue2-clock-picker`) entrega "HH:mm"
        24h (ej. "17:00"). `store()`/`update()` hacen
        `new DateTime($valor)->format('H:iA')` — 'H' es hora 24h, 'A' es
        el indicador AM/PM; para cualquier hora >=13 esto produce un
        string inválido tipo "17:00PM" (debería ser 'h:iA' minúscula
        para 12h). Reproducido en vivo, es EXACTAMENTE el bug ya
        documentado en Asistencia — NO se corrige aquí.
      - `index()` neutraliza el problema para la propia lista: hace
        `substr($valor, 0, -2)` sobre el string guardado, cortando
        siempre los últimos 2 caracteres (sean "AM"/"PM" válidos o no)
        y devolviendo "HH:mm" 24h al frontend — por eso el editor
        siempre muestra la hora correcta al reabrir, aunque la DB tenga
        "17:00PM". El bug es cosmético para esta vista, pero
        `AttendancesController::dateTimeForAttendance` sí lo sufre (ver
        auditoría de Asistencia) porque consume el campo crudo.
      - `update()` además detecta con `strlen($valor) == 5` si el valor
        que llega ya es "HH:mm" plano o si ya trae sufijo de 7
        caracteres, y ajusta con `substr(...,0,-2)` antes de reparsear
        — defensivo, no se toca.
      - Eliminar (`destroy()`/`delete_by_selection()`): SOLO soft-delete
        (`deleted_at = now()`), la fila sigue en la tabla. NO hay
        `is_active`. Los empleados que ya tenían `office_shift_id`
        apuntando a ese turno NO se actualizan — conservan la FK intacta
        (`Employee::office_shift()` es un `hasOne` sin filtro de
        `deleted_at`), así que un turno "eliminado" sigue siendo
        resuelto y aplicado en cálculos de asistencia. Bug ya
        documentado en Asistencia — NO se corrige aquí.
      - Permiso plano único `office_shift` (`Office_ShiftPolicy`) para
        view/create/update/delete — sin permiso separado, no se inventó
        ninguno.
      - Sin cascada de selects: la única FK es `company_id` (select
        simple, sin dependientes).
      - Paginación real (vue-good-table `pagination-options.enabled:
        true`, igual que el resto de vistas HRM) — se conserva
        PxPagination.
      - El editor semanal (horario base + selección rápida + chips de
        día + fila por día con switch + 2 clock-pickers) NO tiene
        equivalente en PX Next. Se conserva TODA su lógica JS intacta
        (mismos nombres de props/métodos/eventos) — solo reskin local
        de clases y tokens, sin nuevo componente global.
    -->
    <px-page-header :title="$t('Office_Shift')" :breadcrumbs="[{ label: $t('hrm') }, { label: $t('Office_Shift') }]">
      <template #actions>
        <div class="pxos__actions">
          <px-button
            v-if="selectedIds.length"
            variant="danger"
            icon="trash-2"
            @click="confirmBulkOpen = true"
          >{{ $t('Del') }} ({{ selectedIds.length }})</px-button>
          <px-button variant="primary" icon="plus" @click="New_Office_Shift">{{ $t('Add') }}</px-button>
        </div>
      </template>
    </px-page-header>

    <px-toolbar :search="search" :search-placeholder="$t('Search_this_table')" @update:search="onSearchInput" />

    <div v-if="isLoading" class="pxos__pad">
      <px-skeleton variant="table" :rows="8" :columns="3" />
    </div>

    <template v-else>
      <div class="pxos__tablewrap" :class="{ 'is-busy': refreshing }">
        <px-table
          v-if="office_shifts.length"
          :columns="columns"
          :rows="office_shifts"
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

        <px-empty-state v-else icon="clock" :title="$t('Office_Shift')" description="Sin turnos que coincidan con la búsqueda." />
      </div>

      <px-pagination
        v-if="office_shifts.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        :per-page-options="['10', '20', '30', '40', '50']"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <!-- Crear / editar turno -->
    <px-modal v-model="modalOpen" :title="editmode ? 'Editar turno de oficina' : 'Añadir turno de oficina'" size="xl">
      <px-validation-observer ref="Create_Office_Shift">
        <form @submit.prevent="Submit_Office_Shift">
          <div class="pxos__section">
            <div class="pxos__section-head">
              <div>
                <h6>Información del turno</h6>
                <p>Define un nombre y la compañía a la que pertenece este horario.</p>
              </div>
            </div>
            <div class="pxos__grid2">
              <v-field name="Nombre" label="Nombre del turno" required :rules="{ required: true }" v-slot="{ invalid, id }">
                <px-input :id="id" v-model="office_shift.name" placeholder="Ej. Horario administrativo" :invalid="invalid" />
              </v-field>

              <v-field name="Compañía" label="Compañía" required :rules="{ required: true }" v-slot="{ invalid, id }">
                <vs-px
                  :input-id="id"
                  :invalid="invalid"
                  v-model="office_shift.company_id"
                  @input="Selected_Company"
                  :reduce="o => o.value"
                  placeholder="Selecciona una compañía"
                  :options="companies.map(c => ({ label: c.name, value: c.id }))"
                />
              </v-field>
            </div>
          </div>

          <div class="pxos__section pxos__section--template">
            <div class="pxos__section-head pxos__section-head--split">
              <div>
                <h6>Horario base</h6>
                <p>Escribe el horario una sola vez y aplícalo a los días que correspondan.</p>
              </div>
              <span class="pxos__helper-badge">Ahorra tiempo</span>
            </div>

            <div class="pxos__base-grid">
              <div class="pxos__time-field">
                <label>Hora de entrada</label>
                <div class="pxos__clock"><vue-clock-picker v-model="baseSchedule.in" placeholder="Entrada" /></div>
              </div>
              <div class="pxos__time-field">
                <label>Hora de salida</label>
                <div class="pxos__clock"><vue-clock-picker v-model="baseSchedule.out" placeholder="Salida" /></div>
              </div>
              <div class="pxos__presets">
                <label>Selección rápida</label>
                <div class="pxos__preset-buttons">
                  <button type="button" class="pxos__preset-btn" @click="selectPreset('weekdays')">Lun–Vie</button>
                  <button type="button" class="pxos__preset-btn" @click="selectPreset('sixdays')">Lun–Sáb</button>
                  <button type="button" class="pxos__preset-btn" @click="selectPreset('all')">Toda la semana</button>
                  <button type="button" class="pxos__preset-btn pxos__preset-btn--muted" @click="clearSelectedDays">Limpiar</button>
                </div>
              </div>
            </div>

            <div class="pxos__day-selector">
              <button
                v-for="day in days"
                :key="'selector-' + day.key"
                type="button"
                class="pxos__day-chip"
                :class="{ 'is-active': selectedDays.includes(day.key) }"
                @click="toggleSelectedDay(day.key)"
              >
                <span class="pxos__day-chip-check"><lucide-icon v-if="selectedDays.includes(day.key)" name="check" :size="12" /></span>
                {{ day.short }}
              </button>
            </div>

            <div class="pxos__schedule-actions">
              <px-button type="button" variant="primary" icon="copy" :disabled="!selectedDays.length || !baseSchedule.in || !baseSchedule.out" @click="applyBaseSchedule">
                Aplicar horario a {{ selectedDays.length }} {{ selectedDays.length === 1 ? 'día' : 'días' }}
              </px-button>
              <px-button type="button" variant="secondary" icon="calendar" :disabled="!selectedDays.length" @click="markSelectedDaysOff">
                Marcar como días libres
              </px-button>
            </div>
            <p class="pxos__schedule-note">Aplicar un horario solo modifica los días seleccionados. Puedes ajustar cualquier día individualmente abajo.</p>
          </div>

          <div class="pxos__section">
            <div class="pxos__section-head">
              <div>
                <h6>Semana laboral</h6>
                <p>Confirma los días laborables y ajusta excepciones si algún día tiene un horario diferente.</p>
              </div>
            </div>

            <div class="pxos__week">
              <div v-for="day in days" :key="day.key" class="pxos__week-row" :class="{ 'is-off': !isDayWorking(day.key) }">
                <div class="pxos__week-name">
                  <strong>{{ day.label }}</strong>
                  <span :class="['pxos__day-status', isDayWorking(day.key) ? 'is-working' : 'is-off']">{{ isDayWorking(day.key) ? 'Laborable' : 'Día libre' }}</span>
                </div>
                <div class="pxos__week-toggle">
                  <b-form-checkbox switch :checked="isDayWorking(day.key)" @change="setDayWorking(day.key, $event)">Trabaja</b-form-checkbox>
                </div>
                <div class="pxos__week-time">
                  <label>Entrada</label>
                  <div class="pxos__clock"><vue-clock-picker v-model="office_shift[day.key + '_in']" :disabled="!isDayWorking(day.key)" placeholder="Entrada" /></div>
                </div>
                <div class="pxos__week-time">
                  <label>Salida</label>
                  <div class="pxos__clock"><vue-clock-picker v-model="office_shift[day.key + '_out']" :disabled="!isDayWorking(day.key)" placeholder="Salida" /></div>
                </div>
              </div>
            </div>
          </div>
        </form>
      </px-validation-observer>

      <template #footer="{ close }">
        <span class="pxos__hint">Los días marcados como libres se guardarán sin hora de entrada ni salida.</span>
        <px-button variant="secondary" :disabled="SubmitProcessing" @click="close">Cancelar</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" @click="Submit_Office_Shift">
          {{ SubmitProcessing ? 'Guardando...' : 'Guardar turno' }}
        </px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (una fila) -->
    <px-modal v-model="confirmOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxos__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxos__grow" />
        <px-button variant="secondary" :disabled="deleting" @click="close">{{ $t('Delete_cancelButtonText') }}</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deleting" @click="doDelete">{{ $t('Delete_confirmButtonText') }}</px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (selección múltiple) -->
    <px-modal v-model="confirmBulkOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxos__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxos__grow" />
        <px-button variant="secondary" :disabled="deletingBulk" @click="close">{{ $t('Delete_cancelButtonText') }}</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deletingBulk" @click="doDeleteBulk">{{ $t('Delete_confirmButtonText') }}</px-button>
      </template>
    </px-modal>
  </div>
</template>

<script>
import { notifications } from "@/platform";
import VueClockPicker from '@pencilpix/vue2-clock-picker';
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxSkeleton from "@/components/PxSkeleton.vue";
import VField from "@/views/app/products/next/edit/VField.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: "Turnos de oficina" },
  components: {
    VueClockPicker,
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab,
    PxInput, PxEmptyState, PxModal, PxSkeleton, "v-field": VField, "vs-px": VsPx
  },

  data() {
    return {
      isLoading: true,
      refreshing: false,
      SubmitProcessing: false,
      serverParams: { columnFilters: {}, sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      selectedIds: [],
      totalRows: "",
      search: "",
      _searchTimer: null,
      limit: "10",
      office_shifts: [],
      companies: [],
      editmode: false,
      modalOpen: false,
      confirmOpen: false,
      pendingDelete: null,
      deleting: false,
      confirmBulkOpen: false,
      deletingBulk: false,
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
        { key: "name", label: this.$t("Name"), sortable: true, strong: true },
        { key: "company_name", label: this.$t("Company"), sortable: true }
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
    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Office_Shift(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: v }); this.Get_Office_Shift(1); } },
    onSort({ key, dir }) { this.updateParams({ sort: { type: dir, field: key } }); this.Get_Office_Shift(this.serverParams.page); },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Office_Shift(1); }, 350);
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.Edit_Office_Shift(row);
      else if (k === "delete") { this.pendingDelete = row; this.confirmOpen = true; }
    },

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
    makeToast(variant, msg, title) { notifications.notify(msg, { title, variant, solid: true }); },
    New_Office_Shift() { this.reset_Form(); this.Get_Data_Create(); this.editmode = false; this.modalOpen = true; },
    Edit_Office_Shift(office_shift) {
      this.reset_Form();
      this.editmode = true;
      this.Get_Data_Edit(office_shift.id);
      this.office_shift = Object.assign(this.emptyOfficeShift(), office_shift);
      this.syncBaseScheduleFromShift();
      this.modalOpen = true;
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
      if (page && page !== 1) this.refreshing = true;
      NProgress.start();
      NProgress.set(0.1);
      axios.get("office_shift?page=" + page + "&SortField=" + this.serverParams.sort.field + "&SortType=" + this.serverParams.sort.type + "&search=" + this.search + "&limit=" + this.limit)
        .then(response => {
          this.office_shifts = response.data.office_shifts;
          this.totalRows = response.data.totalRows;
          NProgress.done();
          this.isLoading = false;
          this.refreshing = false;
        })
        .catch(() => { NProgress.done(); setTimeout(() => { this.isLoading = false; this.refreshing = false; }, 500); });
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

    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.deleting = true;
      axios.delete("office_shift/" + row.id)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          this.pendingDelete = null;
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Delete_Office_Shift");
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
      axios.post("office_shift/delete/by_selection", { selectedIds: this.selectedIds })
        .then(() => {
          this.deletingBulk = false;
          this.confirmBulkOpen = false;
          this.selectedIds = [];
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Delete_Office_Shift");
        })
        .catch(() => {
          this.deletingBulk = false;
          setTimeout(() => NProgress.done(), 500);
          this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
        });
    }
  },

  created() {
    this.Get_Office_Shift(1);
    Fire.$on("Event_Office_Shift", () => {
      setTimeout(() => { this.Get_Office_Shift(this.serverParams.page); this.modalOpen = false; }, 500);
    });
    Fire.$on("Delete_Office_Shift", () => {
      setTimeout(() => { this.Get_Office_Shift(this.serverParams.page); }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxos { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxos { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxos__pad { padding: var(--pxn-space-6) 0; }

.pxos__actions { display: flex; flex-wrap: wrap; gap: var(--pxn-space-3); justify-content: flex-end; max-width: 100%; min-width: 0; }
.pxos ::v-deep .pxn-pagehead__actions { min-width: 0; flex: 1 1 auto; }
@media (max-width: 720px) { .pxos__actions { justify-content: flex-start; } }

.pxos__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxos__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }

.pxos__grow { flex: 1; }
.pxos__hint { flex: 1; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxos__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }

// ---- Editor semanal (reskin local, sin componente global) ----
.pxos__section { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); padding: var(--pxn-space-6); margin-bottom: var(--pxn-space-5); background: var(--pxn-surface); }
.pxos__section--template { background: var(--pxn-surface-2); }
.pxos__section-head { display: flex; align-items: flex-start; margin-bottom: var(--pxn-space-5); }
.pxos__section-head--split { justify-content: space-between; gap: var(--pxn-space-5); }
.pxos__section-head h6 { margin: 0 0 var(--pxn-space-2); font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxos__section-head p { margin: 0; color: var(--pxn-ink-2); font-size: var(--pxn-fs-sm); }
.pxos__helper-badge { background: var(--pxn-primary-softer); color: var(--pxn-primary-ink); border-radius: var(--pxn-radius-pill); padding: 5px 10px; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-bold); white-space: nowrap; }

.pxos__grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: var(--pxn-space-5); }
@media (max-width: 640px) { .pxos__grid2 { grid-template-columns: 1fr; } }

.pxos__base-grid { display: grid; grid-template-columns: minmax(160px,1fr) minmax(160px,1fr) minmax(280px,1.5fr); gap: var(--pxn-space-4); align-items: end; }
.pxos__time-field label, .pxos__presets > label, .pxos__week-time label { display: block; margin-bottom: var(--pxn-space-2); color: var(--pxn-ink-2); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); }
.pxos__preset-buttons { display: flex; flex-wrap: wrap; gap: var(--pxn-space-2); }
.pxos__preset-btn { border: 1px solid var(--pxn-border-control); background: var(--pxn-surface); color: var(--pxn-ink-2); border-radius: var(--pxn-radius-sm); padding: var(--pxn-space-3) var(--pxn-space-4); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); cursor: pointer; transition: border-color var(--pxn-dur-1) var(--pxn-ease), color var(--pxn-dur-1) var(--pxn-ease); }
.pxos__preset-btn:hover { border-color: var(--pxn-primary); color: var(--pxn-primary-ink); }
.pxos__preset-btn--muted { color: var(--pxn-ink-3); }
@media (max-width: 991px) {
  .pxos__base-grid { grid-template-columns: 1fr 1fr; }
  .pxos__presets { grid-column: 1 / -1; }
}
@media (max-width: 575px) { .pxos__base-grid { grid-template-columns: 1fr; } }

.pxos__day-selector { display: flex; flex-wrap: wrap; gap: var(--pxn-space-3); margin-top: var(--pxn-space-5); }
.pxos__day-chip { display: inline-flex; align-items: center; gap: var(--pxn-space-2); border: 1px solid var(--pxn-border-control); background: var(--pxn-surface); color: var(--pxn-ink-2); border-radius: var(--pxn-radius-pill); padding: 7px 12px 7px 8px; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); cursor: pointer; transition: border-color var(--pxn-dur-1) var(--pxn-ease), background var(--pxn-dur-1) var(--pxn-ease), color var(--pxn-dur-1) var(--pxn-ease); }
.pxos__day-chip.is-active { border-color: var(--pxn-primary); background: var(--pxn-primary-softer); color: var(--pxn-primary-ink); }
.pxos__day-chip-check { width: 18px; height: 18px; border-radius: 50%; border: 1px solid var(--pxn-border-control); display: inline-flex; align-items: center; justify-content: center; flex: none; }
.pxos__day-chip.is-active .pxos__day-chip-check { background: var(--pxn-primary); border-color: var(--pxn-primary); color: var(--pxn-primary-contrast); }

.pxos__schedule-actions { display: flex; flex-wrap: wrap; gap: var(--pxn-space-3); margin-top: var(--pxn-space-5); }
.pxos__schedule-note { margin: var(--pxn-space-4) 0 0; color: var(--pxn-ink-3); font-size: var(--pxn-fs-xs); }

.pxos__week { display: flex; flex-direction: column; gap: var(--pxn-space-3); }
.pxos__week-row { display: grid; grid-template-columns: minmax(145px,1fr) 110px minmax(150px,1fr) minmax(150px,1fr); gap: var(--pxn-space-4); align-items: center; padding: var(--pxn-space-4); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); }
.pxos__week-row.is-off { background: var(--pxn-surface-2); }
.pxos__week-name { display: flex; align-items: center; gap: var(--pxn-space-3); }
.pxos__week-name strong { color: var(--pxn-ink); font-size: var(--pxn-fs-sm); }
.pxos__day-status { display: inline-flex; border-radius: var(--pxn-radius-pill); padding: 3px 8px; font-size: 10px; font-weight: var(--pxn-fw-bold); }
.pxos__day-status.is-working { background: var(--pxn-success-soft, #e7f8ef); color: var(--pxn-success-ink, #18794e); }
.pxos__day-status.is-off { background: var(--pxn-surface-3); color: var(--pxn-ink-3); }
.pxos__week-toggle { font-size: var(--pxn-fs-xs); }
@media (max-width: 991px) { .pxos__week-row { grid-template-columns: 1fr 110px; } }
@media (max-width: 575px) { .pxos__week-row { grid-template-columns: 1fr; } }

// Reskin local del clock-picker legacy — el widget en sí (carátula de
// reloj, popup) no se toca, solo el input disparador visible en el form.
.pxos__clock ::v-deep .vue-clock-picker input,
.pxos__clock ::v-deep input.form-control {
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
.pxos__clock ::v-deep input.form-control:disabled { background: var(--pxn-surface-3); color: var(--pxn-ink-disabled); }
</style>
