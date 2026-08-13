<template>
  <div class="main-content">
    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-else class="result-page text-center">
      <div class="result-icon result-failed">
        <lucide-icon name="x" />
      </div>
      <h2 class="result-title">{{ $t('Payment_Failed') || 'Payment Failed' }}</h2>
      <p class="text-muted result-desc">
        {{ $t('Payment_failed_desc') || "We couldn't process your payment. You can try again or choose a different method." }}
      </p>

      <div v-if="payment" class="billing-card text-left mx-auto mb-4 result-detail-card">
        <div class="billing-card-body">
          <div class="detail-row"><span class="text-muted">{{ $t('Plan') || 'Plan' }}</span><span class="fw-bold">{{ payment.plan_name }}</span></div>
          <div class="detail-row"><span class="text-muted">{{ $t('Amount') || 'Amount' }}</span><span class="fw-bold">{{ currencySymbol }}{{ payment.amount.toFixed(2) }} {{ payment.currency }}</span></div>
          <div class="detail-row"><span class="text-muted">{{ $t('Gateway') || 'Gateway' }}</span><span class="fw-bold">{{ payment.gateway_label }}</span></div>
          <div class="detail-row">
            <span class="text-muted">{{ $t('Status') || 'Status' }}</span>
            <span class="badge-billing badge-failed"><lucide-icon name="x" class="mr-1" />{{ $t('Failed') || 'Failed' }}</span>
          </div>
        </div>
      </div>

      <div class="d-flex gap-3 justify-content-center flex-wrap">
        <router-link v-if="payment" :to="'/app/billing/checkout/' + payment.plan_id + '?cycle=' + payment.billing_cycle" class="btn btn-primary">
          <lucide-icon name="rotate-cw" class="mr-1" /> {{ $t('Try_Again') || 'Try Again' }}
        </router-link>
        <router-link to="/app/billing/change-plan" class="btn btn-outline-secondary">
          <lucide-icon name="boxes" class="mr-1" /> {{ $t('View_Plans') || 'View Plans' }}
        </router-link>
      </div>

      <div class="tips-box mt-4 text-left mx-auto">
        <p class="fw-bold small"><lucide-icon name="info" class="mr-1" />{{ $t('Common_reasons') || 'Common reasons for payment failure:' }}</p>
        <ul class="small text-muted mb-0 pl-3">
          <li>{{ $t('Insufficient_funds') || 'Insufficient funds in your account' }}</li>
          <li>{{ $t('Card_declined') || 'Card was declined by your bank' }}</li>
          <li>{{ $t('Payment_cancelled') || 'Payment was cancelled before completing' }}</li>
          <li>{{ $t('Gateway_issue') || 'Temporary gateway issue — please retry' }}</li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script>
import axios from "axios";

export default {
  name: "PaymentFailed",
  data() {
    return { loading: true, payment: null, currencySymbol: "$" };
  },
  created() {
    this.fetchPayment();
  },
  methods: {
    async fetchPayment() {
      const id = this.$route.query.payment_id;
      if (!id) { this.loading = false; return; }
      try {
        const { data } = await axios.get(`/api/billing/payment/${id}`);
        this.payment = data.payment;
        this.currencySymbol = data.currency_symbol || "$";
      } catch (e) { /* silent */ }
      this.loading = false;
    },
  },
};
</script>

