<template>
  <div class="px-next pxsrd">
    <px-page-header
      :title="isLoading ? $t('ReturnDetail') : ($t('ReturnDetail') + ' · ' + (sale_return.Ref || ''))"
      :breadcrumbs="[{ label: $t('ListReturns') }, { label: $t('ReturnDetail') }]"
    >
      <template #actions>
        <px-button
          v-if="!isLoading && currentUserPermissions && currentUserPermissions.includes('Sale_Returns_edit')"
          variant="secondary" icon="pencil"
          @click="$router.push('/app/sale_return/edit/' + $route.params.id + '/' + sale_return.sale_id)"
        >{{ $t('EditReturn') }}</px-button>
        <px-button v-if="!isLoading" variant="secondary" icon="file-text" @click="Return_PDF">PDF</px-button>
        <px-button v-if="!isLoading" variant="secondary" icon="printer" @click="print">{{ $t('print') }}</px-button>
        <px-button
          v-if="!isLoading && currentUserPermissions && currentUserPermissions.includes('Sale_Returns_delete')"
          variant="danger" icon="x" @click="Delete_Return"
        >{{ $t('Del') }}</px-button>
      </template>
    </px-page-header>

    <div v-if="isLoading" class="pxsrd__pad">
      <px-skeleton variant="lines" :rows="10" />
    </div>

    <px-card v-else flush class="pxsrd__card">
      <div class="invoice" id="print_Invoice">
        <div class="invoice-print pxsrd__doc">
          <h4 class="pxsrd__doctitle">{{ $t('ReturnDetail') }} : {{ sale_return.Ref }}</h4>
          <hr class="pxsrd__rule" />

          <div class="pxsrd__cols">
            <div class="pxsrd__col">
              <h5 class="pxsrd__colhead">{{ $t('Customer_Info') }}</h5>
              <div>{{ sale_return.client_name }}</div>
              <div>{{ sale_return.client_email }}</div>
              <div>{{ sale_return.client_phone }}</div>
              <div>{{ sale_return.client_adr }}</div>
            </div>
            <div class="pxsrd__col">
              <h5 class="pxsrd__colhead">{{ $t('Company_Info') }}</h5>
              <div>{{ company.CompanyName }}</div>
              <div>{{ company.email }}</div>
              <div>{{ company.CompanyPhone }}</div>
              <div>{{ company.CompanyAdress }}</div>
            </div>
            <div class="pxsrd__col">
              <h5 class="pxsrd__colhead">{{ $t('Return_Info') }}</h5>
              <div>{{ $t('Reference') }} : {{ sale_return.Ref }}</div>
              <div>{{ $t('Sale_Ref') }} : {{ sale_return.sale_ref }}</div>
              <div>
                {{ $t('PaymentStatus') }} :
                <px-badge :tone="sale_return.payment_status === 'paid' ? 'success' : (sale_return.payment_status === 'partial' ? 'info' : 'warning')">
                  {{ sale_return.payment_status === 'paid' ? $t('Paid') : (sale_return.payment_status === 'partial' ? $t('partial') : $t('Unpaid')) }}
                </px-badge>
              </div>
              <div>{{ $t('warehouse') }} : {{ sale_return.warehouse }}</div>
              <div>
                {{ $t('Status') }} :
                <px-badge :tone="sale_return.statut === 'received' ? 'success' : 'info'">
                  {{ sale_return.statut === 'received' ? $t('Received') : $t('Pending') }}
                </px-badge>
              </div>
            </div>
          </div>

          <div class="pxsrd__lines">
            <h5 class="pxsrd__colhead">{{ $t('list_product_returns') }}</h5>
            <px-alert tone="danger" bare class="pxsrd__refund">{{ $t('products_refunded_alert') }}</px-alert>
            <div class="pxsrd-tbl__wrap pxn-scroll">
              <table class="pxsrd-tbl">
                <thead>
                  <tr>
                    <th>{{ $t('ProductName') }}</th>
                    <th class="is-right">{{ $t('Net_Unit_Price') }}</th>
                    <th class="is-right">{{ $t('Qty_return') }}</th>
                    <th class="is-right">{{ $t('UnitPrice') }}</th>
                    <th class="is-right">{{ $t('Discount') }}</th>
                    <th class="is-right">{{ $t('Tax') }}</th>
                    <th class="is-right">{{ $t('SubTotal') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <template v-for="(detail, dIdx) in details">
                    <tr :key="'r-' + dIdx">
                      <td>
                        <span>{{ detail.code }} ({{ detail.name }})</span>
                        <p v-show="detail.is_imei && detail.imei_number !== null" class="pxsrd__imei">{{ $t('IMEI_SN') }} : {{ detail.imei_number }}</p>
                        <px-badge v-if="detail.is_batch_tracked" tone="info" icon="package">{{ $t('Batches') || 'Lotes' }}</px-badge>
                      </td>
                      <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber(detail.Net_price, 3) }}</td>
                      <td class="is-right pxn-num">{{ formatNumber(detail.quantity, 2) }} {{ detail.unit_sale }}</td>
                      <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber(detail.price, 2) }}</td>
                      <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber(detail.DiscountNet, 2) }}</td>
                      <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber(detail.taxe, 2) }}</td>
                      <td class="is-right pxn-num">{{ currentUser.currency }} {{ detail.total.toFixed(priceDecimals) }}</td>
                    </tr>
                    <tr v-if="detail.is_batch_tracked && (detail.batches || []).length" :key="'b-' + dIdx">
                      <td colspan="7" class="pxsrd__batchcell">
                        <div class="pxsrd__batchbox">
                          <div class="pxsrd__batchhead">
                            <span><lucide-icon name="package" :size="13" /> {{ $t('Batches') || 'Lotes' }}</span>
                            <span class="pxsrd__batchcount">{{ detail.batches.length }} {{ $t('items') || 'ítems' }}</span>
                          </div>
                          <table class="pxsrd-btbl">
                            <thead>
                              <tr>
                                <th>{{ $t('Batch_No') || 'N.º de lote' }}</th>
                                <th>{{ $t('Mfg_Date') || 'Fabricación' }}</th>
                                <th>{{ $t('Expiry_Date') || 'Caducidad' }}</th>
                                <th class="is-right">{{ $t('Quantity') }}</th>
                                <th class="is-right">{{ $t('Price') || 'Precio' }}</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr v-for="(b, bIdx) in detail.batches" :key="'sb-' + dIdx + '-' + bIdx">
                                <td class="pxsrd__strong"><span v-if="b.batch_no">{{ b.batch_no }}</span><span v-else class="pxsrd__muted">—</span></td>
                                <td><span v-if="b.mfg_date">{{ b.mfg_date }}</span><span v-else class="pxsrd__muted">—</span></td>
                                <td>
                                  <span v-if="b.expiry_date" class="pxsrd__pill" :class="expiryClass(b.expiry_date)">{{ b.expiry_date }}</span>
                                  <span v-else class="pxsrd__muted">—</span>
                                </td>
                                <td class="is-right pxsrd__strong pxn-num">{{ formatNumber(b.qty, 2) }} {{ detail.unit_sale }}</td>
                                <td class="is-right pxn-num">
                                  <span v-if="b.unit_price != null">{{ currentUser.currency }} {{ formatNumber(b.unit_price, 2) }}</span>
                                  <span v-else class="pxsrd__muted">—</span>
                                </td>
                              </tr>
                            </tbody>
                          </table>
                        </div>
                      </td>
                    </tr>
                  </template>
                </tbody>
              </table>
            </div>
          </div>

          <div class="pxsrd__totals">
            <table class="pxsrd-totbl">
              <tbody>
                <tr><td>{{ $t('OrderTax') }}</td><td class="is-right pxn-num">{{ formatPriceWithSymbol(currentUser.currency, sale_return.TaxNet, 2) }} ({{ formatNumber(sale_return.tax_rate, 2) }} %)</td></tr>
                <tr><td>{{ $t('Discount') }}</td><td class="is-right pxn-num">{{ formatPriceWithSymbol(currentUser.currency, sale_return.discount, 2) }}</td></tr>
                <tr><td>{{ $t('Shipping') }}</td><td class="is-right pxn-num">{{ formatPriceWithSymbol(currentUser.currency, sale_return.shipping, 2) }}</td></tr>
                <tr class="pxsrd__totrow"><td>{{ $t('Total') }}</td><td class="is-right pxn-num">{{ formatPriceWithSymbol(currentUser.currency, sale_return.GrandTotal, 2) }}</td></tr>
                <tr v-if="sale_return.store_credit_voucher">
                  <td>{{ $t('Store_Credit') || 'Vale emitido' }}</td>
                  <td class="is-right">
                    <span class="pxsrd__strong">{{ sale_return.store_credit_voucher.code }}</span>
                    <div class="pxsrd__muted pxn-num">{{ formatPriceWithSymbol(currentUser.currency, sale_return.store_credit_voucher.remaining_balance, 2) }}</div>
                  </td>
                </tr>
                <tr class="pxsrd__totrow"><td>{{ $t('Paid') }}</td><td class="is-right pxn-num">{{ formatPriceWithSymbol(currentUser.currency, sale_return.paid_amount, 2) }}</td></tr>
                <tr class="pxsrd__totrow"><td>{{ $t('Due') }}</td><td class="is-right pxn-num">{{ formatPriceWithSymbol(currentUser.currency, sale_return.due, 2) }}</td></tr>
              </tbody>
            </table>
          </div>

          <template v-if="sale_return.note">
            <hr class="pxsrd__rule" />
            <p class="pxsrd__note">{{ sale_return.note }}</p>
          </template>
        </div>
      </div>
    </px-card>
  </div>
