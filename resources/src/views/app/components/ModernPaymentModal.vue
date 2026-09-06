
<template>
  <div class="modern-payment-modal">
    <b-modal
      ref="paymentModal"
      hide-footer
      hide-header
      size="xl"
      id="modern_payment_modal"
      class="payment-modal-wrapper premium-payment-modal-large"
      centered
      @hidden="resetForm"
      body-class="modal-body-custom"
      modal-class="premium-modal px-next"
    >
      <div class="payment-container px-next">
        <!-- Enhanced Header -->
        <div class="payment-header">
          <div class="header-left">
            <div class="icon-wrapper">
              <lucide-icon name="wallet" />
          </div>
            <div class="header-text">
              <h2 class="modal-title">{{ isEditMode ? $t('Edit_Payment') : $t('Payment_Checkout') }}</h2>
            </div>
          </div>
          <button type="button" class="close-button" @click="$refs.paymentModal.hide()">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>
        </div>

        <!-- Content Area -->
        <div class="payment-content">
          <!-- Left Side: Transaction Info -->
          <div class="transaction-info">
            <!-- Amount Card -->
            <div class="amount-card">
              <div class="amount-card-header">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <circle cx="12" cy="12" r="10"></circle>
                  <path d="M12 6v6l4 2"></path>
                </svg>
                <span>{{$t('Transaction_Summary')}}</span>
            </div>
              <div class="amount-display">
                <span class="currency-label">{{$t('Total_Amount')}}</span>
                <span class="amount-large">{{ formatCurrency(paymentForm.amountDue) }}</span>
            </div>
        </div>

            <!-- Payment Status -->
            <div class="payment-status-card">
              <h3 class="card-title">{{$t('Payment_Breakdown')}}</h3>
              <div class="status-grid">
                <div class="status-box" v-if="storeCreditApplied > 0">
                  <div class="status-icon paying">
                    <lucide-icon name="ticket" />
                  </div>
                  <div class="status-details">
                    <span class="status-name">{{ $t('Store_Credit') || 'Vale aplicado' }}</span>
                    <span class="status-amount">-{{ formatCurrency(storeCreditApplied) }}</span>
                  </div>
                </div>
                <div class="status-box">
                  <div class="status-icon paying">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                      <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                    </svg>
                  </div>
                  <div class="status-details">
                    <span class="status-name">{{$t('Paying')}}</span>
                    <span class="status-amount">{{ formatCurrency(totalPaid) }}</span>
                  </div>
                </div>
                <div class="status-box">
                  <div class="status-icon balance">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                      <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                    </svg>
                  </div>
                  <div class="status-details">
                    <span class="status-name">{{$t('Balance')}}</span>
                    <span class="status-amount balance-text">{{ formatCurrency(balance) }}</span>
                  </div>
                </div>
                <div class="status-box">
                  <div class="status-icon change">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                      <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                    </svg>
                  </div>
                  <div class="status-details">
                    <span class="status-name">{{$t('Change')}}</span>
                    <span class="status-amount change-text">{{ formatCurrency(changeReturn) }}</span>
                  </div>
                </div>
              </div>
            </div>

          </div>

          <!-- Right Side: Payment Form -->
          <div class="payment-form-area">
            <form @submit.prevent="submitPayment" class="enhanced-form">
              <!-- Payment Methods header removed as requested -->

              <!-- Multi Payment Lines with Right Vertical Quick Amounts -->
              <div class="form-group">
                <div class="payment-lines-layout">
                  <!-- Left: Lines -->
                  <div class="payment-lines-list">
                    <div v-for="(p, idx) in paymentLines" :key="idx" class="payment-line-card">
                      <div class="payment-line-header">
                        <div class="line-badge">{{ idx + 1 }}</div>
                        <span class="line-title">{{$t('Payment')}} #{{ idx + 1 }}</span>
                        <!-- Show remove (X) only for additional lines so user can return to a single payment method -->
                        <button
                          v-if="paymentLines.length > 1 && idx > 0"
                          type="button"
                          class="line-remove-btn"
                          @click="removePaymentLine(idx)"
                          :aria-label="$t('Remove_Payment_Line')"
                        >
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                          </svg>
                        </button>
                      </div>
                      <div class="payment-line-body">
                        <div class="input-field">
                          <label class="field-label">{{$t('Amount')}}</label>
                          <div class="input-with-icon">
                            <input 
                              v-model.number="p.amount"
                              @input="onAmountInput(idx)"
                              type="text"
                              step="0.01"
                              min="0"
                              inputmode="decimal"
                              pattern="\d*(\.\d*)?"
                              :disabled="Number(paymentForm.amountDue) === 0"
                              placeholder="0.00"
                              class="form-input" 
                            />
                          </div>
                        </div>
                        <div class="input-field">
                          <label class="field-label">{{$t('Account')}}</label>
                          <select v-model="p.accountId" class="form-select">
                            <option value="">{{$t('Select_Account')}}</option>
                            <option v-for="a in resolvedAccounts" :key="a.id" :value="a.id">{{ a.account_name || a.name }}</option>
                          </select>
                        </div>
                      <div class="form-group">
                        <div class="payment-methods">
                          <div
                            v-for="m in resolvedPaymentMethods"
                            :key="m.id"
                            class="method-card"
                            :class="{ selected: String(p.paymentMethodId) === String(m.id) }"
                            @click="changePaymentMethod(p, m)"
                          >
                            <div class="method-content">
                              <div class="method-icon-wrapper">
                                <lucide-icon :name="m.icon" />
                              </div>
                              <span class="method-label">{{ m.name }}</span>
                            </div>
                            <div v-if="String(p.paymentMethodId) === String(m.id)" class="selected-indicator">
                              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                              </svg>
                            </div>
                          </div>
                        </div>
                      </div>
                      <!-- Per-line Credit Card Section -->
                      <div class="form-group" v-if="isLineCreditCard(p) && usesStripeCardProcessor && stripeKey">
                        <div class="credit-card-section">
                          <div class="cc-header">
                            <span class="field-label">{{$t('Credit_Card')}}</span>
                          </div>

                          <!-- New Card Form (Stripe Elements) - saved cards disabled -->
                          <div class="new-card-form">
                            <div :id="'card-element-' + idx" class="stripe-card-element"></div>
                            <small class="text-muted">{{$t('Card_Info_Secure_Stripe')}}</small>
                          </div>
                        </div>
                      </div>
                      <div class="form-group" v-if="isLineCreditCard(p) && usesExternalCardProcessor">
                        <div class="external-card-section">
                          <div class="external-card-message">
                            <lucide-icon name="credit-card" />
                            <span>Terminal bancaria</span>
                          </div>
                          <div class="external-card-grid">
                            <div class="input-field">
                              <label class="field-label">Referencia</label>
                              <input
                                v-model.trim="p.cardReference"
                                type="text"
                                class="form-input"
                                placeholder="Referencia del voucher"
                              />
                            </div>
                            <div class="input-field">
                              <label class="field-label">Autorización</label>
                              <input
                                v-model.trim="p.authorizationCode"
                                type="text"
                                class="form-input"
                                placeholder="Código de autorización"
                              />
                            </div>
                          </div>
                        </div>
                      </div>
                      </div>
                    </div>
                    <button v-if="Number(paymentForm.amountDue) > 0" type="button" class="action-btn cancel-btn add-line-btn" @click="addPaymentLine">
                      <lucide-icon name="plus" /> {{$t('Add_Payment_Method')}}
                    </button>
                  </div>

                  <!-- Right: Vertical Quick Amounts -->
                  <div class="quick-amount-rail">
                    <div class="quick-amount-vertical">
                      <button
                        type="button"
                        class="quick-btn"
                        v-for="amt in quickAmountOptions"
                        :key="amt"
                        @click="setQuickAmount(amt)"
                      >
                        {{ formatCurrency(amt) }}
                      </button>
                    </div>
                  </div>
                </div>
              </div>

          

              <!-- Payment Date Only -->
              <div class="form-group">
                <div class="input-field">
                  <label class="field-label">{{$t('Payment_Date')}} *</label>
                  <input v-model="paymentForm.date" type="date" class="form-select" />
                </div>
              </div>

              <!-- Notes -->
              <div class="form-group">
                <div class="dual-input">
                  <div class="input-field">
                    <label class="field-label">{{$t('Payment_note')}}</label>
                    <textarea v-model="paymentNote" rows="3" class="form-textarea" :placeholder="$t('Add_Payment_Note')"></textarea>
                  </div>
                  <div class="input-field">
                    <label class="field-label">{{$t('sale_note')}}</label>
                    <textarea v-model="saleNote" rows="3" class="form-textarea" :placeholder="$t('Add_Sale_Note')"></textarea>
                  </div>
                </div>
              </div>

              <!-- Kitchen routing (only when the Kitchen Display feature is enabled) -->
              <div class="form-group" v-if="kitchenEnabled">
                <label class="field-label">{{ $t('KitchenRouting') || 'Kitchen' }}</label>
                <div class="kitchen-routing">
                  <label class="kitchen-routing-option" :class="{ active: kitchenAction === 'send' }">
                    <input type="radio" value="send" v-model="kitchenAction" />
                    {{ $t('SendToKitchen') || 'Send to Kitchen' }}
                  </label>
                  <label class="kitchen-routing-option" :class="{ active: kitchenAction === 'none' }">
                    <input type="radio" value="none" v-model="kitchenAction" />
                    {{ $t('SaveWithoutSending') || 'Save Without Sending' }}
                  </label>
                  <label class="kitchen-routing-option" :class="{ active: kitchenAction === 'later' }">
                    <input type="radio" value="later" v-model="kitchenAction" />
                    {{ $t('SendLater') || 'Send Later' }}
                  </label>
                </div>
              </div>

              <!-- Email/SMS (hidden in offline mode) -->
              <div class="form-group" v-if="isOnline">
                <div class="checkboxes-group">
                  <label class="checkbox-item">
                    <input type="checkbox" v-model="sendEmail" />
                    {{$t('Send_Email_Receipt')}}
                  </label>
                  <label class="checkbox-item">
                    <input type="checkbox" v-model="sendSMS" />
                    {{$t('Send_SMS_Receipt')}}
                  </label>
                </div>
              </div>

        </form>
          </div>
        </div>
      </div>
      <!-- Footer Action Bar -->
      <div class="payment-footer">
        <button
          type="button"
          class="footer-btn footer-cancel"
          @click="$refs.paymentModal.hide()"
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="18" y1="6" x2="6" y2="18"></line>
            <line x1="6" y1="6" x2="18" y2="18"></line>
          </svg>
          {{$t('Cancel')}}
        </button>
        <button
          type="button"
          class="footer-btn footer-submit"
          :disabled="isSubmitting"
          @click="submitPayment"
        >
          <span v-if="!isSubmitting" class="btn-content">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
              <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
            </svg>
            {{ isEditMode ? $t('Update_Payment') : $t('Complete_Payment') }}
          </span>
          <span v-else class="btn-content">
            <span class="loading-spinner"></span>
            {{$t('Processing')}}...
          </span>
        </button>
      </div>
    </b-modal>
  </div>
