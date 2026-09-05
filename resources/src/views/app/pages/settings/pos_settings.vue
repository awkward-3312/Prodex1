<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('Pos_Settings')"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('Pos_Settings') }]"
    />

    <div v-if="isLoading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="10" />
    </div>

    <validation-observer v-else ref="Submit_Pos_Settings">
      <form @submit.prevent="Submit_Pos_Settings">
        <px-card :title="$t('Pos_Settings')" class="pxcfg__card">
          <div class="pxcfg__toggles">
            <div class="pxcfg__toggle">
              <div class="pxcfg__toggle-copy">
                <div class="pxcfg__toggle-title">{{ $t('Quick_Add_Customer') }}</div>
                <div class="pxcfg__toggle-hint">{{ $t('Enable_Quick_Add_Customer_popup_in_POS') }}</div>
              </div>
              <px-check type="switch" :modelValue="!!pos_settings.quick_add_customer" @change="v => pos_settings.quick_add_customer = v" />
            </div>
            <div class="pxcfg__toggle">
              <div class="pxcfg__toggle-copy">
                <div class="pxcfg__toggle-title">{{ $t('Barcode_Scanning_Sound') }}</div>
                <div class="pxcfg__toggle-hint">{{ $t('Enable_sound_when_scanning_barcodes_in_POS') }}</div>
              </div>
              <px-check type="switch" :modelValue="!!pos_settings.barcode_scanning_sound" @change="v => pos_settings.barcode_scanning_sound = v" />
            </div>
            <div class="pxcfg__toggle">
              <div class="pxcfg__toggle-copy">
                <div class="pxcfg__toggle-title">{{ $t('Show_Product_Images_in_POS') }}</div>
                <div class="pxcfg__toggle-hint">{{ $t('Show_hide_product_images_in_POS_product_listing') }}</div>
              </div>
              <px-check type="switch" :modelValue="!!pos_settings.show_product_images" @change="v => pos_settings.show_product_images = v" />
            </div>
            <div class="pxcfg__toggle">
              <div class="pxcfg__toggle-copy">
                <div class="pxcfg__toggle-title">{{ $t('Show_Stock_Quantity_in_POS') }}</div>
                <div class="pxcfg__toggle-hint">{{ $t('Show_hide_stock_quantity_in_POS') }}</div>
              </div>
              <px-check type="switch" :modelValue="!!pos_settings.show_stock_quantity" @change="v => pos_settings.show_stock_quantity = v" />
            </div>
            <div class="pxcfg__toggle">
              <div class="pxcfg__toggle-copy">
                <div class="pxcfg__toggle-title">{{ $t('Enable_Print_Invoice') }}</div>
              </div>
              <px-check type="switch" :modelValue="Number(pos_settings.is_printable) === 1" @change="v => pos_settings.is_printable = v ? 1 : 0" />
            </div>
            <div class="pxcfg__toggle">
              <div class="pxcfg__toggle-copy">
                <div class="pxcfg__toggle-title">{{ $t('Enable_Hold_Sales') }}</div>
                <div class="pxcfg__toggle-hint">{{ $t('Enable_disable_Hold_Sales_feature_in_POS') }}</div>
              </div>
              <px-check type="switch" :modelValue="!!pos_settings.enable_hold_sales" @change="v => pos_settings.enable_hold_sales = v" />
            </div>
            <div class="pxcfg__toggle">
              <div class="pxcfg__toggle-copy">
                <div class="pxcfg__toggle-title">{{ $t('Enable_Customer_Points_in_POS') }}</div>
                <div class="pxcfg__toggle-hint">{{ $t('Enable_disable_customer_points_system_in_POS') }}</div>
              </div>
              <px-check type="switch" :modelValue="!!pos_settings.enable_customer_points" @change="v => pos_settings.enable_customer_points = v" />
            </div>
            <div class="pxcfg__toggle">
              <div class="pxcfg__toggle-copy">
                <div class="pxcfg__toggle-title">{{ $t('Show_Categories_in_POS') }}</div>
                <div class="pxcfg__toggle-hint">{{ $t('Show_hide_categories_in_POS') }}</div>
              </div>
              <px-check type="switch" :modelValue="!!pos_settings.show_categories" @change="v => pos_settings.show_categories = v" />
            </div>
            <div class="pxcfg__toggle">
              <div class="pxcfg__toggle-copy">
                <div class="pxcfg__toggle-title">{{ $t('Show_Brands_in_POS') }}</div>
                <div class="pxcfg__toggle-hint">{{ $t('Show_hide_brands_in_POS') }}</div>
              </div>
              <px-check type="switch" :modelValue="!!pos_settings.show_brands" @change="v => pos_settings.show_brands = v" />
            </div>
            <div class="pxcfg__toggle">
              <div class="pxcfg__toggle-copy">
                <div class="pxcfg__toggle-title">{{ $t('Allow_Overselling') || 'Allow Overselling' }}</div>
                <div class="pxcfg__toggle-hint">{{ $t('Allow_Overselling_Help') || 'When enabled, the POS allows selling products even when stock is zero or negative. Stock can go negative after the sale.' }}</div>
              </div>
              <px-check type="switch" :modelValue="!!pos_settings.allow_overselling" @change="v => pos_settings.allow_overselling = v" />
            </div>
            <div class="pxcfg__toggle">
              <div class="pxcfg__toggle-copy">
                <div class="pxcfg__toggle-title">{{ $t('Enable_Keyboard_Shortcuts') || 'Enable Keyboard Shortcuts' }}</div>
                <div class="pxcfg__toggle-hint">
                  {{ $t('Enable_Keyboard_Shortcuts_Help') || 'Per-device setting. In the POS press Shift + ? at any time to view shortcuts.' }}
                  <a href="#" class="pxcfg__link" @click.prevent="shortcutsModalOpen = true">{{ $t('View_Shortcuts') || 'View shortcuts' }}</a>
                </div>
              </div>
              <px-check type="switch" :modelValue="!!enable_keyboard_shortcuts" @change="v => { enable_keyboard_shortcuts = v; onToggleKeyboardShortcuts(); }" />
            </div>
          </div>

          <h4 class="pxcfg__subhead">{{ $t('Cash_Drawer_Settings') }}</h4>
          <px-alert tone="info" bare class="pxcfg__alert">{{ $t('Cash_Drawer_Auto_Open_Help') }}</px-alert>
          <div class="pxcfg__grid">
            <div class="pxcfg__toggle">
              <div class="pxcfg__toggle-copy">
                <div class="pxcfg__toggle-title">{{ $t('Cash_Drawer_Auto_Open') }}</div>
              </div>
              <px-check type="switch" :modelValue="pos_settings.cash_drawer_auto_open === true" @change="v => pos_settings.cash_drawer_auto_open = v" />
            </div>
            <px-field :label="$t('Cash_Drawer_Printer_Name')" :hint="$t('Cash_Drawer_Printer_Name_Help')">
              <template #default="{ id }">
                <px-input :id="id" v-model="pos_settings.cash_drawer_printer_name" :placeholder="$t('Leave_blank_for_default_receipt_printer')" maxlength="192" />
              </template>
            </px-field>
          </div>

          <h4 class="pxcfg__subhead">Presentación</h4>
          <div class="pxcfg__grid">
            <validation-provider ref="pppProvider" name="products_per_page" :rules="{ required: true }" v-slot="v">
              <px-field label="How many items do you want to display in POS *" :error="v.errors[0]">
                <template #default="{ id, invalid }">
                  <px-input :id="id" v-model="pos_settings.products_per_page" :invalid="invalid" @input="v.validate" />
                </template>
              </px-field>
            </validation-provider>
            <px-field :label="$t('Invoice_Format')" :hint="$t('Invoice_Format_help')">
              <template #default>
                <div class="pxcfg__seg">
                  <px-button v-for="opt in invoiceFormatOptions" :key="opt.value" size="sm"
                    :variant="invoice_format === opt.value ? 'primary' : 'subtle'" @click="invoice_format = opt.value">
                    {{ $t(opt.textKey) }}
                  </px-button>
                </div>
              </template>
            </px-field>
          </div>

          <template #footer>
            <px-button variant="primary" size="lg" type="submit" @click="Submit_Pos_Settings">{{ $t('submit') }}</px-button>
          </template>
        </px-card>
      </form>
    </validation-observer>

    <px-modal v-model="shortcutsModalOpen" :title="$t('POS_Keyboard_Shortcuts') || 'POS Keyboard Shortcuts'" size="md">
      <p class="pxcfg__cardnote">{{ $t('Shortcuts_Guide_Intro') || 'These shortcuts are available on the POS screen when “Enable Keyboard Shortcuts” is ON. They are ignored while typing in form fields (except F-keys and Esc).' }}</p>
      <table class="pxcfg__table">
        <thead><tr><th style="width:45%">{{ $t('Shortcut') || 'Shortcut' }}</th><th>{{ $t('Action') || 'Action' }}</th></tr></thead>
        <tbody>
          <tr v-for="s in posShortcutsList" :key="s.id">
            <td><kbd class="pxcfg__kbd">{{ s.keys }}</kbd></td>
            <td>{{ $t(s.descriptionKey) || s.descriptionFallback }}</td>
          </tr>
        </tbody>
      </table>
      <template #footer="{ close }">
        <px-button variant="secondary" @click="close">{{ $t('Close') || 'Close' }}</px-button>
      </template>
    </px-modal>
  </div>
