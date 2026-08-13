<template>
  <div class="main-content">
    <breadcumb :page="$t('Serial_Number')" :folder="$t('Serial_Numbers')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-card class="mb-3">
        <div class="d-flex justify-content-between align-items-center" style="flex-wrap:wrap; gap:8px;">
          <h4 class="mb-0">
            <lucide-icon name="scan-barcode" style="width:20px;height:20px;vertical-align:-4px;" />
            {{ serial.serial_number }}
          </h4>
          <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <span class="badge" :class="statusBadge(serial.status)" style="font-size:13px;">{{ statusLabel(serial.status) }}</span>
            <template v-if="canManageStatus && currentUserPermissions && currentUserPermissions.includes('serial_numbers')">
              <b-button v-if="serial.status !== 'available'" size="sm" variant="outline-success" @click="setStatus('available')">{{ $t('Status_available') }}</b-button>
              <b-button v-if="serial.status !== 'damaged'" size="sm" variant="outline-danger" @click="setStatus('damaged')">{{ $t('Status_damaged') }}</b-button>
              <b-button v-if="serial.status !== 'reserved'" size="sm" variant="outline-secondary" @click="setStatus('reserved')">{{ $t('Status_reserved') }}</b-button>
            </template>
          </div>
        </div>
        <hr />
        <b-row>
          <b-col md="4" class="mb-2"><strong>{{ $t('Name_product') }}:</strong> {{ serial.product_name }} <small class="text-muted" v-if="serial.product_code">({{ serial.product_code }})</small></b-col>
          <b-col md="4" class="mb-2" v-if="serial.variant_name"><strong>{{ $t('Variant') || 'Variant' }}:</strong> {{ serial.variant_name }}</b-col>
          <b-col md="4" class="mb-2"><strong>{{ $t('warehouse') }}:</strong> {{ serial.warehouse_name }}</b-col>
          <b-col md="4" class="mb-2" v-if="serial.provider_name"><strong>{{ $t('Supplier') }}:</strong> {{ serial.provider_name }}</b-col>
          <b-col md="4" class="mb-2" v-if="serial.client_name"><strong>{{ $t('Customer') }}:</strong> {{ serial.client_name }}</b-col>
          <b-col md="4" class="mb-2" v-if="serial.created_at"><strong>{{ $t('Registered_Date') || 'Registered' }}:</strong> {{ serial.created_at }}</b-col>
        </b-row>
      </b-card>

      <b-card :title="$t('Serial_Movement_Log') || 'Movement Log'">
        <table class="table table-hover vgt-table mt-2">
          <thead>
            <tr>
              <th class="text-left">{{ $t('date') }}</th>
              <th class="text-left">{{ $t('Action') }}</th>
              <th class="text-left">{{ $t('Serial_Status') }}</th>
              <th class="text-left">{{ $t('Reference') || 'Reference' }}</th>
              <th class="text-left">{{ $t('User') || 'User' }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="movements.length === 0"><td colspan="5">{{ $t('NodataAvailable') }}</td></tr>
            <tr v-for="m in movements" :key="m.id">
              <td class="text-left">{{ m.created_at }}</td>
              <td class="text-left">{{ actionLabel(m.action) }}</td>
              <td class="text-left">
                <span v-if="m.from_status" class="text-muted">{{ statusLabel(m.from_status) }} &rarr; </span>
                <span>{{ statusLabel(m.to_status) }}</span>
              </td>
              <td class="text-left">
                <span v-if="m.reference_type">{{ m.reference_type }}<span v-if="m.reference_ref"> · {{ m.reference_ref }}</span></span>
                <span v-else>—</span>
              </td>
              <td class="text-left">{{ m.user_name || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </b-card>
    </div>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";

export default {
  metaInfo: { title: "Serial Detail" },
  data() {
    return {
      isLoading: true,
      serial: {},
      movements: []
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    // Only in-stock states can be changed manually.
    canManageStatus() {
      return ["available", "damaged", "reserved"].includes(this.serial.status);
    }
  },
  methods: {
    setStatus(status) {
      NProgress.start();
      axios
        .post("serial_numbers/" + this.serial.id + "/status", { status })
        .then(() => {
          NProgress.done();
          this.$root.$bvToast.toast(this.$t("Successfully_Updated") || "Updated", { variant: "success", solid: true });
          this.loadData();
        })
        .catch(error => {
          NProgress.done();
          const msg = (error.response && error.response.data && error.response.data.errors && error.response.data.errors.status)
            ? error.response.data.errors.status[0]
            : (this.$t("InvalidData") || "Failed");
          this.$root.$bvToast.toast(msg, { variant: "danger", solid: true });
        });
    },
    statusLabel(s) {
      return this.$t("Status_" + s) || s;
    },
    statusBadge(s) {
      const map = {
        available: "badge-success",
        sold: "badge-primary",
        returned_customer: "badge-info",
        returned_supplier: "badge-warning",
        damaged: "badge-danger",
        reserved: "badge-secondary"
      };
      return map[s] || "badge-light";
    },
    actionLabel(a) {
      const map = {
        purchased: this.$t("Purchased") || "Purchased",
        sold: this.$t("Status_sold") || "Sold",
        sale_returned: this.$t("Status_returned_customer") || "Sale returned",
        purchase_returned: this.$t("Status_returned_supplier") || "Purchase returned",
        status_changed: this.$t("Status_changed") || "Status changed",
        adjusted: this.$t("Adjusted") || "Adjusted"
      };
      return map[a] || a;
    },
    loadData() {
      NProgress.start();
      NProgress.set(0.1);
      const id = this.$route.params.id;
      axios
        .get("serial_numbers/" + id)
        .then(response => {
          this.serial = response.data.serial || {};
          this.movements = response.data.movements || [];
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    }
  },
  created() {
    this.loadData();
  }
};
</script>
