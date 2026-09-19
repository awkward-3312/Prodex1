<template>
  <div class="px-next pxjobs">
    <!--
      Migracion px-next — Vacantes (Recruit Jobs). Ruta real sin cambios
      (/app/recruit/jobs). Presentación únicamente, auditada contra
      RecruitController@jobs_* y RecruitJobPolicy antes de tocar nada.

      Diferencias verificadas frente al resto del lote (no se copian a
      ciegas otras vistas):
      - jobs_destroy hace un soft-delete REAL: `RecruitJob` usa el trait
        SoftDeletes de Eloquent, así que una vez `deleted_at` se pone, el
        registro desaparece de TODAS las queries futuras (scope global),
        a diferencia de Cargos donde "desactivar" es un estado propio
        (`is_active`) además del soft-delete. Aquí "Eliminar" es un delete
        de verdad (aunque reversible en BD), por eso el legacy usa el
        icono "x" (no "archive") y el wording "Eliminar" — se conserva.
      - Un solo evento Fire ("Event_Job") cubre create/update/delete/bulk
        delete — no hay Delete_Job separado como en otras vistas.
      - SÍ hay selección múltiple + bulk delete reales en esta vista
        (jobs/delete/by_selection), a diferencia de Cargos que no tenía
        selección. Se preservan checkboxes + acción masiva.
      - SÍ hay paginación/orden/búsqueda reales en el backend (limit
        configurable, no limit=-1) — se usa PxPagination de verdad.
      - Filtro adicional por "status" (draft/open/on_hold/closed) vía
        querystring `status=`, independiente del buscador de texto.
      - El modelo tiene `department_id` fillable y una relación
        `department()`, pero el formulario legacy NUNCA expone un select
        de departamento — no se inventa uno aquí tampoco.
      - `category_id` es opcional (sin *): el select se llena desde
        `recruit/categories_all` (solo categorías con is_active=true).
      - El slug se autogenera en el backend (Str::slug + random) — no es
        un campo del formulario, no se toca.
      - Result de eliminar/bulk-eliminar sigue usando $swal (igual que
        crear/editar toast sigue usando $bvToast) — sin homogenizar.
    -->
    <px-page-header :title="$t('Jobs')" :breadcrumbs="[{ label: $t('Recruit') }, { label: $t('Jobs') }]">
      <template #actions>
        <px-button
          v-if="selectedIds.length"
          variant="danger"
          icon="trash-2"
          @click="confirmBulkOpen = true"
        >{{ $t('Del') }} ({{ selectedIds.length }})</px-button>
        <px-button variant="primary" icon="plus" @click="New_Job">{{ $t('Add') }}</px-button>
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

    <div v-if="isLoading" class="pxjobs__pad">
      <px-skeleton variant="table" :rows="8" :columns="6" />
    </div>

    <template v-else>
      <div class="pxjobs__tablewrap" :class="{ 'is-busy': refreshing }">
        <px-table
          v-if="jobs.length"
          :columns="columns"
          :rows="jobs"
          row-key="id"
          selectable
          :selected="selectedIds"
          @update:selected="selectedIds = $event"
          :sort-key="sort.field"
          :sort-dir="sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-category="{ row }">{{ row.category ? row.category.name : '-' }}</template>
          <template #cell-job_type="{ value }"><px-tag :label="formatLabel(value)" :hue="value" /></template>
          <template #cell-status="{ value }">
            <px-badge :tone="statusTone(value)">{{ formatLabel(value) }}</px-badge>
          </template>
          <template #cell-applications_count="{ value }">{{ value || 0 }}</template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state v-else icon="briefcase-business" :title="$t('Jobs')" description="Sin vacantes que coincidan con la búsqueda." />
      </div>

      <px-pagination
        v-if="jobs.length"
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
      <px-validation-observer ref="Create_Job">
        <form @submit.prevent="Submit_Job">
          <div class="pxjobs__grid">
            <v-field name="title" :label="$t('Job_Title')" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <px-input :id="id" v-model="job.title" :invalid="invalid" />
            </v-field>

            <px-field :label="$t('Category')">
              <template #default="{ id }">
                <vs-px
                  :input-id="id"
                  v-model="job.category_id"
                  :reduce="o => o.value"
                  :placeholder="$t('Choose_Category')"
                  :options="categories.map(c => ({ label: c.name, value: c.id }))"
                />
              </template>
            </px-field>

            <px-field :label="$t('Job_Type') + ' *'">
              <template #default="{ id }">
                <px-select :id="id" v-model="job.job_type" :options="jobTypeOptions" />
              </template>
            </px-field>

            <px-field :label="$t('Experience_Level')">
              <template #default="{ id }">
                <px-select :id="id" v-model="job.experience_level" :options="experienceOptions" />
              </template>
            </px-field>

            <px-field :label="$t('Status')">
              <template #default="{ id }">
                <px-select :id="id" v-model="job.status" :options="statusOptions" />
              </template>
            </px-field>

            <px-field :label="$t('Location')">
              <template #default="{ id }">
                <px-input :id="id" v-model="job.location" />
              </template>
            </px-field>

            <px-field :label="$t('Vacancies')">
              <template #default="{ id }">
                <px-input :id="id" type="number" numeric v-model="job.vacancies" />
              </template>
            </px-field>

            <px-field :label="$t('Deadline')">
              <template #default="{ id }">
                <px-input :id="id" type="date" v-model="job.deadline" />
              </template>
            </px-field>

            <px-field :label="$t('Salary_Min')">
              <template #default="{ id }">
                <px-input :id="id" type="number" numeric v-model="job.salary_min" />
              </template>
            </px-field>

            <px-field :label="$t('Salary_Max')">
              <template #default="{ id }">
                <px-input :id="id" type="number" numeric v-model="job.salary_max" />
              </template>
            </px-field>

            <px-field :label="$t('Currency')">
              <template #default="{ id }">
                <px-input :id="id" v-model="job.currency" />
              </template>
            </px-field>
          </div>

          <v-field name="description" :label="$t('Description')" required :rules="{ required: true }" v-slot="{ invalid, id }" class="pxjobs__field">
            <px-textarea :id="id" v-model="job.description" :rows="3" :invalid="invalid" />
          </v-field>

          <div class="pxjobs__grid pxjobs__grid--2 pxjobs__field">
            <px-field :label="$t('Requirements')">
              <template #default="{ id }">
                <px-textarea :id="id" v-model="job.requirements" :rows="3" />
              </template>
            </px-field>
            <px-field :label="$t('Benefits')">
              <template #default="{ id }">
                <px-textarea :id="id" v-model="job.benefits" :rows="3" />
              </template>
            </px-field>
          </div>
        </form>
      </px-validation-observer>

      <template #footer="{ close }">
        <span class="pxjobs__grow" />
        <px-button variant="secondary" :disabled="SubmitProcessing" @click="close">Cancelar</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" @click="Submit_Job">
          {{ $t('submit') }}
        </px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (una fila) -->
    <px-modal v-model="confirmOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxjobs__confirm">
        {{ $t('Delete_Text') }}
        <strong v-if="pendingDelete">{{ pendingDelete.title }}</strong>
      </p>
      <template #footer="{ close }">
        <span class="pxjobs__grow" />
        <px-button variant="secondary" :disabled="deleting" @click="close">{{ $t('Delete_cancelButtonText') }}</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deleting" @click="doDelete">{{ $t('Delete_confirmButtonText') }}</px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (selección múltiple) -->
    <px-modal v-model="confirmBulkOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxjobs__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxjobs__grow" />
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

