<template>
  <div class="px-next pxpurf">
    <px-page-header :title="$t('CreatePurchaseReturn')" :breadcrumbs="[{ label: $t('Purchases') }, { label: $t('ListReturns') }, { label: $t('CreatePurchaseReturn') }]">
      <template #actions>
        <px-button variant="ghost" icon="arrow-left" @click="$router.push({ name: 'index_purchase_return' })">{{ $t('Cancel') }}</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" :disabled="SubmitProcessing" @click="Submit_Return_Purchase">{{ $t('submit') }}</px-button>
      </template>
    </px-page-header>

    <div v-if="isLoading" class="pxpurf__pad">
      <px-skeleton variant="lines" :rows="10" />
    </div>

    <validation-observer v-else ref="create_Return" tag="div">
      <px-card class="pxpurf__card">
        <div class="pxpurf__grid3">
          <validation-provider ref="dateProvider" name="date" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('date')" required :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" type="date" v-model="purchase_return.date" @input="v.validate" /></template>
            </px-field>
          </validation-provider>

          <px-field :label="$t('Purchase')">
            <template #default="{ id }"><px-input :id="id" v-model="purchase_return.purchase_ref" disabled /></template>
          </px-field>

          <validation-provider ref="statutProvider" name="Status" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Status')" required :error="v.errors[0]">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="purchase_return.statut" :reduce="o => o.value"
                  :placeholder="$t('Choose_Status')" @input="v.validate"
                  :options="[{ label: 'completed', value: 'completed' }, { label: 'pending', value: 'pending' }]" />
              </template>
            </px-field>
          </validation-provider>

          <validation-provider v-if="location_meta.requires" ref="locProvider" name="inventory_location" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Inventory_Location')" required :error="v.errors[0]">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="purchase_return.inventory_location_id" :reduce="o => o.value"
                  :placeholder="$t('Choose_Inventory_Location')"
                  @input="val => { Selected_Inventory_Location(val); v.validate(val); }"
                  :options="inventory_locations.map(l => ({ label: l.name + ' · ' + l.type, value: l.id }))" />
              </template>
            </px-field>
          </validation-provider>
        </div>

        <px-alert v-if="location_meta.blocked" tone="danger" class="pxpurf__gap">
          {{ $t('Inventory_Location_Warehouse_Not_Ready') || 'Este almacén usa inventario por ubicación pero aún no está reconciliado.' }}
        </px-alert>

        <div class="pxpurf__lines pxpurf__gap">
          <h5 class="pxpurf__lineshead">{{ $t('list_product_returns') }} *</h5>
          <px-alert tone="danger" bare class="pxpurf__refund">{{ $t('products_refunded_alert') }}</px-alert>

          <div class="pxpurf-tbl__wrap pxn-scroll">
            <table class="pxpurf-tbl">
              <thead>
                <tr>
                  <th style="width:44px">#</th>
                  <th>{{ $t('ProductName') }}</th>
                  <th class="is-right">{{ $t('Net_Unit_Cost') }}</th>
                  <th class="is-right">{{ $t('qty_purchased') }}</th>
                  <th class="is-right">{{ $t('Current_stock') }}</th>
                  <th class="is-center">{{ $t('Qty_return') }}</th>
                  <th class="is-right">{{ $t('Discount') }}</th>
                  <th class="is-right">{{ $t('Tax') }}</th>
                  <th class="is-right">{{ $t('SubTotal') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="details.length <= 0"><td colspan="9" class="pxpurf__empty">{{ $t('NodataAvailable') }}</td></tr>
                <template v-for="detail in details">
                  <tr :key="'d-' + detail.detail_id">
                    <td class="pxn-num">{{ detail.detail_id }}</td>
                    <td>
                      <span class="pxn-mono">{{ detail.code }}</span><br />
                      <px-badge tone="success">{{ detail.name }}</px-badge>
                      <div v-if="detail.is_batch_tracked && purchase_return.statut === 'completed'" class="pxpurf__badgeline">
                        <px-badge tone="info" icon="package"
                          :title="$t('Auto_FEFO_Hint') || 'Oldest-expiring batches will be auto-allocated (FEFO) when this return is completed.'">
                          {{ $t('Batches') || 'Batches' }} · FEFO
                        </px-badge>
                      </div>
                      <div v-if="detail.is_batch_tracked && purchase_return.statut !== 'completed'" class="pxpurf__badgeline">
                        <px-badge tone="neutral" icon="package">
                          {{ $t('Batches_Assigned_On_Completion') || 'Los lotes se asignarán cuando la devolución se complete.' }}
                        </px-badge>
                      </div>
                    </td>
                    <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber(detail.Net_cost, priceDecimals) }}</td>
                    <td class="is-right"><px-badge tone="warning">{{ detail.purchase_quantity }} {{ detail.unitPurchase }}</px-badge></td>
                    <td class="is-right"><px-badge tone="warning">{{ detail.stock }} {{ detail.unitPurchase }}</px-badge></td>
                    <td class="is-center">
                      <span class="pxpurf__stepper">
                        <button type="button" class="pxpurf__step" @click="decrement(detail, detail.detail_id)">−</button>
                        <input class="pxpurf__stepinput" @keyup="Verified_Qty(detail, detail.detail_id)"
                          :min="0.00" v-model.number="detail.quantity" />
                        <button type="button" class="pxpurf__step" @click="increment(detail, detail.detail_id)">+</button>
                      </span>
                    </td>
                    <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber(detail.DiscountNet * detail.quantity, priceDecimals) }}</td>
                    <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber(detail.taxe * detail.quantity, priceDecimals) }}</td>
                    <td class="is-right pxn-num">{{ currentUser.currency }} {{ detail.subtotal.toFixed(priceDecimals) }}</td>
                  </tr>

                  <tr v-if="detail.is_imei && detail.is_batch_tracked" :key="'sb-' + detail.detail_id">
                    <td colspan="9" class="pxpurf__inlinealert">
                      <px-alert tone="danger" bare>
                        {{ $t('Serial_Batch_Incompatible') || 'Este producto está configurado con lote Y serie/IMEI a la vez. La combinación no es compatible: corrige la configuración del producto antes de registrar la devolución.' }}
                      </px-alert>
                    </td>
                  </tr>

                  <tr v-if="detail.is_imei && !detail.is_batch_tracked" :key="'s-' + detail.detail_id">
                    <td colspan="9" class="pxpurf__inlinealert">
                      <div class="pxpurf__serialbox">
                        <serial-numbers-field
                          mode="select"
                          v-model="detail.serial_numbers"
                          :required-count="serialRequiredCount(detail)"
                          fetch-url="serial_numbers/for_purchase"
                          :fetch-params="{ purchase_id: purchase_return.purchase_id || null, product_id: detail.product_id, product_variant_id: detail.product_variant_id || null, inventory_location_id: location_meta.requires ? purchase_return.inventory_location_id : null }"
                        />
                        <div v-if="purchase_return.statut !== 'completed'" class="pxpurf__hint">
                          {{ $t('Serials_Assigned_On_Return_Complete') || 'Los seriales se asignarán cuando la devolución se complete.' }}
                        </div>
                      </div>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>

        <div class="pxpurf__grid3 pxpurf__gap">
          <validation-provider name="Order Tax" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('OrderTax')" :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model.number="purchase_return.tax_rate" suffix="%" @input="v.validate($event); keyup_OrderTax()" /></template>
            </px-field>
          </validation-provider>
          <validation-provider name="Discount" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('Discount')" :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model.number="purchase_return.discount" :suffix="currentUser.currency" @input="v.validate($event); keyup_Discount()" /></template>
            </px-field>
          </validation-provider>
          <validation-provider name="Shipping" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('Shipping')" :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model.number="purchase_return.shipping" :suffix="currentUser.currency" @input="v.validate($event); keyup_Shipping()" /></template>
            </px-field>
          </validation-provider>
        </div>

        <div class="pxpurf__totals pxpurf__gap">
          <table class="pxpurf-totbl">
            <tbody>
              <tr><td>{{ $t('OrderTax') }}</td><td class="is-right pxn-num">{{ currentUser.currency }} {{ purchase_return.TaxNet.toFixed(priceDecimals) }} ({{ formatNumber(purchase_return.tax_rate, 2) }} %)</td></tr>
              <tr><td>{{ $t('Discount') }}</td><td class="is-right pxn-num">{{ currentUser.currency }} {{ purchase_return.discount.toFixed(priceDecimals) }}</td></tr>
              <tr><td>{{ $t('Shipping') }}</td><td class="is-right pxn-num">{{ currentUser.currency }} {{ purchase_return.shipping.toFixed(priceDecimals) }}</td></tr>
              <tr class="pxpurf__totrow"><td>{{ $t('Total') }}</td><td class="is-right pxn-num">{{ currentUser.currency }} {{ GrandTotal.toFixed(priceDecimals) }}</td></tr>
            </tbody>
          </table>
        </div>

        <px-field :label="$t('Please_provide_any_details')" class="pxpurf__gap">
          <template #default="{ id }"><px-textarea :id="id" v-model="purchase_return.notes" :rows="4" :placeholder="$t('Afewwords')" /></template>
        </px-field>

        <div class="pxpurf__actionbar">
          <px-button variant="secondary" type="button" @click="$router.push({ name: 'index_purchase_return' })">{{ $t('Cancel') }}</px-button>
          <px-button variant="primary" icon="check" :loading="SubmitProcessing" :disabled="SubmitProcessing" @click="Submit_Return_Purchase">{{ $t('submit') }}</px-button>
        </div>
      </px-card>
    </validation-observer>
  </div>
