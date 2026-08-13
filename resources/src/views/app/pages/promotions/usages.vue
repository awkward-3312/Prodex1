<template>
  <div class="main-content">
    <breadcumb :page="$t('PromotionUsageReport') || 'Promotion Usage Report'" :folder="$t('Promotions') || 'Promotions'" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-card class="wrapper mb-3" v-if="!isLoading">
      <b-row align-v="end">
        <b-col md="3">
          <b-form-group :label="$t('From') || 'From'">
            <b-form-input type="date" v-model="filters.from" @change="reload()"></b-form-input>
          </b-form-group>
        </b-col>
        <b-col md="3">
          <b-form-group :label="$t('To') || 'To'">
            <b-form-input type="date" v-model="filters.to" @change="reload()"></b-form-input>
          </b-form-group>
        </b-col>
        <b-col md="3">
          <b-form-group :label="$t('Promotion') || 'Promotion'">
            <b-form-select v-model="filters.promotion_id" @change="reload()">
              <template #first>
                <b-form-select-option :value="null">{{ $t('All') || 'All' }}</b-form-select-option>
              </template>
              <option v-for="p in promotionsList" :key="p.id" :value="p.id">
                {{ p.name }}<span v-if="p.code"> ({{ p.code }})</span>
              </option>
            </b-form-select>
          </b-form-group>
        </b-col>
        <b-col md="3">
          <b-form-group :label="$t('Warehouse') || 'Warehouse'">
            <b-form-select v-model="filters.warehouse_id" @change="reload()">
              <template #first>
                <b-form-select-option :value="null">{{ $t('All') || 'All' }}</b-form-select-option>
              </template>
              <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
            </b-form-select>
          </b-form-group>
        </b-col>

        <b-col md="12" class="text-right mb-2">
          <b-button variant="outline-secondary" size="sm" @click="resetFilters">
            {{ $t('Reset') || 'Reset' }}
          </b-button>
          <router-link tag="b-button" to="/app/promotions" class="ml-2 btn btn-outline-primary btn-sm">
            <lucide-icon class="me-1" name="arrow-left" /> {{ $t('BackToPromotions') || 'Back to Promotions' }}
          </router-link>
        </b-col>
      </b-row>
    </b-card>

    <b-card class="wrapper mb-3" v-if="!isLoading">
      <h5 class="mb-3">{{ $t('Summary') || 'Summary' }}</h5>
      <b-row class="mb-3">
        <b-col md="4">
          <div class="kpi-card">
            <div class="kpi-label">{{ $t('TotalUses') || 'Total uses' }}</div>
            <div class="kpi-value">{{ totals.uses || 0 }}</div>
          </div>
        </b-col>
        <b-col md="4">
          <div class="kpi-card">
            <div class="kpi-label">{{ $t('TotalDiscount') || 'Total discount' }}</div>
            <div class="kpi-value">{{ formatMoney(totals.total_discount) }}</div>
          </div>
        </b-col>
        <b-col md="4">
          <div class="kpi-card">
            <div class="kpi-label">{{ $t('PromotionsUsed') || 'Promotions used' }}</div>
            <div class="kpi-value">{{ totals.promotions_with_usage || 0 }}</div>
          </div>
        </b-col>
      </b-row>

      <b-table-simple small responsive hover v-if="summary.length">
        <b-thead>
          <b-tr>
            <b-th>{{ $t('Promotion') || 'Promotion' }}</b-th>
            <b-th>{{ $t('Code') || 'Code' }}</b-th>
            <b-th>{{ $t('Type') || 'Type' }}</b-th>
            <b-th class="text-right">{{ $t('Uses') || 'Uses' }}</b-th>
            <b-th class="text-right">{{ $t('UniqueCustomers') || 'Unique customers' }}</b-th>
            <b-th class="text-right">{{ $t('TotalDiscount') || 'Total discount' }}</b-th>
            <b-th class="text-right">{{ $t('CapProgress') || 'Cap progress' }}</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="row in summary" :key="row.promotion_id">
            <b-td>{{ row.name }}</b-td>
            <b-td><code v-if="row.code">{{ row.code }}</code><span v-else class="text-muted">—</span></b-td>
            <b-td>
              <b-badge :variant="row.kind === 'discount' ? 'info' : 'success'">{{ row.kind }}</b-badge>
            </b-td>
            <b-td class="text-right">{{ row.uses }}</b-td>
            <b-td class="text-right">{{ row.unique_customers }}</b-td>
            <b-td class="text-right">{{ formatMoney(row.total_discount) }}</b-td>
            <b-td class="text-right">
              <template v-if="row.usage_limit_total !== null">
                {{ row.uses }} / {{ row.usage_limit_total }}
                <b-progress :max="row.usage_limit_total" :value="row.uses" :variant="row.uses >= row.usage_limit_total ? 'danger' : 'success'" height="4px" class="mt-1"></b-progress>
              </template>
              <template v-else>
                <span class="text-muted">{{ $t('Unlimited') || 'Unlimited' }}</span>
              </template>
            </b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>
      <div v-else class="text-muted text-center py-3">
        {{ $t('NoUsageYet') || 'No usage recorded in this window.' }}
      </div>
    </b-card>

    <b-card class="wrapper" v-if="!isLoading">
      <h5 class="mb-3">{{ $t('Details') || 'Details' }}</h5>
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="usages"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-sort-change="onSortChange"
        @on-search="onSearch"
        :search-options="{ enabled: true, placeholder: $t('Search_this_table') }"
        :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
        styleClass="table-hover tableOne vgt-table"
      >
        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field === 'promotion'">
            <strong>{{ props.row.promotion ? props.row.promotion.name : '—' }}</strong>
            <small v-if="props.row.promotion && props.row.promotion.kind" class="text-muted d-block">
              <b-badge :variant="props.row.promotion.kind === 'discount' ? 'info' : 'success'">{{ props.row.promotion.kind }}</b-badge>
            </small>
          </span>
          <span v-else-if="props.column.field === 'code'">
            <code v-if="props.row.code">{{ props.row.code }}</code>
            <span v-else class="text-muted">—</span>
          </span>
          <span v-else-if="props.column.field === 'sale'">
            <span v-if="props.row.sale">{{ props.row.sale.Ref }}</span>
            <span v-else class="text-muted">—</span>
          </span>
          <span v-else-if="props.column.field === 'client'">
            <span v-if="props.row.client">{{ props.row.client.name }}</span>
            <span v-else class="text-muted">{{ $t('Guest') || 'Guest' }}</span>
          </span>
          <span v-else-if="props.column.field === 'warehouse'">
            <span v-if="props.row.warehouse && props.row.warehouse.name">{{ props.row.warehouse.name }}</span>
            <span v-else class="text-muted">—</span>
          </span>
          <span v-else-if="props.column.field === 'discount_amount'" class="text-danger">
            −{{ formatMoney(props.row.discount_amount) }}
          </span>
        </template>
      </vue-good-table>
    </b-card>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";