</template>


<script>
import { mapActions, mapGetters } from "vuex";
import NProgress from "nprogress";
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting,
  getPriceDecimals
} from "../../../../utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";

export default {
  components: { PxPageHeader, PxCard, PxButton, PxBadge, PxAlert },
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
  },
  metaInfo: {
    title: "Detalle de devolución"
  },

  data() {
    return {
      isLoading: true,
      sale_return: {},
      details: [],
      company: {},
      email: {},
      // Optional price format key for frontend display (loaded from system settings/localStorage)
      price_format_key: null
    };
  },

  methods: {
    //-----------------------------------  Sale Return PDF -------------------------\\
    Return_PDF() {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      let id = this.$route.params.id;

       axios
        .get(`return_sale_pdf/${id}`, {
          responseType: "blob", // important
          headers: {
            "Content-Type": "application/json"
          }
        })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute(
            "download",
            "Sale_Return-" + this.sale_return.Ref + ".pdf"
          );
          document.body.appendChild(link);
          link.click();
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(() => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
        });
    },

    //------------------------------ Print -------------------------\\
    print() {
      this.$htmlToPaper('print_Invoice');
    },

     //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
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

    expiryClass(dateStr) {
      if (!dateStr) return "is-neutral";
      const today = new Date(); today.setHours(0, 0, 0, 0);
      const exp = new Date(dateStr);
      if (isNaN(exp.getTime())) return "is-neutral";
      exp.setHours(0, 0, 0, 0);
      const diffDays = Math.round((exp - today) / (1000 * 60 * 60 * 24));
      if (diffDays < 0) return "is-expired";
      if (diffDays <= 30) return "is-soon";
      return "is-ok";
    },

    // Price formatting for display only (does NOT affect calculations or stored values)
    // Uses the global/system price_format setting when available; otherwise falls back
    // to the existing formatNumber helper to preserve current behavior.
    formatPriceDisplay(number, dec) {
      try {
        // Money formatter: always honour the configured price precision (2 or 3).
        const decimals = this.priceDecimals;
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        const effectiveKey = key || null;
        return formatPriceDisplayHelper(number, decimals, effectiveKey);
      } catch (e) {
        return this.formatNumber(number, dec);
      }
    },

    formatPriceWithSymbol(symbol, number, dec) {
      const safeSymbol = symbol || "";
      const value = this.formatPriceDisplay(number, dec);
      return safeSymbol ? `${safeSymbol} ${value}` : value;
    },

    //----------------------------------- Get Details Sale Return ------------------------------\\
    Get_Details() {
      let id = this.$route.params.id;
      axios
        .get(`returns/sale/${id}`)
        .then(response => {
          this.sale_return = response.data.sale_Return;
          this.details = response.data.details;
          this.company = response.data.company;
          this.isLoading = false;
        })
        .catch(response => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    //---------------------  Delete Return ------------------------\\
    Delete_Return() {
      let id = this.$route.params.id;
      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "var(--px-primary)",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          axios
            .delete("returns/sale/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              this.$router.push({ name: "index_sale_return" });
            })
            .catch(() => {
              this.$swal(
                this.$t("Delete_Failed"),
                this.$t("Delete_Therewassomethingwronge"),
                "warning"
              );
            });
        }
      });
    }
  }, //end Methods

  //----------------------------- Created function-------------------

  created: function() {
    this.Get_Details();
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxsrd { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxsrd { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxsrd__pad { padding: var(--pxn-space-6) 0; }
.pxsrd__card { margin-top: var(--pxn-space-5); }

.pxsrd__doc { padding: var(--pxn-space-8); }
@media (max-width: 620px) { .pxsrd__doc { padding: var(--pxn-space-5); } }
.pxsrd__doctitle { font-size: var(--pxn-fs-h2); font-weight: var(--pxn-fw-bold); color: var(--pxn-ink); text-align: center; }
.pxsrd__rule { border: 0; border-top: 1px solid var(--pxn-border); margin: var(--pxn-space-6) 0; }

.pxsrd__cols { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-6); margin-bottom: var(--pxn-space-7); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
@media (max-width: 720px) { .pxsrd__cols { grid-template-columns: minmax(0, 1fr); } }
.pxsrd__col > div { margin-bottom: 2px; }
.pxsrd__colhead { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); margin-bottom: var(--pxn-space-3); }

.pxsrd__lines { margin-bottom: var(--pxn-space-7); }
.pxsrd__refund { margin: var(--pxn-space-3) 0 var(--pxn-space-4); }
.pxsrd-tbl__wrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); overflow-x: auto; }
.pxsrd-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxsrd-tbl th { padding: var(--pxn-space-3) var(--pxn-space-4); text-align: left; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap; }
.pxsrd-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink); vertical-align: top; }
.pxsrd-tbl .is-right, .pxsrd-btbl .is-right { text-align: right; }
.pxsrd__imei { margin: 2px 0 0; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }

