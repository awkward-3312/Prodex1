<template>
  <div class="px-next pxcompany">
    <!--
      Migracion px-next — Empresa. Ruta real sin cambios (/app/hrm/company).
      Estructuralmente parecida a Departamentos pero NO idéntica — se
      verificó cada endpoint/comportamiento por separado antes de migrar:

      - CompanyController@index NO hace JOIN con alias (name/phone/
        country/email son columnas propias de companies), así que no
        aplica el hallazgo de sort de Departamentos ni el bug de sort de
        Días festivos: aquí simplemente no hay alias que pueda romper.
      - No hay selects en cascada (no depende de otra entidad, ES la
        entidad compañía), así que no existen las llamadas
        Get_Data_Create/Get_Data_Edit/Get_employees_by_company que sí
        tiene Departamentos.
      - Remove_Company usa $swal directamente como confirmación (no un
        modal bootstrap intermedio) — igual que categories.vue, distinto
        del patrón modal-then-swal de otras vistas. Se preserva con
        PxModal(sm) para la confirmación y $swal para el resultado, igual
        que en las migraciones anteriores.
      - this.company = company es asignación directa (sin spread), igual
        que Departamentos y Tipos de permiso.
      - Fire events Event_Company / Delete_Company preservados exactos.
    -->
    <px-page-header :title="$t('Company')" :breadcrumbs="[{ label: $t('hrm') }, { label: $t('Company') }]">
      <template #actions>
        <px-button
          v-if="selectedIds.length"
          variant="danger"
          icon="trash-2"
          @click="confirmBulkOpen = true"
        >{{ $t('Del') }} ({{ selectedIds.length }})</px-button>
        <px-button variant="primary" icon="plus" @click="New_Company">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxcompany__pad">
      <px-skeleton variant="table" :rows="8" :columns="4" />
    </div>

    <template v-else>
      <div class="pxcompany__tablewrap" :class="{ 'is-busy': refreshing }">
        <px-table
          v-if="companies.length"
          :columns="columns"
          :rows="companies"
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

        <px-empty-state v-else icon="building" :title="$t('Company')" description="Sin empresas que coincidan con la búsqueda." />
      </div>

      <px-pagination
        v-if="companies.length"
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
      <validation-observer ref="Create_Company">
        <form @submit.prevent="Submit_Company">
          <v-field name="Name" :label="$t('Name')" required :rules="{ required: true }" v-slot="{ invalid, id }">
            <px-input :id="id" v-model="company.name" :placeholder="$t('Enter_Company_Name')" :invalid="invalid" />
          </v-field>

          <px-field :label="$t('Email')" class="pxcompany__field">
            <template #default="{ id }">
              <px-input :id="id" v-model="company.email" :placeholder="$t('Enter_email_address')" />
            </template>
          </px-field>

          <px-field :label="$t('Phone')" class="pxcompany__field">
            <template #default="{ id }">
              <px-input :id="id" v-model="company.phone" :placeholder="$t('Enter_Company_Phone')" />
            </template>
          </px-field>

          <px-field :label="$t('Country')" class="pxcompany__field">
            <template #default="{ id }">
              <px-input :id="id" v-model="company.country" :placeholder="$t('Enter_Company_Country')" />
            </template>
          </px-field>
        </form>
      </validation-observer>

      <template #footer="{ close }">
        <span class="pxcompany__grow" />
        <px-button variant="secondary" :disabled="SubmitProcessing" @click="close">Cancelar</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" @click="Submit_Company">
          {{ $t('submit') }}
        </px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (una fila) -->
    <px-modal v-model="confirmOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxcompany__confirm">
        {{ $t('Delete_Text') }}
        <strong v-if="pendingDelete">{{ pendingDelete.name }}</strong>
      </p>
      <template #footer="{ close }">
        <span class="pxcompany__grow" />
        <px-button variant="secondary" :disabled="deleting" @click="close">{{ $t('Delete_cancelButtonText') }}</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deleting" @click="doDelete">{{ $t('Delete_confirmButtonText') }}</px-button>
      </template>
    </px-modal>

    <!-- Confirmar eliminar (selección múltiple) -->
    <px-modal v-model="confirmBulkOpen" :title="$t('Delete_Title')" size="sm">
      <p class="pxcompany__confirm">{{ $t('Delete_Text') }}</p>
      <template #footer="{ close }">
        <span class="pxcompany__grow" />
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

