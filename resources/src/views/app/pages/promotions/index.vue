<template>
  <div class="main-content">
    <breadcumb page="Promociones" folder="Ventas" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="promotions"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-sort-change="onSortChange"
        @on-search="onSearch"
        :search-options="{ enabled: true, placeholder: 'Buscar en esta tabla' }"
        :select-options="{ enabled: false }"
        :pagination-options="{
          enabled: true,
          mode: 'records',
          nextLabel: 'Siguiente',
          prevLabel: 'Anterior'
        }"
        styleClass="table-hover tableOne vgt-table"
      >
        <div slot="table-actions" class="mt-2 mb-3">
          <b-button @click="New_Promotion()" class="btn-rounded" variant="btn btn-primary btn-icon m-1">
            <lucide-icon name="plus" /> Agregar
          </b-button>
          <router-link tag="b-button" to="/app/promotions/usages" class="btn-rounded btn btn-outline-primary btn-icon m-1">
            <lucide-icon name="bar-chart-3" /> Informe de uso
          </router-link>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field === 'kind'">
            <b-badge :variant="props.row.kind === 'discount' ? 'info' : 'success'">{{ promotionTypeLabel(props.row.kind) }}</b-badge>
          </span>
          <span v-else-if="props.column.field === 'value'">
            <template v-if="props.row.discount_type === 'percentage'">{{ props.row.discount_value }}%</template>
            <template v-else>{{ props.row.discount_value }}</template>
          </span>
          <span v-else-if="props.column.field === 'warehouses'">
            <span v-if="props.row.warehouses && props.row.warehouses.length">
              <b-badge v-for="w in props.row.warehouses" :key="w.id" variant="light" class="mr-1">{{ w.name }}</b-badge>
            </span>
            <span v-else class="text-muted">—</span>
          </span>
          <span v-else-if="props.column.field === 'window'"><small>{{ formatDate(props.row.starts_at) }} → {{ formatDate(props.row.ends_at) }}</small></span>
          <span v-else-if="props.column.field === 'is_active'">
            <b-form-checkbox :checked="props.row.is_active" switch @change="Toggle_Promotion(props.row)" />
          </span>
          <span v-else-if="props.column.field === 'actions'">
            <a @click="Edit_Promotion(props.row)" title="Editar" v-b-tooltip.hover><lucide-icon class="text-25 text-success" name="pencil" /></a>
            <a title="Eliminar" v-b-tooltip.hover @click="Remove_Promotion(props.row.id)"><lucide-icon class="text-25 text-danger ml-2" name="x" /></a>
          </span>
        </template>
      </vue-good-table>
    </b-card>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Promociones" },
  data() {
    return {
      isLoading: true,
      serverParams: { sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      totalRows: "",
      search: "",
      limit: "10",
      promotions: []
    };
  },
  computed: {
    columns() {
      return [
        { label: "Nombre", field: "name", tdClass: "text-left", thClass: "text-left" },
        { label: "Tipo", field: "kind", tdClass: "text-left", thClass: "text-left" },
        { label: "Valor", field: "value", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: "Almacenes", field: "warehouses", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: "Vigencia", field: "window", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: "Prioridad", field: "priority", tdClass: "text-left", thClass: "text-left" },
        { label: "Activa", field: "is_active", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: "Acciones", field: "actions", tdClass: "text-left", thClass: "text-left", sortable: false }
      ];
    }
  },
  methods: {
    promotionTypeLabel(kind) {
      const labels = { discount: 'Descuento', coupon: 'Cupón', promotion: 'Promoción', offer: 'Oferta' };
      return labels[String(kind || '').toLowerCase()] || kind || '—';
    },
    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onPageChange({ currentPage }) { if (this.serverParams.page !== currentPage) { this.updateParams({ page: currentPage }); this.Get_Promotions(currentPage); } },
    onPerPageChange({ currentPerPage }) { if (this.limit !== currentPerPage) { this.limit = currentPerPage; this.updateParams({ page: 1, perPage: currentPerPage }); this.Get_Promotions(1); } },
    onSortChange(params) { this.updateParams({ sort: { type: params[0].type, field: params[0].field } }); this.Get_Promotions(this.serverParams.page); },
    onSearch(value) { this.search = value.searchTerm; this.Get_Promotions(this.serverParams.page); },
    makeToast(variant, msg, title) { this.$root.$bvToast.toast(msg, { title, variant, solid: true }); },
    formatDate(value) { if (!value) return "—"; try { return value.replace("T", " ").substring(0, 16); } catch (e) { return value; } },
    Get_Promotions(page) {
      NProgress.start(); NProgress.set(0.1);
      axios.get("promotions?page=" + page + "&SortField=" + this.serverParams.sort.field + "&SortType=" + this.serverParams.sort.type + "&search=" + (this.search || "") + "&limit=" + this.limit)
        .then(response => { this.promotions = response.data.promotions || []; this.totalRows = response.data.totalRows || 0; NProgress.done(); this.isLoading = false; })
        .catch(() => { NProgress.done(); this.isLoading = false; });
    },
    New_Promotion() { this.$router.push("/app/promotions/create"); },
    Edit_Promotion(promo) { this.$router.push("/app/promotions/edit/" + promo.id); },
    Toggle_Promotion(row) { axios.post("promotions/" + row.id + "/toggle").then(() => this.Get_Promotions(this.serverParams.page)).catch(error => this.toastApiError(error)); },
    toastApiError(error) {
      let msg = this.$t("InvalidData");
      if (error && error.response && error.response.data) {
        const data = error.response.data;
        if (data.errors) msg = Object.values(data.errors).flat().join(" ");
        else if (data.message) msg = data.message;
      }
      this.makeToast("danger", msg, "Error");
    },
    Remove_Promotion(id) {
      this.$swal({ title: this.$t("Delete_Title"), text: this.$t("Delete_Text"), type: "warning", showCancelButton: true, confirmButtonColor: "var(--px-primary)", cancelButtonColor: "#d33", cancelButtonText: this.$t("Delete_cancelButtonText"), confirmButtonText: this.$t("Delete_confirmButtonText") }).then(result => {
        if (!(result && (result.value || result.isConfirmed))) return;
        axios.delete("promotions/" + id).then(() => { this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success"); this.Get_Promotions(this.serverParams.page); }).catch(() => this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning"));
      });
    }
  },
  created() { this.Get_Promotions(1); }
};
</script>