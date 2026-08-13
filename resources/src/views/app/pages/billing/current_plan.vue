<template>
  <div class="main-content">
    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-else class="billing-page current-plan-page">
      <div class="row mb-4">
        <div class="col-12">
          <h2 class="billing-title">{{ $t('Current_Plan') || 'Current Plan' }}</h2>
          <p class="text-muted">{{ $t('Manage_your_subscription') || 'Manage your subscription and billing details.' }}</p>
        </div>
      </div>

      <!-- No subscription -->
      <div v-if="!subscription" class="billing-card text-center py-5">
        <lucide-icon name="credit-card" class="billing-empty-icon" />
        <h4 class="mt-3">{{ $t('No_active_subscription') || 'No Active Subscription' }}</h4>
        <p class="text-muted">{{ $t('Choose_plan_to_start') || 'Choose a plan to start using the application.' }}</p>
        <router-link to="/app/billing/change-plan" class="btn btn-primary mt-2">
          <lucide-icon name="arrow-right" class="mr-1" /> {{ $t('Choose_a_Plan') || 'Choose a Plan' }}
        </router-link>
      </div>

      <!-- Expired / Plan Ended — prompt to resubscribe -->
      <div v-else-if="subscription && subscription.status === 'expired'" class="billing-card text-center py-5">
        <div class="expired-icon-wrapper mb-3">
          <lucide-icon name="x" />
        </div>
        <h4 class="mt-2">{{ $t('Plan_Has_Ended') || 'Your Plan Has Ended' }}</h4>
        <p class="text-muted billing-plan-ended-desc">
          {{ $t('Plan_ended_message') || 'Your subscription has expired. Resubscribe now to regain access to all features and continue managing your business.' }}
        </p>
        <div class="expired-plan-info mt-3 mb-3">
          <span class="text-muted">{{ $t('Previous_Plan') || 'Previous Plan' }}:</span>
          <strong>{{ plan ? plan.name : '—' }}</strong>
          <span class="mx-2">|</span>
          <span class="text-muted">{{ $t('Expired_On') || 'Expired On' }}:</span>
          <strong>{{ formatDate(subscription.ends_at) }}</strong>
        </div>
        <router-link to="/app/billing/change-plan" class="btn btn-primary btn-lg mt-2">
          <lucide-icon name="arrow-right" class="mr-1" /> {{ $t('Resubscribe_Now') || 'Resubscribe Now' }}
        </router-link>
      </div>

      <!-- Active subscription -->
      <div v-else>
        <!-- Pending Upgrade Alert -->
        <div v-if="pendingUpgrade" class="pending-upgrade-alert mb-4">
          <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-start gap-3">
              <div class="pending-upgrade-icon">
                <lucide-icon name="clock" />
              </div>
              <div>
                <h5 class="mb-1">{{ $t('Pending_Upgrade') || 'Pending Upgrade Request' }}</h5>
                <p class="mb-0 text-muted">
                  {{ $t('Pending_upgrade_message') || 'You have a pending upgrade to' }}
                  <strong>{{ pendingUpgrade.plan_name }}</strong>
                  ({{ pendingUpgrade.billing_cycle === 'yearly' ? ($t('Yearly') || 'Yearly') : ($t('Monthly') || 'Monthly') }} — {{ currencySymbol }}{{ pendingUpgrade.amount.toFixed(2) }} {{ pendingUpgrade.currency }}).
                  {{ $t('Pending_upgrade_wait') || 'Please wait for admin approval before submitting another request.' }}
                </p>
              </div>
            </div>
            <button @click="showCancelUpgradeModal = true"
              class="btn btn-outline-danger btn-sm flex-shrink-0"
              :disabled="cancelUpgradeLoading">
              <lucide-icon name="x" class="mr-1" /> {{ $t('Cancel_Upgrade') || 'Cancel Upgrade' }}
            </button>
          </div>
        </div>

        <div class="row">
          <!-- Plan overview card -->
          <div class="col-lg-8">
            <div class="billing-card mb-4">
              <div class="billing-card-header d-flex align-items-center justify-content-between">
                <h5 class="mb-0">{{ plan.name }}</h5>
                <span :class="'badge-billing badge-' + subscription.status">
                  {{ subscription.status.charAt(0).toUpperCase() + subscription.status.slice(1) }}
                </span>
              </div>
              <div class="billing-card-body">
                <div class="row">
                  <div class="col-md-6">
                    <div class="billing-detail-row">
                      <span class="billing-label">{{ $t('Billing_Cycle') || 'Billing Cycle' }}</span>
                      <span class="billing-value">{{ subscription.billing_cycle === 'yearly' ? ($t('Yearly') || 'Yearly') : ($t('Monthly') || 'Monthly') }}</span>
                    </div>
                    <div class="billing-detail-row">
                      <span class="billing-label">{{ $t('Amount') || 'Amount' }}</span>
                      <span class="billing-value fw-bold">{{ currencySymbol }}{{ subscription.amount.toFixed(2) }} {{ subscription.currency }}</span>
                    </div>
                    <div class="billing-detail-row">
                      <span class="billing-label">{{ $t('start_date') || 'Start Date' }}</span>
                      <span class="billing-value">{{ formatDate(subscription.starts_at) }}</span>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="billing-detail-row">
                      <span class="billing-label">{{ $t('End_Date') || 'End Date' }}</span>
                      <span class="billing-value">{{ formatDate(subscription.ends_at) }}</span>
                    </div>
                    <div v-if="subscription.trial_ends_at" class="billing-detail-row">
                      <span class="billing-label">{{ $t('Trial_Ends') || 'Trial Ends' }}</span>
                      <span class="billing-value">{{ formatDate(subscription.trial_ends_at) }}</span>
                    </div>
                    <div class="billing-detail-row">
                      <span class="billing-label">{{ $t('Days_Remaining') || 'Days Remaining' }}</span>
                      <span class="billing-value">
                        <span :class="subscription.days_remaining <= 7 ? 'text-danger fw-bold' : ''">
                          {{ subscription.days_remaining }} {{ $t('days') || 'days' }}
                        </span>
                      </span>
                    </div>
                  </div>
                </div>

                <!-- Pending notice -->
                <div v-if="subscription.status === 'pending'" class="pending-notice mt-3">
                  <lucide-icon name="info" class="mr-1" />
                  {{ $t('Subscription_pending_notice') || 'Your subscription is currently pending approval.' }}
                </div>

                <!-- Cancelled notice -->
                <div v-if="subscription.is_cancelled" class="cancelled-notice mt-3">
                  <lucide-icon name="alert-triangle" class="mr-1" />
                  {{ $t('Subscription_cancelled_notice') || 'Your subscription is cancelled and will expire on' }}
                  {{ formatDate(subscription.ends_at) }}.
                </div>

                <div class="mt-3 d-flex gap-2 flex-wrap">
                  <router-link v-if="subscription.status !== 'pending' && !pendingUpgrade" to="/app/billing/change-plan" class="btn btn-primary btn-sm">
                    <lucide-icon name="arrow-up" class="mr-1" /> {{ $t('Change_Plan') || 'Change Plan' }}
                  </router-link>
                  <span v-else-if="pendingUpgrade" class="btn btn-outline-secondary btn-sm disabled" style="pointer-events: none; opacity: 0.6;">
                    <lucide-icon name="clock" class="mr-1" /> {{ $t('Upgrade_Pending') || 'Upgrade Pending' }}
                  </span>
                  <router-link to="/app/billing/history" class="btn btn-outline-secondary btn-sm">
                    <lucide-icon name="clock" class="mr-1" /> {{ $t('Billing_History') || 'Billing History' }}
                  </router-link>
                  <router-link to="/app/billing/invoices" class="btn btn-outline-secondary btn-sm">
                    <lucide-icon name="file" class="mr-1" /> {{ $t('Invoices') || 'Invoices' }}
                  </router-link>

                  <!-- Cancel button (shown when active) -->
                  <button v-if="subscription.status === 'active'" @click="showCancelModal = true"
                    class="btn btn-outline-danger btn-sm">
                    <lucide-icon name="x" class="mr-1" /> {{ $t('Cancel_Subscription') || 'Cancel Subscription' }}
                  </button>

                  <!-- Resume button (shown when cancelled but still within period) -->
                  <button v-if="subscription.can_resume" @click="resumeSubscription"
                    :disabled="resumeLoading"
                    class="btn btn-success btn-sm">
                    <span v-if="resumeLoading" class="spinner-border spinner-border-sm mr-1"></span>
                    <lucide-icon v-else name="rotate-cw" class="mr-1" />
                    {{ $t('Resume_Subscription') || 'Resume Subscription' }}
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Plan Limits & Features -->
          <div class="col-lg-4">
            <!-- Usage Limits -->
            <div class="billing-card mb-4">
              <div class="billing-card-header">
                <h5 class="mb-0">{{ $t('Usage_Limits') || 'Usage Limits' }}</h5>
              </div>
              <div class="billing-card-body">
                <div v-if="usageLoading" class="text-center py-3">
                  <div class="spinner spinner-primary spinner-sm"></div>
                </div>
                <div v-else-if="planUsage && planUsage.has_plan">
                  <div v-for="(limit, key) in planUsage.limits" :key="key" class="usage-limit-item">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                      <span class="usage-limit-label">
                        <i :class="limit.icon" class="usage-limit-icon"></i>
                        {{ limit.label }}
                      </span>
                      <span class="usage-limit-value" :class="limitValueClass(limit)">
                        <template v-if="limit.unlimited">
                          {{ limit.current }} / ∞
                        </template>
                        <template v-else>
                          {{ limit.current }} / {{ limit.max }}
                        </template>
                      </span>
                    </div>
                    <div v-if="!limit.unlimited" class="usage-progress-track">
                      <div class="usage-progress-bar" :style="{ width: limit.percentage + '%' }" :class="progressBarClass(limit)"></div>
                    </div>
                  </div>
                </div>
                <div v-else class="text-center py-3">
                  <p class="text-muted small mb-0">{{ $t('No_plan_data') || 'No plan data available.' }}</p>
                </div>
              </div>
            </div>

            <!-- Features -->
            <div class="billing-card">
              <div class="billing-card-header">
                <h5 class="mb-0">{{ $t('Features') || 'Features' }}</h5>
              </div>
              <div class="billing-card-body">
                <div v-if="usageLoading" class="text-center py-3">
                  <div class="spinner spinner-primary spinner-sm"></div>
                </div>
                <div v-else-if="planUsage && planUsage.has_plan">
                  <div v-for="(feat, key) in planUsage.features" :key="key" class="feature-item" :class="feat.enabled ? 'feature-enabled' : 'feature-disabled'">
                    <lucide-icon :name="feat.enabled ? 'check' : 'x'" class="feature-status-icon" />
                    <span class="feature-label">{{ feat.label }}</span>
                  </div>
                </div>
                <p v-else class="text-muted small mb-0">
                  {{ $t('No_features_listed') || 'No features listed for this plan.' }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Cancel Subscription Modal -->
      <div v-if="showCancelModal" class="modal-overlay" @click.self="showCancelModal = false">
        <div class="confirm-modal">
          <div class="confirm-modal-icon">
            <lucide-icon name="alert-triangle" />
          </div>
          <h4>{{ $t('Cancel_Subscription') || 'Cancel Subscription' }}</h4>
          <p class="text-muted">
            {{ $t('Cancel_confirm_message') || 'Are you sure you want to cancel? Your subscription will remain active until the end of the current billing period.' }}
          </p>
          <div v-if="subscription" class="cancel-info mb-3">
            <span class="text-muted">{{ $t('Active_until') || 'Active until' }}:</span>
            <strong>{{ formatDate(subscription.ends_at) }}</strong>
          </div>
          <div class="d-flex gap-2 justify-content-center">
            <button @click="showCancelModal = false" class="btn btn-outline-secondary">
              {{ $t('Keep_Subscription') || 'Keep Subscription' }}
            </button>
            <button @click="cancelSubscription" :disabled="cancelLoading" class="btn btn-danger">
              <span v-if="cancelLoading" class="spinner-border spinner-border-sm mr-1"></span>
              {{ $t('Confirm_Cancel') || 'Yes, Cancel' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Cancel Pending Upgrade Modal -->
      <div v-if="showCancelUpgradeModal" class="modal-overlay" @click.self="showCancelUpgradeModal = false">
        <div class="confirm-modal">
          <div class="confirm-modal-icon">
            <lucide-icon name="alert-triangle" />
          </div>
          <h4>{{ $t('Cancel_Pending_Upgrade') || 'Cancel Pending Upgrade' }}</h4>
          <p class="text-muted">
            {{ $t('Cancel_upgrade_confirm') || 'Are you sure you want to cancel your pending upgrade to' }}
            <strong>{{ pendingUpgrade ? pendingUpgrade.plan_name : '' }}</strong>?
            {{ $t('Cancel_upgrade_note') || 'Your current plan will remain unchanged.' }}
          </p>
          <div class="d-flex gap-2 justify-content-center">
            <button @click="showCancelUpgradeModal = false" class="btn btn-outline-secondary">
              {{ $t('Keep_Upgrade') || 'Keep Upgrade' }}
            </button>
            <button @click="cancelPendingUpgrade" :disabled="cancelUpgradeLoading" class="btn btn-danger">
              <span v-if="cancelUpgradeLoading" class="spinner-border spinner-border-sm mr-1"></span>
              {{ $t('Confirm_Cancel_Upgrade') || 'Yes, Cancel Upgrade' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import axios from "axios";
import { mapGetters } from "vuex";

export default {
  name: "CurrentPlan",
  data() {
    return {
      loading: true,
      subscription: null,
      plan: null,
      hasActive: false,
      pendingUpgrade: null,
      currencySymbol: "$",
      showCancelModal: false,
      cancelLoading: false,
      showCancelUpgradeModal: false,
      cancelUpgradeLoading: false,
      resumeLoading: false,
      planUsage: null,
      usageLoading: true,
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
  },
  created() {
    this.fetchCurrentPlan();
    this.fetchPlanUsage();
  },
  methods: {
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    },
    async fetchCurrentPlan() {
      this.loading = true;
      try {
        const { data } = await axios.get("/api/billing/current-plan");
        this.subscription = data.subscription;
        this.plan = data.plan;
        this.hasActive = data.has_active;
        this.pendingUpgrade = data.pending_upgrade || null;
        this.currencySymbol = data.currency_symbol || "$";
      } catch (e) {
        this.makeToast("danger", e.response?.data?.message || "Failed to load plan data.", "Error");
      }
      this.loading = false;
    },

    async fetchPlanUsage() {
      this.usageLoading = true;
      try {
        const { data } = await axios.get("/api/billing/plan-usage");
        this.planUsage = data;
      } catch (e) {
        // Fall back to window.__planSummary if available
        if (window.__planSummary) {
          this.planUsage = window.__planSummary;
        }
      }
      this.usageLoading = false;
    },

    limitValueClass(limit) {
      if (limit.unlimited) return 'text-primary';
      if (limit.percentage >= 100) return 'text-danger fw-bold';
      if (limit.percentage >= 80) return 'text-warning fw-bold';
      return '';
    },

    progressBarClass(limit) {
      if (limit.percentage >= 100) return 'bg-danger';
      if (limit.percentage >= 80) return 'bg-warning';
      return 'bg-primary';
    },

    async cancelSubscription() {
      this.cancelLoading = true;
      try {
        const { data } = await axios.post("/api/billing/cancel");
        this.makeToast("success", data.message || "Subscription cancelled.", "Success");
        this.showCancelModal = false;
        await this.fetchCurrentPlan();
      } catch (e) {
        this.makeToast("danger", e.response?.data?.message || "Failed to cancel subscription.", "Error");
      }
      this.cancelLoading = false;
    },

    async resumeSubscription() {
      this.resumeLoading = true;
      try {
        const { data } = await axios.post("/api/billing/resume");
        this.makeToast("success", data.message || "Subscription resumed.", "Success");
        await this.fetchCurrentPlan();
      } catch (e) {
        this.makeToast("danger", e.response?.data?.message || "Failed to resume subscription.", "Error");
      }
      this.resumeLoading = false;
    },

    async cancelPendingUpgrade() {
      this.cancelUpgradeLoading = true;
      try {
        const { data } = await axios.post("/api/billing/cancel-upgrade");
        this.makeToast("success", data.message || "Pending upgrade cancelled.", "Success");
        this.showCancelUpgradeModal = false;
        await this.fetchCurrentPlan();
      } catch (e) {
        this.makeToast("danger", e.response?.data?.message || "Failed to cancel upgrade.", "Error");
      }
      this.cancelUpgradeLoading = false;
    },

    formatDate(d) {
      if (!d) return "—";
      return new Date(d).toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
    },
  },
};
</script>
