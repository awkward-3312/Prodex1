<template>
  <div class="px-next pxlt">
    <!--
      Migracion px-next — Tipo de permiso. Ruta real sin cambios
      (/app/hrm/leaves/type). Conserva endpoint, payloads, permisos (gate
      unico en backend sobre Leave::class, igual que el archivo legacy —
      sin gating de componente), busqueda, orden, paginacion, seleccion
      multiple y borrado individual/masivo (soft-delete). Notificacion de
      resultado sigue siendo $swal (igual que antes) — solo la confirmacion
      de borrado se migro a PxModal, por regla ya aprobada en el canario.
    -->
    <px-page-header :title="$t('Leave_Type')" :breadcrumbs="[{ label: $t('hrm') }, { label: $t('Leave_Type') }]">
      <template #actions>
        <px-button
          v-if="selectedIds.length"
          variant="danger"
          icon="trash-2"
          @click="confirmBulkOpen = true"
        >{{ $t('Del') }} ({{ selectedIds.length }})</px-button>
        <px-button variant="primary" icon="plus" @click="New_Type">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxlt__pad">
      <px-skeleton variant="table" :rows="8" :columns="2" />
    </div>

    <template v-else>
      <div class="pxlt__tablewrap" :class="{ 'is-busy': refreshing }">
        <px-table
          v-if="leave_types.length"
          :columns="columns"
          :rows="leave_types"
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

        <px-empty-state v-else icon="calendar-clock" :title="$t('Leave_Type')" description="Sin tipos de permiso que coincidan con la búsqueda." />
      </div>

      <px-pagination
        v-if="leave_types.length"
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
      <validation-observer ref="Create_Type">
        <form @submit.prevent="Submit_Type">
          <v-field
            name="title"
            :label="$t('title')"
            required
            :rules="{ required: true }"
            v-slot="{ invalid, id }"
          >
            <px-input :id="id" v-model="leave_type.title" :placeholder="$t('Enter_title')" :invalid="invalid" />
          </v-field>
        </form>
      </validation-observer>

      <template #footer="{ close }">
        <span class="pxlt__grow" />
        <px-button variant="secondary" :disabled="SubmitProcessing" @click="close">Cancelar</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" @click="Submit_Type">
          {{ $t('submit') }}
        </px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (una fila) -->
    <px-modal v-model="confirmOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxlt__confirm">
        {{ $t('Delete_Text') }}
        <strong v-if="pendingDelete">{{ pendingDelete.title }}</strong>
      </p>
      <template #footer="{ close }">
        <span class="pxlt__grow" />
        <px-button variant="secondary" :disabled="deleting" @click="close">{{ $t('Delete_cancelButtonText') }}</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deleting" @click="doDelete">{{ $t('Delete_confirmButtonText') }}</px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (selección múltiple) -->
    <px-modal v-model="confirmBulkOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxlt__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxlt__grow" />
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
import PxInput from "@/components/px-next/PxInput.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxSkeleton from "@/components/PxSkeleton.vue";
import VField from "@/views/app/products/next/edit/VField.vue";

export default {
  name: "HrmLeaveTypeNext",
  metaInfo: { title: "Leave Type" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab,
    PxInput, PxEmptyState, PxModal, PxSkeleton, "v-field": VField
  },
  data() {
    return {
      isLoading: true,
      refreshing: false,
      SubmitProcessing: false,
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
      leave_type: { id: "", title: "" },
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
        { key: "title", label: this.$t("Leave_Type"), sortable: true, strong: true }
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
      if (k === "edit") this.Edit_Type(row);
      else if (k === "delete") { this.pendingDelete = row; this.confirmOpen = true; }
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.Get_leaves_type(1); }, 350);
    },
    onSort({ key, dir }) {
      this.sort = { field: key, type: dir };
      this.Get_leaves_type(this.page);
    },
    onPage(p) { if (p !== this.page) { this.page = p; this.Get_leaves_type(p); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.Get_leaves_type(1); },

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },
    makeToast(variant, msg, title) {
      notifications.notify(msg, { title: title, variant: variant, solid: true });
    },

    Submit_Type() {
      this.$refs.Create_Type.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) this.Create_Type();
          else this.Update_Type();
        }
      });
    },

    New_Type() {
      this.reset_Form();
      this.editmode = false;
      this.modalOpen = true;
    },

    Edit_Type(leave_type) {
      // Conserva el refetch previo del listado que ya hacia el legacy antes
      // de abrir el modal de edicion (Get_leaves_type antes de asignar).
      this.Get_leaves_type(this.page);
      this.reset_Form();
      this.leave_type = leave_type;
      this.editmode = true;
      this.modalOpen = true;
    },

    Get_leaves_type(page) {
      if (page === 1 || !page) this.refreshing = !this.isLoading;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "leave_type?page=" + page +
          "&SortField=" + this.sort.field +
          "&SortType=" + this.sort.type +
          "&search=" + this.search +
          "&limit=" + this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.leave_types = response.data.leave_types;
          NProgress.done();
          this.isLoading = false;
          this.refreshing = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; this.refreshing = false; }, 500);
        });
    },

    Create_Type() {
      this.SubmitProcessing = true;
      axios
        .post("leave_type", {
          title: this.leave_type.title
        })
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Type");
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Type() {
      this.SubmitProcessing = true;
      axios
        .put("leave_type/" + this.leave_type.id, {
          title: this.leave_type.title
        })
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Type");
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.leave_type = { id: "", title: "" };
    },

    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.deleting = true;
      axios
        .delete("leave_type/" + row.id)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          this.pendingDelete = null;
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Delete_Type");
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
        .post("leave_type/delete/by_selection", { selectedIds: this.selectedIds })
        .then(() => {
          this.deletingBulk = false;
          this.confirmBulkOpen = false;
          this.selectedIds = [];
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Delete_Type");
        })
        .catch(() => {
          this.deletingBulk = false;
          setTimeout(() => NProgress.done(), 500);
          this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
        });
    }
  },

  created: function () {
    this.Get_leaves_type(1);

    Fire.$on("Event_Type", () => {
      setTimeout(() => {
        this.Get_leaves_type(this.page);
        this.modalOpen = false;
      }, 500);
    });

    Fire.$on("Delete_Type", () => {
      setTimeout(() => {
        this.Get_leaves_type(this.page);
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxlt { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxlt { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxlt__pad { padding: var(--pxn-space-6) 0; }

.pxlt__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxlt__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }

.pxlt__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxlt__grow { flex: 1; }
</style>
