<template>
  <div>
    <!-- Initial app loader: shown immediately after refresh until core data is ready -->
    <div v-if="!Loading" class="initial-loader-overlay">
      <div class="initial-loader-dots">
        <span></span><span></span><span></span>
      </div>
    </div>

    <router-view v-if="Loading" class="app-fade-in"></router-view>

    <!-- Global offline sync fullscreen loader -->
    <div v-if="globalSyncActive" class="global-sync-overlay">
      <div class="global-sync-card">
        <div class="global-sync-spinner"></div>
        <h3 class="global-sync-title">
          {{ $t ? ($t('pos.Syncing_offline_sales') || 'Syncing offline sales') : 'Syncing offline sales' }}
        </h3>
        <p class="global-sync-subtitle">
          {{ $t ? ($t('pos.Syncing_offline_sales_help') || 'Please wait while your offline sales are being synchronized.') : 'Please wait while your offline sales are being synchronized.' }}
        </p>
      </div>
    </div>

    <customizer v-if="show_language && !isPosPage && getCustomizeButtonVisible"></customizer>

    <!-- Global limit-reached modal -->
    <div v-if="limitReachedVisible" class="limit-reached-overlay" @click.self="limitReachedVisible = false">
      <div class="limit-reached-card">
        <div class="limit-reached-icon">
          <svg width="32" height="32" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M5.07 19h13.86c1.5 0 2.47-1.6 1.73-2.88L13.73 4.24c-.74-1.28-2.72-1.28-3.46 0L3.34 16.12C2.6 17.4 3.57 19 5.07 19z"/></svg>
        </div>
        <h3 class="limit-reached-title">Plan Limit Reached</h3>
        <p class="limit-reached-message">{{ limitReachedMessage }}</p>
        <div class="limit-reached-actions">
          <button class="limit-reached-btn-upgrade" @click="goToUpgrade">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 19V5m-7 7 7-7 7 7"/></svg>
            Upgrade Plan
          </button>
          <button class="limit-reached-btn-dismiss" @click="limitReachedVisible = false">Dismiss</button>
        </div>
      </div>
    </div>
  </div>
</template>


<script>
import { mapActions, mapGetters } from "vuex";

export default {
  data() {
    return {
      Loading:false,
      globalSyncActive: false,
      limitReachedVisible: false,
      limitReachedMessage: '',
    };
  },
  computed: {
    
    ...mapGetters("config", ["getThemeMode", "getCustomizeButtonVisible"]),
    ...mapGetters(["isAuthenticated","show_language","currentUser"]),
    themeName() {
      return this.getThemeMode.dark ? "dark-theme" : " ";
    },
    rtl() {
      return this.getThemeMode.rtl ? "rtl" : " ";
    },

    isPosPage() {
      const p = String(this.$route.path || '');
      return p === '/app/pos' || p.startsWith('/app/pos_') || p.startsWith('/app/pos/');
    },
    titleTemplate() {
      return `%s | ${this.currentUser?.page_title_suffix || window.__pageTitleSuffix || "Ultimate Inventory With POS"}`;
    }
  },

  metaInfo() {
    return {
      // if no subcomponents specify a metaInfo.title, this title will be used
      title: window.__appName || "Stocky",
      titleTemplate: this.titleTemplate,

      bodyAttrs: {
        class: [this.themeName, "text-left"]
      },
      htmlAttrs: {
        dir: this.rtl
      },
      
    };
  },

  beforeDestroy() {
    // Clean up listeners
    try {
      if (typeof window !== 'undefined' && window.Fire && window.Fire.$off) {
        window.Fire.$off('offline-sync:start', this.onGlobalSyncStart);
        window.Fire.$off('offline-sync:end', this.onGlobalSyncEnd);
        window.Fire.$off('offline-sync:auto-result', this.onGlobalSyncResult);
        window.Fire.$off('show-limit-reached', this.showLimitReached);
      }
    } catch (e) {}
  },
  methods:{
    ...mapActions([
      "refreshUserPermissions",
    ]),
    ...mapActions("config", ["initPrimaryColor"]),
    async initializeApp() {
      try {
        this.initPrimaryColor();
        // Ensure initial permissions and user info are fetched
        await this.refreshUserPermissions(this.$i18n);
      } catch (e) {
        // ignore; guards/interceptors will handle routing on auth errors
      } finally {
        this.Loading = true;
        // Signal that the app rendered initial route and is allowed to hide loader when no pending requests
        if (window) {
          window.__appReadyToHideLoader = true;
          if (typeof window.__hideInitialLoaderIfDone === 'function') {
            window.__hideInitialLoaderIfDone();
          }
        }
      }
    },
    showLimitReached(message) {
      this.limitReachedMessage = message;
      this.limitReachedVisible = true;
    },
    goToUpgrade() {
      this.limitReachedVisible = false;
      this.$router.push('/app/billing/change-plan').catch(() => {});
    },
    onGlobalSyncStart() {
      this.globalSyncActive = true;
    },
    onGlobalSyncEnd() {
      this.globalSyncActive = false;
    },
    onGlobalSyncResult(payload) {
      try {
        const syncedCount = Number(payload && payload.syncedCount || 0);
        const lastError = payload && payload.lastError;
        // If at least one offline sale was synced successfully and there is no error,
        // reload the current page to reflect updated data everywhere – except when
        // the user is on the POS screen with a potentially active cart. In that
        // case, POS itself will decide if/when to reload via its own confirmation
        // flow to avoid disrupting an in‑progress checkout.
        if (syncedCount > 0 && !lastError) {
          const isPosRoute = this.$route &&
            (this.$route.name === 'pos' ||
             String(this.$route.path || '').includes('/app/pos'));
          if (!isPosRoute) {
            if (typeof window !== 'undefined' && window.location && typeof window.location.reload === 'function') {
              window.location.reload();
            }
          }
        }
      } catch (e) {}
    },
  },

  beforeMount() {
    // Replace timeout with awaited initialization
    this.initializeApp();
  },
  
  mounted() {
    // Listen for global offline sync start/end/result events
    try {
      if (typeof window !== 'undefined' && window.Fire && window.Fire.$on) {
        window.Fire.$on('offline-sync:start', this.onGlobalSyncStart);
        window.Fire.$on('offline-sync:end', this.onGlobalSyncEnd);
        window.Fire.$on('offline-sync:auto-result', this.onGlobalSyncResult);
        window.Fire.$on('show-limit-reached', this.showLimitReached);
      }
    } catch (e) {}
  },
};
</script>
<style scoped>
.initial-loader-overlay {
  position: fixed;
  inset: 0;
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
}