export default {
  name: "HrmCompanyNext",
  metaInfo: { title: "Company" },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab,
    PxField, PxInput, PxEmptyState, PxModal, PxSkeleton,
    "v-field": VField
  },
  data() {
    return {
      isLoading: true,
      refreshing: false,
      SubmitProcessing: false,
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
      company: { id: "", name: "", email: "", country: "", phone: "" },
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
        { key: "name", label: this.$t("Name"), sortable: true, strong: true },
        { key: "phone", label: this.$t("Phone"), sortable: true },
        { key: "country", label: this.$t("Country"), sortable: true },
        { key: "email", label: this.$t("Email"), sortable: true }
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
      if (k === "edit") this.Edit_Company(row);
      else if (k === "delete") { this.pendingDelete = row; this.confirmOpen = true; }
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.page = 1; this.Get_Company(1); }, 350);
    },
    onSort({ key, dir }) {
      this.sort = { field: key, type: dir };
      this.Get_Company(this.page);
    },
    onPage(p) { if (p !== this.page) { this.page = p; this.Get_Company(p); } },
    onLimit(v) { this.limit = String(v); this.page = 1; this.Get_Company(1); },

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },
    makeToast(variant, msg, title) {
      notifications.notify(msg, { title: title, variant: variant, solid: true });
    },

    Submit_Company() {
      this.$refs.Create_Company.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else {
          if (!this.editmode) this.Create_Company();
          else this.Update_Company();
        }
      });
    },

    New_Company() {
      this.reset_Form();
      this.editmode = false;
      this.modalOpen = true;
    },

    Edit_Company(company) {
      this.Get_Company(this.page);
      this.reset_Form();
      this.company = company;
      this.editmode = true;
      this.modalOpen = true;
    },

    Get_Company(page) {
      if (page === 1 || !page) this.refreshing = !this.isLoading;
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "company?page=" + page +
          "&SortField=" + this.sort.field +
          "&SortType=" + this.sort.type +
          "&search=" + this.search +
          "&limit=" + this.limit
        )
        .then(response => {
          this.companies = response.data.companies;
          this.totalRows = response.data.totalRows;
          NProgress.done();
          this.isLoading = false;
          this.refreshing = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; this.refreshing = false; }, 500);
        });
    },

    Create_Company() {
      this.SubmitProcessing = true;
      axios
        .post("company", {
          name: this.company.name,
          email: this.company.email,
          country: this.company.country,
          phone: this.company.phone
        })
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Company");
          this.makeToast("success", this.$t("Created_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Update_Company() {
      this.SubmitProcessing = true;
      axios
        .put("company/" + this.company.id, {
          name: this.company.name,
          email: this.company.email,
          country: this.company.country,
          phone: this.company.phone
        })
        .then(() => {
          this.SubmitProcessing = false;
          Fire.$emit("Event_Company");
          this.makeToast("success", this.$t("Updated_in_successfully"), this.$t("Success"));
        })
        .catch(() => {
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    reset_Form() {
      this.company = { id: "", name: "", email: "", country: "", phone: "" };
    },

    doDelete() {
      const row = this.pendingDelete;
      if (!row) return;
      this.deleting = true;
      axios
        .delete("company/" + row.id)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          this.pendingDelete = null;
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Delete_Company");
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
        .post("company/delete/by_selection", { selectedIds: this.selectedIds })
        .then(() => {
          this.deletingBulk = false;
          this.confirmBulkOpen = false;
          this.selectedIds = [];
          this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
          Fire.$emit("Delete_Company");
        })
        .catch(() => {
          this.deletingBulk = false;
          setTimeout(() => NProgress.done(), 500);
          this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
        });
    }
  },

  created: function () {
    this.Get_Company(1);

    Fire.$on("Event_Company", () => {
      setTimeout(() => {
        this.Get_Company(this.page);
        this.modalOpen = false;
      }, 500);
    });

    Fire.$on("Delete_Company", () => {
      setTimeout(() => {
        this.Get_Company(this.page);
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcompany { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcompany { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcompany__pad { padding: var(--pxn-space-6) 0; }

.pxcompany__tablewrap { margin-top: var(--pxn-space-5); transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxcompany__tablewrap.is-busy { opacity: 0.55; pointer-events: none; }

.pxcompany__field { margin-top: var(--pxn-space-5); }
.pxcompany__confirm { margin: 0; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxcompany__grow { flex: 1; }
</style>
