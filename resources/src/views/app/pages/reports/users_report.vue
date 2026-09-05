<template>
  <div class="px-next pxrl">
    <px-page-header :title="$t('Users_Report')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('Users_Report') }]">
      <template #actions>
        <px-button variant="secondary" size="sm" icon="printer" @click="printTableOnly()">{{ $t('print') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxrl__pad">
      <px-skeleton variant="table" :rows="10" :columns="8" />
    </div>

    <template v-else>
      <div class="pxrl__tablewrap">
        <px-table
          v-if="users.length"
          :columns="columns"
          :rows="users"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-total_sales="{ row }"><span class="pxn-num">{{ num(row.total_sales) }}</span></template>
          <template #cell-total_purchases="{ row }"><span class="pxn-num">{{ num(row.total_purchases) }}</span></template>
          <template #cell-total_quotations="{ row }"><span class="pxn-num">{{ num(row.total_quotations) }}</span></template>
          <template #cell-total_return_sales="{ row }"><span class="pxn-num">{{ num(row.total_return_sales) }}</span></template>
          <template #cell-total_return_purchases="{ row }"><span class="pxn-num">{{ num(row.total_return_purchases) }}</span></template>
          <template #cell-total_transfers="{ row }"><span class="pxn-num">{{ num(row.total_transfers) }}</span></template>
          <template #cell-total_adjustments="{ row }"><span class="pxn-num">{{ num(row.total_adjustments) }}</span></template>
          <template #row-actions="{ row }">
            <px-button variant="ghost" size="sm" icon="bar-chart-3" @click="$router.push('/app/reports/detail_user/' + row.id)">{{ $t('Reports') }}</px-button>
          </template>
        </px-table>

        <px-empty-state v-else icon="users" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
      </div>

      <div v-if="users.length" class="pxrl__totalrow">
        <span>{{ $t('Total') }}</span>
        <span>{{ $t('TotalSales') }}: <b class="pxn-num">{{ num(sum('total_sales')) }}</b></span>
        <span>{{ $t('TotalPurchases') }}: <b class="pxn-num">{{ num(sum('total_purchases')) }}</b></span>
        <span>{{ $t('Total_quotations') }}: <b class="pxn-num">{{ num(sum('total_quotations')) }}</b></span>
        <span>{{ $t('Total_return_sales') }}: <b class="pxn-num">{{ num(sum('total_return_sales')) }}</b></span>
        <span>{{ $t('Total_return_purchases') }}: <b class="pxn-num">{{ num(sum('total_return_purchases')) }}</b></span>
        <span>{{ $t('Total_transfers') }}: <b class="pxn-num">{{ num(sum('total_transfers')) }}</b></span>
        <span>{{ $t('Total_adjustments') }}: <b class="pxn-num">{{ num(sum('total_adjustments')) }}</b></span>
      </div>

      <px-pagination
        v-if="users.length"
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
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  metaInfo: {
    title: "Report Users"
  },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxEmptyState
  },
  data() {
    return {
      _searchTimer: null,
      isLoading: true,
      serverParams: {
        sort: {
          field: "id",
          type: "desc"
        },
        page: 1,
        perPage: 10
      },
      limit: "10",
      search: "",
      totalRows: "",
      users: [],
      rows: [{
        statut: '',
        children: [],
      }],
      user: {}
    };
  },

  computed: {
    columns() {
      return [
        { key: "username", label: this.$t("username"), strong: true },
        { key: "total_sales", label: this.$t("TotalSales"), align: "right", sortable: false },
        { key: "total_purchases", label: this.$t("TotalPurchases"), align: "right", sortable: false },
        { key: "total_quotations", label: this.$t("Total_quotations"), align: "right", sortable: false },
        { key: "total_return_sales", label: this.$t("Total_return_sales"), align: "right", sortable: false },
        { key: "total_return_purchases", label: this.$t("Total_return_purchases"), align: "right", sortable: false },
        { key: "total_transfers", label: this.$t("Total_transfers"), align: "right", sortable: false },
        { key: "total_adjustments", label: this.$t("Total_adjustments"), align: "right", sortable: false },
      ];
    }
  },

  methods: {
    sum(field) {
      return (this.users || []).reduce((acc, r) => acc + (Number(r[field]) || 0), 0);
    },
    num(v) {
      const n = Number(v) || 0;
      return n.toLocaleString();
    },

    //---- update Params Table
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Users_Report(1); }, 350);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Users_Report(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Users_Report(1); } },

    onSort({ key, dir }) {
      this.updateParams({ sort: { type: dir, field: key } });
      this.Get_Users_Report(this.serverParams.page);
    },

    //------------------------------Formetted Numbers -------------------------\\
    formatNumber(number, dec) {
      const value = (typeof number === "string"
        ? number
        : number.toString()
      ).split(".");
      if (dec <= 0) return value[0];
      let formated = value[1] || "";
      if (formated.length > dec)
        return `${value[0]}.${formated.substr(0, dec)}`;
      while (formated.length < dec) formated += "0";
      return `${value[0]}.${formated}`;
    },

    //------ Print Table Only
    printTableOnly() {
      const title = `${this.$t("Reports")} / ${this.$t("Users_Report")}`;
      const rowsData = (this.rows && this.rows[0] && this.rows[0].children) ? this.rows[0].children : (this.users || []);

      let tableHTML = '<table style="width: 100%; border-collapse: collapse; font-size: 10px;">';
      tableHTML += '<thead><tr>';
      this.columns.forEach(col => {
        tableHTML += `<th style="border: 1px solid #ddd; padding: 6px 8px; background-color: #f5f5f5; font-weight: bold; text-align: left;">${col.label}</th>`;
      });
      tableHTML += '</tr></thead><tbody>';

      rowsData.forEach(row => {
        tableHTML += '<tr>';
        this.columns.forEach(col => {
          const cellContent = row[col.key] != null ? row[col.key] : '';
          tableHTML += `<td style="border: 1px solid #ddd; padding: 6px 8px; text-align: left;">${cellContent}</td>`;
        });
        tableHTML += '</tr>';
      });
      tableHTML += '</tbody>';

      tableHTML += '<tfoot><tr>';
      tableHTML += `<td style="border: 1px solid #ddd; padding: 6px 8px; font-weight: bold;">${this.$t('Total')}</td>`;
      ['total_sales','total_purchases','total_quotations','total_return_sales','total_return_purchases','total_transfers','total_adjustments'].forEach(field => {
        tableHTML += `<td style="border: 1px solid #ddd; padding: 6px 8px; font-weight: bold;">${this.sum(field).toLocaleString()}</td>`;
      });
      tableHTML += '</tr></tfoot>';
      tableHTML += '</table>';

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
        @page { size: A4 landscape; margin: 0.3cm; }
      }
      body { margin: 0.3cm; font-family: Arial, sans-serif; }
      .print-header { font-weight: 600; margin-bottom: 10px; font-size: 14px; }
      table { width: 100%; border-collapse: collapse; }
      th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; font-size: 10px; }
      th { background-color: #f5f5f5; font-weight: bold; }
      tr:nth-child(even) { background-color: #f9f9f9; }
    </style>
  </head>
  <body>
    <div class="print-header">${title}</div>
    ${tableHTML}
  </body>
</html>`);
      doc.close();

      w.focus();
      setTimeout(() => {
        w.print();
        w.close();
      }, 400);
    },

    //--------------------------- Get Users Report -------------\\
    Get_Users_Report(page) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "report/users?page=" +
            page +
            "&SortField=" +
            this.serverParams.sort.field +
            "&SortType=" +
            this.serverParams.sort.type +
            "&search=" +
            this.search +
            "&limit=" +
            this.limit
        )
        .then(response => {
          this.users = response.data.report;
          this.totalRows = response.data.totalRows;
          this.rows[0].children = this.users;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(response => {
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    }
  }, //end Methods

  //----------------------------- Created function------------------- \\

  created: function() {
    this.Get_Users_Report(1);
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrl__pad { padding: var(--pxn-space-6) 0; }
.pxrl__tablewrap { margin-top: var(--pxn-space-5); }
.pxrl__totalrow { display: flex; align-items: center; justify-content: flex-end; gap: var(--pxn-space-6); margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); flex-wrap: wrap; }
.pxrl__totalrow > span:first-child { margin-right: auto; font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
</style>
