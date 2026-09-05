<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('Shopify_Settings')"
      subtitle="Synchronize products, inventory, orders and customers with Shopify"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('Shopify_Settings') }]"
    >
      <template #meta>
        <px-badge :tone="connectionTone" :icon="connectionIcon">{{ connectionBadgeText }}</px-badge>
      </template>
      <template #actions>
        <vs-px
          v-if="stores.length"
          style="min-width: 240px"
          :options="storeOptions.map(o => ({ label: o.text, value: o.value }))"
          :reduce="o => o.value"
          :value="selectedStoreId"
          :clearable="false"
          @input="v => { selectedStoreId = v; onStoreChange(); }"
        />
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
          :disabled="tab.needsStore && !currentStore"
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
            <StoresTab v-if="activeTab === 0" :stores="stores" :warehouses="warehouses" @ready="onTabReady" @refreshed="reloadStores" @connection="onConnectionUpdate" />
            <ProductsTab v-else-if="activeTab === 1 && currentStore" :store="currentStore" @ready="onTabReady" />
            <InventoryTab v-else-if="activeTab === 2 && currentStore" :store="currentStore" @ready="onTabReady" />
            <CustomersTab v-else-if="activeTab === 3 && currentStore" :store="currentStore" @ready="onTabReady" />
            <OrdersTab v-else-if="activeTab === 4 && currentStore" :store="currentStore" @ready="onTabReady" />
            <LogsTab v-else-if="activeTab === 5" :store="currentStore" @ready="onTabReady" />
            <GuideTab v-else-if="activeTab === 6" @ready="onTabReady" />
          </div>
        </div>
      </px-card>
    </template>
  </div>
</template>

<script>
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: 'Shopify Settings' },
  components: {
    PxPageHeader, PxCard, PxBadge, VsPx,
    StoresTab: () => import(/* webpackChunkName: "shopify-stores-tab" */ './shopify/StoresTab.vue'),
    ProductsTab: () => import(/* webpackChunkName: "shopify-products-tab" */ './shopify/ProductsTab.vue'),
    InventoryTab: () => import(/* webpackChunkName: "shopify-inventory-tab" */ './shopify/InventoryTab.vue'),
    CustomersTab: () => import(/* webpackChunkName: "shopify-customers-tab" */ './shopify/CustomersTab.vue'),
    OrdersTab: () => import(/* webpackChunkName: "shopify-orders-tab" */ './shopify/OrdersTab.vue'),
    LogsTab: () => import(/* webpackChunkName: "shopify-logs-tab" */ './shopify/LogsTab.vue'),
    GuideTab: () => import(/* webpackChunkName: "shopify-guide-tab" */ './shopify/GuideTab.vue'),
  },
  data() {
    return {
      loading: true,
      tabLoading: true,
      activeTab: 0,
      stores: [],
      warehouses: [],
      selectedStoreId: null,
      connectionOk: null,
    };
  },
  computed: {
    tabs() {
      return [
        { key: 'stores', label: this.$t('Stores'), icon: 'settings', needsStore: false },
        { key: 'products', label: this.$t('Products'), icon: 'barcode', needsStore: true },
        { key: 'inventory', label: this.$t('Inventory'), icon: 'package', needsStore: true },
        { key: 'customers', label: this.$t('Customers'), icon: 'user', needsStore: true },
        { key: 'orders', label: this.$t('Orders'), icon: 'shopping-bag', needsStore: true },
        { key: 'logs', label: this.$t('View_Logs'), icon: 'clipboard-list', needsStore: false },
        { key: 'guide', label: 'Guide', icon: 'help-circle', needsStore: false },
      ];
    },
    storeOptions() {
      return this.stores.map(s => ({ value: s.id, text: s.name + ' (' + s.shop_domain + ')' }));
    },
    currentStore() {
      return this.stores.find(s => s.id === this.selectedStoreId) || null;
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
      if (this.tabs[i].needsStore && !this.currentStore) return;
      this.activeTab = i;
      this.onTabChange();
    },
    onTabChange() { this.tabLoading = true; },
    onTabReady() { this.tabLoading = false; },
    onStoreChange() {
      this.connectionOk = null;
      this.testConnection();
    },
    onConnectionUpdate(val) { this.connectionOk = val; },
    reloadStores() {
      return axios.get('shopify/stores').then(({ data }) => {
        this.stores = data.stores || [];
        this.warehouses = data.warehouses || [];
        if (!this.selectedStoreId && this.stores.length) {
          this.selectedStoreId = this.stores[0].id;
        }
        if (this.selectedStoreId && !this.stores.find(s => s.id === this.selectedStoreId)) {
          this.selectedStoreId = this.stores.length ? this.stores[0].id : null;
        }
      });
    },
    testConnection() {
      if (!this.selectedStoreId) return;
      axios.post('shopify/stores/' + this.selectedStoreId + '/test-connection').then(({ data }) => {
        this.connectionOk = !!data.ok;
      }).catch(() => {
        this.connectionOk = false;
      });
    },
  },
  created() {
    this.reloadStores().then(() => {
      this.testConnection();
    }).finally(() => {
      this.loading = false;
    });
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
.pxcfg__tab:hover:not(:disabled) { color: var(--pxn-text); }
.pxcfg__tab:disabled { opacity: .45; cursor: not-allowed; }
.pxcfg__tab.is-active { color: var(--pxn-primary); border-bottom-color: var(--pxn-primary); font-weight: 600; }
.pxcfg__tab-icon { width: 15px; height: 15px; }
.pxcfg__panel { padding: var(--pxn-space-5); min-height: 380px; }
</style>
