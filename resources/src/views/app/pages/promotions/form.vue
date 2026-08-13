<template>
  <div class="pf-page" :class="{ 'pf-loading': isLoading }">
    <!-- Sticky header bar -->
    <header class="pf-header">
      <div class="pf-header-inner">
        <div class="pf-header-left">
          <button class="pf-icon-btn" @click="goBack" :title="$t('Back') || 'Back'">
            <lucide-icon name="arrow-left" />
          </button>
          <div>
            <div class="pf-eyebrow">{{ $t('Promotions') || 'Promotions' }}</div>
            <h1 class="pf-title">
              {{ isEdit ? ($t('EditPromotion') || 'Edit promotion') : ($t('NewPromotion') || 'New promotion') }}
              <span v-if="isEdit && form.name" class="pf-title-sub">— {{ form.name }}</span>
            </h1>
          </div>
        </div>
        <div class="pf-header-actions">
          <button class="pf-btn pf-btn-ghost" @click="goBack" :disabled="saving">
            {{ $t('Cancel') || 'Cancel' }}
          </button>
          <button
            class="pf-btn pf-btn-secondary"
            @click="submit(false)"
            :disabled="!canSave || saving"
          >
            <lucide-icon name="archive" />
            {{ $t('SaveAsDraft') || 'Save as Draft' }}
          </button>
          <button
            class="pf-btn pf-btn-primary"
            @click="submit(true)"
            :disabled="!canSave || saving"
          >
            <span v-if="saving" class="pf-spinner"></span>
            <lucide-icon v-else name="check" />
            {{ isEdit ? ($t('SaveChanges') || 'Save changes') : ($t('SaveAndActivate') || 'Save & Activate') }}
          </button>
        </div>
      </div>
    </header>

    <div v-if="isLoading" class="pf-loading-state">
      <div class="pf-spinner pf-spinner-lg"></div>
    </div>

    <div v-else class="pf-body">
      <!-- Main column -->
      <main class="pf-main">
        <!-- Section 1: Basics -->
        <section class="pf-card">
          <div class="pf-card-head">
            <div class="pf-step">1</div>
            <div class="pf-card-headings">
              <h2>{{ $t('Basics') || 'Basics' }}</h2>
              <p>{{ $t('BasicsHint') || 'Give the promotion a clear name and choose its type.' }}</p>
            </div>
          </div>
          <div class="pf-card-body">
            <div class="pf-field">
              <label>{{ $t('Name') || 'Name' }} <span class="pf-req">*</span></label>
              <input
                v-model="form.name"
                :placeholder="$t('PromoNamePh') || 'e.g. Summer Weekend Special'"
                :class="{ 'pf-input-error': fieldErrors.name }"
              />
              <small v-if="fieldErrors.name" class="pf-error">{{ fieldErrors.name }}</small>
            </div>

            <div class="pf-grid-2">
              <div class="pf-field">
                <label>{{ $t('Type') || 'Type' }}</label>
                <div class="pf-segment">
                  <button
                    type="button"
                    :class="{ active: form.kind === 'discount' }"
                    @click="form.kind = 'discount'"
                  >
                    <lucide-icon name="percent" />
                    {{ $t('Discount') || 'Discount' }}
                  </button>
                  <button
                    type="button"
                    :class="{ active: form.kind === 'promotion' }"
                    @click="form.kind = 'promotion'"
                  >
                    <lucide-icon name="gift" />
                    {{ $t('Promotion') || 'Promotion' }}
                  </button>
                </div>
                <small class="pf-help">{{
                  form.kind === 'discount'
                    ? ($t('DiscountKindHint') || 'Unconditional reduction that auto-applies.')
                    : ($t('PromotionKindHint') || 'Conditional offer; usually paired with a code.')
                }}</small>
              </div>

              <div class="pf-field">
                <label>{{ $t('Code') || 'Code' }} <span class="pf-optional">{{ $t('Optional') || 'optional' }}</span></label>
                <input
                  v-model="form.code"
                  class="pf-input-mono"
                  :placeholder="$t('CodePh') || 'SUMMER15'"
                  @input="form.code = form.code.toUpperCase().replace(/\s+/g, '')"
                />
                <small class="pf-help">
                  {{ $t('CodeHint') || 'If set, customers must enter this code at checkout.' }}
                </small>
              </div>
            </div>

            <div class="pf-field">
              <label>{{ $t('Description') || 'Description' }}</label>
              <textarea
                v-model="form.description"
                rows="2"
                :placeholder="$t('DescriptionPh') || 'A short note for staff or receipts.'"
              ></textarea>
            </div>
          </div>
        </section>

        <!-- Section 2: Discount value -->
        <section class="pf-card">
          <div class="pf-card-head">
            <div class="pf-step">2</div>
            <div class="pf-card-headings">
              <h2>{{ $t('DiscountValue') || 'Discount value' }}</h2>
              <p>{{ $t('DiscountValueHint') || 'How much to take off the cart.' }}</p>
            </div>
          </div>
          <div class="pf-card-body">
            <div class="pf-grid-2">
              <div class="pf-field">
                <label>{{ $t('DiscountType') || 'Discount type' }}</label>
                <div class="pf-segment">
                  <button
                    type="button"
                    :class="{ active: form.discount_type === 'percentage' }"
                    @click="form.discount_type = 'percentage'"
                  >% {{ $t('Percentage') || 'Percentage' }}</button>
                  <button
                    type="button"
                    :class="{ active: form.discount_type === 'fixed' }"
                    @click="form.discount_type = 'fixed'"
                  >{{ currencySymbol || '$' }} {{ $t('Fixed') || 'Fixed' }}</button>
                </div>
              </div>
              <div class="pf-field">
                <label>{{ $t('Value') || 'Value' }} <span class="pf-req">*</span></label>
                <div class="pf-input-affix">
                  <span class="pf-prefix" v-if="form.discount_type === 'fixed'">{{ currencySymbol || '$' }}</span>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    v-model.number="form.discount_value"
                    :placeholder="form.discount_type === 'percentage' ? '15' : '10.00'"
                    :class="{ 'pf-input-error': fieldErrors.discount_value }"
                  />
                  <span class="pf-suffix" v-if="form.discount_type === 'percentage'">%</span>
                </div>
                <small v-if="fieldErrors.discount_value" class="pf-error">{{ fieldErrors.discount_value }}</small>
              </div>
            </div>
          </div>
        </section>

        <!-- Section 3: Validity -->
        <section class="pf-card">
          <div class="pf-card-head">
            <div class="pf-step">3</div>
            <div class="pf-card-headings">
              <h2>{{ $t('Validity') || 'Validity' }}</h2>
              <p>{{ $t('ValidityHint') || 'When the promotion is honored.' }}</p>
            </div>
          </div>
          <div class="pf-card-body">
            <div class="pf-grid-2">
              <div class="pf-field">
                <label>{{ $t('StartsAt') || 'Starts at' }} <span class="pf-optional">{{ $t('Optional') || 'optional' }}</span></label>
                <input type="datetime-local" v-model="form.starts_at" />
              </div>
              <div class="pf-field">
                <label>{{ $t('EndsAt') || 'Ends at' }} <span class="pf-optional">{{ $t('Optional') || 'optional' }}</span></label>
                <input type="datetime-local" v-model="form.ends_at" />
              </div>
            </div>

            <div class="pf-toggle-row">
              <label class="pf-toggle">
                <input type="checkbox" v-model="restrictHours" />
                <span class="pf-toggle-track"><span class="pf-toggle-thumb"></span></span>
                <span class="pf-toggle-label">{{ $t('RestrictHours') || 'Restrict to specific hours of the day' }}</span>
              </label>
            </div>
            <div class="pf-grid-2" v-if="restrictHours">
              <div class="pf-field">
                <label>{{ $t('FromTime') || 'From time' }}</label>
                <input type="time" v-model="form.time_of_day_start" step="1" />
              </div>
              <div class="pf-field">
                <label>{{ $t('ToTime') || 'To time' }}</label>
                <input type="time" v-model="form.time_of_day_end" step="1" />
                <small class="pf-help">{{ $t('HoursCrossMidnightHint') || 'Times can cross midnight (e.g. 22:00 → 02:00).' }}</small>
              </div>
            </div>
          </div>
        </section>

        <!-- Section 4: Conditions -->
        <section class="pf-card">
          <div class="pf-card-head">
            <div class="pf-step">4</div>
            <div class="pf-card-headings">
              <h2>{{ $t('Conditions') || 'Conditions' }}</h2>
              <p>{{ $t('ConditionsHint') || 'Minimum requirements before the promotion kicks in.' }}</p>
            </div>
          </div>
          <div class="pf-card-body">
            <div class="pf-grid-2">
              <div class="pf-field">
                <label>{{ $t('MinCartTotal') || 'Minimum cart total' }}</label>
                <div class="pf-input-affix">
                  <span class="pf-prefix">{{ currencySymbol || '$' }}</span>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    v-model.number="form.min_cart_total"
                    placeholder="0.00"
                  />
                </div>
              </div>
              <div class="pf-field">
                <label>{{ $t('MinItemCount') || 'Minimum item count' }}</label>
                <input
                  type="number"
                  min="0"
                  v-model.number="form.min_item_count"
                  placeholder="0"
                />
              </div>
            </div>

            <div class="pf-field">
              <label>{{ $t('ProductScope') || 'Product scope' }}</label>
              <div class="pf-radio-grid">
                <label class="pf-radio-card" :class="{ active: form.product_scope === 'all' }">
                  <input type="radio" v-model="form.product_scope" value="all" />
                  <div class="pf-radio-card-icon"><lucide-icon name="package" /></div>
                  <div>
                    <div class="pf-radio-card-title">{{ $t('AllProducts') || 'All products' }}</div>
                    <div class="pf-radio-card-sub">{{ $t('AllProductsHint') || 'Applies to every item in the cart.' }}</div>
                  </div>
                </label>
                <label class="pf-radio-card" :class="{ active: form.product_scope === 'specific' }">
                  <input type="radio" v-model="form.product_scope" value="specific" />
                  <div class="pf-radio-card-icon"><lucide-icon name="package-search" /></div>
                  <div>
                    <div class="pf-radio-card-title">{{ $t('SpecificProducts') || 'Specific products' }}</div>
                    <div class="pf-radio-card-sub">{{ $t('SpecificProductsHint') || 'Only triggers when one of these is in the cart.' }}</div>
                  </div>
                </label>
              </div>
            </div>

            <div v-if="form.product_scope === 'specific'" class="pf-field">
              <label>
                {{ $t('Products') || 'Products' }}
                <span class="pf-count-badge" v-if="form.product_ids.length">{{ form.product_ids.length }}</span>
              </label>
              <div class="pf-product-picker">
                <div class="pf-product-search">
                  <lucide-icon name="search" />
                  <input
                    v-model="productSearch"
                    :placeholder="$t('SearchProducts') || 'Search products…'"
                  />
                </div>
                <div class="pf-product-list">
                  <label
                    v-for="p in filteredProducts.slice(0, 100)"
                    :key="p.id"
                    class="pf-product-item"
                    :class="{ active: form.product_ids.includes(p.id) }"
                  >
                    <input
                      type="checkbox"
                      :value="p.id"
                      v-model="form.product_ids"
                    />
                    <div class="pf-product-info">
                      <div class="pf-product-name">{{ p.name }}</div>
                      <div class="pf-product-code">{{ p.code }}</div>
                    </div>
                  </label>
                  <div v-if="filteredProducts.length === 0" class="pf-empty">
                    {{ $t('NoProductsFound') || 'No products match your search.' }}
                  </div>
                  <div v-else-if="filteredProducts.length > 100" class="pf-empty">
                    {{ $t('TooManyResults') || 'Showing first 100 — refine the search to see more.' }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <!-- Section 5: Warehouses -->
        <section class="pf-card">
          <div class="pf-card-head">
            <div class="pf-step">5</div>
            <div class="pf-card-headings">
              <h2>{{ $t('Warehouses') || 'Warehouses' }}</h2>
              <p>{{ $t('WarehousesHint') || 'Where this promotion is honored.' }}</p>
            </div>
            <button
              v-if="warehouses.length"
              type="button"
              class="pf-link-btn"
              @click="toggleAllWarehouses"
            >
              {{ allWarehousesSelected ? ($t('Deselect_all') || 'Deselect all') : ($t('Select_all') || 'Select all') }}
            </button>
          </div>
          <div class="pf-card-body">
            <div class="pf-warehouse-grid" v-if="warehouses.length">
              <label
                v-for="w in warehouses"
                :key="w.id"
                class="pf-warehouse-card"
                :class="{ active: form.warehouse_ids.includes(w.id) }"
              >
                <input type="checkbox" :value="w.id" v-model="form.warehouse_ids" />
                <div class="pf-warehouse-icon">
                  <lucide-icon name="store" />
                </div>
                <div class="pf-warehouse-info">
                  <div class="pf-warehouse-name">{{ w.name }}</div>
                  <div class="pf-warehouse-sub">{{ [w.city, w.country].filter(Boolean).join(', ') || '—' }}</div>
                </div>
                <div class="pf-warehouse-check">
                  <lucide-icon name="check" />
                </div>
              </label>
            </div>
            <div v-else class="pf-empty">{{ $t('NoWarehousesYet') || 'No warehouses configured yet.' }}</div>
          </div>
        </section>

        <!-- Section 6: Stacking & limits -->
        <section class="pf-card">
          <div class="pf-card-head">
            <div class="pf-step">6</div>
            <div class="pf-card-headings">
              <h2>{{ $t('StackingAndLimits') || 'Stacking & limits' }}</h2>
              <p>{{ $t('StackingHint') || 'How this competes with other promotions, and how often it can be used.' }}</p>
            </div>
          </div>
          <div class="pf-card-body">
            <div class="pf-grid-2">
              <div class="pf-field">
                <label>{{ $t('Priority') || 'Priority' }}</label>
                <input type="number" v-model.number="form.priority" placeholder="0" />
                <small class="pf-help">{{ $t('HigherWins') || 'Higher priority wins when promotions overlap.' }}</small>
              </div>
              <div class="pf-field">
                <label>{{ $t('Stackable') || 'Stackable' }}</label>
                <label class="pf-toggle">
                  <input type="checkbox" v-model="form.stackable" />
                  <span class="pf-toggle-track"><span class="pf-toggle-thumb"></span></span>
                  <span class="pf-toggle-label">
                    {{ form.stackable ? ($t('YesStacksWithOthers') || 'Yes — stacks with other stackable promotions') : ($t('NoExclusive') || 'No — exclusive (only this one wins)') }}
                  </span>
                </label>
              </div>
            </div>
            <div class="pf-grid-2">
              <div class="pf-field">
                <label>{{ $t('UsageLimitTotal') || 'Total usage limit' }}</label>
                <input
                  type="number"
                  min="0"
                  v-model.number="form.usage_limit_total"
                  :placeholder="$t('Unlimited') || 'Unlimited'"
                />
                <small class="pf-help">{{ $t('LeaveBlankUnlimited') || 'Leave blank for unlimited.' }}</small>
              </div>
              <div class="pf-field">
                <label>{{ $t('UsageLimitPerCustomer') || 'Per-customer limit' }}</label>
                <input
                  type="number"
                  min="0"
                  v-model.number="form.usage_limit_per_customer"
                  :placeholder="$t('Unlimited') || 'Unlimited'"
                />
                <small class="pf-help">{{ $t('LeaveBlankUnlimited') || 'Leave blank for unlimited.' }}</small>
              </div>
            </div>
          </div>
        </section>
      </main>

      <!-- Side preview -->
      <aside class="pf-side">
        <div class="pf-side-sticky">
          <!-- Live preview card -->
          <section class="pf-preview-card">
            <div class="pf-preview-tag">{{ $t('Preview') || 'Preview' }}</div>
            <div class="pf-preview-discount">
              <div class="pf-preview-value-row">
                <span class="pf-preview-value">{{ previewValue }}</span>
                <span class="pf-preview-off">OFF</span>
              </div>
            </div>
            <div class="pf-preview-body">
              <div class="pf-preview-name">{{ form.name || ($t('UntitledPromotion') || 'Untitled promotion') }}</div>
              <div class="pf-preview-desc" v-if="form.description">{{ form.description }}</div>
              <div class="pf-preview-meta">
                <span class="pf-tag pf-tag-kind" :data-kind="form.kind">{{ form.kind }}</span>
                <span class="pf-tag pf-tag-code" v-if="form.code">{{ form.code }}</span>
                <span class="pf-tag" :class="form.is_active ? 'pf-tag-active' : 'pf-tag-draft'">
                  {{ form.is_active ? ($t('Active') || 'Active') : ($t('Draft') || 'Draft') }}
                </span>
              </div>
            </div>
          </section>

          <!-- Applies at -->
          <section class="pf-side-card">
            <div class="pf-side-card-head">
              <lucide-icon name="map-pin" />
              <h4>{{ $t('AppliesAt') || 'Applies at' }}</h4>
              <span class="pf-side-count">{{ selectedWarehouseObjects.length }}</span>
            </div>
            <div class="pf-chips" v-if="selectedWarehouseObjects.length">
              <span v-for="w in selectedWarehouseObjects" :key="w.id" class="pf-chip">{{ w.name }}</span>
            </div>
            <p v-else class="pf-empty-mini">{{ $t('PickAtLeastOne') || 'Pick at least one warehouse.' }}</p>
          </section>

          <!-- Validity summary -->
          <section class="pf-side-card">
            <div class="pf-side-card-head">
              <lucide-icon name="calendar" />
              <h4>{{ $t('Validity') || 'Validity' }}</h4>
            </div>
            <div class="pf-summary-line">
              <span class="pf-summary-label">{{ $t('Window') || 'Window' }}</span>
              <span class="pf-summary-value">{{ validityWindowLabel }}</span>
            </div>
            <div class="pf-summary-line" v-if="restrictHours && (form.time_of_day_start || form.time_of_day_end)">
              <span class="pf-summary-label">{{ $t('Hours') || 'Hours' }}</span>
              <span class="pf-summary-value">{{ form.time_of_day_start || '00:00:00' }} → {{ form.time_of_day_end || '23:59:59' }}</span>
            </div>
          </section>

          <!-- Conditions summary -->
          <section class="pf-side-card">
            <div class="pf-side-card-head">
              <lucide-icon name="filter" />
              <h4>{{ $t('Conditions') || 'Conditions' }}</h4>
            </div>
            <div class="pf-summary-line">
              <span class="pf-summary-label">{{ $t('Cart') || 'Cart' }}</span>
              <span class="pf-summary-value">{{ form.min_cart_total ? '≥ ' + (currencySymbol || '$') + ' ' + form.min_cart_total : ($t('Any') || 'Any') }}</span>
            </div>
            <div class="pf-summary-line">
              <span class="pf-summary-label">{{ $t('Items') || 'Items' }}</span>
              <span class="pf-summary-value">{{ form.min_item_count ? '≥ ' + form.min_item_count : ($t('Any') || 'Any') }}</span>
            </div>
            <div class="pf-summary-line">
              <span class="pf-summary-label">{{ $t('Scope') || 'Scope' }}</span>
              <span class="pf-summary-value">
                {{ form.product_scope === 'specific' ? (($t('NProducts') || '{n} products').replace('{n}', form.product_ids.length)) : ($t('AllProducts') || 'All products') }}
              </span>
            </div>
          </section>
        </div>
      </aside>
    </div>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";

const emptyForm = () => ({
  id: null,
  name: "",
  code: "",
  description: "",
  kind: "discount",
  discount_type: "percentage",
  discount_value: 0,
  is_active: true,
  starts_at: "",
  ends_at: "",
  time_of_day_start: "",
  time_of_day_end: "",
  min_cart_total: null,
  min_item_count: null,
  product_scope: "all",
  priority: 0,
  stackable: false,
  usage_limit_total: null,
  usage_limit_per_customer: null,
  warehouse_ids: [],
  product_ids: []
});

export default {
  metaInfo: { title: "Promotion" },

  data() {
    return {
      isLoading: true,
      saving: false,
      form: emptyForm(),
      warehouses: [],
      products: [],
      productSearch: "",
      restrictHours: false,
      fieldErrors: {}
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),
    currencySymbol() {
      return (this.currentUser && this.currentUser.currency) || "";
    },
    isEdit() {
      return !!this.$route.params.id;
    },
    canSave() {
      return !!(this.form.name && this.form.name.trim()) && Number(this.form.discount_value) >= 0;
    },
    previewValue() {
      const val = Number(this.form.discount_value || 0);
      if (this.form.discount_type === "percentage") {
        return val + "%";
      }
      return (this.currencySymbol || "$") + " " + val.toFixed(2);
    },
    selectedWarehouseObjects() {
      const ids = this.form.warehouse_ids || [];
      return (this.warehouses || []).filter(w => ids.includes(w.id));
    },
    allWarehousesSelected() {
      return this.warehouses.length > 0 && this.form.warehouse_ids.length === this.warehouses.length;
    },
    filteredProducts() {
      const q = (this.productSearch || "").toLowerCase().trim();
      if (!q) return this.products;
      return this.products.filter(p =>
        (p.name && p.name.toLowerCase().includes(q)) ||
        (p.code && p.code.toLowerCase().includes(q))
      );
    },
    validityWindowLabel() {
      const f = this.form.starts_at ? this.form.starts_at.replace("T", " ").substring(0, 16) : null;
      const t = this.form.ends_at ? this.form.ends_at.replace("T", " ").substring(0, 16) : null;
      if (!f && !t) return this.$t("Always") || "Always";
      if (f && !t) return (this.$t("From") || "From") + " " + f;
      if (!f && t) return (this.$t("Until") || "Until") + " " + t;
      return f + " → " + t;
    }
  },

  watch: {
    restrictHours(val) {
      if (!val) {
        this.form.time_of_day_start = "";
        this.form.time_of_day_end = "";
      }
    }
  },

  methods: {
    goBack() {
      this.$router.push("/app/promotions");
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    },
    toggleAllWarehouses() {
      if (this.allWarehousesSelected) {
        this.form.warehouse_ids = [];
      } else {
        this.form.warehouse_ids = this.warehouses.map(w => w.id);
      }
    },
    toDtLocal(value) {
      if (!value) return "";
      const s = String(value).replace(" ", "T");
      return s.length >= 16 ? s.substring(0, 16) : s;
    },
    normalizeTime(value) {
      if (!value) return null;
      return value.length === 5 ? value + ":00" : value;
    },
    buildPayload(activate) {
      const f = this.form;
      return {
        name: (f.name || "").trim(),
        code: f.code ? f.code.trim() : null,
        description: f.description || null,
        kind: f.kind,
        discount_type: f.discount_type,
        discount_value: Number(f.discount_value) || 0,
        is_active: activate === undefined ? !!f.is_active : !!activate,
        starts_at: f.starts_at ? f.starts_at.replace("T", " ") + ":00" : null,
        ends_at: f.ends_at ? f.ends_at.replace("T", " ") + ":00" : null,
        time_of_day_start: this.normalizeTime(f.time_of_day_start),
        time_of_day_end: this.normalizeTime(f.time_of_day_end),
        min_cart_total:
          f.min_cart_total === "" || f.min_cart_total === null ? null : Number(f.min_cart_total),
        min_item_count:
          f.min_item_count === "" || f.min_item_count === null ? null : Number(f.min_item_count),
        product_scope: f.product_scope,
        priority: Number(f.priority) || 0,
        stackable: !!f.stackable,
        usage_limit_total:
          f.usage_limit_total === "" || f.usage_limit_total === null ? null : Number(f.usage_limit_total),
        usage_limit_per_customer:
          f.usage_limit_per_customer === "" || f.usage_limit_per_customer === null
            ? null
            : Number(f.usage_limit_per_customer),
        warehouse_ids: f.warehouse_ids || [],
        product_ids: f.product_scope === "specific" ? (f.product_ids || []) : []
      };
    },
    validate() {
      this.fieldErrors = {};
      if (!this.form.name || !this.form.name.trim()) {
        this.fieldErrors.name = this.$t("FieldRequired") || "Required";
      }
      if (Number(this.form.discount_value) < 0) {
        this.fieldErrors.discount_value = this.$t("MustBePositive") || "Must be ≥ 0";
      }
      return Object.keys(this.fieldErrors).length === 0;
    },
    submit(activate) {
      if (!this.validate()) {
        this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        return;
      }
      this.saving = true;
      NProgress.start();
      const payload = this.buildPayload(activate);
      const promise = this.isEdit
        ? axios.put("promotions/" + this.form.id, payload)
        : axios.post("promotions", payload);

      promise
        .then(() => {
          NProgress.done();
          this.saving = false;
          this.makeToast(
            "success",
            this.isEdit ? this.$t("Successfully_Updated") : this.$t("Successfully_Created"),
            this.$t("Success")
          );
          this.$router.push("/app/promotions");
        })
        .catch(error => {
          NProgress.done();
          this.saving = false;
          let msg = this.$t("InvalidData");
          if (error && error.response && error.response.data) {
            const data = error.response.data;
            if (data.errors) {
              msg = Object.values(data.errors).flat().join(" ");
            } else if (data.message) {
              msg = data.message;
            }
          }
          this.makeToast("danger", msg, this.$t("Failed"));
        });
    },
    fetchPromotion(id) {
      return axios
        .get("promotions/" + id)
        .then(response => {
          const promo = response.data && response.data.promotion;
          if (!promo) {
            throw new Error("Not found");
          }
          this.form = {
            id: promo.id,
            name: promo.name || "",
            code: promo.code || "",
            description: promo.description || "",
            kind: promo.kind || "discount",
            discount_type: promo.discount_type || "percentage",
            discount_value: Number(promo.discount_value) || 0,
            is_active: !!promo.is_active,
            starts_at: this.toDtLocal(promo.starts_at),
            ends_at: this.toDtLocal(promo.ends_at),
            time_of_day_start: promo.time_of_day_start || "",
            time_of_day_end: promo.time_of_day_end || "",
            min_cart_total: promo.min_cart_total !== null ? Number(promo.min_cart_total) : null,
            min_item_count: promo.min_item_count !== null ? Number(promo.min_item_count) : null,
            product_scope: promo.product_scope || "all",
            priority: Number(promo.priority) || 0,
            stackable: !!promo.stackable,
            usage_limit_total:
              promo.usage_limit_total !== null ? Number(promo.usage_limit_total) : null,
            usage_limit_per_customer:
              promo.usage_limit_per_customer !== null ? Number(promo.usage_limit_per_customer) : null,
            warehouse_ids: (promo.warehouses || []).map(w => w.id),
            product_ids: (promo.products || []).map(p => p.id)
          };
          this.restrictHours = !!(promo.time_of_day_start || promo.time_of_day_end);
        });
    },
    fetchWarehouses() {
      return axios
        .get("warehouses?page=1&limit=-1&SortField=id&SortType=asc")
        .then(response => {
          this.warehouses = (response.data && response.data.warehouses) || [];
        })
        .catch(() => {});
    },
    fetchProducts() {
      return axios
        .get("products?page=1&limit=-1&SortField=id&SortType=asc")
        .then(response => {
          const data = response.data;
          if (Array.isArray(data)) this.products = data;
          else if (data && Array.isArray(data.products)) this.products = data.products;
          else if (data && Array.isArray(data.data)) this.products = data.data;
        })
        .catch(() => {});
    },
  },

  created() {
    NProgress.start();
    const tasks = [this.fetchWarehouses(), this.fetchProducts()];
    if (this.isEdit) {
      tasks.push(this.fetchPromotion(this.$route.params.id));
    }
    Promise.all(tasks)
      .catch(() => {})
      .finally(() => {
        this.isLoading = false;
        NProgress.done();
      });
  }
};
</script>

<style scoped>
/* ---------------- Page shell ---------------- */
.pf-page {
  background: #f5f6fa;
  min-height: calc(100vh - 60px);
  padding-bottom: 40px;
}
.pf-loading-state {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 80px 0;
}

/* ---------------- Header ---------------- */
.pf-header {
  position: sticky;
  top: 0;
  background: rgba(255, 255, 255, 0.92);
  backdrop-filter: saturate(180%) blur(10px);
  border-bottom: 1px solid #ececf2;
  z-index: 10;
}
.pf-header-inner {
  max-width: 1400px;
  margin: 0 auto;
  padding: 14px 24px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}
.pf-header-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.pf-icon-btn {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: #f0f0f7;
  border: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.15s;
  color: #54546a;
}
.pf-icon-btn:hover {
  background: #e6e6f0;
  color: #1f1f2c;
}
.pf-eyebrow {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #8d8da0;
  margin-bottom: 2px;
}
.pf-title {
  font-size: 18px;
  font-weight: 700;
  color: #1f1f2c;
  margin: 0;
  line-height: 1.2;
}
.pf-title-sub {
  color: #8d8da0;
  font-weight: 500;
  margin-left: 6px;
}
.pf-header-actions {
  display: flex;
  gap: 8px;
  flex-shrink: 0;
}
.pf-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 38px;
  padding: 0 14px;
  border-radius: 10px;
  border: 0;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s;
  white-space: nowrap;
}
.pf-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.pf-btn-ghost {
  background: transparent;
  color: #54546a;
}
.pf-btn-ghost:hover:not(:disabled) {
  background: #f0f0f7;
}
.pf-btn-secondary {
  background: #fff;
  color: #54546a;
  border: 1px solid #e6e6ec;
}
.pf-btn-secondary:hover:not(:disabled) {
  border-color: #c8c8d8;
  color: #1f1f2c;
}
.pf-btn-primary {
  background: linear-gradient(135deg, #6f53d9 0%, #5a3fc0 100%);
  color: #fff;
  box-shadow: 0 4px 14px -4px rgba(111, 83, 217, 0.5);
}
.pf-btn-primary:hover:not(:disabled) {
  box-shadow: 0 6px 18px -4px rgba(111, 83, 217, 0.65);
  transform: translateY(-1px);
}

/* ---------------- Body grid ---------------- */
.pf-body {
  max-width: 1400px;
  margin: 24px auto 0;
  padding: 0 24px;
  display: grid;
  grid-template-columns: minmax(0, 1fr) 360px;
  gap: 24px;
  align-items: start;
}
@media (max-width: 1100px) {
  .pf-body {
    grid-template-columns: 1fr;
  }
}

/* ---------------- Cards ---------------- */
.pf-card {
  background: #fff;
  border-radius: 14px;
  border: 1px solid #ececf2;
  box-shadow: 0 1px 2px rgba(20, 22, 40, 0.04);
  margin-bottom: 18px;
  overflow: hidden;
}
.pf-card-head {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 20px 24px 0;
}
.pf-step {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: linear-gradient(135deg, #6f53d9 0%, #5a3fc0 100%);
  color: #fff;
  font-weight: 700;
  font-size: 13px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.pf-card-headings {
  flex: 1;
  min-width: 0;
}
.pf-card-headings h2 {
  margin: 0 0 2px;
  font-size: 16px;
  font-weight: 700;
  color: #1f1f2c;
}
.pf-card-headings p {
  margin: 0;
  font-size: 12.5px;
  color: #8d8da0;
}
.pf-card-body {
  padding: 18px 24px 24px;
}

/* ---------------- Inputs ---------------- */
.pf-field {
  display: block;
  margin-bottom: 14px;
}
.pf-field:last-child {
  margin-bottom: 0;
}
.pf-field label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: #54546a;
  margin-bottom: 6px;
  letter-spacing: 0.01em;
}
.pf-field input[type='text'],
.pf-field input[type='number'],
.pf-field input[type='time'],
.pf-field input[type='date'],
.pf-field input[type='datetime-local'],
.pf-field textarea,
.pf-field select,
.pf-field input:not([type]) {
  width: 100%;
  height: 40px;
  padding: 0 12px;
  border: 1px solid #e6e6ec;
  border-radius: 10px;
  background: #fafafd;
  font-size: 13.5px;
  color: #1f1f2c;
  outline: none;
  transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
  font-family: inherit;
}
.pf-field textarea {
  height: auto;
  padding: 10px 12px;
  resize: vertical;
  min-height: 60px;
}
.pf-field input:focus,
.pf-field textarea:focus,
.pf-field select:focus {
  border-color: #6f53d9;
  background: #fff;
  box-shadow: 0 0 0 4px rgba(111, 83, 217, 0.12);
}
.pf-input-error {
  border-color: #ef4444 !important;
  box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1) !important;
}
.pf-input-mono {
  font-family: 'JetBrains Mono', 'Menlo', monospace !important;
  letter-spacing: 0.02em;
}
.pf-req {
  color: #ef4444;
  margin-left: 2px;
}
.pf-optional {
  color: #b8b8c8;
  font-weight: 500;
  font-size: 11px;
  margin-left: 4px;
}
.pf-help {
  display: block;
  font-size: 11.5px;
  color: #8d8da0;
  margin-top: 4px;
  line-height: 1.4;
}
.pf-error {
  display: block;
  font-size: 11.5px;
  color: #ef4444;
  margin-top: 4px;
}
.pf-count-badge {
  display: inline-block;
  background: #6f53d9;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  padding: 1px 7px;
  border-radius: 10px;
  margin-left: 6px;
}

/* Grid helpers */
.pf-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
  margin-bottom: 14px;
}
.pf-grid-2:last-child {
  margin-bottom: 0;
}
@media (max-width: 600px) {
  .pf-grid-2 {
    grid-template-columns: 1fr;
  }
}

