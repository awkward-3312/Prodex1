<template>
  <div class="px-next pxdept">
    <!--
      Migracion px-next — Departamentos. Ruta real sin cambios
      (/app/hrm/departments). Esta misma vista se abre desde DOS entradas
      de menu (Personal y Estructura) — un solo componente, sin duplicar.
      Conserva endpoint, payloads, permisos (gate unico en backend sobre
      Department::class, sin gating de componente), busqueda, orden,
      paginacion, seleccion multiple, borrado individual/masivo
      (soft-delete) y las llamadas de datos de apoyo que ya hacia el
      legacy antes de abrir el modal. Notificacion de resultado sigue
      siendo $swal (igual que antes).

      Peculiaridades legacy preservadas TAL CUAL:
      - Edit_Department hace TRES llamadas antes de asignar el registro:
        Get_Department (refetch de la lista), Get_Data_Edit (companias) y
        Get_employees_by_company (jefes de departamento de esa compania) —
        se preservan las tres, en el mismo orden.
      - onSortChange calculaba un `field` remapeado (company_name ->
        company_id) que nunca se usaba: el legacy siempre mandaba la
        columna cruda al backend. A diferencia de Dias festivos, aqui el
        backend SI expone company_name/employee_head como alias reales de
        un JOIN (DepartmentsController@index), asi que ordenar por esas
        columnas no rompe — verificado en vivo, no asumido.
      - department_head es opcional y depende de la compania elegida
        (cascada): al cambiar de compania se limpian employees y
        department_head, igual que el legacy.
    -->
    <px-page-header :title="$t('department')" :breadcrumbs="[{ label: $t('hrm') }, { label: $t('department') }]">
      <template #actions>
        <px-button
          v-if="selectedIds.length"
          variant="danger"
          icon="trash-2"
          @click="confirmBulkOpen = true"
        >{{ $t('Del') }} ({{ selectedIds.length }})</px-button>
        <px-button variant="primary" icon="plus" @click="New_Department">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxdept__pad">
      <px-skeleton variant="table" :rows="8" :columns="3" />
    </div>

    <template v-else>
      <div class="pxdept__tablewrap" :class="{ 'is-busy': refreshing }">
        <px-table
          v-if="departments.length"
          :columns="columns"
          :rows="departments"
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

        <px-empty-state v-else icon="building-2" :title="$t('department')" description="Sin departamentos que coincidan con la búsqueda." />
      </div>

      <px-pagination
        v-if="departments.length"
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
      <px-validation-observer ref="Create_Department">
        <form @submit.prevent="Submit_Department">
          <v-field name="department" :label="$t('department')" required :rules="{ required: true }" v-slot="{ invalid, id }">
            <px-input :id="id" v-model="department.department" :placeholder="$t('Enter_Department_Name')" :invalid="invalid" />
          </v-field>

          <v-field name="Company" :label="$t('Company')" required :rules="{ required: true }" v-slot="{ invalid, id }" class="pxdept__field">
            <vs-px
              :input-id="id"
              :invalid="invalid"
              v-model="department.company_id"
              @input="Selected_Company"
              :reduce="o => o.value"
              :placeholder="$t('Choose_Company')"
              :options="companies.map(c => ({ label: c.name, value: c.id }))"
            />
          </v-field>

          <px-field :label="$t('Department_Head')" class="pxdept__field">
            <template #default="{ id }">
              <vs-px
                :input-id="id"
                v-model="department.department_head"
                @input="Selected_Employee"
                :reduce="o => o.value"
                :placeholder="$t('Choose_Department_Head')"
                :options="employees.map(e => ({ label: e.username, value: e.id }))"
              />
            </template>
          </px-field>
        </form>
      </px-validation-observer>

      <template #footer="{ close }">
        <span class="pxdept__grow" />
        <px-button variant="secondary" :disabled="SubmitProcessing" @click="close">Cancelar</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" @click="Submit_Department">
          {{ $t('submit') }}
        </px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (una fila) -->
    <px-modal v-model="confirmOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxdept__confirm">
        {{ $t('Delete_Text') }}
        <strong v-if="pendingDelete">{{ pendingDelete.department }}</strong>
      </p>
      <template #footer="{ close }">
        <span class="pxdept__grow" />
        <px-button variant="secondary" :disabled="deleting" @click="close">{{ $t('Delete_cancelButtonText') }}</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deleting" @click="doDelete">{{ $t('Delete_confirmButtonText') }}</px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (selección múltiple) -->
    <px-modal v-model="confirmBulkOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxdept__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxdept__grow" />
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
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxSkeleton from "@/components/PxSkeleton.vue";
import VField from "@/views/app/products/next/edit/VField.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  name: "HrmDepartmentNext",
  metaInfo: { title: "Department" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab,
    PxField, PxInput, PxEmptyState, PxModal, PxSkeleton,
    "v-field": VField, "vs-px": VsPx
  },
  data() {
    return {
      isLoading: true,
      refreshing: false,
      SubmitProcessing: false,
      departments: [],
      employees: [],
      companies: [],
      totalRows: "",
      page: 1,
      limit: "10",
      search: "",
      _searchTimer: null,
      sort: { field: "id", type: "desc" },
      selectedIds: [],
      editmode: false,
      modalOpen: false,
      department: { id: "", department: "", company_id: "", department_head: "" },
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
        { key: "department", label: this.$t("department"), sortable: true, strong: true },
        { key: "employee_head", label: this.$t("Department_Head"), sortable: true },
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
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.Edit_Department(row);
      else if (k === "delete") { this.pendingDelete = row; this.confirmOpen = true; }
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.Get_Department(1); }, 350);
    },
    onSort({ key, dir }) {
      // Legacy siempre mandaba la columna cruda (ver nota arriba) — se
      // preserva exacto, sin remapear company_name -> company_id.
      this.sort = { field: key, type: dir };
      this.Get_Department(this.page);
    },
    onPage(p) { if (p !== this.page) { this.page = p; this.Get_Department(p); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.Get_Department(1); },

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },
    makeToast(variant, msg, title) {
      notifications.notify(msg, { title: title, variant: variant, solid: true });
    },

    Submit_Department() {
      this.$refs.Create_Department.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) this.Create_Department();
          else this.Update_Department();
        }
      });
    },

    New_Department() {
      this.reset_Form();
      this.editmode = false;
      this.Get_Data_Create();
      this.modalOpen = true;
    },

    Edit_Department(department) {
      this.Get_Department(this.page);
      this.reset_Form();
      this.Get_Data_Edit(department.id);
      this.Get_employees_by_company(department.company_id);
      this.department = department;
      this.editmode = true;
      this.modalOpen = true;
    },

    Selected_Company(value) {
      if (value === null) {
        this.department.company_id = "";
      }
      this.employees = [];
      this.department.department_head = "";
      this.Get_employees_by_company(value);
    },

    Selected_Employee(value) {
      if (value === null) {
        this.department.department_head = "";
      }
    },

    Get_employees_by_company(value) {
      axios
        .get("/core/get_employees_by_company?id=" + value)
        .then(({ data }) => (this.employees = data));
    },

    Get_Data_Create() {
      axios
        .get("/departments/create")
        .then(response => {
          this.companies = response.data.companies;
        })
        .catch(() => {});
    },

    Get_Data_Edit(id) {
      axios
        .get("/departments/" + id + "/edit")
        .then(response => {
          this.companies = response.data.companies;
        })
        .catch(() => {});
    },

    Get_Department(page) {
      if (page === 1 || !page) this.refreshing = !this.isLoading;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "departments?page=" + page +
          "&SortField=" + this.sort.field +
          "&SortType=" + this.sort.type +
          "&search=" + this.search +
          "&limit=" + this.limit
        )
        .then(response => {
          this.totalRows = response.data.totalRows;
          this.departments = response.data.departments;
          NProgress.done();
          this.isLoading = false;
          this.refreshing = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; this.refreshing = false; }, 500);
        });
    },

    Create_Department() {
      this.SubmitProcessing = true;
      axios
        .post("departments", {
          department: this.department.department,
          company_id: this.department.company_id,
          department_head: this.department.department_head
        })
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Department");
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Department() {
      this.SubmitProcessing = true;
      axios
        .put("departments/" + this.department.id, {
          department: this.department.department,
          company_id: this.department.company_id,
          department_head: this.department.department_head
        })
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Department");
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.department = { id: "", department: "", company_id: "", department_head: "" };
    },

    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.deleting = true;
      axios
        .delete("departments/" + row.id)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          this.pendingDelete = null;
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Delete_Department");
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
        .post("departments/delete/by_selection", { selectedIds: this.selectedIds })
        .then(() => {
          this.deletingBulk = false;
          this.confirmBulkOpen = false;
          this.selectedIds = [];
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Delete_Department");
        })
        .catch(() => {
          this.deletingBulk = false;
          setTimeout(() => NProgress.done(), 500);
          this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
        });
    }
  },

  created: function () {
    this.Get_Department(1);

    Fire.$on("Event_Department", () => {
      setTimeout(() => {
        this.Get_Department(this.page);
        this.modalOpen = false;
      }, 500);
    });

    Fire.$on("Delete_Department", () => {
      setTimeout(() => {
        this.Get_Department(this.page);
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxdept { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxdept { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxdept__pad { padding: var(--pxn-space-6) 0; }

.pxdept__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxdept__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }

.pxdept__field { margin-top: var(--pxn-space-5); }
.pxdept__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxdept__grow { flex: 1; }
</style>
