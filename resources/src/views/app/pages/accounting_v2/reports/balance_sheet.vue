<template>
  <!-- NEW FEATURE - SAFE ADDITION -->
  <div class="px-next pxac">
    <px-page-header :title="$t('Balance_Sheet_Title')" :subtitle="$t('Balance_Sheet_Subtitle')" />

    <px-card class="pxac__filtercard">
      <div class="pxac__filterrow">
        <px-field :label="$t('As_Of')">
          <template #default="{ id }"><px-input :id="id" type="date" v-model="to" /></template>
        </px-field>
        <px-button variant="secondary" icon="refresh-cw" @click="fetch">{{ $t('Refresh') }}</px-button>
      </div>
    </px-card>

    <div class="pxac__kpis pxac__kpis--3">
      <px-stat bordered :label="$t('Assets')" :value="toMoney(data.assets)" />
      <px-stat bordered :label="$t('Liabilities')" :value="toMoney(data.liabilities)" />
      <px-stat bordered :label="$t('Equity')" :value="toMoney(data.equity)" />
    </div>

    <px-alert :tone="Math.abs(data.balance) < 0.01 ? 'success' : 'warning'" :title="$t('Balance_Check')">
      <span class="pxn-num pxac__balval">{{ toMoney(data.balance) }}</span>
    </px-alert>
  </div>
</template>

<script>
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting,
  getPriceDecimals
} from "../../../../../utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxStat from "@/components/px-next/PxStat.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";

export default {
  name: "BalanceSheetV2",
  components: { PxPageHeader, PxCard, PxStat, PxField, PxInput, PxButton, PxAlert },
  data() {
    return {
      data: { assets: 0, liabilities: 0, equity: 0, balance: 0 },
      to: "",
      // Optional price format key for frontend display (loaded from system settings/localStorage)
      price_format_key: null
    };
  },
  created() { this.fetch(); },
  computed: {
    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
  },
  methods: {
    async fetch() {
      try {
        const { data } = await axios.get("/accounting/v2/reports/balance-sheet", { params: { to: this.to || undefined } });
        this.data = data || this.data;
      } catch (e) {}
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
        return n.toLocaleString(undefined, { minimumFractionDigits: this.priceDecimals, maximumFractionDigits: this.priceDecimals });
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxac { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxac { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxac__filtercard { margin-top: var(--pxn-space-5); }
.pxac__filterrow { display: flex; align-items: flex-end; gap: var(--pxn-space-5); flex-wrap: wrap; }
.pxac__filterrow ::v-deep .pxn-field { max-width: 260px; }
.pxac__kpis { display: grid; gap: var(--pxn-space-5); margin: var(--pxn-space-5) 0; }
.pxac__kpis--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
@media (max-width: 720px) { .pxac__kpis--3 { grid-template-columns: minmax(0, 1fr); } }
.pxac__balval { font-size: var(--pxn-fs-lg); font-weight: var(--pxn-fw-bold); }
</style>
