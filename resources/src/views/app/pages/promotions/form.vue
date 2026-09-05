<template>
  <div class="px-next pxpf">
    <px-page-header
      :title="isEdit ? ($t('EditPromotion') || 'Editar promoción') : ($t('NewPromotion') || 'Nueva promoción')"
      :breadcrumbs="[{ label: $t('Sales') }, { label: 'Promociones' }, { label: isEdit ? ($t('Edit') || 'Editar') : ($t('Add') || 'Nueva') }]"
    >
      <template #actions>
        <px-button variant="ghost" :disabled="saving" @click="goBack">{{ $t('Cancel') || 'Cancelar' }}</px-button>
        <px-button variant="secondary" icon="archive" :disabled="!canSave || saving" @click="submit(false)">{{ $t('SaveAsDraft') || 'Guardar borrador' }}</px-button>
        <px-button variant="primary" icon="check" :loading="saving" :disabled="!canSave || saving" @click="submit(true)">
          {{ isEdit ? ($t('SaveChanges') || 'Guardar cambios') : ($t('SaveAndActivate') || 'Guardar y activar') }}
        </px-button>
      </template>
    </px-page-header>

    <div v-if="isLoading" class="pxpf__pad">
      <px-skeleton variant="lines" :rows="10" />
    </div>

    <div v-else class="pxpf__body">
      <main class="pxpf__main">
        <!-- 1 · Basics -->
        <px-card>
          <template #header>
            <div class="pxpf__sechead"><span class="pxpf__step">1</span>
              <div><h3 class="pxpf__sectitle">{{ $t('Basics') || 'Datos básicos' }}</h3>
              <p class="pxpf__sechint">{{ $t('BasicsHint') || 'Nombra la promoción y elige su tipo.' }}</p></div>
            </div>
          </template>

          <px-field :label="$t('Name') || 'Nombre'" required :error="fieldErrors.name">
            <template #default="{ id }">
              <px-input :id="id" v-model="form.name" :placeholder="$t('PromoNamePh') || 'Ej. Especial fin de semana'" />
            </template>
          </px-field>

          <div class="pxpf__grid2 pxpf__mt">
            <px-field :label="$t('Type') || 'Tipo'">
              <template #default>
                <px-tabs variant="pill" :tabs="kindTabs" :value="form.kind" @input="form.kind = $event" />
                <p class="pxpf__hint">{{ form.kind === 'discount' ? ($t('DiscountKindHint') || 'Reducción automática sin condiciones.') : ($t('PromotionKindHint') || 'Oferta condicional; normalmente con código.') }}</p>
              </template>
            </px-field>
            <px-field :label="$t('Code') || 'Código'" :optional="true">
              <template #default="{ id }">
                <px-input :id="id" v-model="form.code" class="pxn-mono"
                  :placeholder="$t('CodePh') || 'SUMMER15'"
                  @input="form.code = (form.code || '').toUpperCase().replace(/\s+/g, '')" />
                <p class="pxpf__hint">{{ $t('CodeHint') || 'Si se define, el cliente debe introducirlo al pagar.' }}</p>
              </template>
            </px-field>
          </div>

          <px-field :label="$t('Description') || 'Descripción'" class="pxpf__mt">
            <template #default="{ id }">
              <px-textarea :id="id" v-model="form.description" :rows="2" :placeholder="$t('DescriptionPh') || 'Nota breve para el personal o el recibo.'" />
            </template>
          </px-field>
        </px-card>

        <!-- 2 · Discount value -->
        <px-card>
          <template #header>
            <div class="pxpf__sechead"><span class="pxpf__step">2</span>
              <div><h3 class="pxpf__sectitle">{{ $t('DiscountValue') || 'Valor del descuento' }}</h3>
              <p class="pxpf__sechint">{{ $t('DiscountValueHint') || 'Cuánto se descuenta del carrito.' }}</p></div>
            </div>
          </template>
          <div class="pxpf__grid2">
            <px-field :label="$t('DiscountType') || 'Tipo de descuento'">
              <template #default>
                <px-tabs variant="pill" :tabs="discountTypeTabs" :value="form.discount_type" @input="form.discount_type = $event" />
              </template>
            </px-field>
            <px-field :label="$t('Value') || 'Valor'" required :error="fieldErrors.discount_value">
              <template #default="{ id }">
                <px-input
                  :id="id"
                  type="number"
                  step="0.01"
                  min="0"
                  v-model.number="form.discount_value"
                  :prefix="form.discount_type === 'fixed' ? (currencySymbol || '$') : null"
                  :suffix="form.discount_type === 'percentage' ? '%' : null"
                  :placeholder="form.discount_type === 'percentage' ? '15' : '10.00'"
                />
              </template>
            </px-field>
          </div>
        </px-card>

        <!-- 3 · Validity -->
        <px-card>
          <template #header>
            <div class="pxpf__sechead"><span class="pxpf__step">3</span>
              <div><h3 class="pxpf__sectitle">{{ $t('Validity') || 'Vigencia' }}</h3>
              <p class="pxpf__sechint">{{ $t('ValidityHint') || 'Cuándo se aplica la promoción.' }}</p></div>
            </div>
          </template>
          <div class="pxpf__grid2">
            <px-field :label="$t('StartsAt') || 'Inicia'" :optional="true">
              <template #default="{ id }"><px-input :id="id" type="datetime-local" v-model="form.starts_at" /></template>
            </px-field>
            <px-field :label="$t('EndsAt') || 'Finaliza'" :optional="true">
              <template #default="{ id }"><px-input :id="id" type="datetime-local" v-model="form.ends_at" /></template>
            </px-field>
          </div>
          <label class="pxpf__switch pxpf__mt">
            <px-check type="switch" v-model="restrictHours" />
            {{ $t('RestrictHours') || 'Restringir a horas específicas del día' }}
          </label>
          <div class="pxpf__grid2 pxpf__mt" v-if="restrictHours">
            <px-field :label="$t('FromTime') || 'Desde'">
              <template #default="{ id }"><px-input :id="id" type="time" v-model="form.time_of_day_start" step="1" /></template>
            </px-field>
            <px-field :label="$t('ToTime') || 'Hasta'">
              <template #default="{ id }">
                <px-input :id="id" type="time" v-model="form.time_of_day_end" step="1" />
                <p class="pxpf__hint">{{ $t('HoursCrossMidnightHint') || 'Los horarios pueden cruzar la medianoche (p. ej. 22:00 → 02:00).' }}</p>
              </template>
            </px-field>
          </div>
        </px-card>

        <!-- 4 · Conditions -->
        <px-card>
          <template #header>
            <div class="pxpf__sechead"><span class="pxpf__step">4</span>
              <div><h3 class="pxpf__sectitle">{{ $t('Conditions') || 'Condiciones' }}</h3>
              <p class="pxpf__sechint">{{ $t('ConditionsHint') || 'Requisitos mínimos para que aplique la promoción.' }}</p></div>
            </div>
          </template>
          <div class="pxpf__grid2">
            <px-field :label="$t('MinCartTotal') || 'Total mínimo del carrito'">
              <template #default="{ id }">
                <px-input :id="id" type="number" step="0.01" min="0" v-model.number="form.min_cart_total" :prefix="currencySymbol || '$'" placeholder="0.00" />
              </template>
            </px-field>
            <px-field :label="$t('MinItemCount') || 'Cantidad mínima de artículos'">
              <template #default="{ id }">
                <px-input :id="id" type="number" min="0" v-model.number="form.min_item_count" placeholder="0" />
              </template>
            </px-field>
          </div>

          <px-field :label="$t('ProductScope') || 'Alcance de productos'" class="pxpf__mt">
            <template #default>
              <div class="pxpf__radiogrid">
                <label class="pxpf__radiocard" :class="{ 'is-active': form.product_scope === 'all' }">
                  <input type="radio" v-model="form.product_scope" value="all" />
                  <lucide-icon name="package" :size="18" />
                  <div><div class="pxpf__radiocard-t">{{ $t('AllProducts') || 'Todos los productos' }}</div>
                  <div class="pxpf__radiocard-s">{{ $t('AllProductsHint') || 'Aplica a cada artículo del carrito.' }}</div></div>
                </label>
                <label class="pxpf__radiocard" :class="{ 'is-active': form.product_scope === 'specific' }">
                  <input type="radio" v-model="form.product_scope" value="specific" />
                  <lucide-icon name="package-search" :size="18" />
                  <div><div class="pxpf__radiocard-t">{{ $t('SpecificProducts') || 'Productos específicos' }}</div>
                  <div class="pxpf__radiocard-s">{{ $t('SpecificProductsHint') || 'Solo se activa si uno de estos está en el carrito.' }}</div></div>
                </label>
              </div>
            </template>
          </px-field>

          <px-field v-if="form.product_scope === 'specific'" class="pxpf__mt">
            <template #label>
              {{ $t('Products') || 'Productos' }}
              <span v-if="form.product_ids.length" class="pxpf__countbadge">{{ form.product_ids.length }}</span>
            </template>
            <template #default>
              <div class="pxpf__picker">
                <div class="pxpf__picker-search">
                  <lucide-icon name="search" :size="15" />
                  <input v-model="productSearch" :placeholder="$t('SearchProducts') || 'Buscar productos…'" />
                </div>
                <div class="pxpf__picker-list pxn-scroll">
                  <label
                    v-for="p in filteredProducts.slice(0, 100)"
                    :key="p.id"
                    class="pxpf__picker-item"
                    :class="{ 'is-active': form.product_ids.includes(p.id) }"
                  >
                    <input type="checkbox" :value="p.id" v-model="form.product_ids" />
                    <div><div class="pxpf__picker-name">{{ p.name }}</div>
                    <div class="pxpf__picker-code pxn-mono">{{ p.code }}</div></div>
                  </label>
                  <div v-if="filteredProducts.length === 0" class="pxpf__pickerempty">{{ $t('NoProductsFound') || 'Ningún producto coincide con la búsqueda.' }}</div>
                  <div v-else-if="filteredProducts.length > 100" class="pxpf__pickerempty">{{ $t('TooManyResults') || 'Mostrando los primeros 100 — refina la búsqueda.' }}</div>
                </div>
              </div>
            </template>
          </px-field>
        </px-card>

        <!-- 5 · Warehouses -->
        <px-card>
          <template #header>
            <div class="pxpf__sechead"><span class="pxpf__step">5</span>
              <div><h3 class="pxpf__sectitle">{{ $t('Warehouses') || 'Almacenes' }}</h3>
              <p class="pxpf__sechint">{{ $t('WarehousesHint') || 'Dónde se aplica esta promoción.' }}</p></div>
            </div>
          </template>
          <template #actions>
            <px-button v-if="warehouses.length" size="sm" variant="ghost" @click="toggleAllWarehouses">
              {{ allWarehousesSelected ? ($t('Deselect_all') || 'Deseleccionar todo') : ($t('Select_all') || 'Seleccionar todo') }}
            </px-button>
          </template>
          <div class="pxpf__whgrid" v-if="warehouses.length">
            <label
              v-for="w in warehouses"
              :key="w.id"
              class="pxpf__whcard"
              :class="{ 'is-active': form.warehouse_ids.includes(w.id) }"
            >
              <input type="checkbox" :value="w.id" v-model="form.warehouse_ids" />
              <lucide-icon name="store" :size="16" />
              <div><div class="pxpf__whcard-n">{{ w.name }}</div>
              <div class="pxpf__whcard-s">{{ [w.city, w.country].filter(Boolean).join(', ') || '—' }}</div></div>
              <lucide-icon name="check" :size="15" class="pxpf__whcard-chk" />
            </label>
          </div>
          <div v-else class="pxpf__pickerempty">{{ $t('NoWarehousesYet') || 'Aún no hay almacenes configurados.' }}</div>
        </px-card>

        <!-- 6 · Stacking & limits -->
        <px-card>
          <template #header>
            <div class="pxpf__sechead"><span class="pxpf__step">6</span>
              <div><h3 class="pxpf__sectitle">{{ $t('StackingAndLimits') || 'Combinación y límites' }}</h3>
              <p class="pxpf__sechint">{{ $t('StackingHint') || 'Cómo compite con otras promociones y con qué frecuencia se usa.' }}</p></div>
            </div>
          </template>
          <div class="pxpf__grid2">
            <px-field :label="$t('Priority') || 'Prioridad'">
              <template #default="{ id }">
                <px-input :id="id" type="number" v-model.number="form.priority" placeholder="0" />
                <p class="pxpf__hint">{{ $t('HigherWins') || 'La mayor prioridad gana cuando se solapan promociones.' }}</p>
              </template>
            </px-field>
            <px-field :label="$t('Stackable') || 'Combinable'">
              <template #default>
                <label class="pxpf__switch">
                  <px-check type="switch" v-model="form.stackable" />
                  {{ form.stackable ? ($t('YesStacksWithOthers') || 'Sí — se combina con otras promociones combinables') : ($t('NoExclusive') || 'No — exclusiva (solo gana esta)') }}
                </label>
              </template>
            </px-field>
          </div>
          <div class="pxpf__grid2 pxpf__mt">
            <px-field :label="$t('UsageLimitTotal') || 'Límite total de usos'">
              <template #default="{ id }">
                <px-input :id="id" type="number" min="0" v-model.number="form.usage_limit_total" :placeholder="$t('Unlimited') || 'Ilimitado'" />
                <p class="pxpf__hint">{{ $t('LeaveBlankUnlimited') || 'Déjalo en blanco para ilimitado.' }}</p>
              </template>
            </px-field>
            <px-field :label="$t('UsageLimitPerCustomer') || 'Límite por cliente'">
              <template #default="{ id }">
                <px-input :id="id" type="number" min="0" v-model.number="form.usage_limit_per_customer" :placeholder="$t('Unlimited') || 'Ilimitado'" />
                <p class="pxpf__hint">{{ $t('LeaveBlankUnlimited') || 'Déjalo en blanco para ilimitado.' }}</p>
              </template>
            </px-field>
          </div>
        </px-card>
      </main>

      <aside class="pxpf__side">
        <div class="pxpf__sticky">
          <px-card class="pxpf__preview">
            <div class="pxpf__preview-tag">{{ $t('Preview') || 'Vista previa' }}</div>
            <div class="pxpf__preview-val">
              <span class="pxpf__preview-num">{{ previewValue }}</span>
              <span class="pxpf__preview-off">OFF</span>
            </div>
            <div class="pxpf__preview-name">{{ form.name || ($t('UntitledPromotion') || 'Promoción sin título') }}</div>
            <div class="pxpf__preview-desc" v-if="form.description">{{ form.description }}</div>
            <div class="pxpf__preview-meta">
              <px-badge :tone="form.kind === 'discount' ? 'info' : 'success'">{{ form.kind === 'discount' ? ($t('Discount') || 'Descuento') : ($t('Promotion') || 'Promoción') }}</px-badge>
              <px-badge v-if="form.code" tone="neutral">{{ form.code }}</px-badge>
              <px-badge :tone="form.is_active ? 'success' : 'neutral'">{{ form.is_active ? ($t('Active') || 'Activa') : ($t('Draft') || 'Borrador') }}</px-badge>
            </div>
          </px-card>

          <px-card :title="$t('AppliesAt') || 'Se aplica en'">
            <template #actions><span class="pxpf__sidecount">{{ selectedWarehouseObjects.length }}</span></template>
            <div class="pxpf__chips" v-if="selectedWarehouseObjects.length">
              <px-badge v-for="w in selectedWarehouseObjects" :key="w.id" tone="neutral">{{ w.name }}</px-badge>
            </div>
            <p v-else class="pxpf__hint">{{ $t('PickAtLeastOne') || 'Elige al menos un almacén.' }}</p>
          </px-card>

          <px-card :title="$t('Validity') || 'Vigencia'">
            <div class="pxpf__sumline"><span>{{ $t('Window') || 'Ventana' }}</span><span>{{ validityWindowLabel }}</span></div>
            <div class="pxpf__sumline" v-if="restrictHours && (form.time_of_day_start || form.time_of_day_end)">
              <span>{{ $t('Hours') || 'Horas' }}</span><span>{{ form.time_of_day_start || '00:00:00' }} → {{ form.time_of_day_end || '23:59:59' }}</span>
            </div>
          </px-card>

          <px-card :title="$t('Conditions') || 'Condiciones'">
            <div class="pxpf__sumline"><span>{{ $t('Cart') || 'Carrito' }}</span><span>{{ form.min_cart_total ? '≥ ' + (currencySymbol || '$') + ' ' + form.min_cart_total : ($t('Any') || 'Cualquiera') }}</span></div>
            <div class="pxpf__sumline"><span>{{ $t('Items') || 'Artículos' }}</span><span>{{ form.min_item_count ? '≥ ' + form.min_item_count : ($t('Any') || 'Cualquiera') }}</span></div>
            <div class="pxpf__sumline"><span>{{ $t('Scope') || 'Alcance' }}</span><span>{{ form.product_scope === 'specific' ? (($t('NProducts') || '{n} productos').replace('{n}', form.product_ids.length)) : ($t('AllProducts') || 'Todos los productos') }}</span></div>
          </px-card>
        </div>
      </aside>
    </div>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxTabs from "@/components/px-next/PxTabs.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";

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
  metaInfo: { title: "Promoción" },
  components: {
    PxPageHeader, PxCard, PxField, PxInput, PxTextarea, PxTabs, PxCheck, PxButton, PxBadge
  },

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
    kindTabs() {
      return [
        { value: "discount", label: this.$t("Discount") || "Descuento", icon: "percent" },
        { value: "promotion", label: this.$t("Promotion") || "Promoción", icon: "gift" }
      ];
    },
    discountTypeTabs() {
      return [
        { value: "percentage", label: (this.$t("Percentage") || "Porcentaje"), icon: "percent" },
        { value: "fixed", label: (this.currencySymbol || "$") + " " + (this.$t("Fixed") || "Fijo") }
      ];
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
      if (!f && !t) return this.$t("Always") || "Siempre";
      if (f && !t) return (this.$t("From") || "Desde") + " " + f;
      if (!f && t) return (this.$t("Until") || "Hasta") + " " + t;
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
        this.fieldErrors.name = this.$t("FieldRequired") || "Requerido";
      }
      if (Number(this.form.discount_value) < 0) {
        this.fieldErrors.discount_value = this.$t("MustBePositive") || "Debe ser ≥ 0";
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

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxpf { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxpf { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxpf__pad { padding: var(--pxn-space-6) 0; }

.pxpf__body { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: var(--pxn-space-6); margin-top: var(--pxn-space-6); }
@media (max-width: 1080px) { .pxpf__body { grid-template-columns: minmax(0, 1fr); } }
.pxpf__main { min-width: 0; display: flex; flex-direction: column; gap: var(--pxn-space-6); }

.pxpf__sechead { display: flex; align-items: flex-start; gap: var(--pxn-space-4); }
.pxpf__step {
  flex: none; display: grid; place-items: center; width: 26px; height: 26px;
  border-radius: 50%; background: var(--pxn-primary-soft); color: var(--pxn-primary-ink);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-bold);
}
.pxpf__sectitle { font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxpf__sechint { margin: 2px 0 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }

.pxpf__grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: var(--pxn-space-5) var(--pxn-space-6); }
@media (max-width: 640px) { .pxpf__grid2 { grid-template-columns: 1fr; } }
.pxpf__mt { margin-top: var(--pxn-space-5); }
.pxpf__hint { margin: var(--pxn-space-2) 0 0; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxpf__switch { display: flex; align-items: center; gap: var(--pxn-space-3); font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); cursor: pointer; }
.pxpf__countbadge {
  display: inline-block; margin-left: var(--pxn-space-2); padding: 0 var(--pxn-space-2);
  border-radius: var(--pxn-radius-pill); background: var(--pxn-primary-soft); color: var(--pxn-primary-ink);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
}

.pxpf__radiogrid { display: grid; grid-template-columns: 1fr 1fr; gap: var(--pxn-space-4); }
@media (max-width: 640px) { .pxpf__radiogrid { grid-template-columns: 1fr; } }
.pxpf__radiocard {
  display: flex; align-items: flex-start; gap: var(--pxn-space-3);
  padding: var(--pxn-space-4); border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-md); cursor: pointer;
  transition: border-color var(--pxn-dur-1) var(--pxn-ease), background-color var(--pxn-dur-1) var(--pxn-ease);
}
.pxpf__radiocard input { margin-top: 2px; }
.pxpf__radiocard.is-active { border-color: var(--pxn-primary); background: var(--pxn-primary-softer); }
.pxpf__radiocard-t { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); }
.pxpf__radiocard-s { margin-top: 2px; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }

