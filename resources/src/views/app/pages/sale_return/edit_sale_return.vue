<template>
  <div class="px-next pxsrf">
    <px-page-header :title="$t('EditSaleReturn')" :breadcrumbs="[{ label: $t('ListReturns') }, { label: $t('EditSaleReturn') }]">
      <template #actions>
        <px-button variant="ghost" icon="arrow-left" @click="$router.push({ name: 'index_sale_return' })">{{ $t('Cancel') }}</px-button>
        <px-button variant="primary" icon="check" :loading="SubmitProcessing" :disabled="SubmitProcessing" @click="Submit_Sale_return">{{ $t('submit') }}</px-button>
      </template>
    </px-page-header>

    <div v-if="isLoading" class="pxsrf__pad">
      <px-skeleton variant="lines" :rows="10" />
    </div>

    <validation-observer v-else ref="edit_sale_return" tag="div">
      <px-card class="pxsrf__card">
        <div class="pxsrf__grid3">
          <validation-provider ref="dateProvider" name="date" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('date')" required :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" type="date" v-model="sale_return.date" @input="v.validate" /></template>
            </px-field>
          </validation-provider>

          <px-field :label="$t('Sale')">
            <template #default="{ id }"><px-input :id="id" v-model="sale_return.sale_ref" disabled /></template>
          </px-field>

          <validation-provider ref="statutProvider" name="Status" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Status')" required :error="v.errors[0]">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="sale_return.statut" :reduce="o => o.value"
                  :placeholder="$t('Choose_Status')" @input="v.validate"
                  :options="[{ label: $t('Received'), value: 'received' }, { label: $t('Pending'), value: 'pending' }]" />
              </template>
            </px-field>
          </validation-provider>

          <validation-provider v-if="location_meta.requires" ref="locProvider" name="inventory_location" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Inventory_Location')" required :error="v.errors[0]"
              :hint="$t('Return_Destination_Hint') || 'A dónde entran físicamente los productos devueltos (puede diferir de la ubicación de la venta original).'">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="sale_return.inventory_location_id" :reduce="o => o.value"
                  :placeholder="$t('Choose_Inventory_Location')" @input="v.validate"
                  :options="inventory_locations.map(l => ({ label: l.name + ' · ' + l.type, value: l.id }))" />
              </template>
            </px-field>
          </validation-provider>
        </div>

        <px-alert v-if="location_meta.blocked" tone="danger" class="pxsrf__gap">
          {{ $t('Inventory_Location_Warehouse_Not_Ready') || 'Este almacén usa inventario por ubicación pero aún no está reconciliado. No se puede registrar la devolución hasta resolverlo.' }}
        </px-alert>

        <div class="pxsrf__lines pxsrf__gap">
          <h5 class="pxsrf__lineshead">{{ $t('list_product_returns') }} *</h5>
          <px-alert tone="danger" bare class="pxsrf__refund">{{ $t('products_refunded_alert') }}</px-alert>

          <div class="pxsrf-tbl__wrap pxn-scroll">
            <table class="pxsrf-tbl">
              <thead>
                <tr>
                  <th style="width:44px">#</th>
                  <th>{{ $t('ProductName') }}</th>
                  <th class="is-right">{{ $t('Net_Unit_Price') }}</th>
                  <th class="is-right">{{ $t('Quantity_sold') }}</th>
                  <th class="is-center">{{ $t('Qty_return') }}</th>
                  <th class="is-right">{{ $t('Discount') }}</th>
                  <th class="is-right">{{ $t('Tax') }}</th>
                  <th class="is-right">{{ $t('SubTotal') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="details.length <= 0"><td colspan="8" class="pxsrf__empty">{{ $t('NodataAvailable') }}</td></tr>
                <tr
                  v-for="detail in details"
                  :key="detail.detail_id"
                  :class="{ 'pxsrf__rowdel': detail.del === 1 || (detail.no_unit === 0 && detail.product_type != 'is_service') }"
                >
                  <td class="pxn-num">{{ detail.detail_id }}</td>
                  <td>
                    <span class="pxn-mono">{{ detail.code }}</span><br />
                    <px-badge tone="success">{{ detail.name }}</px-badge>
                    <div v-if="detail.is_batch_tracked" class="pxsrf__badgeline">
                      <px-badge tone="info" icon="package"
                        :title="$t('Auto_Batch_Mirror_Hint') || 'Los lotes de la venta original se acreditarán proporcionalmente al recibir esta devolución.'">
                        {{ $t('Batches') || 'Lotes' }} · Auto
                      </px-badge>
                    </div>
                    <div v-if="detail.is_imei && detail.is_batch_tracked" class="pxsrf__badgeline">
                      <px-badge tone="danger" icon="alert-triangle" :title="$t('Serial_Batch_Incompatible') || 'Lote y serie/IMEI no son compatibles'">
                        {{ $t('Serial_Batch_Incompatible') || 'Lote y serie/IMEI incompatibles' }}
                      </px-badge>
                    </div>
                  </td>
                  <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber(detail.Net_price, priceDecimals) }}</td>
                  <td class="is-right"><px-badge tone="warning">{{ detail.sale_quantity }} {{ detail.unitSale }}</px-badge></td>
                  <td class="is-center">
                    <div class="pxsrf__stepper">
                      <button type="button" class="pxsrf__step" v-show="detail.no_unit !== 0 || detail.product_type == 'is_service'" @click="decrement(detail, detail.detail_id)">−</button>
                      <input
                        class="pxsrf__stepinput"
                        @keyup="Verified_Qty(detail, detail.detail_id)"
                        :min="0"
                        v-model.number="detail.quantity"
                        :disabled="detail.del === 1 || (detail.no_unit === 0 && detail.product_type != 'is_service')"
                      />
                      <button type="button" class="pxsrf__step" v-show="detail.no_unit !== 0 || detail.product_type == 'is_service'" @click="increment(detail, detail.detail_id)">+</button>
                    </div>
                  </td>
                  <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber(detail.DiscountNet * detail.quantity, 2) }}</td>
                  <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber(detail.taxe * detail.quantity, 2) }}</td>
                  <td class="is-right pxn-num">{{ currentUser.currency }} {{ detail.subtotal.toFixed(priceDecimals) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="pxsrf__grid3 pxsrf__gap">
          <validation-provider name="Order Tax" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('OrderTax')" :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model.number="sale_return.tax_rate" suffix="%" @input="v.validate($event); keyup_OrderTax()" /></template>
            </px-field>
          </validation-provider>
          <validation-provider name="Discount" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('Discount')" :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model.number="sale_return.discount" :suffix="currentUser.currency" @input="v.validate($event); keyup_Discount()" /></template>
            </px-field>
          </validation-provider>
          <validation-provider name="Shipping" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('Shipping')" :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model.number="sale_return.shipping" :suffix="currentUser.currency" @input="v.validate($event); keyup_Shipping()" /></template>
            </px-field>
          </validation-provider>
        </div>

        <div class="pxsrf__totals pxsrf__gap">
          <table class="pxsrf-totbl">
            <tbody>
              <tr><td>{{ $t('OrderTax') }}</td><td class="is-right pxn-num">{{ currentUser.currency }} {{ sale_return.TaxNet.toFixed(priceDecimals) }} ({{ formatNumber(sale_return.tax_rate, 2) }} %)</td></tr>
              <tr><td>{{ $t('Discount') }}</td><td class="is-right pxn-num">{{ currentUser.currency }} {{ sale_return.discount.toFixed(priceDecimals) }}</td></tr>
              <tr><td>{{ $t('Shipping') }}</td><td class="is-right pxn-num">{{ currentUser.currency }} {{ sale_return.shipping.toFixed(priceDecimals) }}</td></tr>
              <tr class="pxsrf__totrow"><td>{{ $t('Total') }}</td><td class="is-right pxn-num">{{ currentUser.currency }} {{ GrandTotal.toFixed(priceDecimals) }}</td></tr>
            </tbody>
          </table>
        </div>

        <px-field :label="$t('Please_provide_any_details')" class="pxsrf__gap">
          <template #default="{ id }"><px-textarea :id="id" v-model="sale_return.notes" :rows="4" :placeholder="$t('Afewwords')" /></template>
        </px-field>

        <div class="pxsrf__actionbar">
          <px-button variant="secondary" type="button" @click="$router.push({ name: 'index_sale_return' })">{{ $t('Cancel') }}</px-button>
          <px-button variant="primary" icon="check" :loading="SubmitProcessing" :disabled="SubmitProcessing" @click="Submit_Sale_return">{{ $t('submit') }}</px-button>
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
    title: "Editar devolución"
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
      sale_return: {
        id: "",
        date: "",
        notes: "",
        statut: "",
        client_id: "",
        warehouse_id: "",
        inventory_location_id: null,
        sale_id:"",
        tax_rate: 0,
        TaxNet: 0,
        shipping: 0,
        discount: 0
      },
      // MS7-B1 — RETURN DESTINATION context for the sale's warehouse. Never
      // auto-assumed from the original sale's own location (§21/§25) — the
      // user picks explicitly among this warehouse's locations.
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
    syncValidators() {
      this.$nextTick(() => {
        if (this.$refs.dateProvider) this.$refs.dateProvider.syncValue(this.sale_return.date);
        if (this.$refs.statutProvider) this.$refs.statutProvider.syncValue(this.sale_return.statut);
        if (this.$refs.locProvider) this.$refs.locProvider.syncValue(this.sale_return.inventory_location_id);
      });
    },

    //--- Submit Validate Update Sale Return
    Submit_Sale_return() {
      this.$refs.edit_sale_return.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Update_Return();
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

    //-----------------------------------Verified QTY ------------------------------\\
     Verified_Qty(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          if (isNaN(detail.quantity)) {
            this.details[i].quantity = 1;
          }

          if (detail.quantity > detail.sale_quantity) {
            this.makeToast("warning", this.$t("qty_return_is_greater_than_qty_sold"), this.$t("Warning"));
            this.details[i].quantity = detail.sale_quantity;
          } else {
            this.details[i].quantity = detail.quantity;
          }

          this.Calcul_Total();
          this.$forceUpdate();
        }
      }
    },

    //-----------------------------------increment QTY ------------------------------\\

     increment(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          if (detail.quantity + 1 > detail.sale_quantity) {
            this.makeToast("warning", this.$t("qty_return_is_greater_than_qty_sold"), this.$t("Warning"));
          } else {
            this.formatNumber(this.details[i].quantity++, 2);
          }
        }
      }
      this.$forceUpdate();
      this.Calcul_Total();
    },

    //-----------------------------------decrement QTY ------------------------------\\

     decrement(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          if (detail.quantity - 1 > 0) {
            if (detail.quantity - 1 > detail.sale_quantity) {
              this.makeToast(
                "warning",
                this.$t("qty_return_is_greater_than_qty_sold"),
                this.$t("Warning")
              );
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
          this.details[i].quantity * this.details[i].Net_price + tax
        );
        this.total = parseFloat(this.total + this.details[i].subtotal);
      }

      const total_without_discount = parseFloat(
        this.total - this.sale_return.discount
      );
      this.sale_return.TaxNet = parseFloat(
        (total_without_discount * this.sale_return.tax_rate) / 100
      );
      this.GrandTotal = parseFloat(
        total_without_discount +
          this.sale_return.TaxNet +
          this.sale_return.shipping
      );

      var grand_total =  this.GrandTotal.toFixed(this.priceDecimals);
      this.GrandTotal = parseFloat(grand_total);
    },

    //---------- keyup OrderTax
    keyup_OrderTax() {
      if (isNaN(this.sale_return.tax_rate)) {
        this.sale_return.tax_rate = 0;
      } else if(this.sale_return.tax_rate == ''){
         this.sale_return.tax_rate = 0;
        this.Calcul_Total();
      }else {
        this.Calcul_Total();
      }
    },

    //---------- keyup Discount

    keyup_Discount() {
      if (isNaN(this.sale_return.discount)) {
        this.sale_return.discount = 0;
       } else if(this.sale_return.discount == ''){
         this.sale_return.discount = 0;
        this.Calcul_Total();
      }else {
        this.Calcul_Total();
      }
    },

    //---------- keyup Shipping

    keyup_Shipping() {
      if (isNaN(this.sale_return.shipping)) {
        this.sale_return.shipping = 0;
       } else if(this.sale_return.shipping == ''){
         this.sale_return.shipping = 0;
        this.Calcul_Total();
      }else {
        this.Calcul_Total();
      }
    },

    //-----------------------------------verified Qty If Null || 0 ------------------------------\\

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

        // A product configured with BOTH batch and serial/IMEI tracking is not
        // supported by the backend (422) — block before submit.
        if (this.serialBatchConflictDetail) {
          this.makeToast(
            "danger",
            `${this.$t('Serial_Batch_Incompatible') || 'Lote y serie/IMEI no son compatibles'} (${this.serialBatchConflictDetail.name})`,
            this.$t("Failed")
          );
          return false;
        }

        // MS7-B1 — never submit a return that would fall back to legacy.
        if (this.location_meta.blocked) {
          this.makeToast("danger", this.$t("Inventory_Location_Warehouse_Not_Ready") || "El almacén de inventario por ubicación no está listo.", this.$t("Failed"));
          return false;
        }
        if (this.location_meta.requires && !this.sale_return.inventory_location_id) {
          this.makeToast("warning", this.$t("Choose_Inventory_Location") || "Selecciona una ubicación de inventario.", this.$t("Warning"));
          return false;
        }

        return true;
      }
    },

    //--------------------------------- Update Return -------------------------\\
    Update_Return() {
      if (this.verifiedForm()) {
        this.SubmitProcessing = true;
        NProgress.start();
        NProgress.set(0.1);
        let id = this.$route.params.id;
        axios
          .put(`returns/sale/${id}`, {
            date: this.sale_return.date,
            client_id: this.sale_return.client_id,
            sale_id: this.sale_return.sale_id,
            warehouse_id: this.sale_return.warehouse_id,
            // MS7-B1 — RETURN DESTINATION; only sent when the warehouse is a
            // healthy location_primary.
            inventory_location_id: this.location_meta.requires ? this.sale_return.inventory_location_id : null,
            statut: this.sale_return.statut,
            notes: this.sale_return.notes,
            tax_rate: this.sale_return.tax_rate?this.sale_return.tax_rate:0,
            TaxNet: this.sale_return.TaxNet?this.sale_return.TaxNet:0,
            discount: this.sale_return.discount?this.sale_return.discount:0,
            shipping: this.sale_return.shipping?this.sale_return.shipping:0,
            GrandTotal: this.GrandTotal,
            details: this.details
          })
          .then(response => {
            NProgress.done();
            this.makeToast(
              "success",
              this.$t("Successfully_Updated"),
              this.$t("Success")
            );

            this.SubmitProcessing = false;
            this.$router.push({ name: "index_sale_return" });
          })
          .catch(error => {
            NProgress.done();
            this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
            this.SubmitProcessing = false;
          });
      }
    },

    //---------------------------------------Get Elements ------------------------------\\
    GetElements() {
      let id = this.$route.params.id;
      let sale_id = this.$route.params.sale_id;
      axios
        .get(`returns/sale/edit_sell_return/${id}/${sale_id}`)
        .then(response => {
          this.sale_return = response.data.sale_return;
          this.details = response.data.details;
          // MS7-B1 — load the RETURN DESTINATION context for the sale's
          // warehouse; the loaded sale_return.inventory_location_id (if any)
          // is preserved.
          this.Load_Inventory_Locations(this.sale_return.warehouse_id);
          this.Calcul_Total();
          this.isLoading = false;
          this.syncValidators();
        })
        .catch(response => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    //---- MS7-B1 · inventory locations of the sale's warehouse (return destination) ----\\
    Load_Inventory_Locations(id) {
      if (!id) return;
      axios
        .get("sale_returns_inventory_locations/" + id)
        .then(({ data }) => {
          this.inventory_locations = (data && data.locations) || [];
          this.location_meta = {
            requires: !!(data && data.requires_inventory_location),
            blocked: !!(data && data.blocked),
            mode: data && data.transition_mode,
            status: data && data.transition_status
          };
          if (!this.location_meta.requires) {
            this.sale_return.inventory_location_id = null;
            return;
          }
          // A persisted, still-valid location is kept as document state; if
          // it is no longer valid it is cleared and D2 re-runs over the
          // current options.
          this.sale_return.inventory_location_id = resolveAutoInventoryLocation({
            locations: this.inventory_locations,
            defaultLocationId: data && data.default_inventory_location_id,
            persistedLocationId: this.sale_return.inventory_location_id
          });
          this.syncValidators();
        })
        .catch(() => {
          this.inventory_locations = [];
          this.location_meta = { requires: false, blocked: false, mode: null, status: null };
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
.pxsrf { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxsrf { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxsrf__pad { padding: var(--pxn-space-6) 0; }
.pxsrf__card { margin-top: var(--pxn-space-5); }

.pxsrf__grid3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 820px) { .pxsrf__grid3 { grid-template-columns: minmax(0, 1fr); } }
.pxsrf__gap { margin-top: var(--pxn-space-6); }

.pxsrf__lineshead { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxsrf__refund { margin: var(--pxn-space-3) 0 var(--pxn-space-4); }
.pxsrf-tbl__wrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); overflow-x: auto; }
.pxsrf-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxsrf-tbl th { padding: var(--pxn-space-3) var(--pxn-space-4); text-align: left; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap; }
.pxsrf-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink); vertical-align: top; }
.pxsrf-tbl .is-right { text-align: right; }
.pxsrf-tbl .is-center { text-align: center; }
.pxsrf__empty { text-align: center; color: var(--pxn-ink-3); }
.pxsrf__rowdel { opacity: 0.55; }
.pxsrf__badgeline { margin-top: var(--pxn-space-2); }

.pxsrf__stepper { display: inline-flex; align-items: stretch; border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-sm); overflow: hidden; }
.pxsrf__step { border: 0; width: 30px; background: var(--pxn-primary); color: var(--pxn-primary-contrast); font-size: var(--pxn-fs-body); cursor: pointer; }
.pxsrf__step:hover { background: var(--pxn-primary-hover); }
.pxsrf__stepinput { width: 64px; text-align: center; border: 0; border-left: 1px solid var(--pxn-border-control); border-right: 1px solid var(--pxn-border-control); background: var(--pxn-surface); color: var(--pxn-ink); font-size: var(--pxn-fs-sm); }
.pxsrf__stepinput:focus { outline: none; }
.pxsrf__stepinput:disabled { background: var(--pxn-surface-2); color: var(--pxn-ink-3); }

.pxsrf__totals { display: flex; justify-content: flex-end; }
.pxsrf-totbl { min-width: 300px; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxsrf-totbl td { padding: var(--pxn-space-2) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink-2); }
.pxsrf-totbl td.is-right { text-align: right; color: var(--pxn-ink); }
.pxsrf__totrow td { font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }

.pxsrf__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
</style>