.global-sync-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  backdrop-filter: blur(4px);
}

.initial-loader-dots {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.initial-loader-dots span {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: #8b5cf6;
  display: inline-block;
  animation: initial-loader-dots 1.2s ease-in-out infinite;
}

.initial-loader-dots span:nth-child(1) { animation-delay: 0s; }
.initial-loader-dots span:nth-child(2) { animation-delay: 0.15s; }
.initial-loader-dots span:nth-child(3) { animation-delay: 0.3s; }

@keyframes initial-loader-dots {
  0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
  40%           { transform: scale(1);   opacity: 1;   }
}

.app-fade-in {
  animation: app-fade-in 0.5s ease-out both;
}

@keyframes app-fade-in {
  from { opacity: 0; }
  to   { opacity: 1; }
}

.global-sync-card {
  background: linear-gradient(135deg, #111827, #1f2933);
  border-radius: 18px;
  padding: 24px 32px;
  box-shadow:
    0 20px 40px rgba(0, 0, 0, 0.45),
    0 0 0 1px rgba(148, 163, 184, 0.25);
  display: flex;
  flex-direction: column;
  align-items: center;
  max-width: 340px;
  width: 90%;
  text-align: center;
  color: #e5e7eb;
}

.global-sync-spinner {
  width: 40px;
  height: 40px;
  border-radius: 999px;
  border: 3px solid rgba(148, 163, 184, 0.35);
  border-top-color: #38bdf8;
  animation: global-sync-spin 0.9s linear infinite;
  margin-bottom: 16px;
}

.global-sync-title {
  font-size: 1.05rem;
  font-weight: 600;
  margin: 0 0 6px;
}

.global-sync-subtitle {
  font-size: 0.85rem;
  opacity: 0.85;
  margin: 0;
}

@keyframes global-sync-spin {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}

/* Limit-reached modal */
.limit-reached-overlay {
  position: fixed;
  inset: 0;
  z-index: 10000;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.45);
  backdrop-filter: blur(4px);
}

.limit-reached-card {
  background: #fff;
  border-radius: 16px;
  max-width: 460px;
  width: 90%;
  padding: 2.5rem;
  text-align: center;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}

.limit-reached-icon {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: #fef3c7;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 1.25rem;
}

.limit-reached-title {
  font-size: 1.25rem;
  font-weight: 700;
  color: #1e293b;
  margin: 0 0 0.5rem;
}

.limit-reached-message {
  color: #64748b;
  font-size: 0.9rem;
  line-height: 1.5;
  margin: 0 0 1.5rem;
}

.limit-reached-actions {
  display: flex;
  gap: 0.75rem;
  justify-content: center;
}

.limit-reached-btn-upgrade {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.625rem 1.5rem;
  background: #6366f1;
  color: #fff;
  border: none;
  border-radius: 8px;
  font-weight: 600;
  font-size: 0.875rem;
  cursor: pointer;
  transition: background 0.2s;
}

.limit-reached-btn-upgrade:hover {
  background: #4f46e5;
}

.limit-reached-btn-dismiss {
  padding: 0.625rem 1.5rem;
  background: #f1f5f9;
  color: #475569;
  border: none;
  border-radius: 8px;
  font-weight: 600;
  font-size: 0.875rem;
  cursor: pointer;
  transition: background 0.2s;
}

.limit-reached-btn-dismiss:hover {
  background: #e2e8f0;
}
</style>
