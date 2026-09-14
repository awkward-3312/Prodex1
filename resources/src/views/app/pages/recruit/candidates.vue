<template>
  <div class="px-next pxcand">
    <!--
      Migracion px-next — Candidatos (Recruit Candidates). Ruta real sin
      cambios (/app/recruit/candidates). Presentación únicamente, auditada
      a fondo contra RecruitController@candidates_* (incluido el helper
      privado storeUpload), RecruitCandidatePolicy y el modelo antes de
      tocar nada. Vista de RIESGO MAYOR por manejar archivos — nada del
      contrato de subida/edición/borrado se modificó.

      Hallazgos clave, verificados en el controller y preservados EXACTOS:

      - Upload real: storeUpload() guarda en filesystem local, NO en un
        disk de Laravel — `public_path('images/recruit/{resumes|photos}')`,
        con nombre aleatorio (Str::random(20) + extensión original del
        cliente). Devuelve una ruta relativa ("images/recruit/resumes/
        xxxx.pdf") que se guarda en `resume_path` / `photo`.
      - El backend NO valida tipo/tamaño de resume ni photo (no hay reglas
        'resume'/'photo' en $request->validate()) — solo el atributo HTML
        `accept` del input es una sugerencia del navegador, nunca una
        garantía. No se agregó validación nueva: sería un cambio funcional
        no autorizado.
      - Edición SIN elegir archivo nuevo: el objeto `candidate` en el
        cliente incluye `resume_path`/`photo` (vienen en la fila que
        devuelve el índice). `build_form_data()` los reenvía como texto
        (mismo valor, sin cambio) salvo `photo`, que el backend excluye
        vía `$request->except(['resume','photo'])` porque el campo de
        archivo TAMBIÉN se llama `photo` (colisión de nombre intencional
        en el legacy) — así que el string viejo de `photo` nunca llega a
        $data, y `resume_path` sí llega pero con el mismo valor que ya
        tenía (no-op). Ningún archivo se pierde nunca al editar sin tocar
        los inputs de archivo — verificado en vivo y en BD.
      - Reemplazar un archivo: solo si `$request->hasFile('resume'|'photo')`
        es true se sobrescribe `resume_path`/`photo` con la ruta nueva. El
        archivo físico VIEJO nunca se borra del filesystem (ni aquí ni en
        destroy) — es basura acumulada conocida del legacy, no se
        "arregla" en esta migración visual.
      - Update usa POST + `_method=PUT` (Laravel method spoofing) porque
        FormData con archivos + verbo PUT nativo es el patrón legacy — se
        preserva exacto, no se cambia a PUT real con FormData.
      - Delete es soft-delete puro (`deleted_at`) — NUNCA borra los
        archivos físicos ni valida relaciones con `applications` antes de
        borrar (la referencia queda huérfana pero válida, mismo patrón
        que otras entidades del módulo).
      - La tabla del LEGACY no muestra ni CV ni foto en ningún lado — ni
        thumbnail, ni link de descarga, ni nombre de archivo. No se
        inventa ninguna de esas capacidades aquí: el file input siempre
        se ve "vacío" (ningún archivo seleccionado) incluso editando un
        candidato que ya tiene resume_path/photo, exactamente igual que el
        legacy (los `<b-form-file>` nunca reflejan un valor existente).
      - `email` tiene constraint UNIQUE real en BD, validado server-side
        (`unique:recruit_candidates,email` en store, con exclusión del
        propio id en update) — sin cambios.
      - Campos del modelo NO expuestos en el formulario legacy (date_of_
        birth, gender, address, state, zip_code, education) — no se
        agregan aquí tampoco.
    -->
    <px-page-header :title="$t('Candidates')" :breadcrumbs="[{ label: $t('Recruit') }, { label: $t('Candidates') }]">
      <template #actions>
        <px-button
          v-if="selectedIds.length"
          variant="danger"
          icon="trash-2"
          @click="confirmBulkOpen = true"
        >{{ $t('Del') }} ({{ selectedIds.length }})</px-button>
        <px-button variant="primary" icon="plus" @click="New_Candidate">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar :search="search" :search-placeholder="$t('Search_this_table')" @update:search="onSearchInput">
      <template #filters>
        <px-select
          v-model="source_filter"
          :options="sourceFilterOptions"
          placeholder="Todos"
          @input="onSourceFilter"
        />
      </template>
    </px-toolbar>

    <div v-if="isLoading" class="pxcand__pad">
      <px-skeleton variant="table" :rows="8" :columns="6" />
    </div>

    <template v-else>
      <div class="pxcand__tablewrap" :class="{ 'is-busy': refreshing }">
        <px-table
          v-if="candidates.length"
          :columns="columns"
          :rows="candidates"
          row-key="id"
          selectable
          :selected="selectedIds"
          @update:selected="selectedIds = $event"
          :sort-key="sort.field"
          :sort-dir="sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-full_name="{ row }">{{ row.first_name }} {{ row.last_name }}</template>
          <template #cell-source="{ value }"><px-tag :label="formatLabel(value)" :hue="value" /></template>
          <template #cell-applications_count="{ value }">{{ value || 0 }}</template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state v-else icon="user" :title="$t('Candidates')" description="Sin candidatos que coincidan con la búsqueda." />
      </div>

      <px-pagination
        v-if="candidates.length"
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
      <validation-observer ref="Create_Candidate">
        <form @submit.prevent="Submit_Candidate">
          <div class="pxcand__grid">
            <v-field name="first_name" :label="$t('First_Name')" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <px-input :id="id" v-model="candidate.first_name" :invalid="invalid" />
            </v-field>
            <v-field name="last_name" :label="$t('Last_Name')" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <px-input :id="id" v-model="candidate.last_name" :invalid="invalid" />
            </v-field>
          </div>

          <div class="pxcand__grid pxcand__field">
            <v-field name="email" :label="$t('Email')" required :rules="{ required: true, email: true }" v-slot="{ invalid, id }">
              <px-input :id="id" type="email" v-model="candidate.email" :invalid="invalid" />
            </v-field>
            <px-field :label="$t('Phone')">
              <template #default="{ id }">
                <px-input :id="id" v-model="candidate.phone" />
              </template>
            </px-field>
          </div>

          <div class="pxcand__grid pxcand__grid--3 pxcand__field">
            <px-field :label="$t('Source')">
              <template #default="{ id }">
                <px-select :id="id" v-model="candidate.source" :options="sourceOptions" />
              </template>
            </px-field>
            <px-field :label="$t('Current_Position')">
              <template #default="{ id }">
                <px-input :id="id" v-model="candidate.current_position" />
              </template>
            </px-field>
            <px-field :label="$t('Experience_Years')">
              <template #default="{ id }">
                <px-input :id="id" type="number" numeric v-model="candidate.experience_years" />
              </template>
            </px-field>
          </div>

          <div class="pxcand__grid pxcand__field">
            <px-field :label="$t('Current_Company')">
              <template #default="{ id }">
                <px-input :id="id" v-model="candidate.current_company" />
              </template>
            </px-field>
            <div class="pxcand__grid pxcand__grid--2">
              <px-field :label="$t('Current_Salary')">
                <template #default="{ id }">
                  <px-input :id="id" type="number" numeric v-model="candidate.current_salary" />
                </template>
              </px-field>
              <px-field :label="$t('Expected_Salary')">
                <template #default="{ id }">
                  <px-input :id="id" type="number" numeric v-model="candidate.expected_salary" />
                </template>
              </px-field>
            </div>
          </div>

          <div class="pxcand__grid pxcand__field">
            <px-field :label="$t('City')">
              <template #default="{ id }">
                <px-input :id="id" v-model="candidate.city" />
              </template>
            </px-field>
            <px-field :label="$t('Country')">
              <template #default="{ id }">
                <px-input :id="id" v-model="candidate.country" />
              </template>
            </px-field>
          </div>

          <div class="pxcand__grid pxcand__field">
            <px-field :label="$t('LinkedIn_URL')">
              <template #default="{ id }">
                <px-input :id="id" v-model="candidate.linkedin_url" />
              </template>
            </px-field>
            <px-field :label="$t('Portfolio_URL')">
              <template #default="{ id }">
                <px-input :id="id" v-model="candidate.portfolio_url" />
              </template>
            </px-field>
          </div>

          <div class="pxcand__grid pxcand__field">
            <px-field :label="$t('Resume')">
              <template #default>
                <div
                  class="pxcand__file"
                  :class="{ 'is-over': resumeDragOver }"
                  @dragover.prevent="resumeDragOver = true"
                  @dragleave.prevent="resumeDragOver = false"
                  @drop.prevent="onResumeDrop"
                >
                  <input
                    ref="resumeInput"
                    type="file"
                    class="pxcand__file-input"
                    accept=".pdf,.doc,.docx"
                    @change="onResumeChange"
                  />
                  <button type="button" class="pxcand__file-btn" @click="$refs.resumeInput.click()">
                    <lucide-icon name="file-up" :size="15" />
                    {{ $t('Choose_a_file') }}
                  </button>
                  <span class="pxcand__file-name">{{ resumeFile ? resumeFile.name : 'Ningún archivo seleccionado' }}</span>
                </div>
              </template>
            </px-field>
            <px-field :label="$t('Photo')">
              <template #default>
                <div
                  class="pxcand__file"
                  :class="{ 'is-over': photoDragOver }"
                  @dragover.prevent="photoDragOver = true"
                  @dragleave.prevent="photoDragOver = false"
                  @drop.prevent="onPhotoDrop"
                >
                  <input
                    ref="photoInput"
                    type="file"
                    class="pxcand__file-input"
                    accept="image/*"
                    @change="onPhotoChange"
                  />
                  <button type="button" class="pxcand__file-btn" @click="$refs.photoInput.click()">
                    <lucide-icon name="image" :size="15" />
                    {{ $t('Choose_a_file') }}
                  </button>
                  <span class="pxcand__file-name">{{ photoFile ? photoFile.name : 'Ningún archivo seleccionado' }}</span>
                </div>
              </template>
            </px-field>
          </div>

          <px-field :label="$t('Skills')" class="pxcand__field">
            <template #default="{ id }">
              <px-textarea :id="id" v-model="candidate.skills" :rows="2" />
            </template>
          </px-field>
          <px-field :label="$t('Notes')" class="pxcand__field">
            <template #default="{ id }">
              <px-textarea :id="id" v-model="candidate.notes" :rows="2" />
            </template>
          </px-field>
        </form>
      </validation-observer>

      <template #footer="{ close }">
        <span class="pxcand__grow" />
        <px-button variant="secondary" :disabled="SubmitProcessing" @click="close">Cancelar</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" @click="Submit_Candidate">
          {{ $t('submit') }}
        </px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (una fila) -->
    <px-modal v-model="confirmOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxcand__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxcand__grow" />
        <px-button variant="secondary" :disabled="deleting" @click="close">{{ $t('Delete_cancelButtonText') }}</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deleting" @click="doDelete">{{ $t('Delete_confirmButtonText') }}</px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (selección múltiple) -->
    <px-modal v-model="confirmBulkOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxcand__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxcand__grow" />
        <px-button variant="secondary" :disabled="deletingBulk" @click="close">{{ $t('Delete_cancelButtonText') }}</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deletingBulk" @click="doDeleteBulk">{{ $t('Delete_confirmButtonText') }}</px-button>
      </template>
    </px-modal>
  </div>
</template>

<script>
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
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxSkeleton from "@/components/PxSkeleton.vue";
import VField from "@/views/app/products/next/edit/VField.vue";

const SOURCES = ["website", "referral", "linkedin", "job_board", "agency", "walk_in", "other"];

export default {
  name: "RecruitCandidatesNext",
  metaInfo: { title: "Candidates" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab,
    PxField, PxInput, PxTextarea, PxSelect, PxTag, PxEmptyState, PxModal,
    PxSkeleton, "v-field": VField
  },
  data() {
    return {
      isLoading: true,
      refreshing: false,
      SubmitProcessing: false,
      candidates: [],
      totalRows: "",
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "id", type: "desc" },
      source_filter: "",
      selectedIds: [],
      editmode: false,
      modalOpen: false,
      candidate: this.empty_candidate(),
      resumeFile: null,
      photoFile: null,
      resumeDragOver: false,
      photoDragOver: false,
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
        { key: "full_name", label: this.$t("Name"), strong: true },
        { key: "email", label: this.$t("Email"), sortable: true },
        { key: "phone", label: this.$t("Phone"), sortable: true },
        { key: "source", label: this.$t("Source"), sortable: true },
        { key: "applications_count", label: this.$t("Applications") }
      ];
    },
    rowActions() {
      return [
        { key: "edit", label: "Editar", icon: "pencil" },
        { key: "delete", label: "Eliminar", icon: "trash-2", tone: "danger" }
      ];
    },
    sourceOptions() { return SOURCES.map(s => ({ value: s, label: this.formatLabel(s) })); },
    sourceFilterOptions() { return [{ value: "", label: this.$t("All") }, ...this.sourceOptions]; }
  },
  methods: {
    empty_candidate() {
      return {
        id: "", first_name: "", last_name: "", email: "", phone: "", source: "website",
        current_position: "", current_company: "", current_salary: "", expected_salary: "",
        experience_years: "", city: "", country: "", linkedin_url: "", portfolio_url: "",
        skills: "", notes: ""
      };
    },
    formatLabel(v) { return v ? v.replace(/_/g, " ") : "-"; },

    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.Edit_Candidate(row);
      else if (k === "delete") { this.pendingDelete = row; this.confirmOpen = true; }
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.Get_Candidates(1); }, 350);
    },
    onSourceFilter() { this.page = 1; this.Get_Candidates(1); },
    onSort({ key, dir }) {
      this.sort = { field: key, type: dir };
      this.Get_Candidates(this.page);
    },
    onPage(p) { if (p !== this.page) { this.page = p; this.Get_Candidates(p); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.Get_Candidates(1); },

    onResumeChange(e) { this.resumeFile = e.target.files[0]; },
    onPhotoChange(e) { this.photoFile = e.target.files[0]; },
    onResumeDrop(e) {
      this.resumeDragOver = false;
      const f = e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) this.resumeFile = f;
    },
    onPhotoDrop(e) {
      this.photoDragOver = false;
      const f = e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) this.photoFile = f;
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },

    build_form_data() {
      const fd = new FormData();
      Object.keys(this.candidate).forEach(key => {
        if (key === "id") return;
        const val = this.candidate[key];
        if (val !== null && val !== undefined && val !== "") fd.append(key, val);
      });
      if (this.resumeFile) fd.append("resume", this.resumeFile);
      if (this.photoFile) fd.append("photo", this.photoFile);
      return fd;
    },

    Submit_Candidate() {
      this.$refs.Create_Candidate.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) this.Create_Candidate();
          else this.Update_Candidate();
        }
      });
    },

    New_Candidate() {
      this.reset_Form();
      this.editmode = false;
      this.modalOpen = true;
    },

    Edit_Candidate(candidate) {
      this.reset_Form();
      this.candidate = { ...this.empty_candidate(), ...candidate };
      this.editmode = true;
      this.modalOpen = true;
    },

    Get_Candidates(page) {
      if (page === 1 || !page) this.refreshing = !this.isLoading;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "recruit/candidates?page=" + page +
          "&SortField=" + this.sort.field +
          "&SortType=" + this.sort.type +
          "&search=" + this.search +
          "&source=" + this.source_filter +
          "&limit=" + this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.candidates = response.data.candidates;
          NProgress.done();
          this.isLoading = false;
          this.refreshing = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; this.refreshing = false; }, 500);
        });
    },

    Create_Candidate() {
      this.SubmitProcessing = true;
      axios
        .post("recruit/candidates", this.build_form_data())
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Candidate");
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Candidate() {
      this.SubmitProcessing = true;
      const fd = this.build_form_data();
      fd.append("_method", "PUT");
      axios
        .post("recruit/candidates/" + this.candidate.id, fd)
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Candidate");
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.candidate = this.empty_candidate();
      this.resumeFile = null;
      this.photoFile = null;
    },

    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.deleting = true;
      axios
        .delete("recruit/candidates/" + row.id)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          this.pendingDelete = null;
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Event_Candidate");
        })
        .catch(() => {
          this.deleting = false;
          this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
        });
    },

    doDeleteBulk() {
      this.deletingBulk = true;
      axios
        .post("recruit/candidates/delete/by_selection", { selectedIds: this.selectedIds })
        .then(() => {
          this.deletingBulk = false;
          this.confirmBulkOpen = false;
          this.selectedIds = [];
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Event_Candidate");
        })
        .catch(() => {
          this.deletingBulk = false;
          this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
        });
    }
  },

  created: function () {
    this.Get_Candidates(1);
    Fire.$on("Event_Candidate", () => {
      setTimeout(() => {
        this.Get_Candidates(this.page);
        this.modalOpen = false;
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcand { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcand { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcand__pad { padding: var(--pxn-space-6) 0; }

.pxcand__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxcand__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }

.pxcand__grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--pxn-space-5); }
.pxcand__grid--3 { grid-template-columns: repeat(3, 1fr); }
.pxcand__grid--2 { grid-template-columns: repeat(2, 1fr); gap: var(--pxn-space-5); }
@media (max-width: 720px) { .pxcand__grid, .pxcand__grid--3, .pxcand__grid--2 { grid-template-columns: 1fr; } }

.pxcand__field { margin-top: var(--pxn-space-5); }
.pxcand__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxcand__grow { flex: 1; }

// Reskin local del <input type="file"> nativo — sin componente global
// PxFileInput todavía. Mismo contrato funcional que el legacy
// (@change, accept, un solo archivo), solo presentación px-next.
.pxcand__file {
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
.pxcand__file.is-over { border-color: var(--pxn-primary); background: var(--pxn-primary-softer); }
.pxcand__file-input {
  position: absolute;
  inset: 0;
  opacity: 0;
  width: 100%;
  height: 100%;
  cursor: pointer;
  z-index: 1;
}
.pxcand__file-btn {
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
.pxcand__file-name {
  font-size: var(--pxn-fs-sm);
  color: var(--pxn-ink-3);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