</template>

<script>
import { loadStripe } from "@stripe/stripe-js";
import Util from "../../../utils";
import { getPriceDecimals } from "../../../utils/priceFormat";
export default {
  name: 'ModernPaymentModal',
  props: {
    paymentMethods: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    savedPaymentMethods: { type: Array, default: () => [] },
    currency: { type: String, default: '$' },
    // Data required to submit payment like in old POS logic
    clientId: { type: [Number, String], default: null },
    warehouseId: { type: [Number, String], default: null },
    cashDrawerId: { type: [Number, String], default: null },
    sale: { type: Object, default: () => ({}) },
    details: { type: Array, default: () => [] },
    grandTotal: { type: Number, default: 0 },
    discountFromPoints: { type: Number, default: 0 },
    usedPoints: { type: Number, default: 0 },
    // Client credit control
    clientCreditLimit: { type: Number, default: 0 },
    clientNetBalance: { type: Number, default: 0 },
    // API endpoints
    createPosUrl: { type: String, default: 'pos/create_pos' },
    // Optional Stripe publishable key (to mirror old POS behavior)
    stripeKey: { type: String, default: '' },
    cardProcessingMode: { type: String, default: 'external_terminal' },
    // Optional: when paying from a loaded draft sale
    draftSaleId: { type: [Number, String], default: null },
    // Cotización -> Venta POS traceability (Option A): set only when the cart
    // was pre-loaded from a quotation. Forwarded to /pos/create_pos so the
    // emitted sale is linked to its source quotation.
    sourceQuotationId: { type: [Number, String], default: null },
    // POS online/offline state (controls some UI like email/SMS options)
    isOnline: { type: Boolean, default: true },
    // System defaults from settings (used when opening modal and when adding new payment lines)
    defaultAccountId: { type: [Number, String], default: null },
    defaultPaymentMethodId: { type: [Number, String], default: null },
    // Promotion checkout context — forwarded as-is to /pos/create_pos so the
    // backend can re-evaluate promotions and create PromotionUsage rows. Without
    // this, sales submitted through the modern modal never persist promotions.
    promotionContext: { type: Object, default: () => ({}) },
    storeCreditVouchers: { type: Array, default: () => [] }
  },
  data() {
    return {
      isEditMode: false,
      isSubmitting: false,
      paymentProcessing: false,
      // Stable identifier for the current checkout; generated once via crypto.randomUUID()
      // and reused for:
      //  - immediate online API request
      //  - offline save payload
      //  - offline sync retries
      currentSaleUuid: null,
      transactionId: 'TRX-20251017-001',
      transactionStatus: 'Pending',
      stripe: null,
      cardElement: null,
      cardElementsByLine: {},
      activeCardLineIndex: 0,
      paymentLines: [],
      sendEmail: false,
      sendSMS: false,
      paymentNote: '',
      saleNote: '',
      // Kitchen routing choice for this sale: 'send' | 'none' | 'later'. Defaults to 'none'
      // so the existing checkout flow is unchanged when the feature is off or not chosen.
      kitchenAction: 'none',
      isCardInputComplete: false,
      paymentForm: {
        amountDue: 0,
        accountId: '',
        date: new Date().toISOString().split('T')[0], // Will be updated in created() or openModal()
        reference: '',
        notes: ''
      }
    };
  },
  watch: {
  },
  computed: {
    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    // Whether the Kitchen Display feature is enabled (system setting, exposed via auth store).
    kitchenEnabled() {
      const user = this.$store && this.$store.getters && this.$store.getters.currentUser;
      return !!(user && user.enable_kitchen_display);
    },
    totalPaid() {
      return (this.paymentLines || []).reduce((sum, line) => sum + (Number(line.amount) || 0), 0);
    },
    storeCreditApplied() {
      return (this.storeCreditVouchers || []).reduce((sum, voucher) => sum + (Number(voucher.amount) || 0), 0);
    },
    balance() {
      const amountDue = this.paymentForm.amountDue || 0;
      const paid = this.totalPaid;
      return Math.max(0, amountDue - paid);
    },
    changeReturn() {
      const over = this.totalPaid - (this.paymentForm.amountDue || 0);
      return over > 0 ? over : 0;
    },
    resolvedPaymentMethods() {
      const methods = (this.paymentMethods || []).map(m => ({
        ...m,
        icon: m.icon || this.getPaymentIcon(m)
      }));
      const isCash = (m) => !!(m && m.name && m.name.toLowerCase().includes('cash'));
      const cash = methods.filter(isCash);
      const others = methods.filter(m => !isCash(m));
      return cash.concat(others);
    },
    resolvedAccounts() {
      return this.accounts || [];
    },
    anyCreditCardUsed() {
      return (this.paymentLines || []).some(line => {
        const pm = this.resolvedPaymentMethods.find(m => String(m.id) === String(line.paymentMethodId));
        return this.isCreditCardMethod(pm);
      });
    },
    usesStripeCardProcessor() {
      return this.cardProcessingMode === 'stripe';
    },
    usesExternalCardProcessor() {
      return this.cardProcessingMode !== 'stripe';
    },
    anyStripeCreditCardUsed() {
      return this.usesStripeCardProcessor && this.anyCreditCardUsed;
    }
    ,
    quickAmountOptions() {
      const base = [this.paymentForm && this.paymentForm.amountDue, 15, 20, 50, 100, 200]
        .map(v => Number(v))
        .filter(v => Number.isFinite(v));
      const seen = new Set();
      const unique = [];
      for (const v of base) {
        const key = v.toFixed(this.priceDecimals);
        if (!seen.has(key)) {
          seen.add(key);
          unique.push(parseFloat(key));
        }
      }
      return unique;
    }
  },
  methods: {
    // Get current date in configured timezone (YYYY-MM-DD format)
    getCurrentDateInTimezone() {
      try {
        // Get timezone from Vuex store getter (from get_user_auth API)
        // or default to UTC
        const timezone = (this.$store && this.$store.getters && this.$store.getters.getTimezone) || 'UTC';
        
        // Format current date in the specified timezone
        const now = new Date();
        const formatter = new Intl.DateTimeFormat('en-CA', {
          timeZone: timezone,
          year: 'numeric',
          month: '2-digit',
          day: '2-digit'
        });
        return formatter.format(now);
      } catch (e) {
        // Fallback to UTC if timezone is invalid or formatting fails
        return new Date().toISOString().split('T')[0];
      }
    },
    hasAnyNewCardToTokenize() {
      // Saved cards are disabled; any credit card line requires a fresh token.
      return this.usesStripeCardProcessor && (this.paymentLines || []).some((line) => this.isLineCreditCard(line));
    },
    isCreditCardMethod(method) {
      if (!method || !method.name) return false;
      const name = method.name.trim().toLowerCase();
      return String(method.id) === '1'
        || name === 'credit card'
        || name.includes('tarjeta')
        || name.includes('card')
        || name.includes('credit')
        || name.includes('debit')
        || name.includes('tpe');
    },
    isLineCreditCard(line) {
      if (!line) return false;
      const pm = this.resolvedPaymentMethods.find(m => String(m.id) === String(line.paymentMethodId));
      return this.isCreditCardMethod(pm);
    },
    changePaymentMethod(line, method) {
      // Stripe mode requires tenant Stripe credentials; external terminal does not.
      if (this.isCreditCardMethod(method) && this.usesStripeCardProcessor && !this.stripeKey) {
        this.makeToast('warning', this.$t ? this.$t('credit_card_account_not_available') : 'Credit card account not available', this.$t ? this.$t('Warning') : 'Warning');
        // keep existing selection; if none, fallback to cash
        if (!line.paymentMethodId) {
          const cashId = this.getCashMethodId();
          if (cashId) line.paymentMethodId = cashId;
        }
        return;
      }
      line.paymentMethodId = method.id;
      const idx = (this.paymentLines || []).indexOf(line);

      if (this.isCreditCardMethod(method)) {
        if (this.usesStripeCardProcessor) {
          this.activeCardLineIndex = idx >= 0 ? idx : 0;
          this.$nextTick(() => this.loadStripePayment(this.activeCardLineIndex));
        } else if (idx >= 0) {
          this.destroyCardElementForLine(idx);
        }
      } else if (idx >= 0) {
        // Clean up any existing card element if switching away from credit card
        this.destroyCardElementForLine(idx);
      }
    },
    //------ Toast (mirror old POS behavior)
    makeToast(variant, msg, title) {
      if (this.$root && this.$root.$bvToast) {
        this.$root.$bvToast.toast(msg, {
          title: title,
          variant: variant,
          solid: true
        });
      } else {
        // Fallback
        console && console.warn && console.warn(`[${variant}] ${title}: ${msg}`);
      }
    },
    getApiErrorMessage(error, fallback = 'Invalid data') {
      const data = error && error.response ? error.response.data : error;

      if (typeof data === 'string' && data.trim()) {
        return data;
      }

      if (data && typeof data === 'object') {
        if (data.message) return String(data.message);
        if (data.error) return String(data.error);

        if (data.errors && typeof data.errors === 'object') {
          const values = Object.values(data.errors).reduce((all, value) => {
            if (Array.isArray(value)) return all.concat(value);
            if (value !== undefined && value !== null) all.push(value);
            return all;
          }, []);
          const first = values.find(value => value !== undefined && value !== null && String(value).trim());
          if (first) return String(first);
        }
      }

      if (error && error.message && error.message !== 'Network Error') {
        return String(error.message);
      }

      return fallback;
    },
    isTrueNetworkError(error) {
      if (!error || typeof error !== 'object') return false;
      if (error.response) return false;
      return error.message === 'Network Error' || !!error.request;
    },
    notifyInvoicePrintStarted() {
      this.makeToast && this.makeToast(
        'success',
        'Factura generada y enviada al flujo de impresión.',
        this.$t ? this.$t('Success') : 'Success'
      );
    },
    getPaymentIcon(method) {
      const name = ((method && method.name) || '').toLowerCase();
      if (name.includes('cash')) return 'wallet';
      if (name.includes('card') || name.includes('credit')) return 'credit-card';
      if (name.includes('bank') || name.includes('transfer')) return 'landmark';
      if (name.includes('cheque') || name.includes('check')) return 'file';
      return 'wallet';
    },
    getCashMethodId() {
      const list = this.resolvedPaymentMethods || [];
      const found = list.find(m => m && m.name && m.name.toLowerCase().includes('cash'));
      return found ? found.id : null;
    },
    getDefaultPaymentMethodId() {
      const id = this.defaultPaymentMethodId;
      if (id !== null && id !== undefined && id !== '') {
        const list = this.resolvedPaymentMethods || [];
        const found = list.find(m => m && String(m.id) === String(id));
        if (found) return found.id;
      }
      return this.getCashMethodId();
    },
    getDefaultAccountId() {
      const id = this.defaultAccountId;
      if (id !== null && id !== undefined && id !== '') {
        const list = this.resolvedAccounts || [];
        const found = list.find(a => a && String(a.id) === String(id));
        if (found) return String(found.id);
      }
      return '';
    },
    selectPaymentMethod(method) {
      this.paymentForm.paymentMethodId = method.id;
    },
    calculateChange() {
      // With multiple payment lines, change is derived from totalPaid vs amountDue
      return;
    },
    formatCurrency(value) {
      const num = Number(value);
      const isValid = !isNaN(num);
      const amount = isValid ? Math.abs(num) : 0;
      const dec = this.priceDecimals;
      const formattedNumber = new Intl.NumberFormat('en-US', {
        minimumFractionDigits: dec,
        maximumFractionDigits: dec
      }).format(amount);
      const sign = isValid && num < 0 ? '-' : '';
      return `${sign}${this.currency} ${formattedNumber}`;
    },
    addPaymentLine() {
      if (Number(this.paymentForm.amountDue) === 0) {
        this.makeToast('warning', this.$t ? this.$t('Zero_total_no_payment_required') : 'Zero total sale: no payment needed', this.$t ? this.$t('Warning') : 'Warning');
        return;
      }
      const defaultMethodId = this.getDefaultPaymentMethodId();
      const defaultAccountId = this.getDefaultAccountId();
      this.paymentLines.push(this.makePaymentLine(0, defaultMethodId, defaultAccountId || this.paymentForm.accountId || ''));
    },
    makePaymentLine(amount, paymentMethodId, accountId) {
      return {
        amount,
        paymentMethodId,
        accountId,
        cardReference: '',
        authorizationCode: '',
        cardLast4: ''
      };
    },
    cardPayloadForLine(line) {
      if (!this.isLineCreditCard(line)) {
        return {};
      }
      return {
        card_processor: this.usesStripeCardProcessor ? 'stripe' : 'external_terminal',
        card_reference: line.cardReference || null,
        authorization_code: line.authorizationCode || null,
        card_last4: line.cardLast4 || null
      };
    },
    removePaymentLine(index) {
      // Never allow removing the last remaining line – there must always be at least one payment method
      if (!this.paymentLines || this.paymentLines.length <= 1) {
        return;
      }
      // Remove the line
      this.paymentLines.splice(index, 1);
      // Destroy any Stripe element tied to this line and clear all to avoid index drift
      try { this.destroyCardElementForLine(index); } catch (e) { /* ignore */ }
      try { this.destroyAllCardElements && this.destroyAllCardElements(); } catch (e) { /* ignore */ }
    },
    setQuickAmount(amount) {
      if (Number(this.paymentForm.amountDue) === 0) {
        this.makeToast('warning', this.$t ? this.$t('Zero_total_no_payment_required') : 'Zero total sale: no payment needed', this.$t ? this.$t('Warning') : 'Warning');
        return;
      }
      if (!this.paymentLines.length) this.addPaymentLine();
      this.paymentLines[0].amount = Number(amount) || 0;
    },
    onAmountInput(idx) {
      const line = this.paymentLines && this.paymentLines[idx];
      if (!line) return;
      let val = line.amount;
      // Coerce to finite non-negative number with the configured precision (2 or 3)
      val = Number(val);
      if (!Number.isFinite(val) || val < 0) val = 0;
      // Avoid editing when zero due
      if (Number(this.paymentForm.amountDue) === 0) val = 0;
      const dec = this.priceDecimals;
      this.$set ? this.$set(this.paymentLines[idx], 'amount', Number(val.toFixed ? val.toFixed(dec) : val)) : (this.paymentLines[idx].amount = val);
    },
    async loadStripePayment(lineIndex) {
      try {
        if (!this.stripeKey) return;
        if (!this.stripe) {
          this.stripe = await loadStripe(`${this.stripeKey}`);
        }
        if (!this.stripe) return;
        const idx = typeof lineIndex === 'number' ? lineIndex : (this.activeCardLineIndex || 0);
        const containerId = `card-element-${idx}`;

        // Mount helper with small retry window so that when the modal is
        // re‑opened (or during transitions) the Stripe Element still mounts
        // once the DOM node is actually present.
        const tryMount = (attempt = 0) => {
          const container = document.getElementById(containerId);
          if (!container) {
            // Retry a few times in case the element isn't in the DOM yet
            if (attempt < 5) {
              setTimeout(() => tryMount(attempt + 1), 60);
            }
            return;
          }

          if (!this.cardElementsByLine[idx]) {
            const elements = this.stripe.elements();
            const element = elements.create('card', { hidePostalCode: true });
            element.mount(container);
            try {
              element.on && element.on('change', (event) => {
                this.isCardInputComplete = !!(event && event.complete);
              });
            } catch (e) { /* ignore */ }
            this.cardElementsByLine[idx] = element;
          }
        };

        tryMount();
      } catch (e) {
        // ignore mount errors
      }
    },
    destroyCardElementForLine(lineIndex) {
      try {
        const idx = typeof lineIndex === 'number' ? lineIndex : (this.activeCardLineIndex || 0);
        const el = this.cardElementsByLine && this.cardElementsByLine[idx];
        if (el && el.unmount) {
          el.unmount();
        }
        if (this.cardElementsByLine) {
          delete this.cardElementsByLine[idx];
        }
      } catch (e) { /* ignore */ }
    },
    destroyAllCardElements() {
      try {
        const keys = Object.keys(this.cardElementsByLine || {});
        keys.forEach(k => {
          const el = this.cardElementsByLine[k];
          if (el && el.unmount) {
            try { el.unmount(); } catch (e) { /* ignore */ }
          }
        });
        this.cardElementsByLine = {};
      } catch (e) { /* ignore */ }
    },
    // Keep template hook but delegate to CreatePOS to mirror old logic
    async submitPayment() {
      await this.CreatePOS();
    },

    resolvedCashDrawerId() {
      return this.cashDrawerId || (this.sale && this.sale.cash_drawer_id) || null;
    },

    ensureCashDrawerAssigned() {
      if (this.resolvedCashDrawerId()) {
        return true;
      }

      const msg = 'No tienes una caja física asignada. Contacta a tu supervisor.';
      if (typeof NProgress !== 'undefined') NProgress.done();
      this.paymentProcessing = false;
      this.isSubmitting = false;
      this.makeToast && this.makeToast('danger', msg, this.$t ? this.$t('Failed') : 'Failed');
      return false;
    },

    //-------------------------------- Invoice POS (mirror old) ------------------------------\\
    async Invoice_POS(id) {
      try {
        if (typeof NProgress !== 'undefined') {
          NProgress.start();
          NProgress.set(0.1);
        }
        const response = await axios.get(`sales_print_invoice/${id}`);
        const posSettings = (response.data && response.data.pos_settings) ? response.data.pos_settings : {};
        const invoicePayload = {
          invoice: response.data,
          payments: response.data && response.data.payments ? response.data.payments : [],
          pos_settings: posSettings
        };
        // Emit upwards so parent can decide how to present invoice
        this.$emit('invoice-ready', invoicePayload);
        if (typeof NProgress !== 'undefined') {
          NProgress.done();
        }
        // Directly trigger print dialog without showing any modal.
        // If Direct Network Printing is enabled, send straight to the
        // configured network printer and skip the browser print popup.
        setTimeout(() => this.print_pos(id, posSettings), 300);
        return true;
      } catch (e) {
        if (typeof NProgress !== 'undefined') {
          setTimeout(() => NProgress.done(), 300);
        }
        const message = this.getApiErrorMessage(e, 'La venta fue creada, pero no se pudo generar la factura para impresión.');
        this.makeToast && this.makeToast('danger', message, this.$t ? this.$t('Failed') : 'Failed');
        throw e;
      }
    },

    // Try Direct Network Printing (RAW/port 9100) when enabled in settings.
    // Returns Promise resolving to:
    //   { ok: true }                       — receipt dispatched to printer
    //   { ok: false, configured: false }   — DNP off / no IP / no saleId
    //   { ok: false, configured: true, message } — DNP attempt failed
    tryDirectNetworkPrint(saleId, posSettings) {
      try {
        const ps = posSettings || {};
        const enabled = ps.direct_network_printing === true || ps.direct_network_printing === 1 || ps.direct_network_printing === '1';
        const ip = (ps.network_printer_ip || '').toString().trim();
        if (!enabled || !ip || !saleId) return Promise.resolve({ ok: false, configured: false });
        return axios.post('direct_network_print/' + saleId)
          .then((response) => {
            if (response && response.data && response.data.success) return { ok: true };
            const message = (response && response.data && response.data.message) || 'Network printer error';
            return { ok: false, configured: true, message };
          })
          .catch((err) => {
            const message = this.getApiErrorMessage(err, 'Network printer unreachable');
            return { ok: false, configured: true, message };
          });
      } catch (e) {
        return Promise.resolve({ ok: false, configured: false });
      }
    },

    //------------------------------ Print -------------------------\\
    // When DNP is enabled, always use the network printer and surface a
    // toast on failure. Never fall back to the browser print popup,
    // since the user explicitly opted into direct printing.
    print_pos(saleId, posSettings) {
      const ps = posSettings || {};
      const dnpEnabled = ps.direct_network_printing === true || ps.direct_network_printing === 1 || ps.direct_network_printing === '1';
      if (dnpEnabled && saleId) {
        this.tryDirectNetworkPrint(saleId, ps).then((result) => {
          if (result && result.ok) {
            this.makeToast && this.makeToast(
              'success',
              'Factura enviada correctamente a la impresora.',
              this.$t ? this.$t('Success') : 'Success'
            );
            return;
          }
          if (result && result.configured) {
            this.makeToast && this.makeToast(
              'danger',
              (this.$t ? this.$t('Network_print_failed') || 'Network print failed' : 'Network print failed') + ': ' + (result.message || ''),
              this.$t ? this.$t('Failed') : 'Failed'
            );
            return;
          }
          this.printBrowserFallback();
        });
        return;
      }
      this.printBrowserFallback();
    },

    printBrowserFallback() {
      var el = document.getElementById('invoice-POS');
      if (!el) {
        this.makeToast && this.makeToast(
          'warning',
          'La factura fue generada, pero no se encontró el contenido para imprimir. Puedes reimprimirla desde ventas.',
          this.$t ? this.$t('Warning') : 'Warning'
        );
        return;
      }
      var divContents = el.innerHTML;
      var a = window.open('', '', 'height=600, width=480');
      if (!a) {
        this.makeToast && this.makeToast(
          'warning',
          'La factura fue generada, pero el navegador bloqueó la ventana de impresión. Habilita las ventanas emergentes y usa Reimprimir.',
          this.$t ? this.$t('Warning') : 'Warning'
        );
        return;
      }
      a.document.write('<html><head><link rel="stylesheet" href="/css/pos_print.css"></head><body>');
      a.document.write(divContents);
      a.document.write('</body></html>');
      a.document.close();
      this.makeToast && this.makeToast(
        'success',
        'Factura generada y enviada al flujo de impresión.',
        this.$t ? this.$t('Success') : 'Success'
      );
      const reloadParent = () => {
        try { window.removeEventListener('focus', reloadParent); } catch(e) {}
        try { a.close(); } catch(e) {}
      };
      try { window.addEventListener('focus', reloadParent); } catch(e) {}
      try { a.onafterprint = reloadParent; } catch(e) {}
      setTimeout(() => {
        try { a.focus(); } catch(e) {}
        try { a.print(); } catch(e) {
          this.makeToast && this.makeToast(
            'danger',
            'La factura fue generada, pero no fue posible abrir el diálogo de impresión. Puedes reimprimirla desde ventas.',
            this.$t ? this.$t('Failed') : 'Failed'
          );
          reloadParent();
        }
      }, 300);
    },

    //----------------------------------Process Payment (mirror old) ------------------------------\\
    ensureSaleUuid() {
      if (this.currentSaleUuid) {
        return this.currentSaleUuid;
      }

      let uuid = null;

      try {
        if (typeof window !== 'undefined' && window.crypto && typeof window.crypto.randomUUID === 'function') {
          uuid = window.crypto.randomUUID();
        }
      } catch (e) {
        uuid = null;
      }

      if (!uuid) {
        uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
          const r = Math.random() * 16 | 0;
          const v = c === 'x' ? r : (r & 0x3 | 0x8);
          return v.toString(16);
        });
      }

      this.currentSaleUuid = uuid;
      return uuid;
    },

    async processPayment() {
      const saleUuid = this.ensureSaleUuid();
      this.paymentProcessing = true;
      this.isSubmitting = true;
      try {
        // Validate Stripe configuration and UI readiness
        if (!this.stripeKey) {
          this.paymentProcessing = false;
          this.isSubmitting = false;
          if (typeof NProgress !== 'undefined') NProgress.done();
          this.makeToast('danger', this.$t ? this.$t('credit_card_account_not_available') : 'Credit card not available', this.$t ? this.$t('Failed') : 'Failed');
          return;
        }
        if (!this.stripe) {
          this.paymentProcessing = false;
          this.isSubmitting = false;
          if (typeof NProgress !== 'undefined') NProgress.done();
          this.makeToast('danger', this.$t ? this.$t('Failed_to_load') : 'Failed to load.', this.$t ? this.$t('Failed') : 'Failed');
          return;
        }
        // Determine which lines require a new card (not saved)
        const newCardIndices = (this.paymentLines || []).map((line, i) => {
          const needsNew = this.isLineCreditCard(line);
          return needsNew ? i : null;
        }).filter(i => i !== null);

        // Validate that each such line has a mounted element
        const missing = newCardIndices.find(i => !this.cardElementsByLine || !this.cardElementsByLine[i]);
        if (missing !== undefined) {
          this.paymentProcessing = false;
          this.isSubmitting = false;
          if (typeof NProgress !== 'undefined') NProgress.done();
          this.makeToast('danger', this.$t ? this.$t('Please_enter_credit_card_details') : 'Please enter credit card details', this.$t ? this.$t('Warning') : 'Warning');
          return;
        }

        // Generate tokens for all new-card lines
        // IMPORTANT: Some Stripe integrations don't set event.complete reliably across all browsers/locales
        // We proceed to tokenization and rely on Stripe to validate completeness
        const tokenResults = await Promise.all(newCardIndices.map(async (i) => {
          try {
            return await this.stripe.createToken(this.cardElementsByLine[i]);
          } catch (e) {
            return { error: e };
          }
        }));
        const tokensByLine = {};
        for (let idx = 0; idx < tokenResults.length; idx++) {
          const lineKey = newCardIndices[idx];
          const res = tokenResults[idx] || {};
          if (res.error) {
            this.paymentProcessing = false;
            this.isSubmitting = false;
            if (typeof NProgress !== 'undefined') NProgress.done();
            this.makeToast('danger', this.$t ? this.$t('InvalidData') : 'Invalid data', this.$t ? this.$t('Failed') : 'Failed');
            return;
          }
          if (res.token && res.token.id) {
            tokensByLine[lineKey] = res.token.id;
          }
        }
        // Backward compatibility single token: prefer active line's token if any, else first
        let token = null;
        if (this.activeCardLineIndex != null && tokensByLine[this.activeCardLineIndex]) {
          token = { id: tokensByLine[this.activeCardLineIndex] };
        } else {
          const firstIdx = newCardIndices && newCardIndices.length ? newCardIndices[0] : null;
          if (firstIdx != null && tokensByLine[firstIdx]) {
            token = { id: tokensByLine[firstIdx] };
          }
        }

        const payload = {
          client_id: this.clientId,
          warehouse_id: this.warehouseId,
          cash_drawer_id: this.resolvedCashDrawerId(),
          tax_rate: this.sale && this.sale.tax_rate ? this.sale.tax_rate : 0,
          TaxNet: this.sale && this.sale.TaxNet ? this.sale.TaxNet : 0,
          discount: this.sale && this.sale.discount ? this.sale.discount : 0,
          // Ensure order-level discount method is sent: '1' = percent, '2' = fixed (default)
          discount_Method: this.sale && this.sale.discount_Method
            ? String(this.sale.discount_Method)
            : '2',
          shipping: this.sale && this.sale.shipping ? this.sale.shipping : 0,
          details: this.details,
          GrandTotal: this.grandTotal || this.paymentForm.amountDue || this.totalPaid,
          store_credit_vouchers: this.storeCreditVouchers || [],
          // Multi-payment array with optional per-line account and saved card
          payments: (this.paymentLines || []).map((l) => ({
            amount: Number(l.amount) || 0,
            payment_method_id: l.paymentMethodId,
            account_id: l.accountId || this.paymentForm.accountId || null,
            ...this.cardPayloadForLine(l)
          })),
          send_email: this.sendEmail,
          send_sms: this.sendSMS,
          account_id: this.paymentForm.accountId,
          payment_note: this.paymentNote || '',
          token: token && token.id ? token.id : null,
          is_new_credit_card: this.hasAnyNewCardToTokenize(),
          card_tokens_by_line: tokensByLine,
          discount_from_points: this.discountFromPoints || 0,
          used_points: this.usedPoints || 0,
          draft_sale_id: this.draftSaleId || null,
          quotation_id: this.sourceQuotationId || undefined,
          sale_uuid: saleUuid || null,
          // Kitchen routing: only 'send' creates a kitchen ticket on the server (and only
          // when the feature is enabled). 'none' / 'later' leave the sales flow untouched.
          kitchen_action: this.kitchenEnabled ? this.kitchenAction : 'none',
          ...(this.promotionContext || {})
        };

        const response = await axios.post(this.createPosUrl, payload);
        this.paymentProcessing = false;
        this.isSubmitting = false;
        if (response && response.data && response.data.success === true) {
          if (typeof NProgress !== 'undefined') NProgress.done();
          // Prefer parent's Invoice_POS if available (ensures existing invoice modal/printing works)
          if (this.$parent && typeof this.$parent.Invoice_POS === 'function') {
            await this.$parent.Invoice_POS(response.data.id);
          } else {
            await this.Invoice_POS(response.data.id);
          }
          this.notifyInvoicePrintStarted();
          this.$emit('payment-success', {
            id: response.data.id,
            payload,
            updated_stock: response.data.updated_stock,
            server_time: response.data.server_time
          });
          this.$refs.paymentModal && this.$refs.paymentModal.hide && this.$refs.paymentModal.hide();
        } else {
          if (typeof NProgress !== 'undefined') NProgress.done();
          const message = this.getApiErrorMessage(response && response.data, this.$t ? this.$t('InvalidData') : 'Invalid data');
          this.makeToast('danger', message, this.$t ? this.$t('Failed') : 'Failed');
        }
      } catch (e) {
        this.paymentProcessing = false;
        this.isSubmitting = false;
        if (typeof NProgress !== 'undefined') NProgress.done();
        const message = this.getApiErrorMessage(e, this.$t ? this.$t('InvalidData') : 'Invalid data');
        this.makeToast('danger', message, this.$t ? this.$t('Failed') : 'Failed');
      }
    },

    //----------------------------------Create POS (mirror old, with offline queue) ------------------------------\\
    async CreatePOS() {
      if (typeof NProgress !== 'undefined') {
        NProgress.start();
        NProgress.set(0.1);
      }
      if (!this.ensureCashDrawerAssigned()) {
        return;
      }

      const saleUuid = this.ensureSaleUuid();

      // Basic validations: allow 0 total only if exactly one payment line
      const zeroTotal = this.totalPaid <= 0;
      if (!this.paymentLines.length || (zeroTotal && this.paymentLines.length > 1)) {
        if (typeof NProgress !== 'undefined') NProgress.done();
        this.makeToast('danger', this.$t ? this.$t('InvalidData') : 'Invalid data', this.$t ? this.$t('Failed') : 'Failed');
        return;
      }
      // Account selection is optional per line; proceed even if unset

      // Validate multi-payment overpay like old POS
      const total = parseFloat(this.totalPaid);
      const due = parseFloat((this.paymentForm.amountDue || this.grandTotal || 0).toFixed(this.priceDecimals));
      const multi = this.paymentLines.length > 1;
      if (multi && total > due) {
        this.makeToast(
          'warning',
          this.$t ? this.$t('TotalPaidExceedsGrandTotalForMultiPayment') : 'Total paid exceeds grand total for multiple payments',
          this.$t ? this.$t('Warning') : 'Warning'
        );
        if (typeof NProgress !== 'undefined') NProgress.done();
        return;
      }

      // Credit Limit Validation (0 means no limit)
      // Only applies when this sale is adding new credit (paid amount < sale total)
      if (this.clientId && Number(this.clientCreditLimit || 0) > 0 && total < due) {
        const currentDue = parseFloat(this.clientNetBalance || 0);
        const newSaleDue = due - total; // Remaining due from this sale
        const newTotalDue = currentDue + newSaleDue;

        if (newTotalDue > Number(this.clientCreditLimit)) {
          if (typeof NProgress !== 'undefined') NProgress.done();
          const exceededAmount = newTotalDue - Number(this.clientCreditLimit);
          const t = this.$t ? this.$t.bind(this) : (k => k);
          this.makeToast(
            'danger',
            t('Credit_Limit_Exceeded') + ': ' +
              this.formatCurrency(exceededAmount) + ' ' +
              t('exceeds_credit_limit_of') + ' ' +
              this.formatCurrency(this.clientCreditLimit),
            this.$t ? this.$t('Warning') : 'Warning'
          );
          return;
        }
      }

      const buildPayload = () => {
        // Ensure each detail line carries a sale_unit_id so offline sync can rely on it
        const normalizedDetails = (this.details || []).map(d => ({
          ...d,
          sale_unit_id:
            d && d.sale_unit_id !== undefined && d.sale_unit_id !== null && d.sale_unit_id !== ''
              ? d.sale_unit_id
              : d && d.sale_unit_id
        }));

        return {
          client_id: this.clientId,
          warehouse_id: this.warehouseId,
          cash_drawer_id: this.resolvedCashDrawerId(),
          tax_rate: this.sale && this.sale.tax_rate ? this.sale.tax_rate : 0,
          TaxNet: this.sale && this.sale.TaxNet ? this.sale.TaxNet : 0,
          discount: this.sale && this.sale.discount ? this.sale.discount : 0,
          // Ensure order-level discount method is sent: '1' = percent, '2' = fixed (default)
          discount_Method: this.sale && this.sale.discount_Method
            ? String(this.sale.discount_Method)
            : '2',
          shipping: this.sale && this.sale.shipping ? this.sale.shipping : 0,
          notes: this.saleNote || (this.sale && this.sale.notes) || '',
          details: normalizedDetails,
          GrandTotal: this.grandTotal || this.paymentForm.amountDue || total,
          store_credit_vouchers: this.storeCreditVouchers || [],
          // Multi-payment array including per-line account (no global)
          payments: (this.paymentLines || []).map((l) => ({
            amount: Number(l.amount) || 0,
            payment_method_id: l.paymentMethodId,
            account_id: l.accountId || null,
            ...this.cardPayloadForLine(l)
          })),
          send_email: this.sendEmail,
          send_sms: this.sendSMS,
          // No global account_id
          payment_note: this.paymentNote || '',
          discount_from_points: this.discountFromPoints || 0,
          used_points: this.usedPoints || 0,
          draft_sale_id: this.draftSaleId || null,
          quotation_id: this.sourceQuotationId || undefined,
          sale_uuid: saleUuid || null,
          // Kitchen routing: only 'send' creates a kitchen ticket on the server (and only
          // when the feature is enabled). 'none' / 'later' leave the sales flow untouched.
          kitchen_action: this.kitchenEnabled ? this.kitchenAction : 'none',
          ...(this.promotionContext || {})
        };
      };

      // Prefer explicit POS online state from parent; fall back to navigator.
      const isOnline = this.isOnline !== false
        ? true
        : ((typeof window === 'undefined' || !window.navigator)
            ? true
            : window.navigator.onLine !== false);
      const usingStripeCard = this.anyStripeCreditCardUsed;

      // If we are offline and no credit card is involved, queue sale locally instead of calling API
      if (!isOnline && !usingStripeCard) {
        const payload = buildPayload();
        if (typeof NProgress !== 'undefined') NProgress.done();
        this.paymentProcessing = false;
        this.isSubmitting = false;
        this.queueOfflineSale(payload);
        return;
      }

      // If offline but card is involved, block and warn
      if (!isOnline && usingStripeCard) {
        if (typeof NProgress !== 'undefined') NProgress.done();
        this.paymentProcessing = false;
        this.isSubmitting = false;
        const msg = this.$t
          ? (this.$t('pos.CreditCard_Requires_Online') || 'Credit card payments require an internet connection.')
          : 'Credit card payments require an internet connection.';
        this.makeToast && this.makeToast('warning', msg, this.$t ? this.$t('Warning') : 'Warning');
        return;
      }

      // Determine if any line requires a new card entry (no saved card selected)
      const anyNewCard = this.hasAnyNewCardToTokenize();

      if (anyNewCard) {
        if (!this.stripeKey) {
          this.makeToast('danger', this.$t ? this.$t('credit_card_account_not_available') : 'Credit card not available', this.$t ? this.$t('Failed') : 'Failed');
          if (typeof NProgress !== 'undefined') NProgress.done();
          return;
        }
        if (!this.stripe) {
          this.makeToast('danger', this.$t ? this.$t('Failed_to_load') : 'Failed to load.', this.$t ? this.$t('Failed') : 'Failed');
          if (typeof NProgress !== 'undefined') NProgress.done();
          return;
        }
        // Ensure at least one card element exists for any card lines requiring new card entry
        const needsNewCard = Object.keys(this.cardElementsByLine || {}).some(i => {
          const li = Number(i);
          const line = (this.paymentLines || [])[li];
          return this.isLineCreditCard(line);
        });
        if (!needsNewCard) {
          this.makeToast('danger', this.$t ? this.$t('Please_enter_credit_card_details') : 'Please enter credit card details', this.$t ? this.$t('Warning') : 'Warning');
          if (typeof NProgress !== 'undefined') NProgress.done();
          return;
        }
        await this.processPayment();
        return;
      }

      // Non-card / saved card path
      this.paymentProcessing = true;
      this.isSubmitting = true;
      try {
        const payload = buildPayload();
        const response = await axios.post(this.createPosUrl, payload);
        if (response && response.data && response.data.success === true) {
          if (typeof NProgress !== 'undefined') NProgress.done();
          this.paymentProcessing = false;
          this.isSubmitting = false;
          // Prefer parent's Invoice_POS if available (ensures existing invoice modal/printing works)
          if (this.$parent && typeof this.$parent.Invoice_POS === 'function') {
            await this.$parent.Invoice_POS(response.data.id);
          } else {
            await this.Invoice_POS(response.data.id);
          }
          this.notifyInvoicePrintStarted();
          this.$emit('payment-success', {
            id: response.data.id,
            payload,
            updated_stock: response.data.updated_stock,
            server_time: response.data.server_time
          });
          this.$refs.paymentModal && this.$refs.paymentModal.hide && this.$refs.paymentModal.hide();
        } else {
          if (typeof NProgress !== 'undefined') NProgress.done();
          this.paymentProcessing = false;
          this.isSubmitting = false;
          const message = this.getApiErrorMessage(response && response.data, this.$t ? this.$t('InvalidData') : 'Invalid data');
          this.makeToast('danger', message, this.$t ? this.$t('Failed') : 'Failed');
        }
      } catch (error) {
        if (typeof NProgress !== 'undefined') NProgress.done();
        this.paymentProcessing = false;
        this.isSubmitting = false;
        const isNetworkError = this.isTrueNetworkError(error);
        // In this non-card branch, true offline handling is already covered by the
        // explicit (!isOnline && !usingCard) path above. Here we are effectively
        // in "online" mode, so do NOT silently queue a new offline sale; instead
        // surface an error toast so the cashier can react.
        if (isNetworkError) {
          const msg = this.$t
            ? (this.$t('pos.NetworkError') || 'Network error, please try again.')
            : 'Network error, please try again.';
          this.makeToast && this.makeToast('danger', msg, this.$t ? this.$t('Failed') : 'Failed');
        } else {
          const message = this.getApiErrorMessage(error, this.$t ? this.$t('InvalidData') : 'Invalid data');
          this.makeToast('danger', message, this.$t ? this.$t('Failed') : 'Failed');
        }
      }
    },
    queueOfflineSale(payload) {
      let record = null;
      try {
        if (Util && Util.offlinePos && Util.offlinePos.addOfflineSale) {
          record = Util.offlinePos.addOfflineSale(payload);
        }
      } catch (e) {}
      // Record shadow stock deductions in IndexedDB for this offline sale (best-effort)
      try {
        const offlineId = record && record.id ? record.id : (payload && payload.offline_id) || null;
        if (offlineId && Util && Util.shadowStock && Util.shadowStock.recordDeductions) {
          Util.shadowStock.recordDeductions(payload.warehouse_id, offlineId, payload.details || []);
        }
      } catch (e) {}
      try {
        const msg = this.$t
          ? (this.$t('pos.Offline_Sale_Saved') || 'Sale saved offline. It will sync when you are back online.')
          : 'Sale saved offline. It will sync when you are back online.';
        this.makeToast && this.makeToast('success', msg, this.$t ? this.$t('Success') : 'Success');
      } catch (e) {}
      this.$emit('payment-success', {
        id: null,
        payload,
        offline: true,
        offlineId: record && record.id ? record.id : null
      });
      this.$refs.paymentModal && this.$refs.paymentModal.hide && this.$refs.paymentModal.hide();
    },
    resetForm() {
      this.paymentForm = {
        amountDue: this.grandTotal || 0,
        accountId: '',
        date: this.getCurrentDateInTimezone(),
        reference: '',
        notes: ''
      };
      // Clear lines and any per-line state
      this.paymentLines = [];
      this.destroyAllCardElements && this.destroyAllCardElements();
      this.activeCardLineIndex = 0;
      this.sendEmail = false;
      this.sendSMS = false;
      this.paymentNote = '';
      this.saleNote = '';
      this.kitchenAction = 'none';
      this.isCardInputComplete = false;
      this.isSubmitting = false;
      this.currentSaleUuid = null;
    },
    openModal(data = {}) {
      if (data.id) {
        this.isEditMode = true;
        // Load edit data
        Object.assign(this.paymentForm, data);
      } else {
        this.isEditMode = false;
        // Set payment date to current date in configured timezone
        this.paymentForm.date = this.getCurrentDateInTimezone();
        if (data.reference) {
          this.paymentForm.reference = data.reference;
        }
        if (data.notes) {
          this.paymentForm.notes = data.notes;
        }
      }
      // Derive amountDue: prefer explicit data, then prop grandTotal, else 0
      if (Object.prototype.hasOwnProperty.call(data, 'amountDue') && data.amountDue !== undefined && data.amountDue !== null) {
        this.paymentForm.amountDue = Number(data.amountDue) || 0;
      } else {
        this.paymentForm.amountDue = this.grandTotal || 0;
      }
      // Initialize one payment line prefilled with amount due; use system defaults when available
      this.paymentLines = [];
      this.destroyAllCardElements && this.destroyAllCardElements();
      this.activeCardLineIndex = 0;
      const defaultMethodId = this.getDefaultPaymentMethodId();
      const defaultAccountId = this.getDefaultAccountId();
      this.paymentForm.accountId = defaultAccountId;
      this.paymentLines.push(this.makePaymentLine(Number(this.paymentForm.amountDue || 0), defaultMethodId, defaultAccountId));
      this.paymentNote = '';
      this.saleNote = '';
      this.kitchenAction = 'none';
      this.sendEmail = false;
      this.sendSMS = false;
      this.$refs.paymentModal.show();
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss">
/* ========================================
   PREMIUM PAYMENT MODAL DESIGN
   ======================================== */

.kitchen-routing {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 6px;
}
.kitchen-routing-option {
  flex: 1 1 30%;
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 10px;
  border: 1px solid var(--pxn-border);
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.85rem;
  margin-bottom: 0;
  transition: all 0.15s ease;

  &.active {
    border-color: var(--pxn-info);
    background: var(--pxn-info-soft);
    font-weight: 600;
  }
}

.modern-payment-modal {
  .modal-dialog {
    max-width: 1100px !important;
    width: 90vw !important;
  }

  .modal-content {
    border: none !important;
    border-radius: 18px !important;
    box-shadow: 0 15px 50px rgba(0, 0, 0, 0.15) !important;
    overflow: hidden !important;
  }

  .modal-body-custom {
    padding: 0 !important;
  }

  &.premium-payment-modal-large {
    .modal-dialog {
      max-width: 1100px !important;
    }
  }
}

/* Global modal override for this specific modal */
#modern_payment_modal {
  .modal-dialog {
    max-width: 1100px !important;
    width: 90vw !important;
  }

  .modal-content {
    border: none !important;
    border-radius: 18px !important;
    box-shadow: 0 15px 50px rgba(0, 0, 0, 0.15) !important;
    overflow: hidden !important;
  }

  .modal-body {
    padding: 0 !important;
  }
}

