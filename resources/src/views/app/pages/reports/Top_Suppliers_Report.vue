<template>
  <div class="px-next pxrp">
    <px-page-header :title="$t('Top_Suppliers_Report')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('Top_Suppliers_Report') }]">
      <template #actions>
        <px-menu :items="exportMenu" align="end" @select="onExport">
          <template #trigger>
            <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">{{ $t('Export') }}</px-button>
          </template>
        </px-menu>
        <px-button variant="primary" size="sm" icon="refresh-cw" @click="fetchReport">{{ $t('Refresh') }}</px-button>
      </template>
    </px-page-header>

    <px-card class="pxrp__filters">
      <div class="pxrp__filterrow">
        <div class="pxrp__field">
          <label class="pxrp__label">{{ $t('DateRange') }}</label>
          <date-range-picker
            v-model="dateRange"
            :locale-data="locale"
            :autoApply="true"
            :showDropdowns="true"
            :opens="isMobile ? 'center' : 'right'"
            :drops="'down'"
            @update="fetchReport"
          >
            <template v-slot:input="picker">
              <button type="button" class="pxrp__daterange pxn-ring">
                <lucide-icon name="calendar-days" :size="14" />
                {{ fmt(picker.startDate) }} — {{ fmt(picker.endDate) }}
              </button>
            </template>
          </date-range-picker>
        </div>
        <div class="pxrp__field">
          <label class="pxrp__label">{{ $t('QuickRanges') }}</label>
          <div class="pxrp__quick">
            <px-button size="sm" variant="subtle" @click="quick('7d')">7D</px-button>
            <px-button size="sm" variant="subtle" @click="quick('30d')">30D</px-button>
            <px-button size="sm" variant="subtle" @click="quick('90d')">90D</px-button>
            <px-button size="sm" variant="subtle" @click="quick('mtd')">{{ $t('MTD') }}</px-button>
            <px-button size="sm" variant="subtle" @click="quick('ytd')">{{ $t('YTD') }}</px-button>
          </div>
        </div>
        <div class="pxrp__field pxrp__field--wh">
          <label class="pxrp__label">{{ $t('warehouse') }}</label>
          <vs-px
            v-model="warehouse_id"
            @input="fetchReport"
            :reduce="o => o.value"
            :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
            :placeholder="$t('Choose_Warehouse')"
          />
        </div>
      </div>
    </px-card>

    <div v-if="isLoading" class="pxrp__pad">
      <px-skeleton variant="lines" :rows="8" />
    </div>

    <template v-else>
      <div class="pxrp__kpis">
        <px-stat bordered icon="users" :label="$t('Vendors')" :value="num(kpis.vendors_count)" />
        <px-stat bordered icon="file-text" :label="$t('Purchases')" :value="num(kpis.total_purchases)" />
        <px-stat bordered icon="package" :label="$t('QtyPurchased')" :value="formatQty(kpis.total_qty)" />
        <px-stat bordered icon="banknote" :label="$t('TotalSpend')" :value="money(kpis.total_spend)" />
      </div>

      <div class="pxrp__cols pxrp__cols--87 pxrp__gap">
        <px-card :title="$t('TopSuppliersByValue')">
          <apexchart type="bar" height="320" :options="apexValueOptions" :series="apexValueSeries" />
        </px-card>
        <px-card :title="$t('TopSuppliersByQty')">
          <apexchart type="bar" height="320" :options="apexQtyOptions" :series="apexQtySeries" />
        </px-card>
      </div>

      <px-toolbar
        :search="search"
        :search-placeholder="$t('Search_this_table')"
        @update:search="onSearchInput"
      />

      <div class="pxrp__tablewrap">
        <px-table
          v-if="rows.length"
          :columns="columns"
          :rows="rows"
          row-key="supplier"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          @sort="onSort"
        >
          <template #cell-orders_count="{ row }"><span class="pxn-num">{{ num(row.orders_count) }}</span></template>
          <template #cell-qty_sum="{ row }"><span class="pxn-num">{{ formatQty(row.qty_sum) }}</span></template>
          <template #cell-value_sum="{ row }"><span class="pxn-num">{{ money(row.value_sum) }}</span></template>
          <template #cell-avg_value="{ row }"><span class="pxn-num">{{ money(row.avg_value) }}</span></template>
        </px-table>

        <px-empty-state v-else icon="trending-up" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
      </div>

      <div v-if="rows.length" class="pxrp__totalrow">
        <span>{{ $t('Totals') }}</span>
        <span>{{ $t('Purchases') }}: <b class="pxn-num">{{ num(sumField(rows, 'orders_count')) }}</b></span>
        <span>{{ $t('QtyPurchased') }}: <b class="pxn-num">{{ formatQty(sumField(rows, 'qty_sum')) }}</b></span>
        <span>{{ $t('TotalSpend') }}: <b class="pxn-num">{{ money(sumField(rows, 'value_sum')) }}</b></span>
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
import NProgress from "nprogress";
import { mapGetters } from "vuex";
import DateRangePicker from "vue2-daterange-picker";
import "vue2-daterange-picker/dist/vue2-daterange-picker.css";
import moment from "moment";
import VueApexCharts from "vue-apexcharts";
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting,
  getPriceDecimals
} from "../../../../utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxMenu from "@/components/px-next/PxMenu.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxStat from "@/components/px-next/PxStat.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: "Top Suppliers Report" },
  components: {
    apexchart: VueApexCharts, "date-range-picker": DateRangePicker,
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu, PxCard, PxStat, PxEmptyState, "vs-px": VsPx
  },
  data(){
    const end = new Date(), start = new Date(); start.setDate(end.getDate()-29);
    return {
      _searchTimer: null,
      warehouses: [], warehouse_id:null,
      isLoading:true,
      kpis:{vendors_count:0,total_purchases:0,total_qty:0,total_spend:0},
      topByValue:[], topByQty:[],
      rows:[], totalRows:0,
      serverParams:{page:1, perPage:10, sort:{field:'value_sum', type:'desc'}},
      limit:10, search:'',
      dateRange:{startDate:start, endDate:end},
      locale:{
        Label: this.$t("Apply") || "Apply",
        cancelLabel: this.$t("Cancel") || "Cancel",
        weekLabel: "W",
        customRangeLabel: this.$t("CustomRange") || "Custom Range",
        daysOfWeek: moment.weekdaysMin(),
        monthNames: moment.monthsShort(),
        firstDay: 1
      },
      isMobile:false,
      price_format_key: null
    }
  },
  computed:{
    ...mapGetters(["currentUser"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    currency(){ return (this.currentUser && this.currentUser.currency) || "USD"; },
    labelRange(){ return `${this.fmt(this.dateRange.startDate)} → ${this.fmt(this.dateRange.endDate)}`; },
    exportMenu() {
      return [
        { key: "print", label: this.$t("print"), icon: "printer" },
        { key: "pdf", label: this.$t("Export_PDF") || "PDF", icon: "file-text" }
      ];
    },
    columns(){
      return [
        {key:'supplier',      label:this.$t('Supplier'),        sortable:true, strong:true},
        {key:'orders_count',  label:this.$t('Purchases'),       sortable:true, align:'right'},
        {key:'qty_sum',       label:this.$t('QtyPurchased'),    sortable:true, align:'right'},
        {key:'value_sum',     label:this.$t('TotalSpend'),      sortable:true, align:'right'},
        {key:'avg_value',     label:this.$t('AvgPerPurchase'),  sortable:true, align:'right'}
      ]
    },
    apexValueOptions(){
      return {
        chart: { toolbar: { show: false } },
        xaxis: { categories: this.topByValue.map(x => x.supplier) },
        yaxis: { labels: { formatter: (v) => this.shortMoney(v) } },
        dataLabels: { enabled: false },
        tooltip: { y: { formatter: (v) => this.money(v) } },
      };
    },
    apexValueSeries(){
      return [{ name: this.$t('TotalSpend'), data: this.topByValue.map(x => Number(x.value_sum || 0)) }];
    },
    apexQtyOptions(){
      return {
        chart: { toolbar: { show: false } },
        xaxis: { labels: { formatter: (v) => this.shortMoney(v) } },
        yaxis: { categories: this.topByQty.map(x => x.supplier) },
        plotOptions: { bar: { horizontal: true } },
        dataLabels: { enabled: false },
      };
    },
    apexQtySeries(){
      return [{ name: this.$t('QtyPurchased'), data: this.topByQty.map(x => Number(x.qty_sum || 0)) }];
    }
  },
  methods:{
    fmt(d){ return moment(d).format('YYYY-MM-DD'); },
    fmtShort(d){ return moment(d).format('MMM D'); },
    num(v){ const n = Number(v||0); return isNaN(n)?'0':n.toLocaleString(); },
    money(v){
      try {
        const n = Number(v || 0);
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        const effectiveKey = key || null;
        const formatted = formatPriceDisplayHelper(n, this.priceDecimals, effectiveKey);
        return `${this.currency} ${formatted}`;
      } catch(e) {
        try {
          return new Intl.NumberFormat(undefined,{style:'currency',currency:this.currency}).format(Number(v||0));
        } catch(e2) {
          return `${this.currency} ${Number(v||0).toLocaleString()}`;
        }
      }
    },
    shortMoney(v){ return new Intl.NumberFormat(undefined,{notation:'compact',maximumFractionDigits:1}).format(Number(v||0)); },
    formatQty(v){ return Number(v||0).toLocaleString(undefined,{maximumFractionDigits:2}); },

    handleResize(){ this.isMobile = window.innerWidth < 576; },

    quick(kind){
      const now = moment(); let s = now.clone().subtract(29,'days'), e = now.clone();
      if(kind==='7d')  s = now.clone().subtract(6,'days');
      if(kind==='30d') s = now.clone().subtract(29,'days');
      if(kind==='90d') s = now.clone().subtract(89,'days');
      if(kind==='mtd') s = now.clone().startOf('month');
      if(kind==='ytd') s = now.clone().startOf('year');
      this.dateRange = { startDate: s.toDate(), endDate: e.toDate() };
      this.fetchReport();
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.serverParams.page = 1; this.fetchReport(); }, 350);
    },
    onPage(p) { if (this.serverParams.page !== p) { this.serverParams.page = p; this.fetchReport(); } },
    onLimit(v) { this.serverParams.perPage = Number(v); this.limit = Number(v); this.serverParams.page = 1; this.fetchReport(); },
    onSort({ key, dir }) { this.serverParams.sort = { field: key, type: dir }; this.fetchReport(); },

    onExport(item) {
      const k = item && item.key;
      if (k === "print") this.printTableOnly();
      else if (k === "pdf") this.exportPDF();
    },

    sumField(rows, key){
      if(!Array.isArray(rows)) return 0;
      return rows.reduce((acc,r)=> acc + Number(r[key]||0), 0);
    },

    fetchReport(){
      NProgress.start(); NProgress.set(0.1); this.isLoading=true;
      const qs = new URLSearchParams({
        from:this.fmt(this.dateRange.startDate),
        to:this.fmt(this.dateRange.endDate),
        warehouse_id:this.warehouse_id || '',
        page:String(this.serverParams.page),
        limit:String(this.serverParams.perPage || this.limit),
        SortField:this.serverParams.sort?.field || 'value_sum',
        SortType:this.serverParams.sort?.type || 'desc',
        search:this.search || ''
      }).toString();

      axios.get(`report/top_suppliers?${qs}`)
        .then(({data})=>{
          const d = data.data || {};
          this.kpis = d.kpis || this.kpis;
          this.topByValue = d.topByValue || [];
          this.topByQty   = d.topByQty || [];
          this.rows = d.rows || [];
          this.totalRows = d.totalRows || 0;
          this.warehouses = data.warehouses || [];
          this.isLoading=false; NProgress.done();
        })
        .catch(()=>{ this.isLoading=false; NProgress.done(); });
    },

    async exportPDF(){
      try{
        NProgress.start(); NProgress.set(0.2);

        const qs = new URLSearchParams({
          from: this.fmt(this.dateRange.startDate),
          to:   this.fmt(this.dateRange.endDate),
          warehouse_id: this.warehouse_id || '',
          page: '1',
          limit: '100000',
          SortField: this.serverParams?.sort?.field || 'value_sum',
          SortType:  this.serverParams?.sort?.type  || 'desc',
          search: this.search || ''
        }).toString();

        const { data } = await axios.get(`report/top_suppliers?${qs}`).catch(()=>({data:{}}));
        const d = data?.data || {};
        const items = Array.isArray(d.rows) ? d.rows : [];
        const k = d.kpis || this.kpis;

        const doc = new jsPDF({ orientation:'landscape', unit:'pt', format:'a4' });
        const fontPath = "/fonts/Vazirmatn-Bold.ttf";
        try {
          doc.addFont(fontPath, "Vazirmatn", "normal");
          doc.addFont(fontPath, "Vazirmatn", "bold");
        } catch(_) { /* ignore if already added */ }
        doc.setFont("Vazirmatn", "normal");

        const rtl =
          (this.$i18n && ['ar','fa','ur','he'].includes(this.$i18n.locale)) ||
          (typeof document !== 'undefined' && document.documentElement.dir === 'rtl');

        const pageW   = doc.internal.pageSize.getWidth();
        const marginX = 40;

        const title = 'Top Suppliers Report';
        const range = `${this.fmt(this.dateRange.startDate)} — ${this.fmt(this.dateRange.endDate)}`;
        const whText = (() => {
          const id = this.warehouse_id;
          if (!id) return this.$t('All');
          const w = (this.warehouses||[]).find(x => String(x.id) === String(id));
          return w ? (w.name || `#${id}`) : `#${id}`;
        })();

        doc.setFont("Vazirmatn","bold"); doc.setFontSize(14);
        rtl ? doc.text(title, pageW - marginX, 40, { align:'right' })
            : doc.text(title, marginX, 40);

        doc.setFont("Vazirmatn","normal"); doc.setFontSize(10);
        const line1 = `${this.$t('DateRange')}: ${range}   •   ${this.$t('warehouse')}: ${whText}`;
        const line2 = `${this.$t('Vendors')}: ${k.vendors_count}   •   ${this.$t('Purchases')}: ${k.total_purchases}   •   ${this.$t('QtyPurchased')}: ${Number(k.total_qty||0).toLocaleString()}   •   ${this.$t('TotalSpend')}: ${this.money(k.total_spend)}`;

        rtl ? doc.text(line1, pageW - marginX, 58, { align:'right' })
            : doc.text(line1, marginX, 58);
        rtl ? doc.text(line2, pageW - marginX, 74, { align:'right' })
            : doc.text(line2, marginX, 74);

        const head = [[
          this.$t('Supplier'),
          this.$t('Purchases'),
          this.$t('QtyPurchased'),
          this.$t('TotalSpend'),
          this.$t('AvgPerPurchase')
        ]];

        const body = items.map(r => [
          r.supplier || '',
          Number(r.orders_count||0).toLocaleString(),
          Number(r.qty_sum||0).toLocaleString(undefined,{maximumFractionDigits:2}),
          this.money(r.value_sum),
          this.money(r.avg_value),
        ]);

        const tPurchases = items.reduce((a,b)=>a+Number(b.orders_count||0),0);
        const tQty       = items.reduce((a,b)=>a+Number(b.qty_sum||0),0);
        const tValue     = items.reduce((a,b)=>a+Number(b.value_sum||0),0);

        autoTable(doc, {
          startY: 90,
          head, body,
          styles: { font: "Vazirmatn", fontSize: 9, cellPadding: 6, halign: rtl ? 'right' : 'left' },
          headStyles: { font: "Vazirmatn", fontStyle: "bold", fillColor: [26,86,219], textColor: 255, halign: rtl ? 'right' : 'left' },
          columnStyles: { 1:{halign:'right'}, 2:{halign:'right'}, 3:{halign:'right'}, 4:{halign:'right'} },
          foot: [[
            { content: this.$t('Totals'), styles:{ font: "Vazirmatn", fontStyle:'bold' } },
            { content: tPurchases.toLocaleString(), styles:{ halign:'right', fontStyle:'bold' } },
            { content: tQty.toLocaleString(undefined,{maximumFractionDigits:2}), styles:{ halign:'right', fontStyle:'bold' } },
            { content: this.money(tValue), styles:{ halign:'right', fontStyle:'bold' } },
            ''
          ]],
          margin: { left: marginX, right: marginX }
        });

        doc.save(`top-suppliers_${this.fmt(this.dateRange.startDate)}_${this.fmt(this.dateRange.endDate)}.pdf`);
      } finally {
        NProgress.done();
      }
    },

    //------ Print Table Only
    printTableOnly() {
      const rowsData = this.rows || [];

      let tableHtml = `<table class="vgt-table table table-hover tableOne">`;
      tableHtml += `<thead><tr>`;
      this.columns.forEach(col => {
        tableHtml += `<th class="text-left">${col.label}</th>`;
      });
      tableHtml += `</tr></thead>`;
      tableHtml += `<tbody>`;
      rowsData.forEach(row => {
        tableHtml += `<tr>`;
        this.columns.forEach(col => {
          let cellContent = '';
          if (col.key === 'value_sum' || col.key === 'avg_value') {
            cellContent = this.money(row[col.key]);
          } else if (col.key === 'qty_sum') {
            cellContent = this.formatQty(row.qty_sum);
          } else {
            cellContent = row[col.key] || '';
          }
          tableHtml += `<td class="text-left">${cellContent}</td>`;
        });
        tableHtml += `</tr>`;
      });
      tableHtml += `</tbody>`;

      const totalPurchases = rowsData.reduce((sum, row) => sum + parseFloat(row.orders_count || 0), 0);
      const totalQty = rowsData.reduce((sum, row) => sum + parseFloat(row.qty_sum || 0), 0);
      const totalValue = rowsData.reduce((sum, row) => sum + parseFloat(row.value_sum || 0), 0);

      tableHtml += `<tfoot><tr>`;
      tableHtml += `<td class="text-left font-weight-bold">${this.$t('Totals')}</td>`;
      tableHtml += `<td class="text-left font-weight-bold">${totalPurchases.toLocaleString()}</td>`;
      tableHtml += `<td class="text-left font-weight-bold">${this.formatQty(totalQty)}</td>`;
      tableHtml += `<td class="text-left font-weight-bold">${this.money(totalValue)}</td>`;
      tableHtml += `<td></td>`;
      tableHtml += `</tr></tfoot>`;
      tableHtml += `</table>`;

      const w = window.open("", "_blank");
      if (!w) {
        window.print();
        return;
      }

      const title = `${this.$t("Reports")} / ${this.$t("Top_Suppliers_Report")}`;
      const dateRangeText = `${this.fmt(this.dateRange.startDate)} — ${this.fmt(this.dateRange.endDate)}`;
      const warehouseText = (() => {
        const id = this.warehouse_id;
        if (!id) return this.$t('All');
        const w = (this.warehouses || []).find(x => String(x.id) === String(id));
        return w ? (w.name || `#${id}`) : `#${id}`;
      })();

      const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
        .map(l => l.outerHTML)
        .join("\n");

      const inlineStyles = Array.from(document.querySelectorAll("style"))
        .filter(s => !((s.textContent || "").includes("@media print")))
        .map(s => s.outerHTML)
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
    ${inlineStyles}
    <style>
      @media print { body, body * { visibility: visible !important; } }
      body { margin: 0.3cm; }
      .print-header { font-weight: 600; margin-bottom: 8px; }
      .print-meta { font-size: 10px; margin-bottom: 15px; color: #666; }
      table { width: 100%; border-collapse: collapse; }
      th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
      th { background-color: #f2f2f2; }
    </style>
  </head>
  <body>
    <div class="print-header">${title}</div>
    <div class="print-meta">
      ${dateRangeText} | ${this.$t('warehouse')}: ${warehouseText}
    </div>
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
  },
  created(){ this.fetchReport(); },
  mounted(){
    this.handleResize();
    window.addEventListener('resize', this.handleResize);
  },
  beforeDestroy(){
    window.removeEventListener('resize', this.handleResize);
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
.pxrp__kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-5); margin-top: var(--pxn-space-5); }
@media (max-width: 900px) { .pxrp__kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 480px) { .pxrp__kpis { grid-template-columns: minmax(0, 1fr); } }
.pxrp__cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); }
.pxrp__cols--87 { grid-template-columns: 2fr 1fr; }
@media (max-width: 900px) { .pxrp__cols, .pxrp__cols--87 { grid-template-columns: minmax(0, 1fr); } }
.pxrp__gap { margin-top: var(--pxn-space-5); margin-bottom: var(--pxn-space-5); }
.pxrp__tablewrap { margin-top: var(--pxn-space-5); }
.pxrp__totalrow { display: flex; align-items: center; justify-content: flex-end; gap: var(--pxn-space-6); margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); flex-wrap: wrap; }
.pxrp__totalrow > span:first-child { margin-right: auto; font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxrp ::v-deep .daterangepicker { z-index: 2055 !important; }
</style>
