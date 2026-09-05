<template>
  <div class="px-next pxrl">
    <px-page-header :title="$t('Expiry_Report')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('Expiry_Report') }]">
      <template #actions>
        <px-button variant="secondary" size="sm" icon="printer" @click="printTableOnly()">{{ $t('print') }}</px-button>
      </template>
    </px-page-header>

    <div v-if="isLoading" class="pxrl__pad">
      <px-skeleton variant="table" :rows="10" :columns="7" />
    </div>

    <template v-else>
      <div class="pxrl__kpis">
        <px-stat bordered icon="alert-circle" :label="$t('Expired')" :value="String(kpis.expired)"
          :sub="kpis.expired_value ? `${$t('Value')}: ${formatNumber(kpis.expired_value, priceDecimals)}` : null" />
        <px-stat bordered icon="calendar-clock" :label="$t('Near_Expiry')" :value="String(kpis.near)"
          :sub="kpis.near_value ? `${$t('Value')}: ${formatNumber(kpis.near_value, priceDecimals)}` : null" />
        <px-stat bordered icon="check-circle" :label="$t('Valid')" :value="String(kpis.valid)" />
        <px-stat bordered icon="bell" :label="$t('Expiry_Warning_Days')" :value="String(expiryWarningDays)" />
      </div>

      <px-toolbar
        :search="search"
        :search-placeholder="$t('Search_this_table')"
        :filter-count="activeFilterCount"
        @update:search="onSearchInput"
        @open-filters="filtersOpen = !filtersOpen"
      />

      <div v-if="filtersOpen" class="pxrl__filters">
        <div class="pxrl__filters-grid">
          <px-field :label="$t('Warehouse')">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="filters.warehouse_id" :reduce="o => o.value" :placeholder="$t('All')"
                :options="warehouses.map(w => ({ label: w.name, value: w.id }))" @input="onFilterChange" />
            </template>
          </px-field>
          <px-field :label="$t('Expiry_Window')">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="filters.expiry_window" :reduce="o => o.value" :clearable="false"
                :options="windowOptions" @input="onFilterChange" />
            </template>
          </px-field>
        </div>
      </div>

      <div class="pxrl__tablewrap">
        <px-table
          v-if="rows.length"
          :columns="columns"
          :rows="rows"
          row-key="__rowkey"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          @sort="onSort"
        >
          <template #cell-product="{ row }">
            <div>
              <strong>{{ row.product_name }}</strong>
              <small v-if="row.product_code" class="pxrl__muted"> [{{ row.product_code }}]</small>
            </div>
            <small v-if="row.generic_name" class="pxrl__muted">
              {{ row.generic_name }}
              <span v-if="row.strength"> · {{ row.strength }}</span>
              <span v-if="row.dosage_form"> · {{ row.dosage_form }}</span>
            </small>
          </template>
          <template #cell-expiry_date="{ row }">
            <span v-if="row.expiry_date">
              {{ row.expiry_date }}<br>
              <small :class="{
                'pxrl__neg': row.expiry_bucket === 'expired',
                'pxrl__warn': row.expiry_bucket === 'near',
                'pxrl__pos': row.expiry_bucket === 'valid',
              }">
                <span v-if="row.expiry_bucket === 'expired'">{{ $t('Expired') }} ({{ Math.abs(row.days_to_expiry) }}d)</span>
                <span v-else-if="row.expiry_bucket === 'near'">{{ $t('Expires_in') }} {{ row.days_to_expiry }}d</span>
                <span v-else>{{ row.days_to_expiry }}d</span>
              </small>
            </span>
            <span v-else class="pxrl__muted">—</span>
          </template>
          <template #cell-qty="{ row }"><span class="pxn-num">{{ formatNumber(row.qty) }}</span></template>
          <template #cell-value="{ row }">
            <span v-if="row.value !== null" class="pxn-num">{{ formatNumber(row.value, priceDecimals) }}</span>
            <span v-else class="pxrl__muted">—</span>
          </template>
          <template #cell-status="{ row }">
            <px-badge :tone="statusTone(row.status)">{{ $t('Batch_Status_' + row.status) }}</px-badge>
          </template>
        </px-table>

        <px-empty-state v-else icon="calendar-clock" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
      </div>

      <px-pagination
        v-if="rows.length"
        :page="serverParams.page"
        :per-page="Number(serverParams.perPage)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>
  </div>