/* Affix input (prefix/suffix) */
.pf-input-affix {
  display: flex;
  align-items: center;
  border: 1px solid #e6e6ec;
  border-radius: 10px;
  background: #fafafd;
  transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
}
.pf-input-affix:focus-within {
  border-color: #6f53d9;
  background: #fff;
  box-shadow: 0 0 0 4px rgba(111, 83, 217, 0.12);
}
.pf-input-affix input {
  border: 0 !important;
  background: transparent !important;
  box-shadow: none !important;
  height: 38px;
  flex: 1;
  padding: 0 10px !important;
  font-size: 13.5px;
}
.pf-input-affix input:focus {
  border: 0 !important;
  box-shadow: none !important;
}
.pf-prefix,
.pf-suffix {
  color: #8d8da0;
  font-size: 13px;
  font-weight: 600;
  padding: 0 12px;
}
.pf-prefix {
  border-right: 1px solid #ececf2;
}
.pf-suffix {
  border-left: 1px solid #ececf2;
}

/* Segmented control */
.pf-segment {
  display: inline-flex;
  background: #f0f0f7;
  border-radius: 10px;
  padding: 4px;
  width: 100%;
  gap: 4px;
}
.pf-segment button {
  flex: 1;
  height: 32px;
  border: 0;
  background: transparent;
  border-radius: 7px;
  font-size: 12.5px;
  font-weight: 600;
  color: #8d8da0;
  cursor: pointer;
  transition: all 0.15s;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
}
.pf-segment button:hover {
  color: #54546a;
}
.pf-segment button.active {
  background: #fff;
  color: #1f1f2c;
  box-shadow: 0 1px 3px rgba(20, 22, 40, 0.08);
}