.pxsrd__batchcell { padding: 0 !important; border-bottom: 1px solid var(--pxn-border); }
.pxsrd__batchbox { margin: var(--pxn-space-2) var(--pxn-space-3) var(--pxn-space-4); border: 1px solid var(--pxn-primary-border); border-radius: var(--pxn-radius-md); overflow: hidden; background: var(--pxn-primary-softer); }
.pxsrd__batchhead { display: flex; align-items: center; justify-content: space-between; padding: var(--pxn-space-2) var(--pxn-space-4); background: var(--pxn-primary); color: var(--pxn-primary-contrast); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; }
.pxsrd__batchcount { background: rgba(255,255,255,0.22); padding: 1px 8px; border-radius: var(--pxn-radius-pill); }
.pxsrd-btbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-xs); }
.pxsrd-btbl th { padding: var(--pxn-space-2) var(--pxn-space-3); text-align: left; color: var(--pxn-primary-ink); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.03em; background: var(--pxn-primary-soft); }
.pxsrd-btbl td { padding: var(--pxn-space-2) var(--pxn-space-3); border-top: 1px solid var(--pxn-primary-border); color: var(--pxn-ink); }
.pxsrd__strong { font-weight: var(--pxn-fw-semibold); }
.pxsrd__muted { color: var(--pxn-ink-3); }
.pxsrd__pill { display: inline-block; padding: 2px 8px; border-radius: var(--pxn-radius-pill); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); }
.pxsrd__pill.is-neutral { background: var(--pxn-surface-3); color: var(--pxn-ink-3); }
.pxsrd__pill.is-expired { background: var(--pxn-danger-soft); color: var(--pxn-danger-ink); }
.pxsrd__pill.is-soon { background: var(--pxn-warning-soft); color: var(--pxn-warning-ink); }
.pxsrd__pill.is-ok { background: var(--pxn-success-soft); color: var(--pxn-success-ink); }

.pxsrd__totals { display: flex; justify-content: flex-end; }
.pxsrd-totbl { min-width: 300px; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxsrd-totbl td { padding: var(--pxn-space-2) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink-2); }
.pxsrd-totbl td.is-right { text-align: right; color: var(--pxn-ink); }
.pxsrd__totrow td { font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }

.pxsrd__note { margin: 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); white-space: pre-wrap; }
</style>
