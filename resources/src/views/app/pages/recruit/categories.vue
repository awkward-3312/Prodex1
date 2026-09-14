<template>
  <div class="px-next pxrc">
    <!--
      Canario px-next — Categorías de vacantes. Ruta real sin cambios
      (/app/recruit/categories, sin entrada de sidebar — igual que antes).
      Conserva endpoint, payloads, permisos (ninguno a nivel de componente,
      igual que el archivo legacy), búsqueda, orden, paginación, selección
      múltiple y borrado individual/masivo. Toast sigue siendo $bvToast
      (la normalización de toasts es una tarea aparte, no esta).
    -->
    <px-page-header :title="$t('Job_Categories')" :breadcrumbs="[{ label: $t('Recruit') }, { label: $t('Job_Categories') }]">
      <template #actions>
        <px-button
          v-if="selectedIds.length"
          variant="danger"
          icon="trash-2"
          @click="confirmBulkOpen = true"
        >{{ $t('Del') }} ({{ selectedIds.length }})</px-button>
        <px-button variant="primary" icon="plus" @click="New_Category">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <px-alert v-if="error" tone="danger" :title="$t('Failed')" class="pxrc__alert">
      {{ error }}
      <template #actions><px-button size="sm" variant="secondary" @click="Get_Categories(page)">Reintentar</px-button></template>
    </px-alert>

    <div v-if="isLoading" class="pxrc__pad">
      <px-skeleton variant="table" :rows="8" :columns="4" />
    </div>

    <template v-else>
      <div class="pxrc__tablewrap" :class="{ 'is-busy': refreshing }">
        <px-table
          v-if="categories.length"
          :columns="columns"
          :rows="categories"
          row-key="id"
          selectable
          :selected="selectedIds"
          @update:selected="selectedIds = $event"
          :sort-key="sort.field"
          :sort-dir="sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-is_active="{ row }">
            <px-badge :tone="row.is_active ? 'success' : 'neutral'">
              {{ row.is_active ? $t('Active') : $t('Inactive') }}
            </px-badge>
          </template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state v-else icon="tag" :title="$t('Job_Categories')" description="Sin categorías que coincidan con la búsqueda." />
      </div>

      <px-pagination
        v-if="categories.length"
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
      <validation-observer ref="Create_Category">
        <form @submit.prevent="Submit_Category">
          <v-field
            name="name"
            :label="$t('Category_Name')"
            required
            :rules="{ required: true }"
            v-slot="{ invalid, id }"
          >
            <px-input :id="id" v-model="category.name" :placeholder="$t('Category_Name')" :invalid="invalid" />
          </v-field>

          <px-field :label="$t('Description')" class="pxrc__field">
            <template #default="{ id }">
              <px-textarea :id="id" v-model="category.description" rows="3" />
            </template>
          </px-field>

          <px-check v-model="category.is_active" class="pxrc__active">{{ $t('Active') }}</px-check>
        </form>
      </validation-observer>

      <template #footer="{ close }">
        <span class="pxrc__grow" />
        <px-button variant="secondary" :disabled="SubmitProcessing" @click="close">Cancelar</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" @click="Submit_Category">
          {{ $t('submit') }}
        </px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (una fila) -->
    <px-modal v-model="confirmOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxrc__confirm">
        {{ $t('Delete_Text') }}
        <strong v-if="pendingDelete">{{ pendingDelete.name }}</strong>
      </p>
      <template #footer="{ close }">
        <span class="pxrc__grow" />
        <px-button variant="secondary" :disabled="deleting" @click="close">{{ $t('Delete_cancelButtonText') }}</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deleting" @click="doDelete">{{ $t('Delete_confirmButtonText') }}</px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (selección múltiple) -->
    <px-modal v-model="confirmBulkOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxrc__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxrc__grow" />
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
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxSkeleton from "@/components/PxSkeleton.vue";
import VField from "@/views/app/products/next/edit/VField.vue";

