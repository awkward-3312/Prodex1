<template>
  <div class="main-content">
    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-else class="billing-page">
      <div class="row mb-3">
        <div class="col-12 d-flex align-items-center justify-content-between flex-wrap">
          <div>
            <h2 class="billing-title">{{ $t('Billing_History') || 'Billing & Invoices' }}</h2>
            <p class="text-muted mb-0">{{ $t('View_payments') || 'View your subscription payments and invoices.' }}</p>
          </div>
          <router-link to="/app/billing/current-plan" class="btn btn-outline-secondary btn-sm">
            <lucide-icon name="arrow-left" class="mr-1" /> {{ $t('Current_Plan') || 'Current Plan' }}
          </router-link>
        </div>
      </div>

      <!-- Payment history table -->
      <div class="billing-card">
        <div class="billing-card-header d-flex align-items-center justify-content-between">
          <span><lucide-icon name="clock" class="mr-2" />{{ $t('Payment_History') || 'Payment History' }}</span>
          <span class="text-muted small">{{ total }} {{ $t('transactions') || 'transaction(s)' }}</span>
        </div>

        <div v-if="payments.length > 0" class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th class="th-billing">{{ $t('Invoice') || 'Invoice' }}</th>
                <th class="th-billing">{{ $t('Plan') || 'Plan' }}</th>
                <th class="th-billing">{{ $t('Amount') || 'Amount' }}</th>
                <th class="th-billing">{{ $t('Gateway') || 'Gateway' }}</th>
                <th class="th-billing">{{ $t('Status') || 'Status' }}</th>
                <th class="th-billing">{{ $t('Date') || 'Date' }}</th>
                <th class="th-billing"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in payments" :key="p.id">
                <td class="td-billing"><span class="fw-bold">{{ p.invoice_number || '—' }}</span></td>
                <td class="td-billing">{{ p.plan_name }} <span class="text-muted small">({{ capitalize(p.billing_cycle) }})</span></td>
                <td class="td-billing"><span class="fw-bold">{{ currencySymbol }}{{ p.amount.toFixed(2) }}</span> <span class="text-muted small">{{ p.currency }}</span></td>
                <td class="td-billing">{{ p.gateway_label }}</td>
                <td class="td-billing">
                  <span :class="'badge-billing badge-' + p.status">{{ capitalize(p.status) }}</span>
                </td>
                <td class="td-billing">{{ formatDate(p.created_at) }}</td>
                <td class="td-billing">
                  <button v-if="p.status === 'failed'" @click="retryPayment(p)" :disabled="p._retrying"
                     class="btn btn-outline-primary btn-sm action-btn">
                    <span v-if="p._retrying" class="spinner-border spinner-border-sm mr-1"></span>
                    <lucide-icon v-else name="rotate-cw" class="mr-1" />{{ $t('Retry') || 'Retry' }}
                  </button>
                  <button v-if="p.status === 'paid'" @click="downloadInvoice(p)" :disabled="p._downloading"
                     class="btn btn-outline-secondary btn-sm action-btn">
                    <span v-if="p._downloading" class="spinner-border spinner-border-sm mr-1"></span>
                    <lucide-icon v-else name="download" class="mr-1" />{{ $t('PDF') || 'PDF' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-else class="text-center py-5">
          <lucide-icon name="inbox" class="text-muted billing-empty-state-icon" />
          <p class="text-muted mt-2">{{ $t('No_payments') || 'No payment records yet.' }}</p>
        </div>

        <!-- Pagination -->
        <div v-if="lastPage > 1" class="billing-card-body d-flex justify-content-center">
          <nav>
            <ul class="pagination pagination-sm mb-0">
              <li :class="['page-item', { disabled: currentPage <= 1 }]">
                <a class="page-link" href="#" @click.prevent="goToPage(currentPage - 1)">&laquo;</a>
              </li>
              <li v-for="p in lastPage" :key="p" :class="['page-item', { active: p === currentPage }]">
                <a class="page-link" href="#" @click.prevent="goToPage(p)">{{ p }}</a>
              </li>
              <li :class="['page-item', { disabled: currentPage >= lastPage }]">
                <a class="page-link" href="#" @click.prevent="goToPage(currentPage + 1)">&raquo;</a>
              </li>
            </ul>
          </nav>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import axios from "axios";

export default {
  name: "BillingHistory",
  data() {
    return {
      loading: true,
      payments: [],
      currentPage: 1,
      lastPage: 1,
      total: 0,
      currencySymbol: "$",
    };
  },
  created() {
    this.fetchHistory();
  },
  methods: {
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    },
    async fetchHistory(page) {
      this.loading = true;
      try {
        const { data } = await axios.get("/api/billing/history", {
          params: { page: page || 1, per_page: 15 },
        });
        this.payments = data.payments;
        this.currencySymbol = data.currency_symbol || "$";
        this.currentPage = data.current_page;
        this.lastPage = data.last_page;
        this.total = data.total;
      } catch (e) {
        this.makeToast("danger", e.response?.data?.message || "Failed to load history.", "Error");
      }
      this.loading = false;
    },
    goToPage(p) {
      if (p >= 1 && p <= this.lastPage) this.fetchHistory(p);
    },
    async downloadInvoice(payment) {
      this.$set(payment, '_downloading', true);
      try {
        const response = await axios.get(`/api/billing/invoices/${payment.id}/download`, {
          responseType: 'blob',
        });
        const blob = new Blob([response.data], { type: 'application/pdf' });
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `Invoice-${payment.invoice_number || payment.id}.pdf`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);
      } catch (e) {
        this.makeToast("danger", this.$t('Download_failed') || 'Failed to download invoice.', "Error");
      }
      this.$set(payment, '_downloading', false);
    },
    async retryPayment(payment) {
      this.$set(payment, '_retrying', true);
      try {
        const { data } = await axios.post(`/api/billing/retry/${payment.id}`);
        this.$router.push(`/app/billing/checkout/${data.plan_id}?cycle=${data.billing_cycle}`);
      } catch (e) {
        this.makeToast("danger", e.response?.data?.message || "Cannot retry this payment.", "Error");
      }
      this.$set(payment, '_retrying', false);
    },
    formatDate(d) {
      if (!d) return "—";
      return new Date(d).toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
    },
    capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ""; },
  },
};
</script>