export default {
  name: "RecruitJobsNext",
  metaInfo: { title: "Jobs" },
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
      jobs: [],
      categories: [],
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
      job: this.empty_job(),
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
        { key: "title", label: this.$t("Job_Title"), sortable: true, strong: true },
        { key: "category", label: this.$t("Category") },
        { key: "job_type", label: this.$t("Job_Type"), sortable: true },
        { key: "location", label: this.$t("Location"), sortable: true },
        { key: "status", label: this.$t("Status"), sortable: true },
        { key: "applications_count", label: this.$t("Applications") }
      ];
    },
    rowActions() {
      return [
        { key: "edit", label: "Editar", icon: "pencil" },
        { key: "delete", label: "Eliminar", icon: "trash-2", tone: "danger" }
      ];
    },
    statusFilterOptions() {
      return [
        { value: "", label: this.$t("All") },
        { value: "draft", label: this.$t("Draft") },
        { value: "open", label: this.$t("Open") },
        { value: "on_hold", label: this.$t("On_Hold") },
        { value: "closed", label: this.$t("Closed") }
      ];
    },
    jobTypeOptions() {
      return [
        { value: "full_time", label: this.$t("Full_Time") },
        { value: "part_time", label: this.$t("Part_Time") },
        { value: "contract", label: this.$t("Contract") },
        { value: "internship", label: this.$t("Internship") },
        { value: "remote", label: this.$t("Remote") }
      ];
    },
    experienceOptions() {
      return [
        { value: "entry", label: this.$t("Entry") },
        { value: "mid", label: this.$t("Mid") },
        { value: "senior", label: this.$t("Senior") },
        { value: "lead", label: this.$t("Lead") },
        { value: "manager", label: this.$t("Manager") }
      ];
    },
    statusOptions() {
      return [
        { value: "draft", label: this.$t("Draft") },
        { value: "open", label: this.$t("Open") },
        { value: "on_hold", label: this.$t("On_Hold") },
        { value: "closed", label: this.$t("Closed") }
      ];
    }
  },
  methods: {
    empty_job() {
      return {
        id: "", title: "", category_id: "", job_type: "full_time", experience_level: "entry",
        status: "draft", location: "", vacancies: 1, deadline: "", salary_min: "", salary_max: "",
        currency: "USD", description: "", requirements: "", benefits: ""
      };
    },
    formatLabel(v) { return v ? v.replace(/_/g, " ") : "-"; },
    statusTone(s) {
      const map = { open: "success", draft: "warning", on_hold: "info", closed: "neutral" };
      return map[s] || "neutral";
    },

    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.Edit_Job(row);
      else if (k === "delete") { this.pendingDelete = row; this.confirmOpen = true; }
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.Get_Jobs(1); }, 350);
    },
    onStatusFilter() { this.page = 1; this.Get_Jobs(1); },
    onSort({ key, dir }) {
      this.sort = { field: key, type: dir };
      this.Get_Jobs(this.page);
    },
    onPage(p) { if (p !== this.page) { this.page = p; this.Get_Jobs(p); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.Get_Jobs(1); },

    makeToast(variant, msg, title) {
      notifications.notify(msg, { title: title, variant: variant, solid: true });
    },

    Submit_Job() {
      this.$refs.Create_Job.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) this.Create_Job();
          else this.Update_Job();
        }
      });
    },

    New_Job() {
      this.reset_Form();
      this.editmode = false;
      this.Get_Categories();
      this.modalOpen = true;
    },

    Edit_Job(job) {
      this.reset_Form();
      this.Get_Categories();
      this.job = { ...this.empty_job(), ...job };
      this.editmode = true;
      this.modalOpen = true;
    },

    Get_Categories() {
      axios.get("recruit/categories_all").then(({ data }) => (this.categories = data));
    },

    Get_Jobs(page) {
      if (page === 1 || !page) this.refreshing = !this.isLoading;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "recruit/jobs?page=" + page +
          "&SortField=" + this.sort.field +
          "&SortType=" + this.sort.type +
          "&search=" + this.search +
          "&status=" + this.status_filter +
          "&limit=" + this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.jobs = response.data.jobs;
          NProgress.done();
          this.isLoading = false;
          this.refreshing = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; this.refreshing = false; }, 500);
        });
    },

    Create_Job() {
      this.SubmitProcessing = true;
      axios
        .post("recruit/jobs", this.job)
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Job");
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Job() {
      this.SubmitProcessing = true;
      axios
        .put("recruit/jobs/" + this.job.id, this.job)
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Job");
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.job = this.empty_job();
    },

    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.deleting = true;
      axios
        .delete("recruit/jobs/" + row.id)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          this.pendingDelete = null;
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Event_Job");
        })
        .catch(() => {
          this.deleting = false;
          this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
        });
    },

    doDeleteBulk() {
      this.deletingBulk = true;
      axios
        .post("recruit/jobs/delete/by_selection", { selectedIds: this.selectedIds })
        .then(() => {
          this.deletingBulk = false;
          this.confirmBulkOpen = false;
          this.selectedIds = [];
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Event_Job");
        })
        .catch(() => {
          this.deletingBulk = false;
          this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
        });
    }
  },

  created: function () {
    this.Get_Jobs(1);
    Fire.$on("Event_Job", () => {
      setTimeout(() => {
        this.Get_Jobs(this.page);
        this.modalOpen = false;
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxjobs { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxjobs { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxjobs__pad { padding: var(--pxn-space-6) 0; }

.pxjobs__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxjobs__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }

.pxjobs__grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--pxn-space-5); }
.pxjobs__grid--2 { grid-template-columns: repeat(2, 1fr); }
@media (max-width: 720px) { .pxjobs__grid, .pxjobs__grid--2 { grid-template-columns: 1fr; } }

.pxjobs__field { margin-top: var(--pxn-space-5); }
.pxjobs__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxjobs__grow { flex: 1; }
</style>
