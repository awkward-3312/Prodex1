<template>
  <div>
    <b-card class="stores-card shadow-sm mb-4">
      <template #header>
        <div class="d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center">
            <lucide-icon class="mr-2 text-primary" name="store" />
            <h5 class="mb-0 font-weight-bold">{{ $t('Shopify_Stores') }}</h5>
          </div>
          <b-button variant="primary" size="sm" @click="openCreate">
            <lucide-icon class="mr-1" name="plus" /> {{ $t('Add') }}
          </b-button>
        </div>
      </template>

      <div v-if="!stores.length" class="text-center text-muted py-5">
        <lucide-icon name="store" style="font-size: 40px;" />
        <p class="mt-3 mb-0">{{ $t('No_Shopify_store_yet') }}</p>
        <b-button variant="outline-primary" class="mt-3" @click="openCreate">
          <lucide-icon class="mr-1" name="plus" /> {{ $t('Connect_your_first_store') }}
        </b-button>
      </div>

      <b-table v-else :items="stores" :fields="fields" responsive striped hover>
        <template #cell(is_active)="{ item }">
          <b-badge :variant="item.is_active ? 'success' : 'secondary'">
            {{ item.is_active ? $t('Active') : $t('Inactive') }}
          </b-badge>
        </template>
        <template #cell(warehouse_id)="{ item }">
          {{ warehouseName(item.warehouse_id) }}
        </template>
        <template #cell(actions)="{ item }">
          <b-button size="sm" variant="outline-success" class="mr-1 mb-1" :disabled="busyId === item.id" @click="test(item)">
            <lucide-icon name="cloud-check" /> {{ $t('Test_Connection') }}
          </b-button>
          <b-button size="sm" variant="outline-info" class="mr-1 mb-1" :disabled="busyId === item.id" @click="registerWebhooks(item)">
            <lucide-icon name="webhook" /> {{ $t('Register_Webhooks') }}
          </b-button>
          <b-button size="sm" variant="outline-primary" class="mr-1 mb-1" @click="openEdit(item)">
            <lucide-icon name="edit" /> {{ $t('Edit') }}
          </b-button>
          <b-button size="sm" variant="outline-danger" class="mb-1" @click="remove(item)">
            <lucide-icon name="trash-2" /> {{ $t('Del') }}
          </b-button>
        </template>
      </b-table>
    </b-card>

    <!-- Create / edit store -->
    <validation-observer ref="form">
      <b-modal v-model="showModal" :title="form.id ? $t('Edit_Store') : $t('Add_Store')" hide-footer size="lg">
        <b-form @submit.prevent="save">
          <b-row>
            <b-col md="6" class="mb-3">
              <validation-provider :name="$t('Name')" :rules="{ required: true }" v-slot="v">
                <b-form-group :label="$t('Name') + ' *'">
                  <b-form-input v-model="form.name" :state="getState(v)" placeholder="My Shopify store" />
                  <b-form-invalid-feedback>{{ v.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>
            <b-col md="6" class="mb-3">
              <validation-provider :name="$t('Shop_Domain')" :rules="{ required: true }" v-slot="v">
                <b-form-group :label="$t('Shop_Domain') + ' *'">
                  <b-form-input v-model="form.shop_domain" :state="getState(v)" placeholder="my-store.myshopify.com" />
                  <b-form-invalid-feedback>{{ v.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>
            <b-col md="6" class="mb-3">
              <validation-provider :name="$t('Access_Token')" :rules="{ required: true }" v-slot="v">
                <b-form-group :label="$t('Admin_API_Access_Token') + ' *'">
                  <b-form-input type="password" v-model="form.access_token" :state="getState(v)" placeholder="shpat_..." />
                  <b-form-invalid-feedback>{{ v.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>
            <b-col md="6" class="mb-3">
              <b-form-group :label="$t('API_Secret_Key')">
                <b-form-input type="password" v-model="form.api_secret" placeholder="shpss_... (required for webhooks)" />
                <small class="text-muted">{{ $t('Used_to_verify_webhook_signatures') }}</small>
              </b-form-group>
            </b-col>
            <b-col md="4" class="mb-3">
              <b-form-group :label="$t('API_Version')">
                <b-form-input v-model="form.api_version" placeholder="2025-01" />
              </b-form-group>
            </b-col>
            <b-col md="4" class="mb-3">
              <b-form-group :label="$t('warehouse')">
                <b-form-select v-model="form.warehouse_id" :options="warehouseOptions" />
                <small class="text-muted">{{ $t('Warehouse_used_for_inventory_and_orders') }}</small>
              </b-form-group>
            </b-col>
            <b-col md="4" class="mb-3">
              <b-form-group :label="$t('Shopify_Location')">
                <b-form-select v-model="form.location_id" :options="locationOptions" @change="syncLocationName" />
                <b-button size="sm" variant="link" class="p-0 mt-1" :disabled="!form.id" @click="loadLocations">
                  {{ $t('Load_locations') }}
                </b-button>
              </b-form-group>
            </b-col>
            <b-col md="6" class="mb-3">
              <b-form-checkbox v-model="form.is_active" switch>{{ $t('Active') }}</b-form-checkbox>
            </b-col>
            <b-col md="12">
              <b-button variant="primary" type="submit">
                <lucide-icon class="mr-1" name="check" /> {{ $t('Save') }}
              </b-button>
            </b-col>
          </b-row>
        </b-form>
      </b-modal>
    </validation-observer>
  </div>
</template>

<script>
import NProgress from 'nprogress';

export default {
  props: {
    stores: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
  },
  data() {
    return {
      showModal: false,
      busyId: null,
      locations: [],
      form: this.emptyForm(),
    };
  },
  computed: {
    fields() {
      return [
        { key: 'name', label: this.$t('Name') },
        { key: 'shop_domain', label: this.$t('Shop_Domain') },
        { key: 'api_version', label: this.$t('API_Version') },
        { key: 'warehouse_id', label: this.$t('warehouse') },
        { key: 'location_name', label: this.$t('Shopify_Location') },
        { key: 'is_active', label: this.$t('Active') },
        { key: 'actions', label: this.$t('Action') },
      ];
    },
    warehouseOptions() {
      return [{ value: null, text: this.$t('All_Warehouses') }]
        .concat(this.warehouses.map(w => ({ value: w.id, text: w.name })));
    },
    locationOptions() {
      const opts = [{ value: null, text: '--' }];
      this.locations.forEach(l => opts.push({ value: l.id, text: l.name }));
      if (this.form.location_id && !this.locations.find(l => l.id === this.form.location_id)) {
        opts.push({ value: this.form.location_id, text: this.form.location_name || String(this.form.location_id) });
      }
      return opts;
    },
  },
  methods: {
    emptyForm() {
      return {
        id: null,
        name: '',
        shop_domain: '',
        access_token: '',
        api_secret: '',
        api_version: '2025-01',
        location_id: null,
        location_name: null,
        warehouse_id: null,
        is_active: true,
      };
    },
    getState(v) { return v.validated ? v.valid : null; },
    warehouseName(id) {
      const w = this.warehouses.find(w => w.id === id);
      return w ? w.name : this.$t('All_Warehouses');
    },
    openCreate() {
      this.form = this.emptyForm();
      this.locations = [];
      this.showModal = true;
    },
    openEdit(store) {
      this.form = Object.assign(this.emptyForm(), store);
      this.locations = [];
      this.showModal = true;
    },
    syncLocationName() {
      const l = this.locations.find(l => l.id === this.form.location_id);
      this.form.location_name = l ? l.name : this.form.location_name;
    },
    loadLocations() {
      if (!this.form.id) return;
      axios.get('shopify/stores/' + this.form.id + '/locations').then(({ data }) => {
        this.locations = data.locations || [];
        if (!this.locations.length) this.toast('warning', this.$t('No_locations_found'));
      }).catch(err => {
        this.toast('danger', (err.response && err.response.data && err.response.data.error) || this.$t('Connection_failed'));
      });
    },
    save() {
      this.$refs.form.validate().then(valid => {
        if (!valid) {
          this.toast('danger', this.$t('Please_fill_the_form_correctly'));
          return;
        }
        NProgress.start(); NProgress.set(0.1);
        const request = this.form.id
          ? axios.put('shopify/stores/' + this.form.id, this.form)
          : axios.post('shopify/stores', this.form);
        request.then(() => {
          this.toast('success', this.$t('Successfully_Updated'));
          this.showModal = false;
          this.$emit('refreshed');
        }).catch(err => {
          const msg = err.response && err.response.data && err.response.data.message;
          this.toast('danger', msg || this.$t('InvalidData'));
        }).finally(() => NProgress.done());
      });
    },
    test(store) {
      this.busyId = store.id;
      axios.post('shopify/stores/' + store.id + '/test-connection').then(({ data }) => {
        this.$emit('connection', !!data.ok);
        const shop = data.shop || {};
        this.toast('success', this.$t('Connection_successful') + (shop.name ? ' — ' + shop.name : ''));
      }).catch(err => {
        this.$emit('connection', false);
        this.toast('danger', (err.response && err.response.data && err.response.data.error) || this.$t('Connection_failed'));
      }).finally(() => { this.busyId = null; });
    },
    registerWebhooks(store) {
      this.busyId = store.id;
      axios.post('shopify/stores/' + store.id + '/register-webhooks').then(({ data }) => {
        this.toast('success', this.$t('Webhooks_registered') + ' (' + (data.created || []).length + ')');
      }).catch(err => {
        this.toast('danger', (err.response && err.response.data && err.response.data.error) || this.$t('Connection_failed'));
      }).finally(() => { this.busyId = null; });
    },
    remove(store) {
      this.$swal({
        title: this.$t('Delete_Title'),
        text: this.$t('This_removes_the_store_and_all_its_sync_mappings'),
        type: 'warning',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: this.$t('Delete_confirmButtonText'),
        cancelButtonText: this.$t('Delete_cancelButtonText'),
      }).then(result => {
        if (!result.value) return;
        axios.delete('shopify/stores/' + store.id).then(() => {
          this.toast('success', this.$t('Deleted_in_successfully'));
          this.$emit('refreshed');
        }).catch(() => this.toast('danger', this.$t('InvalidData')));
      });
    },
    toast(variant, msg) {
      this.$root.$bvToast.toast(msg, { title: 'Shopify', variant, solid: true });
    },
  },
  created() {
    this.$emit('ready');
  }
};
</script>

<style scoped>
.stores-card {
  border-radius: 12px;
  border: none;
}

.stores-card ::v-deep .card-header {
  background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
  border-bottom: 2px solid #e9ecef;
  padding: 1.25rem 1.5rem;
  border-radius: 12px 12px 0 0;
}
</style>
