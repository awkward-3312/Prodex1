<template>
  <div class="px-next pxrp">
    <px-page-header :title="$t('Analytics_Report')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('Analytics_Report') }]">
      <template #actions>
        <px-button variant="secondary" icon="printer" @click="printReport">{{ $t('print') }}</px-button>
        <px-button variant="primary" icon="refresh-cw" @click="fetchReport">{{ $t('Refresh') }}</px-button>
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
      <px-skeleton variant="lines" :rows="10" />
    </div>

    <div v-else class="analytics-report">
      <div class="pxrp__cols">
        <px-card title="Opening/Purchase">
          <div class="pxrp__dl">
            <div class="pxrp__dlrow"><span>Opening Stock (By purchase price)</span><span class="pxn-num">{{ money(data.opening_stock_purchase_price) }}</span></div>
            <div class="pxrp__dlrow"><span>Opening Stock (By sale price)</span><span class="pxn-num">{{ money(data.opening_stock_sale_price) }}</span></div>
            <div class="pxrp__dlrow"><span>Total Purchase (Excl. tax, Discount)</span><span class="pxn-num">{{ money(data.total_purchase_excl_tax) }}</span></div>
            <div class="pxrp__dlrow"><span>Total Stock Adjustment</span><span class="pxn-num">{{ money(data.total_stock_adjustment) }}</span></div>
            <div class="pxrp__dlrow"><span>Total Expense</span><span class="pxn-num">{{ money(data.total_expense) }}</span></div>
            <div class="pxrp__dlrow"><span>Total purchase shipping charge</span><span class="pxn-num">{{ money(data.total_purchase_shipping_charge) }}</span></div>
            <div class="pxrp__dlrow"><span>Total transfer shipping charge</span><span class="pxn-num">{{ money(data.total_transfer_shipping_charge) }}</span></div>
            <div class="pxrp__dlrow"><span>Total Sell discount</span><span class="pxn-num">{{ money(data.total_sell_discount) }}</span></div>
            <div class="pxrp__dlrow"><span>Total customer reward</span><span class="pxn-num">{{ money(data.total_customer_reward) }}</span></div>
            <div class="pxrp__dlrow"><span>Total Sell Return</span><span class="pxn-num">{{ money(data.total_sell_return) }}</span></div>
          </div>
        </px-card>

        <px-card title="Closing/Sales">
          <div class="pxrp__dl">
            <div class="pxrp__dlrow"><span>Closing stock (By purchase price)</span><span class="pxn-num">{{ money(data.closing_stock_purchase_price) }}</span></div>
            <div class="pxrp__dlrow"><span>Closing stock (By sale price)</span><span class="pxn-num">{{ money(data.closing_stock_sale_price) }}</span></div>
            <div class="pxrp__dlrow"><span>Total Sales (Excl. tax, Discount)</span><span class="pxn-num">{{ money(data.total_sales_excl_tax) }}</span></div>
            <div class="pxrp__dlrow"><span>Total sell shipping charge</span><span class="pxn-num">{{ money(data.total_sell_shipping_charge) }}</span></div>
            <div class="pxrp__dlrow"><span>Total Purchase Return</span><span class="pxn-num">{{ money(data.total_purchase_return) }}</span></div>
            <div class="pxrp__dlrow"><span>Total Purchase discount</span><span class="pxn-num">{{ money(data.total_purchase_discount) }}</span></div>
          </div>
        </px-card>
      </div>

      <px-card class="pxrp__profit">
        <div class="pxrp__profitrow">
          <span class="pxrp__profitlabel">Gross Profit:</span>
          <span class="pxrp__profitval pxn-num">{{ money(grossProfit) }}</span>
        </div>
        <p class="pxrp__profitformula">Formula: (Total sell price - Total purchase price)</p>
        <div class="pxrp__profitrow pxrp__profitrow--gap">
          <span class="pxrp__profitlabel">Net Profit:</span>
          <span class="pxrp__profitval pxn-num">{{ money(netProfit) }}</span>
        </div>
        <p class="pxrp__profitformula">Formula: Gross Profit - (Total Expense + Total transfer shipping charge)</p>
      </px-card>
    </div>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";
