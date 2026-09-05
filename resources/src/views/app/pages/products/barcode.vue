<template>
  <div class="px-next pxbc">
    <px-page-header
      title="Imprimir códigos de barras"
      :breadcrumbs="[{ label: $t('Products') }, { label: 'Códigos de barra' }]"
    >
      <template #actions>
        <px-button variant="ghost" icon="arrow-left" @click="$router.push({ name: 'index_products' })">Volver a productos</px-button>
      </template>
    </px-page-header>

    <p class="pxbc__lead">Configura e imprime etiquetas de código de barras para tus productos.</p>

    <div v-if="isLoading" class="pxbc__loading">
      <px-skeleton variant="lines" :rows="6" />
    </div>

    <template v-else>
      <!-- Barcode scanner -->
      <px-modal v-model="scanOpen" title="Escáner de código de barras" size="md">
        <qrcode-scanner
          :qrbox="250"
          :fps="10"
          class="pxbc__scanner"
          @result="onScan"
        />
      </px-modal>

      <div class="pxbc__stack">
        <!-- Configuration -->
        <px-card :title="$t('Configuration') || 'Configuración'">
          <div class="pxbc__grid2">
            <validation-observer ref="show_Barcode" tag="div">
              <validation-provider name="warehouse" :rules="{ required: true }" v-slot="v">
                <px-field :label="$t('warehouse')" required :error="v.errors[0]">
                  <template #default="{ id }">
                    <vs-px
                      :input-id="id"
                      :invalid="!!v.errors.length"
                      @input="val => { Selected_Warehouse(val); v.validate(val); }"
                      v-model="barcode.warehouse_id"
                      :reduce="label => label.value"
                      :placeholder="$t('Choose_Warehouse')"
                      :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
                    />
                  </template>
                </px-field>
              </validation-provider>
            </validation-observer>

            <px-field :label="$t('Paper_size')">
              <template #default="{ id }">
                <vs-px
                  :input-id="id"
                  v-model="paper_size"
                  @input="Selected_Paper_size"
                  :reduce="label => label.value"
                  :placeholder="$t('Paper_size')"
                  :options="getPaperSizeOptions()"
                />
              </template>
            </px-field>
          </div>

          <div
            v-if="paper_size === 'customstyle' || (paper_size && paper_size.startsWith('sticker_'))"
            class="pxbc__grid2 pxbc__mt"
          >
            <px-field label="Ancho (mm)">
              <template #default="{ id }">
                <px-input
                  :id="id"
                  type="number"
                  min="1"
                  v-model.number="custom_sticker_width"
                  :disabled="paper_size !== 'customstyle'"
                  @input="updateCustomStickerLabel"
                />
              </template>
            </px-field>
            <px-field label="Alto (mm)">
              <template #default="{ id }">
                <px-input
                  :id="id"
                  type="number"
                  min="1"
                  v-model.number="custom_sticker_height"
                  :disabled="paper_size !== 'customstyle'"
                  @input="updateCustomStickerLabel"
                />
              </template>
            </px-field>
            <p v-if="paper_size !== 'customstyle'" class="pxbc__hint pxbc__span2">
              <lucide-icon name="info" :size="13" />
              Las dimensiones son predefinidas. Elige «Stickers - Custom Value» para introducir medidas propias.
            </p>
          </div>

          <div class="pxbc__toggles">
            <label class="pxbc__toggle">
              <px-check type="switch" v-model="show_price" />
              {{ $t('Display_Price') || 'Mostrar precio' }}
            </label>
            <label class="pxbc__toggle">
              <px-check type="switch" v-model="auto_print" />
              {{ $t('Auto_Print') || 'Impresión automática' }}
            </label>
          </div>
        </px-card>

        <!-- Product search -->
        <px-card :title="$t('ProductName')" class="pxbc__searchcard">
          <div class="pxbc__search">
            <button
              type="button"
              class="pxbc__scanbtn"
              @click="showModal"
              :title="$t('Scan_Barcode') || 'Escanear'"
            >
              <lucide-icon name="qr-code" :size="18" />
            </button>
            <input
              :placeholder="$t('Scan_Search_Product_by_Code_Name')"
              @input="e => search_input = e.target.value"
              @keyup="search(search_input)"
              @focus="handleFocus"
              @blur="handleBlur"
              ref="product_autocomplete"
              class="pxbc__searchinput"
            />
            <ul class="pxbc__results pxn-scroll" v-show="focused && product_filter.length">
              <li
                class="pxbc__result"
                v-for="product_fil in product_filter"
                :key="product_fil.code"
                @mousedown="SearchProduct(product_fil)"
              >
                {{ getResultValue(product_fil) }}
              </li>
            </ul>
          </div>
        </px-card>

        <!-- Selected products -->
        <px-card :title="$t('Selected_Products') || $t('ProductName')">
          <template #actions>
            <px-badge v-if="products_added.length > 0" tone="neutral">{{ products_added.length }}</px-badge>
            <px-button
              v-if="products_added.length > 0"
              variant="ghost"
              size="sm"
              icon="rotate-ccw"
              @click="reset"
            >{{ $t('Reset') }}</px-button>
          </template>
          <px-table :columns="cols" :rows="products_added" row-key="code" has-row-actions>
            <template #cell-code="{ row }">
              <span class="pxn-mono">{{ row.code }}</span>
            </template>
            <template #cell-qte="{ row }">
              <input
                v-model.number="row.qte"
                class="pxbc__qty"
                type="number"
                min="1"
                @input="autoGenerateBarcodes"
              />
            </template>
            <template #row-actions="{ row }">
              <px-button
                variant="ghost"
                size="sm"
                icon-only
                icon="x"
                :title="$t('Delete')"
                @click="delete_Product(row.code)"
              />
            </template>
            <template #empty>
              <px-empty-state
                icon="inbox"
                :title="$t('NodataAvailable')"
                description="Busca un producto arriba para añadirlo a la lista."
              />
            </template>
          </px-table>
        </px-card>

        <!-- Barcode preview -->
        <px-card v-if="ShowCard" :title="$t('Barcode_Preview') || 'Vista previa'">
          <template #actions>
            <px-badge v-if="pages.length > 0" tone="info">{{ pages.length }} {{ $t('Pages') || 'páginas' }}</px-badge>
            <px-button variant="primary" size="sm" icon="printer" @click="print_all_Barcode">{{ $t('print') }}</px-button>
          </template>
          <div class="pxbc__preview" id="print_barcode_label">
            <div v-for="(page, pageIndex) in pages" :key="pageIndex">
              <div :class="class_type_page">
                <div class="barcode-item" :class="class_sheet" v-for="(bc, index) in page" :key="index">
                  <div class="head_barcode text-left" style="padding-left: 10px; font-weight: bold;font-size: 10px;">
                    <span class="barcode-name">{{ bc.name }}</span>
                    <span class="barcode-price" v-if="show_price">{{ currentUser.currency }} {{ bc.Net_price }}</span>
                  </div>
                  <barcode
                    class="barcode"
                    :format="bc.Type_barcode"
                    :value="bc.barcode"
                    textmargin="0"
                    fontoptions="bold"
                    fontSize="15"
                    height="25"
                    width="1"
                  ></barcode>
                </div>
              </div>
            </div>
          </div>
        </px-card>
      </div>
    </template>
  </div>