/* Toggle switch */
.pf-toggle {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  user-select: none;
}
.pf-toggle input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}
.pf-toggle-track {
  width: 40px;
  height: 22px;
  background: #d8d8e4;
  border-radius: 12px;
  position: relative;
  transition: background 0.2s;
  flex-shrink: 0;
}
.pf-toggle-thumb {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 18px;
  height: 18px;
  background: #fff;
  border-radius: 50%;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
  transition: transform 0.2s;
}
.pf-toggle input:checked + .pf-toggle-track {
  background: #6f53d9;
}
.pf-toggle input:checked + .pf-toggle-track .pf-toggle-thumb {
  transform: translateX(18px);
}
.pf-toggle-label {
  font-size: 13px;
  color: #1f1f2c;
}
.pf-toggle-row {
  margin: 10px 0 14px;
}

/* Radio card */
.pf-radio-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
@media (max-width: 600px) {
  .pf-radio-grid {
    grid-template-columns: 1fr;
  }
}
.pf-radio-card {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px;
  border: 1.5px solid #e6e6ec;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.15s;
  background: #fff;
}
.pf-radio-card input {
  position: absolute;
  opacity: 0;
}
.pf-radio-card:hover {
  border-color: #c8c8d8;
}
.pf-radio-card.active {
  border-color: #6f53d9;
  background: #faf8ff;
}
.pf-radio-card-icon {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: #f0f0f7;
  color: #6f53d9;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.pf-radio-card.active .pf-radio-card-icon {
  background: #6f53d9;
  color: #fff;
}
.pf-radio-card-title {
  font-size: 13.5px;
  font-weight: 600;
  color: #1f1f2c;
  margin-bottom: 2px;
}
.pf-radio-card-sub {
  font-size: 11.5px;
  color: #8d8da0;
}

