<template>
  <div class="px-next pxqf">
    <px-page-header
      :title="$t('EditQuote')"
      :breadcrumbs="[{ label: $t('Sales') }, { label: $t('ListQuotations') }, { label: $t('EditQuote') }]"
    >
      <template #actions>
        <px-button variant="ghost" icon="arrow-left" @click="$router.push({ name: 'index_quotation' })">{{ $t('Cancel') }}</px-button>
        <px-button
          variant="primary" icon="check"
          :loading="SubmitProcessing"
          :disabled="SubmitProcessing || hasBatchValidationErrors"
          @click="Submit_Quotation"
        >{{ $t('submit') }}</px-button>
      </template>
    </px-page-header>

    <div v-if="isLoading" class="pxqf__pad">
      <px-skeleton variant="lines" :rows="10" />
    </div>

    <validation-observer v-else ref="edit_quote" tag="div">
      <px-card class="pxqf__card">
        <!-- Header fields -->
        <div class="pxqf__grid3">
          <validation-provider ref="dateProvider" name="date" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('date')" required :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" type="date" v-model="quote.date" @input="v.validate" /></template>
            </px-field>
          </validation-provider>

          <validation-provider ref="clientProvider" name="Customer" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Customer')" required :error="v.errors[0]">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="quote.client_id" :reduce="o => o.value"
                  :placeholder="$t('Choose_Customer')" @input="v.validate"
                  :options="clients.map(c => ({ label: c.name, value: c.id }))" />
              </template>
            </px-field>
          </validation-provider>

          <validation-provider ref="warehouseProvider" name="warehouse" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('warehouse')" required :error="v.errors[0]"
              :hint="details.length > 0 ? ($t('Warehouse_locked_has_lines') || 'No se puede cambiar el almacén con productos en la lista.') : ''">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="quote.warehouse_id" :reduce="o => o.value"
                  :disabled="details.length > 0" :placeholder="$t('Choose_Warehouse')"
                  @input="val => { Selected_Warehouse(val); v.validate(val); }"
                  :options="warehouses.map(w => ({ label: w.name, value: w.id }))" />
              </template>
            </px-field>
          </validation-provider>
        </div>

        <!-- Product search -->
        <div class="pxqf__search pxqf__gap">
          <h5 class="pxqf__lineshead">{{ $t('ProductName') }}</h5>
          <div id="autocomplete" class="pxqf-ac">
            <button type="button" class="pxqf-ac__scan" :title="$t('Scan')" @click="showModal">
              <lucide-icon name="scan-line" />
            </button>
            <input
              :placeholder="$t('Scan_Search_Product_by_Code_Name')"
              @input="e => search_input = e.target.value"
              @keyup="search(search_input)"
              @focus="handleFocus"
              @blur="handleBlur"
              ref="product_autocomplete"
              class="pxqf-ac__input" />
            <ul class="pxqf-ac__list" v-show="focused && product_filter.length">
              <li class="pxqf-ac__item" v-for="product_fil in product_filter" :key="product_fil.id" @mousedown="SearchProduct(product_fil)">
                {{ getResultValue(product_fil) }}
              </li>
            </ul>
          </div>
        </div>

        <!-- Order products -->
        <div class="pxqf__lines pxqf__gap">
          <h5 class="pxqf__lineshead">{{ $t('order_products') }} *</h5>
          <div class="pxqf-tbl__wrap pxn-scroll">
            <table class="pxqf-tbl">
              <thead>
                <tr>
                  <th style="width:44px">#</th>
                  <th>{{ $t('ProductName') }}</th>
                  <th class="is-right">{{ $t('Net_Unit_Price') }}</th>
                  <th class="is-right">{{ $t('CurrentStock') }}</th>
                  <th class="is-center">{{ $t('Qty') }}</th>
                  <th class="is-right">{{ $t('Discount') }}</th>
                  <th class="is-right">{{ $t('Tax') }}</th>
                  <th class="is-right">{{ $t('SubTotal') }}</th>
                  <th class="is-center" style="width:72px"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="details.length <= 0"><td colspan="9" class="pxqf__empty">{{ $t('NodataAvailable') }}</td></tr>
                <template v-for="detail in details">
                  <tr :key="'r-' + detail.detail_id">
                    <td class="pxn-num">{{ detail.detail_id }}</td>
                    <td>
                      <span class="pxn-mono">{{ detail.code }}</span><br />
                      <px-badge tone="success">{{ detail.name }}</px-badge>
                      <px-badge v-if="detail.is_batch_tracked" tone="info" :title="batchBadgeTitle(detail)" class="pxqf__badgeline">
                        {{ (detail.batches && detail.batches.length) || 0 }} {{ $t('Batches') || 'batches' }}
                      </px-badge>
                    </td>
                    <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber(detail.Net_price, priceDecimals) }}</td>
                    <td class="is-right">
                      <px-badge tone="warning" v-if="detail.product_type == 'is_service'">----</px-badge>
                      <px-badge tone="warning" v-else>{{ detail.stock }} {{ detail.unitSale }}</px-badge>
                    </td>
                    <td class="is-center">
                      <span class="pxqf__stepper">
                        <button type="button" class="pxqf__step" @click="decrement(detail, detail.detail_id)">−</button>
                        <input class="pxqf__stepinput" @keyup="Verified_Qty(detail, detail.detail_id)"
                          :min="0.00" :max="detail.stock" v-model.number="detail.quantity" />
                        <button type="button" class="pxqf__step" @click="increment(detail, detail.detail_id)">+</button>
                      </span>
                    </td>
                    <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber(detail.DiscountNet * detail.quantity, priceDecimals) }}</td>
                    <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber(detail.taxe * detail.quantity, priceDecimals) }}</td>
                    <td class="is-right pxn-num">{{ currentUser.currency }} {{ detail.subtotal.toFixed(priceDecimals) }}</td>
                    <td class="is-center pxqf__rowactions">
                      <lucide-icon class="pxqf__ico is-edit" name="pencil"
                        v-if="currentUserPermissions && currentUserPermissions.includes('edit_product_quotation')"
                        @click="Modal_Updat_Detail(detail)" />
                      <lucide-icon class="pxqf__ico is-del" name="x" @click="delete_Product_Detail(detail.detail_id)" />
                    </td>
                  </tr>
                  <tr v-if="detail.is_batch_tracked" :key="'b-' + detail.detail_id" class="pxqf__batchrow">
                    <td colspan="9">
                      <div class="pxqf__batchbox">
                        <div class="pxqf__batchhead">
                          <div class="pxqf__batchtitle">
                            {{ $t('Batch_Allocation') || 'Batch allocation' }}
                            <span class="pxqf__batchsub">— {{ detail.name }}</span>
                          </div>
                          <px-button size="sm" variant="secondary" icon="plus" @click="add_batch_to_detail(detail)">
                            {{ $t('Add_Batch') || 'Add batch' }}
                          </px-button>
                        </div>
                        <div v-if="detail.batches_loading" class="pxqf__batchmuted">{{ $t('Loading') || 'Loading...' }}</div>
                        <div v-else-if="!detail.batches || detail.batches.length === 0" class="pxqf__batchmuted">
                          {{ $t('No_Batch_Selected_Yet') || 'No batch selected yet — click Add batch.' }}
                        </div>
                        <table v-else class="pxqf-batchtbl">
                          <thead>
                            <tr>
                              <th>{{ $t('Batch') || 'Batch' }}</th>
                              <th>{{ $t('Expiry') || 'Expiry' }}</th>
                              <th>{{ $t('Available') || 'Available' }}</th>
                              <th>{{ $t('Qty') || 'Qty' }}</th>
                              <th></th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr v-for="(b, idx) in detail.batches" :key="'bb-' + detail.detail_id + '-' + idx">
                              <td style="min-width:220px;">
                                <vs-px
                                  :append-to-body="true"
                                  :options="(detail.available_batches || []).map(ab => ({ label: ab.batch_no + (ab.expiry_date ? ' (exp ' + ab.expiry_date + ')' : ''), value: ab.id }))"
                                  :reduce="x => x.value"
                                  :value="b.product_batch_id"
                                  @input="(val) => on_batch_select(detail, idx, val)"
                                  :placeholder="$t('Choose_Batch') || 'Choose batch'" />
                              </td>
                              <td>
                                <span v-if="b.expiry_date" :style="expiry_pill_style(b.expiry_date)">{{ b.expiry_date }}</span>
                                <span v-else class="pxqf__batchmuted">—</span>
                              </td>
                              <td><span class="pxqf__batchmuted">{{ b.qty_available != null ? b.qty_available : '—' }}</span></td>
                              <td style="max-width:120px;">
                                <input type="number" class="pxqf__batchqty" min="0" step="any" :value="b.qty" @input="(e) => on_batch_qty_input(detail, idx, e.target.value)" />
                              </td>
                              <td>
                                <lucide-icon class="pxqf__ico is-del" name="x" @click="remove_batch_from_detail(detail, idx)" />
                              </td>
                            </tr>
                          </tbody>
                        </table>
                        <div v-if="batch_qty_mismatch(detail)" class="pxqf__batchwarn">
                          {{ $t('Total_batch_qty_mismatch') || 'Total batch quantity does not match the line quantity' }} ({{ batch_total_qty(detail) }} / {{ detail.quantity }})
                        </div>
                      </div>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Tax / Discount / Shipping -->
        <div class="pxqf__grid3 pxqf__gap" v-if="currentUserPermissions && currentUserPermissions.includes('edit_tax_discount_shipping_quotation')">
          <validation-provider name="Order Tax" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('OrderTax')" :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model.number="quote.tax_rate" suffix="%" @input="v.validate($event); keyup_OrderTax()" /></template>
            </px-field>
          </validation-provider>
          <validation-provider name="Discount" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('Discount')" :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model.number="quote.discount" :suffix="currentUser.currency" @input="v.validate($event); keyup_Discount()" /></template>
            </px-field>
          </validation-provider>
          <validation-provider name="Shipping" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('Shipping')" :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model.number="quote.shipping" :suffix="currentUser.currency" @input="v.validate($event); keyup_Shipping()" /></template>
            </px-field>
          </validation-provider>
        </div>

        <!-- Totals -->
        <div class="pxqf__totals pxqf__gap">
          <table class="pxqf-totbl">
            <tbody>
              <tr><td>{{ $t('OrderTax') }}</td><td class="is-right pxn-num">{{ currentUser.currency }} {{ quote.TaxNet.toFixed(priceDecimals) }} ({{ formatNumber(quote.tax_rate, 2) }} %)</td></tr>
              <tr><td>{{ $t('Discount') }}</td><td class="is-right pxn-num">{{ currentUser.currency }} {{ quote.discount.toFixed(priceDecimals) }}</td></tr>
              <tr><td>{{ $t('Shipping') }}</td><td class="is-right pxn-num">{{ currentUser.currency }} {{ quote.shipping.toFixed(priceDecimals) }}</td></tr>
              <tr class="pxqf__totrow"><td>{{ $t('Total') }}</td><td class="is-right pxn-num">{{ currentUser.currency }} {{ GrandTotal.toFixed(priceDecimals) }}</td></tr>
            </tbody>
          </table>
        </div>

        <!-- Status + Note -->
        <div class="pxqf__grid3 pxqf__gap">
          <validation-provider ref="statutProvider" name="Status" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Status')" required :error="v.errors[0]">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="quote.statut" :reduce="o => o.value"
                  :placeholder="$t('Choose_Status')" @input="v.validate"
                  :options="[{ label: $t('Sent'), value: 'sent' }, { label: $t('Pending'), value: 'pending' }]" />
              </template>
            </px-field>
          </validation-provider>
        </div>

        <px-field :label="$t('Note')" class="pxqf__gap">
          <template #default="{ id }"><px-textarea :id="id" v-model="quote.notes" :rows="4" :placeholder="$t('Afewwords')" /></template>
        </px-field>

        <px-alert v-if="hasBatchValidationErrors" tone="warning" icon="info" class="pxqf__gap">
          {{ firstBatchErrorMessage }}
        </px-alert>

        <div class="pxqf__actionbar">
          <px-button variant="secondary" type="button" @click="$router.push({ name: 'index_quotation' })">{{ $t('Cancel') }}</px-button>
          <px-button variant="primary" icon="check" :loading="SubmitProcessing" :disabled="SubmitProcessing || hasBatchValidationErrors" @click="Submit_Quotation">{{ $t('submit') }}</px-button>
        </div>
      </px-card>
    </validation-observer>

    <!-- Modal: Update line detail -->
    <px-modal v-model="updateDetailOpen" :title="detail.name || $t('EditProduct')" size="lg">
      <validation-observer ref="Update_Detail_quote" tag="div">
        <div class="pxqf__grid2">
          <validation-provider name="Product Price" :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('ProductPrice')" required :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model="detail.Unit_price" @input="v.validate" /></template>
            </px-field>
          </validation-provider>

          <validation-provider name="Tax Method" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('TaxMethod')" required :error="v.errors[0]">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="detail.tax_method" :reduce="o => o.value"
                  :placeholder="$t('Choose_Method')" @input="v.validate"
                  :options="[{ label: 'Exclusive', value: '1' }, { label: 'Inclusive', value: '2' }]" />
              </template>
            </px-field>
          </validation-provider>

          <validation-provider name="Order Tax" :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('OrderTax')" required :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model="detail.tax_percent" suffix="%" @input="v.validate" /></template>
            </px-field>
          </validation-provider>

          <validation-provider name="Discount Method" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Discount_Method')" required :error="v.errors[0]">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="detail.discount_Method" :reduce="o => o.value"
                  :placeholder="$t('Choose_Method')" @input="v.validate"
                  :options="[{ label: 'Percent %', value: '1' }, { label: 'Fixed', value: '2' }]" />
              </template>
            </px-field>
          </validation-provider>

          <validation-provider name="Discount Rate" :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('Discount')" required :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model="detail.discount" @input="v.validate" /></template>
            </px-field>
          </validation-provider>

          <validation-provider v-if="detail.product_type != 'is_service'" name="Unit Sale" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('UnitSale')" required :error="v.errors[0]">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="detail.sale_unit_id" :reduce="o => o.value"
                  :placeholder="$t('Choose_Unit_Sale')" @input="v.validate"
                  :options="units.map(u => ({ label: u.name, value: u.id }))" />
              </template>
            </px-field>
          </validation-provider>

          <px-field v-show="detail.is_imei" :label="$t('Add_product_IMEI_Serial_number')" class="pxqf__grid2-full">
            <template #default="{ id }"><px-input :id="id" v-model="detail.imei_number" :placeholder="$t('Add_product_IMEI_Serial_number')" /></template>
          </px-field>
        </div>
      </validation-observer>
      <template #footer="{ close }">
        <px-button variant="secondary" @click="close">{{ $t('Cancel') }}</px-button>
        <px-button variant="primary" icon="check" :loading="Submit_Processing_detail" :disabled="Submit_Processing_detail" @click="submit_Update_Detail">{{ $t('submit') }}</px-button>
      </template>
    </px-modal>

    <!-- Modal: Barcode scanner -->
    <px-modal v-model="scanOpen" :title="$t('Barcode_Scanner') || 'Barcode Scanner'" size="md">
      <qrcode-scanner v-if="scanOpen" :qrbox="250" :fps="10" style="width: 100%;" @result="onScan" />
    </px-modal>
  </div>