</template>

<script>
import VueBarcode from "vue-barcode";
import NProgress from "nprogress";
import { mapActions, mapGetters } from "vuex";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: "Imprimir código de barras" },
  components: {
    barcode: VueBarcode,
    PxPageHeader,
    PxCard,
    PxField,
    PxInput,
    PxCheck,
    PxTable,
    PxButton,
    PxBadge,
    PxModal,
    PxEmptyState,
    "vs-px": VsPx
  },
  data() {
    return {
      focused: false,
      timer:null,
      search_input:'',
      product_filter:[],
      isLoading: true,
      ShowCard: false,
      scanOpen: false,
      barcode: {
        product_id: "",
        warehouse_id: "",
        qte: 10
      },
      count: "",
      paper_size:"",
      sheets:'',
      total_a4:'',
      class_sheet:'',
      class_type_page:'',
      rest:'',
      warehouses: [],
      submitStatus: null,
      show_price:true,
      auto_print: true,
      products_added: [],
      pages: [],
      products: [],
      product: {
        name: "",
        code: "",
        Type_barcode: "",
        barcode:"",
        Net_price:"",
      },
      printTimeout: null,
      isGenerating: false,
      custom_sticker_width: 50,
      custom_sticker_height: 25
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),
    cols() {
      return [
        { key: "name", label: this.$t("ProductName"), strong: true },
        { key: "code", label: this.$t("CodeProduct") },
        { key: "qte", label: this.$t("Quantity"), align: "center" }
      ];
    },
    canGenerateBarcodes() {
      const hasPaperSize = this.paper_size &&
                          (this.sheets > 0 ||
                           this.paper_size === 'customstyle' ||
                           (this.paper_size && this.paper_size.startsWith('sticker_')));
      return this.products_added.length > 0 &&
             hasPaperSize &&
             this.barcode.warehouse_id;
    }
  },

  watch: {
    products_added: {
      handler() {
        if (this.canGenerateBarcodes) {
          this.autoGenerateBarcodes();
        }
      },
      deep: true
    },
    paper_size(newVal, oldVal) {
      // Only regenerate if paper size actually changed and we have the necessary data
      if (newVal !== oldVal && newVal) {
        this.$nextTick(() => {
          if (this.canGenerateBarcodes) {
            this.autoGenerateBarcodes(true); // Skip auto-print when paper size changes via watcher
          } else if (this.products_added.length > 0 && this.barcode.warehouse_id) {
            // Clear view if we can't generate with new paper size
            this.ShowCard = false;
          }
        });
      }
    },
    show_price() {
      if (this.canGenerateBarcodes) {
        this.autoGenerateBarcodes();
      }
    }
  },

  methods: {

    loadPurchaseBarcodes(purchaseId) {
      NProgress.start();
      NProgress.set(0.1);

      axios
        .get("purchases/" + purchaseId + "/barcodes")
        .then(response => {
          const data = response.data || {};

          if (data.warehouse_id) {
            this.barcode.warehouse_id = data.warehouse_id;
          }

          const items = data.products || [];
          this.products_added = items.map(p => ({
            code: p.code,
            barcode: p.barcode,
            name: p.name,
            Type_barcode: p.Type_barcode,
            Net_price: p.Net_price,
            qte: p.qte
          }));

          // Default paper size if not selected
          if (!this.paper_size) {
            this.paper_size = "style40";
            this.Selected_Paper_size("style40");
          } else if (this.paper_size === 'customstyle' || (this.paper_size && this.paper_size.startsWith('sticker_'))) {
            // Apply custom dimensions if sticker style is already selected
            if (this.paper_size.startsWith('sticker_')) {
              const option = this.getPaperSizeOptions().find(opt => opt.value === this.paper_size);
              if (option && option.width && option.height) {
                this.custom_sticker_width = option.width;
                this.custom_sticker_height = option.height;
              }
            }
            this.applyCustomStickerDimensions();
          }

          // Auto-generate will be triggered by watcher
          if (this.canGenerateBarcodes) {
            this.autoGenerateBarcodes();
          }

          setTimeout(() => NProgress.done(), 500);
        })
        .catch(() => {
          setTimeout(() => NProgress.done(), 500);
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

    Per_Page(){
      this.total_a4 = parseInt(this.barcode.qte/this.sheets);
      this.rest = this.barcode.qte%this.sheets;
    },
 //---------------------- Event Selected_Paper_size------------------------------\\
    Selected_Paper_size(value) {
      if(value == 'style40'){
        this.sheets = 40;
        this.class_sheet = 'style40';
        this.class_type_page = 'barcodea4';
      }else if(value == 'style30'){
        this.sheets = 30;
        this.class_type_page = 'barcode_non_a4';
        this.class_sheet = 'style30';
      }else if(value == 'style24'){
        this.sheets = 24;
        this.class_sheet = 'style24';
       this.class_type_page = 'barcodea4';
      }else if(value == 'style20'){
        this.sheets = 20;
        this.class_sheet = 'style20';
        this.class_type_page = 'barcode_non_a4';
      }else if(value == 'style18'){
        this.sheets =  18;
        this.class_sheet = 'style18';
        this.class_type_page = 'barcodea4';
      }else if(value == 'style14'){
        this.sheets = 14;
        this.class_sheet = 'style14';
        this.class_type_page = 'barcode_non_a4';
      }else if(value == 'style12'){
        this.sheets = 12;
        this.class_sheet = 'style12';
       this.class_type_page = 'barcodea4';
      }else if(value == 'style10'){
        this.sheets = 10;
        this.class_sheet = 'style10';
       this.class_type_page = 'barcode_non_a4';
      }else if(value == 'customstyle'){
        this.sheets = 1;
        this.class_sheet = 'customstyle';
        this.class_type_page = 'barcode_custom';
        // Apply custom dimensions
        this.applyCustomStickerDimensions();
      }else if(value && value.startsWith('sticker_')){
        // Handle predefined sticker sizes
        this.sheets = 1;
        this.class_sheet = 'customstyle';
        this.class_type_page = 'barcode_custom';

        // Extract dimensions from option
        const option = this.getPaperSizeOptions().find(opt => opt.value === value);
        if (option && option.width && option.height) {
          this.custom_sticker_width = option.width;
          this.custom_sticker_height = option.height;
          this.applyCustomStickerDimensions();
        }
      }

      this.Per_Page();

      // Force regeneration when paper size changes (skip auto-print so user can preview first)
      this.$nextTick(() => {
        if (this.canGenerateBarcodes) {
          this.autoGenerateBarcodes(true); // Skip auto-print when paper size changes
        } else if (this.products_added.length > 0 && this.barcode.warehouse_id) {
          // If we have products but can't generate yet, clear the view
          this.ShowCard = false;
        }
      });
    },
    // Get paper size options with dynamic sticker label
    getPaperSizeOptions() {
      const baseOptions = [
        {label: '40 per sheet (a4) (1.799 * 1.003)', value: 'style40'},
        {label: '30 per sheet (2.625 * 1)', value: 'style30'},
        {label: '24 per sheet (a4) (2.48 * 1.334)', value: 'style24'},
        {label: '20 per sheet (4 * 1)', value: 'style20'},
        {label: '18 per sheet (a4) (2.5 * 1.835)', value: 'style18'},
        {label: '14 per sheet (4 * 1.33)', value: 'style14'},
        {label: '12 per sheet (a4) (2.5 * 2.834)', value: 'style12'},
        {label: '10 per sheet (4 * 2)', value: 'style10'},
      ];

      // Add sticker size options
      const stickerOptions = [
        {label: 'Stickers - 50mm x 25mm', value: 'sticker_50x25', width: 50, height: 25},
        {label: 'Stickers - 50mm x 30mm', value: 'sticker_50x30', width: 50, height: 30},
        {label: 'Stickers - 53mm x 32mm (Avery 22806)', value: 'sticker_53x32', width: 53, height: 32},
        {label: 'Stickers - 57mm x 32mm', value: 'sticker_57x32', width: 57, height: 32},
        {label: 'Stickers - 63mm x 29mm', value: 'sticker_63x29', width: 63, height: 29},
        {label: 'Stickers - 63mm x 38mm', value: 'sticker_63x38', width: 63, height: 38},
        {label: 'Stickers - 70mm x 36mm', value: 'sticker_70x36', width: 70, height: 36},
        {label: 'Stickers - 70mm x 37mm', value: 'sticker_70x37', width: 70, height: 37},
        {label: 'Stickers - 74mm x 52mm', value: 'sticker_74x52', width: 74, height: 52},
        {label: 'Stickers - 80mm x 50mm', value: 'sticker_80x50', width: 80, height: 50},
        {label: 'Stickers - 100mm x 50mm', value: 'sticker_100x50', width: 100, height: 50},
        {label: 'Stickers - 100mm x 70mm', value: 'sticker_100x70', width: 100, height: 70},
        {label: 'Stickers - 105mm x 37mm', value: 'sticker_105x37', width: 105, height: 37},
        {label: 'Stickers - 105mm x 48mm', value: 'sticker_105x48', width: 105, height: 48},
        {label: 'Stickers - 105mm x 74mm', value: 'sticker_105x74', width: 105, height: 74},
        {label: 'Stickers - 148mm x 105mm (A5)', value: 'sticker_148x105', width: 148, height: 105},
      ];

      // Add sticker options to base options
      stickerOptions.forEach(option => {
        baseOptions.push({
          label: option.label,
          value: option.value,
          width: option.width,
          height: option.height
        });
      });

      // Add custom sticker option
      baseOptions.push({label: 'Stickers - Custom Value', value: 'customstyle'});

      return baseOptions;
    },
    // Update custom sticker label in options
    updateCustomStickerLabel() {
      if (this.paper_size === 'customstyle' || (this.paper_size && this.paper_size.startsWith('sticker_'))) {
        this.applyCustomStickerDimensions();
        if (this.canGenerateBarcodes) {
          this.autoGenerateBarcodes(true); // Skip auto-print when dimensions change
        }
      }
    },
    // Apply custom sticker dimensions to CSS
    applyCustomStickerDimensions() {
      this.$nextTick(() => {
        const styleId = 'custom-sticker-dimensions';
        let styleElement = document.getElementById(styleId);

        if (!styleElement) {
          styleElement = document.createElement('style');
          styleElement.id = styleId;
          document.head.appendChild(styleElement);
        }

        const widthMM = this.custom_sticker_width || 50;
        const heightMM = this.custom_sticker_height || 25;

        // Convert mm to CSS units (1mm = 3.7795275590551px, but we'll use mm directly)
        styleElement.textContent = `
          .barcode_custom {
            width: ${widthMM}mm !important;
            height: ${heightMM}mm !important;
          }
        `;
      });
    },
    //------ Auto Generate Barcodes
    autoGenerateBarcodes(skipAutoPrint = false) {
      if (this.isGenerating) return;

      this.isGenerating = true;

      // Clear any pending print timeout
      if (this.printTimeout) {
        clearTimeout(this.printTimeout);
        this.printTimeout = null;
      }

      // Use nextTick to ensure DOM updates are complete
      this.$nextTick(() => {
        if (this.canGenerateBarcodes) {
          this.generatePages();
          this.ShowCard = true;

          // Auto-print after a short delay if enabled and not skipped
          if (this.auto_print && this.pages.length > 0 && !skipAutoPrint) {
            this.printTimeout = setTimeout(() => {
              this.print_all_Barcode();
            }, 1500);
          }
        } else {
          this.ShowCard = false;
        }

        this.isGenerating = false;
      });
    },

    //------ Validate Form (kept for backward compatibility)
    submit() {
      this.$refs.show_Barcode.validate().then(success => {
        if (!success) {
          return;
        } else {
          this.autoGenerateBarcodes();
        }
      });
    },
    //---Validate State Fields
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },
      handleFocus() {
      this.focused = true
    },
    handleBlur() {
      this.focused = false
    },

      //-----------------------------------Delete Product ------------------------------\\
      delete_Product(code) {
      for (var i = 0; i < this.products_added.length; i++) {
        if (code === this.products_added[i].code) {
          this.products_added.splice(i, 1);
          // Auto-regenerate after deletion
          if (this.canGenerateBarcodes) {
            this.autoGenerateBarcodes();
          } else {
            this.ShowCard = false;
          }
          break;
        }
      }
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
      if (this.barcode.warehouse_id != "" &&  this.barcode.warehouse_id != null) {
          this.timer = setTimeout(() => {
          const product_filter = this.products.filter(product => product.code === this.search_input || product.barcode.includes(this.search_input));
            if(product_filter.length === 1){
                this.SearchProduct(product_filter[0])
            }else{
              let tokens = this.search_input.toLowerCase().split(' ');
                this.product_filter=  this.products.filter(product => {

                  return tokens.every(token =>
                      product.name.toLowerCase().includes(token)
                      ||  product.code.toLowerCase().includes(token)
                      ||  product.barcode.toLowerCase().includes(token)
                      ||  (product.note && product.note.toLowerCase().includes(token))
                  );
                // this.product_filter=  this.products.filter(product => {
                //   return (
                //     product.name.toLowerCase().includes(this.search_input.toLowerCase()) ||
                //     product.code.toLowerCase().includes(this.search_input.toLowerCase()) ||
                //     product.barcode.toLowerCase().includes(this.search_input.toLowerCase())
                //     );
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
    //------ Search Result value
    getResultValue(result) {
      return result.code + " " + "(" + result.name + ")";
    },

     //------ Submit Search Product
     SearchProduct(result) {
      const existingProduct = this.products_added.find(product => product.code === result.code);

      if (existingProduct) {
        this.makeToast("warning", this.$t("AlreadyAdd"), this.$t("Warning"));
      } else {
        this.products_added.push({
          code: result.code,
          barcode: result.barcode,
          name: result.name,
          Type_barcode: result.Type_barcode,
          Net_price: result.Net_price,
          qte: 1, // Default quantity
        });
        // Auto-generate will be triggered by watcher
      }

      this.search_input = '';
      this.$refs.product_autocomplete.value = "";
      this.product_filter = [];
    },
    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },
    //------------------------------------ Get Products By Warehouse -------------------------\\
    Get_Products_By_Warehouse(id) {
      // Start the progress bar.
        NProgress.start();
        NProgress.set(0.1);
      axios
        .get("get_Products_by_warehouse/" + id + "?stock=" + 0 + "&product_service=" + 1 + "&product_combo=" + 1)
         .then(response => {
            this.products = response.data;
             NProgress.done();
            })
          .catch(error => {
          });
    },
    //-------------------------------------- Print Barcode -------------------------\\
    print_all_Barcode() {
      var divContents = document.getElementById("print_barcode_label").innerHTML;
      var a = window.open("", "", "height=500, width=500");
      a.document.write(
        '<html><head><link rel="stylesheet" href="/assets_setup/css/print_label.css">'
      );
      if (this.paper_size === 'customstyle' || (this.paper_size && this.paper_size.startsWith('sticker_'))) {
        const w = this.custom_sticker_width || 50;
        const h = this.custom_sticker_height || 25;
        a.document.write(
          '<style>' +
          '@page { size: ' + w + 'mm ' + h + 'mm; margin: 0; }' +
          'html, body { margin: 0; padding: 0; }' +
          '.barcode_custom {' +
            'width: ' + w + 'mm !important;' +
            'height: ' + h + 'mm !important;' +
            'margin: 0 !important;' +
            'padding: 1mm !important;' +
            'border: none !important;' +
            'box-sizing: border-box !important;' +
            'overflow: hidden !important;' +
            'display: flex !important;' +
            'flex-direction: column;' +
            'align-items: center;' +
            'justify-content: center;' +
            'page-break-after: always;' +
          '}' +
          'body > div:last-child .barcode_custom { page-break-after: auto; }' +
          '.barcode_custom .barcode-item {' +
            'width: 100%; height: 100%;' +
            'display: flex; flex-direction: column;' +
            'align-items: stretch; justify-content: flex-start;' +
            'overflow: hidden; border: none !important;' +
          '}' +
          '.barcode_custom .head_barcode {' +
            'flex: 0 0 auto; width: 100%; padding-left: 0 !important;' +
            'text-align: center !important;' +
            'font-size: 8px !important; line-height: 1.2 !important;' +
          '}' +
          '.barcode_custom .barcode-name {' +
            'display: block; max-width: 100%;' +
            'white-space: nowrap; overflow: hidden; text-overflow: ellipsis;' +
          '}' +
          '.barcode_custom .barcode {' +
            'flex: 1 1 auto; width: 100% !important; min-height: 0;' +
            'display: flex; align-items: stretch; justify-content: center;' +
          '}' +
          '.barcode_custom .barcode svg {' +
            'width: 100% !important; height: 100% !important;' +
            'display: block;' +
          '}' +
          '</style>'
        );
        a.document.write(
          '<scr' + 'ipt>' +
          'window.addEventListener("DOMContentLoaded", function() {' +
            'document.querySelectorAll(".barcode_custom svg").forEach(function(svg) {' +
              'if (!svg.getAttribute("viewBox")) {' +
                'var w = svg.width.baseVal.value || svg.getBoundingClientRect().width;' +
                'var h = svg.height.baseVal.value || svg.getBoundingClientRect().height;' +
                'if (w && h) { svg.setAttribute("viewBox", "0 0 " + w + " " + h); }' +
              '}' +
              'svg.setAttribute("preserveAspectRatio", "none");' +
              'svg.removeAttribute("width");' +
              'svg.removeAttribute("height");' +
            '});' +
          '});' +
          '</scr' + 'ipt>'
        );
      }
      a.document.write('</head><body>');
      a.document.write(divContents);
      a.document.write("</body></html>");
      a.document.close();

      setTimeout(() => {
         a.print();
      }, 1000);


    },

    generatePages() {
      let allBarcodes = [];
      this.products_added.forEach(product => {
        for (let i = 0; i < product.qte; i++) {
          allBarcodes.push({
            name: product.name,
            barcode: product.barcode,
            Type_barcode: product.Type_barcode,
            Net_price: product.Net_price
          });
        }
      });

      this.pages = [];
      while (allBarcodes.length > 0) {
        this.pages.push(allBarcodes.splice(0, this.sheets));
      }
    },

    //-------------------------------------- Show Barcode -------------------------\\
    showBarcode() {
      // this.Per_Page();
      // this.count = this.barcode.qte;
      this.generatePages();
      this.ShowCard = true;
    },
    //---------------------- Event Select Warehouse ------------------------------\\
    Selected_Warehouse(value) {
      this.search_input= '';
      this.product_filter = [];
      this.Get_Products_By_Warehouse(value);
    },
    //----------------------------------- GET Barcode Elements -------------------------\\
    Get_Elements: function() {
      axios
        .get("barcode_create_page")
        .then(response => {
          this.warehouses = response.data.warehouses;
          this.isLoading = false;
        })
        .catch(response => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },
    //----------------------------------- Reset Data -------------------------\\
    reset() {
      this.ShowCard = false;
      this.products = [];
      this.products_added = [];
      this.product.name = "";
      this.product.code = "";
      this.product.Net_price = "";
      this.barcode.qte = 10;
      this.count = 10;
      this.barcode.warehouse_id = "";
      this.paper_size = "";
      this.sheets = "";
      this.search_input= '';
      if (this.$refs.product_autocomplete) {
        this.$refs.product_autocomplete.value = "";
      }
      this.product_filter = [];
      this.pages = [];
      this.custom_sticker_width = 50;
      this.custom_sticker_height = 25;

      // Clear any pending print timeout
      if (this.printTimeout) {
        clearTimeout(this.printTimeout);
        this.printTimeout = null;
      }

      // Remove custom style
      const styleElement = document.getElementById('custom-sticker-dimensions');
      if (styleElement) {
        styleElement.remove();
      }

      // Reset sheets for sticker sizes
      if (this.paper_size && this.paper_size.startsWith('sticker_')) {
        this.sheets = 1;
      }
    }
  }, //end Methods
  //-----------------------------Created function-------------------
  created: function() {
    this.Get_Elements();

    const purchaseId = this.$route && this.$route.query
      ? this.$route.query.purchase_id
      : null;

    if (purchaseId) {
      this.loadPurchaseBarcodes(purchaseId);
    }
  },
  mounted() {
    // Apply custom dimensions if sticker style is selected on mount
    if (this.paper_size === 'customstyle' || (this.paper_size && this.paper_size.startsWith('sticker_'))) {
      this.$nextTick(() => {
        // If it's a predefined sticker, get dimensions from option
        if (this.paper_size.startsWith('sticker_')) {
          const option = this.getPaperSizeOptions().find(opt => opt.value === this.paper_size);
          if (option && option.width && option.height) {
            this.custom_sticker_width = option.width;
            this.custom_sticker_height = option.height;
          }
        }
        this.applyCustomStickerDimensions();
      });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxbc {
  min-height: 100%;
  background: var(--pxn-bg);
  padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9);
}
@media (max-width: 620px) {
  .pxbc { padding: var(--pxn-space-6) var(--pxn-space-5); }
}

.pxbc__lead {
  margin: 0 0 var(--pxn-space-6);
  font-size: var(--pxn-fs-sm);
  color: var(--pxn-ink-3);
}
.pxbc__loading { padding: var(--pxn-space-7) 0; }

.pxbc__stack { display: flex; flex-direction: column; gap: var(--pxn-space-6); }

.pxbc__grid2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--pxn-space-5) var(--pxn-space-6);
}
@media (max-width: 720px) {
  .pxbc__grid2 { grid-template-columns: 1fr; }
}
.pxbc__mt { margin-top: var(--pxn-space-5); }
.pxbc__span2 { grid-column: 1 / -1; }
.pxbc__hint {
  display: flex; align-items: center; gap: var(--pxn-space-2);
  margin: 0; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3);
}

.pxbc__toggles {
  display: flex; flex-wrap: wrap; gap: var(--pxn-space-4) var(--pxn-space-7);
  margin-top: var(--pxn-space-6);
  padding-top: var(--pxn-space-6);
  border-top: 1px solid var(--pxn-border);
}
@media (max-width: 620px) {
  .pxbc__toggles { flex-direction: column; }
}
.pxbc__toggle {
  display: inline-flex; align-items: center; gap: var(--pxn-space-3);
  font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); cursor: pointer;
}

/* Product search */
/* The bespoke autocomplete list is position:absolute; PxCard clips its body
   (overflow:clip) so let this one card overflow to show the dropdown. */
.pxbc__searchcard { overflow: visible; }
.pxbc__search { position: relative; display: flex; align-items: stretch; }
.pxbc__scanbtn {
  display: flex; align-items: center; justify-content: center; min-width: 44px;
  border: 1px solid var(--pxn-primary);
  background: var(--pxn-primary);
  color: var(--pxn-primary-contrast);
  border-radius: var(--pxn-radius-md) 0 0 var(--pxn-radius-md);
  cursor: pointer;
  transition: background var(--pxn-dur-1) var(--pxn-ease), border-color var(--pxn-dur-1) var(--pxn-ease);
}
.pxbc__scanbtn:hover { background: var(--pxn-primary-hover); border-color: var(--pxn-primary-hover); }
.pxbc__scanbtn:active { transform: translateY(1px); }
.pxbc__searchinput {
  flex: 1; min-width: 0;
  height: var(--pxn-control-h-md);
  padding: 0 var(--pxn-space-5);
  border: 1px solid var(--pxn-border-control);
  border-left: none;
  border-radius: 0 var(--pxn-radius-md) var(--pxn-radius-md) 0;
  background: var(--pxn-surface);
  color: var(--pxn-ink);
  font-size: var(--pxn-fs-body);
}
.pxbc__searchinput:focus {
  outline: none;
  border-color: var(--pxn-primary);
  box-shadow: 0 0 0 3px var(--pxn-primary-softer);
}
.pxbc__searchinput::placeholder { color: var(--pxn-ink-3); }

.pxbc__results {
  position: absolute; top: calc(100% + 4px); left: 0; right: 0;
  z-index: var(--pxn-z-dropdown);
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md);
  box-shadow: var(--pxn-shadow-menu);
  max-height: 280px; overflow-y: auto;
  padding: var(--pxn-space-2);
}
.pxbc__result {
  padding: var(--pxn-space-3) var(--pxn-space-4);
  border-radius: var(--pxn-radius-sm);
  font-size: var(--pxn-fs-sm);
  color: var(--pxn-ink-2);
  cursor: pointer;
}
.pxbc__result:hover { background: var(--pxn-primary-soft); color: var(--pxn-primary-ink); }

/* Quantity cell input */
.pxbc__qty {
  width: 84px; text-align: center;
  height: var(--pxn-control-h-sm);
  border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-sm);
  background: var(--pxn-surface);
  color: var(--pxn-ink);
  font-size: var(--pxn-fs-sm);
}
.pxbc__qty:focus {
  outline: none;
  border-color: var(--pxn-primary);
  box-shadow: 0 0 0 3px var(--pxn-primary-softer);
}

.pxbc__scanner { width: 100%; }

/* On-screen preview surface. The inner print classes (class_type_page /
   class_sheet / barcode-item / head_barcode / barcode) are untouched — they
   drive print_all_Barcode() and /assets_setup/css/print_label.css. */
.pxbc__preview {
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md);
  padding: var(--pxn-space-6);
  overflow-x: auto;
}
</style>