</template>

<script>
import { mapActions, mapGetters } from "vuex";
import NProgress from "nprogress";
import { posShortcutsEnabled, setPosShortcutsEnabled, POS_SHORTCUTS } from "../../../../mixins/posKeyboardShortcuts";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxModal from "@/components/px-next/PxModal.vue";

export default {
  metaInfo: {
    title: "POS Settings"
  },
  components: { PxPageHeader, PxButton, PxCard, PxField, PxInput, PxCheck, PxAlert, PxModal },
  data() {
    return {
      isLoading: true,
      shortcutsModalOpen: false,
      pos_settings: {
        note_customer: "",
        show_logo: "",
        show_store_name: "",
        show_reference: "",
        show_date: "",
        show_seller: "",
        show_note: "",
        show_barcode: "",
        show_discount: "",
        show_tax: "",
        show_shipping: "",
        show_phone: "",
        show_email: "",
        show_address: "",
        show_customer: "",
        show_Warehouse: "",
        is_printable: '',
        products_per_page: '',
        receipt_layout: 1,
        receipt_paper_size: 80,
        show_paid: "",
        show_due: "",
        show_payments: "",
        show_zatca_qr: "",
        quick_add_customer: false,
        barcode_scanning_sound: false,
        show_product_images: false,
        show_stock_quantity: false,
        enable_hold_sales: false,
        enable_customer_points: false,
        show_categories: false,
        show_brands: false,
        allow_overselling: false,
        cash_drawer_auto_open: false,
        cash_drawer_printer_name: "",
      },

      invoice_format: "thermal",
      invoiceFormatOptions: [
        { value: "thermal", textKey: "Invoice_Thermal" },
        { value: "a4", textKey: "Invoice_A4" },
      ],

      enable_keyboard_shortcuts: false,
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),

    currentReceiptLayout() {
      const raw = this.pos_settings && this.pos_settings.receipt_layout != null
        ? this.pos_settings.receipt_layout
        : 1;
      const n = Number(raw) || 1;
      return [1, 2, 3].includes(n) ? n : 1;
    },

    posShortcutsList() {
      return POS_SHORTCUTS;
    },
  },

  methods: {
    ...mapActions(["refreshUserPermissions"]),

    Submit_Pos_Settings() {
      this.$refs.Submit_Pos_Settings.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Update_Pos_Settings();
        }
      });
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    onToggleKeyboardShortcuts() {
      setPosShortcutsEnabled(this.enable_keyboard_shortcuts);
    },

    Update_Pos_Settings() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .put("pos_settings/" + this.pos_settings.id, {
          note_customer: this.pos_settings.note_customer,
          show_logo: this.pos_settings.show_logo,
          logo_size: this.pos_settings.logo_size,
          show_store_name: this.pos_settings.show_store_name,
          show_reference: this.pos_settings.show_reference,
          show_date: this.pos_settings.show_date,
          show_seller: this.pos_settings.show_seller,
          show_note: this.pos_settings.show_note,
          show_barcode: this.pos_settings.show_barcode,
          show_discount: this.pos_settings.show_discount,
          show_tax: this.pos_settings.show_tax,
          show_shipping: this.pos_settings.show_shipping,
          show_phone: this.pos_settings.show_phone,
          show_email: this.pos_settings.show_email,
          show_address: this.pos_settings.show_address,
          show_customer: this.pos_settings.show_customer,
          show_Warehouse: this.pos_settings.show_Warehouse,
          is_printable: this.pos_settings.is_printable,
          receipt_paper_size: this.pos_settings.receipt_paper_size,
          show_paid: this.pos_settings.show_paid,
          show_due: this.pos_settings.show_due,
          show_payments: this.pos_settings.show_payments,
          show_zatca_qr: this.pos_settings.show_zatca_qr,
          products_per_page: this.pos_settings.products_per_page,
          receipt_layout: this.pos_settings.receipt_layout,
          quick_add_customer: this.pos_settings.quick_add_customer,
          barcode_scanning_sound: this.pos_settings.barcode_scanning_sound,
          show_product_images: this.pos_settings.show_product_images,
          show_stock_quantity: this.pos_settings.show_stock_quantity,
          enable_hold_sales: this.pos_settings.enable_hold_sales,
          enable_customer_points: this.pos_settings.enable_customer_points,
          show_categories: this.pos_settings.show_categories,
          show_brands: this.pos_settings.show_brands,
          allow_overselling: this.pos_settings.allow_overselling ? 1 : 0,
          cash_drawer_auto_open: this.pos_settings.cash_drawer_auto_open ? 1 : 0,
          cash_drawer_printer_name: this.pos_settings.cash_drawer_printer_name || null,
          invoice_format: this.invoice_format,
        })
        .then(response => {
          Fire.$emit("Event_Pos_Settings");
          this.makeToast(
            "success",
            this.$t("Successfully_Updated"),
            this.$t("Success")
          );
          NProgress.done();
        })
        .catch(error => {
          NProgress.done();
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    printPosDemo() {
      try {
        const el = document.getElementById("pos-receipt-demo");
        if (!el) return;
        const divContents = el.innerHTML;
        const w = window.open("", "", "height=600,width=400");
        w.document.write('<html><head>');
        w.document.write('<link rel="stylesheet" href="/css/pos_print.css">');
        w.document.write("</head><body>");
        w.document.write(divContents);
        w.document.write("</body></html>");
        w.document.close();
        setTimeout(() => {
          w.print();
        }, 500);
      } catch (e) {
        // silently ignore print errors in settings preview
      }
    },

    get_pos_Settings() {
      axios
        .get("get_pos_Settings_api")
        .then(response => {
          this.pos_settings = response.data.pos_settings;
          this.isLoading = false;
        })
        .catch(error => {
          this.isLoading = false;
        });
    },

    Get_Settings() {
      axios
        .get("get_Settings_data")
        .then(response => {
          const settings = (response && response.data && response.data.settings) || {};
          const raw = settings.invoice_format;
          if (typeof raw === "string" && ["thermal", "a4"].includes(raw)) {
            this.invoice_format = raw;
          } else {
            this.invoice_format = "thermal";
          }
        })
        .catch(error => {
          // Silent fail – POS Settings page will fall back to default 'thermal'
        });
    },
  }, //end Methods

  created: function() {
    this.get_pos_Settings();
    this.Get_Settings();

    this.enable_keyboard_shortcuts = posShortcutsEnabled();

    Fire.$on("Event_Pos_Settings", () => {
      this.get_pos_Settings();
      this.Get_Settings();
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__card { margin-top: var(--pxn-space-5); }
.pxcfg__cardnote { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); margin: 0 0 var(--pxn-space-3); }
.pxcfg__toggles { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-3); }
@media (max-width: 700px) { .pxcfg__toggles { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__toggle { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--pxn-space-4); padding: var(--pxn-space-4) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); }
.pxcfg__toggle-title { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxcfg__toggle-hint { margin-top: var(--pxn-space-1); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxcfg__link { color: var(--pxn-primary); margin-left: var(--pxn-space-2); }
.pxcfg__subhead { margin: var(--pxn-space-6) 0 var(--pxn-space-3); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); }
.pxcfg__alert { margin-bottom: var(--pxn-space-4); }
.pxcfg__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
@media (max-width: 640px) { .pxcfg__grid { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__seg { display: flex; gap: var(--pxn-space-2); }
.pxcfg__table { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxcfg__table th, .pxcfg__table td { padding: var(--pxn-space-2) var(--pxn-space-3); border-bottom: 1px solid var(--pxn-border); text-align: left; }
.pxcfg__kbd { font-family: var(--pxn-font-mono, monospace); font-size: var(--pxn-fs-xs); background: var(--pxn-surface-2); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-sm); padding: 1px var(--pxn-space-2); }
</style>
