<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('WooCommerce_Settings')"
      subtitle="Manage your WooCommerce integration and synchronization"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('WooCommerce_Settings') }]"
    >
      <template #meta>
        <px-badge :tone="connectionTone" :icon="connectionIcon">{{ connectionBadgeText }}</px-badge>
      </template>
    </px-page-header>

    <div v-if="loading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="6" />
    </div>

    <template v-else>
      <div class="pxcfg__tabbar" role="tablist">
        <button
          v-for="(tab, i) in tabs"
          :key="tab.key"
          type="button"
          class="pxcfg__tab"
          :class="{ 'is-active': activeTab === i }"
          @click="selectTab(i)"
        >
          <lucide-icon class="pxcfg__tab-icon" :name="tab.icon" />
          <span>{{ tab.label }}</span>
        </button>
      </div>

      <px-card flush class="pxcfg__card">
        <div class="pxcfg__panel">
          <div v-if="tabLoading" class="pxcfg__pad"><px-skeleton variant="lines" :rows="4" /></div>
          <div v-show="!tabLoading">
            <SettingsTab v-if="activeTab === 0" @ready="onTabReady" @connection="onConnectionUpdate" @updated="onChildRefreshed" />
            <ProductsTab v-else-if="activeTab === 1" @ready="onTabReady" @refreshed="onChildRefreshed" />
            <StockTab v-else-if="activeTab === 2" @ready="onTabReady" @refreshed="onChildRefreshed" @view-logs="switchToLogs" />
            <CategoriesTab v-else-if="activeTab === 3" @ready="onTabReady" @refreshed="onChildRefreshed" />
            <BrandsTab v-else-if="activeTab === 4" @ready="onTabReady" @refreshed="onChildRefreshed" />
            <CustomersTab v-else-if="activeTab === 5" @ready="onTabReady" @refreshed="onChildRefreshed" />
            <OrdersTab v-else-if="activeTab === 6" @ready="onTabReady" />
            <LogsTab v-else-if="activeTab === 7" @ready="onTabReady" />
            <GuideTab v-else-if="activeTab === 8" @ready="onTabReady" />
          </div>
        </div>
      </px-card>
    </template>

    <px-modal :value="!!selectedLog" :title="$t('Log_Details')" size="md" @close="selectedLog = null">
      <div v-if="selectedLog" class="pxcfg__deflist">
        <div class="pxcfg__defrow"><span>{{ $t('date') }}</span><b>{{ formatDate(selectedLog.created_at) }}</b></div>
        <div class="pxcfg__defrow"><span>{{ $t('Action') }}</span><b>{{ selectedLog.action }}</b></div>
        <div class="pxcfg__defrow"><span>{{ $t('Level') }}</span><px-badge :tone="levelTone(selectedLog.level)">{{ selectedLog.level }}</px-badge></div>
        <div class="pxcfg__defrow"><span>{{ $t('Message') }}</span><b>{{ selectedLog.message }}</b></div>
        <h5 class="pxcfg__subhead">{{ $t('Context') }}</h5>
        <pre class="pxcfg__pre">{{ stringify(selectedLog.context) }}</pre>
      </div>
      <template #footer="{ close }">
        <px-button variant="ghost" @click="close">{{ $t('Close') || 'Close' }}</px-button>
      </template>
    </px-modal>
  </div>
</template>

<script>
import moment from 'moment';
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";

