<template>
  <div class="main-content">
    <breadcumb :page="$t('GroupPermissions')" :folder="$t('Users')"/>

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="roles"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-sort-change="onSortChange"
        @on-search="onSearch"
        :search-options="{ enabled: true, placeholder: $t('Search_this_table') }"
        :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'Siguiente', prevLabel: 'Anterior' }"
        styleClass="table-hover tableOne vgt-table"
      >
        <div slot="table-actions" class="mt-2 mb-3">
          <router-link class="btn-rounded btn btn-primary ripple btn-icon m-1" v-if="currentUserPermissions && currentUserPermissions.includes('permissions_add')" to="/app/User_Management/permissions/store">
            <span class="ul-btn__icon"><lucide-icon name="plus" /></span>
            <span class="ul-btn__text ml-1">{{$t('Add')}}</span>
          </router-link>
        </div>

        <template slot="table-row" slot-scope="props" v-if="props.row.id !==1">
          <span v-if="props.column.field == 'actions'">
            <router-link v-if="currentUserPermissions && currentUserPermissions.includes('permissions_edit')" title="Editar" v-b-tooltip.hover :to="'/app/User_Management/permissions/edit/'+props.row.id">
              <lucide-icon class="text-25 text-success" name="pencil" />
            </router-link>
            <a title="Eliminar" v-b-tooltip.hover v-if="currentUserPermissions && currentUserPermissions.includes('permissions_delete')" @click="Delete_Role(props.row.id)">
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>
        </template>
      </vue-good-table>
    </b-card>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Permisos" },
  data() {
    return {
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
        { label: this.$t("RoleName"), field: "name", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Description"), field: "description", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Action"), field: "actions", tdClass: "text-left", thClass: "text-left", sortable: false }
      ];
    }
  },
  methods: {
    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onPageChange({ currentPage }) { if (this.serverParams.page !== currentPage) { this.updateParams({ page: currentPage }); this.Get_Roles(currentPage); } },
    onPerPageChange({ currentPerPage }) { if (this.limit !== currentPerPage) { this.limit = currentPerPage; this.updateParams({ page: 1, perPage: currentPerPage }); this.Get_Roles(1); } },
    onSortChange(params) { this.updateParams({ sort: { type: params[0].type, field: params[0].field } }); this.Get_Roles(this.serverParams.page); },
    onSearch(value) { this.search = value.searchTerm; this.Get_Roles(this.serverParams.page); },
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