</template>


<script>
import { mapActions, mapGetters } from "vuex";
import { getPriceDecimals } from "../../../../utils/priceFormat";
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "Editar cotización"
  },
  components: {
    PxPageHeader, PxCard, PxField, PxInput, PxTextarea, PxButton, PxBadge, PxAlert, PxModal, "vs-px": VsPx
  },
  data() {
    return {
      focused: false,
      timer:null,
      search_input:'',
      product_filter:[],
      isLoading: true,
      SubmitProcessing:false,
      Submit_Processing_detail:false,
      scanOpen: false,
      updateDetailOpen: false,
      warehouses: [],
      clients: [],
      products: [],
      details: [],
      detail: {},
      quotations: [],
      quote: {
        id: "",
        statut: "",
        notes: "",
        date: "",
        client_id: "",
        warehouse_id: "",
        tax_rate: 0,
        TaxNet: 0,
        shipping: 0,
        discount: 0
      },
      total: 0,
      GrandTotal: 0,
      product: {
        id: "",
        code: "",
        product_type: "",
        stock: "",
        quantity: 1,
        discount: "",
        DiscountNet: "",
        discount_Method: "",
        name: "",
        unitSale: "",
        sale_unit_id: "",
        Net_price: "",
        Total_price: "",
        Unit_price: "",
        subtotal: "",
        product_id: "",
        detail_id: "",
        taxe: "",
        tax_percent: "",
        tax_method: "",
        product_variant_id: "",
        del: "",
        etat: "",
        is_imei: "",
        imei_number:"",
        is_batch_tracked: false,
        batches: [],
        available_batches: [],
        batches_loading: false,
      }
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions","currentUser"]),
    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    hasBatchValidationErrors() {
      if (!Array.isArray(this.details)) return false;
      for (const d of this.details) {
        if (!d || !d.is_batch_tracked || d.del === 1) continue;
        const batches = Array.isArray(d.batches) ? d.batches : [];
        if (batches.length === 0) return true;
        const seen = new Set();
        for (const b of batches) {
          if (!b.product_batch_id) return true;
          const q = Number(b.qty);
          if (!(q > 0)) return true;
          if (q > (Number(b.qty_available) || 0) + 0.01) return true;
          if (seen.has(b.product_batch_id)) return true;
          seen.add(b.product_batch_id);
        }
        const total = Math.round(batches.reduce((s, b) => s + (Number(b.qty) || 0), 0) * 10000) / 10000;
        const target = Math.round((Number(d.quantity) || 0) * 10000) / 10000;
        if (Math.abs(total - target) > 0.01) return true;
      }
      return false;
    },
    firstBatchErrorMessage() {
      if (!Array.isArray(this.details)) return "";
      for (const d of this.details) {
        if (!d || !d.is_batch_tracked || d.del === 1) continue;
        const batches = Array.isArray(d.batches) ? d.batches : [];
        const label = d.name || d.code || "";
        if (batches.length === 0) {
          return (this.$t("Select_Batch_Required_For") || "Select a batch for") + " " + label;
        }
        const seen = new Set();
        for (const b of batches) {
          if (!b.product_batch_id) return (this.$t("Select_Batch_Required_For") || "Select a batch for") + " " + label;
          const q = Number(b.qty);
          if (!(q > 0)) return (this.$t("Batch_Qty_Required_For") || "Batch quantity must be greater than 0 for") + " " + label;
          if (q > (Number(b.qty_available) || 0) + 0.01) return (this.$t("Batch_Qty_Exceeds_Available") || "Batch quantity exceeds available stock for") + " " + label;
          if (seen.has(b.product_batch_id)) return (this.$t("Duplicate_Batch_Selected") || "The same batch is selected twice for") + " " + label;
          seen.add(b.product_batch_id);
        }
        const total = Math.round(batches.reduce((s, b) => s + (Number(b.qty) || 0), 0) * 10000) / 10000;
        const target = Math.round((Number(d.quantity) || 0) * 10000) / 10000;
        if (Math.abs(total - target) > 0.01) {
          return (this.$t("Total_batch_qty_mismatch") || "Total batch quantity does not match the line quantity") + " (" + total + " / " + target + ") — " + label;
        }
      }
      return "";
    }
  },

  watch: {
    "details": {
      deep: true,
      handler(details) {
        if (!Array.isArray(details)) return;
        for (const d of details) {
          if (!d || !d.is_batch_tracked || d.del === 1) continue;
          const batches = Array.isArray(d.batches) ? d.batches : [];
          if (batches.length !== 1) continue;
          const b = batches[0];
          const lineQty = Number(d.quantity);
          const batchQty = Number(b.qty);
          if (Number.isFinite(lineQty) && lineQty > 0 && batchQty !== lineQty) {
            this.$set(b, "qty", lineQty);
          }
        }
      }
    }
  },

  methods: {

     handleFocus() {
      this.focused = true
    },

    handleBlur() {
      this.focused = false
    },

       
    syncValidators() {
      this.$nextTick(() => {
        if (this.$refs.dateProvider) this.$refs.dateProvider.syncValue(this.quote.date);
        if (this.$refs.clientProvider) this.$refs.clientProvider.syncValue(this.quote.client_id);
        if (this.$refs.warehouseProvider) this.$refs.warehouseProvider.syncValue(this.quote.warehouse_id);
        if (this.$refs.statutProvider) this.$refs.statutProvider.syncValue(this.quote.statut);
      });
    },

    showModal() {
      this.scanOpen = true;
      
    },

    onScan (decodedText, decodedResult) {
      const code = decodedText;
      this.search_input = code;
      this.search();
      this.scanOpen = false;
    },

    
    //--- Submit Validate Edit Quotation
    Submit_Quotation() {
      this.$refs.edit_quote.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Update_Quotation();
        }
      });
    },
    //---Submit Validation Update Detail
    submit_Update_Detail() {
      this.$refs.Update_Detail_quote.validate().then(success => {
        if (!success) {
          return;
        } else {
          this.Update_Detail();
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

    //------ Show Modal Update Detail Product
    Modal_Updat_Detail(detail) {
      NProgress.start();
      NProgress.set(0.1);
      this.detail = {};
      this.detail.name = detail.name;
      this.detail.detail_id = detail.detail_id;
      this.detail.Unit_price = detail.Unit_price;
      this.detail.tax_method = detail.tax_method;
      this.detail.discount_Method = detail.discount_Method;
      this.detail.discount = detail.discount;
      this.detail.quantity = detail.quantity;
      this.detail.tax_percent = detail.tax_percent;
      this.detail.is_imei = detail.is_imei;
      this.detail.imei_number = detail.imei_number;

      setTimeout(() => {
        NProgress.done();
        this.updateDetailOpen = true;
      }, 1000);

    },

    //------ Submit Update Detail Product

    Update_Detail() {
      NProgress.start();
      NProgress.set(0.1);
      this.Submit_Processing_detail = true;
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id === this.detail.detail_id) {
          this.details[i].tax_percent = this.detail.tax_percent;
          this.details[i].Unit_price = this.detail.Unit_price;
          this.details[i].quantity = this.detail.quantity;
          this.details[i].tax_method = this.detail.tax_method;
          this.details[i].discount_Method = this.detail.discount_Method;
          this.details[i].discount = this.detail.discount;
          this.details[i].imei_number = this.detail.imei_number;

          if (this.details[i].discount_Method == "2") {
            //Fixed
            this.details[i].DiscountNet = this.detail.discount;
          } else {
            //Percentage %
            this.details[i].DiscountNet = parseFloat(
              (this.detail.Unit_price * this.details[i].discount) / 100
            );
          }

          if (this.details[i].tax_method == "1") {
            //Exclusive
            this.details[i].Net_price = parseFloat(
              this.detail.Unit_price - this.details[i].DiscountNet
            );

            this.details[i].taxe = parseFloat(
              (this.detail.tax_percent *
                (this.detail.Unit_price - this.details[i].DiscountNet)) /
                100
            );
          } else {
            //Inclusive
            this.details[i].taxe = parseFloat(
              (this.detail.Unit_price - this.details[i].DiscountNet) *
                (this.detail.tax_percent / 100)
            );

            this.details[i].Net_price = parseFloat(
              this.detail.Unit_price -
                this.details[i].taxe -
                this.details[i].DiscountNet
            );
          }

          this.$forceUpdate();
        }
      }
      this.Calcul_Total();

      setTimeout(() => {
        NProgress.done();
        this.Submit_Processing_detail = false;
        this.updateDetailOpen = false;
      }, 1000);

    },

   // Search Products
    search(){

      if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
      }

      if (this.search_input.length < 2) {

        return this.product_filter= [];
      }
      if (this.quote.warehouse_id != "" &&  this.quote.warehouse_id != null) {
        this.timer = setTimeout(() => {
          const product_filter = this.products.filter(product => product.code === this.search_input || product.barcode.includes(this.search_input));
            if(product_filter.length === 1){
                this.SearchProduct(product_filter[0])
            }else{
                this.product_filter=  this.products.filter(product => {
                  return (
                    product.name.toLowerCase().includes(this.search_input.toLowerCase()) ||
                    product.code.toLowerCase().includes(this.search_input.toLowerCase()) ||
                    product.barcode.toLowerCase().includes(this.search_input.toLowerCase())
                    );
                });

                // Check if product_filter is empty and show alert
                if (this.product_filter.length <= 0) {
                  this.makeToast(
                    "warning",
                    "Product Not Found",
                    "Warning"
                  );
                }
            }
        }, 800);
      } else {
        this.makeToast(
          "warning",
          this.$t("SelectWarehouse"),
          this.$t("Warning")
        );
      }

    },

    //------get Result Value Search Product

    getResultValue(result) {
      return result.code + " " + "(" + result.name + ")";
    },

    //------Submit Search Product

    SearchProduct(result) {
      this.product = {};
      if (
        this.details.length > 0 &&
        this.details.some(detail => detail.code === result.code)
      ) {
        this.makeToast("warning", this.$t("AlreadyAdd"), this.$t("Warning"));
      } else {
          if( result.product_type =='is_service'){
            this.product.quantity = 1;
            this.product.code = result.code;
          }else{

            this.product.code = result.code;
            this.product.stock = result.qte_sale;
            if (result.qte_sale < 1) {
              this.product.quantity = result.qte_sale;
            } else {
              this.product.quantity = 1;
            }
          }
        this.product.product_variant_id = result.product_variant_id;
        this.Get_Product_Details(result.id, result.product_variant_id);
      }

      this.search_input= '';
      this.$refs.product_autocomplete.value = "";
      this.product_filter = [];
    },

    //---------------------- Event Select Warehouse ------------------------------\\
    Selected_Warehouse(value) {
      this.search_input= '';
      this.product_filter = [];
      this.Get_Products_By_Warehouse(value);
      if (Array.isArray(this.details)) {
        for (const d of this.details) {
          if (d && d.is_batch_tracked) {
            this.$set(d, "batches", []);
            this.fetch_batches_for_detail(d);
          }
        }
      }
    },

      //------------------------------------ Get Products By Warehouse -------------------------\\

    Get_Products_By_Warehouse(id) {
      // Start the progress bar.
        NProgress.start();
        NProgress.set(0.1);
      axios
        .get("get_Products_by_warehouse/" + id + "?stock=" + 1 + "&product_service=" + 1 + "&product_combo=" + 1)
         .then(response => {
            this.products = response.data;
             NProgress.done();

            })
          .catch(error => {
          });
    },

    //----------------------------------------- Add product to order list -------------------------\\
    add_product() {
      if (this.details.length > 0) {
        this.Last_Detail_id();
      } else if (this.details.length === 0) {
        this.product.detail_id = 1;
      }

      this.details.push(this.product);

      if(this.product.is_imei){
        this.Modal_Updat_Detail(this.product);
      }

      const last = this.details[this.details.length - 1];
      if (last && last.is_batch_tracked) {
        this.fetch_batches_for_detail(last);
      }
    },

    //----------------------------------------- Batch handling -------------------------\\
    fetch_batches_for_detail(detail) {
      if (!detail) return;
      if (!("batches_loading" in detail)) this.$set(detail, "batches_loading", false);
      if (!("available_batches" in detail)) this.$set(detail, "available_batches", []);
      if (!Array.isArray(detail.batches)) this.$set(detail, "batches", []);
      if (!detail.is_batch_tracked) { this.$set(detail, "batches_loading", false); return; }
      const wid = this.quote && this.quote.warehouse_id;
      const productId = detail.product_id || detail.id;
      if (!wid || !productId) { this.$set(detail, "batches_loading", false); return; }
      const variantSeg = (detail.product_variant_id != null && detail.product_variant_id !== "") ? detail.product_variant_id : 0;
      const existingQtyById = {};
      for (const b of (Array.isArray(detail.batches) ? detail.batches : [])) {
        if (b && b.product_batch_id != null) {
          existingQtyById[b.product_batch_id] = (existingQtyById[b.product_batch_id] || 0) + (Number(b.qty) || 0);
        }
      }
      this.$set(detail, "batches_loading", true);
      axios
        .get(`batches_for_quotation/${productId}/${wid}/${variantSeg}`, { timeout: 15000 })
        .then(response => {
          const list = (response && response.data && Array.isArray(response.data.batches))
            ? response.data.batches.map(ab => ({
                ...ab,
                qty_available: (Number(ab.qty_available) || 0) + (existingQtyById[ab.id] || 0),
              }))
            : [];
          this.$set(detail, "available_batches", list);
          if (Array.isArray(detail.batches)) {
            for (const b of detail.batches) {
              if (b && b.product_batch_id != null) {
                const ab = list.find(x => x.id === b.product_batch_id);
                this.$set(b, "qty_available", ab ? (Number(ab.qty_available) || 0) : (existingQtyById[b.product_batch_id] || 0));
              }
            }
          }
        })
        .catch(() => { this.$set(detail, "available_batches", []); })
        .then(() => { this.$set(detail, "batches_loading", false); });
    },

    add_batch_to_detail(detail) {
      if (!Array.isArray(detail.batches)) this.$set(detail, "batches", []);
      detail.batches.push({
        product_batch_id: null, batch_no: "", expiry_date: null, qty_available: 0,
        qty: detail.batches.length === 0 ? (Number(detail.quantity) || 0) : 0,
      });
    },

    remove_batch_from_detail(detail, idx) {
      if (!Array.isArray(detail.batches)) return;
      detail.batches.splice(idx, 1);
    },

    on_batch_select(detail, idx, batchId) {
      const list = Array.isArray(detail.available_batches) ? detail.available_batches : [];
      const row = detail.batches[idx];
      if (!row) return;
      const ab = list.find(x => x.id === batchId);
      this.$set(row, "product_batch_id", ab ? ab.id : null);
      this.$set(row, "batch_no", ab ? ab.batch_no : "");
      this.$set(row, "expiry_date", ab ? ab.expiry_date : null);
      this.$set(row, "qty_available", ab ? (Number(ab.qty_available) || 0) : 0);
    },

    on_batch_qty_input(detail, idx, raw) {
      const row = detail.batches[idx];
      if (!row) return;
      const v = Number(raw);
      this.$set(row, "qty", Number.isFinite(v) ? v : 0);
    },

    batch_total_qty(detail) {
      const batches = Array.isArray(detail.batches) ? detail.batches : [];
      const total = batches.reduce((s, b) => s + (Number(b.qty) || 0), 0);
      return Math.round(total * 10000) / 10000;
    },

    batch_qty_mismatch(detail) {
      if (!detail || !detail.is_batch_tracked) return false;
      const batches = Array.isArray(detail.batches) ? detail.batches : [];
      if (batches.length === 0) return false;
      const total = this.batch_total_qty(detail);
      const target = Math.round((Number(detail.quantity) || 0) * 10000) / 10000;
      return Math.abs(total - target) > 0.01;
    },

    expiry_pill_style(date) {
      const base = { padding: "2px 8px", borderRadius: "999px", fontSize: "11px", fontWeight: "600" };
      if (!date) return Object.assign({}, base, { background: "#e5e7eb", color: "#374151" });
      const exp = new Date(date);
      const today = new Date();
      const diffDays = Math.floor((exp - today) / (1000 * 60 * 60 * 24));
      if (diffDays < 0) return Object.assign({}, base, { background: "#fee2e2", color: "#991b1b" });
      if (diffDays <= 30) return Object.assign({}, base, { background: "#fef3c7", color: "#92400e" });
      return Object.assign({}, base, { background: "#dcfce7", color: "#166534" });
    },

    batchBadgeTitle(detail) {
      if (!detail || !detail.is_batch_tracked) return "";
      const batches = Array.isArray(detail.batches) ? detail.batches : [];
      if (batches.length === 0) return this.$t("No_Batch_Selected_Yet") || "No batch selected yet";
      return batches.map(b => (b.batch_no || "?") + " × " + (Number(b.qty) || 0)).join(", ");
    },

    buildSubmitDetails() {
      return (this.details || []).map(d => {
        const out = Object.assign({}, d);
        delete out.available_batches;
        delete out.batches_loading;
        if (d.is_batch_tracked && Array.isArray(d.batches)) {
          out.batches = d.batches
            .filter(b => b && b.product_batch_id && Number(b.qty) > 0)
            .map(b => ({ product_batch_id: Number(b.product_batch_id), qty: Number(b.qty) || 0 }));
        } else {
          delete out.batches;
        }
        return out;
      });
    },

    //-----------------------------------Verified QTY ------------------------------\\
    Verified_Qty(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id === id) {
          if (isNaN(detail.quantity)) {
            this.details[i].quantity = detail.stock;
          }

          if (detail.etat == "new" && detail.quantity > detail.stock) {
            this.makeToast("warning", this.$t("LowStock"), this.$t("Warning"));
            this.details[i].quantity = detail.stock;
          } else if (
            detail.etat == "current" &&
            detail.quantity > detail.stock + detail.qte_copy
          ) {
            this.makeToast("warning", this.$t("LowStock"), this.$t("Warning"));
            this.details[i].quantity = detail.qte_copy;
          } else {
            this.details[i].quantity = detail.quantity;
          }
        }
      }

      this.$forceUpdate();
      this.Calcul_Total();
    },

    //-----------------------------------increment QTY ------------------------------\\

    increment(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          if (detail.etat == "new" && detail.quantity + 1 > detail.stock) {
            this.makeToast("warning", this.$t("LowStock"), this.$t("Warning"));
          } else if (
            detail.etat == "current" &&
            detail.quantity + 1 > detail.stock + detail.qte_copy
          ) {
            this.makeToast("warning", this.$t("LowStock"), this.$t("Warning"));
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
            if (detail.etat == "new" && detail.quantity - 1 > detail.stock) {
              this.makeToast(
                "warning",
                this.$t("LowStock"),
                this.$t("Warning")
              );
            } else if (
              detail.etat == "current" &&
              detail.quantity - 1 > detail.stock + detail.qte_copy
            ) {
              this.makeToast(
                "warning",
                this.$t("LowStock"),
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

    //---------- keyup OrderTax
    keyup_OrderTax() {
      if (isNaN(this.quote.tax_rate)) {
        this.quote.tax_rate = 0;
      } else if(this.quote.tax_rate == ''){
        this.quote.tax_rate = 0;
        this.Calcul_Total();
      }else {
        this.Calcul_Total();
      }
    },

    //---------- keyup Discount

    keyup_Discount() {
      if (isNaN(this.quote.discount)) {
        this.quote.discount = 0;
      } else if(this.quote.discount == ''){
        this.quote.discount = 0;
        this.Calcul_Total();
      }else {
        this.Calcul_Total();
      }
    },

    //---------- keyup Shipping

    keyup_Shipping() {
      if (isNaN(this.quote.shipping)) {
        this.quote.shipping = 0;
      } else if(this.quote.shipping == ''){
        this.quote.shipping = 0;
        this.Calcul_Total();
      }else {
        this.Calcul_Total();
      }
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
        this.total - this.quote.discount
      );
      this.quote.TaxNet = parseFloat(
        (total_without_discount * this.quote.tax_rate) / 100
      );
      this.GrandTotal = parseFloat(
        total_without_discount + this.quote.TaxNet + this.quote.shipping
      );

      var grand_total =  this.GrandTotal.toFixed(this.priceDecimals);
      this.GrandTotal = parseFloat(grand_total);
    },

    //-----------------------------------Delete Detail Product ------------------------------\\
    delete_Product_Detail(id) {
      for (var i = 0; i < this.details.length; i++) {
        if (id === this.details[i].detail_id) {
          this.details.splice(i, 1);
          this.Calcul_Total();
        }
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
            this.details[i].quantity == "" ||
            this.details[i].quantity === 0
          ) {
            count += 1;
          }
        }

        if (count > 0) {
          this.makeToast("warning", this.$t("AddQuantity"), this.$t("Warning"));
          return false;
        }
        if (this.hasBatchValidationErrors) {
          this.makeToast(
            "danger",
            this.firstBatchErrorMessage || (this.$t("Total_batch_qty_mismatch") || "Batch quantities are invalid"),
            this.$t("Failed") || "Failed"
          );
          return false;
        }
        return true;
      }
    },

    //--------------------------------- Update Quotation -------------------------\\
    Update_Quotation() {
      if (this.verifiedForm()) {
        this.SubmitProcessing = true;
        // Start the progress bar.
        NProgress.start();
        NProgress.set(0.1);
        let id = this.$route.params.id;
        axios
          .put(`quotations/${id}`, {
            client_id: this.quote.client_id,
            GrandTotal: this.GrandTotal,
            warehouse_id: this.quote.warehouse_id,
            statut: this.quote.statut,
            notes: this.quote.notes,
            date: this.quote.date,
            tax_rate: this.quote.tax_rate?this.quote.tax_rate:0,
            TaxNet: this.quote.TaxNet?this.quote.TaxNet:0,
            discount: this.quote.discount?this.quote.discount:0,
            shipping: this.quote.shipping?this.quote.shipping:0,
            details: this.buildSubmitDetails()
          })
          .then(response => {
            // Complete the animation of theprogress bar.
            NProgress.done();
            this.makeToast(
              "success",
              this.$t("Successfully_Updated"),
              this.$t("Success")
            );

            this.SubmitProcessing = false;
            this.$router.push({ name: "index_quotation" });
          })
          .catch(error => {
            // Complete the animation of theprogress bar.
            NProgress.done();
            this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
            this.SubmitProcessing = false;
          });
      }
    },

    //-------------------------------- Get Last Detail Id -------------------------\\
    Last_Detail_id() {
      this.product.detail_id = 0;
      var len = this.details.length;
      this.product.detail_id = this.details[len - 1].detail_id + 1;
    },

    //---------------------------------Get Product Details ------------------------\\

    Get_Product_Details(product_id, variant_id) {
      axios.get("/show_product_data/" + product_id +"/"+ variant_id).then(response => {
        this.product.del = 0;
        this.product.id = 0;
        this.product.discount           = response.data.discount;
        this.product.DiscountNet        = response.data.DiscountNet;
        this.product.discount_Method    = response.data.discount_method;
        this.product.etat = "new";
        this.product.product_id = response.data.id;
        this.product.name = response.data.name;
        this.product.product_type = response.data.product_type;
        this.product.Net_price = response.data.Net_price;
        this.product.Unit_price = response.data.Unit_price;
        this.product.taxe = response.data.tax_price;
        this.product.tax_method = response.data.tax_method;
        this.product.tax_percent = response.data.tax_percent;
        this.product.unitSale = response.data.unitSale;
        this.product.sale_unit_id = response.data.sale_unit_id;
        this.product.is_imei = response.data.is_imei;
        this.product.imei_number = '';
        this.$set(this.product, "is_batch_tracked", !!response.data.is_batch_tracked);
        this.$set(this.product, "batches", []);
        this.$set(this.product, "available_batches", []);
        this.$set(this.product, "batches_loading", false);
        this.add_product();
        this.Calcul_Total();
      });
    },

    //---------------------------------------Get Elements ------------------------------\\
    GetElements() {
      let id = this.$route.params.id;
      axios
        .get(`quotations/${id}/edit`)
        .then(response => {
          this.quote = response.data.quote;
          this.details = response.data.details;
          this.clients = response.data.clients;
          this.warehouses = response.data.warehouses;
          this.syncValidators();
          this.Get_Products_By_Warehouse(this.quote.warehouse_id);
          this.Calcul_Total();
          // Pharmacy: hydrate available batches for preloaded batch-tracked lines.
          if (Array.isArray(this.details)) {
            for (const d of this.details) {
              if (d && d.is_batch_tracked) {
                this.fetch_batches_for_detail(d);
              }
            }
          }
          this.isLoading = false;
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
.pxqf { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxqf { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxqf__pad { padding: var(--pxn-space-6) 0; }
// PxCard clips absolutely-positioned children (the product autocomplete list) —
// this form's card must let it overflow.
.pxqf__card { margin-top: var(--pxn-space-5); overflow: visible; }

.pxqf__grid3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 820px) { .pxqf__grid3 { grid-template-columns: minmax(0, 1fr); } }
.pxqf__grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 620px) { .pxqf__grid2 { grid-template-columns: minmax(0, 1fr); } }
.pxqf__grid2-full { grid-column: 1 / -1; }
.pxqf__gap { margin-top: var(--pxn-space-6); }
.pxqf__lineshead { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); margin-bottom: var(--pxn-space-3); }

// Product autocomplete
.pxqf__search { position: relative; }
.pxqf-ac { position: relative; display: flex; align-items: stretch; gap: var(--pxn-space-2); }
.pxqf-ac__scan { flex: none; width: 40px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-sm); background: var(--pxn-surface); color: var(--pxn-ink-2); cursor: pointer; }
.pxqf-ac__scan:hover { background: var(--pxn-surface-2); color: var(--pxn-ink); }
.pxqf-ac__input { flex: 1; height: 40px; padding: 0 var(--pxn-space-4); border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-sm); background: var(--pxn-surface); color: var(--pxn-ink); font-size: var(--pxn-fs-sm); }
.pxqf-ac__input:focus { outline: none; border-color: var(--pxn-primary); box-shadow: 0 0 0 3px var(--pxn-primary-soft); }
.pxqf-ac__list { position: absolute; top: calc(100% + 4px); left: 48px; right: 0; z-index: 60; margin: 0; padding: var(--pxn-space-2); list-style: none; max-height: 280px; overflow-y: auto; background: var(--pxn-surface); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); box-shadow: var(--pxn-shadow-lg); }
.pxqf-ac__item { padding: var(--pxn-space-2) var(--pxn-space-3); border-radius: var(--pxn-radius-sm); font-size: var(--pxn-fs-sm); color: var(--pxn-ink); cursor: pointer; }
.pxqf-ac__item:hover { background: var(--pxn-primary-soft); color: var(--pxn-primary); }