export default {
  metaInfo: { title: "Promotion Usage Report" },

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
        { label: this.$t("Date") || "Date", field: "used_at", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Promotion") || "Promotion", field: "promotion", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Code") || "Code", field: "code", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Sale") || "Sale", field: "sale", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Client") || "Client", field: "client", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Warehouse") || "Warehouse", field: "warehouse", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Discount") || "Discount", field: "discount_amount", tdClass: "text-right", thClass: "text-right" }
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

    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) {
        this.serverParams.page = currentPage;
        this.fetchUsages();
      }
    },
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.serverParams.page = 1;
        this.fetchUsages();
      }
    },
    onSortChange(params) {
      this.serverParams.sort = { type: params[0].type, field: params[0].field };
      this.fetchUsages();
    },
    onSearch(value) {
      this.search = value.searchTerm;
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

<style scoped>
.kpi-card {
  padding: 14px 16px;
  background: #f7f7fb;
  border-radius: 8px;
  border: 1px solid #ececf2;
}
.kpi-label {
  font-size: 11px;
  letter-spacing: 0.08em;
  color: #8d8da0;
  text-transform: uppercase;
  margin-bottom: 4px;
}
.kpi-value {
  font-size: 22px;
  font-weight: 700;
  color: #1f1f2c;
  font-family: 'JetBrains Mono', monospace;
}
</style>
