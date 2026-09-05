<template>
  <div class="px-next pxrp">
    <px-page-header :title="$t('ProfitandLoss')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('ProfitandLoss') }]">
      <template #actions>
        <px-button variant="secondary" icon="printer" @click="printTableOnly()">{{ $t('print') }}</px-button>
        <px-button variant="primary" icon="refresh-cw" @click="fetchPnl">{{ $t('Refresh') }}</px-button>
      </template>
    </px-page-header>

    <px-card class="pxrp__filters">
      <div class="pxrp__filterrow">
        <div class="pxrp__field">
          <label class="pxrp__label">{{ $t('DateRange') }}</label>
          <date-range-picker
            v-model="dateRange"
            :startDate="dateRange.startDate"
            :endDate="dateRange.endDate"
            :locale-data="locale"
            :autoApply="true"
            :showDropdowns="true"
            :opens="picker.opens"
            :drops="picker.drops"
            :parentEl="'body'"
            @update="onDateChange"
          >
            <template v-slot:input="pickerSlot">
              <button type="button" class="pxrp__daterange pxn-ring">
                <lucide-icon name="calendar-days" :size="14" />
                {{ fmtDate(pickerSlot.startDate) }} — {{ fmtDate(pickerSlot.endDate) }}
              </button>
            </template>
          </date-range-picker>
        </div>

        <div class="pxrp__field">
          <label class="pxrp__label">{{ $t('QuickRanges') }}</label>
          <div class="pxrp__quick">
            <px-button size="sm" variant="subtle" @click="applyQuick('today')">{{ $t('Today') || 'Today' }}</px-button>
            <px-button size="sm" variant="subtle" @click="applyQuick('yesterday')">{{ $t('Yesterday') || 'Yesterday' }}</px-button>
            <px-button size="sm" variant="subtle" @click="applyQuick('7d')">7D</px-button>
            <px-button size="sm" variant="subtle" @click="applyQuick('30d')">30D</px-button>
            <px-button size="sm" variant="subtle" @click="applyQuick('90d')">90D</px-button>
            <px-button size="sm" variant="subtle" @click="applyQuick('mtd')">{{ $t('MTD') }}</px-button>
            <px-button size="sm" variant="subtle" @click="applyQuick('ytd')">{{ $t('YTD') }}</px-button>
          </div>
        </div>

        <div class="pxrp__field pxrp__field--wh">
          <label class="pxrp__label">{{ $t('warehouse') }}</label>
          <vs-px
            v-model="warehouse_id"
            :reduce="opt => opt.value"
            :placeholder="$t('Choose_Warehouse')"
            :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
            @input="onWarehouseChange"
          />
        </div>
      </div>
    </px-card>

    <px-alert tone="info" icon="clock" class="pxrp__range">
      <strong>{{ fmtDate(dateRange.startDate) }}</strong> — <strong>{{ fmtDate(dateRange.endDate) }}</strong>
      <span v-if="warehouseLabel" class="pxrp__whtag">{{ warehouseLabel }}</span>
    </px-alert>

    <div v-if="isLoading" class="pxrp__pad">
      <px-skeleton variant="lines" :rows="8" />
    </div>

    <template v-else>
      <div class="pxrp__grid">
        <px-stat bordered icon="banknote" :label="$t('Sales')" :sub="`(${num(infos.sales_count)})`" :value="money(infos.sales_sum)" />
        <px-stat bordered icon="shopping-cart" :label="$t('Purchases')" :sub="`(${num(infos.purchases_count)})`" :value="money(infos.purchases_sum)" />
        <px-stat bordered icon="repeat" :label="$t('SalesReturn')" :sub="`(${num(infos.returns_sales_count)})`" :value="money(infos.returns_sales_sum)" />
        <px-stat bordered icon="undo" :label="$t('PurchasesReturn')" :sub="`(${num(infos.returns_purchases_count)})`" :value="money(infos.returns_purchases_sum)" />

        <px-stat bordered icon="trending-up" :label="$t('Revenue')" :value="money(infos.total_revenue)" :sub="`${$t('Sales')} – ${$t('SalesReturn')}`" />
        <px-stat bordered icon="wallet" :label="$t('PaiementsReceived')" :value="money(infos.payment_received)" :sub="`${$t('PaymentsSales')} + ${$t('PurchasesReturn')}`" />
        <px-stat bordered icon="user-minus" :label="$t('PaiementsSent')" :value="money(infos.payment_sent)" :sub="`${$t('PaymentsPurchases')} + ${$t('SalesReturn')} + ${$t('Expenses')}`" />
        <px-stat bordered icon="receipt" :label="$t('Expenses')" :value="money(infos.expenses_sum)" />
        <px-stat bordered icon="banknote" :label="$t('PaiementsNet')" :value="money(infos.paiement_net)" :sub="`${$t('Recieved')} – ${$t('Sent')}`" />

        <px-stat bordered icon="wrench" :label="`${$t('Service_Jobs')} – ${$t('Revenue')}`" :sub="`(${num(infos.service_jobs_count)})`" :value="money(infos.service_revenue_sum)" />
        <px-stat bordered icon="package" :label="`${$t('Service_Jobs')} – ${$t('Product_Cost')}`" :value="money(infos.service_parts_cost)" />
        <px-stat bordered icon="bar-chart" :label="`${$t('Service_Jobs')} – ${$t('ProfitNet')}`" :value="money(infos.service_profit)" :sub="`${$t('Revenue')} – ${$t('Product_Cost')}`" />

        <px-stat bordered icon="bar-chart" :label="$t('ProfitNet') + ' (FIFO)'" :value="money(infos.profit_fifo)" :sub="`${$t('Sales')} – ${$t('Product_Cost')} – ${$t('Expenses')} + ${$t('Service_Jobs')}`" />
        <px-stat bordered icon="bar-chart" :label="$t('ProfitNet') + ' (' + $t('AverageCost') + ')'" :value="money(infos.profit_average_cost)" :sub="`${$t('Sales')} – ${$t('Product_Cost')} – ${$t('Expenses')} + ${$t('Service_Jobs')}`" />
      </div>
    </template>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";
