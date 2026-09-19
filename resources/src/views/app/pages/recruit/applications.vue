<template>
  <div class="px-next pxapps">
    <!--
      Migracion px-next — Postulaciones (Recruit Applications). Ruta real
      sin cambios (/app/recruit/applications). Presentación únicamente,
      auditada contra RecruitController@applications_* y
      RecruitApplicationPolicy antes de tocar nada.

      Hallazgo clave de la auditoría, confirmado en el controller y
      preservado EXACTO: la columna "Etapa" en la tabla YA es un select
      interactivo en el legacy (no un badge de solo lectura). Al cambiar
      su valor dispara `PUT applications/{id}/stage` — un endpoint
      DEDICADO y distinto del update genérico, que además marca
      `reviewed_by`/`reviewed_at` en el backend (efecto secundario que
      solo ocurre por esa ruta, nunca por el modal de editar). Se
      preserva tal cual: no se inventa Kanban, drag-and-drop ni un flujo
      nuevo — sigue siendo un select por fila con el mismo comportamiento.
      El modal de crear/editar tiene su PROPIO select de "Etapa" que va
      por el PUT genérico (sin tocar reviewed_by/reviewed_at) — dos
      caminos distintos para cambiar `stage`, ambos preservados.

      Otros hallazgos:
      - RecruitApplication usa SoftDeletes real (igual que Vacantes) —
        "Eliminar" es un soft-delete genuino, nunca hard-delete.
      - candidate_id/job_id son selects poblados desde `candidates_all`/
        `jobs_all` (listas completas, sin paginar) — igual que legacy.
      - applied_date se autocompleta en el backend con `now()` si llega
        vacío (`applications_store`) — no se replica esa lógica en el
        cliente, se deja intacta en el servidor.
      - Selección múltiple + bulk delete reales (igual que Vacantes).
      - Un solo evento Fire ("Event_Application") cubre create/update/
        delete/bulk delete — igual que Vacantes, distinto de otras vistas
        del lote de RR.HH.
    -->
    <px-page-header :title="$t('Applications')" :breadcrumbs="[{ label: $t('Recruit') }, { label: $t('Applications') }]">
      <template #actions>
        <px-button
          v-if="selectedIds.length"
          variant="danger"
          icon="trash-2"
          @click="confirmBulkOpen = true"
        >{{ $t('Del') }} ({{ selectedIds.length }})</px-button>
        <px-button variant="primary" icon="plus" @click="New_Application">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar :search="search" :search-placeholder="$t('Search_this_table')" @update:search="onSearchInput">
      <template #filters>
        <px-select
          v-model="stage_filter"
          :options="stageFilterOptions"
          placeholder="Todos"
          @input="onStageFilter"
        />
      </template>
    </px-toolbar>

    <div v-if="isLoading" class="pxapps__pad">
      <px-skeleton variant="table" :rows="8" :columns="6" />
    </div>

    <template v-else>
      <div class="pxapps__tablewrap" :class="{ 'is-busy': refreshing }">
        <px-table
          v-if="applications.length"
          :columns="columns"
          :rows="applications"
          row-key="id"
          selectable
          :selected="selectedIds"
          @update:selected="selectedIds = $event"
          :sort-key="sort.field"
          :sort-dir="sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-candidate="{ row }">{{ row.candidate ? row.candidate.first_name + ' ' + row.candidate.last_name : '-' }}</template>
          <template #cell-job="{ row }">{{ row.job ? row.job.title : '-' }}</template>
          <template #cell-stage="{ row }">
            <px-select
              class="pxapps__stagesel"
              :value="row.stage"
              :options="stageOptions"
              @input="changeStage(row.id, $event)"
            />
          </template>
          <template #cell-rating="{ value }">{{ value || '-' }}</template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state v-else icon="clipboard-list" :title="$t('Applications')" description="Sin postulaciones que coincidan con la búsqueda." />
      </div>

      <px-pagination
        v-if="applications.length"
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
      <validation-observer ref="Create_Application">
        <form @submit.prevent="Submit_Application">
          <v-field name="candidate" :label="$t('Candidate')" required :rules="{ required: true }" v-slot="{ invalid, id }">
            <vs-px
              :input-id="id"
              :invalid="invalid"
              v-model="application.candidate_id"
              :reduce="o => o.value"
              :placeholder="$t('Choose_Candidate')"
              :options="candidates.map(c => ({ label: c.first_name + ' ' + c.last_name + ' (' + c.email + ')', value: c.id }))"
            />
          </v-field>

          <v-field name="job" :label="$t('Job')" required :rules="{ required: true }" v-slot="{ invalid, id }" class="pxapps__field">
            <vs-px
              :input-id="id"
              :invalid="invalid"
              v-model="application.job_id"
              :reduce="o => o.value"
              :placeholder="$t('Choose_Job')"
              :options="jobs.map(j => ({ label: j.title, value: j.id }))"
            />
          </v-field>

          <div class="pxapps__grid pxapps__field">
            <px-field :label="$t('Stage')">
              <template #default="{ id }">
                <px-select :id="id" v-model="application.stage" :options="stageOptions" />
              </template>
            </px-field>
            <px-field :label="$t('Applied_Date')">
              <template #default="{ id }">
                <px-input :id="id" type="date" v-model="application.applied_date" />
              </template>
            </px-field>
          </div>

          <px-field :label="$t('Rating')" class="pxapps__field">
            <template #default="{ id }">
              <px-input :id="id" type="number" numeric v-model="application.rating" />
            </template>
          </px-field>

          <px-field :label="$t('Cover_Letter')" class="pxapps__field">
            <template #default="{ id }">
              <px-textarea :id="id" v-model="application.cover_letter" :rows="2" />
            </template>
          </px-field>

          <px-field :label="$t('Notes')" class="pxapps__field">
            <template #default="{ id }">
              <px-textarea :id="id" v-model="application.notes" :rows="2" />
            </template>
          </px-field>
        </form>
      </validation-observer>

      <template #footer="{ close }">
        <span class="pxapps__grow" />
        <px-button variant="secondary" :disabled="SubmitProcessing" @click="close">Cancelar</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" @click="Submit_Application">
          {{ $t('submit') }}
        </px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (una fila) -->
    <px-modal v-model="confirmOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxapps__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxapps__grow" />
        <px-button variant="secondary" :disabled="deleting" @click="close">{{ $t('Delete_cancelButtonText') }}</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deleting" @click="doDelete">{{ $t('Delete_confirmButtonText') }}</px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (selección múltiple) -->
    <px-modal v-model="confirmBulkOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxapps__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxapps__grow" />
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
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxSkeleton from "@/components/PxSkeleton.vue";
import VField from "@/views/app/products/next/edit/VField.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

