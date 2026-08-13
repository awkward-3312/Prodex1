<template>
  <div class="main-content">
    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-else class="billing-page">
      <div class="mb-3">
        <router-link to="/app/billing/change-plan" class="text-muted small text-decoration-none">
          <lucide-icon name="arrow-left" class="mr-1" /> {{ $t('Back_to_plans') || 'Back to plans' }}
        </router-link>
      </div>

      <!-- Pending Upgrade Block -->
      <div v-if="pendingUpgrade" class="pending-upgrade-alert mb-4">
        <div class="d-flex align-items-start gap-3">
          <div class="pending-upgrade-icon">
            <lucide-icon name="clock" />
          </div>
          <div>
            <h5 class="mb-1">{{ $t('Pending_Upgrade') || 'Pending Upgrade Request' }}</h5>
            <p class="mb-2 text-muted">
              {{ $t('Pending_upgrade_message') || 'You have a pending upgrade to' }}
              <strong>{{ pendingUpgrade.plan_name }}</strong>.
              {{ $t('Pending_upgrade_checkout_block') || 'You cannot submit a new request until the pending one is processed or cancelled.' }}
            </p>
            <router-link to="/app/billing/current-plan" class="btn btn-outline-secondary btn-sm">
              <lucide-icon name="arrow-left" class="mr-1" /> {{ $t('Back_to_plan') || 'Back to Current Plan' }}
            </router-link>
          </div>
        </div>
      </div>

      <div v-else class="row">
        <!-- Order summary (right on desktop) -->
        <div class="col-lg-5 order-lg-2 mb-4">
          <div class="billing-card sticky-card">
            <div class="billing-card-header"><lucide-icon name="receipt" class="mr-2" />{{ $t('Order_Summary') || 'Order Summary' }}</div>
            <div class="billing-card-body">
              <div class="summary-row"><span class="text-muted">{{ $t('Plan') || 'Plan' }}</span><span class="fw-bold">{{ plan.name }}</span></div>
              <div class="summary-row"><span class="text-muted">{{ $t('Billing_Cycle') || 'Billing Cycle' }}</span><span class="fw-bold">{{ selectedCycleLabel }}</span></div>
              <hr>
              <div class="summary-row"><span class="text-muted">{{ $t('Subtotal') || 'Subtotal' }}</span><span class="fw-bold">{{ currencySymbol }}{{ displayAmount }} {{ currencyCode }}</span></div>
              <div class="summary-row"><span class="text-muted">{{ $t('Tax') || 'Tax' }}</span><span class="fw-bold">{{ currencySymbol }}0.00</span></div>
              <hr>
              <div class="summary-row">
                <span class="fw-bold summary-total-label">{{ $t('Total') || 'Total' }}</span>
                <span class="fw-bold text-primary summary-total-amount">{{ currencySymbol }}{{ displayAmount }} {{ currencyCode }}</span>
              </div>

              <div v-if="isUpgrade !== null" class="upgrade-note mt-3">
                <lucide-icon name="info" class="mr-1" />
                <span v-if="isUpgrade">{{ $t('Upgrade_note') || 'Your current plan will be replaced immediately after payment.' }}</span>
                <span v-else>{{ $t('Downgrade_note') || 'Your new plan will start immediately after payment.' }}</span>
              </div>

              <!-- Plan features summary -->
              <div class="mt-3 pt-3 summary-included">
                <div class="small fw-bold text-muted text-uppercase mb-2">{{ $t('Included') || 'Included' }}</div>
                <div v-for="(limit, key) in plan.limits" :key="'l'+key" class="d-flex align-items-center gap-1 mb-1 small">
                  <lucide-icon name="check" class="text-success" /> {{ limit.display }} {{ limit.label }}
                </div>
                <div v-for="(feat, key) in plan.features" :key="'f'+key" class="d-flex align-items-center gap-1 mb-1 small">
                  <lucide-icon name="check" class="text-success" /> {{ feat.label }}
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Payment form (left) -->
        <div class="col-lg-7 order-lg-1">
          <!-- Billing cycle -->
          <div class="billing-card mb-4">
            <div class="billing-card-header"><lucide-icon name="calendar" class="mr-2" />{{ $t('Billing_Cycle') || 'Billing Cycle' }}</div>
            <div class="billing-card-body">
              <div class="d-flex gap-3">
                <label :class="['cycle-option flex-fill', { selected: selectedCycle === 'monthly' }]">
                  <input type="radio" v-model="selectedCycle" value="monthly" class="d-none">
                  <div class="cycle-card">
                    <div>
                      <div class="fw-bold">{{ $t('Monthly') || 'Monthly' }}</div>
                      <div class="text-muted small">{{ $t('Billed_monthly') || 'Billed every month' }}</div>
                    </div>
                    <span class="fw-bold">{{ currencySymbol }}{{ plan.price.toFixed(2) }}</span>
                  </div>
                </label>
                <label :class="['cycle-option flex-fill', { selected: selectedCycle === 'yearly' }]">
                  <input type="radio" v-model="selectedCycle" value="yearly" class="d-none">
                  <div class="cycle-card">
                    <div>
                      <div class="fw-bold">
                        {{ $t('Yearly') || 'Yearly' }}
                        <span v-if="plan.savings_percent > 0" class="save-chip">-{{ plan.savings_percent }}%</span>
                      </div>
                      <div class="text-muted small">{{ $t('Billed_annually') || 'Billed annually' }}</div>
                    </div>
                    <span class="fw-bold">{{ currencySymbol }}{{ plan.yearly_price.toFixed(2) }}</span>
                  </div>
                </label>
              </div>
            </div>
          </div>

          <!-- Payment gateway -->
          <div class="billing-card mb-4">
            <div class="billing-card-header"><lucide-icon name="credit-card" class="mr-2" />{{ $t('Payment_Method') || 'Payment Method' }}</div>
            <div class="billing-card-body">
              <div v-if="gateways.length === 0" class="text-center py-4">
                <lucide-icon name="alert-triangle" class="text-warning no-gateways-icon" />
                <p class="text-muted mt-2">{{ $t('No_gateways') || 'No payment gateways available. Please contact support.' }}</p>
              </div>
              <div v-else class="d-flex flex-column gap-2">
                <label v-for="(gw, i) in gateways" :key="gw.key"
                  :class="['gateway-option', { selected: selectedGateway === gw.key }]">
                  <input type="radio" v-model="selectedGateway" :value="gw.key" class="d-none">
                  <div class="gateway-card">
                    <div class="d-flex align-items-center gap-3">
                      <div class="gateway-icon" :style="{ background: gw.color + '15' }">
                        <lucide-icon :name="mapGatewayIcon(gw.icon)" :style="{ color: gw.color }" />
                      </div>
                      <div>
                        <div class="fw-bold small">{{ gw.label }}</div>
                        <div class="text-muted gateway-description">
                          {{ gw.key === 'offline' ? ($t('Pay_via_bank') || 'Pay via bank transfer and upload proof of payment') : ($t('Pay_with') || 'Pay securely with') + ' ' + gw.label }}
                        </div>
                      </div>
                    </div>
                    <span class="gateway-check">{{ selectedGateway === gw.key ? '✓' : '○' }}</span>
                  </div>
                </label>
              </div>
            </div>
          </div>

          <!-- Bank Transfer Details (shown only when offline is selected) -->
          <div v-if="isOfflinePayment" class="billing-card mb-4">
            <div class="billing-card-header"><lucide-icon name="landmark" class="mr-2" />{{ $t('Bank_Transfer_Details') || 'Bank Transfer Details' }}</div>
            <div class="billing-card-body">
              <!-- Amount to transfer -->
              <div class="offline-amount-box mb-3">
                <p class="mb-1 text-muted small-label">{{ $t('Amount_to_Transfer') || 'Amount to Transfer' }}</p>
                <p class="mb-0 fw-bold amount-value">{{ currencySymbol }}{{ displayAmount }} {{ currencyCode }}</p>
              </div>

              <!-- Bank details grid -->
              <div v-if="hasBankDetails" class="bank-details-grid mb-3">
                <div v-if="bankDetails.bank_name" class="bank-detail-row">
                  <span class="bank-detail-label">{{ $t('Bank_Name') || 'Bank Name' }}</span>
                  <span class="bank-detail-value">{{ bankDetails.bank_name }}</span>
                </div>
                <div v-if="bankDetails.account_holder" class="bank-detail-row">
                  <span class="bank-detail-label">{{ $t('Account_Holder') || 'Account Holder' }}</span>
                  <span class="bank-detail-value">{{ bankDetails.account_holder }}</span>
                </div>
                <div v-if="bankDetails.account_number" class="bank-detail-row">
                  <span class="bank-detail-label">{{ $t('Account_Number') || 'Account Number' }}</span>
                  <span class="bank-detail-value">{{ bankDetails.account_number }}</span>
                </div>
                <div v-if="bankDetails.iban" class="bank-detail-row">
                  <span class="bank-detail-label">{{ $t('IBAN') || 'IBAN' }}</span>
                  <span class="bank-detail-value">{{ bankDetails.iban }}</span>
                </div>
                <div v-if="bankDetails.swift" class="bank-detail-row">
                  <span class="bank-detail-label">{{ $t('SWIFT_BIC') || 'SWIFT / BIC' }}</span>
                  <span class="bank-detail-value">{{ bankDetails.swift }}</span>
                </div>
                <div v-if="bankDetails.branch" class="bank-detail-row">
                  <span class="bank-detail-label">{{ $t('Branch') || 'Branch' }}</span>
                  <span class="bank-detail-value">{{ bankDetails.branch }}</span>
                </div>
              </div>

              <!-- Instructions -->
              <div v-if="bankDetails.instructions" class="bank-instructions mb-3">
                <p class="mb-1 fw-bold small-label">{{ $t('Instructions') || 'Instructions' }}</p>
                <p class="mb-0 text-muted small-text">{{ bankDetails.instructions }}</p>
              </div>

              <!-- No bank details warning -->
              <div v-if="!hasBankDetails" class="alert alert-warning d-flex align-items-center gap-2 mb-3 small">
                <lucide-icon name="alert-triangle" />
                {{ $t('Bank_details_not_configured') || 'Bank details are not configured yet. Please contact support.' }}
              </div>

              <hr>

              <!-- Proof of payment upload -->
              <div class="mb-2">
                <label class="fw-bold small-label mb-2">
                  <lucide-icon name="upload" class="mr-1" /> {{ $t('Upload_Proof') || 'Upload Proof of Payment' }} <span class="text-danger">*</span>
                </label>
                <div class="offline-upload-area"
                  @click="$refs.proofInput.click()"
                  @dragover.prevent="dragOver = true"
                  @dragleave="dragOver = false"
                  @drop.prevent="onDropProof"
                  :class="{ 'drag-over': dragOver }">
                  <input ref="proofInput" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" class="d-none" @change="onProofSelected">
                  <div v-if="!proofFile" class="text-center">
                    <lucide-icon name="upload" class="upload-icon" />
                    <p class="mb-1 fw-bold small-label">{{ $t('Click_or_drag') || 'Click or drag file here' }}</p>
                    <p class="mb-0 text-muted tiny-text">{{ $t('File_formats') || 'JPG, PNG, WebP or PDF (max 5MB)' }}</p>
                  </div>
                  <div v-else class="text-center">
                    <lucide-icon name="file-text" class="upload-icon-success" />
                    <p class="mb-0 fw-bold small-label">{{ proofFile.name }}</p>
                    <button type="button" class="btn btn-sm btn-outline-danger mt-2" @click.stop="removeProof">
                      <lucide-icon name="x" /> {{ $t('Remove') || 'Remove' }}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Submit -->
          <button v-if="gateways.length > 0" @click="submitPayment" :disabled="processing" class="btn btn-primary w-100 py-2">
            <span v-if="processing" class="spinner-border spinner-border-sm mr-2"></span>
            <lucide-icon v-else name="lock" class="mr-1" />
            {{ processing ? ($t('Processing') || 'Processing...') : submitButtonText }}
          </button>
          <p class="text-center text-muted small mt-3">
            <lucide-icon name="lock" class="mr-1" />
            {{ isOfflinePayment ? ($t('Proof_review_note') || 'Your proof will be reviewed by our team. Your plan activates after approval.') : ($t('Payment_secure') || 'Your payment is secured and encrypted. You will be redirected to complete payment.') }}
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import axios from "axios";