import DateRangePicker from "vue2-daterange-picker";
import "vue2-daterange-picker/dist/vue2-daterange-picker.css";
import moment from "moment";
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting,
  getPriceDecimals
} from "../../../../utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxStat from "@/components/px-next/PxStat.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: "Profit & Loss" },
  components: {
    "date-range-picker": DateRangePicker,
    PxPageHeader, PxCard, PxStat, PxButton, PxAlert, "vs-px": VsPx
  },
  data() {
    const start = moment().startOf('day').toDate();
    const end   = moment().endOf('day').toDate();
    return {
      warehouses: [],
      warehouse_id: null,
      isLoading: true,
      infos: {},
      price_format_key: null,
      dateRange: { startDate: start, endDate: end }, // default: Today
      picker: { opens: 'right', drops: 'auto' },
      locale: {
        Label: this.$t("Apply") || "Apply",
        cancelLabel: this.$t("Cancel") || "Cancel",
        weekLabel: "W",
        customRangeLabel: this.$t("CustomRange") || "Custom Range",
        daysOfWeek: moment.weekdaysMin(),
        monthNames: moment.monthsShort(),
        firstDay: 1
      },
    };
  },
  computed: {
    ...mapGetters(["currentUser"]),
    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    currency(){ return (this.currentUser && this.currentUser.currency) || "USD"; },
    warehouseLabel() {
      const w = this.warehouses.find(w => w.id === this.warehouse_id);
      return w ? w.name : null;
    },
  },

  mounted() {
    this.updatePickerPlacement();
    window.addEventListener('resize', this.updatePickerPlacement);
  },
  beforeDestroy() {
    window.removeEventListener('resize', this.updatePickerPlacement);
  },
  methods: {
    updatePickerPlacement() {
      const isXs = window.matchMedia('(max-width: 576px)').matches;
      this.picker.opens = isXs ? 'center' : 'right';
      this.picker.drops = 'auto';
    },

    fmtDate(d){ return moment(d).format('YYYY-MM-DD'); },
    num(v){ const n = parseFloat(v || 0); return isNaN(n)?0:n; },
    // Price formatting for display only (does NOT affect calculations or stored values)
    money(v){
      try {
        const n = this.num(v);
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        const effectiveKey = key || null;
        const formatted = formatPriceDisplayHelper(n, this.priceDecimals, effectiveKey);
        return `${this.currency} ${formatted}`;
      } catch(e){
        try {
          return new Intl.NumberFormat(undefined,{style:'currency',currency:this.currency}).format(this.num(v));
        } catch(e2) {
          return `${this.currency} ${this.num(v).toLocaleString()}`;
        }
      }
    },
    shortMoney(v){
      const n = this.num(v);
      return new Intl.NumberFormat(undefined,{ notation:'compact', maximumFractionDigits:1 }).format(n);
    },

    onDateChange(){ this.fetchPnl(); },
    onWarehouseChange(){ this.fetchPnl(); },

    applyQuick(kind){
      const now = moment();
      let start, end;

      if (kind === 'today')     { start = now.clone().startOf('day'); end = now.clone().endOf('day'); }
      if (kind === 'yesterday') { start = now.clone().subtract(1,'day').startOf('day'); end = now.clone().subtract(1,'day').endOf('day'); }
      if (kind === '7d')        { start = now.clone().subtract(6,'days').startOf('day'); end = now.clone().endOf('day'); }
      if (kind === '30d')       { start = now.clone().subtract(29,'days').startOf('day'); end = now.clone().endOf('day'); }
      if (kind === '90d')       { start = now.clone().subtract(89,'days').startOf('day'); end = now.clone().endOf('day'); }
      if (kind === 'mtd')       { start = now.clone().startOf('month'); end = now.clone().endOf('day'); }
      if (kind === 'ytd')       { start = now.clone().startOf('year'); end = now.clone().endOf('day'); }

      this.dateRange = { startDate: start.toDate(), endDate: end.toDate() };
      this.fetchPnl();
    },

    //------ Print Table Only
    printTableOnly() {
      const title = `${this.$t("Reports")} / ${this.$t("ProfitandLoss")}`;
      const dateRangeText = `${this.fmtDate(this.dateRange.startDate)} — ${this.fmtDate(this.dateRange.endDate)}`;
      const warehouseText = this.warehouseLabel ? ` (${this.warehouseLabel})` : '';

      let tableHtml = `<table style="width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 16px;">`;
      tableHtml += `<thead><tr><th colspan="2" style="border: 1px solid #ddd; padding: 12px; background-color: #f5f5f5; font-weight: bold; text-align: left;">${title}</th></tr>`;
      tableHtml += `<tr><td colspan="2" style="border: 1px solid #ddd; padding: 8px; background-color: #f9f9f9; font-size: 10px;">${dateRangeText}${warehouseText}</td></tr>`;
      tableHtml += `</thead>`;
      tableHtml += `<tbody>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; font-weight: 600;">${this.$t('Sales')} (${this.num(this.infos.sales_count)})</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${this.money(this.infos.sales_sum)}</td></tr>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; font-weight: 600;">${this.$t('Purchases')} (${this.num(this.infos.purchases_count)})</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${this.money(this.infos.purchases_sum)}</td></tr>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; font-weight: 600;">${this.$t('SalesReturn')} (${this.num(this.infos.returns_sales_count)})</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${this.money(this.infos.returns_sales_sum)}</td></tr>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; font-weight: 600;">${this.$t('PurchasesReturn')} (${this.num(this.infos.returns_purchases_count)})</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${this.money(this.infos.returns_purchases_sum)}</td></tr>`;
      tableHtml += `<tr><td colspan="2" style="border: 1px solid #ddd; padding: 4px; background-color: #f5f5f5;"></td></tr>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; font-weight: 700; background-color: #eef0ff;">${this.$t('Revenue')}</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right; font-weight: 700; background-color: #eef0ff;">${this.money(this.infos.total_revenue)}</td></tr>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; font-weight: 600;">${this.$t('PaiementsReceived')}</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${this.money(this.infos.payment_received)}</td></tr>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; font-weight: 600;">${this.$t('PaiementsSent')}</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${this.money(this.infos.payment_sent)}</td></tr>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; font-weight: 600;">${this.$t('Expenses')}</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${this.money(this.infos.expenses_sum)}</td></tr>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; font-weight: 700; background-color: #eef2f7;">${this.$t('PaiementsNet')}</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right; font-weight: 700; background-color: #eef2f7;">${this.money(this.infos.paiement_net)}</td></tr>`;
      tableHtml += `<tr><td colspan="2" style="border: 1px solid #ddd; padding: 4px; background-color: #f5f5f5;"></td></tr>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; font-weight: 600;">${this.$t('Service_Jobs')} – ${this.$t('Revenue')} (${this.num(this.infos.service_jobs_count)})</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${this.money(this.infos.service_revenue_sum)}</td></tr>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; font-weight: 600;">${this.$t('Service_Jobs')} – ${this.$t('Product_Cost')}</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${this.money(this.infos.service_parts_cost)}</td></tr>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; font-weight: 700; background-color: #eaf7ef;">${this.$t('Service_Jobs')} – ${this.$t('ProfitNet')}</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right; font-weight: 700; background-color: #eaf7ef;">${this.money(this.infos.service_profit)}</td></tr>`;
      tableHtml += `<tr><td colspan="2" style="border: 1px solid #ddd; padding: 4px; background-color: #f5f5f5;"></td></tr>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; font-weight: 700; background-color: #e6fbff;">${this.$t('ProfitNet')} (FIFO)</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right; font-weight: 700; background-color: #e6fbff;">${this.money(this.infos.profit_fifo)}</td></tr>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; font-weight: 700; background-color: #fff8e1;">${this.$t('ProfitNet')} (${this.$t('AverageCost')})</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right; font-weight: 700; background-color: #fff8e1;">${this.money(this.infos.profit_average_cost)}</td></tr>`;
      tableHtml += `</tbody></table>`;

      const w = window.open("", "_blank");
      if (!w) {
        alert("Please allow popups to print");
        return;
      }

      const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
        .map(l => l.outerHTML)
        .join("\n");

      const doc = w.document;
      doc.open();
      doc.write(`<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <base href="${window.location.origin}/" />
    <title>${title}</title>
    ${links}
    <style>
      @media print {
        body, body * { visibility: visible !important; }
        @page { size: A4; margin: 1cm; }
      }
      body { margin: 0.3cm; font-family: Arial, sans-serif; }
      .print-header { font-weight: 600; margin-bottom: 8px; font-size: 14px; }
      table { width: 100%; border-collapse: collapse; }
      th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
      th { background-color: #f5f5f5; font-weight: bold; }
    </style>
  </head>
  <body>
    <div class="print-header">${title}</div>
    ${tableHtml}
  </body>
</html>`);
      doc.close();

      w.focus();
      setTimeout(() => {
        w.print();
        w.close();
      }, 400);
    },

    fetchPnl(){
      NProgress.start(); NProgress.set(0.1);
      this.isLoading = true;
      const from = this.fmtDate(this.dateRange.startDate);
      const to   = this.fmtDate(this.dateRange.endDate);
      const wh   = this.warehouse_id || '';

      axios.get(`report/profit_and_loss?from=${from}&to=${to}&warehouse_id=${wh}`)
        .then(({data})=>{
          this.infos = data.data || {};
          this.warehouses = data.warehouses || [];
          this.isLoading = false; NProgress.done();
        })
        .catch(()=>{ this.isLoading = false; NProgress.done(); });
    }
  },
  created(){ this.fetchPnl(); }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrp { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrp { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrp__pad { padding: var(--pxn-space-6) 0; }
.pxrp__filters { margin-top: var(--pxn-space-5); }
.pxrp__filterrow { display: flex; flex-wrap: wrap; gap: var(--pxn-space-6); align-items: flex-start; }
.pxrp__field { display: flex; flex-direction: column; gap: var(--pxn-space-2); }
.pxrp__field--wh { min-width: 240px; }
.pxrp__label { font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); }
.pxrp__daterange {
  display: inline-flex; align-items: center; gap: var(--pxn-space-3);
  height: var(--pxn-control-h-md); padding: 0 var(--pxn-space-5);
  border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); font: inherit; font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink); cursor: pointer;
}
.pxrp__daterange:hover { background: var(--pxn-surface-2); }
.pxrp__quick { display: flex; flex-wrap: wrap; gap: var(--pxn-space-2); }
.pxrp__range { margin-top: var(--pxn-space-4); }
.pxrp__whtag { margin-left: var(--pxn-space-3); padding: 2px var(--pxn-space-3); border-radius: var(--pxn-radius-sm); background: var(--pxn-surface-3); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-medium); }
.pxrp__grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-5); margin-top: var(--pxn-space-5); }
@media (max-width: 1100px) { .pxrp__grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 820px) { .pxrp__grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 480px) { .pxrp__grid { grid-template-columns: minmax(0, 1fr); } }
.pxrp ::v-deep .daterangepicker { z-index: 2055 !important; }
</style>