const STAGES = ["applied", "screening", "shortlisted", "interview", "offered", "hired", "rejected"];

export default {
  name: "RecruitApplicationsNext",
  metaInfo: { title: "Applications" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab,
    PxField, PxInput, PxTextarea, PxSelect, PxEmptyState, PxModal, PxSkeleton,
    "v-field": VField, "vs-px": VsPx
  },
  data() {
    return {
      isLoading: true,
      refreshing: false,
      SubmitProcessing: false,
      applications: [],
      jobs: [],
      candidates: [],
      totalRows: "",
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "id", type: "desc" },
      stage_filter: "",
      selectedIds: [],
      editmode: false,
      modalOpen: false,
      application: this.empty_application(),
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
        { key: "stage", label: this.$t("Stage"), width: "170px" },
        { key: "applied_date", label: this.$t("Applied_Date"), sortable: true },
        { key: "rating", label: this.$t("Rating"), sortable: true }
      ];
    },
    rowActions() {
      return [
        { key: "edit", label: "Editar", icon: "pencil" },
        { key: "delete", label: "Eliminar", icon: "trash-2", tone: "danger" }
      ];
    },
    stageOptions() {
      return STAGES.map(s => ({ value: s, label: this.formatLabel(s) }));
    },
    stageFilterOptions() {
      return [{ value: "", label: this.$t("All") }, ...this.stageOptions];
    }
  },
  methods: {
    empty_application() {
      return { id: "", candidate_id: "", job_id: "", stage: "applied", applied_date: "", rating: "", cover_letter: "", notes: "" };
    },
    formatLabel(v) { return v ? v.replace(/_/g, " ") : "-"; },

    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.Edit_Application(row);
      else if (k === "delete") { this.pendingDelete = row; this.confirmOpen = true; }
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.Get_Applications(1); }, 350);
    },
    onStageFilter() { this.page = 1; this.Get_Applications(1); },
    onSort({ key, dir }) {
      this.sort = { field: key, type: dir };
      this.Get_Applications(this.page);
    },
    onPage(p) { if (p !== this.page) { this.page = p; this.Get_Applications(p); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.Get_Applications(1); },

    makeToast(variant, msg, title) {
      notifications.notify(msg, { title: title, variant: variant, solid: true });
    },

    Submit_Application() {
      this.$refs.Create_Application.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) this.Create_Application();
          else this.Update_Application();
        }
      });
    },

    New_Application() {
      this.reset_Form();
      this.editmode = false;
      this.Get_FormData();
      this.modalOpen = true;
    },

    Edit_Application(application) {
      this.reset_Form();
      this.Get_FormData();
      this.application = { ...this.empty_application(), ...application };
      this.editmode = true;
      this.modalOpen = true;
    },

    Get_FormData() {
      axios.get("recruit/jobs_all").then(({ data }) => (this.jobs = data));
      axios.get("recruit/candidates_all").then(({ data }) => (this.candidates = data));
    },

    Get_Applications(page) {
      if (page === 1 || !page) this.refreshing = !this.isLoading;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "recruit/applications?page=" + page +
          "&SortField=" + this.sort.field +
          "&SortType=" + this.sort.type +
          "&search=" + this.search +
          "&stage=" + this.stage_filter +
          "&limit=" + this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.applications = response.data.applications;
          NProgress.done();
          this.isLoading = false;
          this.refreshing = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; this.refreshing = false; }, 500);
        });
    },

    changeStage(id, stage) {
      axios
        .put("recruit/applications/" + id + "/stage", { stage: stage })
        .then(() => {
          Fire.$emit("Event_Application");
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Create_Application() {
      this.SubmitProcessing = true;
      axios
        .post("recruit/applications", this.application)
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Application");
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Application() {
      this.SubmitProcessing = true;
      axios
        .put("recruit/applications/" + this.application.id, this.application)
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Application");
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.application = this.empty_application();
    },

    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.deleting = true;
      axios
        .delete("recruit/applications/" + row.id)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          this.pendingDelete = null;
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Event_Application");
        })
        .catch(() => {
          this.deleting = false;
          this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
        });
    },

    doDeleteBulk() {
      this.deletingBulk = true;
      axios
        .post("recruit/applications/delete/by_selection", { selectedIds: this.selectedIds })
        .then(() => {
          this.deletingBulk = false;
          this.confirmBulkOpen = false;
          this.selectedIds = [];
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Event_Application");
        })
        .catch(() => {
          this.deletingBulk = false;
          this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
        });
    }
  },

  created: function () {
    this.Get_Applications(1);
    Fire.$on("Event_Application", () => {
      setTimeout(() => {
        this.Get_Applications(this.page);
        this.modalOpen = false;
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxapps { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxapps { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxapps__pad { padding: var(--pxn-space-6) 0; }

.pxapps__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxapps__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }
.pxapps__stagesel { min-width: 140px; }

.pxapps__grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--pxn-space-5); }
@media (max-width: 620px) { .pxapps__grid { grid-template-columns: 1fr; } }

.pxapps__field { margin-top: var(--pxn-space-5); }
.pxapps__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxapps__grow { flex: 1; }
</style>