.external-card-section {
  margin-top: 10px;
  padding: 12px;
  border: 1px solid var(--pxn-border);
  border-radius: 8px;
  background: var(--pxn-surface-2);
}

.external-card-message {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--pxn-ink-2);
  font-weight: 600;
  margin-bottom: 10px;
}

.external-card-message svg {
  width: 18px;
  height: 18px;
}

.external-card-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

@media (max-width: 640px) {
  .external-card-grid {
    grid-template-columns: 1fr;
  }
}

/* ========================================
   PAYMENT CONTAINER
   ======================================== */

.payment-container {
  background: var(--pxn-surface);
  min-height: 450px;
  max-height: 85vh;
  display: flex;
  flex-direction: column;
}

/* ========================================
   HEADER SECTION
   ======================================== */

.payment-header {
  background: var(--pxn-surface);
  border-bottom: 1px solid var(--pxn-border);
  padding: var(--pxn-space-5) var(--pxn-space-7);
  display: flex;
  justify-content: space-between;
  align-items: center;

  .header-left {
    display: flex;
    align-items: center;
    gap: var(--pxn-space-4);
  }

  .icon-wrapper {
    width: 32px;
    height: 32px;
    background: var(--pxn-surface-2);
    border-radius: var(--pxn-radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--pxn-ink-2);
    svg { width: 18px; height: 18px; }
  }

  .header-text {
    .modal-title {
      margin: 0;
      font-family: var(--pxn-font-sans);
      font-size: var(--pxn-fs-h2);
      font-weight: var(--pxn-fw-semibold);
      color: var(--pxn-ink);
      letter-spacing: -0.01em;
    }
  }

  .close-button {
    width: var(--pxn-control-h-sm);
    height: var(--pxn-control-h-sm);
    background: var(--pxn-surface);
    border: 1px solid var(--pxn-border);
    border-radius: var(--pxn-radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background-color var(--pxn-dur-1) var(--pxn-ease), border-color var(--pxn-dur-1) var(--pxn-ease);
    color: var(--pxn-ink-3);

    &:hover {
      background: var(--pxn-surface-hover);
      border-color: var(--pxn-border-control);
      color: var(--pxn-ink);
    }
  }
}

/* ========================================
   CONTENT AREA
   ======================================== */

.payment-content {
  display: grid;
  grid-template-columns: 300px 1fr;
  min-height: 400px;
  flex: 1;
  overflow: hidden;

  @media (max-width: 992px) {
    display: block;
    grid-template-columns: none;
    overflow-y: auto;
  }
}

/* ========================================
   TRANSACTION INFO SIDEBAR
   ======================================== */

.transaction-info {
  background: var(--pxn-surface-2);
  padding: 14px 12px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  border-right: 1px solid var(--pxn-border);
  overflow-y: auto;
  overflow-x: hidden;

  /* Custom scrollbar */
  &::-webkit-scrollbar {
    width: 6px;
  }

  &::-webkit-scrollbar-track {
    background: transparent;
  }

  &::-webkit-scrollbar-thumb {
    background: var(--pxn-border-control);
    border-radius: 3px;

    &:hover {
      background: var(--pxn-ink-3);
    }
  }
}

.amount-card {
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-lg);
  padding: var(--pxn-space-5);

  .amount-card-header {
    display: flex;
    align-items: center;
    gap: var(--pxn-space-3);
    margin-bottom: var(--pxn-space-3);
    color: var(--pxn-ink-3);
    font-weight: var(--pxn-fw-semibold);
    font-size: var(--pxn-fs-xs);
    text-transform: uppercase;
    letter-spacing: 0.04em;

    svg { flex-shrink: 0; width: 14px; height: 14px; }
  }

  .amount-display {
    text-align: left;
    padding: 0;

    .currency-label {
      display: block;
      font-size: var(--pxn-fs-xs);
      color: var(--pxn-ink-3);
      font-weight: var(--pxn-fw-medium);
      text-transform: uppercase;
      letter-spacing: 0.04em;
      margin-bottom: 2px;
    }

    .amount-large {
      display: block;
      font-family: var(--pxn-font-mono);
      font-size: 26px;
      font-weight: var(--pxn-fw-bold);
      color: var(--pxn-ink);
      letter-spacing: -0.01em;
    }
  }

  .transaction-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid rgba(0, 0, 0, 0.06);

    .meta-item {
      display: flex;
      flex-direction: column;
      gap: 4px;

      .meta-label {
        font-size: 9px;
        color: var(--pxn-ink-3);
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }

      .meta-value {
        font-size: 12px;
        font-weight: 700;
        color: var(--pxn-ink);
      }
    }

    .meta-divider {
      width: 1px;
      height: 24px;
      background: rgba(0, 0, 0, 0.06);
    }

    .status-badge {
      display: flex;
      align-items: center;
      gap: 4px;
      background: var(--pxn-primary);
      color: white;
      padding: 4px 10px;
      border-radius: 16px;
      font-size: 10px;
      font-weight: 600;

      .status-dot {
        width: 5px;
        height: 5px;
        background: white;
        border-radius: 50%;
        animation: pulse 2s ease infinite;
      }
    }
  }
}

