<template>
  <div class="px-next pxpru">
    <px-page-header
      :title="$t('PromotionUsageReport') || 'Informe de uso de promociones'"
      :breadcrumbs="[{ label: $t('Sales') }, { label: 'Promociones' }, { label: 'Informe de uso' }]"
    >
      <template #actions>
        <px-button variant="ghost" icon="arrow-left" @click="$router.push('/app/promotions')">{{ $t('BackToPromotions') || 'Volver a promociones' }}</px-button>
      </template>
    </px-page-header>

    <div v-if="isLoading" class="pxpru__pad">
      <px-skeleton variant="lines" :rows="4" />
      <px-skeleton variant="table" :rows="8" :columns="6" />
    </div>

    <template v-else>
      <px-card class="pxpru__filters">
        <div class="pxpru__filters-grid">
          <px-field :label="$t('From') || 'Desde'">
            <template #default="{ id }"><px-input :id="id" type="date" v-model="filters.from" @input="reload" /></template>
          </px-field>
          <px-field :label="$t('To') || 'Hasta'">
            <template #default="{ id }"><px-input :id="id" type="date" v-model="filters.to" @input="reload" /></template>
          </px-field>
          <px-field :label="$t('Promotion') || 'Promoción'">
            <template #default="{ id }">
              <vs-px
                :input-id="id"
                v-model="filters.promotion_id"
                :reduce="o => o.value"
                :placeholder="$t('All') || 'Todas'"
                :options="promotionsList.map(p => ({ label: p.code ? (p.name + ' (' + p.code + ')') : p.name, value: p.id }))"
                @input="reload"
              />
            </template>
          </px-field>
          <px-field :label="$t('Warehouse') || 'Almacén'">
            <template #default="{ id }">
              <vs-px
                :input-id="id"
                v-model="filters.warehouse_id"
                :reduce="o => o.value"
                :placeholder="$t('All') || 'Todos'"
                :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
                @input="reload"
              />
            </template>
          </px-field>
        </div>
        <div class="pxpru__filters-act">
          <px-button size="sm" variant="ghost" icon="x" @click="resetFilters">{{ $t('Reset') || 'Limpiar' }}</px-button>
        </div>
      </px-card>

      <div class="pxpru__kpis">
        <px-stat bordered :label="$t('TotalUses') || 'Usos totales'" :value="String(totals.uses || 0)" icon="repeat" />
        <px-stat bordered :label="$t('TotalDiscount') || 'Descuento total'" :value="formatMoney(totals.total_discount)" icon="badge-percent" />
        <px-stat bordered :label="$t('PromotionsUsed') || 'Promociones usadas'" :value="String(totals.promotions_with_usage || 0)" icon="tag" />
      </div>

      <px-card :title="$t('Summary') || 'Resumen'" flush class="pxpru__sec">
        <div class="pxpru-tbl__wrap pxn-scroll" v-if="summary.length">
          <table class="pxpru-tbl">
            <thead>
              <tr>
                <th>{{ $t('Promotion') || 'Promoción' }}</th>
                <th>{{ $t('Code') || 'Código' }}</th>
                <th>{{ $t('Type') || 'Tipo' }}</th>
                <th class="is-right">{{ $t('Uses') || 'Usos' }}</th>
                <th class="is-right">{{ $t('UniqueCustomers') || 'Clientes únicos' }}</th>
                <th class="is-right">{{ $t('TotalDiscount') || 'Descuento total' }}</th>
                <th class="is-right">{{ $t('CapProgress') || 'Progreso de tope' }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in summary" :key="row.promotion_id">
                <td>{{ row.name }}</td>
                <td><span v-if="row.code" class="pxn-mono">{{ row.code }}</span><span v-else class="pxpru__muted">—</span></td>
                <td><px-badge :tone="row.kind === 'discount' ? 'info' : 'success'">{{ row.kind }}</px-badge></td>
                <td class="is-right pxn-num">{{ row.uses }}</td>
                <td class="is-right pxn-num">{{ row.unique_customers }}</td>
                <td class="is-right pxn-num">{{ formatMoney(row.total_discount) }}</td>
                <td class="is-right">
                  <template v-if="row.usage_limit_total !== null">
                    <span class="pxn-num">{{ row.uses }} / {{ row.usage_limit_total }}</span>
                    <div class="pxpru__cap">
                      <div
                        class="pxpru__cap-bar"
                        :class="{ 'is-full': row.uses >= row.usage_limit_total }"
                        :style="{ width: Math.min(100, (row.uses / row.usage_limit_total) * 100) + '%' }"
                      ></div>
                    </div>
                  </template>
                  <span v-else class="pxpru__muted">{{ $t('Unlimited') || 'Ilimitado' }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="pxpru__empty">{{ $t('NoUsageYet') || 'Sin usos registrados en este período.' }}</div>
      </px-card>

      <px-card :title="$t('Details')" flush class="pxpru__sec">
        <px-toolbar
          :search="search"
          :search-placeholder="$t('Search_this_table')"
          @update:search="onSearchInput"
        />
        <div class="pxpru__tablewrap">
          <px-table
            v-if="usages.length"
            :columns="columns"
            :rows="usages"
            row-key="id"
            :sort-key="serverParams.sort.field"
            :sort-dir="serverParams.sort.type"
            @sort="onSort"
          >
            <template #cell-used_at="{ row }">{{ row.used_at }}</template>
            <template #cell-promotion="{ row }">
              <div class="pxpru__promo">
                <strong>{{ row.promotion ? row.promotion.name : '—' }}</strong>
                <px-badge v-if="row.promotion && row.promotion.kind" :tone="row.promotion.kind === 'discount' ? 'info' : 'success'">{{ row.promotion.kind }}</px-badge>
              </div>
            </template>
            <template #cell-code="{ row }">
              <span v-if="row.code" class="pxn-mono">{{ row.code }}</span><span v-else class="pxpru__muted">—</span>
            </template>
            <template #cell-sale="{ row }">
              <span v-if="row.sale">{{ row.sale.Ref }}</span><span v-else class="pxpru__muted">—</span>
            </template>
            <template #cell-client="{ row }">
              <span v-if="row.client">{{ row.client.name }}</span><span v-else class="pxpru__muted">{{ $t('Guest') || 'Invitado' }}</span>
            </template>
            <template #cell-warehouse="{ row }">
              <span v-if="row.warehouse && row.warehouse.name">{{ row.warehouse.name }}</span><span v-else class="pxpru__muted">—</span>
            </template>
            <template #cell-discount_amount="{ row }">
              <span class="pxpru__neg pxn-num">−{{ formatMoney(row.discount_amount) }}</span>
            </template>
          </px-table>

          <px-empty-state
            v-else
            icon="bar-chart-3"
            :title="$t('No_promotion_usages_yet')"
            :description="$t('No_promotion_usages_desc')"
          />
        </div>

        <px-pagination
          v-if="usages.length"
          :page="serverParams.page"
          :per-page="Number(limit)"
          :total="Number(totalRows) || 0"
          :per-page-options="['15', '25', '50', '100']"
          @update:page="onPage"
          @update:perPage="onLimit"
        />
      </px-card>
    </template>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxStat from "@/components/px-next/PxStat.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxCard, PxField, PxInput,
    PxBadge, PxStat, PxEmptyState, "vs-px": VsPx
  },
  metaInfo: { title: "Informe de uso de promociones" },

  data() {
    return {
      isLoading: true,
      serverParams: {
        sort: { field: "used_at", type: "desc" },
        page: 1,
        perPage: 15
      },
      totalRows: 0,
      search: "",
      limit: "15",
      _searchTimer: null,
      usages: [],
      summary: [],
      totals: { uses: 0, total_discount: 0, promotions_with_usage: 0 },
      promotionsList: [],
      warehouses: [],
      filters: {
        from: "",
        to: "",
        promotion_id: null,
        warehouse_id: null
      }
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),
    currency() {
      return (this.currentUser && this.currentUser.currency) || "";
    },
    columns() {
      return [
        { key: "used_at", label: this.$t("Date") || "Fecha", sortable: true },
        { key: "promotion", label: this.$t("Promotion") || "Promoción", sortable: false },
        { key: "code", label: this.$t("Code") || "Código", sortable: false },
        { key: "sale", label: this.$t("Sale") || "Venta", sortable: false },
        { key: "client", label: this.$t("Client") || "Cliente", sortable: false },
        { key: "warehouse", label: this.$t("Warehouse") || "Almacén", sortable: false },
        { key: "discount_amount", label: this.$t("Discount") || "Descuento", align: "right" }
      ];
    }
  },

  methods: {
    formatMoney(value) {
      const n = Number(value || 0).toFixed(2);
      return this.currency ? `${this.currency} ${n}` : n;
    },

    buildQuery() {
      const p = new URLSearchParams();
      p.append("page", this.serverParams.page);
      p.append("SortField", this.serverParams.sort.field);
      p.append("SortType", this.serverParams.sort.type);
      p.append("search", this.search || "");
      p.append("limit", this.limit);
      if (this.filters.from) p.append("from", this.filters.from);
      if (this.filters.to) p.append("to", this.filters.to);
      if (this.filters.promotion_id) p.append("promotion_id", this.filters.promotion_id);
      if (this.filters.warehouse_id) p.append("warehouse_id", this.filters.warehouse_id);
      return p.toString();
    },

    buildSummaryQuery() {
      const p = new URLSearchParams();
      if (this.filters.from) p.append("from", this.filters.from);
      if (this.filters.to) p.append("to", this.filters.to);
      if (this.filters.warehouse_id) p.append("warehouse_id", this.filters.warehouse_id);
      return p.toString();
    },

    fetchUsages() {
      NProgress.start();
      return axios
        .get("promotions/usages?" + this.buildQuery())
        .then(response => {
          this.usages = (response.data && response.data.usages) || [];
          this.totalRows = (response.data && response.data.totalRows) || 0;
        })
        .catch(() => {})
        .finally(() => NProgress.done());
    },

    fetchSummary() {
      return axios
        .get("promotions/usages_summary?" + this.buildSummaryQuery())
        .then(response => {
          const data = (response && response.data) || {};
          this.summary = data.summary || [];
          this.totals = data.totals || { uses: 0, total_discount: 0, promotions_with_usage: 0 };
        })
        .catch(() => {});
    },

    fetchPromotionsList() {
      return axios
        .get("promotions?page=1&limit=-1&SortField=name&SortType=asc")
        .then(response => {
          this.promotionsList = (response.data && response.data.promotions) || [];
        })
        .catch(() => {});
    },

    fetchWarehouses() {
      return axios
        .get("warehouses?page=1&limit=-1&SortField=id&SortType=asc")
        .then(response => {
          this.warehouses = (response.data && response.data.warehouses) || [];
        })
        .catch(() => {});
    },

    reload() {
      this.serverParams.page = 1;
      return Promise.all([this.fetchUsages(), this.fetchSummary()]);
    },

    resetFilters() {
      this.filters = { from: "", to: "", promotion_id: null, warehouse_id: null };
      this.search = "";
      this.reload();
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.serverParams.page = 1; this.fetchUsages(); }, 350);
    },
    onPage(p) {
      if (this.serverParams.page !== p) {
        this.serverParams.page = p;
        this.fetchUsages();
      }
    },
    onLimit(v) {
      if (this.limit !== String(v)) {
        this.limit = String(v);
        this.serverParams.page = 1;
        this.fetchUsages();
      }
    },
    onSort({ key, dir }) {
      this.serverParams.sort = { type: dir, field: key };
      this.fetchUsages();
    }
  },

  created() {
    this.fetchPromotionsList();
    this.fetchWarehouses();
    Promise.all([this.fetchUsages(), this.fetchSummary()]).finally(() => {
      this.isLoading = false;
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxpru { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxpru { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxpru__pad { padding: var(--pxn-space-6) 0; display: flex; flex-direction: column; gap: var(--pxn-space-6); }

.pxpru__filters { margin-bottom: var(--pxn-space-6); }
.pxpru__filters-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxpru__filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxpru__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxpru__filters-act { display: flex; justify-content: flex-end; margin-top: var(--pxn-space-4); }

.pxpru__kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); margin-bottom: var(--pxn-space-6); }
@media (max-width: 720px) { .pxpru__kpis { grid-template-columns: minmax(0, 1fr); } }

.pxpru__sec { margin-bottom: var(--pxn-space-6); }
.pxpru__sec ::v-deep .pxn-card__body { padding: 0; }
.pxpru__tablewrap { padding: var(--pxn-space-4) var(--pxn-space-5) var(--pxn-space-5); }

.pxpru-tbl__wrap { overflow-x: auto; }
.pxpru-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxpru-tbl th {
  text-align: left; padding: var(--pxn-space-3) var(--pxn-space-5);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
  text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3);
  background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap;
}
.pxpru-tbl td { padding: var(--pxn-space-3) var(--pxn-space-5); border-bottom: 1px solid var(--pxn-border); }
.pxpru-tbl tr:last-child td { border-bottom: 0; }
.pxpru-tbl .is-right { text-align: right; }

.pxpru__cap { height: 4px; margin-top: 4px; border-radius: var(--pxn-radius-pill); background: var(--pxn-surface-3); overflow: hidden; }
.pxpru__cap-bar { height: 100%; background: var(--pxn-success); }
.pxpru__cap-bar.is-full { background: var(--pxn-danger); }

.pxpru__empty { padding: var(--pxn-space-8); text-align: center; color: var(--pxn-ink-3); font-size: var(--pxn-fs-sm); }
.pxpru__muted { color: var(--pxn-ink-3); }
.pxpru__neg { color: var(--pxn-danger-ink); }
.pxpru__promo { display: flex; align-items: center; gap: var(--pxn-space-3); }
</style>
