<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('System_Health')"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('System_Health') }]"
    >
      <template #actions>
        <px-button variant="secondary" size="sm" icon="refresh-cw" :loading="refreshing" @click="refresh">
          {{ refreshing ? $t('Refreshing') + '...' : $t('Refresh') }}
        </px-button>
        <px-button variant="primary" size="sm" icon="download" :loading="pdfLoading" @click="downloadPdf">
          {{ pdfLoading ? $t('Loading') + '...' : $t('Download_Report_PDF') }}
        </px-button>
      </template>
      <template #meta>
        <span v-if="generatedAt">{{ $t('Last_updated') }}: {{ generatedAt }}</span>
      </template>
    </px-page-header>

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="6" />
    </div>

    <template v-else>
      <div class="pxcfg__stats">
        <px-stat icon="code" :label="$t('PHP_Version')" :value="metrics.php_version || '—'" bordered />
        <px-stat icon="server" :label="$t('Laravel_Version')" :value="metrics.laravel_version || '—'" bordered />
        <px-stat icon="activity" :label="$t('Environment')" :value="(metrics.environment || '—')" bordered />
        <px-stat icon="database" :label="$t('Database_Size')" :value="(metrics.database && metrics.database.size_human) || (metrics.database && metrics.database.error) || '—'" bordered />
        <px-stat icon="hard-drive" :label="$t('Storage_Usage')" :value="(metrics.storage && metrics.storage.size_human) || '—'" bordered />
        <px-stat icon="activity" :label="$t('Queue_Status')" :value="queueLabel" bordered />
        <px-stat icon="database-backup" :label="$t('Last_Backup_Date')" :value="(metrics.last_backup && metrics.last_backup.human) || (metrics.last_backup && metrics.last_backup.message) || '—'" bordered />
      </div>
    </template>
  </div>
</template>

<script>
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxStat from "@/components/px-next/PxStat.vue";

export default {
  metaInfo: {
    title: 'System Health'
  },
  components: { PxPageHeader, PxButton, PxStat },
  data() {
    return {
      isLoading: true,
      refreshing: false,
      pdfLoading: false,
      metrics: {},
      generatedAt: null
    };
  },
  computed: {
    queueLabel() {
      const q = this.metrics.queue;
      if (!q) return '—';
      let out = q.driver || '';
      if (q.pending !== undefined) out += ` — Pending: ${q.pending}`;
      if (q.failed !== undefined) out += `, Failed: ${q.failed}`;
      if (q.error) out += ` (${q.error})`;
      return out || '—';
    }
  },
  created() {
    this.fetchMetrics();
  },
  methods: {
    fetchMetrics() {
      axios
        .get('system_health')
        .then(response => {
          if (response.data && response.data.success && response.data.data) {
            this.metrics = response.data.data;
            this.generatedAt = response.data.generated_at || null;
          }
        })
        .catch(error => {
          const msg = (error.response && error.response.data && (error.response.data.message || error.response.data.error)) || this.$t('InvalidData');
          this.makeToast('danger', msg, this.$t('Failed'));
        })
        .finally(() => {
          this.isLoading = false;
          this.refreshing = false;
        });
    },
    refresh() {
      this.refreshing = true;
      this.fetchMetrics();
    },
    downloadPdf() {
      this.pdfLoading = true;
      axios
        .get('system_health/pdf', { responseType: 'blob' })
        .then(response => {
          const blob = new Blob([response.data], { type: 'application/pdf' });
          const url = window.URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = url;
          a.download = 'system-health-report.pdf';
          document.body.appendChild(a);
          a.click();
          window.URL.revokeObjectURL(url);
          document.body.removeChild(a);
          this.makeToast('success', this.$t('Success'), this.$t('Success'));
        })
        .catch(error => {
          const msg = (error.response && error.response.data) ? (typeof error.response.data === 'string' ? error.response.data : (error.response.data.message || error.response.data.error)) : this.$t('InvalidData');
          this.makeToast('danger', msg || this.$t('Failed'), this.$t('Failed'));
        })
        .finally(() => {
          this.pdfLoading = false;
        });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-4); margin-top: var(--pxn-space-5); }
@media (max-width: 900px) { .pxcfg__stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxcfg__stats { grid-template-columns: minmax(0, 1fr); } }
</style>