.pxpf__picker { border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md); overflow: hidden; }
.pxpf__picker-search { display: flex; align-items: center; gap: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink-3); }
.pxpf__picker-search input { flex: 1; border: 0; outline: none; background: transparent; font: inherit; font-size: var(--pxn-fs-sm); color: var(--pxn-ink); }
.pxpf__picker-list { max-height: 280px; overflow-y: auto; }
.pxpf__picker-item { display: flex; align-items: center; gap: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); cursor: pointer; }
.pxpf__picker-item:last-child { border-bottom: 0; }
.pxpf__picker-item.is-active { background: var(--pxn-primary-softer); }
.pxpf__picker-name { font-size: var(--pxn-fs-sm); color: var(--pxn-ink); }
.pxpf__picker-code { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxpf__pickerempty { padding: var(--pxn-space-6); text-align: center; color: var(--pxn-ink-3); font-size: var(--pxn-fs-sm); }

.pxpf__whgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: var(--pxn-space-4); }
.pxpf__whcard {
  display: flex; align-items: center; gap: var(--pxn-space-3); position: relative;
  padding: var(--pxn-space-4); border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md); cursor: pointer;
  transition: border-color var(--pxn-dur-1) var(--pxn-ease), background-color var(--pxn-dur-1) var(--pxn-ease);
}
.pxpf__whcard input { position: absolute; opacity: 0; pointer-events: none; }
.pxpf__whcard.is-active { border-color: var(--pxn-primary); background: var(--pxn-primary-softer); }
.pxpf__whcard-n { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); }
.pxpf__whcard-s { margin-top: 2px; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxpf__whcard-chk { margin-left: auto; color: var(--pxn-primary); opacity: 0; transition: opacity var(--pxn-dur-1) var(--pxn-ease); }
.pxpf__whcard.is-active .pxpf__whcard-chk { opacity: 1; }

.pxpf__side { min-width: 0; }
@media (max-width: 1080px) { .pxpf__side { order: -1; } }
.pxpf__sticky { position: sticky; top: var(--pxn-space-6); display: flex; flex-direction: column; gap: var(--pxn-space-5); }

.pxpf__preview ::v-deep .pxn-card__body { display: flex; flex-direction: column; gap: var(--pxn-space-3); }
.pxpf__preview-tag { font-size: var(--pxn-fs-xs); text-transform: uppercase; letter-spacing: 0.06em; color: var(--pxn-ink-3); }
.pxpf__preview-val { display: flex; align-items: baseline; gap: var(--pxn-space-3); }
.pxpf__preview-num { font-size: var(--pxn-fs-display); font-weight: var(--pxn-fw-bold); color: var(--pxn-primary); }
.pxpf__preview-off { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink-3); }
.pxpf__preview-name { font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxpf__preview-desc { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxpf__preview-meta { display: flex; flex-wrap: wrap; gap: var(--pxn-space-2); margin-top: var(--pxn-space-2); }

.pxpf__sidecount {
  display: inline-grid; place-items: center; min-width: 22px; height: 22px; padding: 0 6px;
  border-radius: var(--pxn-radius-pill); background: var(--pxn-surface-3); color: var(--pxn-ink-2);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
}
.pxpf__chips { display: flex; flex-wrap: wrap; gap: var(--pxn-space-2); }
.pxpf__sumline { display: flex; justify-content: space-between; gap: var(--pxn-space-4); padding: var(--pxn-space-2) 0; font-size: var(--pxn-fs-sm); }
.pxpf__sumline span:first-child { color: var(--pxn-ink-3); }
.pxpf__sumline span:last-child { color: var(--pxn-ink); text-align: right; }
</style>