export default {
  name: "BillingCheckout",
  data() {
    return {
      loading: true,
      processing: false,
      plan: { name: "", price: 0, yearly_price: 0, savings_percent: 0, limits: {}, features: {} },
      gateways: [],
      selectedCycle: "monthly",
      selectedGateway: "",
      proofFile: null,
      dragOver: false,
      isUpgrade: null,
      pendingUpgrade: null,
      currencyCode: "USD",
      currencySymbol: "$",
      bankDetails: {},
    };
  },
  computed: {
    displayAmount() {
      const amt = this.selectedCycle === "yearly" ? this.plan.yearly_price : this.plan.price;
      return (amt || 0).toFixed(2);
    },
    selectedCycleLabel() {
      return this.selectedCycle === "yearly" ? (this.$t("Yearly") || "Yearly") : (this.$t("Monthly") || "Monthly");
    },
    isOfflinePayment() {
      return this.selectedGateway === 'offline';
    },
    hasBankDetails() {
      return !!(this.bankDetails.bank_name || this.bankDetails.account_number);
    },
    submitButtonText() {
      if (this.isOfflinePayment) {
        return this.$t('Submit_Payment_Proof') || 'Submit Payment Proof';
      }
      return (this.$t('Pay') || 'Pay') + ' ' + this.currencySymbol + this.displayAmount + ' ' + this.currencyCode;
    },
  },
  created() {
    this.selectedCycle = this.$route.query.cycle || "monthly";
    this.fetchCheckoutData();
  },
  methods: {
    mapGatewayIcon(biIcon) {
      const map = {
        'bi-credit-card-2-front': 'receipt',
        'bi-credit-card':         'receipt',
        'bi-paypal':              'wallet',
        'bi-currency-rupee':      'coins',
        'bi-cash-stack':          'banknote',
        'bi-cash-coin':           'coins',
        'bi-wallet2':             'wallet',
        'bi-bank':                'landmark',
      };
      return map[biIcon] || 'receipt';
    },
    async fetchCheckoutData() {
      this.loading = true;
      try {
        const planId = this.$route.params.id;
        const { data } = await axios.get(`/api/billing/checkout-data/${planId}`);
        this.plan = data.plan;
        this.gateways = data.gateways;
        this.isUpgrade = data.is_upgrade;
        this.pendingUpgrade = data.pending_upgrade || null;
        this.currencyCode = data.currency_code || "USD";
        this.currencySymbol = data.currency_symbol || "$";
        this.bankDetails = data.bank_details || {};
        if (this.gateways.length > 0) {
          this.selectedGateway = this.gateways[0].key;
        }
      } catch (e) {
        this.makeToast("danger", e.response?.data?.message || "Failed to load checkout data.", this.$t("Error") || "Error");
      }
      this.loading = false;
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true,
      });
    },
    onProofSelected(e) {
      const file = e.target.files[0];
      if (file) this.setProofFile(file);
    },
    onDropProof(e) {
      this.dragOver = false;
      const file = e.dataTransfer.files[0];
      if (file) this.setProofFile(file);
    },
    setProofFile(file) {
      const maxSize = 5 * 1024 * 1024;
      if (file.size > maxSize) {
        this.makeToast("warning", this.$t("File_too_large") || "File must be less than 5MB.", this.$t("Warning") || "Warning");
        return;
      }
      const allowed = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp', 'application/pdf'];
      if (!allowed.includes(file.type)) {
        this.makeToast("warning", this.$t("Invalid_file_type") || "Only JPG, PNG, WebP, and PDF files are allowed.", this.$t("Warning") || "Warning");
        return;
      }
      this.proofFile = file;
    },
    removeProof() {
      this.proofFile = null;
      if (this.$refs.proofInput) this.$refs.proofInput.value = '';
    },
    async submitPayment() {
      if (!this.selectedGateway) {
        this.makeToast("warning", this.$t("Select_gateway") || "Please select a payment method.", this.$t("Warning") || "Warning");
        return;
      }

      // Offline: require proof upload
      if (this.isOfflinePayment) {
        if (!this.proofFile) {
          this.makeToast("warning", this.$t("Upload_proof_required") || "Please upload proof of payment.", this.$t("Warning") || "Warning");
          return;
        }

        this.processing = true;
        try {
          const formData = new FormData();
          formData.append('plan_id', this.plan.id);
          formData.append('billing_cycle', this.selectedCycle);
          formData.append('offline_method', 'bank_transfer');
          formData.append('payment_proof', this.proofFile);

          const { data } = await axios.post("/api/billing/offline-payment", formData);

          if (data.success) {
            this.makeToast("success", data.message || "Payment proof submitted successfully.", this.$t("Success") || "Success");
            this.$router.push("/app/billing/current-plan");
          } else {
            this.makeToast("danger", data.message || "Request failed.", this.$t("Error") || "Error");
          }
        } catch (e) {
          this.makeToast("danger", e.response?.data?.message || "Submission failed. Please try again.", this.$t("Error") || "Error");
        } finally {
          this.processing = false;
        }
        return;
      }

      // Online payment flow
      this.processing = true;
      try {
        const { data } = await axios.post("/api/billing/checkout", {
          plan_id: this.plan.id,
          gateway: this.selectedGateway,
          billing_cycle: this.selectedCycle,
        });

        if (data.success && data.payment_url) {
          window.location.href = data.payment_url;
        } else {
          this.makeToast("danger", data.message || "Payment initialization failed.", this.$t("Error") || "Error");
        }
      } catch (e) {
        this.makeToast("danger", e.response?.data?.message || "Payment failed. Please try again.", this.$t("Error") || "Error");
      } finally {
        this.processing = false;
      }
    },
  },
};
</script>

