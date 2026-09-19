<template>
  <div class="px-next pxintv">
    <!--
      Migracion px-next — Entrevistas (Recruit Interviews). Ruta real sin
      cambios (/app/recruit/interviews). Presentación únicamente, auditada
      contra RecruitController@interviews_* y RecruitInterviewPolicy antes
      de tocar nada.

      Hallazgo clave, preservado EXACTO sin simplificar (instrucción
      explícita): `Edit_Interview` reformatea `scheduled_at` para el input
      nativo `datetime-local`, que exige el formato "YYYY-MM-DDTHH:mm"
      exacto. El backend serializa `scheduled_at` (cast `datetime` en el
      modelo) como ISO8601 con "T" y sufijo de zona
      ("2026-09-14T10:00:00.000000Z"). El legacy hace:
        scheduled_at.replace(" ", "T").substring(0, 16)
      El `.replace(" ", "T")` es defensivo/no-op sobre el formato ISO real
      (no hay espacio que reemplazar), pero el `.substring(0, 16)` es lo
      que realmente importa: recorta a "YYYY-MM-DDTHH:mm", exactamente lo
      que el input nativo necesita. Se preserva la lógica completa tal
      cual, incluida la parte aparentemente redundante — no se "limpia".
      Al guardar, el string que produce el input datetime-local se manda
      tal cual en el payload (`scheduled_at`), sin transformación
      adicional — igual que el legacy. No se tocó zona horaria: el
      comportamiento (incluida cualquier conversión UTC/local que ya
      exista en el backend) se conserva sin modificar.

      Otros hallazgos:
      - `interviews_store` AUTO-AVANZA el `stage` de la postulación
        asociada a "interview" si estaba en applied/screening/shortlisted
        — efecto secundario 100% backend, invisible en el formulario,
        no se replica nada en cliente.
      - RecruitInterview usa SoftDeletes real — "Eliminar" es soft-delete
        genuino, igual que Vacantes/Postulaciones.
      - application_id es un select poblado desde `applications_all`
        (candidato + vacante concatenados como label) — sin paginar,
        igual que legacy.
      - Selección múltiple + bulk delete reales.
      - Un solo evento Fire ("Event_Interview") cubre todo el CRUD.
    -->
    <px-page-header :title="$t('Interviews')" :breadcrumbs="[{ label: $t('Recruit') }, { label: $t('Interviews') }]">
      <template #actions>
        <px-button
          v-if="selectedIds.length"
          variant="danger"
          icon="trash-2"
          @click="confirmBulkOpen = true"
        >{{ $t('Del') }} ({{ selectedIds.length }})</px-button>
        <px-button variant="primary" icon="plus" @click="New_Interview">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar :search="search" :search-placeholder="$t('Search_this_table')" @update:search="onSearchInput">
      <template #filters>
        <px-select
          v-model="status_filter"
          :options="statusFilterOptions"
          placeholder="Todos"
          @input="onStatusFilter"
        />
      </template>
    </px-toolbar>

    <div v-if="isLoading" class="pxintv__pad">
      <px-skeleton variant="table" :rows="8" :columns="6" />
    </div>

    <template v-else>
      <div class="pxintv__tablewrap" :class="{ 'is-busy': refreshing }">
        <px-table
          v-if="interviews.length"
          :columns="columns"
          :rows="interviews"
          row-key="id"
          selectable
          :selected="selectedIds"
          @update:selected="selectedIds = $event"
          :sort-key="sort.field"
          :sort-dir="sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-candidate="{ row }">{{ candidateName(row) }}</template>
          <template #cell-job="{ row }">{{ row.application && row.application.job ? row.application.job.title : '-' }}</template>
          <template #cell-type="{ value }"><px-tag :label="formatLabel(value)" :hue="value" /></template>
          <template #cell-status="{ value }">
            <px-badge :tone="statusTone(value)">{{ formatLabel(value) }}</px-badge>
          </template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state v-else icon="calendar-check" :title="$t('Interviews')" description="Sin entrevistas que coincidan con la búsqueda." />
      </div>

      <px-pagination
        v-if="interviews.length"
        :page="page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        :per-page-options="['10', '25', '50', '100']"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <!-- Crear / editar -->
    <px-modal v-model="modalOpen" :title="editmode ? $t('Edit') : $t('Add')" size="md">
      <validation-observer ref="Create_Interview">
        <form @submit.prevent="Submit_Interview">
          <v-field name="application" :label="$t('Application')" required :rules="{ required: true }" v-slot="{ invalid, id }">
            <vs-px
              :input-id="id"
              :invalid="invalid"
              v-model="interview.application_id"
              :reduce="o => o.value"
              :placeholder="$t('Choose_Application')"
              :options="applications.map(a => ({ label: applicationLabel(a), value: a.id }))"
            />
          </v-field>

          <div class="pxintv__grid pxintv__field">
            <px-field :label="$t('Type') + ' *'">
              <template #default="{ id }">
                <px-select :id="id" v-model="interview.type" :options="typeOptions" />
              </template>
            </px-field>
            <v-field name="scheduled_at" :label="$t('Scheduled_At')" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <px-input :id="id" type="datetime-local" v-model="interview.scheduled_at" :invalid="invalid" />
            </v-field>
          </div>

          <div class="pxintv__grid pxintv__field">
            <px-field :label="$t('Duration_Minutes')">
              <template #default="{ id }">
                <px-input :id="id" type="number" numeric v-model="interview.duration_minutes" />
              </template>
            </px-field>
            <px-field :label="$t('Status')">
              <template #default="{ id }">
                <px-select :id="id" v-model="interview.status" :options="statusOptions" />
              </template>
            </px-field>
          </div>

          <div class="pxintv__grid pxintv__field">
            <px-field :label="$t('Location')">
              <template #default="{ id }">
                <px-input :id="id" v-model="interview.location" />
              </template>
            </px-field>
            <px-field :label="$t('Meeting_Link')">
              <template #default="{ id }">
                <px-input :id="id" v-model="interview.meeting_link" />
              </template>
            </px-field>
          </div>

          <px-field :label="$t('Rating')" class="pxintv__field">
            <template #default="{ id }">
              <px-input :id="id" type="number" numeric v-model="interview.rating" />
            </template>
          </px-field>

          <px-field :label="$t('Feedback')" class="pxintv__field">
            <template #default="{ id }">
              <px-textarea :id="id" v-model="interview.feedback" :rows="2" />
            </template>
          </px-field>

          <px-field :label="$t('Notes')" class="pxintv__field">
            <template #default="{ id }">
              <px-textarea :id="id" v-model="interview.notes" :rows="2" />
            </template>
          </px-field>
        </form>
      </validation-observer>

      <template #footer="{ close }">
        <span class="pxintv__grow" />
        <px-button variant="secondary" :disabled="SubmitProcessing" @click="close">Cancelar</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" @click="Submit_Interview">
          {{ $t('submit') }}
        </px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (una fila) -->
    <px-modal v-model="confirmOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxintv__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxintv__grow" />
        <px-button variant="secondary" :disabled="deleting" @click="close">{{ $t('Delete_cancelButtonText') }}</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deleting" @click="doDelete">{{ $t('Delete_confirmButtonText') }}</px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (selección múltiple) -->
    <px-modal v-model="confirmBulkOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxintv__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxintv__grow" />
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
import PxTag from "@/components/px-next/PxTag.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxSkeleton from "@/components/PxSkeleton.vue";
import VField from "@/views/app/products/next/edit/VField.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