.payment-status-card {
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-lg);
  padding: var(--pxn-space-5);

  .card-title {
    font-size: var(--pxn-fs-xs);
    font-weight: var(--pxn-fw-semibold);
    color: var(--pxn-ink-3);
    margin: 0 0 var(--pxn-space-3) 0;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  .status-grid {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .status-box {
    display: flex;
    align-items: center;
    gap: var(--pxn-space-4);
    padding: var(--pxn-space-4) 0;

    & + .status-box { border-top: 1px solid var(--pxn-border); }

    .status-icon {
      width: 26px;
      height: 26px;
      border-radius: var(--pxn-radius-sm);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      background: var(--pxn-surface-2);
      color: var(--pxn-ink-3);

      &.paying   { background: var(--pxn-surface-2); color: var(--pxn-ink-3); }
      &.balance  { background: var(--pxn-warning-soft); color: var(--pxn-warning-ink); }
      &.change   { background: var(--pxn-info-soft); color: var(--pxn-info-ink); }

      svg { width: 14px; height: 14px; }
    }

    .status-details {
      flex: 1;
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: var(--pxn-space-3);

      .status-name {
        font-size: var(--pxn-fs-xs);
        color: var(--pxn-ink-3);
        font-weight: var(--pxn-fw-medium);
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }

      .status-amount {
        font-family: var(--pxn-font-mono);
        font-size: var(--pxn-fs-h3);
        font-weight: var(--pxn-fw-semibold);
        color: var(--pxn-ink);

        &.balance-text { color: var(--pxn-warning-ink); }
        &.change-text  { color: var(--pxn-info-ink); }
      }
    }
  }
}

/* ========================================
   PAYMENT FORM AREA
   ======================================== */

.payment-form-area {
  padding: 16px 20px;
  overflow-y: auto;
  overflow-x: hidden;
  max-height: calc(85vh - 120px);
  position: relative;

  /* Custom scrollbar */
  &::-webkit-scrollbar {
    width: 6px;
  }

  &::-webkit-scrollbar-track {
    background: var(--pxn-surface-2);
    border-radius: 4px;
  }

  &::-webkit-scrollbar-thumb {
    background: var(--pxn-border-control);
    border-radius: 4px;

    &:hover {
      background: var(--pxn-ink-3);
    }
  }

  @media (max-width: 992px) {
    max-height: none;
    padding: 16px 16px;
  }
}

.enhanced-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.form-label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  font-weight: 700;
  color: var(--pxn-ink);
  text-transform: uppercase;
  letter-spacing: 0.5px;

  svg {
    color: var(--pxn-primary);
    flex-shrink: 0;
    width: 13px;
    height: 13px;
  }
}