/* Warehouse grid */
.pf-warehouse-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 10px;
}
.pf-warehouse-card {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border: 1.5px solid #e6e6ec;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.15s;
  background: #fff;
  position: relative;
}
.pf-warehouse-card input {
  position: absolute;
  opacity: 0;
}
.pf-warehouse-card:hover {
  border-color: #c8c8d8;
}
.pf-warehouse-card.active {
  border-color: #6f53d9;
  background: #faf8ff;
}
.pf-warehouse-icon {
  width: 36px;
  height: 36px;
  border-radius: 9px;
  background: #f0f0f7;
  color: #6f53d9;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.pf-warehouse-card.active .pf-warehouse-icon {
  background: #6f53d9;
  color: #fff;
}
.pf-warehouse-info {
  flex: 1;
  min-width: 0;
}
.pf-warehouse-name {
  font-size: 13.5px;
  font-weight: 600;
  color: #1f1f2c;
  margin-bottom: 1px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.pf-warehouse-sub {
  font-size: 11.5px;
  color: #8d8da0;
}
.pf-warehouse-check {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: transparent;
  border: 1.5px solid #d8d8e4;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: transparent;
  transition: all 0.15s;
  flex-shrink: 0;
}
.pf-warehouse-card.active .pf-warehouse-check {
  background: #6f53d9;
  border-color: #6f53d9;
  color: #fff;
}

/* Product picker */
.pf-product-picker {
  border: 1px solid #e6e6ec;
  border-radius: 10px;
  background: #fff;
  overflow: hidden;
}
.pf-product-search {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border-bottom: 1px solid #ececf2;
  background: #fafafd;
  color: #8d8da0;
}
.pf-product-search input {
  border: 0;
  background: transparent;
  outline: none;
  flex: 1;
  font-size: 13px;
}
.pf-product-list {
  max-height: 280px;
  overflow-y: auto;
}
.pf-product-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 12px;
  cursor: pointer;
  border-bottom: 1px solid #f4f4f9;
  transition: background 0.1s;
}
.pf-product-item:last-child {
  border-bottom: 0;
}
.pf-product-item:hover {
  background: #fafafd;
}
.pf-product-item.active {
  background: #faf8ff;
}
.pf-product-item input {
  accent-color: #6f53d9;
}
.pf-product-info {
  flex: 1;
  min-width: 0;
}
.pf-product-name {
  font-size: 13px;
  color: #1f1f2c;
  font-weight: 500;
}
.pf-product-code {
  font-size: 11px;
  color: #8d8da0;
  font-family: 'JetBrains Mono', monospace;
}

