<template>
  <div class="main-content">
    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>

    <!-- Capture in progress -->
    <div v-else-if="capturing" class="result-page text-center">
      <div class="result-icon result-processing">
        <span class="spinner-border text-primary result-spinner"></span>
      </div>
      <h2 class="result-title">{{ $t('Processing_Payment') || 'Processing Payment...' }}</h2>
      <p class="text-muted result-desc">
        {{ $t('Confirming_with_paypal') || 'We are confirming your payment with PayPal. Please wait a moment.' }}
      </p>
    </div>

    <!-- Success -->
    <div v-else-if="payment && payment.status === 'paid'" class="result-page text-center">
      <div class="result-icon result-success">
        <lucide-icon name="check" />
      </div>
      <h2 class="result-title">{{ $t('Payment_Successful') || 'Payment Successful!' }}</h2>
      <p class="text-muted result-desc">
        {{ $t('Subscription_activated') || 'Your subscription has been activated. You\'re all set!' }}
      </p>

      <div class="billing-card text-left mx-auto mb-4 result-detail-card">
        <div class="billing-card-body">
          <div class="detail-row"><span class="text-muted">{{ $t('Invoice') || 'Invoice' }}</span><span class="fw-bold">{{ payment.invoice_number }}</span></div>
          <div class="detail-row"><span class="text-muted">{{ $t('Plan') || 'Plan' }}</span><span class="fw-bold">{{ payment.plan_name }}</span></div>
          <div class="detail-row"><span class="text-muted">{{ $t('Billing_Cycle') || 'Billing' }}</span><span class="fw-bold">{{ capitalize(payment.billing_cycle) }}</span></div>
          <div class="detail-row"><span class="text-muted">{{ $t('Amount') || 'Amount' }}</span><span class="fw-bold">{{ currencySymbol }}{{ payment.amount.toFixed(2) }} {{ payment.currency }}</span></div>
          <div class="detail-row"><span class="text-muted">{{ $t('Gateway') || 'Gateway' }}</span><span class="fw-bold">{{ payment.gateway_label }}</span></div>
          <div class="detail-row">
            <span class="text-muted">{{ $t('Status') || 'Status' }}</span>
            <span class="badge-billing badge-paid"><lucide-icon name="check" class="mr-1" />{{ $t('Paid') || 'Paid' }}</span>
          </div>
          <div v-if="subscription && subscription.ends_at" class="detail-row">
            <span class="text-muted">{{ $t('Valid_Until') || 'Valid Until' }}</span>
            <span class="fw-bold">{{ formatDate(subscription.ends_at) }}</span>
          </div>
        </div>
      </div>

      <div class="d-flex gap-3 justify-content-center">
        <router-link to="/app/dashboard" class="btn btn-primary">
          <lucide-icon name="arrow-right" class="mr-1" /> {{ $t('Go_to_Dashboard') || 'Go to Dashboard' }}
        </router-link>
        <router-link to="/app/billing/history" class="btn btn-outline-secondary">
          <lucide-icon name="clock" class="mr-1" /> {{ $t('Billing_History') || 'Billing History' }}
        </router-link>
      </div>
    </div>

    <!-- Pending (waiting for webhook / still processing) -->
    <div v-else-if="payment && payment.status === 'pending'" class="result-page text-center">
      <div class="result-icon result-processing">
        <span class="spinner-border text-warning result-spinner"></span>
      </div>
      <h2 class="result-title">{{ $t('Payment_Processing') || 'Payment Processing' }}</h2>
      <p class="text-muted result-desc">
        {{ $t('Payment_being_verified') || 'Your payment is being verified. This page will update automatically.' }}
      </p>
      <router-link to="/app/billing/current-plan" class="btn btn-outline-secondary mt-3">
        <lucide-icon name="arrow-left" class="mr-1" /> {{ $t('Back_to_plan') || 'Back to Current Plan' }}
      </router-link>
    </div>

    <!-- Fallback: no payment data -->
    <div v-else class="result-page text-center">
      <div class="result-icon result-success">
        <lucide-icon name="check" />
      </div>
      <h2 class="result-title">{{ $t('Thank_You') || 'Thank You!' }}</h2>
      <p class="text-muted result-desc">
        {{ $t('Payment_submitted') || 'Your payment has been submitted.' }}
      </p>
      <router-link to="/app/billing/current-plan" class="btn btn-primary">
        <lucide-icon name="arrow-right" class="mr-1" /> {{ $t('View_Plan') || 'View Current Plan' }}
      </router-link>
    </div>
  </div>
</template>

<script>
import axios from "axios";

export default {
  name: "PaymentSuccess",
  data() {
    return {
      loading: true,
      capturing: false,
      payment: null,
      subscription: null,
      pollTimer: null,
      pollCount: 0,
      currencySymbol: "$",
    };
  },
  created() {
    this.init();
  },
  beforeDestroy() {
    this.stopPolling();
  },
  methods: {
    async init() {
      const paymentId = this.$route.query.payment_id;
      const token     = this.$route.query.token; // PayPal order ID appended by PayPal

      if (!paymentId) {
        this.loading = false;
        return;
      }

      // If we have a PayPal token, the callback should have already captured,
      // but as a safety net try the API capture endpoint too.
      if (token) {
        this.loading = false;
        this.capturing = true;
        try {
          await axios.post("/api/billing/paypal/capture", {
            payment_id: parseInt(paymentId, 10),
            token: token,
          });
        } catch (e) {
          // Capture may have already happened in the callback — continue to fetch.
        }
        this.capturing = false;
      }

      await this.fetchPayment();
      this.loading = false;

      // If still pending, poll until the webhook or a delayed capture resolves it.
      if (this.payment && this.payment.status === "pending") {
        this.startPolling();
      }
    },

    async fetchPayment() {
      const id = this.$route.query.payment_id;
      if (!id) return;
      try {
        const { data } = await axios.get(`/api/billing/payment/${id}`);
        this.payment = data.payment;
        this.currencySymbol = data.currency_symbol || "$";
        this.subscription = data.subscription;
      } catch (e) { /* silent */ }
    },

    startPolling() {
      this.pollCount = 0;
      this.pollTimer = setInterval(async () => {
        this.pollCount++;
        await this.fetchPayment();

        if (!this.payment || this.payment.status !== "pending" || this.pollCount >= 20) {
          this.stopPolling();
        }
      }, 3000);
    },

    stopPolling() {
      if (this.pollTimer) {
        clearInterval(this.pollTimer);
        this.pollTimer = null;
      }
    },

    formatDate(d) {
      if (!d) return "—";
      return new Date(d).toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
    },
    capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ""; },
  },
};
</script>