export default {
  metaInfo: { title: 'WooCommerce Settings' },
  components: {
    PxPageHeader, PxCard, PxModal, PxButton, PxBadge,
    SettingsTab: () => import(/* webpackChunkName: "woo-settings-tab" */ './woocommerce/SettingsTab.vue'),
    ProductsTab: () => import(/* webpackChunkName: "woo-products-tab" */ './woocommerce/ProductsTab.vue'),
    StockTab: () => import(/* webpackChunkName: "woo-stock-tab" */ './woocommerce/StockTab.vue'),
    CategoriesTab: () => import(/* webpackChunkName: "woo-categories-tab" */ './woocommerce/CategoriesTab.vue'),
    BrandsTab: () => import(/* webpackChunkName: "woo-brands-tab" */ './woocommerce/BrandsTab.vue'),
    CustomersTab: () => import(/* webpackChunkName: "woo-customers-tab" */ './woocommerce/CustomersTab.vue'),
    OrdersTab: () => import(/* webpackChunkName: "woo-orders-tab" */ './woocommerce/OrdersTab.vue'),
    LogsTab: () => import(/* webpackChunkName: "woo-logs-tab" */ './woocommerce/LogsTab.vue'),
    GuideTab: () => import(/* webpackChunkName: "woo-guide-tab" */ './woocommerce/GuideTab.vue'),
  },
  data() {
    return {
      loading: true,
      connectionOk: null,
      totalProducts: null,
      unsyncedCount: null,
      activeTab: 0,
      tabLoading: true,
      selectedLog: null,
    };
  },
  computed: {
    tabs() {
      return [
        { key: 'settings', label: this.$t('Settings'), icon: 'settings' },
        { key: 'products', label: this.$t('Products'), icon: 'barcode' },
        { key: 'stock', label: this.$t('Stock'), icon: 'package' },
        { key: 'categories', label: this.$t('Categories'), icon: 'folder' },
        { key: 'brands', label: this.$t('Brands'), icon: 'tag' },
        { key: 'customers', label: this.$t('Customers'), icon: 'user' },
        { key: 'orders', label: this.$t('Orders'), icon: 'shopping-bag' },
        { key: 'logs', label: this.$t('View_Logs'), icon: 'clipboard-list' },
        { key: 'guide', label: 'Guide', icon: 'help-circle' },
      ];
    },
    connectionTone() {
      if (this.connectionOk === true) return 'success';
      if (this.connectionOk === false) return 'danger';
      return 'neutral';
    },
    connectionBadgeText() {
      if (this.connectionOk === true) return this.$t('Connected');
      if (this.connectionOk === false) return this.$t('Disconnected');
      return this.$t('Unknown');
    },
    connectionIcon() {
      if (this.connectionOk === true) return 'check-circle';
      if (this.connectionOk === false) return 'x-circle';
      return 'help-circle';
    },
  },
  methods: {
    selectTab(i) {
      if (this.activeTab === i) return;
      this.activeTab = i;
      this.onTabChange();
    },
    onTabChange() { this.tabLoading = true; },
    onTabReady() {
      this.tabLoading = false;
    },
    switchToLogs() {
      // Tabs order: Settings=0, Products=1, Stock=2, Categories=3, Brands=4, Customers=5, Orders=6, Logs=7
      this.activeTab = 7;
    },
    fetchCounts() {
      axios.get('products', { params: { limit: 1 } }).then(({ data }) => {
        this.totalProducts = data.totalRows != null ? data.totalRows : null;
      }).catch(() => {
        this.totalProducts = null;
      });

      axios.get('woocommerce/unsynced-count').then(({ data }) => {
        this.unsyncedCount = data.count;
      }).catch(() => {
        this.unsyncedCount = null;
      }).finally(() => {
        this.loading = false;
      });
    },
    testConnection() {
      axios.post('woocommerce/test-connection').then(({ data }) => {
        this.connectionOk = !!data.ok;
      }).catch(() => {
        this.connectionOk = false;
      });
    },
    onConnectionUpdate(val) {
      this.connectionOk = val;
    },
    onChildRefreshed() {
      this.fetchCounts();
      this.testConnection();
    },
    levelTone(level) {
      if (level === 'error') return 'danger';
      if (level === 'warning') return 'warning';
      return 'success';
    },
    formatDate(date) {
      return date ? moment(date).format('YYYY-MM-DD HH:mm') : '';
    },
    stringify(obj) {
      return JSON.stringify(obj, null, 2);
    },
  },
  created() {
    this.fetchCounts();
    this.testConnection();
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6); }
.pxcfg__card { margin-top: var(--pxn-space-4); }
.pxcfg__tabbar {
  display: flex; flex-wrap: wrap; gap: var(--pxn-space-1);
  border-bottom: 1px solid var(--pxn-border);
  margin-top: var(--pxn-space-5);
}
.pxcfg__tab {
  appearance: none; background: transparent; border: 0;
  border-bottom: 2px solid transparent;
  padding: var(--pxn-space-3) var(--pxn-space-4);
  display: inline-flex; align-items: center; gap: var(--pxn-space-2);
  font-size: var(--pxn-fs-sm); font-weight: 500; color: var(--pxn-text-muted);
  cursor: pointer; transition: color .12s ease, border-color .12s ease;
}
.pxcfg__tab:hover { color: var(--pxn-text); }
.pxcfg__tab.is-active { color: var(--pxn-primary); border-bottom-color: var(--pxn-primary); font-weight: 600; }
.pxcfg__tab-icon { width: 15px; height: 15px; }
.pxcfg__panel { padding: var(--pxn-space-5); min-height: 380px; }
.pxcfg__deflist { display: grid; gap: var(--pxn-space-2); }
.pxcfg__defrow { display: grid; grid-template-columns: 120px minmax(0, 1fr); gap: var(--pxn-space-4); align-items: baseline; font-size: var(--pxn-fs-sm); }
.pxcfg__defrow > span { color: var(--pxn-text-muted); text-transform: uppercase; font-size: 11px; letter-spacing: .04em; }
.pxcfg__defrow > b { font-weight: 500; word-break: break-word; }
.pxcfg__subhead { margin: var(--pxn-space-4) 0 var(--pxn-space-2); font-size: var(--pxn-fs-sm); font-weight: 600; }
.pxcfg__pre { background: var(--pxn-surface-2); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); padding: var(--pxn-space-3); white-space: pre-wrap; word-break: break-word; max-height: 400px; overflow: auto; font-size: 13px; line-height: 1.6; margin: 0; }
</style>