/* Side preview */
.pf-side-sticky {
  position: sticky;
  top: 80px;
}
.pf-preview-card {
  background: linear-gradient(135deg, #1f1f2c 0%, #2f2f44 100%);
  border-radius: 14px;
  padding: 22px;
  color: #fff;
  margin-bottom: 14px;
  position: relative;
  overflow: hidden;
}
.pf-preview-card::before {
  content: '';
  position: absolute;
  top: -40%;
  right: -10%;
  width: 200px;
  height: 200px;
  background: radial-gradient(circle, rgba(111, 83, 217, 0.4) 0%, transparent 70%);
  pointer-events: none;
}
.pf-preview-tag {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.14em;
  color: rgba(255, 255, 255, 0.55);
  text-transform: uppercase;
  margin-bottom: 14px;
  position: relative;
}
.pf-preview-discount {
  margin-bottom: 14px;
  position: relative;
}
.pf-preview-value-row {
  display: flex;
  align-items: baseline;
  gap: 8px;
}
.pf-preview-value {
  font-size: 38px;
  font-weight: 800;
  line-height: 1;
  font-family: 'JetBrains Mono', monospace;
  color: #fff;
  letter-spacing: -0.02em;
}
.pf-preview-off {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.14em;
  color: rgba(255, 255, 255, 0.5);
}
.pf-preview-body {
  position: relative;
}
.pf-preview-name {
  font-size: 15px;
  font-weight: 600;
  color: #fff;
  margin-bottom: 4px;
}
.pf-preview-desc {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.65);
  margin-bottom: 10px;
  line-height: 1.4;
}
.pf-preview-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 10px;
}
.pf-tag {
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: 0.04em;
  padding: 3px 9px;
  border-radius: 6px;
  background: rgba(255, 255, 255, 0.1);
  color: rgba(255, 255, 255, 0.8);
  text-transform: uppercase;
}
.pf-tag-kind[data-kind='promotion'] {
  background: rgba(46, 213, 115, 0.18);
  color: #6ee7b7;
}
.pf-tag-kind[data-kind='discount'] {
  background: rgba(59, 130, 246, 0.18);
  color: #93c5fd;
}
.pf-tag-code {
  font-family: 'JetBrains Mono', monospace;
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
}
.pf-tag-active {
  background: rgba(34, 197, 94, 0.2);
  color: #6ee7b7;
}
.pf-tag-draft {
  background: rgba(234, 179, 8, 0.18);
  color: #fcd34d;
}