// Line-items table
.pxqf-tbl__wrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); overflow-x: auto; }
.pxqf-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxqf-tbl th { padding: var(--pxn-space-3) var(--pxn-space-4); text-align: left; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap; }
.pxqf-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink); vertical-align: top; }
.pxqf-tbl .is-right { text-align: right; }
.pxqf-tbl .is-center { text-align: center; }
.pxqf__empty { text-align: center; color: var(--pxn-ink-3); }
.pxqf__badgeline { margin-left: var(--pxn-space-2); }
.pxqf__rowactions { white-space: nowrap; }
.pxqf__ico { cursor: pointer; width: 18px; height: 18px; }
.pxqf__ico.is-edit { color: var(--pxn-success); margin-right: var(--pxn-space-3); }
.pxqf__ico.is-del { color: var(--pxn-danger); }

.pxqf__stepper { display: inline-flex; align-items: stretch; border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-sm); overflow: hidden; }
.pxqf__step { border: 0; width: 30px; background: var(--pxn-primary); color: var(--pxn-primary-contrast); font-size: var(--pxn-fs-body); cursor: pointer; }
.pxqf__step:hover { background: var(--pxn-primary-hover); }
.pxqf__stepinput { width: 64px; text-align: center; border: 0; border-left: 1px solid var(--pxn-border-control); border-right: 1px solid var(--pxn-border-control); background: var(--pxn-surface); color: var(--pxn-ink); font-size: var(--pxn-fs-sm); }
.pxqf__stepinput:focus { outline: none; }