</template>

<script>
import NProgress from 'nprogress';
import { getPriceDecimals } from "../../../../utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxStat from "@/components/px-next/PxStat.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: 'Expiry Report' },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxStat, PxBadge,
    PxField, PxEmptyState, "vs-px": VsPx
  },

  data() {
    return {
      _searchTimer: null,
      filtersOpen: false,
      isLoading: true,
      serverParams: {
        sort: { field: 'expiry_date', type: 'asc' },
        page: 1,
        perPage: 10
      },
      rows: [],
      totalRows: 0,
      search: '',
      limit: 10,
      warehouses: [],
      expiryWarningDays: 90,
      kpis: { expired: 0, near: 0, valid: 0, expired_value: 0, near_value: 0 },
      filters: {
        warehouse_id: '',
        expiry_window: 'expired_or_near'
      }
    };
  },

  computed: {
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    activeFilterCount() {
      return [this.filters.warehouse_id, this.filters.expiry_window !== 'expired_or_near' ? this.filters.expiry_window : '']
        .filter(v => v !== '' && v !== null && v !== undefined).length;
    },
    windowOptions() {
      return [
        { label: `${this.$t('Expired')} + ${this.$t('Near_Expiry')}`, value: 'expired_or_near' },
        { label: this.$t('Expired'), value: 'expired' },
        { label: this.$t('Near_Expiry'), value: 'near' },
        { label: this.$t('Valid'), value: 'valid' },
        { label: this.$t('All'), value: 'all' }
      ];
    },
    columns() {
      return [
        { key: 'product', label: this.$t('Product') },
        { key: 'batch_no', label: this.$t('Batch_No') },
        { key: 'warehouse_name', label: this.$t('Warehouse') },
        { key: 'expiry_date', label: this.$t('Expiry_Date'), sortable: true },
        { key: 'qty', label: this.$t('Quantity'), align: 'right', sortable: true },
        { key: 'value', label: this.$t('Value'), align: 'right' },
        { key: 'status', label: this.$t('Status') }
      ];
    }
  },

  methods: {
    formatNumber(v, dec) {
      if (v === null || v === undefined || v === '') return '';
      const n = Number(v);
      if (Number.isNaN(n)) return v;
      if (dec !== undefined) return n.toFixed(dec);
      return Number.isInteger(n) ? n.toString() : n.toFixed(2);
    },

    statusTone(status) {
      switch (status) {
        case 'active': return 'success';
        case 'quarantined': return 'warning';
        case 'expired': return 'danger';
        case 'written_off': return 'neutral';
        default: return 'neutral';
      }
    },

    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.fetch(1); }, 350);
    },
    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.fetch(p); } },
    onLimit(v) { this.limit = Number(v); this.updateParams({ perPage: Number(v), page: 1 }); this.fetch(1); },
    onSort({ key, dir }) { this.updateParams({ sort: { type: dir, field: key } }); this.fetch(this.serverParams.page); },
    onFilterChange() {
      this.updateParams({ page: 1 });
      this.fetch(1);
    },

    fetch(page) {
      NProgress.start();
      NProgress.set(0.1);
      const params = {
        page: page || 1,
        limit: this.limit,
        SortField: this.serverParams.sort.field,
        SortType: this.serverParams.sort.type,
        search: this.search || '',
        expiry_window: this.filters.expiry_window
      };
      if (this.filters.warehouse_id !== '' && this.filters.warehouse_id != null) {
        params.warehouse_id = this.filters.warehouse_id;
      }
      axios
        .get('report/expiry', { params })
        .then(response => {
          this.rows = (response.data.batches || []).map((b, i) => Object.assign({ __rowkey: `${b.batch_no || 'b'}-${b.product_code || ''}-${i}` }, b));
          this.totalRows = response.data.totalRows || 0;
          this.warehouses = response.data.warehouses || [];
          this.kpis = response.data.kpis || this.kpis;
          if (response.data.expiry_warning_days) {
            this.expiryWarningDays = response.data.expiry_warning_days;
          }
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    printTableOnly() {
      const title = `${this.$t('Reports')} / ${this.$t('Expiry_Report')}`;
      const items = this.rows || [];

      let tableHTML = '<table style="width:100%; border-collapse:collapse; font-size:11px;">';
      tableHTML += '<thead><tr>';
      this.columns.forEach(col => {
        tableHTML += `<th style="border:1px solid #ddd; padding:6px; background:#f5f5f5; text-align:left;">${col.label}</th>`;
      });
      tableHTML += '</tr></thead><tbody>';
      items.forEach(r => {
        tableHTML += '<tr>';
        tableHTML += `<td style="border:1px solid #ddd; padding:6px;">${r.product_name || ''}${r.product_code ? ' ['+r.product_code+']' : ''}</td>`;
        tableHTML += `<td style="border:1px solid #ddd; padding:6px;">${r.batch_no || ''}</td>`;
        tableHTML += `<td style="border:1px solid #ddd; padding:6px;">${r.warehouse_name || ''}</td>`;
        tableHTML += `<td style="border:1px solid #ddd; padding:6px;">${r.expiry_date || '—'}</td>`;
        tableHTML += `<td style="border:1px solid #ddd; padding:6px; text-align:right;">${this.formatNumber(r.qty)}</td>`;
        tableHTML += `<td style="border:1px solid #ddd; padding:6px; text-align:right;">${r.value !== null ? this.formatNumber(r.value, this.priceDecimals) : '—'}</td>`;
        tableHTML += `<td style="border:1px solid #ddd; padding:6px;">${this.$t('Batch_Status_' + r.status)}</td>`;
        tableHTML += '</tr>';
      });
      tableHTML += '</tbody></table>';

      const w = window.open('', '_blank');
      if (!w) { alert('Please allow popups to print'); return; }
      const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
        .map(l => l.outerHTML).join('\n');
      const doc = w.document;
      doc.open();
      doc.write(`<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <base href="${window.location.origin}/" />
    <title>${title}</title>
    ${links}
    <style>
      @media print { body, body * { visibility: visible !important; } @page { size: A4 landscape; margin: 0.3cm; } }
      body { margin: 0.3cm; font-family: Arial, sans-serif; }
      .print-header { font-weight: 600; margin-bottom: 10px; font-size: 14px; }
    </style>
  </head>
  <body>
    <div class="print-header">${title}</div>
    ${tableHTML}
  </body>
</html>`);
      doc.close();
      w.focus();
      setTimeout(() => { w.print(); w.close(); }, 400);
    }
  },

  created() {
    this.fetch(1);
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrl__pad { padding: var(--pxn-space-6) 0; }
.pxrl__kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-5); margin-bottom: var(--pxn-space-5); }
@media (max-width: 900px) { .pxrl__kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 480px) { .pxrl__kpis { grid-template-columns: minmax(0, 1fr); } }
.pxrl__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxrl__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 560px) { .pxrl__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxrl__tablewrap { margin-top: var(--pxn-space-5); }
.pxrl__muted { color: var(--pxn-ink-3); }
.pxrl__neg { color: var(--pxn-danger); }
.pxrl__warn { color: var(--pxn-warning); }
.pxrl__pos { color: var(--pxn-success); }
</style>