.field-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--pxn-ink-2);
  margin-bottom: 4px;
}

/* Payment Methods */
.payment-methods {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;

  @media (max-width: 1024px) {
    grid-template-columns: repeat(2, 1fr);
  }
}

.method-card {
  position: relative;
  padding: 10px 8px;
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md);
  cursor: pointer;
  transition: border-color var(--pxn-dur-1) var(--pxn-ease), background-color var(--pxn-dur-1) var(--pxn-ease);
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  min-height: 70px;

  &:hover {
    border-color: var(--pxn-border-control);
    background: var(--pxn-surface-hover);
  }

  &.selected {
    border-color: var(--pxn-primary);
    background: var(--pxn-selected-bg);
    .method-icon-wrapper { background: var(--pxn-primary); color: var(--pxn-primary-contrast); }
  }

  .method-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    width: 100%;
  }

  .method-icon-wrapper {
    width: 32px;
    height: 32px;
    background: var(--pxn-surface-2);
    border-radius: var(--pxn-radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    color: var(--pxn-ink-2);
  }

  .method-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--pxn-ink);
  }

  .selected-indicator {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 16px;
    height: 16px;
    background: var(--pxn-primary);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--pxn-primary-contrast);

    svg { width: 10px; height: 10px; }
  }
}

/* Amount Inputs */
.amount-row {
      display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;

  @media (max-width: 1024px) {
    grid-template-columns: 1fr;
  }
}

