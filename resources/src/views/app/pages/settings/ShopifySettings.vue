<template>
  <div class="main-content">
    <breadcumb :page="$t('Shopify_Settings')" :folder="$t('Settings')"/>
    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-else>
      <b-card no-body class="shopify-settings-card shadow-sm">
        <div class="shopify-header px-4 pt-4 pb-3">
          <div class="d-flex align-items-center justify-content-between flex-wrap">
            <div class="d-flex align-items-center mb-2">
              <div class="shopify-icon-wrapper mr-3">
                <lucide-icon class="shopify-icon" name="store" />
              </div>
              <div>
                <h4 class="mb-1 font-weight-bold text-white">{{ $t('Shopify_Settings') }}</h4>
                <p class="mb-0 small text-white" style="opacity: 0.9;">Synchronize products, inventory, orders and customers with Shopify</p>
              </div>
            </div>
            <div class="d-flex align-items-center mb-2">
              <b-form-select
                v-if="stores.length"
                v-model="selectedStoreId"
                :options="storeOptions"
                class="store-selector mr-3"
                @change="onStoreChange"
              />
              <b-badge :variant="connectionBadgeVariant" class="connection-badge px-3 py-2">
                <lucide-icon :name="connectionIcon" class="mr-2" />
                {{ connectionBadgeText }}
              </b-badge>
            </div>
          </div>
        </div>

        <b-tabs v-model="activeTab" @input="onTabChange" content-class="shopify-tabs-content position-relative" class="shopify-tabs">
          <div v-if="tabLoading" class="loading_page spinner spinner-primary mr-3"></div>
          <b-tab lazy>
            <template #title>
              <lucide-icon class="mr-2" name="settings" />
              {{ $t('Stores') }}
            </template>
            <div v-show="!tabLoading" class="px-4 py-3">
              <StoresTab :stores="stores" :warehouses="warehouses" @ready="onTabReady" @refreshed="reloadStores" @connection="onConnectionUpdate" />
            </div>
          </b-tab>
          <b-tab lazy :disabled="!currentStore">
            <template #title>
              <lucide-icon class="mr-2" name="barcode" />
              {{ $t('Products') }}
            </template>
            <div v-show="!tabLoading" class="px-4 py-3">
              <ProductsTab v-if="currentStore" :store="currentStore" @ready="onTabReady" />
            </div>
          </b-tab>
          <b-tab lazy :disabled="!currentStore">
            <template #title>
              <lucide-icon class="mr-2" name="package" />
              {{ $t('Inventory') }}
            </template>
            <div v-show="!tabLoading" class="px-4 py-3">
              <InventoryTab v-if="currentStore" :store="currentStore" @ready="onTabReady" />
            </div>
          </b-tab>
          <b-tab lazy :disabled="!currentStore">
            <template #title>
              <lucide-icon class="mr-2" name="user" />
              {{ $t('Customers') }}
            </template>
            <div v-show="!tabLoading" class="px-4 py-3">
              <CustomersTab v-if="currentStore" :store="currentStore" @ready="onTabReady" />
            </div>
          </b-tab>
          <b-tab lazy :disabled="!currentStore">
            <template #title>
              <lucide-icon class="mr-2" name="shopping-bag" />
              {{ $t('Orders') }}
            </template>
            <div v-show="!tabLoading" class="px-4 py-3">
              <OrdersTab v-if="currentStore" :store="currentStore" @ready="onTabReady" />
            </div>
          </b-tab>
          <b-tab lazy>
            <template #title>
              <lucide-icon class="mr-2" name="clipboard-list" />
              {{ $t('View_Logs') }}
            </template>
            <div v-show="!tabLoading" class="px-4 py-3">
              <LogsTab :store="currentStore" @ready="onTabReady" />
            </div>
          </b-tab>
          <b-tab lazy>
            <template #title>
              <lucide-icon class="mr-2" name="help-circle" />
              Guide
            </template>
            <div v-show="!tabLoading" class="px-4 py-3">
              <GuideTab @ready="onTabReady" />
            </div>
          </b-tab>
        </b-tabs>
      </b-card>
    </div>
  </div>
</template>

<script>
export default {
  metaInfo: { title: 'Shopify Settings' },
  components: {
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
    storeOptions() {
      return this.stores.map(s => ({ value: s.id, text: s.name + ' (' + s.shop_domain + ')' }));
    },
    currentStore() {
      return this.stores.find(s => s.id === this.selectedStoreId) || null;
    },
    connectionBadgeVariant() {
      if (this.connectionOk === true) return 'success';
      if (this.connectionOk === false) return 'danger';
      return 'secondary';
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

<style scoped>
.shopify-settings-card {
  border-radius: 12px;
  overflow: hidden;
  border: none;
}

.shopify-header {
  background: linear-gradient(135deg, #96bf48 0%, #5e8e3e 100%);
  color: white !important;
  border-radius: 0;
}

.shopify-header h4,
.shopify-header p,
.shopify-header .text-white {
  color: white !important;
}

.shopify-icon-wrapper {
  width: 56px;
  height: 56px;
  background: rgba(255, 255, 255, 0.2);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  backdrop-filter: blur(10px);
}

.shopify-icon {
  font-size: 28px;
  color: white;
}

.store-selector {
  min-width: 240px;
  border-radius: 8px;
}

.connection-badge {
  font-size: 14px;
  font-weight: 600;
  border-radius: 20px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.shopify-tabs ::v-deep .nav-tabs {
  border-bottom: 2px solid #f0f0f0;
  padding: 0 1rem;
}

.shopify-tabs ::v-deep .nav-tabs .nav-link {
  border: none;
  border-bottom: 3px solid transparent;
  color: #6c757d;
  font-weight: 500;
  padding: 1rem 1.5rem;
  transition: all 0.3s ease;
  margin-right: 0.5rem;
}

.shopify-tabs ::v-deep .nav-tabs .nav-link:hover {
  color: #5e8e3e;
  background: rgba(94, 142, 62, 0.05);
  border-bottom-color: rgba(94, 142, 62, 0.3);
}

.shopify-tabs ::v-deep .nav-tabs .nav-link.active {
  color: #5e8e3e;
  background: transparent;
  border-bottom-color: #5e8e3e;
  font-weight: 600;
}

.shopify-tabs-content {
  min-height: 400px;
}
</style>
