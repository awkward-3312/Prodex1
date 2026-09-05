<template>
  <!-- NEW FEATURE - SAFE ADDITION -->
  <div class="px-next pxac">
    <px-page-header :title="$t('Tax_Summary_Report')" :breadcrumbs="[{ label: $t('Reports') }, { label: $t('Tax_Summary_Report') }]" />

    <px-alert v-if="error" tone="danger" :title="$t('Error')" class="pxac__err">{{ error }}</px-alert>

    <div v-if="isLoading" class="pxac__pad">
      <px-skeleton variant="lines" :rows="6" />
    </div>

    <template v-else>
      <px-card class="pxac__filtercard">
        <div class="pxac__filterrow">
          <px-field :label="$t('From')">
            <template #default="{ id }"><px-input :id="id" type="date" v-model="filters.from" @change="fetch" /></template>
          </px-field>
          <px-field :label="$t('To')">
            <template #default="{ id }"><px-input :id="id" type="date" v-model="filters.to" @change="fetch" /></template>
          </px-field>
          <px-button variant="secondary" icon="refresh-cw" :disabled="isLoading" @click="fetch">{{ $t('Refresh') }}</px-button>
        </div>
      </px-card>

      <div class="pxac__twocol">
        <px-card :title="`${$t('Sales_Tax')} (${$t('Output_Tax')})`">
          <div class="pxac__line pxac__line--muted">
            <span>{{ $t('Total_Sales') }}</span><span class="pxn-num">{{ toMoney(data.sales) }}</span>
          </div>
          <div class="pxac__line pxac__line--muted">
            <span>{{ $t('Sale_Returns') }}</span><span class="pxn-num pxac__neg">- {{ toMoney(data.sale_returns) }}</span>
          </div>
          <div class="pxac__divider"></div>
          <div class="pxac__line pxac__line--strong">
            <span>{{ $t('Net_Sales') }}</span><span class="pxn-num">{{ toMoney(data.taxable_sales) }}</span>
          </div>
          <div class="pxac__line pxac__line--strong">
            <span>{{ $t('Output_Tax') }}</span><span class="pxn-num pxac__pos">{{ toMoney(data.output_tax) }}</span>
          </div>
        </px-card>

        <px-card :title="`${$t('Purchase_Tax')} (${$t('Input_Tax')})`">
          <div class="pxac__line pxac__line--muted">
            <span>{{ $t('Total_Purchases') }}</span><span class="pxn-num">{{ toMoney(data.purchases) }}</span>
          </div>
          <div class="pxac__line pxac__line--muted">
            <span>{{ $t('Purchase_Returns') }}</span><span class="pxn-num pxac__neg">- {{ toMoney(data.purchase_returns) }}</span>
          </div>
          <div class="pxac__divider"></div>
          <div class="pxac__line pxac__line--strong">
            <span>{{ $t('Net_Purchases') }}</span><span class="pxn-num">{{ toMoney(data.taxable_purchases) }}</span>
          </div>
          <div class="pxac__line pxac__line--strong">
            <span>{{ $t('Input_Tax') }}</span><span class="pxn-num pxac__info">{{ toMoney(data.input_tax) }}</span>
          </div>
        </px-card>
      </div>

      <px-alert :tone="data.net_tax >= 0 ? 'warning' : 'success'" :title="$t('Net_Tax')">
        <div class="pxac__nettax">
          <span class="pxn-num pxac__balval">{{ toMoney(data.net_tax) }}</span>
          <span class="pxac__nettax-note">{{ data.net_tax >= 0 ? $t('Tax_Payable') : $t('Tax_Refund') }}</span>
        </div>
      </px-alert>
    </template>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting,
  getPriceDecimals
} from "../../../../../utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";

