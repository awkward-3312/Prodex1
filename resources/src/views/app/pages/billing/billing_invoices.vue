<template>
  <div class="main-content">
    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-else class="billing-page">
      <div class="row mb-3">
        <div class="col-12 d-flex align-items-center justify-content-between flex-wrap">
          <div>
            <h2 class="billing-title">{{ $t('Invoices') || 'Invoices' }}</h2>
            <p class="text-muted mb-0">{{ $t('Download_invoices') || 'Download your subscription invoices.' }}</p>
          </div>
          <router-link to="/app/billing/current-plan" class="btn btn-outline-secondary btn-sm">
            <lucide-icon name="arrow-left" class="mr-1" /> {{ $t('Current_Plan') || 'Current Plan' }}
          </router-link>
        </div>
      </div>

      <!-- Invoices table -->
      <div class="billing-card">
        <div class="billing-card-header d-flex align-items-center justify-content-between">
          <span><lucide-icon name="file" class="mr-2" />{{ $t('Invoice_List') || 'Invoice List' }}</span>
          <span class="text-muted small">{{ total }} {{ $t('invoices') || 'invoice(s)' }}</span>
        </div>

        <div v-if="invoices.length > 0" class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th class="th-billing">{{ $t('Invoice') || 'Invoice #' }}</th>
                <th class="th-billing">{{ $t('Plan') || 'Plan' }}</th>
                <th class="th-billing">{{ $t('Amount') || 'Amount' }}</th>
                <th class="th-billing">{{ $t('Gateway') || 'Gateway' }}</th>
                <th class="th-billing">{{ $t('Billing_Cycle') || 'Cycle' }}</th>
                <th class="th-billing">{{ $t('Paid_Date') || 'Paid Date' }}</th>
                <th class="th-billing"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="inv in invoices" :key="inv.id">
                <td class="td-billing"><span class="fw-bold">{{ inv.invoice_number || '—' }}</span></td>
                <td class="td-billing">{{ inv.plan_name }}</td>
                <td class="td-billing">
                  <span class="fw-bold">{{ currencySymbol }}{{ inv.total.toFixed(2) }}</span>
                  <span class="text-muted small">{{ inv.currency }}</span>
                </td>
                <td class="td-billing">{{ inv.gateway_label }}</td>
                <td class="td-billing">{{ capitalize(inv.billing_cycle) }}</td>
                <td class="td-billing">{{ formatDate(inv.paid_at) }}</td>
                <td class="td-billing">
                  <button @click="downloadPdf(inv)"
                     :disabled="inv._downloading"
                     class="btn btn-outline-primary btn-sm download-btn">
                    <lucide-icon v-if="!inv._downloading" name="download" class="mr-1" /><span v-else class="spinner-border spinner-border-sm mr-1"></span>
                    {{ inv._downloading ? $t('Downloading') || 'Downloading…' : $t('PDF') || 'PDF' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-else class="text-center py-5">
          <lucide-icon name="file" class="text-muted billing-empty-state-icon" />
          <p class="text-muted mt-2">{{ $t('No_invoices') || 'No invoices yet.' }}</p>
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
  name: "BillingInvoices",
  data() {
    return {
      loading: true,
      invoices: [],
      currentPage: 1,
      lastPage: 1,
      total: 0,
      currencySymbol: "$",
    };
  },
  created() {
    this.fetchInvoices();
  },
  methods: {
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    },
    async fetchInvoices(page) {
      this.loading = true;
      try {
        const { data } = await axios.get("/api/billing/invoices", {
          params: { page: page || 1, per_page: 15 },
        });
        this.invoices = data.invoices;
        this.currencySymbol = data.currency_symbol || "$";
        this.currentPage = data.current_page;
        this.lastPage = data.last_page;
        this.total = data.total;
      } catch (e) {
        this.makeToast("danger", e.response?.data?.message || "Failed to load invoices.", "Error");
      }
      this.loading = false;
    },
    goToPage(p) {
      if (p >= 1 && p <= this.lastPage) this.fetchInvoices(p);
    },
    formatDate(d) {
      if (!d) return "—";
      return new Date(d).toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
    },
    capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ""; },
    async downloadPdf(inv) {
      this.$set(inv, '_downloading', true);
      try {
        const response = await axios.get("/api/billing/invoices/" + inv.id + "/download", {
          responseType: "blob",
        });
        const blob = new Blob([response.data], { type: "application/pdf" });
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;
        link.download = "Invoice-" + (inv.invoice_number || inv.id) + ".pdf";
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);
      } catch (e) {
        let msg = "Failed to download invoice.";
        if (e.response?.data instanceof Blob) {
          try {
            const text = await e.response.data.text();
            const json = JSON.parse(text);
            if (json.message) msg = json.message;
          } catch (_) {}
        }
        this.makeToast("danger", msg, "Error");
      }
      this.$set(inv, '_downloading', false);
    },
  },
};
</script>

