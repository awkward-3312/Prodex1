<template>
  <div class="main-content">
    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-else class="billing-page change-plan-page">
      <div class="row mb-3">
        <div class="col-12 d-flex align-items-center justify-content-between flex-wrap">
          <div>
            <h2 class="billing-title">{{ $t('Choose_Plan') || 'Choose Your Plan' }}</h2>
            <p class="text-muted mb-0">{{ $t('Select_plan_fits') || 'Select the plan that fits your business.' }}</p>
          </div>
          <router-link to="/app/billing/current-plan" class="btn btn-outline-secondary btn-sm">
            <lucide-icon name="arrow-left" class="mr-1" /> {{ $t('Back') || 'Back' }}
          </router-link>
        </div>
      </div>

      <!-- Pending Upgrade Alert -->
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
              {{ $t('Pending_upgrade_wait') || 'Please wait for admin approval before submitting another request.' }}
            </p>
            <router-link to="/app/billing/current-plan" class="btn btn-outline-secondary btn-sm">
              <lucide-icon name="arrow-left" class="mr-1" /> {{ $t('Back_to_plan') || 'Back to Current Plan' }}
            </router-link>
          </div>
        </div>
      </div>

      <!-- Billing cycle toggle -->
      <div class="text-center mb-4">
        <div class="cycle-toggle d-inline-flex">
          <button :class="{ active: cycle === 'monthly' }" @click="cycle = 'monthly'" class="cycle-btn">
            {{ $t('Monthly') || 'Monthly' }}
          </button>
          <button :class="{ active: cycle === 'yearly' }" @click="cycle = 'yearly'" class="cycle-btn">
            {{ $t('Yearly') || 'Yearly' }}
            <span class="save-badge">{{ $t('Save') || 'Save' }}</span>
          </button>
        </div>
      </div>

      <!-- Plans grid -->
      <div class="row justify-content-center">
        <div v-for="plan in plans" :key="plan.id" class="col-lg-4 col-md-6 mb-4">
          <div :class="['plan-card', { 'plan-current': plan.is_current }]">
            <div v-if="plan.is_current" class="plan-current-badge">
              {{ $t('Current') || 'Current' }}
            </div>

            <h4 class="plan-name">{{ plan.name }}</h4>
            <p class="plan-slug text-muted">{{ plan.slug }}</p>

            <div class="plan-price">
              <span class="price-amount">{{ currencySymbol }}{{ cycle === 'yearly' ? plan.yearly_price.toFixed(2) : plan.price.toFixed(2) }}</span>
              <span class="price-period">/{{ cycle === 'yearly' ? ($t('year') || 'year') : ($t('month') || 'month') }}</span>
            </div>

            <div v-if="cycle === 'yearly' && plan.savings_percent > 0" class="plan-savings">
              {{ $t('Save') || 'Save' }} {{ plan.savings_percent }}%
            </div>

            <hr>

            <!-- Limits -->
            <div class="plan-section-label">{{ $t('Limits') || 'Limits' }}</div>
            <div v-for="(limit, key) in plan.limits" :key="'l-' + key" class="plan-feature-row">
              <i :class="'bi ' + limit.icon" class="plan-feature-icon text-primary"></i>
              <span>{{ limit.display }} {{ limit.label }}</span>
            </div>

            <!-- Features -->
            <div class="plan-section-label mt-3">{{ $t('Features') || 'Features' }}</div>
            <div v-for="(feat, key) in plan.features" :key="'f-' + key" class="plan-feature-row">
              <lucide-icon name="check" class="text-success plan-feature-icon" />
              <span>{{ feat.label }}</span>
            </div>
            <div v-for="(feat, key) in missingFeatures(plan)" :key="'m-' + key" class="plan-feature-row text-muted">
              <lucide-icon name="x" class="plan-feature-icon" />
              <span>{{ feat.label }}</span>
            </div>

            <div class="mt-4">
              <button v-if="plan.is_current" class="btn btn-outline-secondary w-100" disabled>
                <lucide-icon name="check" class="mr-1" /> {{ $t('Current_Plan') || 'Current Plan' }}
              </button>
              <button v-else-if="pendingUpgrade" class="btn btn-outline-secondary w-100" disabled>
                <lucide-icon name="clock" class="mr-1" /> {{ $t('Upgrade_Pending') || 'Upgrade Pending' }}
              </button>
              <router-link v-else :to="'/app/billing/checkout/' + plan.id + '?cycle=' + cycle"
                class="btn btn-primary w-100">
                <template v-if="hasActive && currentPlanPrice !== null && plan.price > currentPlanPrice">
                  <lucide-icon name="arrow-up" class="mr-1" /> {{ $t('Upgrade') || 'Upgrade' }}
                </template>
                <template v-else-if="hasActive">
                  <lucide-icon name="arrow-down" class="mr-1" /> {{ $t('Switch') || 'Switch' }}
                </template>
                <template v-else>
                  <lucide-icon name="arrow-right" class="mr-1" /> {{ $t('Get_Started') || 'Get Started' }}
                </template>
              </router-link>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import axios from "axios";

export default {
  name: "ChangePlan",
  data() {
    return {
      loading: true,
      plans: [],
      cycle: "monthly",
      currentPlanId: null,
      currentPlanPrice: null,
      hasActive: false,
      pendingUpgrade: null,
      allFeatures: {},
      currencySymbol: "$",
    };
  },
  created() {
    this.fetchPlans();
  },
  methods: {
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    },
    async fetchPlans() {
      this.loading = true;
      try {
        const { data } = await axios.get("/api/billing/plans");
        this.plans = data.plans;
        this.currentPlanId = data.current_plan_id;
        this.currentPlanPrice = data.current_plan_price;
        this.hasActive = data.has_active;
        this.pendingUpgrade = data.pending_upgrade || null;
        this.currencySymbol = data.currency_symbol || "$";
        if (this.plans.length > 0) {
          this.allFeatures = this.plans[0].all_features || {};
        }
      } catch (e) {
        this.makeToast("danger", e.response?.data?.message || "Failed to load plans.", "Error");
      }
      this.loading = false;
    },
    missingFeatures(plan) {
      const active = plan.features || {};
      const all = this.allFeatures || {};
      const missing = {};
      for (const key of Object.keys(all)) {
        if (!active[key]) missing[key] = all[key];
      }
      return missing;
    },
  },
};
</script>