/* Side cards */
.pf-side-card {
  background: #fff;
  border-radius: 12px;
  border: 1px solid #ececf2;
  padding: 16px;
  margin-bottom: 12px;
}
.pf-side-card-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
  color: #54546a;
}
.pf-side-card-head h4 {
  font-size: 13px;
  font-weight: 700;
  color: #1f1f2c;
  margin: 0;
  flex: 1;
}
.pf-side-count {
  font-size: 11px;
  font-weight: 700;
  color: #fff;
  background: #6f53d9;
  padding: 1px 8px;
  border-radius: 10px;
}
.pf-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.pf-chip {
  font-size: 11.5px;
  font-weight: 500;
  color: #54546a;
  background: #f0f0f7;
  padding: 4px 10px;
  border-radius: 8px;
}
.pf-empty,
.pf-empty-mini {
  color: #b8b8c8;
  font-size: 12.5px;
  font-style: italic;
  padding: 14px 0;
  text-align: center;
}
.pf-empty-mini {
  padding: 4px 0;
  text-align: left;
}
.pf-summary-line {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 12.5px;
  padding: 5px 0;
  border-bottom: 1px solid #f4f4f9;
}
.pf-summary-line:last-child {
  border-bottom: 0;
}
.pf-summary-label {
  color: #8d8da0;
}
.pf-summary-value {
  color: #1f1f2c;
  font-weight: 600;
  text-align: right;
}

/* Misc */
.pf-link-btn {
  background: transparent;
  border: 0;
  color: #6f53d9;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  padding: 6px 10px;
  border-radius: 6px;
  transition: background 0.15s;
}
.pf-link-btn:hover {
  background: #faf8ff;
}
.pf-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(255, 255, 255, 0.35);
  border-top-color: #fff;
  border-radius: 50%;
  animation: pf-spin 0.7s linear infinite;
}
.pf-spinner-lg {
  width: 28px;
  height: 28px;
  border-color: rgba(111, 83, 217, 0.25);
  border-top-color: #6f53d9;
}
@keyframes pf-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
