<template>
  <div>
    <b-row class="mb-4" v-if="stats">
      <b-col md="4" class="mb-2">
        <b-card class="stat-card text-center">
          <h3 class="mb-0">{{ stats.customers_total }}</h3>
          <small class="text-muted">{{ $t('Total_Customers') }}</small>
        </b-card>
      </b-col>
      <b-col md="4" class="mb-2">
        <b-card class="stat-card text-center">
          <h3 class="mb-0 text-success">{{ stats.customers_mapped }}</h3>
          <small class="text-muted">{{ $t('Synced_with_Shopify') }}</small>
        </b-card>
      </b-col>
      <b-col md="4" class="mb-2">
        <b-card class="stat-card text-center">
          <h3 class="mb-0 text-warning">{{ Math.max(0, stats.customers_total - stats.customers_mapped) }}</h3>
          <small class="text-muted">{{ $t('Not_synced_yet') }}</small>
        </b-card>
      </b-col>
    </b-row>

    <b-card class="sync-card shadow-sm mb-4">
      <template #header>
        <div class="d-flex align-items-center">
          <lucide-icon class="mr-2 text-primary" name="upload" />
          <h5 class="mb-0 font-weight-bold">{{ $t('Push_customers_to_Shopify') }}</h5>
        </div>
      </template>
      <p class="text-muted">{{ $t('Push_customers_help') }}</p>
      <b-button variant="primary" :disabled="running" @click="push">
        <lucide-icon class="mr-1" name="upload" /> {{ $t('Push_to_Shopify') }}
      </b-button>
      <b-button v-if="running" variant="outline-danger" class="ml-2" @click="cancelRun">
        {{ $t('Cancel') }}
      </b-button>
    </b-card>

    <b-card class="sync-card shadow-sm mb-4">
      <template #header>
        <div class="d-flex align-items-center">
          <lucide-icon class="mr-2 text-info" name="download" />
          <h5 class="mb-0 font-weight-bold">{{ $t('Pull_customers_from_Shopify') }}</h5>
        </div>
      </template>
      <p class="text-muted">{{ $t('Pull_customers_help') }}</p>
      <b-button variant="info" :disabled="running" @click="pull">
        <lucide-icon class="mr-1" name="download" /> {{ $t('Pull_from_Shopify') }}
      </b-button>
    </b-card>

    <b-card v-if="running || hasCounters" class="sync-card shadow-sm">
      <div class="d-flex align-items-center mb-2" v-if="running">
        <span class="mini-spinner mr-2"></span>
        <strong>{{ $t('Sync_in_progress') }}</strong>
      </div>
      <div class="d-flex flex-wrap">
        <b-badge v-for="(value, key) in counters" :key="key" variant="light" class="mr-2 mb-2 px-3 py-2 counter-badge">
          {{ key }}: <strong>{{ value }}</strong>
        </b-badge>
      </div>
      <div v-if="batchErrors.length" class="mt-2">
        <b-alert show variant="warning" class="mb-0">
          <strong>{{ $t('Errors') }} ({{ batchErrors.length }})</strong>
          <ul class="mb-0 mt-2 pl-3">
            <li v-for="(err, i) in batchErrors.slice(0, 10)" :key="i">
              {{ err.client_id || err.shopify_customer_id }} — {{ err.error }}
            </li>
          </ul>
        </b-alert>
      </div>
    </b-card>
  </div>
</template>

<script>
import syncRunner from './syncRunner';

export default {
  mixins: [syncRunner],
  props: { store: { type: Object, required: true } },
  data() {
    return { stats: null };
  },
  watch: {
    'store.id'() { this.loadStats(); },
  },
  methods: {
    loadStats() {
      axios.get('shopify/stores/' + this.store.id + '/stats').then(({ data }) => {
        this.stats = data.stats;
      }).catch(() => { this.stats = null; });
    },
    push() {
      this.runSyncLoop('shopify/sync/customers?mode=push', {}, 'push').then(() => this.loadStats());
    },
    pull() {
      this.runSyncLoop('shopify/sync/customers?mode=pull', {}, 'pull').then(() => this.loadStats());
    },
  },
  created() {
    this.loadStats();
    this.$emit('ready');
  }
};
</script>

<style scoped>
.sync-card, .stat-card {
  border-radius: 12px;
  border: none;
}

.sync-card ::v-deep .card-header {
  background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
  border-bottom: 2px solid #e9ecef;
  padding: 1rem 1.5rem;
  border-radius: 12px 12px 0 0;
}

.counter-badge {
  font-size: 13px;
  border: 1px solid #e9ecef;
}

.mini-spinner {
  display: inline-block;
  width: 16px;
  height: 16px;
  border: 2px solid rgba(94, 142, 62, 0.2);
  border-top-color: #5e8e3e;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
