<template>
  <div class="px-next pxhol">
    <!--
      Migracion px-next — Dias festivos. Ruta real sin cambios (/app/hrm/holidays).
      Conserva endpoint, payloads, permisos (gate unico en backend sobre
      Holiday::class, sin gating de componente — igual que el legacy), busqueda,
      orden, paginacion, seleccion multiple, borrado individual/masivo
      (soft-delete) y las dos llamadas de datos de apoyo (holiday/create,
      holiday/{id}/edit) que ya hacia el legacy antes de abrir el modal.
      Notificacion de resultado sigue siendo $swal (igual que antes).

      Peculiaridades legacy preservadas TAL CUAL (no corregidas):
      - onSortChange calculaba un `field` remapeado (company_name -> company_id)
        pero nunca lo usaba: el legacy siempre mandaba el nombre de columna
        crudo al backend. Se preserva ese comportamiento exacto.
      - Selected_Company() referencia `this.policy.company_id`, que no existe
        en este componente (copy/paste de otro modulo) — lanza TypeError si el
        usuario limpia el select de empresa. Bug legacy real, no se corrige.
      - El datepicker (vuejs-datepicker) se sustituye por <input type="date">
        nativo dentro de PxInput: PX Next no tiene un componente de calendario
        propio y no queremos otro widget/skin paralelo. El valor final que se
        envia al backend sigue siendo el mismo string "YYYY-MM-DD".
    -->
    <px-page-header :title="$t('Holidays')" :breadcrumbs="[{ label: $t('hrm') }, { label: $t('Holidays') }]">
      <template #actions>
        <px-button
          v-if="selectedIds.length"
          variant="danger"
          icon="trash-2"
          @click="confirmBulkOpen = true"
        >{{ $t('Del') }} ({{ selectedIds.length }})</px-button>
        <px-button variant="primary" icon="plus" @click="New_Holiday">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxhol__pad">
      <px-skeleton variant="table" :rows="8" :columns="4" />
    </div>

    <template v-else>
      <div class="pxhol__tablewrap" :class="{ 'is-busy': refreshing }">
        <px-table
          v-if="holidays.length"
          :columns="columns"
          :rows="holidays"
          row-key="id"
          selectable
          :selected="selectedIds"
          @update:selected="selectedIds = $event"
          :sort-key="sort.field"
          :sort-dir="sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state v-else icon="calendar-clock" :title="$t('Holidays')" description="Sin días festivos que coincidan con la búsqueda." />
      </div>

      <px-pagination
        v-if="holidays.length"
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
      <validation-observer ref="Create_Holiday">
        <form @submit.prevent="Submit_Holiday">
          <v-field name="Company" :label="$t('Company')" required :rules="{ required: true }" v-slot="{ invalid, id }">
            <vs-px
              :input-id="id"
              :invalid="invalid"
              v-model="holiday.company_id"
              @input="Selected_Company"
              :reduce="o => o.value"
              :placeholder="$t('Choose_Company')"
              :options="companies.map(c => ({ label: c.name, value: c.id }))"
            />
          </v-field>

          <v-field name="title" :label="$t('title')" required :rules="{ required: true }" v-slot="{ invalid, id }" class="pxhol__field">
            <px-input :id="id" v-model="holiday.title" :placeholder="$t('Enter_title')" :invalid="invalid" />
          </v-field>

          <v-field name="start_date" :label="$t('start_date')" required :rules="{ required: true }" v-slot="{ invalid, id }" class="pxhol__field">
            <px-input :id="id" type="date" v-model="holiday.start_date" :invalid="invalid" />
          </v-field>

          <v-field name="Finish_Date" :label="$t('Finish_Date')" required :rules="{ required: true }" v-slot="{ invalid, id }" class="pxhol__field">
            <px-input :id="id" type="date" v-model="holiday.end_date" :invalid="invalid" />
          </v-field>

          <px-field :label="$t('Please_provide_any_details')" class="pxhol__field">
            <template #default="{ id }">
              <px-textarea :id="id" v-model="holiday.description" rows="3" :placeholder="$t('Please_provide_any_details')" />
            </template>
          </px-field>
        </form>
      </validation-observer>

      <template #footer="{ close }">
        <span class="pxhol__grow" />
        <px-button variant="secondary" :disabled="SubmitProcessing" @click="close">Cancelar</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" @click="Submit_Holiday">
          {{ $t('submit') }}
        </px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (una fila) -->
    <px-modal v-model="confirmOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxhol__confirm">
        {{ $t('Delete_Text') }}
        <strong v-if="pendingDelete">{{ pendingDelete.title }}</strong>
      </p>
      <template #footer="{ close }">
        <span class="pxhol__grow" />
        <px-button variant="secondary" :disabled="deleting" @click="close">{{ $t('Delete_cancelButtonText') }}</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deleting" @click="doDelete">{{ $t('Delete_confirmButtonText') }}</px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (selección múltiple) -->
    <px-modal v-model="confirmBulkOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxhol__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxhol__grow" />
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
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxSkeleton from "@/components/PxSkeleton.vue";
import VField from "@/views/app/products/next/edit/VField.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  name: "HrmHolidaysNext",
  metaInfo: { title: "Holiday" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab,
    PxField, PxInput, PxTextarea, PxEmptyState, PxModal, PxSkeleton,
    "v-field": VField, "vs-px": VsPx
  },
  data() {
    return {
      isLoading: true,
      refreshing: false,
      SubmitProcessing: false,
      companies: [],
      holidays: [],
      totalRows: "",
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "id", type: "desc" },
      selectedIds: [],
      editmode: false,
      modalOpen: false,
      holiday: { id: "", title: "", company_id: "", start_date: "", end_date: "", description: "" },
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
        { key: "title", label: this.$t("Holiday"), sortable: true, strong: true },
        { key: "company_name", label: this.$t("Company"), sortable: true },
        { key: "start_date", label: this.$t("start_date"), sortable: true },
        { key: "end_date", label: this.$t("Finish_Date"), sortable: true }
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
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.Edit_Holiday(row);
      else if (k === "delete") { this.pendingDelete = row; this.confirmOpen = true; }
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.Get_Holidays(1); }, 350);
    },
    onSort({ key, dir }) {
      // Legacy siempre mandaba la columna cruda (ver nota arriba) — se
      // preserva exacto, sin remapear company_name -> company_id.
      this.sort = { field: key, type: dir };
      this.Get_Holidays(this.page);
    },
    onPage(p) { if (p !== this.page) { this.page = p; this.Get_Holidays(p); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.Get_Holidays(1); },

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },
    makeToast(variant, msg, title) {
      notifications.notify(msg, { title: title, variant: variant, solid: true });
    },

    Submit_Holiday() {
      this.$refs.Create_Holiday.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) this.Create_Holiday();
          else this.Update_Holiday();
        }
      });
    },

    New_Holiday() {
      this.reset_Form();
      this.editmode = false;
      this.Get_Data_Create();
      this.modalOpen = true;
    },

    Edit_Holiday(holiday) {
      this.editmode = true;
      this.reset_Form();
      this.Get_Data_Edit(holiday.id);
      this.holiday = holiday;
      this.modalOpen = true;
    },

    Get_Data_Create() {
      axios
        .get("/holiday/create")
        .then(response => {
          this.companies = response.data.companies;
        })
        .catch(() => {});
    },

    Get_Data_Edit(id) {
      axios
        .get(`holiday/${id}/edit`)
        .then(response => {
          this.companies = response.data.companies;
        })
        .catch(() => {});
    },

    Selected_Company(value) {
      if (value === null) {
        // Bug legacy preservado: `this.policy` no existe en este componente.
        this.policy.company_id = "";
      }
    },

    Get_Holidays(page) {
      if (page === 1 || !page) this.refreshing = !this.isLoading;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "holiday?page=" + page +
          "&SortField=" + this.sort.field +
          "&SortType=" + this.sort.type +
          "&search=" + this.search +
          "&limit=" + this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.holidays = response.data.holidays;
          NProgress.done();
          this.isLoading = false;
          this.refreshing = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; this.refreshing = false; }, 500);
        });
    },

    Create_Holiday() {
      this.SubmitProcessing = true;
      axios.post("/holiday", {
        company_id: this.holiday.company_id,
        title: this.holiday.title,
        start_date: this.holiday.start_date,
        end_date: this.holiday.end_date,
        description: this.holiday.description
      }).then(() => {
        this.SubmitProcessing = false;
        Fire.$emit("Event_Holiday");
        this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
      }).catch(() => {
        this.SubmitProcessing = false;
        this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
      });
    },

    Update_Holiday() {
      this.SubmitProcessing = true;
      axios.put("/holiday/" + this.holiday.id, {
        title: this.holiday.title,
        company_id: this.holiday.company_id,
        start_date: this.holiday.start_date,
        end_date: this.holiday.end_date,
        description: this.holiday.description
      }).then(() => {
        this.SubmitProcessing = false;
        Fire.$emit("Event_Holiday");
        this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
      }).catch(() => {
        this.SubmitProcessing = false;
        this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
      });
    },

    reset_Form() {
      this.holiday = { id: "", title: "", company_id: "", start_date: "", end_date: "", description: "" };
    },

    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.deleting = true;
      axios
        .delete("holiday/" + row.id)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          this.pendingDelete = null;
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Delete_Holiday");
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
        .post("holiday/delete/by_selection", { selectedIds: this.selectedIds })
        .then(() => {
          this.deletingBulk = false;
          this.confirmBulkOpen = false;
          this.selectedIds = [];
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Delete_Holiday");
        })
        .catch(() => {
          this.deletingBulk = false;
          setTimeout(() => NProgress.done(), 500);
          this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
        });
    }
  },

  created: function () {
    this.Get_Holidays(1);

    Fire.$on("Event_Holiday", () => {
      setTimeout(() => {
        this.Get_Holidays(this.page);
        this.modalOpen = false;
      }, 500);
    });

    Fire.$on("Delete_Holiday", () => {
      setTimeout(() => {
        this.Get_Holidays(this.page);
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxhol { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxhol { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxhol__pad { padding: var(--pxn-space-6) 0; }

.pxhol__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxhol__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }

.pxhol__field { margin-top: var(--pxn-space-5); }
.pxhol__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxhol__grow { flex: 1; }
</style>