const TYPES = ["phone", "video", "in_person", "technical", "panel", "group"];
const STATUSES = ["scheduled", "completed", "cancelled", "no_show", "rescheduled"];

export default {
  name: "RecruitInterviewsNext",
  metaInfo: { title: "Interviews" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab,
    PxField, PxInput, PxTextarea, PxSelect, PxTag, PxBadge, PxEmptyState,
    PxModal, PxSkeleton, "v-field": VField, "vs-px": VsPx
  },
  data() {
    return {
      isLoading: true,
      refreshing: false,
      SubmitProcessing: false,
      interviews: [],
      applications: [],
      totalRows: "",
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "id", type: "desc" },
      status_filter: "",
      selectedIds: [],
      editmode: false,
      modalOpen: false,
      interview: this.empty_interview(),
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
        { key: "candidate", label: this.$t("Candidate") },
        { key: "job", label: this.$t("Job") },
        { key: "type", label: this.$t("Type"), sortable: true },
        { key: "scheduled_at", label: this.$t("Scheduled_At"), sortable: true },
        { key: "status", label: this.$t("Status"), sortable: true }
      ];
    },
    rowActions() {
      return [
        { key: "edit", label: "Editar", icon: "pencil" },
        { key: "delete", label: "Eliminar", icon: "trash-2", tone: "danger" }
      ];
    },
    typeOptions() { return TYPES.map(t => ({ value: t, label: this.formatLabel(t) })); },
    statusOptions() { return STATUSES.map(s => ({ value: s, label: this.formatLabel(s) })); },
    statusFilterOptions() { return [{ value: "", label: this.$t("All") }, ...this.statusOptions]; }
  },
  methods: {
    empty_interview() {
      return {
        id: "", application_id: "", type: "in_person", scheduled_at: "", duration_minutes: 60,
        location: "", meeting_link: "", status: "scheduled", rating: "", feedback: "", notes: ""
      };
    },
    formatLabel(v) { return v ? v.replace(/_/g, " ") : "-"; },
    statusTone(s) {
      const map = { scheduled: "info", completed: "success", cancelled: "danger", no_show: "neutral", rescheduled: "warning" };
      return map[s] || "neutral";
    },
    candidateName(row) {
      const c = row.application && row.application.candidate;
      return c ? c.first_name + " " + c.last_name : "-";
    },
    applicationLabel(a) {
      const c = a.candidate ? a.candidate.first_name + " " + a.candidate.last_name : "?";
      const j = a.job ? a.job.title : "?";
      return c + " - " + j;
    },

    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.Edit_Interview(row);
      else if (k === "delete") { this.pendingDelete = row; this.confirmOpen = true; }
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.Get_Interviews(1); }, 350);
    },
    onStatusFilter() { this.page = 1; this.Get_Interviews(1); },
    onSort({ key, dir }) {
      this.sort = { field: key, type: dir };
      this.Get_Interviews(this.page);
    },
    onPage(p) { if (p !== this.page) { this.page = p; this.Get_Interviews(p); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.Get_Interviews(1); },

    makeToast(variant, msg, title) {
      notifications.notify(msg, { title: title, variant: variant, solid: true });
    },

    Submit_Interview() {
      this.$refs.Create_Interview.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) this.Create_Interview();
          else this.Update_Interview();
        }
      });
    },

    New_Interview() {
      this.reset_Form();
      this.editmode = false;
      this.Get_FormData();
      this.modalOpen = true;
    },

    Edit_Interview(interview) {
      this.reset_Form();
      this.Get_FormData();
      this.interview = { ...this.empty_interview(), ...interview };
      // Normalize datetime for the datetime-local input (expects "YYYY-MM-DDTHH:mm").
      if (this.interview.scheduled_at) {
        this.interview.scheduled_at = this.interview.scheduled_at.replace(" ", "T").substring(0, 16);
      }
      this.editmode = true;
      this.modalOpen = true;
    },

    Get_FormData() {
      axios.get("recruit/applications_all").then(({ data }) => (this.applications = data));
    },

    Get_Interviews(page) {
      if (page === 1 || !page) this.refreshing = !this.isLoading;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "recruit/interviews?page=" + page +
          "&SortField=" + this.sort.field +
          "&SortType=" + this.sort.type +
          "&search=" + this.search +
          "&status=" + this.status_filter +
          "&limit=" + this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.interviews = response.data.interviews;
          NProgress.done();
          this.isLoading = false;
          this.refreshing = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; this.refreshing = false; }, 500);
        });
    },

    Create_Interview() {
      this.SubmitProcessing = true;
      axios
        .post("recruit/interviews", this.interview)
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Interview");
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Interview() {
      this.SubmitProcessing = true;
      axios
        .put("recruit/interviews/" + this.interview.id, this.interview)
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Interview");
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.interview = this.empty_interview();
    },

    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.deleting = true;
      axios
        .delete("recruit/interviews/" + row.id)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          this.pendingDelete = null;
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Event_Interview");
        })
        .catch(() => {
          this.deleting = false;
          this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
        });
    },

    doDeleteBulk() {
      this.deletingBulk = true;
      axios
        .post("recruit/interviews/delete/by_selection", { selectedIds: this.selectedIds })
        .then(() => {
          this.deletingBulk = false;
          this.confirmBulkOpen = false;
          this.selectedIds = [];
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Event_Interview");
        })
        .catch(() => {
          this.deletingBulk = false;
          this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
        });
    }
  },

  created: function () {
    this.Get_Interviews(1);
    Fire.$on("Event_Interview", () => {
      setTimeout(() => {
        this.Get_Interviews(this.page);
        this.modalOpen = false;
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxintv { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxintv { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxintv__pad { padding: var(--pxn-space-6) 0; }

.pxintv__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxintv__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }

.pxintv__grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--pxn-space-5); }
@media (max-width: 620px) { .pxintv__grid { grid-template-columns: 1fr; } }

.pxintv__field { margin-top: var(--pxn-space-5); }
.pxintv__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxintv__grow { flex: 1; }
</style>