<style scoped>
.offline-amount-box {
  background: #f0fdfa;
  border: 1px solid #c7d2fe;
  border-radius: 10px;
  padding: 0.85rem 1rem;
  text-align: center;
}
.amount-value {
  font-size: 1.35rem;
  color: #0d9488;
}
.bank-details-grid {
  display: flex;
  flex-direction: column;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  overflow: hidden;
}
.bank-detail-row {
  display: flex;
  justify-content: space-between;
  padding: 0.6rem 0.85rem;
  font-size: 0.85rem;
}
.bank-detail-row:not(:last-child) {
  border-bottom: 1px solid #e2e8f0;
}
.bank-detail-row:nth-child(even) {
  background: #f8fafc;
}
.bank-detail-label {
  color: #64748b;
  font-weight: 500;
}
.bank-detail-value {
  font-weight: 600;
  color: #1e293b;
  text-align: right;
  word-break: break-all;
}
.bank-instructions {
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 10px;
  padding: 0.75rem 1rem;
}
.offline-upload-area {
  border: 2px dashed #cbd5e1;
  border-radius: 12px;
  padding: 1.5rem 1rem;
  cursor: pointer;
  text-align: center;
  transition: border-color 0.2s, background 0.2s;
}
.offline-upload-area:hover,
.offline-upload-area.drag-over {
  border-color: #0d9488;
  background: #f0fdfa;
}
.upload-icon {
  font-size: 2rem;
  color: #94a3b8;
  display: block;
  margin-bottom: 0.5rem;
}
.upload-icon-success {
  font-size: 2rem;
  color: #10b981;
  display: block;
  margin-bottom: 0.5rem;
}
.small-label {
  font-size: 0.85rem;
}
.small-text {
  font-size: 0.82rem;
}
.tiny-text {
  font-size: 0.75rem;
}
</style>
