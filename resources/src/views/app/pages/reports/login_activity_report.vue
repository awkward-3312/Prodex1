<template>
  <div class="px-next pxrl">
    <px-page-header :title="$t('Login_Activity_Report')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('Login_Activity_Report') }]">
      <template #actions>
        <px-button variant="secondary" size="sm" icon="printer" @click="printTableOnly()">{{ $t('print') }}</px-button>
        <px-button variant="primary" size="sm" icon="refresh-cw" :loading="isLoading" @click="LoadLoginActivity(serverParams.page)">{{ $t('Refresh') || 'Actualizar' }}</px-button>
      </template>
      <template #meta>
        Actividad histórica de inicio de sesión de tu cuenta (todas las sesiones, incluidas las inactivas).
      </template>
    </px-page-header>

    <div v-if="isLoading" class="pxrl__pad">
      <px-skeleton variant="table" :rows="8" :columns="5" />
    </div>

    <template v-else>
      <div class="pxrl__tablewrap">
        <px-table
          v-if="sessions.length"
          :columns="columns"
          :rows="sessions"
          row-key="__rowkey"
        >
          <template #cell-device="{ row }">
            <span>{{ row.device }}</span>
            <px-badge v-if="row.is_current" tone="success" class="pxrl__mlbadge">{{ $t('Current') || 'Actual' }}</px-badge>
            <px-badge v-else-if="row.is_active" tone="info" class="pxrl__mlbadge">{{ $t('Active') || 'Activa' }}</px-badge>
            <px-badge v-else tone="neutral" class="pxrl__mlbadge">{{ $t('Inactive') || 'Inactiva' }}</px-badge>
          </template>
          <template #cell-ip_address="{ row }">{{ row.ip_address || '-' }}</template>
          <template #cell-login_at="{ row }">{{ formatDateTime(row.login_at) }}</template>
          <template #cell-last_activity_at="{ row }">{{ row.last_activity_at ? formatDateTime(row.last_activity_at) : '-' }}</template>
          <template #cell-status="{ row }">
            <px-badge v-if="row.is_current" tone="success">{{ $t('Current') || 'Actual' }}</px-badge>
            <px-badge v-else-if="row.revoked_at" tone="danger">Cerrada</px-badge>
            <px-badge v-else-if="row.is_active" tone="info">{{ $t('Active') || 'Activa' }}</px-badge>
            <px-badge v-else tone="neutral">Expirada</px-badge>
          </template>
        </px-table>

        <px-empty-state v-else icon="log-in" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
      </div>

      <px-pagination
        v-if="sessions.length"
        :page="serverParams.page"
        :per-page="Number(serverParams.perPage)"
        :total="Number(totalRows) || 0"
        :per-page-options="['10', '20', '50', '100']"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import Util from '../../../../utils';
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  metaInfo: {
    title: "Login Activity Report"
  },
  components: {
    PxPageHeader, PxTable, PxPagination, PxButton, PxBadge, PxEmptyState
  },
  data() {
    return {
      isLoading: true,
      serverParams: {
        sort: {
          field: "login_at",
          type: "desc"
        },
        page: 1,
        perPage: 50
      },
      limit: "50",
      totalRows: 0,
      sessions: [],
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    columns() {
      return [
        { key: "device", label: "Dispositivo / Navegador", sortable: false, strong: true },
        { key: "ip_address", label: "Dirección IP", sortable: false },
        { key: "login_at", label: "Fecha y hora de inicio", sortable: false },
        { key: "last_activity_at", label: "Última actividad", sortable: false },
        { key: "status", label: this.$t("Status") || "Estado", sortable: false }
      ];
    }
  },
  created() {
    // Permission gate (UI). Backend also enforces.
    const perms = this.currentUserPermissions || [];
    const allowed = perms.includes("login_device_management") || perms.includes("setting_system");
    if (!allowed) {
      this.$router.push({ name: "not_authorize" });
      return;
    }
    this.LoadLoginActivity(1);
  },
  methods: {
    formatDateTime(v) {
      try {
        if (!v) return "";
        const d = new Date(v);
        if (isNaN(d.getTime())) return String(v);
        const dateFormat = this.$store.getters.getDateFormat || Util.getDateFormat(this.$store);
        return Util.formatDisplayDate(d.toISOString(), dateFormat);
      } catch (e) {
        return String(v || "");
      }
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },
    onPage(p) {
      if (this.serverParams.page !== p) {
        this.updateParams({ page: p });
        this.LoadLoginActivity(p);
      }
    },
    onLimit(v) {
      const perPage = Number(v);
      this.updateParams({ perPage: perPage, page: 1 });
      this.limit = String(perPage);
      this.LoadLoginActivity(1);
    },
    LoadLoginActivity(page) {
      NProgress.start();
      NProgress.set(0.1);
      this.isLoading = true;

      axios
        .get(
          "security/login-activity-report?page=" +
            page +
            "&limit=" +
            this.limit
        )
        .then(response => {
          const list = (response && response.data && response.data.sessions) ? response.data.sessions : [];
          this.sessions = list.map((s, i) => Object.assign({ __rowkey: s.id != null ? `s-${s.id}` : `r-${i}` }, s));
          this.totalRows = response.data.totalRows || 0;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(error => {
          NProgress.done();
          const msg =
            (error && error.response && error.response.data && (error.response.data.message || error.response.data.error)) ||
            this.$t("Failed");
          this.makeToast("danger", msg, this.$t("Failed"));
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    //------ Print Table Only - Print ALL login activity data with all columns
    printTableOnly() {
      const title = `${this.$t("Reports")} / ${this.$t("Login_Activity_Report")}`;
      const sessions = Array.isArray(this.sessions) ? this.sessions : [];

      let tableHTML = '<table style="width: 100%; border-collapse: collapse; font-size: 10px;">';
      tableHTML += '<thead><tr>';

      this.columns.forEach(col => {
        tableHTML += `<th style="border: 1px solid #ddd; padding: 6px 8px; background-color: #f5f5f5; font-weight: bold; text-align: left;">${col.label}</th>`;
      });
      tableHTML += '</tr></thead><tbody>';

      sessions.forEach(session => {
        tableHTML += '<tr>';
        this.columns.forEach(col => {
          let cellValue = '';

          if (col.key === 'device') {
            let deviceText = session.device || '';
            if (session.is_current) {
              deviceText += ' (Actual)';
            } else if (session.is_active) {
              deviceText += ' (Activa)';
            } else {
              deviceText += ' (Inactiva)';
            }
            cellValue = deviceText;
          } else if (col.key === 'ip_address') {
            cellValue = session.ip_address || '-';
          } else if (col.key === 'login_at') {
            cellValue = this.formatDateTime(session.login_at);
          } else if (col.key === 'last_activity_at') {
            cellValue = session.last_activity_at ? this.formatDateTime(session.last_activity_at) : '-';
          } else if (col.key === 'status') {
            if (session.is_current) {
              cellValue = 'Actual';
            } else if (session.revoked_at) {
              cellValue = 'Cerrada';
            } else if (session.is_active) {
              cellValue = 'Activa';
            } else {
              cellValue = 'Expirada';
            }
          } else {
            cellValue = session[col.key] || '';
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
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxrl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxrl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxrl__pad { padding: var(--pxn-space-6) 0; }
.pxrl__tablewrap { margin-top: var(--pxn-space-5); }
.pxrl__mlbadge { margin-left: var(--pxn-space-3); }
</style>