export default {
  name: "RecruitCategoriesNext",
  metaInfo: { title: "Job Categories" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab,
    PxField, PxInput, PxTextarea, PxCheck, PxAlert, PxEmptyState, PxModal,
    PxBadge, PxSkeleton, "v-field": VField
  },
  data() {
    return {
      isLoading: true,
      refreshing: false,
      error: null,
      SubmitProcessing: false,
      categories: [],
      totalRows: "",
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "id", type: "desc" },
      selectedIds: [],
      editmode: false,
      modalOpen: false,
      category: { id: "", name: "", description: "", is_active: true },
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
        { key: "name", label: this.$t("Category_Name"), sortable: true, strong: true },
        { key: "jobs_count", label: this.$t("Jobs"), align: "right", numeric: true, sortable: true, width: "110px" },
        { key: "is_active", label: this.$t("Status"), sortable: true, width: "120px" }
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
      if (k === "edit") this.Edit_Category(row);
      else if (k === "delete") { this.pendingDelete = row; this.confirmOpen = true; }
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.Get_Categories(1); }, 350);
    },
    onSort({ key, dir }) {
      this.sort = { field: key, type: dir };
      this.Get_Categories(this.page);
    },
    onPage(p) { if (p !== this.page) { this.page = p; this.Get_Categories(p); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.Get_Categories(1); },

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    },

    Submit_Category() {
      this.$refs.Create_Category.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) this.Create_Category();
          else this.Update_Category();
        }
      });
    },

    New_Category() {
      this.reset_Form();
      this.editmode = false;
      this.modalOpen = true;
    },

    Edit_Category(category) {
      this.reset_Form();
      this.category = { ...category, is_active: !!category.is_active };
      this.editmode = true;
      this.modalOpen = true;
    },

    Get_Categories(page) {
      if (page === 1 || !page) this.refreshing = !this.isLoading;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "recruit/categories?page=" + page +
          "&SortField=" + this.sort.field +
          "&SortType=" + this.sort.type +
          "&search=" + encodeURIComponent(this.search || "") +
          "&limit=" + this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.categories = response.data.categories;
          NProgress.done();
          this.isLoading = false;
          this.refreshing = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; this.refreshing = false; }, 500);
        });
    },

    Create_Category() {
      this.SubmitProcessing = true;
      axios
        .post("recruit/categories", {
          name: this.category.name,
          description: this.category.description,
          is_active: this.category.is_active
        })
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Category");
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Category() {
      this.SubmitProcessing = true;
      axios
        .put("recruit/categories/" + this.category.id, {
          name: this.category.name,
          description: this.category.description,
          is_active: this.category.is_active
        })
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Category");
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.category = { id: "", name: "", description: "", is_active: true };
    },

    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.deleting = true;
      axios
        .delete("recruit/categories/" + row.id)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          this.pendingDelete = null;
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Event_Category");
        })
        .catch(() => {
          this.deleting = false;
          this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
        });
    },

    doDeleteBulk() {
      this.deletingBulk = true;
      axios
        .post("recruit/categories/delete/by_selection", { selectedIds: this.selectedIds })
        .then(() => {
          this.deletingBulk = false;
          this.confirmBulkOpen = false;
          this.selectedIds = [];
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Event_Category");
        })
        .catch(() => {
          this.deletingBulk = false;
          this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
        });
    }
  },

  created: function () {
    this.Get_Categories(1);
    Fire.$on("Event_Category", () => {
      setTimeout(() => {
        this.Get_Categories(this.page);
        this.modalOpen = false;
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrc { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrc { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrc__pad { padding: var(--pxn-space-6) 0; }
.pxrc__alert { margin-top: var(--pxn-space-5); }

.pxrc__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxrc__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }

.pxrc__field { margin-top: var(--pxn-space-5); }
.pxrc__active { margin-top: var(--pxn-space-5); }
.pxrc__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxrc__grow { flex: 1; }
</style>
