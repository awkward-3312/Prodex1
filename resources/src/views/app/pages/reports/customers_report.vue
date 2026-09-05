<template>
  <div class="px-next pxrl">
    <px-page-header :title="$t('CustomersReport')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('CustomersReport') }]">
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
      <px-skeleton variant="table" :rows="10" :columns="7" />
    </div>

    <template v-else>
      <div class="pxrl__tablewrap">
        <px-table
          v-if="clients.length"
          :columns="columns"
          :rows="clients"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-total_amount="{ row }"><span class="pxn-num">{{ formatNumber(row.total_amount || 0, priceDecimals) }}</span></template>
          <template #cell-total_paid="{ row }"><span class="pxn-num">{{ formatNumber(row.total_paid || 0, priceDecimals) }}</span></template>
          <template #cell-due="{ row }"><span class="pxn-num">{{ formatNumber(row.due || 0, priceDecimals) }}</span></template>
          <template #cell-return_Due="{ row }"><span class="pxn-num">{{ formatNumber(row.return_Due || 0, priceDecimals) }}</span></template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state v-else icon="users" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
      </div>

      <div v-if="clients.length" class="pxrl__totalrow">
        <span>{{ $t('Total') }}</span>
        <span>{{ $t('Amount') }}: <b class="pxn-num">{{ formatNumber(sum('total_amount'), priceDecimals) }}</b></span>
        <span>{{ $t('Paid') }}: <b class="pxn-num">{{ formatNumber(sum('total_paid'), priceDecimals) }}</b></span>
        <span>{{ $t('Total_Sale_Due') }}: <b class="pxn-num">{{ formatNumber(sum('due'), priceDecimals) }}</b></span>
        <span>{{ $t('Total_Sell_Return_Due') }}: <b class="pxn-num">{{ formatNumber(sum('return_Due'), priceDecimals) }}</b></span>
      </div>

      <px-pagination
        v-if="clients.length"
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
import { mapGetters } from "vuex";
import { getPriceDecimals } from "../../../../utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  metaInfo: {
    title: "Report Customers"
  },
  components: {
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxKebab, PxEmptyState
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
      clients: [],
      client: {},
      rows: [{
          total_sales: 'Total',
          children: [
          ],
      },],
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    rowActions() {
      return [
        { key: "pdf", label: "PDF", icon: "file-text" },
        { key: "detail", label: this.$t("Report") || "Reporte", icon: "eye" }
      ];
    },
    columns() {
      return [
        { key: "name", label: this.$t("CustomerName"), strong: true },
        { key: "phone", label: this.$t("Phone") },
        { key: "total_sales", label: this.$t("TotalSales"), align: "right" },
        { key: "total_amount", label: this.$t("Amount"), align: "right" },
        { key: "total_paid", label: this.$t("Paid"), align: "right" },
        { key: "due", label: this.$t("Total_Sale_Due"), align: "right" },
        { key: "return_Due", label: this.$t("Total_Sell_Return_Due"), align: "right" },
      ];
    }
  },

  methods: {
    sum(field) {
      return (this.clients || []).reduce((acc, r) => acc + (typeof r[field] === 'number' ? r[field] : (parseFloat(r[field]) || 0)), 0);
    },

    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "pdf") this.Download_PDF(row, row.id);
      else if (k === "detail") this.$router.push('/app/reports/detail_customer/' + row.id);
    },

     //--------------------------- Download_PDF-------------------------------\\
    Download_PDF(client , id) {
      NProgress.start();
      NProgress.set(0.1);

       axios
        .get("report/client_pdf/" + id, {
          responseType: "blob",
          headers: {
            "Content-Type": "application/json"
          }
        })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute("download", "report-" + client.name + ".pdf");
          document.body.appendChild(link);
          link.click();
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(() => {
          setTimeout(() => NProgress.done(), 500);
        });
    },

    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Client_Report(1); }, 350);
    },

    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Client_Report(p); } },

    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Client_Report(1); } },

    onSort({ key, dir }) {
      this.updateParams({ sort: { type: dir, field: key } });
      this.Get_Client_Report(this.serverParams.page);
    },

    //------------------------------Formetted Numbers -------------------------\\
    formatNumber(number, dec) {
      const value = (typeof number === "string"
        ? number
        : Number(number || 0).toString()
      ).split(".");
      if (dec <= 0) return value[0];
      let formated = value[1] || "";
      if (formated.length > dec)
        return `${value[0]}.${formated.substr(0, dec)}`;
      while (formated.length < dec) formated += "0";
      return `${value[0]}.${formated}`;
    },

    //------ Print Table Only - Print ALL customers data with all columns
    printTableOnly() {
      const title = `${this.$t("Reports")} / ${this.$t("CustomersReport")}`;
      const clients = Array.isArray(this.rows[0]?.children) ? this.rows[0].children : [];

      let tableHTML = '<table style="width: 100%; border-collapse: collapse; font-size: 10px;">';
      tableHTML += '<thead><tr>';

      this.columns.forEach(col => {
        tableHTML += `<th style="border: 1px solid #ddd; padding: 6px 8px; background-color: #f5f5f5; font-weight: bold; text-align: left;">${col.label}</th>`;
      });
      tableHTML += '</tr></thead><tbody>';

      clients.forEach(client => {
        tableHTML += '<tr>';
        this.columns.forEach(col => {
          let cellValue = '';

          if (col.key === 'name') {
            cellValue = client.name || '';
          } else if (col.key === 'phone') {
            cellValue = client.phone || '';
          } else if (col.key === 'total_sales') {
            cellValue = client.total_sales || 0;
          } else if (col.key === 'total_amount' || col.key === 'total_paid' || col.key === 'due' || col.key === 'return_Due') {
            cellValue = this.formatNumber(client[col.key] || 0, this.priceDecimals);
          } else {
            cellValue = client[col.key] || '';
          }

          tableHTML += `<td style="border: 1px solid #ddd; padding: 6px 8px; text-align: left;">${cellValue}</td>`;
        });
        tableHTML += '</tr>';
      });

      tableHTML += '</tbody></table>';

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

    //--------------------------- Get Customer Report -------------\\
    Get_Client_Report(page) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "report/client?page=" +
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
          this.clients = response.data.report;
          this.totalRows = response.data.totalRows;
          this.rows[0].children = this.clients;
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
    this.Get_Client_Report(1);

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
