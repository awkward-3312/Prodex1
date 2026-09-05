<template>
  <div class="px-next pxrp">
    <px-page-header :title="$t('Return_Ratio_Report')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('Return_Ratio_Report') }]">
      <template #actions>
        <px-button variant="secondary" icon="printer" @click="printTableOnly()">{{ $t('print') }}</px-button>
        <px-button variant="primary" icon="refresh-cw" @click="fetchData">{{ $t('Refresh') }}</px-button>
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
            <px-button size="sm" variant="subtle" @click="applyQuick('today')">{{ $t('Today') }}</px-button>
            <px-button size="sm" variant="subtle" @click="applyQuick('7d')">7D</px-button>
            <px-button size="sm" variant="subtle" @click="applyQuick('30d')">30D</px-button>
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
      <px-skeleton variant="lines" :rows="6" />
    </div>

    <template v-else>
      <div class="pxrp__cols">
        <px-card :title="$t('Sales')">
          <div class="pxrp__ratio">
            <div class="pxrp__ratio-left">
              <p class="pxrp__ratio-total pxn-num">{{ money(data.sales_sum) }}</p>
              <p class="pxrp__ratio-sub">{{ $t('SalesReturn') }}</p>
              <p class="pxrp__ratio-ret pxn-num">{{ money(data.returns_sales_sum) }}</p>
            </div>
            <div class="pxrp__ratio-pct">
              <span class="pxrp__ratio-pctlabel">{{ $t('Return_Ratio') || 'Return Ratio' }}</span>
              <span class="pxrp__ratio-pctval pxn-num">{{ data.sales_return_ratio_pct || 0 }}%</span>
            </div>
          </div>
        </px-card>
        <px-card :title="$t('Purchases')">
          <div class="pxrp__ratio">
            <div class="pxrp__ratio-left">
              <p class="pxrp__ratio-total pxn-num">{{ money(data.purchases_sum) }}</p>
              <p class="pxrp__ratio-sub">{{ $t('PurchasesReturn') }}</p>
              <p class="pxrp__ratio-ret pxn-num">{{ money(data.returns_purchases_sum) }}</p>
            </div>
            <div class="pxrp__ratio-pct">
              <span class="pxrp__ratio-pctlabel">{{ $t('Return_Ratio') || 'Return Ratio' }}</span>
              <span class="pxrp__ratio-pctval pxn-num">{{ data.purchase_return_ratio_pct || 0 }}%</span>
            </div>
          </div>
        </px-card>
      </div>

      <div class="pxrp__cols pxrp__gap">
        <px-card :title="`${$t('Report')} — ${$t('Returns')}`">
          <apexchart type="radialBar" height="320" :options="apexRadialOptions" :series="apexRadialSeries" />
        </px-card>
        <px-card :title="`${$t('Report')} — ${$t('Totals')}`">
          <apexchart type="bar" height="320" :options="apexBarOptions" :series="apexBarSeries" />
        </px-card>
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
import VueApexCharts from "vue-apexcharts";
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting,
  getPriceDecimals
} from "../../../../utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: "Return Ratio Report" },
  components: { 'date-range-picker': DateRangePicker, apexchart: VueApexCharts, PxPageHeader, PxCard, PxButton, PxAlert, "vs-px": VsPx },
  data(){
    const start = moment().startOf('day').toDate();
    const end   = moment().endOf('day').toDate();
    return {
      isLoading: true,
      dateRange: { startDate: start, endDate: end },
      picker: { opens: 'right', drops: 'auto' },
      locale: {
        Label: this.$t("Apply"),
        cancelLabel: this.$t("Cancel"),
        weekLabel: "W",
        customRangeLabel: this.$t("CustomRange"),
        daysOfWeek: moment.weekdaysMin(),
        monthNames: moment.monthsShort(),
        firstDay: 1
      },
      warehouses: [],
      warehouse_id: null,
      data: {
        sales_sum: 0,
        returns_sales_sum: 0,
        sales_return_ratio_pct: 0,
        purchases_sum: 0,
        returns_purchases_sum: 0,
        purchase_return_ratio_pct: 0,
      },
      price_format_key: null
    };
  },
  computed: {
    ...mapGetters(["currentUser"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    currency(){ return (this.currentUser && this.currentUser.currency) || "USD"; },
    warehouseLabel(){ const w = this.warehouses.find(w=>w.id===this.warehouse_id); return w ? w.name : null; },

    apexRadialOptions(){
      return {
        chart: { type: 'radialBar' },
        plotOptions: {
          radialBar: {
            hollow: { size: '45%' },
            dataLabels: {
              name: { fontSize: '14px' },
              value: { formatter: (v)=> `${Number(v||0).toFixed(2)}%` }
            }
          }
        },
        labels: [ this.$t('Sales'), this.$t('Purchases') ],
        colors: ['#2563eb','#14b8a6']
      };
    },
    apexRadialSeries(){
      return [
        Number(this.data.sales_return_ratio_pct || 0),
        Number(this.data.purchase_return_ratio_pct || 0)
      ];
    },

    apexBarOptions(){
      return {
        chart: { type: 'bar', stacked: false, toolbar: { show:false } },
        plotOptions: { bar: { horizontal: false, columnWidth: '45%' } },
        dataLabels: { enabled: false },
        xaxis: { categories: [ this.$t('Sales'), this.$t('Purchases') ] },
        yaxis: { labels: { formatter: (v)=> this.shortMoney(v) } },
        tooltip: { y: { formatter: (v)=> this.money(v) } },
        legend: { position: 'top' }
      };
    },
    apexBarSeries(){
      return [
        { name: this.$t('Total'), data: [ Number(this.data.sales_sum||0), Number(this.data.purchases_sum||0) ] },
        { name: this.$t('Returns'), data: [ Number(this.data.returns_sales_sum||0), Number(this.data.returns_purchases_sum||0) ] }
      ];
    }
  },
  mounted(){ this.updatePickerPlacement(); window.addEventListener('resize', this.updatePickerPlacement); },
  beforeDestroy(){ window.removeEventListener('resize', this.updatePickerPlacement); },
  methods: {
    updatePickerPlacement(){ const isXs = window.matchMedia('(max-width: 576px)').matches; this.picker.opens = isXs ? 'center':'right'; this.picker.drops = 'auto'; },
    fmtDate(d){ return moment(d).format('YYYY-MM-DD'); },
    num(v){ const n = parseFloat(v||0); return isNaN(n)?0:n; },
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
      } catch(e) {
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
    onDateChange(){ this.fetchData(); },
    onWarehouseChange(){ this.fetchData(); },
    applyQuick(kind){
      const now = moment(); let start,end;
      if(kind==='today'){ start = now.clone().startOf('day'); end = now.clone().endOf('day'); }
      if(kind==='7d'){ start = now.clone().subtract(6,'days').startOf('day'); end = now.clone().endOf('day'); }
      if(kind==='30d'){ start = now.clone().subtract(29,'days').startOf('day'); end = now.clone().endOf('day'); }
      if(kind==='ytd'){ start = now.clone().startOf('year'); end = now.clone().endOf('day'); }
      this.dateRange = { startDate: start.toDate(), endDate: end.toDate() };
      this.fetchData();
    },
    //------ Print Table Only
    printTableOnly() {
      const title = `${this.$t("Reports")} / ${this.$t("Return_Ratio_Report")}`;
      const dateRangeText = `${this.fmtDate(this.dateRange.startDate)} — ${this.fmtDate(this.dateRange.endDate)}`;
      const warehouseText = this.warehouseLabel ? ` (${this.warehouseLabel})` : '';

      let tableHtml = `<table style="width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 16px;">`;
      tableHtml += `<thead><tr><th colspan="4" style="border: 1px solid #ddd; padding: 12px; background-color: #f5f5f5; font-weight: bold; text-align: left;">${title}</th></tr>`;
      tableHtml += `<tr><td colspan="4" style="border: 1px solid #ddd; padding: 8px; background-color: #f9f9f9; font-size: 10px;">${dateRangeText}${warehouseText}</td></tr>`;
      tableHtml += `</thead>`;
      tableHtml += `<tbody>`;
      tableHtml += `<tr><td colspan="4" style="border: 1px solid #ddd; padding: 4px; background-color: #f5f5f5;"></td></tr>`;
      tableHtml += `<tr><td colspan="4" style="border: 1px solid #ddd; padding: 8px; font-weight: 700; background-color: #e6f0ff;">${this.$t('Sales')}</td></tr>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; padding-left: 24px;">${this.$t('Sales')}</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${this.money(this.data.sales_sum)}</td><td style="border: 1px solid #ddd; padding: 8px;"></td><td style="border: 1px solid #ddd; padding: 8px;"></td></tr>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; padding-left: 24px;">${this.$t('SalesReturn')}</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${this.money(this.data.returns_sales_sum)}</td><td style="border: 1px solid #ddd; padding: 8px;">${this.$t('Return_Ratio')}</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right; font-weight: 700;">${Number(this.data.sales_return_ratio_pct || 0).toFixed(2)}%</td></tr>`;
      tableHtml += `<tr><td colspan="4" style="border: 1px solid #ddd; padding: 4px; background-color: #f5f5f5;"></td></tr>`;
      tableHtml += `<tr><td colspan="4" style="border: 1px solid #ddd; padding: 8px; font-weight: 700; background-color: #e6fbf6;">${this.$t('Purchases')}</td></tr>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; padding-left: 24px;">${this.$t('Purchases')}</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${this.money(this.data.purchases_sum)}</td><td style="border: 1px solid #ddd; padding: 8px;"></td><td style="border: 1px solid #ddd; padding: 8px;"></td></tr>`;
      tableHtml += `<tr><td style="border: 1px solid #ddd; padding: 8px; padding-left: 24px;">${this.$t('PurchasesReturn')}</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${this.money(this.data.returns_purchases_sum)}</td><td style="border: 1px solid #ddd; padding: 8px;">${this.$t('Return_Ratio')}</td><td style="border: 1px solid #ddd; padding: 8px; text-align: right; font-weight: 700;">${Number(this.data.purchase_return_ratio_pct || 0).toFixed(2)}%</td></tr>`;
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

    fetchData(){
      NProgress.start(); NProgress.set(0.1); this.isLoading = true;
      const from = this.fmtDate(this.dateRange.startDate);
      const to   = this.fmtDate(this.dateRange.endDate);
      const wh   = this.warehouse_id || '';
      axios.get(`report/return_ratio_report?from=${from}&to=${to}&warehouse_id=${wh}`)
        .then(({data})=>{
          this.data = data.data || this.data;
          this.warehouses = data.warehouses || [];
          this.isLoading = false; NProgress.done();
        })
        .catch(()=>{ this.isLoading = false; NProgress.done(); });
    }
  },
  created(){ this.fetchData(); }
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
.pxrp__cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); margin-top: var(--pxn-space-5); }
@media (max-width: 820px) { .pxrp__cols { grid-template-columns: minmax(0, 1fr); } }
.pxrp__gap { margin-top: var(--pxn-space-5); }
.pxrp__ratio { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-5); }
.pxrp__ratio-total { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); margin-bottom: var(--pxn-space-3); }
.pxrp__ratio-sub { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxrp__ratio-ret { font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxrp__ratio-pct { text-align: right; }
.pxrp__ratio-pctlabel { display: block; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxrp__ratio-pctval { font-size: var(--pxn-fs-kpi); font-weight: var(--pxn-fw-bold); color: var(--pxn-ink); }
.pxrp ::v-deep .daterangepicker { z-index: 2055 !important; }
</style>
