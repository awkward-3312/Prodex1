<template>
  <div>
    <b-row class="mb-4" v-if="stats">
      <b-col md="4" class="mb-2">
        <b-card class="stat-card text-center">
          <h3 class="mb-0">{{ stats.products_total }}</h3>
          <small class="text-muted">{{ $t('Total_Products') }}</small>
        </b-card>
      </b-col>
      <b-col md="4" class="mb-2">
        <b-card class="stat-card text-center">
          <h3 class="mb-0 text-success">{{ stats.products_mapped }}</h3>
          <small class="text-muted">{{ $t('Synced_with_Shopify') }}</small>
        </b-card>
      </b-col>
      <b-col md="4" class="mb-2">
        <b-card class="stat-card text-center">
          <h3 class="mb-0 text-warning">{{ Math.max(0, stats.products_total - stats.products_mapped) }}</h3>
          <small class="text-muted">{{ $t('Not_synced_yet') }}</small>
        </b-card>
      </b-col>
    </b-row>

    <b-card class="sync-card shadow-sm mb-4">
      <template #header>
        <div class="d-flex align-items-center">
          <lucide-icon class="mr-2 text-primary" name="upload" />
          <h5 class="mb-0 font-weight-bold">{{ $t('Push_products_to_Shopify') }}</h5>
        </div>
      </template>
      <p class="text-muted">{{ $t('Push_products_help') }}</p>
      <b-form-checkbox v-model="onlyUnsynced" class="mb-3" :disabled="running">
        {{ $t('Only_products_not_synced_yet') }}
      </b-form-checkbox>
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
          <h5 class="mb-0 font-weight-bold">{{ $t('Pull_products_from_Shopify') }}</h5>
        </div>
      </template>
      <p class="text-muted">{{ $t('Pull_products_help') }}</p>
      <b-button variant="info" :disabled="running" @click="pull">
        <lucide-icon class="mr-1" name="download" /> {{ $t('Pull_from_Shopify') }}
      </b-button>
      <b-button variant="outline-warning" class="ml-2" :disabled="running" @click="resetMappings">
        {{ $t('Reset_product_mappings') }}
      </b-button>
    </b-card>

    <b-card v-if="running || hasCounters" class="sync-card shadow-sm">
      <div class="d-flex align-items-center mb-2" v-if="running">
        <span class="mini-spinner mr-2"></span>
        <strong>{{ $t('Sync_in_progress') }}</strong>
      </div>
      <div class="d-flex flex-wrap">
        <b-badge v-for="(value, key) in counters" :key="key" variant="light" class="mr-2 mb-2 px-3 py-2 counter-badge">
          {{ $t(counterLabel(key)) }}: <strong>{{ value }}</strong>
        </b-badge>
      </div>
      <div v-if="batchErrors.length" class="mt-2">
        <b-alert show variant="warning" class="mb-0">
          <strong>{{ $t('Errors') }} ({{ batchErrors.length }})</strong>
          <ul class="mb-0 mt-2 pl-3">
            <li v-for="(err, i) in batchErrors.slice(0, 10)" :key="i">
              {{ err.name || err.sku || err.product_id || err.shopify_product_id }} — {{ err.error }}
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
    return {
      stats: null,
      onlyUnsynced: true,
    };
  },
  watch: {
    'store.id'() { this.loadStats(); },
  },
  methods: {
    counterLabel(key) {
      return {
        processed: 'Processed', created: 'Created', updated: 'Updated',
        failed: 'Failed', skipped: 'Skipped', imported: 'Imported',
      }[key] || key;
    },
    loadStats() {
      axios.get('shopify/stores/' + this.store.id + '/stats').then(({ data }) => {
        this.stats = data.stats;
      }).catch(() => { this.stats = null; });
    },
    push() {
      this.runSyncLoop('shopify/sync/products?mode=push', { only_unsynced: this.onlyUnsynced }, 'push')
        .then(() => this.loadStats());
    },
    pull() {
      this.runSyncLoop('shopify/sync/products?mode=pull', {}, 'pull')
        .then(() => this.loadStats());
    },
    resetMappings() {
      this.$swal({
        title: this.$t('Delete_Title'),
        text: this.$t('Reset_mappings_warning'),
        type: 'warning',
        icon: 'warning',
        showCancelButton: true,
      }).then(result => {
        if (!result.value) return;
        axios.post('shopify/stores/' + this.store.id + '/reset-mappings', { entity_type: 'product' }).then(() => {
          this.notify('success', this.$t('Successfully_Updated'));
          this.loadStats();
        }).catch(() => this.notify('danger', this.$t('InvalidData')));
      });
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
