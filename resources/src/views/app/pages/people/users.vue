<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('UserManagement')"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('Users') }]"
    >
      <template #actions>
        <px-menu :items="exportMenu" align="end" @select="onExport">
          <template #trigger>
            <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button>
          </template>
        </px-menu>
        <px-button
          v-if="currentUserPermissions && currentUserPermissions.includes('users_add')"
          variant="primary" size="sm" icon="plus" @click="New_User()">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      :filter-count="activeFilterCount"
      @update:search="onSearchInput"
      @open-filters="filtersOpen = !filtersOpen"
    />

    <div v-if="filtersOpen" class="pxcfg__filters">
      <div class="pxcfg__filters-grid">
        <px-field :label="$t('username')">
          <template #default="{ id }"><px-input :id="id" v-model="Filter_Name" :placeholder="$t('username')" /></template>
        </px-field>
        <px-field :label="$t('Phone')">
          <template #default="{ id }"><px-input :id="id" v-model="Filter_Phone" :placeholder="$t('SearchByPhone')" /></template>
        </px-field>
        <px-field :label="$t('Email')">
          <template #default="{ id }"><px-input :id="id" v-model="Filter_Email" :placeholder="$t('SearchByEmail')" /></template>
        </px-field>
        <px-field :label="$t('Status')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_status" :reduce="o => o.value" :placeholder="$t('Choose_Status')"
              :options="[{ label: 'Actif', value: '1' }, { label: 'Inactif', value: '0' }]" />
          </template>
        </px-field>
      </div>
      <div class="pxcfg__filters-act">
        <px-button size="sm" variant="primary" icon="filter" @click="Get_Users(serverParams.page)">{{ $t('Filter') }}</px-button>
        <px-button size="sm" variant="ghost" icon="x" @click="Reset_Filter()">{{ $t('Reset') }}</px-button>
      </div>
    </div>

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="table" :rows="10" :columns="6" />
    </div>

    <template v-else>
      <div class="pxcfg__tablewrap">
        <px-table
          v-if="users.length"
          :columns="columns"
          :rows="users"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-statut="{ row }">
            <px-check type="switch" :modelValue="!!row.statut" @change="v => { row.statut = v; isChecked(row); }" />
          </template>
          <template #row-actions="{ row }">
            <div class="pxcfg__rowbtns">
              <px-button v-if="currentUserPermissions && currentUserPermissions.includes('users_edit')"
                variant="ghost" size="sm" icon-only icon="pencil" aria-label="Edit" @click="Edit_User(row)" />
              <px-button v-if="currentUserPermissions && currentUserPermissions.includes('users_delete') && currentUser && row.id !== currentUser.id"
                class="pxcfg__del" variant="ghost" size="sm" icon-only icon="trash-2" aria-label="Delete" @click="Remove_User(row.id)" />
            </div>
          </template>
        </px-table>
        <px-empty-state v-else icon="users" title="Sin usuarios" description="Agrega un usuario para verlo en esta lista." />
      </div>

      <px-pagination
        v-if="users.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxMenu from "@/components/px-next/PxMenu.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "Users"
  },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxField,
    PxInput, PxCheck, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      _searchTimer: null,
      filtersOpen: false,
      editmode: false,
      isLoading: true,
      SubmitProcessing: false,
      email_exist: "",
      serverParams: {
        columnFilters: {},
        sort: {
          field: "id",
          type: "desc"
        },
        page: 1,
        perPage: 10
      },
      totalRows: "",
      search: "",
      limit: "10",
      Filter_Name: "",
      Filter_Email: "",
      Filter_status: "",
      Filter_Phone: "",
      permissions: {},
      users: [],
      roles: [],
      warehouses: [],
      data: new FormData(),
      user: {
        firstname: "",
        lastname: "",
        username: "",
        password: "",
        NewPassword: null,
        email: "",
        phone: "",
        statut: "",
        role_id: "",
        avatar: "",
        is_all_warehouses: 1,
      },
      assigned_warehouses: [],
    };
  },

  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    activeFilterCount() {
      return [this.Filter_Name, this.Filter_Phone, this.Filter_Email, this.Filter_status]
        .filter(v => v !== "" && v !== null && v !== undefined).length;
    },
    exportMenu() {
      return [
        { key: "pdf", label: "PDF", icon: "file-text" },
        { key: "xlsx", label: "CSV / Excel", icon: "file-spreadsheet" }
      ];
    },
    columns() {
      return [
        { key: "firstname", label: this.$t("Firstname") },
        { key: "lastname", label: this.$t("lastname") },
        { key: "username", label: this.$t("username"), strong: true },
        { key: "email", label: this.$t("Email") },
        { key: "phone", label: this.$t("Phone") },
        { key: "statut", label: this.$t("Status"), align: "center", sortable: false }
      ];
    }
  },

  methods: {
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Users(1); }, 350);
    },
    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Users(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Users(1); } },
    onSort({ key, dir }) {
      this.updateParams({ sort: { type: dir, field: key } });
      this.Get_Users(this.serverParams.page);
    },
    onExport(item) {
      const k = item && item.key;
      if (k === "pdf") this.Users_PDF();
      else if (k === "xlsx") this.exportCsv();
    },
    exportCsv() {
      const cols = this.columns.filter(c => c.key !== "statut");
      const head = cols.map(c => c.label);
      const lines = [head.join(",")].concat(
        (this.users || []).map(r =>
          cols.map(c => `"${String(r[c.key] == null ? "" : r[c.key]).replace(/"/g, '""')}"`).join(",")
        )
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "users.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    Reset_Filter() {
      this.search = "";
      this.Filter_Name = "";
      this.Filter_status = "";
      this.Filter_Phone = "";
      this.Filter_Email = "";
      this.Get_Users(this.serverParams.page);
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    Selected_Warehouse(value) {
      if (!value.length) {
        this.assigned_warehouses = [];
      }
    },

    isChecked(user) {
      axios
        .put("users_switch_activated/" + user.id, {
          statut: user.statut,
          id: user.id
        })
        .then(response => {
          if (response.data.success) {
            if (user.statut) {
              user.statut = 1;
              this.makeToast("success", this.$t("ActivateUser"), this.$t("Success"));
            } else {
              user.statut = 0;
              this.makeToast("success", this.$t("DisActivateUser"), this.$t("Success"));
            }
          } else {
            user.statut = 1;
            this.makeToast("warning", this.$t("Delete_Therewassomethingwronge"), this.$t("Warning"));
          }
        })
        .catch(error => {
          user.statut = 1;
          this.makeToast("warning", this.$t("Delete_Therewassomethingwronge"), this.$t("Warning"));
        });
    },

    //--------------------------- Users PDF ---------------------------\\
    Users_PDF() {
      const pdf = new jsPDF("p", "pt");
      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      try {
        pdf.addFont(fontPath, "Vazirmatn", "normal");
        pdf.addFont(fontPath, "Vazirmatn", "bold");
      } catch (e) { /* ignore if already added */ }
      pdf.setFont("Vazirmatn", "normal");

      const headers = [
        this.$t("Firstname"),
        this.$t("lastname"),
        this.$t("username"),
        this.$t("Email"),
        this.$t("Phone")
      ];

      const body = (this.users || []).map(u => ([
        u.firstname,
        u.lastname,
        u.username,
        u.email,
        u.phone
      ]));

      const marginX = 40;
      const rtl =
        (this.$i18n && ["ar", "fa", "ur", "he"].includes(this.$i18n.locale)) ||
        (typeof document !== 'undefined' && document.documentElement.dir === 'rtl');

      autoTable(pdf, {
        head: [headers],
        body,
        startY: 110,
        theme: 'striped',
        margin: { left: marginX, right: marginX },
        styles: { font: 'Vazirmatn', fontSize: 9, cellPadding: 4, halign: rtl ? 'right' : 'left', textColor: 33 },
        headStyles: { font: 'Vazirmatn', fontStyle: 'bold', fillColor: [63, 81, 181], textColor: 255 },
        alternateRowStyles: { fillColor: [245, 247, 250] },
        didDrawPage: (d) => {
          const pageW = pdf.internal.pageSize.getWidth();
          const pageH = pdf.internal.pageSize.getHeight();
          pdf.setFillColor(63, 81, 181);
          pdf.rect(0, 0, pageW, 60, 'F');
          pdf.setTextColor(255);
          pdf.setFont('Vazirmatn', 'bold');
          pdf.setFontSize(16);
          const title = this.$t('UserManagement') || 'User List';
          rtl ? pdf.text(title, pageW - marginX, 38, { align: 'right' })
              : pdf.text(title, marginX, 38);
          pdf.setTextColor(33);
          pdf.setFontSize(8);
          const pn = `${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`;
          rtl ? pdf.text(pn, marginX, pageH - 14, { align: 'left' })
              : pdf.text(pn, pageW - marginX, pageH - 14, { align: 'right' });
        }
      });

      pdf.save("User_List.pdf");
    },

    setToStrings() {
      if (this.Filter_status === null) {
        this.Filter_status = "";
      }
    },

    //----------------------------------- Get All Users  ---------------------------\\
    Get_Users(page) {
      NProgress.start();
      NProgress.set(0.1);
      this.setToStrings();
      axios
        .get(
          "users?page=" +
            page +
            "&name=" +
            this.Filter_Name +
            "&statut=" +
            this.Filter_status +
            "&phone=" +
            this.Filter_Phone +
            "&email=" +
            this.Filter_Email +
            "&SortField=" +
            this.serverParams.sort.field +
            "&SortType=" +
            this.serverParams.sort.type +
            "&search=" +
            this.search +
            "&limit=" +
            this.limit
        )
        .then(response => {
          this.users = response.data.users;
          this.roles = response.data.roles;
          this.warehouses = response.data.warehouses;
          this.totalRows = response.data.totalRows;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(response => {
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    New_User() {
      this.$router.push({ name: 'Create_User' });
    },

    Edit_User(user) {
      this.$router.push({ name: 'Edit_User', params: { id: user.id } });
    },

    reset_Form() {
      this.user = {
        id: "",
        firstname: "",
        lastname: "",
        username: "",
        password: "",
        NewPassword: null,
        email: "",
        phone: "",
        statut: "",
        role_id: "",
        avatar: "",
        is_all_warehouses: 1,
      };
      this.data = new FormData();
      this.assigned_warehouses = [];
      this.email_exist = "";
    },

    Remove_User(id) {
      if (this.currentUser && id === this.currentUser.id) {
        this.$swal({
          title: this.$t("Error"),
          text: "You cannot delete your own account.",
          type: "error",
          confirmButtonText: this.$t("OK")
        });
        return;
      }

      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "var(--px-primary)",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios
            .delete("users/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              Fire.$emit("Delete_User");
            })
            .catch(error => {
              let errorMessage = "this User already linked with other operation";
              if (error.response && error.response.data && error.response.data.message) {
                errorMessage = error.response.data.message;
              }
              this.$swal(
                this.$t("Delete_Failed"),
                errorMessage,
                "warning"
              );
            });
        }
      });
    }
  }, // END METHODS

  created: function() {
    this.Get_Users(1);

    Fire.$on("Event_User", () => {
      setTimeout(() => {
        this.Get_Users(this.serverParams.page);
      }, 500);
    });

    Fire.$on("Delete_User", () => {
      setTimeout(() => {
        this.Get_Users(this.serverParams.page);
      }, 500);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__tablewrap { margin-top: var(--pxn-space-5); }
.pxcfg__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxcfg__filters-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 560px) { .pxcfg__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__filters-act { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); }
.pxcfg__rowbtns { display: flex; gap: var(--pxn-space-2); justify-content: flex-end; }
.pxcfg__del ::v-deep .pxn-btn__icon { color: var(--pxn-danger); }
</style>
