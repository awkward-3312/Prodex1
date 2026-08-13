<template>
  <div class="pos-return-modal">
    <b-modal
      ref="returnModal"
      hide-footer
      hide-header
      size="xl"
      id="pos_return_modal"
      centered
      scrollable
      @hidden="resetForm"
      body-class="p-0"
    >
      <div style="display: flex; flex-direction: column; max-height: 82vh;">

        <!-- Header -->
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #e6e6ec;">
          <div style="display: flex; align-items: center; gap: 10px;">
            <span style="width: 34px; height: 34px; border-radius: 9px; background: #f5f3fd; color: #6f53d9; display: inline-flex; align-items: center; justify-content: center;">
              <lucide-icon name="undo" />
            </span>
            <h2 style="margin: 0; font-size: 16px; font-weight: 700; color: #1f1f2c;">
              {{ $t('POS_Return') || 'Product Return' }}
            </h2>
          </div>
          <button type="button" @click="$refs.returnModal.hide()"
            style="width: 30px; height: 30px; border-radius: 8px; border: 1px solid #e6e6ec; background: #fff; color: #54546a; display: inline-flex; align-items: center; justify-content: center; cursor: pointer;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>
        </div>

        <!-- Body -->
        <div style="padding: 16px 20px; overflow-y: auto;">

          <!-- STEP 1 — find the original sale -->
          <div v-if="step === 1">
            <div style="font-size: 9px; font-weight: 700; letter-spacing: 0.08em; color: #8d8da0; margin-bottom: 6px; text-transform: uppercase;">
              {{ $t('Search_Sale_To_Return') || 'Search the original sale by reference, customer or amount' }}
            </div>
            <div style="display: flex; gap: 8px; margin-bottom: 14px;">
              <input
                v-model="search"
                type="text"
                ref="searchInput"
                @keyup.enter="searchSales"
                :placeholder="$t('Sale_Ref') || 'Sale reference'"
                style="flex: 1; height: 40px; padding: 0 12px; border: 1px solid #e6e6ec; border-radius: 8px; font-size: 13px; color: #1f1f2c; outline: none;"
              />
              <button type="button" @click="searchSales" :disabled="searching"
                style="height: 40px; padding: 0 18px; background: #6f53d9; color: #fff; border: 0; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                <lucide-icon name="search" />
                <span>{{ searching ? ($t('Loading') || 'Loading...') : ($t('Search') || 'Search') }}</span>
              </button>
            </div>

            <div v-if="searching" style="text-align: center; padding: 24px; color: #8d8da0;">
              <div class="spinner spinner-primary"></div>
            </div>

            <div v-else-if="searched && sales.length === 0" style="text-align: center; padding: 32px; color: #8d8da0; font-size: 13px;">
              {{ $t('No_Sales_Found') || 'No matching sales found' }}
            </div>

            <div v-else-if="sales.length" style="border: 1px solid #e6e6ec; border-radius: 10px; overflow: hidden;">
              <div
                v-for="sale in sales"
                :key="sale.id"
                @click="selectSale(sale)"
                class="pos-return-sale-row"
                style="display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 14px; border-bottom: 1px solid #f0f0f4; cursor: pointer; transition: background 120ms ease;">
                <div style="min-width: 0;">
                  <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 13px; font-weight: 700; color: #1f1f2c; font-family: 'JetBrains Mono', monospace;">{{ sale.Ref }}</span>
                    <span v-if="sale.sale_has_return === 'yes'" style="font-size: 10px; font-weight: 600; color: #a86413; background: #fdf1dc; padding: 1px 7px; border-radius: 99px;">
                      {{ $t('Already_Returned') || 'Has return' }}
                    </span>
                  </div>
                  <div style="font-size: 11px; color: #8d8da0; margin-top: 2px;">
                    {{ sale.date }} · {{ sale.client_name }} · {{ sale.warehouse_name }}
                  </div>
                </div>
                <div style="text-align: right; flex-shrink: 0;">
                  <div style="font-size: 13px; font-weight: 700; color: #1f1f2c; font-family: 'JetBrains Mono', monospace;">{{ money(sale.GrandTotal) }}</div>
                  <div style="font-size: 11px; color: #6f53d9; font-weight: 600;">{{ $t('Select') || 'Select' }} →</div>
                </div>
              </div>
            </div>
          </div>

          <!-- STEP 2 — choose the lines & quantities to return -->
          <div v-else-if="step === 2">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
              <button type="button" @click="backToSearch"
                style="display: inline-flex; align-items: center; gap: 6px; background: transparent; border: 0; color: #6f53d9; font-size: 13px; font-weight: 600; cursor: pointer; padding: 0;">
                <lucide-icon name="arrow-left" /> {{ $t('Back') || 'Back' }}
              </button>
              <div style="font-size: 13px; color: #54546a;">
                {{ $t('Sale_Ref') || 'Sale Ref' }}:
                <strong style="font-family: 'JetBrains Mono', monospace; color: #1f1f2c;">{{ sale_return.sale_ref }}</strong>
              </div>
            </div>

            <div style="font-size: 11px; color: #a83232; background: #fef2f2; border-radius: 8px; padding: 8px 12px; margin-bottom: 12px;">
              {{ $t('products_refunded_alert') || 'Enter the quantity to return for each line. Stock will be returned to the warehouse.' }}
            </div>

            <div style="border: 1px solid #e6e6ec; border-radius: 10px; overflow: hidden;">
              <table class="table" style="margin: 0; font-size: 13px;">
                <thead style="background: #f7f7fb;">
                  <tr>
                    <th style="font-size: 10px; letter-spacing: 0.05em; color: #8d8da0; text-transform: uppercase;">{{ $t('ProductName') || 'Product' }}</th>
                    <th style="font-size: 10px; letter-spacing: 0.05em; color: #8d8da0; text-transform: uppercase; text-align: right;">{{ $t('Net_Unit_Price') || 'Unit Price' }}</th>
                    <th style="font-size: 10px; letter-spacing: 0.05em; color: #8d8da0; text-transform: uppercase; text-align: center;">{{ $t('Quantity_sold') || 'Qty sold' }}</th>
                    <th style="font-size: 10px; letter-spacing: 0.05em; color: #8d8da0; text-transform: uppercase; text-align: center; width: 150px;">{{ $t('Qty_return') || 'Qty return' }}</th>
                    <th style="font-size: 10px; letter-spacing: 0.05em; color: #8d8da0; text-transform: uppercase; text-align: right;">{{ $t('SubTotal') || 'Subtotal' }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="detail in details" :key="detail.detail_id">
                    <td>
                      <div style="font-weight: 600; color: #1f1f2c;">{{ detail.name }}</div>
                      <div style="font-size: 11px; color: #8d8da0; font-family: 'JetBrains Mono', monospace;">{{ detail.code }}</div>
                      <span v-if="detail.is_batch_tracked" style="display: inline-block; margin-top: 3px; font-size: 10px; font-weight: 600; color: #4f46e5; background: #eef2ff; padding: 1px 7px; border-radius: 99px;">
                        {{ $t('Batches') || 'Batches' }} · Auto
                      </span>
                    </td>
                    <td style="text-align: right; font-family: 'JetBrains Mono', monospace; color: #54546a;">{{ money(detail.Net_price) }}</td>
                    <td style="text-align: center;">
                      <span style="font-size: 11px; font-weight: 600; color: #a86413; background: #fdf1dc; padding: 2px 8px; border-radius: 99px;">{{ detail.sale_quantity }} {{ detail.unitSale }}</span>
                    </td>
                    <td style="text-align: center;">
                      <div style="display: inline-flex; align-items: center; border: 1px solid #e6e6ec; border-radius: 6px; height: 32px; background: #fff;">
                        <button type="button" @click="decrement(detail)" style="width: 28px; height: 30px; background: transparent; border: 0; color: #54546a; font-size: 16px; cursor: pointer;">−</button>
                        <input v-model.number="detail.quantity" type="text" @keyup="verifiedQty(detail)" @change="verifiedQty(detail)"
                          style="width: 44px; height: 100%; border: 0; text-align: center; font-size: 13px; font-family: 'JetBrains Mono', monospace; background: transparent; outline: none;" />
                        <button type="button" @click="increment(detail)" style="width: 28px; height: 30px; background: transparent; border: 0; color: #54546a; font-size: 16px; cursor: pointer;">+</button>
                      </div>
                    </td>
                    <td style="text-align: right; font-family: 'JetBrains Mono', monospace; font-weight: 600; color: #1f1f2c;">{{ money(detail.subtotal) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Charges + totals -->
            <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 16px; margin-top: 16px;">
              <div style="display: grid; grid-template-columns: repeat(3, minmax(90px, 120px)); gap: 8px;">
                <label style="display: block;">
                  <div style="font-size: 9px; font-weight: 700; letter-spacing: 0.08em; color: #8d8da0; margin-bottom: 4px;">{{ $t('OrderTax') || 'Tax' }} %</div>
                  <input v-model.number="sale_return.tax_rate" @keyup="keyup_OrderTax" type="text" placeholder="0"
                    style="width: 100%; height: 32px; padding: 0 8px; border: 1px solid #e6e6ec; border-radius: 6px; font-size: 12px; font-family: 'JetBrains Mono', monospace; outline: none;" />
                </label>
                <label style="display: block;">
                  <div style="font-size: 9px; font-weight: 700; letter-spacing: 0.08em; color: #8d8da0; margin-bottom: 4px;">{{ $t('Discount') || 'Discount' }}</div>
                  <input v-model.number="sale_return.discount" @keyup="keyup_Discount" type="text" placeholder="0"
                    style="width: 100%; height: 32px; padding: 0 8px; border: 1px solid #e6e6ec; border-radius: 6px; font-size: 12px; font-family: 'JetBrains Mono', monospace; outline: none;" />
                </label>
                <label style="display: block;">
                  <div style="font-size: 9px; font-weight: 700; letter-spacing: 0.08em; color: #8d8da0; margin-bottom: 4px;">{{ $t('Shipping') || 'Shipping' }}</div>
                  <input v-model.number="sale_return.shipping" @keyup="keyup_Shipping" type="text" placeholder="0"
                    style="width: 100%; height: 32px; padding: 0 8px; border: 1px solid #e6e6ec; border-radius: 6px; font-size: 12px; font-family: 'JetBrains Mono', monospace; outline: none;" />
                </label>
              </div>

              <div style="min-width: 220px; background: #f7f7fb; border-radius: 10px; padding: 12px 14px;">
                <div style="display: flex; justify-content: space-between; padding: 3px 0; font-size: 12px;">
                  <span style="color: #8d8da0;">{{ $t('OrderTax') || 'Tax' }}</span>
                  <span style="font-family: 'JetBrains Mono', monospace; color: #54546a;">{{ money(sale_return.TaxNet) }}</span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 3px 0; font-size: 12px;">
                  <span style="color: #8d8da0;">{{ $t('Discount') || 'Discount' }}</span>
                  <span style="font-family: 'JetBrains Mono', monospace; color: #d64545;">{{ money(sale_return.discount) }}</span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 3px 0; font-size: 12px;">
                  <span style="color: #8d8da0;">{{ $t('Shipping') || 'Shipping' }}</span>
                  <span style="font-family: 'JetBrains Mono', monospace; color: #54546a;">{{ money(sale_return.shipping) }}</span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 8px 0 0; margin-top: 6px; border-top: 1px solid #e6e6ec; font-size: 14px;">
                  <span style="font-weight: 700; color: #1f1f2c;">{{ $t('Total') || 'Total' }}</span>
                  <span style="font-weight: 700; font-family: 'JetBrains Mono', monospace; color: #1f1f2c;">{{ money(GrandTotal) }}</span>
                </div>
              </div>
            </div>

            <div style="margin-top: 14px;">
              <div style="font-size: 9px; font-weight: 700; letter-spacing: 0.08em; color: #8d8da0; margin-bottom: 4px; text-transform: uppercase;">{{ $t('Note') || 'Note' }}</div>
              <textarea v-model="sale_return.notes" rows="2" :placeholder="$t('Afewwords') || 'A few words...'"
                style="width: 100%; padding: 8px 10px; border: 1px solid #e6e6ec; border-radius: 8px; font-size: 13px; outline: none; resize: vertical;"></textarea>
            </div>
          </div>
        </div>

        <!-- Footer (step 2 only) -->
        <div v-if="step === 2" style="display: flex; align-items: center; justify-content: flex-end; gap: 10px; padding: 14px 20px; border-top: 1px solid #e6e6ec; background: #fff;">
          <button type="button" @click="$refs.returnModal.hide()"
            style="height: 42px; padding: 0 18px; background: #fff; color: #54546a; border: 1px solid #e6e6ec; border-radius: 9px; font-size: 14px; font-weight: 600; cursor: pointer;">
            {{ $t('Cancel') || 'Cancel' }}
          </button>
          <button type="button" @click="submitReturn" :disabled="SubmitProcessing"
            style="height: 42px; padding: 0 24px; background: #6f53d9; color: #fff; border: 0; border-radius: 9px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 14px rgba(111,83,217,0.32);"
            :style="{ opacity: SubmitProcessing ? 0.6 : 1 }">
            <lucide-icon name="check" />
            <span>{{ SubmitProcessing ? ($t('pos.Processing') || 'Processing...') : ($t('Confirm_Return') || 'Confirm Return') }}</span>
          </button>
        </div>
      </div>
    </b-modal>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import { getPriceDecimals } from "../../../utils/priceFormat";

export default {
  name: "PosReturnModal",

  props: {
    warehouseId: { type: [Number, String], default: "" },
    currency: { type: String, default: "" },
  },

  data() {
    return {
      step: 1,
      search: "",
      searching: false,
      searched: false,
      sales: [],
      SubmitProcessing: false,
      details: [],
      sale_return: {
        date: new Date().toISOString().slice(0, 10),
        statut: "received",
        notes: "",
        client_id: "",
        warehouse_id: "",
        sale_id: "",
        sale_ref: "",
        tax_rate: 0,
        TaxNet: 0,
        shipping: 0,
        discount: 0,
      },
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
  },

  methods: {
    // Open the modal (called by the POS parent).
    open() {
      this.resetForm();
      this.$refs.returnModal.show();
      this.$nextTick(() => {
        if (this.$refs.searchInput) this.$refs.searchInput.focus();
      });
    },

    resetForm() {
      this.step = 1;
      this.search = "";
      this.searching = false;
      this.searched = false;
      this.sales = [];
      this.SubmitProcessing = false;
      this.details = [];
      this.total = 0;
      this.GrandTotal = 0;
      this.sale_return = {
        date: new Date().toISOString().slice(0, 10),
        statut: "received",
        notes: "",
        client_id: "",
        warehouse_id: "",
        sale_id: "",
        sale_ref: "",
        tax_rate: 0,
        TaxNet: 0,
        shipping: 0,
        discount: 0,
      };
    },

    money(value) {
      const n = parseFloat(value) || 0;
      const cur = this.currency || (this.currentUser && this.currentUser.currency) || "";
      return cur + " " + n.toFixed(this.priceDecimals);
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    },

    // ---- Step 1: search the original sale -----------------------------------
    searchSales() {
      this.searching = true;
      this.searched = false;
      axios
        .get("returns/sale/pos_search_sales", {
          params: {
            limit: 10,
            search: this.search,
          },
        })
        .then((response) => {
          this.sales = response.data.sales || [];
          this.searching = false;
          this.searched = true;
        })
        .catch(() => {
          this.searching = false;
          this.searched = true;
          this.makeToast("danger", this.$t("InvalidData") || "Invalid data", this.$t("Failed") || "Failed");
        });
    },

    // ---- Step 2: load the sale lines prepared for return --------------------
    selectSale(sale) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(`returns/sale/create_sell_return/${sale.id}`)
        .then((response) => {
          this.details = response.data.details;
          this.sale_return = response.data.sale_return;
          this.sale_return.date = new Date().toISOString().slice(0, 10);
          this.sale_return.notes = "";
          this.sale_return.statut = "received";
          this.calculTotal();
          this.step = 2;
          NProgress.done();
        })
        .catch(() => {
          NProgress.done();
          this.makeToast("danger", this.$t("InvalidData") || "Invalid data", this.$t("Failed") || "Failed");
        });
    },

    backToSearch() {
      this.step = 1;
    },

    // ---- Quantity guards (mirror create_sale_return.vue) -------------------
    verifiedQty(detail) {
      if (isNaN(detail.quantity) || detail.quantity === "") {
        detail.quantity = 0;
      } else if (detail.quantity > detail.sale_quantity) {
        this.makeToast("warning", this.$t("qty_return_is_greater_than_qty_sold") || "Qty return is greater than Qty sold", this.$t("Warning") || "Warning");
        detail.quantity = detail.sale_quantity;
      } else if (detail.quantity < 0) {
        detail.quantity = 0;
      }
      this.calculTotal();
      this.$forceUpdate();
    },

    increment(detail) {
      if (detail.quantity + 1 > detail.sale_quantity) {
        this.makeToast("warning", this.$t("qty_return_is_greater_than_qty_sold") || "Qty return is greater than Qty sold", this.$t("Warning") || "Warning");
      } else {
        detail.quantity = (parseFloat(detail.quantity) || 0) + 1;
      }
      this.$forceUpdate();
      this.calculTotal();
    },

    decrement(detail) {
      if ((parseFloat(detail.quantity) || 0) - 1 >= 0) {
        detail.quantity = (parseFloat(detail.quantity) || 0) - 1;
      }
      this.$forceUpdate();
      this.calculTotal();
    },

    // ---- Totals (identical maths to create_sale_return.vue) ----------------
    calculTotal() {
      this.total = 0;
      for (let i = 0; i < this.details.length; i++) {
        const tax = this.details[i].taxe * this.details[i].quantity;
        this.details[i].subtotal = parseFloat(
          this.details[i].quantity * this.details[i].Net_price + tax
        );
        this.total = parseFloat(this.total + this.details[i].subtotal);
      }

      const total_without_discount = parseFloat(this.total - this.sale_return.discount);
      this.sale_return.TaxNet = parseFloat(
        (total_without_discount * this.sale_return.tax_rate) / 100
      );
      this.GrandTotal = parseFloat(
        total_without_discount + this.sale_return.TaxNet + this.sale_return.shipping
      );
      this.GrandTotal = parseFloat(this.GrandTotal.toFixed(this.priceDecimals));
    },

    keyup_OrderTax() {
      if (isNaN(this.sale_return.tax_rate) || this.sale_return.tax_rate === "") {
        this.sale_return.tax_rate = 0;
      }
      this.calculTotal();
    },
    keyup_Discount() {
      if (isNaN(this.sale_return.discount) || this.sale_return.discount === "") {
        this.sale_return.discount = 0;
      }
      this.calculTotal();
    },
    keyup_Shipping() {
      if (isNaN(this.sale_return.shipping) || this.sale_return.shipping === "") {
        this.sale_return.shipping = 0;
      }
      this.calculTotal();
    },

    // ---- Validate at least one returned line --------------------------------
    verifiedForm() {
      if (this.details.length <= 0) {
        this.makeToast("warning", this.$t("AddProductToList") || "Add a product to the list", this.$t("Warning") || "Warning");
        return false;
      }
      const returned = this.details.filter((d) => (parseFloat(d.quantity) || 0) > 0);
      if (returned.length === 0) {
        this.makeToast("warning", this.$t("Please_add_return_quantity") || "Please add a return quantity", this.$t("Warning") || "Warning");
        return false;
      }
      return true;
    },

    // ---- Submit -------------------------------------------------------------
    submitReturn() {
      if (!this.verifiedForm()) return;

      this.SubmitProcessing = true;
      NProgress.start();
      NProgress.set(0.1);

      // Only send lines that are actually being returned.
      const details = this.details.filter((d) => (parseFloat(d.quantity) || 0) > 0);

      axios
        .post("returns/sale", {
          date: this.sale_return.date,
          client_id: this.sale_return.client_id,
          sale_id: this.sale_return.sale_id,
          warehouse_id: this.sale_return.warehouse_id,
          statut: "received",
          notes: this.sale_return.notes,
          tax_rate: this.sale_return.tax_rate ? this.sale_return.tax_rate : 0,
          TaxNet: this.sale_return.TaxNet ? this.sale_return.TaxNet : 0,
          discount: this.sale_return.discount ? this.sale_return.discount : 0,
          shipping: this.sale_return.shipping ? this.sale_return.shipping : 0,
          GrandTotal: this.GrandTotal,
          details: details,
        })
        .then(() => {
          NProgress.done();
          this.SubmitProcessing = false;
          this.makeToast("success", this.$t("Successfully_Created") || "Successfully created", this.$t("Success") || "Success");
          this.$emit("return-success");
          this.$refs.returnModal.hide();
        })
        .catch(() => {
          NProgress.done();
          this.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData") || "Invalid data", this.$t("Failed") || "Failed");
        });
    },
  },
};
</script>

<style scoped>
.pos-return-sale-row:hover {
  background: #f5f3fd;
}
.pos-return-modal table.table th,
.pos-return-modal table.table td {
  vertical-align: middle;
  padding: 10px 12px;
}
</style>