.input-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.input-with-icon {
      position: relative;
      display: flex;
      align-items: center;

  .input-icon {
        position: absolute;
    left: 16px;
    font-size: 16px;
    font-weight: 700;
        color: var(--pxn-primary);
    pointer-events: none;
  }

  .form-input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--pxn-border-control);
    border-radius: var(--pxn-radius-md);
    font-size: 13px;
    font-weight: 500;
    color: var(--pxn-ink);
    font-family: var(--pxn-font-mono);
    transition: border-color var(--pxn-dur-1) var(--pxn-ease), box-shadow var(--pxn-dur-1) var(--pxn-ease);
    background: white;

    &:focus {
      outline: none;
      border-color: var(--pxn-primary);
      box-shadow: 0 0 0 3px var(--pxn-primary-soft);
    }

    &::placeholder {
      color: var(--pxn-ink-3);
      font-weight: 400;
    }
  }
}

/* Change Notification */
.change-notification {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: var(--pxn-success-soft);
  border: 2px solid var(--pxn-success);
  border-radius: 8px;
  margin-top: 8px;
  animation: slideIn 0.4s ease;

  .change-icon {
    width: 30px;
    height: 30px;
    background: var(--pxn-success);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;

    svg {
      width: 16px;
      height: 16px;
    }
  }

  .change-content {
    flex: 1;
    display: flex;
    justify-content: space-between;
    align-items: center;

    .change-title {
      font-size: 10px;
      font-weight: 600;
      color: #047857;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .change-amount {
      font-size: 16px;
      font-weight: 800;
      color: var(--pxn-success);
    }
  }
}