</template>

<script>
import { mapActions, mapGetters } from "vuex";
import { getPriceDecimals } from "../../../../utils/priceFormat";
import { resolveAutoInventoryLocation } from "../../../../utils/inventoryLocationAutoSelect";
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "Create Return Purchase"
  },
  components: {
    PxPageHeader, PxCard, PxField, PxInput, PxTextarea, PxButton, PxBadge, PxAlert, "vs-px": VsPx
  },
  data() {
    return {
      isLoading: true,
      SubmitProcessing:false,
      details: [],
      detail: {},
      purchases: [],
      purchase_return: {
        id: "",
        date: new Date().toISOString().slice(0, 10),
        statut: "completed",
        notes: "",
        supplier_id: "",
        warehouse_id: "",
        inventory_location_id: null,
        purchase_id: "",
        tax_rate: 0,
        TaxNet: 0,
        shipping: 0,
        discount: 0
      },
      // MS3 — inventory-location select (only for location_primary warehouses).
      inventory_locations: [],
      location_meta: { requires: false, blocked: false, mode: null, status: null },
      total: 0,
      GrandTotal: 0,
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),
    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    // A product configured with BOTH batch and serial/IMEI tracking — the
    // backend rejects the combination (422); block the form the same way.
    serialBatchConflictDetail() {
      if (!Array.isArray(this.details)) return null;
      return this.details.find(d => d && d.is_imei && d.is_batch_tracked) || null;
    }
  },

  methods: {

    // vee-validate 3.x — seed prefilled/fetched values so an untouched-but-valid
    // field does not block submit (scopedSlot detection regression).
    syncValidators() {
      this.$nextTick(() => {
        if (this.$refs.dateProvider) this.$refs.dateProvider.syncValue(this.purchase_return.date);
        if (this.$refs.statutProvider) this.$refs.statutProvider.syncValue(this.purchase_return.statut);
        if (this.$refs.locProvider) this.$refs.locProvider.syncValue(this.purchase_return.inventory_location_id);
      });
    },

    // MS6-B2 — base-unit quantity for a line, matching the backend unit maths
    // ('*' multiplies by operator_value, '/' divides). The return form never
    // lets the user change the purchase unit, so these come straight off the
    // detail payload (no local units list to fall back on).
    detailBaseQty(detail) {
      const q = Number(detail && detail.quantity) || 0;
      const op = detail && detail.purchase_unit_operator;
      const val = Number(detail && detail.purchase_unit_operator_value);
      if (!op || !isFinite(val) || val <= 0) return q;
      return op === '/' ? q / val : q * val;
    },
    // Serials required for a line: the PHYSICAL base quantity for a
    // location-native (location_primary) warehouse — count(serials) ==
    // quantity_base — the entered document quantity for a legacy one. Kept
    // mode-dependent so legacy tenants keep their exact current behaviour.
    serialRequiredCount(detail) {
      return this.location_meta.requires
        ? Math.round(this.detailBaseQty(detail))
        : Math.round(Number(detail && detail.quantity) || 0);
    },
    //--- Serial / IMEI: a serialized line must have exactly quantity_base-many serials.
    serialCountMismatch(detail) {
      if (!detail || !detail.is_imei) return false;
      const count = Array.isArray(detail.serial_numbers) ? detail.serial_numbers.length : 0;
      return count !== this.serialRequiredCount(detail);
    },

    //--- Submit Validate Create Return Purchase
    Submit_Return_Purchase() {
      // A product configured with BOTH batch and serial/IMEI tracking is not
      // supported by the backend (422) — block before submit.
      if (this.serialBatchConflictDetail) {
        this.makeToast(
          "danger",
          `${this.$t('Serial_Batch_Incompatible') || 'Lote y serie/IMEI no son compatibles'} (${this.serialBatchConflictDetail.name})`,
          this.$t("Failed")
        );
        return;
      }
      // Serials are removed from inventory only when the return is completed.
      if (this.purchase_return.statut === 'completed') {
        const bad = this.details.find(d => this.serialCountMismatch(d));
        if (bad) {
          this.makeToast("danger", `${this.$t('Serials_Count_Mismatch')} (${bad.name})`, this.$t("Failed"));
          return;
        }
      }
      this.$refs.create_Return.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Create_Return_Purchase();
        }
      });
    },
   
    //---Validate State Fields
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    // //---------------------- Event Select purchase ------------------------------\\
    // Selected_Purchase_Ref(value) {
    //   this.Get_Products_By_purchase(value);
    // },

    //  //------------------------------------ Get Products By purchase -------------------------\\

    // Get_Products_By_purchase(id) {
    //   // Start the progress bar.
    //     NProgress.start();
    //     NProgress.set(0.1);
    //   axios
    //     .get("get_Products_by_purchase/" + id)
    //      .then(response => {
    //         this.details = response.data.details;
    //         this.purchase_return = response.data.purchase_return;
    //         this.purchase_return.date = new Date().toISOString().slice(0, 10);
    //         this.Calcul_Total();
    //          NProgress.done();

    //         })
    //       .catch(error => {
    //       });
    // },

    //-----------------------------------Verified QTY ------------------------------\\
    Verified_Qty(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
           if (isNaN(detail.quantity)) {
            this.details[i].quantity = 1;
          }

          if (detail.quantity > detail.purchase_quantity) {
            this.makeToast("warning", this.$t("qty_return_is_greater_than_qty_purchased"), this.$t("Warning"));
            this.details[i].quantity = 0;
          }else if(detail.quantity > detail.stock){
            this.makeToast("warning", this.$t("qty_return_is_greater_than_Quantity_Remaining"), this.$t("Warning"));
            this.details[i].quantity = 0;
          } else {
            this.details[i].quantity = detail.quantity;
          }
          this.Calcul_Total();
          this.$forceUpdate();
        }
      }
    },

    //----------------------------------- increment QTY ------------------------------\\

    increment(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          if (detail.quantity + 1 > detail.purchase_quantity) {
            this.makeToast("warning", this.$t("qty_return_is_greater_than_qty_purchased"), this.$t("Warning"));
          }else if(detail.quantity + 1 > detail.stock){
            this.makeToast("warning", this.$t("qty_return_is_greater_than_Quantity_Remaining"), this.$t("Warning"));
          } else {
            this.formatNumber(this.details[i].quantity++, 2);
          }
        }
      }
      this.$forceUpdate();
      this.Calcul_Total();
    },

    //----------------------------------- decrement QTY ------------------------------\\

    decrement(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          if (detail.quantity - 1 > 0) {
            if (detail.quantity - 1 > detail.purchase_quantity) {
            this.makeToast("warning", this.$t("qty_return_is_greater_than_qty_purchased"), this.$t("Warning"));
            // this.details[i].quantity = 0;
          }else if(detail.quantity - 1 > detail.stock){
            this.makeToast("warning", this.$t("qty_return_is_greater_than_Quantity_Remaining"), this.$t("Warning"));
            // this.details[i].quantity = 0;
          } else {
              this.formatNumber(this.details[i].quantity--, 2);
            }
          }
        }
      }
      this.$forceUpdate();
      this.Calcul_Total();
    },

    //------------------------------Formetted Numbers -------------------------\\
    formatNumber(number, dec) {
      const value = (typeof number === "string"
        ? number
        : number.toString()
      ).split(".");
      if (dec <= 0) return value[0];
      let formated = value[1] || "";
      if (formated.length > dec)
        return `${value[0]}.${formated.substr(0, dec)}`;
      while (formated.length < dec) formated += "0";
      return `${value[0]}.${formated}`;
    },

    //-----------------------------------------Calcul Total ------------------------------\\
    Calcul_Total() {
      this.total = 0;
      for (var i = 0; i < this.details.length; i++) {
        var tax = this.details[i].taxe * this.details[i].quantity;
        this.details[i].subtotal = parseFloat(
          this.details[i].quantity * this.details[i].Net_cost + tax
        );
        this.total = parseFloat(this.total + this.details[i].subtotal);
      }

      const total_without_discount = parseFloat(
        this.total - this.purchase_return.discount
      );
      this.purchase_return.TaxNet = parseFloat(
        (total_without_discount * this.purchase_return.tax_rate) / 100
      );
      this.GrandTotal = parseFloat(
        total_without_discount +
          this.purchase_return.TaxNet +
          this.purchase_return.shipping
      );

      var grand_total =  this.GrandTotal.toFixed(this.priceDecimals);
      this.GrandTotal = parseFloat(grand_total);
    },

    //---------- keyup OrderTax
    keyup_OrderTax() {
      if (isNaN(this.purchase_return.tax_rate)) {
        this.purchase_return.tax_rate = 0;
      } else if(this.purchase_return.tax_rate == ''){
         this.purchase_return.tax_rate = 0;
        this.Calcul_Total();
      }else {
        this.Calcul_Total();
      }
    },

    //---------- keyup Discount

    keyup_Discount() {
      if (isNaN(this.purchase_return.discount)) {
        this.purchase_return.discount = 0;
      } else if(this.purchase_return.discount == ''){
         this.purchase_return.discount = 0;
        this.Calcul_Total();
      }else {
        this.Calcul_Total();
      }
    },

    //---------- keyup Shipping

    keyup_Shipping() {
      if (isNaN(this.purchase_return.shipping)) {
        this.purchase_return.shipping = 0;
      } else if(this.purchase_return.shipping == ''){
         this.purchase_return.shipping = 0;
        this.Calcul_Total();
      }else {
        this.Calcul_Total();
      }
    },

    //-----------------------------------Delete Detail Product ------------------------------\\
    // delete_Product_Detail(id) {
    //   for (var i = 0; i < this.details.length; i++) {
    //     if (id === this.details[i].detail_id) {
    //       this.details.splice(i, 1);
    //       this.Calcul_Total();
    //     }
    //   }
    // },

    //----------------------------------- Verified Qty If Null ------------------------------\\

     verifiedForm() {
      if (this.details.length <= 0) {
        this.makeToast(
          "warning",
          this.$t("AddProductToList"),
          this.$t("Warning")
        );
        return false;
      } else {
        var count = 0;
        for (var i = 0; i < this.details.length; i++) {
          if (
            this.details[i].quantity != "" ||
            this.details[i].quantity !== 0
          ) {
            count += 1;
          }
         
        }

        if (count === 0) {
          this.makeToast("warning", this.$t("Please_add_return_quantity"), this.$t("Warning"));
          return false;
        }

        // MS3 — never submit a return that would fall back to legacy.
        if (this.location_meta.blocked) {
          this.makeToast("danger", this.$t("Inventory_Location_Warehouse_Not_Ready") || "El almacén de inventario por ubicación no está listo.", this.$t("Failed"));
          return false;
        }
        if (this.location_meta.requires && !this.purchase_return.inventory_location_id) {
          this.makeToast("warning", this.$t("Choose_Inventory_Location") || "Selecciona una ubicación de inventario.", this.$t("Warning"));
          return false;
        }

        return true;
      }
    },

    //---- MS3 · inventory-location context for the return warehouse ----\\
    Load_Inventory_Locations(id) {
      if (!id) return;
      axios
        .get("purchase_returns_inventory_locations/" + id)
        .then(({ data }) => {
          this.inventory_locations = (data && data.locations) || [];
          this.location_meta = {
            requires: !!(data && data.requires_inventory_location),
            blocked: !!(data && data.blocked),
            mode: data && data.transition_mode,
            status: data && data.transition_status
          };
          if (!this.location_meta.requires) {
            this.purchase_return.inventory_location_id = null;
            return;
          }
          // MS5-D.1 (D2) precedence: explicit default (quarantine allowed) ->
          // linked-purchase location ONLY if not quarantine -> sole
          // non-quarantine location -> empty. The backend seeds
          // inventory_location_id from the linked Purchase, so it is passed as
          // a suggestion, never as authorisation to auto-pick quarantine.
          this.purchase_return.inventory_location_id = resolveAutoInventoryLocation({
            locations: this.inventory_locations,
            defaultLocationId: data && data.default_inventory_location_id,
            linkedLocationId: this.purchase_return.inventory_location_id
          });
          if (this.purchase_return.inventory_location_id) this.Selected_Inventory_Location(this.purchase_return.inventory_location_id);
        })
        .catch(() => {
          this.inventory_locations = [];
          this.location_meta = { requires: false, blocked: false, mode: null, status: null };
        });
    },

    //---- MS3 · refresh per-line stock from the chosen inventory_location ----\\
    Selected_Inventory_Location(locationId) {
      const id = locationId || this.purchase_return.inventory_location_id;
      // MS6-B2 — a serial selected for the PREVIOUS location is never valid at
      // the new one: never conserve it silently. serial-numbers-field refetches
      // its candidate list on its own (fetch-params watcher); the SELECTION
      // itself must be cleared here, or the payload could still submit stale
      // serials that this location's candidate list no longer offers.
      this.details.forEach(d => {
        if (d && d.is_imei && Array.isArray(d.serial_numbers) && d.serial_numbers.length) {
          this.$set(d, "serial_numbers", []);
        }
      });
      if (!id || !this.location_meta.requires) return;
      axios
        .get("purchase_returns_location_catalog/" + id)
        .then(({ data }) => {
          const byKey = {};
          (data && data.products ? data.products : []).forEach(row => {
            byKey[row.product_id + ":" + (row.product_variant_id || 0)] = Number(row.available_quantity) || 0;
          });
          this.details.forEach(d => {
            const key = d.product_id + ":" + (d.product_variant_id || 0);
            if (Object.prototype.hasOwnProperty.call(byKey, key)) {
              this.$set(d, "stock", byKey[key]);
            }
          });
        })
        .catch(() => {});
    },

    //--------------------------------- Create Return Purchase -------------------------\\
    Create_Return_Purchase() {
      if (this.verifiedForm()) {
        this.SubmitProcessing = true;
        NProgress.start();
        NProgress.set(0.1);
        axios
          .post("returns/purchase", {
            date: this.purchase_return.date,
            supplier_id: this.purchase_return.supplier_id,
            purchase_id: this.purchase_return.purchase_id,
            warehouse_id: this.purchase_return.warehouse_id,
            // MS3 — only sent when the warehouse is a healthy location_primary.
            inventory_location_id: this.location_meta.requires ? this.purchase_return.inventory_location_id : null,
            statut: this.purchase_return.statut,
            notes: this.purchase_return.notes,
            tax_rate: this.purchase_return.tax_rate?this.purchase_return.tax_rate:0,
            TaxNet: this.purchase_return.TaxNet?this.purchase_return.TaxNet:0,
            discount: this.purchase_return.discount?this.purchase_return.discount:0,
            shipping: this.purchase_return.shipping?this.purchase_return.shipping:0,
            GrandTotal: this.GrandTotal,
            details: this.details
          })
          .then(response => {
            NProgress.done();
            this.makeToast(
              "success",
              this.$t("Successfully_Created"),
              this.$t("Success")
            );

            this.SubmitProcessing = false;
            this.$router.push({
              name: "index_purchase_return"
            });
          })
          .catch(error => {
            NProgress.done();
            this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
            this.SubmitProcessing = false;
          });
      }
    },


    //--------------------------------------- Get Elements ------------------------------\\
    GetElements() {
      let id = this.$route.params.id;
      axios
        .get(`returns/purchase/create_purchase_return/${id}`)
        .then(response => {
          this.details = (response.data.details || []).map(d => {
            if (!Array.isArray(d.serial_numbers)) d.serial_numbers = [];
            return d;
          });
          this.purchase_return = response.data.purchase_return;
          this.purchase_return.date = new Date().toISOString().slice(0, 10);
          // MS3 — load the inventory-location context for the (fixed) warehouse;
          // the suggested purchase_return.inventory_location_id is preserved if valid.
          this.Load_Inventory_Locations(this.purchase_return.warehouse_id);
          this.Calcul_Total();
          this.isLoading = false;
          this.syncValidators();
        })
        .catch(response => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    }
  },

  //----------------------------- Created function-------------------
  created() {
    this.GetElements();
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxpurf { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxpurf { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxpurf__pad { padding: var(--pxn-space-6) 0; }
.pxpurf__card { margin-top: var(--pxn-space-5); }
.pxpurf__grid3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 820px) { .pxpurf__grid3 { grid-template-columns: minmax(0, 1fr); } }
.pxpurf__gap { margin-top: var(--pxn-space-6); }
.pxpurf__lineshead { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxpurf__refund { margin: var(--pxn-space-3) 0 var(--pxn-space-4); }
.pxpurf-tbl__wrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); overflow-x: auto; }
.pxpurf-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxpurf-tbl th { padding: var(--pxn-space-3) var(--pxn-space-4); text-align: left; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap; }
.pxpurf-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink); vertical-align: top; }
.pxpurf-tbl .is-right { text-align: right; }
.pxpurf-tbl .is-center { text-align: center; }
.pxpurf__empty { text-align: center; color: var(--pxn-ink-3); }
.pxpurf__badgeline { margin-top: var(--pxn-space-2); }
.pxpurf__inlinealert { padding: 0 var(--pxn-space-3) var(--pxn-space-4) !important; border-bottom: 1px solid var(--pxn-border); }
.pxpurf__serialbox { background: var(--pxn-info-soft); border: 1px solid var(--pxn-info-border); border-left: 4px solid var(--pxn-info); border-radius: var(--pxn-radius-md); padding: var(--pxn-space-4) var(--pxn-space-5); }
.pxpurf__hint { margin-top: var(--pxn-space-2); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxpurf__stepper { display: inline-flex; align-items: stretch; border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-sm); overflow: hidden; }
.pxpurf__step { border: 0; width: 30px; background: var(--pxn-primary); color: var(--pxn-primary-contrast); font-size: var(--pxn-fs-body); cursor: pointer; }
.pxpurf__step:hover { background: var(--pxn-primary-hover); }
.pxpurf__stepinput { width: 64px; text-align: center; border: 0; border-left: 1px solid var(--pxn-border-control); border-right: 1px solid var(--pxn-border-control); background: var(--pxn-surface); color: var(--pxn-ink); font-size: var(--pxn-fs-sm); }
.pxpurf__stepinput:focus { outline: none; }
.pxpurf__totals { display: flex; justify-content: flex-end; }
.pxpurf-totbl { min-width: 300px; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxpurf-totbl td { padding: var(--pxn-space-2) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink-2); }
.pxpurf-totbl td.is-right { text-align: right; color: var(--pxn-ink); }
.pxpurf__totrow td { font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxpurf__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
</style>