// Batch allocation sub-row
.pxqf__batchrow td { background: transparent; padding: 0 var(--pxn-space-3) var(--pxn-space-4) !important; border-bottom: 0; }
.pxqf__batchbox { background: var(--pxn-info-soft); border: 1px solid var(--pxn-info-border); border-left: 4px solid var(--pxn-info); border-radius: var(--pxn-radius-md); padding: var(--pxn-space-4) var(--pxn-space-5); }
.pxqf__batchhead { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--pxn-space-3); }
.pxqf__batchtitle { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxqf__batchsub { font-weight: var(--pxn-fw-regular); color: var(--pxn-ink-2); margin-left: var(--pxn-space-2); }
.pxqf__batchmuted { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxqf-batchtbl { width: 100%; border-collapse: collapse; background: var(--pxn-surface); border-radius: var(--pxn-radius-sm); overflow: hidden; }
.pxqf-batchtbl th { padding: var(--pxn-space-2) var(--pxn-space-3); text-align: left; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink-3); background: var(--pxn-surface-2); }
.pxqf-batchtbl td { padding: var(--pxn-space-2) var(--pxn-space-3); border-top: 1px solid var(--pxn-border); vertical-align: middle; }
.pxqf__batchqty { width: 100%; height: 32px; padding: 0 var(--pxn-space-3); border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-sm); background: var(--pxn-surface); color: var(--pxn-ink); font-size: var(--pxn-fs-sm); }
.pxqf__batchwarn { margin-top: var(--pxn-space-3); padding: var(--pxn-space-2) var(--pxn-space-3); background: var(--pxn-warning-soft); color: var(--pxn-warning-ink); border: 1px solid var(--pxn-warning-border); border-radius: var(--pxn-radius-sm); font-size: var(--pxn-fs-xs); }

.pxqf__totals { display: flex; justify-content: flex-end; }
.pxqf-totbl { min-width: 300px; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxqf-totbl td { padding: var(--pxn-space-2) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink-2); }
.pxqf-totbl td.is-right { text-align: right; color: var(--pxn-ink); }
.pxqf__totrow td { font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }

.pxqf__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
</style>