/* Dual Input */
.dual-input {
      display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;

  @media (max-width: 1024px) {
    grid-template-columns: 1fr;
  }
}

.form-select {
  width: 100%;
  padding: 10px 12px;
  border: 2px solid var(--pxn-border);
  border-radius: 8px;
  font-size: 12px;
  font-weight: 500;
  color: var(--pxn-ink);
  transition: all 0.3s ease;
  background: white;
  cursor: pointer;

  &:focus {
    outline: none;
    border-color: var(--pxn-primary);
    box-shadow: 0 0 0 3px var(--pxn-primary-soft);
  }
}

.form-textarea {
  width: 100%;
  padding: 10px 12px;
  border: 2px solid var(--pxn-border);
  border-radius: 8px;
  font-size: 12px;
  font-weight: 500;
  color: var(--pxn-ink);
  transition: all 0.3s ease;
  background: white;
  resize: vertical;
  min-height: 60px;
  font-family: inherit;

  &:focus {
    outline: none;
    border-color: var(--pxn-primary);
    box-shadow: 0 0 0 3px var(--pxn-primary-soft);
  }

  &::placeholder {
    color: var(--pxn-ink-3);
  }
}

/* Payment lines layout: left list + right vertical quick amounts */
.payment-lines-layout {
  display: grid;
  grid-template-columns: 1fr 180px;
  gap: 12px;

  @media (max-width: 992px) {
    grid-template-columns: 1fr;
  }
}