export default {
  name: "TaxReportV2",
  metaInfo: {
    title: "Tax Summary Report"
  },
  components: { PxPageHeader, PxCard, PxField, PxInput, PxButton, PxAlert },
  data() {
    return {
      data: {
        sales: 0,
        sale_returns: 0,
        taxable_sales: 0,
        output_tax: 0,
        purchases: 0,
        purchase_returns: 0,
        taxable_purchases: 0,
        input_tax: 0,
        net_tax: 0
      },
      filters: {
        from: "",
        to: ""
      },
      isLoading: false,
      error: null,
      // Optional price format key for frontend display (loaded from system settings/localStorage)
      price_format_key: null
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
  },
  created() {
    this.initializeDates();
    this.fetch();
  },
  methods: {
    initializeDates() {
      // Set default date range to current month
      const now = new Date();
      const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
      const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0);

      this.filters.from = firstDay.toISOString().split('T')[0];
      this.filters.to = lastDay.toISOString().split('T')[0];
    },
    async fetch() {
      this.isLoading = true;
      this.error = null;

      try {
        const { data } = await axios.get("/accounting/v2/reports/tax-summary", {
          params: this.filters
        });

        if (data) {
          this.data = {
            sales: parseFloat(data.sales || 0),
            sale_returns: parseFloat(data.sale_returns || 0),
            taxable_sales: parseFloat(data.taxable_sales || 0),
            output_tax: parseFloat(data.output_tax || 0),
            purchases: parseFloat(data.purchases || 0),
            purchase_returns: parseFloat(data.purchase_returns || 0),
            taxable_purchases: parseFloat(data.taxable_purchases || 0),
            input_tax: parseFloat(data.input_tax || 0),
            net_tax: parseFloat(data.net_tax || 0)
          };
        }
      } catch (e) {
        console.error("Tax Summary Error:", e);
        this.error = e.response?.data?.message || this.$t('Failed_Load_Tax_Summary');

        // Show toast notification
        this.$root.$bvToast.toast(this.error, {
          title: this.$t('Error'),
          variant: 'danger',
          solid: true
        });
      } finally {
        this.isLoading = false;
      }
    },
    // Price formatting for display only (does NOT affect calculations or stored values)
    // Uses the global/system price_format setting when available; otherwise falls back
    // to the existing toLocaleString behavior to preserve current behavior.
    toMoney(v) {
      try {
        const n = parseFloat(v || 0);
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        const effectiveKey = key || null;
        return formatPriceDisplayHelper(n, this.priceDecimals, effectiveKey);
      } catch (e) {
        const n = parseFloat(v || 0);
        return n.toLocaleString(undefined, {
          minimumFractionDigits: this.priceDecimals,
          maximumFractionDigits: this.priceDecimals
        });
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxac { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxac { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxac__pad { padding: var(--pxn-space-6) 0; }
.pxac__err { margin-top: var(--pxn-space-5); }
.pxac__filtercard { margin-top: var(--pxn-space-5); }
.pxac__filterrow { display: flex; align-items: flex-end; gap: var(--pxn-space-5); flex-wrap: wrap; }
.pxac__filterrow ::v-deep .pxn-field { max-width: 220px; }
.pxac__twocol { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); margin: var(--pxn-space-5) 0; }
@media (max-width: 780px) { .pxac__twocol { grid-template-columns: minmax(0, 1fr); } }
.pxac__line { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-4); padding: var(--pxn-space-2) 0; font-size: var(--pxn-fs-sm); }
.pxac__line--muted { color: var(--pxn-ink-2); }
.pxac__line--strong { font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxac__divider { height: 1px; background: var(--pxn-border); margin: var(--pxn-space-3) 0; }
.pxac__pos { color: var(--pxn-success); }
.pxac__neg { color: var(--pxn-danger); }
.pxac__info { color: var(--pxn-info); }
.pxac__balval { font-size: var(--pxn-fs-lg); font-weight: var(--pxn-fw-bold); }
.pxac__nettax { display: flex; align-items: baseline; gap: var(--pxn-space-4); flex-wrap: wrap; }
.pxac__nettax-note { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
</style>