import moment from "moment";
import DateRangePicker from "vue2-daterange-picker";
import "vue2-daterange-picker/dist/vue2-daterange-picker.css";
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting
} from "../../../../utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: "Analytics Report" },
  components: {
    "date-range-picker": DateRangePicker,
    PxPageHeader, PxCard, PxButton, PxAlert, "vs-px": VsPx
  },
  data() {
    const end = moment().endOf('day').toDate();
    const start = moment().startOf('day').toDate();
    return {
      isLoading: true,
      warehouses: [],
      warehouse_id: null,
      dateRange: { startDate: start, endDate: end },
      picker: { opens: 'right', drops: 'auto' },
      isMobile: false,
      locale: {
        Label: this.$t("Apply") || "Apply",
        cancelLabel: this.$t("Cancel") || "Cancel",
        weekLabel: "W",
        customRangeLabel: this.$t("CustomRange") || "Custom Range",
        daysOfWeek: moment.weekdaysMin(),
        monthNames: moment.monthsShort(),
        firstDay: 1
      },
      data: {
        opening_stock_purchase_price: 0,
        opening_stock_sale_price: 0,
        total_purchase_excl_tax: 0,
        total_stock_adjustment: 0,
        total_expense: 0,
        total_purchase_shipping_charge: 0,
        total_transfer_shipping_charge: 0,
        total_sell_discount: 0,
        total_customer_reward: 0,
        total_sell_return: 0,
        closing_stock_purchase_price: 0,
        closing_stock_sale_price: 0,
        total_sales_excl_tax: 0,
        total_sell_shipping_charge: 0,
        total_purchase_return: 0,
        total_purchase_discount: 0
      },
      price_format_key: null
    };
  },
  computed: {
    ...mapGetters(["currentUser"]),
    currency() {
      return (this.currentUser && this.currentUser.currency) || "KD";
    },
    warehouseLabel() {
      const w = this.warehouses.find(w => w.id === this.warehouse_id);
      return w ? w.name : null;
    },
    totalPurchasePrice() {
      return (
        Number(this.data.opening_stock_purchase_price || 0) +
        Number(this.data.total_purchase_excl_tax || 0)
      );
    },
    totalSellPrice() {
      return (
        Number(this.data.closing_stock_sale_price || 0) +
        Number(this.data.total_sales_excl_tax || 0)
      );
    },
    grossProfit() {
      return this.totalSellPrice - this.totalPurchasePrice;
    },
    netProfit() {
      const deductions =
        Number(this.data.total_expense || 0) +
        Number(this.data.total_transfer_shipping_charge || 0);
      return this.grossProfit - deductions;
    }
  },
  methods: {
    handleResize() {
      this.isMobile = window.innerWidth < 576;
    },
    updatePickerPlacement() {
      const isXs = window.matchMedia('(max-width: 576px)').matches;
      this.picker.opens = isXs ? 'center' : 'right';
      this.picker.drops = 'auto';
    },
    fmtDate(d) {
      return moment(d).format('YYYY-MM-DD');
    },
    fmtShort(d) {
      return moment(d).format('MMM D');
    },
    onDateChange() {
      this.fetchReport();
    },
    onWarehouseChange() {
      this.fetchReport();
    },
    applyQuick(kind) {
      const now = moment();
      let start, end;

      if (kind === 'today') {
        start = now.clone().startOf('day');
        end = now.clone().endOf('day');
      } else if (kind === 'yesterday') {
        start = now.clone().subtract(1, 'day').startOf('day');
        end = now.clone().subtract(1, 'day').endOf('day');
      } else if (kind === '7d') {
        start = now.clone().subtract(6, 'days').startOf('day');
        end = now.clone().endOf('day');
      } else if (kind === '30d') {
        start = now.clone().subtract(29, 'days').startOf('day');
        end = now.clone().endOf('day');
      } else if (kind === '90d') {
        start = now.clone().subtract(89, 'days').startOf('day');
        end = now.clone().endOf('day');
      } else if (kind === 'mtd') {
        start = now.clone().startOf('month');
        end = now.clone().endOf('day');
      } else if (kind === 'ytd') {
        start = now.clone().startOf('year');
        end = now.clone().endOf('day');
      }

      this.dateRange = { startDate: start.toDate(), endDate: end.toDate() };
      this.fetchReport();
    },
    num(v) {
      const n = parseFloat(v || 0);
      return isNaN(n) ? 0 : n;
    },
    money(v) {
      try {
        const n = this.num(v);
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        const effectiveKey = key || null;
        const formatted = formatPriceDisplayHelper(n, 2, effectiveKey);
        return `${this.currency} ${formatted}`;
      } catch (e) {
        try {
          return new Intl.NumberFormat(undefined, {
            style: "currency",
            currency: this.currency
          }).format(this.num(v));
        } catch (e2) {
          return `${this.currency} ${this.num(v).toLocaleString()}`;
        }
      }
    },
    fetchReport() {
      NProgress.start();
      NProgress.set(0.1);
      this.isLoading = true;

      const params = new URLSearchParams({
        from: this.fmtDate(this.dateRange.startDate),
        to: this.fmtDate(this.dateRange.endDate)
      });

      if (this.warehouse_id) {
        params.append('warehouse_id', this.warehouse_id);
      }

      axios
        .get(`report/analytics_summary?${params.toString()}`)
        .then(({ data }) => {
          this.data = {
            opening_stock_purchase_price: Number(data.opening_stock_purchase_price || 0),
            opening_stock_sale_price: Number(data.opening_stock_sale_price || 0),
            total_purchase_excl_tax: Number(data.total_purchase_excl_tax || 0),
            total_stock_adjustment: Number(data.total_stock_adjustment || 0),
            total_expense: Number(data.total_expense || 0),
            total_purchase_shipping_charge: Number(data.total_purchase_shipping_charge || 0),
            total_transfer_shipping_charge: Number(data.total_transfer_shipping_charge || 0),
            total_sell_discount: Number(data.total_sell_discount || 0),
            total_customer_reward: Number(data.total_customer_reward || 0),
            total_sell_return: Number(data.total_sell_return || 0),
            closing_stock_purchase_price: Number(data.closing_stock_purchase_price || 0),
            closing_stock_sale_price: Number(data.closing_stock_sale_price || 0),
            total_sales_excl_tax: Number(data.total_sales_excl_tax || 0),
            total_sell_shipping_charge: Number(data.total_sell_shipping_charge || 0),
            total_purchase_return: Number(data.total_purchase_return || 0),
            total_purchase_discount: Number(data.total_purchase_discount || 0)
          };
          this.warehouses = data.warehouses || [];
          this.isLoading = false;
          NProgress.done();
        })
        .catch((error) => {
          this.isLoading = false;
          NProgress.done();
          const msg = (error && error.response && error.response.data && error.response.data.message)
            || (this.$t ? this.$t('Error') : null)
            || 'Failed to load analytics report';
          if (this.$swal && typeof this.$swal.fire === 'function') {
            this.$swal.fire({ icon: 'error', title: msg, timer: 3000, showConfirmButton: false });
          } else if (window && typeof window.alert === 'function') {
            window.alert(msg);
          }
        });
    },
    printReport() {
      const title = `${this.$t("Reports")} / ${this.$t("Analytics_Report")}`;
      const dateRangeText = `${this.fmtDate(this.dateRange.startDate)} — ${this.fmtDate(this.dateRange.endDate)}`;
      const warehouseText = this.warehouseLabel ? ` (${this.warehouseLabel})` : '';
      const root = this.$el;
      if (!root) {
        window.print();
        return;
      }

      const reportContent = root.querySelector(".analytics-report");
      if (!reportContent) {
        window.print();
        return;
      }

      const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
        .map((l) => l.outerHTML)
        .join("\n");

      const inlineStyles = Array.from(document.querySelectorAll("style"))
        .filter((s) => !(s.textContent || "").includes("@media print"))
        .map((s) => s.outerHTML)
        .join("\n");

      let reportHtml = `<div class="analytics-report">`;

      reportHtml += `<div class="card mb-3" style="page-break-inside: avoid;">
        <div class="card-body">
          <h6 class="mb-3">Opening/Purchase</h6>
          <table style="width: 100%;">`;
      const leftFields = [
        { label: "Opening Stock (By purchase price)", value: this.money(this.data.opening_stock_purchase_price) },
        { label: "Opening Stock (By sale price)", value: this.money(this.data.opening_stock_sale_price) },
        { label: "Total Purchase (Excl. tax, Discount)", value: this.money(this.data.total_purchase_excl_tax) },
        { label: "Total Stock Adjustment", value: this.money(this.data.total_stock_adjustment) },
        { label: "Total Expense", value: this.money(this.data.total_expense) },
        { label: "Total purchase shipping charge", value: this.money(this.data.total_purchase_shipping_charge) },
        { label: "Total transfer shipping charge", value: this.money(this.data.total_transfer_shipping_charge) },
        { label: "Total Sell discount", value: this.money(this.data.total_sell_discount) },
        { label: "Total customer reward", value: this.money(this.data.total_customer_reward) },
        { label: "Total Sell Return", value: this.money(this.data.total_sell_return) }
      ];
      leftFields.forEach((field) => {
        reportHtml += `<tr style="border-bottom: 1px solid #e0e0e0;">
          <td style="padding: 8px; text-align: left;">${field.label}</td>
          <td style="padding: 8px; text-align: right;">${field.value}</td>
        </tr>`;
      });
      reportHtml += `</table></div></div>`;

      reportHtml += `<div class="card mb-3" style="page-break-inside: avoid;">
        <div class="card-body">
          <h6 class="mb-3">Closing/Sales</h6>
          <table style="width: 100%;">`;
      const rightFields = [
        { label: "Closing stock (By purchase price)", value: this.money(this.data.closing_stock_purchase_price) },
        { label: "Closing stock (By sale price)", value: this.money(this.data.closing_stock_sale_price) },
        { label: "Total Sales (Excl. tax, Discount)", value: this.money(this.data.total_sales_excl_tax) },
        { label: "Total sell shipping charge", value: this.money(this.data.total_sell_shipping_charge) },
        { label: "Total Purchase Return", value: this.money(this.data.total_purchase_return) },
        { label: "Total Purchase discount", value: this.money(this.data.total_purchase_discount) }
      ];
      rightFields.forEach((field) => {
        reportHtml += `<tr style="border-bottom: 1px solid #e0e0e0;">
          <td style="padding: 8px; text-align: left;">${field.label}</td>
          <td style="padding: 8px; text-align: right;">${field.value}</td>
        </tr>`;
      });
      reportHtml += `</table></div></div>`;

      reportHtml += `<div class="card mt-3" style="page-break-inside: avoid;">
        <div class="card-body">
          <div style="margin-bottom: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
              <strong>Gross Profit:</strong>
              <strong>${this.money(this.grossProfit)}</strong>
            </div>
            <div style="font-size: 11px; color: #666;">Formula: (Total sell price - Total purchase price)</div>
          </div>
          <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
              <strong>Net Profit:</strong>
              <strong>${this.money(this.netProfit)}</strong>
            </div>
            <div style="font-size: 11px; color: #666;">Formula: Gross Profit - (Total Expense + Total transfer shipping charge)</div>
          </div>
        </div>
      </div>`;

      reportHtml += `</div>`;

      const w = window.open("", "_blank");
      if (!w) {
        window.print();
        return;
      }

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
    ${inlineStyles}
    <style>
      @media print {
        body, body * { visibility: visible !important; }
        @page { size: A4; margin: 1cm; }
      }
      body { margin: 0.3cm; font-family: Arial, sans-serif; }
      .print-header { font-weight: 600; margin-bottom: 8px; font-size: 14px; }
      .print-meta { font-size: 10px; margin-bottom: 15px; color: #666; }
      .card { border: 1px solid #ddd; border-radius: 8px; margin-bottom: 16px; }
      .card-body { padding: 16px; }
      table { width: 100%; border-collapse: collapse; }
      td { border-bottom: 1px solid #e0e0e0; }
      @media print {
        .analytics-report { display: block; }
      }
      @media screen {
        .analytics-report { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
      }
    </style>
  </head>
  <body>
    <div class="print-header">${title}</div>
    <div class="print-meta">${dateRangeText}${warehouseText}</div>
    ${reportHtml}
  </body>
</html>`);
      doc.close();

      w.focus();
      setTimeout(() => {
        w.print();
        w.close();
      }, 400);
    }
  },
  mounted() {
    this.handleResize();
    this.updatePickerPlacement();
    window.addEventListener('resize', this.handleResize);
    window.addEventListener('resize', this.updatePickerPlacement);
  },
  beforeDestroy() {
    window.removeEventListener('resize', this.handleResize);
    window.removeEventListener('resize', this.updatePickerPlacement);
  },
  created() {
    this.fetchReport();
  }
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
.pxrp__dl { display: flex; flex-direction: column; }
.pxrp__dlrow { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-5); padding: var(--pxn-space-3) 0; border-bottom: 1px solid var(--pxn-border); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.pxrp__dlrow:last-child { border-bottom: 0; }
.pxrp__dlrow .pxn-num { color: var(--pxn-ink); font-weight: var(--pxn-fw-medium); }
.pxrp__profit { margin-top: var(--pxn-space-5); }
.pxrp__profitrow { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-4); }
.pxrp__profitrow--gap { margin-top: var(--pxn-space-6); }
.pxrp__profitlabel { font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxrp__profitval { font-size: var(--pxn-fs-lg); font-weight: var(--pxn-fw-bold); color: var(--pxn-ink); }
.pxrp__profitformula { margin-top: var(--pxn-space-2); font-size: var(--pxn-fs-xs); font-style: italic; color: var(--pxn-ink-3); }
.pxrp ::v-deep .daterangepicker { z-index: 2055 !important; }
</style>