.payment-lines-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.payment-line-card {
  background: var(--pxn-surface-2);
  border: 1px solid var(--pxn-border);
  border-radius: 8px;
  overflow: hidden;
}

.payment-line-header {
  background: white;
  border-bottom: 1px solid var(--pxn-border);
  padding: 10px 12px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.line-badge {
  background: var(--pxn-primary);
  color: white;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 700;
}

.line-title {
  flex: 1;
  font-size: 12px;
  font-weight: 700;
  color: var(--pxn-ink);
}

.line-remove-btn {
  color: var(--pxn-danger);
  padding: 0;
  background: none;
  border: none;
  cursor: pointer;
  transition: all .2s ease;
  font-size: 18px;

  &:hover {
    color: var(--pxn-danger-ink);
    transform: scale(1.1);
  }
}

.payment-line-body {
  padding: 12px;
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;

  @media (max-width: 1024px) {
    grid-template-columns: 1fr;
  }
}

.method-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.method-pill {
  padding: 8px 10px;
  border: 2px solid var(--pxn-border);
  background:  var(--pxn-surface);
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all .2s ease;

  &:hover {
    border-color: var(--pxn-primary);
    background: var(--pxn-primary-softer);
  }

  &.selected {
    border-color: var(--pxn-primary);
    background: var(--pxn-primary-soft);
    color: #2b2e83;
  }
}

.add-line-btn {
  margin-top: 8px;
  outline: none !important;
  box-shadow: none !important;
  &:focus,
  &:active,
  &:focus-visible {
    outline: none !important;
    box-shadow: none !important;
  }
}

.quick-amount-rail {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 8px;
}

.quick-amount-title {
  font-size: 11px;
  font-weight: 700;
  color: var(--pxn-ink);
  text-transform: uppercase;
  letter-spacing: .5px;
}

.quick-amount-vertical {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.quick-btn {
  height: 32px;
  padding: 0 var(--pxn-space-4);
  border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
  color: var(--pxn-ink-2);
  font-family: var(--pxn-font-mono);
  font-size: var(--pxn-fs-sm);
  font-weight: var(--pxn-fw-medium);
  cursor: pointer;
  transition: background-color var(--pxn-dur-1) var(--pxn-ease), border-color var(--pxn-dur-1) var(--pxn-ease), color var(--pxn-dur-1) var(--pxn-ease);
  outline: none;

  &:hover {
    background: var(--pxn-surface-hover);
    border-color: var(--pxn-primary);
    color: var(--pxn-primary-ink);
  }
  &:focus-visible {
    border-color: var(--pxn-primary);
    box-shadow: 0 0 0 3px var(--pxn-focus-ring);
  }
}

/* Action Buttons */
.form-actions-new {
  position: sticky;
  bottom: 0;
  display: flex;
  gap: 10px;
  padding: 12px 20px;
  margin: 16px -20px -16px -20px;
  background: white;
  border-top: 2px solid var(--pxn-border);
  box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.08);
  z-index: 10;

  @media (max-width: 1024px) {
    flex-direction: row;
    gap: 8px;
    padding: 10px 16px;
    margin: 16px -16px -16px -16px;
  }
}

.action-btn {
  flex: 1;
  padding: 12px 20px;
  border: none;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  min-height: 42px;

  &:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .btn-content {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  svg {
    width: 16px;
    height: 16px;
  }
}

.cancel-btn {
  background: var(--pxn-surface-2);
  color: var(--pxn-ink-2);
  border: 2px solid var(--pxn-border);

  &:hover:not(:disabled) {
    background: var(--pxn-border);
    border-color: var(--pxn-border-control);
    transform: none;
  }
}

.submit-btn {
  background: var(--pxn-success);
  color: white;
  box-shadow: 0 4px 12px var(--pxn-success-border);

  &:hover:not(:disabled) {
    box-shadow: 0 6px 16px var(--pxn-success-border);
    transform: none;
  }

  &:active:not(:disabled) {
    transform: scale(0.98);
  }
}

/* Fixed footer buttons */
.payment-footer {
  position: sticky;
  bottom: 0;
  display: flex;
  justify-content: flex-end;
  gap: var(--pxn-space-4);
  padding: var(--pxn-space-5) var(--pxn-space-7);
  background: var(--pxn-surface);
  border-top: 1px solid var(--pxn-border);
  z-index: 20;
}

/* Saved Cards minimal styling */
.saved-cards {
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border);
  border-radius: 10px;
  overflow: hidden;
}

.saved-cards-header {
  padding: 10px 12px;
  font-size: 11px;
  font-weight: 700;
  color: var(--pxn-ink);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  background: var(--pxn-surface-2);
  border-bottom: 1px solid var(--pxn-border);
}

.saved-cards-table {
  margin: 0;
  width: 100%;
}

.saved-cards-table thead th {
  font-size: 11px;
  font-weight: 700;
  color: var(--pxn-ink-2);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  background: var(--pxn-surface-2);
  border-bottom: 1px solid var(--pxn-border);
}

.saved-cards-table tbody td {
  vertical-align: middle;
  font-size: 12px;
}

.bg-selected-card {
  background: var(--pxn-primary-softer);
}

.default-badge {
  display: inline-block;
  margin-left: 8px;
  padding: 2px 6px;
  border-radius: 999px;
  background: var(--pxn-info-soft);
  color: #0369a1;
  border: 1px solid #67e8f9;
  font-size: 10px;
  font-weight: 700;
  vertical-align: middle;
}

.selected-badge {
  display: inline-block;
  margin-left: 6px;
  padding: 2px 6px;
  border-radius: 999px;
  background: var(--pxn-success-soft);
  color: #065f46;
  border: 1px solid var(--pxn-success-border);
  font-size: 10px;
  font-weight: 700;
  vertical-align: middle;
}

.footer-btn {
  padding: 0 var(--pxn-space-6);
  border: 1px solid transparent;
  border-radius: var(--pxn-radius-md);
  font-family: var(--pxn-font-sans);
  font-size: var(--pxn-fs-body);
  font-weight: var(--pxn-fw-medium);
  cursor: pointer;
  transition: background-color var(--pxn-dur-1) var(--pxn-ease), border-color var(--pxn-dur-1) var(--pxn-ease), filter var(--pxn-dur-1) var(--pxn-ease);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--pxn-space-3);
  min-height: var(--pxn-control-h-lg);

  &:disabled { opacity: 0.55; cursor: not-allowed; }
}

.footer-cancel {
  background: transparent;
  color: var(--pxn-ink-2);
  border-color: var(--pxn-border-control);

  &:hover:not(:disabled) { background: var(--pxn-surface-hover); border-color: var(--pxn-border-control); color: var(--pxn-ink); }
}

.footer-submit {
  background: var(--pxn-primary);
  color: var(--pxn-primary-contrast);
  border-color: var(--pxn-primary);

  &:hover:not(:disabled) { filter: brightness(0.96); }
}

/* Loading Spinner */
.loading-spinner {
    display: inline-block;
  width: 16px;
  height: 16px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
  }

/* Animations */
  @keyframes spin {
    to {
      transform: rotate(360deg);
    }
}

@keyframes pulse {
  0%, 100% {
    opacity: 1;
  }
  50% {
    opacity: 0.5;
  }
}

@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* Responsive Design */
@media (max-width: 992px) {
  .payment-header {
    padding: 14px 20px;

    .icon-wrapper {
      width: 40px;
      height: 40px;
      font-size: 20px;
    }

    .header-text {
      .modal-title {
        font-size: 18px;
      }
    }
  }

  .transaction-info {
    padding: 14px 12px;
  }

  .payment-form-area {
    padding: 16px 20px;
  }
}

@media (max-width: 1024px) {
  .payment-header {
    padding: 12px 16px;

    .header-left {
      gap: 10px;
    }

    .icon-wrapper {
      width: 36px;
      height: 36px;
      font-size: 18px;
    }

    .header-text {
      .modal-title {
        font-size: 16px;
      }

      .modal-subtitle {
        font-size: 10px;
      }
    }

    .close-button {
      width: 32px;
      height: 32px;
    }
  }

  .payment-form-area {
    padding: 14px 16px;
  }

  /* Ensure transaction info doesn't scroll vertically on small screens */
  .transaction-info {
    overflow-y: visible;
    max-height: none;
  }
}
</style>
