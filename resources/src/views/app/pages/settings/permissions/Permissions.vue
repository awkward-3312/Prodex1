<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('GroupPermissions')"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('Users'), href: '#/app/User_Management/Users' }, { label: $t('GroupPermissions') }]"
    >
      <template #actions>
        <px-button
          v-if="currentUserPermissions && currentUserPermissions.includes('permissions_add')"
          variant="primary" size="sm" icon="plus"
          @click="$router.push('/app/User_Management/permissions/store')">{{ $t('Add') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="table" :rows="8" :columns="3" />
    </div>

    <template v-else>
      <div class="pxcfg__tablewrap">
        <px-table
          v-if="roles.length"
          :columns="columns"
          :rows="roles"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #row-actions="{ row }">
            <div v-if="row.id !== 1" class="pxcfg__rowbtns">
              <px-button v-if="currentUserPermissions && currentUserPermissions.includes('permissions_edit')"
                variant="ghost" size="sm" icon-only icon="pencil" aria-label="Editar"
                @click="$router.push('/app/User_Management/permissions/edit/' + row.id)" />
              <px-button v-if="currentUserPermissions && currentUserPermissions.includes('permissions_delete')"
                class="pxcfg__del" variant="ghost" size="sm" icon-only icon="trash-2" aria-label="Eliminar"
                @click="Delete_Role(row.id)" />
            </div>
          </template>
        </px-table>
        <px-empty-state v-else icon="shield-check" title="Sin roles" description="Crea un rol para verlo en esta lista." />
      </div>

      <px-pagination
        v-if="roles.length"
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
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  metaInfo: { title: "Permisos" },
  components: { PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxEmptyState },
  data() {
    return {
      _searchTimer: null,
      isLoading: true,
      serverParams: { columnFilters: {}, sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      totalRows: "",
      search: "",
      limit: "10",
      roles: []
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    columns() {
      return [
        { key: "name", label: this.$t("RoleName"), strong: true },
        { key: "description", label: this.$t("Description") }
      ];
    }
  },
  methods: {
    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Roles(1); }, 350);
    },
    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Roles(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Roles(1); } },
    onSort({ key, dir }) { this.updateParams({ sort: { type: dir, field: key } }); this.Get_Roles(this.serverParams.page); },
    Get_Roles(page) {
      NProgress.start(); NProgress.set(0.1);
      axios.get("roles?page=" + page + "&SortField=" + this.serverParams.sort.field + "&SortType=" + this.serverParams.sort.type + "&search=" + this.search + "&limit=" + this.limit)
        .then(response => { this.roles = response.data.roles; this.totalRows = response.data.totalRows; NProgress.done(); this.isLoading = false; })
        .catch(() => { NProgress.done(); setTimeout(() => { this.isLoading = false; }, 500); });
    },
    Delete_Role(id) {
      this.$swal({ title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning", showCancelButton: true, confirmButtonColor: "var(--px-primary)", cancelButtonColor: "#d33", cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText") }).then(result => {
        if (result.value || result.isConfirmed) {
          axios.delete("roles/" + id).then(() => { this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success"); Fire.$emit("Delete_role"); }).catch(() => this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning"));
        }
      });
    }
  },
  created: function() {
    this.Get_Roles(1);
    Fire.$on("Delete_role", () => { setTimeout(() => { this.Get_Roles(this.serverParams.page); }, 500); });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__tablewrap { margin-top: var(--pxn-space-5); }
.pxcfg__rowbtns { display: flex; gap: var(--pxn-space-2); justify-content: flex-end; }
.pxcfg__del ::v-deep .pxn-btn__icon { color: var(--pxn-danger); }
</style>
