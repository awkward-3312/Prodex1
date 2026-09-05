<template>
  <div class="px-next pxpromo">
    <px-page-header title="Promociones" :breadcrumbs="[{ label: $t('Sales') }, { label: 'Promociones' }]">
      <template #actions>
        <px-button variant="secondary" icon="bar-chart-3" @click="$router.push('/app/promotions/usages')">Informe de uso</px-button>
        <px-button variant="primary" icon="plus" @click="New_Promotion">Agregar</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxpromo__pad">
      <px-skeleton variant="table" :rows="8" :columns="6" />
    </div>

    <template v-else>
      <div class="pxpromo__tablewrap">
        <px-table
          v-if="promotions.length"
          :columns="columns"
          :rows="promotions"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-kind="{ row }">
            <px-badge :tone="row.kind === 'discount' ? 'info' : 'success'">{{ promotionTypeLabel(row.kind) }}</px-badge>
          </template>
          <template #cell-value="{ row }">
            <span class="pxn-num">
              <template v-if="row.discount_type === 'percentage'">{{ row.discount_value }}%</template>
              <template v-else>{{ row.discount_value }}</template>
            </span>
          </template>
          <template #cell-warehouses="{ row }">
            <span v-if="row.warehouses && row.warehouses.length" class="pxpromo__whs">
              <px-badge v-for="w in row.warehouses" :key="w.id" tone="neutral">{{ w.name }}</px-badge>
            </span>
            <span v-else class="pxpromo__muted">—</span>
          </template>
          <template #cell-window="{ row }">
            <span class="pxpromo__muted">{{ formatDate(row.starts_at) }} → {{ formatDate(row.ends_at) }}</span>
          </template>
          <template #cell-is_active="{ row }">
            <px-check type="switch" :model-value="!!row.is_active" @change="Toggle_Promotion(row)" />
          </template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state
          v-else
          icon="tag"
          :title="$t('No_promotions_yet')"
          :description="$t('No_promotions_desc')"
        >
          <px-button size="sm" variant="primary" icon="plus" @click="New_Promotion">Agregar</px-button>
        </px-empty-state>
      </div>

      <px-pagination
        v-if="promotions.length"
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
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab, PxBadge, PxCheck, PxEmptyState
  },
  metaInfo: { title: "Promociones" },
  data() {
    return {
      isLoading: true,
      serverParams: { sort: { field: "id", type: "desc" }, page: 1, perPage: 10 },
      totalRows: "",
      search: "",
      limit: "10",
      _searchTimer: null,
      promotions: []
    };
  },
  computed: {
    columns() {
      return [
        { key: "name", label: "Nombre", sortable: true, strong: true },
        { key: "kind", label: "Tipo", sortable: true },
        { key: "value", label: "Valor", sortable: false, align: "right" },
        { key: "warehouses", label: "Almacenes", sortable: false },
        { key: "window", label: "Vigencia", sortable: false },
        { key: "priority", label: "Prioridad", sortable: true, align: "right" },
        { key: "is_active", label: "Activa", sortable: false }
      ];
    },
    rowActions() {
      return [
        { key: "edit", label: this.$t("Edit"), icon: "pencil" },
        { key: "delete", label: this.$t("Delete"), icon: "x", tone: "danger" }
      ];
    }
  },
  methods: {
    promotionTypeLabel(kind) {
      const labels = { discount: "Descuento", coupon: "Cupón", promotion: "Promoción", offer: "Oferta" };
      return labels[String(kind || "").toLowerCase()] || kind || "—";
    },
    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Promotions(1); }, 350);
    },
    onSort({ key, dir }) {
      this.updateParams({ sort: { field: key, type: dir } });
      this.Get_Promotions(this.serverParams.page);
    },
    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Promotions(p); } },
    onLimit(v) {
      if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Promotions(1); }
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "edit") this.Edit_Promotion(row);
      else if (k === "delete") this.Remove_Promotion(row.id);
    },
    makeToast(variant, msg, title) { this.$root.$bvToast.toast(msg, { title, variant, solid: true }); },
    formatDate(value) {
      if (!value) return "—";
      try { return value.replace("T", " ").substring(0, 16); } catch (e) { return value; }
    },
    Get_Promotions(page) {
      NProgress.start(); NProgress.set(0.1);
      axios.get("promotions?page=" + page + "&SortField=" + this.serverParams.sort.field + "&SortType=" + this.serverParams.sort.type + "&search=" + (this.search || "") + "&limit=" + this.limit)
        .then(response => { this.promotions = response.data.promotions || []; this.totalRows = response.data.totalRows || 0; NProgress.done(); this.isLoading = false; })
        .catch(() => { NProgress.done(); this.isLoading = false; });
    },
    New_Promotion() { this.$router.push("/app/promotions/create"); },
    Edit_Promotion(promo) { this.$router.push("/app/promotions/edit/" + promo.id); },
    Toggle_Promotion(row) {
      axios.post("promotions/" + row.id + "/toggle").then(() => this.Get_Promotions(this.serverParams.page)).catch(error => this.toastApiError(error));
    },
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

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxpromo { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxpromo { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxpromo__pad { padding: var(--pxn-space-6) 0; }
.pxpromo__tablewrap { margin-top: var(--pxn-space-5); }
.pxpromo__whs { display: inline-flex; flex-wrap: wrap; gap: var(--pxn-space-2); }
.pxpromo__muted { color: var(--pxn-ink-3); font-size: var(--pxn-fs-sm); }
</style>
