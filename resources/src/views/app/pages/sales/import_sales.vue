<template>
  <div class="px-next pximps">
    <px-page-header
      :title="$t('Import_Sales') || 'Importar ventas'"
      :breadcrumbs="[{ label: $t('ListSales') }, { label: $t('Import_Sales') || 'Importar ventas' }]"
    >
      <template #actions>
        <px-button variant="ghost" icon="arrow-left" @click="$router.push({ name: 'index_sales' })">{{ $t('BackToList') || 'Volver al listado' }}</px-button>
        <px-button variant="secondary" icon="file-spreadsheet" @click="downloadExample">{{ $t('Download_exemple') }}</px-button>
      </template>
    </px-page-header>

    <p class="pximps__lead">{{ $t('Import_Sale_Sub') || 'Sube un archivo CSV con códigos de producto y cantidades para crear una venta de forma masiva.' }}</p>

    <div v-if="isLoading" class="pximps__pad">
      <px-skeleton variant="lines" :rows="8" />
    </div>

    <validation-observer v-else ref="create_sale" tag="div">
      <div class="pximps__grid">
        <!-- Left: Sale details -->
        <px-card :title="$t('SaleDetails') || 'Detalles de la venta'" class="pximps__sec">
          <validation-provider ref="dateProvider" name="date" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('date')" required :error="v.errors[0]">
              <template #default="{ id }">
                <px-input :id="id" type="date" v-model="sale.date" @input="v.validate" />
              </template>
            </px-field>
          </validation-provider>

          <validation-provider ref="clientProvider" name="Customer" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Customer')" required :error="v.errors[0]" class="pximps__gap">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="sale.client_id" :reduce="o => o.value"
                  :placeholder="$t('Choose_Customer')" :options="clients.map(c => ({ label: c.name, value: c.id }))" @input="v.validate" />
              </template>
            </px-field>
          </validation-provider>

          <validation-provider ref="warehouseProvider" name="warehouse" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('warehouse')" required :error="v.errors[0]" class="pximps__gap">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="sale.warehouse_id" :reduce="o => o.value"
                  :placeholder="$t('Choose_Warehouse')" :options="warehouses.map(w => ({ label: w.name, value: w.id }))" @input="v.validate" />
              </template>
            </px-field>
          </validation-provider>

          <px-field :label="$t('Sales_Agent')" class="pximps__gap">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="sale.sales_agent_id" :reduce="o => o.value"
                :placeholder="$t('PleaseSelect')" :options="sales_agents.map(ag => ({ label: ag.name, value: ag.id }))" />
            </template>
          </px-field>

          <validation-provider ref="statutProvider" name="Status" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Status')" required :error="v.errors[0]" class="pximps__gap">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="sale.statut" :reduce="o => o.value"
                  :placeholder="$t('Choose_Status')" @input="v.validate"
                  :options="[{ label: $t('completed'), value: 'completed' }, { label: $t('Pending'), value: 'pending' }]" />
              </template>
            </px-field>
          </validation-provider>

          <div v-if="canEditTotals" class="pximps__grid2 pximps__gap">
            <px-field :label="$t('OrderTax')">
              <template #default="{ id }">
                <px-input :id="id" v-model.number="sale.tax_rate" suffix="%" @input="keyup_OrderTax" />
              </template>
            </px-field>
            <px-field :label="$t('Discount')">
              <template #default="{ id }">
                <px-input :id="id" v-model.number="sale.discount" :suffix="currentUser.currency" @input="keyup_Discount" />
              </template>
            </px-field>
          </div>
          <px-field v-if="canEditTotals" :label="$t('Shipping')" class="pximps__gap">
            <template #default="{ id }">
              <px-input :id="id" v-model.number="sale.shipping" :suffix="currentUser.currency" @input="keyup_Shipping" />
            </template>
          </px-field>

          <px-field :label="$t('Note')" class="pximps__gap">
            <template #default="{ id }">
              <px-textarea :id="id" v-model="sale.notes" :rows="3" :placeholder="$t('Afewwords')" />
            </template>
          </px-field>
        </px-card>

        <!-- Right: CSV import + preview -->
        <div class="pximps__right">
          <px-card :title="$t('CSV_Import') || 'Importación CSV'" class="pximps__sec">
            <label
              class="pximps-dz"
              :class="{ 'is-dragover': dropzoneHover, 'has-file': !!import_products }"
              @dragover.prevent="onDragOver"
              @dragleave.prevent="onDragLeave"
              @drop.prevent="onDrop"
            >
              <input id="csv-file-input" type="file" accept=".csv,text/csv" class="pximps-dz__input" @change="onFileSelected" />
              <div class="pximps-dz__icon"><lucide-icon :name="import_products ? 'file-text' : 'upload'" :size="26" /></div>
              <template v-if="!import_products">
                <p class="pximps-dz__title">{{ $t('Click_Or_Drop_CSV') || 'Haz clic o suelta tu archivo CSV aquí' }}</p>
                <p class="pximps-dz__sub">{{ $t('Accepted_Format_CSV') || 'Solo se admiten archivos .csv · separador punto y coma (;)' }}</p>
              </template>
              <template v-else>
                <p class="pximps-dz__title">{{ import_products.name }}</p>
                <p class="pximps-dz__sub">{{ formatBytes(import_products.size) }}</p>
                <px-button size="sm" variant="danger" icon="x" @click.stop.prevent="clearFile">{{ $t('Remove') || 'Quitar' }}</px-button>
              </template>
            </label>

            <div v-if="previewLoading" class="pximps__loading">
              <span class="pximps__spin"></span> {{ $t('Parsing_CSV') || 'Analizando y validando el CSV…' }}
            </div>

            <px-alert v-if="errorMessages.length" tone="danger" :title="$t('Import_Failed_Fix_Below') || 'La importación falló. Corrige lo siguiente:'" class="pximps__panel">
              <ul class="pximps__msglist">
                <li v-for="(err, idx) in errorMessages" :key="'err-' + idx">{{ err }}</li>
              </ul>
            </px-alert>

            <div v-if="previewRows.length" class="pximps__preview">
              <div class="pximps__preview-head">
                <lucide-icon name="check" :size="15" />
                <span>{{ $t('Preview') || 'Vista previa' }}</span>
                <px-badge tone="info">{{ previewRows.length }} {{ $t('items') || 'ítems' }}</px-badge>
              </div>
              <div class="pximps-ptbl__wrap pxn-scroll">
                <table class="pximps-ptbl">
                  <thead>
                    <tr>
                      <th style="width:40px">#</th>
                      <th>{{ $t('Code') || 'Código' }}</th>
                      <th>{{ $t('product_name') || $t('Product_Name') || 'Producto' }}</th>
                      <th class="is-right">{{ $t('Quantity') }}</th>
                      <th class="is-right">{{ $t('Price') }}</th>
                      <th class="is-right">{{ $t('Subtotal') || 'Subtotal' }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, idx) in previewRows" :key="'prv-' + idx">
                      <td class="pxn-num">{{ idx + 1 }}</td>
                      <td><span class="pxn-mono">{{ row.code }}</span></td>
                      <td>{{ row.name }}</td>
                      <td class="is-right pxn-num">{{ formatNumber(row.qty, 2) }} <span class="pximps__unit">{{ row.unit }}</span></td>
                      <td class="is-right pxn-num">{{ formatNumber(row.price, 2) }}</td>
                      <td class="is-right pxn-num pximps__strong">{{ formatNumber(row.total, 2) }}</td>
                    </tr>
                  </tbody>
                  <tfoot>
                    <tr>
                      <td colspan="5" class="is-right pximps__foot-l">{{ $t('Subtotal') || 'Subtotal' }}</td>
                      <td class="is-right pximps__foot-v pxn-num">{{ formatNumber(previewSubtotal, 2) }}</td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>

            <px-alert v-if="!import_products && !previewLoading && !errorMessages.length" tone="info" bare class="pximps__tip">
              <lucide-icon name="info" :size="13" />
              <strong>{{ $t('CSV_Format_Hint_Title') || 'Formato de CSV esperado' }}</strong>
              — {{ $t('CSV_Format_Hint_Body') || 'Columnas: productcode;qty — usa el archivo de ejemplo como referencia.' }}
            </px-alert>
          </px-card>

          <div class="pximps__submitbar">
            <div class="pximps__submitinfo">
              <template v-if="previewRows.length">
                <strong>{{ previewRows.length }}</strong> {{ $t('items_ready') || 'ítems listos para importar' }} ·
                <strong class="pximps__accent">{{ formatNumber(previewSubtotal, 2) }}</strong>
              </template>
              <template v-else>{{ $t('Upload_CSV_To_Preview') || 'Sube un CSV para previsualizar los ítems antes de enviar.' }}</template>
            </div>
            <px-button variant="primary" icon="check" :loading="SubmitProcessing" :disabled="SubmitProcessing || !previewRows.length" @click="Submit_Sale">
              {{ $t('submit') }}
            </px-button>
          </div>
        </div>
      </div>
    </validation-observer>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
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
    title: "Importar ventas"
  },
  components: {
    PxPageHeader, PxCard, PxField, PxInput, PxTextarea, PxButton, PxBadge, PxAlert, "vs-px": VsPx
  },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      warehouses: [],
      clients: [],
      sales_agents: [],
      import_products: null,
      previewRows: [],
      previewSubtotal: 0,
      previewLoading: false,
      errorMessages: [],
      dropzoneHover: false,
      sale: {
        date: new Date().toISOString().slice(0, 10),
        statut: "completed",
        notes: "",
        client_id: "",
        warehouse_id: "",
        sales_agent_id: null,
        tax_rate: 0,
        shipping: 0,
        discount: 0
      }
    };
  },

  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    canEditTotals() {
      return this.currentUserPermissions && this.currentUserPermissions.includes("edit_tax_discount_shipping_sale");
    }
  },

  methods: {
    downloadExample() {
      window.open("/import/exemples/import_sales.csv", "_blank", "noopener");
    },
    syncValidators() {
      this.$nextTick(() => {
        if (this.$refs.dateProvider) this.$refs.dateProvider.syncValue(this.sale.date);
        if (this.$refs.statutProvider) this.$refs.statutProvider.syncValue(this.sale.statut);
        if (this.$refs.clientProvider) this.$refs.clientProvider.syncValue(this.sale.client_id);
        if (this.$refs.warehouseProvider) this.$refs.warehouseProvider.syncValue(this.sale.warehouse_id);
      });
    },

    //------------------------------ File handlers -------------------------\\
    onFileSelected(e) {
      const file = e.target.files[0];
      if (!file) return;
      this.handleFile(file);
    },

    onDragOver() {
      this.dropzoneHover = true;
    },

    onDragLeave() {
      this.dropzoneHover = false;
    },

    onDrop(e) {
      this.dropzoneHover = false;
      const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (!file) return;
      this.handleFile(file);
    },

    handleFile(file) {
      const name = file.name || "";
      const ext = name.split(".").pop().toLowerCase();
      if (ext !== "csv") {
        this.errorMessages = [this.$t("field_must_be_in_csv_format") || "El archivo debe estar en formato CSV"];
        this.import_products = null;
        this.previewRows = [];
        this.previewSubtotal = 0;
        return;
      }
      this.import_products = file;
      this.errorMessages = [];
      this.fetchPreview();
    },

    clearFile() {
      this.import_products = null;
      this.previewRows = [];
      this.previewSubtotal = 0;
      this.errorMessages = [];
      const input = document.getElementById("csv-file-input");
      if (input) input.value = "";
    },

    formatBytes(bytes) {
      if (!bytes && bytes !== 0) return "";
      if (bytes < 1024) return bytes + " B";
      if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + " KB";
      return (bytes / (1024 * 1024)).toFixed(2) + " MB";
    },

    //------------------------------ Preview CSV -------------------------\\
    fetchPreview() {
      if (!this.import_products) return;
      this.previewLoading = true;
      this.previewRows = [];
      this.previewSubtotal = 0;
      this.errorMessages = [];

      const formData = new FormData();
      formData.append("products", this.import_products);

      axios
        .post("preview_import_sales", formData)
        .then(response => {
          this.previewLoading = false;
          const d = response.data || {};
          if (d.status === false) {
            this.errorMessages = this.collectErrorsFromResponse(d);
            if (!this.errorMessages.length) {
              this.errorMessages = [this.$t("CSV_Parse_Failed") || "No se pudo analizar el CSV"];
            }
            return;
          }
          const rows = Array.isArray(d.rows) ? d.rows : [];
          this.previewRows = rows;
          this.previewSubtotal = Number(d.grand_total) || 0;
          if (!this.previewRows.length) {
            this.errorMessages = [this.$t("CSV_No_Valid_Rows") || "No se encontraron filas válidas en el archivo CSV"];
          }
        })
        .catch(error => {
          this.previewLoading = false;
          this.errorMessages = this.collectErrorsFromAxios(error);
        });
    },

    flattenLaravelErrors(errorsObj) {
      const out = [];
      if (!errorsObj || typeof errorsObj !== "object") return out;
      Object.keys(errorsObj).forEach(k => {
        const v = errorsObj[k];
        if (Array.isArray(v)) {
          v.forEach(m => {
            if (m) out.push(String(m));
          });
        } else if (v) {
          out.push(String(v));
        }
      });
      return out;
    },
    collectErrorsFromResponse(data) {
      const out = [];
      if (!data || typeof data !== "object") return out;
      if (Array.isArray(data.messages)) {
        data.messages.forEach(m => {
          if (m) out.push(String(m));
        });
      }
      if (data.message) {
        out.push(String(data.message));
      }
      if (data.errors) {
        out.push(...this.flattenLaravelErrors(data.errors));
      }
      if (data.insufficient && Array.isArray(data.insufficient)) {
        data.insufficient.forEach(it => {
          out.push(
            `${it.product_code}: requested ${it.requested}, available ${it.available}`
          );
        });
      }
      if (data.msg && !(data.insufficient && data.insufficient.length)) {
        out.push(String(data.msg));
      }
      if (data.details) {
        if (Array.isArray(data.details)) {
          data.details.forEach(m => {
            if (m) out.push(String(m));
          });
        } else if (typeof data.details === "string") {
          out.push(data.details);
        }
      }
      if (data.error && typeof data.error === "string") {
        out.push(data.error);
      }
      const seen = {};
      return out.filter(m => (seen[m] ? false : (seen[m] = true)));
    },
    collectErrorsFromAxios(err) {
      let payload = null;
      if (err && err.response && err.response.data !== undefined) {
        payload = err.response.data;
      } else if (err && typeof err === "object" && (err.msg !== undefined || err.details !== undefined || err.errors !== undefined || err.message !== undefined)) {
        payload = err;
      }
      const list = this.collectErrorsFromResponse(payload);
      if (list.length) return list;
      if (err && typeof err === "object" && err.message) return [String(err.message)];
      return [this.$t("An_error_occurred_while_processing_the_CSV_file") || "Ocurrió un error al procesar el archivo CSV."];
    },

    //--- Submit Validate Create Sale
    Submit_Sale() {
      this.errorMessages = [];
      this.$refs.create_sale.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
          return;
        }
        if (!this.import_products) {
          this.makeToast(
            "danger",
            this.$t("field_must_be_in_csv_format"),
            this.$t("Failed")
          );
          return;
        }
        if (!this.previewRows.length) {
          this.makeToast(
            "danger",
            this.$t("CSV_No_Valid_Rows") || "No se encontraron filas válidas en el archivo CSV",
            this.$t("Failed")
          );
          return;
        }
        this.Create_Sale();
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

    //------------------------------Formetted Numbers -------------------------\\
    formatNumber(number, dec) {
      const value = (typeof number === "string"
        ? number
        : (number == null ? "0" : number.toString())
      ).split(".");
      if (dec <= 0) return value[0];
      let formated = value[1] || "";
      if (formated.length > dec)
        return `${value[0]}.${formated.substr(0, dec)}`;
      while (formated.length < dec) formated += "0";
      return `${value[0]}.${formated}`;
    },

    keyup_OrderTax() {
      if (isNaN(this.sale.tax_rate) || this.sale.tax_rate === "") {
        this.sale.tax_rate = 0;
      }
    },
    keyup_Discount() {
      if (isNaN(this.sale.discount) || this.sale.discount === "") {
        this.sale.discount = 0;
      }
    },
    keyup_Shipping() {
      if (isNaN(this.sale.shipping) || this.sale.shipping === "") {
        this.sale.shipping = 0;
      }
    },

    //--------------------------------- Create Sale -------------------------\\
    Create_Sale() {
      this.SubmitProcessing = true;
      NProgress.start();
      NProgress.set(0.1);

      const data = new FormData();
      data.append("date", this.sale.date);
      data.append("client_id", this.sale.client_id);
      data.append("warehouse_id", this.sale.warehouse_id);
      if (this.sale.sales_agent_id != null && this.sale.sales_agent_id !== "") {
        data.append("sales_agent_id", this.sale.sales_agent_id);
      }
      data.append("statut", this.sale.statut);
      data.append("notes", this.sale.notes);
      data.append("tax_rate", this.sale.tax_rate);
      data.append("discount", this.sale.discount);
      data.append("shipping", this.sale.shipping);
      data.append("products", this.import_products);

      axios
        .post("store_import_sales", data)
        .then(response => {
          NProgress.done();
          this.errorMessages = [];
          this.makeToast("success", this.$t("Successfully_Imported"), this.$t("Success"));
          this.SubmitProcessing = false;
          this.$router.push({ name: "index_sales" });
        })
        .catch(error => {
          NProgress.done();
          this.errorMessages = this.collectErrorsFromAxios(error);
          this.makeToast(
            "danger",
            this.$t("Check_the_error_list_and_fix_your_file") || "Revisa la lista de errores y corrige tu archivo.",
            this.$t("Failed")
          );
          this.SubmitProcessing = false;
        });
    },

    GetElements() {
      axios
        .get("get_import_sales")
        .then(response => {
          this.clients = response.data.clients;
          this.warehouses = response.data.warehouses;
          this.sales_agents = response.data.sales_agents || [];
          this.isLoading = false;
          this.syncValidators();
        })
        .catch(() => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    }
  },

  created() {
    this.GetElements();
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pximps { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pximps { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pximps__lead { margin: var(--pxn-space-3) 0 var(--pxn-space-6); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pximps__pad { padding: var(--pxn-space-6) 0; }

.pximps__grid { display: grid; grid-template-columns: 5fr 7fr; gap: var(--pxn-space-6); }
@media (max-width: 1024px) { .pximps__grid { grid-template-columns: minmax(0, 1fr); } }
.pximps__right { display: flex; flex-direction: column; gap: var(--pxn-space-5); min-width: 0; }
.pximps__sec { }
.pximps__sec ::v-deep .pxn-card__body { display: block; }
.pximps__gap { margin-top: var(--pxn-space-5); }
.pximps__grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: var(--pxn-space-4) var(--pxn-space-5); }

.pximps-dz {
  display: block; text-align: center; cursor: pointer;
  border: 2px dashed var(--pxn-border-strong); border-radius: var(--pxn-radius-lg);
  padding: var(--pxn-space-8) var(--pxn-space-6); background: var(--pxn-surface-2);
  transition: border-color var(--pxn-dur-1) var(--pxn-ease), background-color var(--pxn-dur-1) var(--pxn-ease);
}
.pximps-dz:hover { border-color: var(--pxn-primary-border); background: var(--pxn-primary-softer); }
.pximps-dz.is-dragover { border-color: var(--pxn-primary); background: var(--pxn-primary-soft); }
.pximps-dz__input { display: none; }
.pximps-dz__icon { color: var(--pxn-primary); }
.pximps-dz__title { margin: var(--pxn-space-3) 0 var(--pxn-space-2); font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pximps-dz__sub { margin: 0 0 var(--pxn-space-3); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }

.pximps__loading { display: flex; align-items: center; gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.pximps__spin { width: 14px; height: 14px; border-radius: 50%; border: 2px solid var(--pxn-border-strong); border-top-color: var(--pxn-primary); animation: pximps-spin 0.7s linear infinite; }
@keyframes pximps-spin { to { transform: rotate(360deg); } }

.pximps__panel { margin-top: var(--pxn-space-4); }
.pximps__msglist { margin: 0; padding-left: var(--pxn-space-6); font-size: var(--pxn-fs-sm); }
.pximps__tip { margin-top: var(--pxn-space-4); }
.pximps__tip ::v-deep svg { vertical-align: -2px; margin-right: var(--pxn-space-2); }

.pximps__preview { margin-top: var(--pxn-space-5); }
.pximps__preview-head { display: flex; align-items: center; gap: var(--pxn-space-3); margin-bottom: var(--pxn-space-3); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pximps-ptbl__wrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); overflow-x: auto; }
.pximps-ptbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); white-space: nowrap; }
.pximps-ptbl th { padding: var(--pxn-space-3) var(--pxn-space-4); text-align: left; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); }
.pximps-ptbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink); }
.pximps-ptbl tbody tr:last-child td { border-bottom: 0; }
.pximps-ptbl .is-right { text-align: right; }
.pximps__unit { color: var(--pxn-ink-3); font-size: var(--pxn-fs-xs); }
.pximps__strong { font-weight: var(--pxn-fw-semibold); }
.pximps__foot-l { padding: var(--pxn-space-3) var(--pxn-space-4); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-2); background: var(--pxn-surface-2); border-top: 2px solid var(--pxn-border); }
.pximps__foot-v { padding: var(--pxn-space-3) var(--pxn-space-4); font-weight: var(--pxn-fw-bold); color: var(--pxn-primary); background: var(--pxn-surface-2); border-top: 2px solid var(--pxn-border); }

.pximps__submitbar {
  display: flex; align-items: center; gap: var(--pxn-space-4);
  padding: var(--pxn-space-4) var(--pxn-space-5);
  background: var(--pxn-surface); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg);
}
.pximps__submitinfo { flex: 1; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pximps__accent { color: var(--pxn-primary); }
</style>
