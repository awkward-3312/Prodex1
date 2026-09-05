<template>
  <div class="px-next pximp">
    <px-page-header
      :title="$t('ImportProducts')"
      :breadcrumbs="[{ label: $t('Products') }, { label: $t('ImportProducts') }]"
    >
      <template #actions>
        <px-button variant="ghost" icon="arrow-left" @click="$router.push({ name: 'index_products' })">{{ $t('BackToList') }}</px-button>
      </template>
    </px-page-header>

    <p class="pximp__lead">{{ $t('BulkAddItemsFromExcel') }}</p>

    <px-card class="pximp__sec">
      <px-tabs :tabs="typeTabs" :value="importType" @input="switchType" />

      <!-- Dropzone -->
      <div
        class="pximp-dz"
        :class="{ 'is-dragover': isDragOver, 'has-file': file }"
        @dragover.prevent="onDragOver"
        @dragleave.prevent="onDragLeave"
        @drop.prevent="onDrop"
        @click="browse"
      >
        <input ref="file" type="file" class="pximp-dz__input" @change="onFileSelected" :accept="accept" />
        <div class="pximp-dz__icon"><lucide-icon name="upload" :size="26" /></div>
        <p class="pximp-dz__title">{{ $t('Click_Or_Drop_Excel') }}</p>
        <p class="pximp-dz__sub">{{ $t('Allowed_Format_Excel') }}</p>
        <div v-if="file" class="pximp-dz__file" @click.stop>
          <span class="pximp-dz__filedot"></span>
          <div class="pximp-dz__filemeta">
            <div class="pximp-dz__filename">{{ fileName }}</div>
            <div class="pximp-dz__filesize">{{ prettySize }}</div>
          </div>
          <px-button size="sm" variant="danger" icon="x" @click="clearFile()">{{ $t('Remove') }}</px-button>
        </div>
      </div>

      <!-- Example format -->
      <px-card class="pximp__example" flush>
        <div class="pximp__example-head"><lucide-icon name="info" :size="15" /> {{ $t('ExampleFormat') }}</div>

        <template v-if="importType === 'single'">
          <p class="pximp__example-p">
            {{ $t('ImportSingleExampleIntro') }}
            <span class="pximp__req-badge">{{ $t('green') }}</span> {{ $t('AreRequired') }}
          </p>
          <div class="pximp-extbl__wrap pxn-scroll">
            <table class="pximp-extbl">
              <thead>
                <tr>
                  <th class="req">name</th><th class="req">code</th><th class="req">cost</th>
                  <th class="req">category</th><th>sub_category</th><th class="req">unit</th>
                  <th class="req">Retail price</th><th>Wholesale price</th><th>Min price</th>
                  <th>brand</th><th>Stock alert</th><th>note</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>Blue T-Shirt</td><td>TSHIRT-BLUE</td><td>8.00</td><td>Apparel</td><td>T-Shirts</td>
                  <td>pc</td><td>19.90</td><td>17.00</td><td>15.00</td><td>Acme</td><td>5</td><td>Summer collection</td>
                </tr>
                <tr>
                  <td>Coffee Mug</td><td>MUG-COF-01</td><td>2.20</td><td>Home</td><td>Kitchen</td>
                  <td>pc</td><td>6.50</td><td>6.00</td><td>5.75</td><td></td><td>0</td><td></td>
                </tr>
              </tbody>
            </table>
          </div>
          <ul class="pximp__notes">
            <li><strong>code</strong> {{ $t('ImportNoteCodeUnique') }}</li>
            <li><strong>unit</strong> {{ $t('ImportNoteUnitExists') }}</li>
            <li><strong>category</strong> {{ $t('ImportNoteCategoryAuto') }}</li>
            <li><strong>sub_category</strong> {{ $t('ImportNoteSubCategoryOptional') }}</li>
          </ul>
        </template>

        <template v-else-if="importType === 'variant'">
          <p class="pximp__example-p">
            {{ $t('ImportVariantExampleIntro') }}
            {{ $t('ColumnsIn') }} <span class="pximp__req-badge">{{ $t('green') }}</span> {{ $t('AreRequired') }}
          </p>
          <div class="pximp-extbl__wrap pxn-scroll">
            <table class="pximp-extbl">
              <thead>
                <tr>
                  <th class="req">product name</th><th class="req">product code</th><th class="req">category</th>
                  <th>sub_category</th><th class="req">unit</th><th>brand</th>
                  <th class="req">variant name</th><th class="req">variant code</th><th class="req">variant cost</th>
                  <th class="req">variant price</th><th>variant wholesale</th><th>variant min price</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>T-Shirt</td><td>TSHIRT-100</td><td>Apparel</td><td>T-Shirts</td><td>pc</td><td>Acme</td>
                  <td>Small</td><td>TSHIRT-100-S</td><td>7.50</td><td>14.90</td><td>13.00</td><td>12.00</td>
                </tr>
                <tr>
                  <td>T-Shirt</td><td>TSHIRT-100</td><td>Apparel</td><td>T-Shirts</td><td>pc</td><td>Acme</td>
                  <td>Medium</td><td>TSHIRT-100-M</td><td>7.50</td><td>14.90</td><td>13.00</td><td>12.00</td>
                </tr>
                <tr>
                  <td>T-Shirt</td><td>TSHIRT-100</td><td>Apparel</td><td>T-Shirts</td><td>pc</td><td>Acme</td>
                  <td>Large</td><td>TSHIRT-100-L</td><td>7.50</td><td>14.90</td><td>13.00</td><td>12.00</td>
                </tr>
              </tbody>
            </table>
          </div>
          <ul class="pximp__notes">
            <li><strong>product_code</strong> {{ $t('ImportNoteProductCodeGroups') }}</li>
            <li><strong>variant_code</strong> {{ $t('ImportNoteVariantCodeUnique') }}</li>
            <li><strong>unit</strong> {{ $t('ImportNoteUnitExists') }}</li>
            <li><strong>category</strong> {{ $t('ImportNoteCategoryAuto') }}</li>
            <li><strong>sub_category</strong> {{ $t('ImportNoteSubCategoryOptional') }}</li>
          </ul>
        </template>

        <template v-else-if="importType === 'service'">
          <p class="pximp__example-p">
            {{ $t('ImportServiceExampleIntro') }}
            <span class="pximp__req-badge">{{ $t('green') }}</span> {{ $t('AreRequired') }}
          </p>
          <div class="pximp-extbl__wrap pxn-scroll">
            <table class="pximp-extbl">
              <thead>
                <tr>
                  <th class="req">name</th><th class="req">code</th><th class="req">Retail price</th>
                  <th class="req">category</th><th>sub_category</th><th class="req">unit</th>
                  <th>Wholesale price</th><th>Min price</th><th>brand</th><th>note</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>Consulting Hour</td><td>SRV-CONS-01</td><td>120.00</td><td>Services</td><td>IT Consulting</td>
                  <td>hr</td><td>100.00</td><td>90.00</td><td></td><td>Professional consulting</td>
                </tr>
                <tr>
                  <td>Delivery Fee</td><td>SRV-DEL-01</td><td>15.00</td><td>Services</td><td></td>
                  <td>pc</td><td></td><td></td><td></td><td></td>
                </tr>
              </tbody>
            </table>
          </div>
          <ul class="pximp__notes">
            <li><strong>code</strong> {{ $t('ImportNoteCodeUnique') }}</li>
            <li><strong>unit</strong> {{ $t('ImportNoteUnitExists') }}</li>
            <li><strong>category</strong> {{ $t('ImportNoteCategoryAuto') }}</li>
            <li><strong>sub_category</strong> {{ $t('ImportNoteSubCategoryOptional') }}</li>
          </ul>
        </template>
      </px-card>

      <px-alert v-if="errorMessages.length" tone="danger" :title="$t('Import_Failed_Fix_Below')" class="pximp__panel">
        <ul class="pximp__msglist">
          <li v-for="(err, idx) in errorMessages" :key="'err-' + idx">{{ err }}</li>
        </ul>
      </px-alert>

      <px-alert v-if="warningMessages.length" tone="warning" :title="$t('Warnings')" class="pximp__panel">
        <ul class="pximp__msglist">
          <li v-for="(w, idx) in warningMessages" :key="'warn-' + idx">{{ w }}</li>
        </ul>
      </px-alert>

      <div v-if="uploading" class="pximp__progress">
        <div class="pximp__progress-row"><span>{{ $t('Uploading') }}</span><span class="pxn-num">{{ progress }}%</span></div>
        <div class="pximp__progress-track"><div class="pximp__progress-bar" :style="{ width: progress + '%' }"></div></div>
      </div>

      <div class="pximp__actions">
        <px-button variant="primary" icon="upload" :loading="uploading" :disabled="!canSubmit || uploading" @click="submit">
          {{ uploading ? $t('Processing') : $t('Import_now') }}
        </px-button>
        <px-button variant="secondary" icon="file-spreadsheet" @click="downloadExample">{{ $t('Download_exemple') }}</px-button>
        <px-button variant="ghost" icon="x" :disabled="!file || uploading" @click="clearFile()">{{ $t('Reset') }}</px-button>
      </div>
    </px-card>

    <px-card :title="$t('RequiredAndOptionalColumns')" class="pximp__guide">
      <div class="pximp__chips">
        <span
          v-for="c in activeGuide"
          :key="c.key"
          class="pximp__chip"
          :class="c.required ? 'is-req' : 'is-opt'"
        >{{ c.label }}</span>
      </div>
      <ul class="pximp__notes" v-if="importType === 'single'">
        <li><strong>code</strong> — {{ $t('ImportGuideCodeUnique') }}</li>
        <li><strong>unit</strong> — {{ $t('ImportGuideUnitExists') }}</li>
        <li><strong>category</strong> — {{ $t('ImportGuideCategoryAuto') }}</li>
        <li><strong>sub_category</strong> — {{ $t('ImportGuideSubCategoryOptional') }}</li>
        <li><strong>wholesale price</strong> {{ $t('And') }} <strong>min price</strong> {{ $t('AreOptional') }}</li>
      </ul>
      <ul class="pximp__notes" v-else-if="importType === 'service'">
        <li><strong>code</strong> — {{ $t('ImportGuideCodeUnique') }}</li>
        <li><strong>unit</strong> — {{ $t('ImportGuideUnitExists') }}</li>
        <li><strong>category</strong> — {{ $t('ImportGuideCategoryAuto') }}</li>
        <li><strong>sub_category</strong> — {{ $t('ImportGuideSubCategoryOptional') }}</li>
        <li><strong>wholesale price</strong> {{ $t('And') }} <strong>min price</strong> {{ $t('AreOptional') }} {{ $t('CostAlwaysZeroForServices') }}</li>
      </ul>
      <ul class="pximp__notes" v-else>
        <li><strong>product_code</strong> — {{ $t('ImportNoteProductCodeGroups') }}</li>
        <li><strong>variant_code</strong> — {{ $t('ImportGuideVariantCodeUnique') }}</li>
        <li><strong>unit</strong> — {{ $t('ImportGuideUnitExists') }}</li>
        <li><strong>category</strong> — {{ $t('ImportGuideCategoryAuto') }}</li>
        <li><strong>sub_category</strong> — {{ $t('ImportGuideSubCategoryOptional') }}</li>
        <li><strong>variant wholesale</strong> {{ $t('And') }} <strong>variant min price</strong> {{ $t('AreOptional') }}</li>
      </ul>
    </px-card>

    <px-alert tone="info" bare class="pximp__tip">
      <lucide-icon name="info" :size="13" /> <strong>{{ $t('HeadsUp') }}</strong> — {{ $t('LargeFilesMayTakeLonger') }}
    </px-alert>
  </div>
</template>

<script>
import NProgress from 'nprogress';
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxTabs from "@/components/px-next/PxTabs.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
// axios assumed globally available

export default {
  metaInfo: {
    title: "Importar productos"
  },
  components: {
    PxPageHeader, PxCard, PxTabs, PxButton, PxAlert
  },
  data() {
    return {
      // endpoints
      singleEndpoint: 'products/import/single',
      variantEndpoint: 'products/import/variants',
      serviceEndpoint: 'products/import/service',

      // default selection: keep variant active (solid) and single always outline
      importType: 'single',

      // file state
      file: null,
      fileName: '',
      fileSize: 0,

      // ui state
      uploading: false,
      progress: 0,

      // multi-error support
      errorMessages: [],
      warningMessages: [],

      // dnd
      isDragOver: false,

      // limits
      maxSize: 20 * 1024 * 1024, // 20MB
      accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,.xlsx,.xls',

      // guides (UI-only help)
      singlesGuide: [
        { key: 'name',        label: 'name',           required: true },
        { key: 'code',        label: 'code',           required: true },
        { key: 'Retail price',label: 'Retail price',   required: true },
        { key: 'cost',        label: 'cost',           required: true },
        { key: 'category',    label: 'category',       required: true },
        { key: 'sub_category',label: 'sub_category',   required: false },
        { key: 'unit',        label: 'unit',           required: true },
        { key: 'Wholesale price', label: 'Wholesale price', required: false },
        { key: 'Min price',       label: 'Min price',       required: false },
        { key: 'brand',       label: 'brand',          required: false },
        { key: 'Stock alert', label: 'Stock alert',    required: false },
        { key: 'note',        label: 'note',           required: false }
      ],
      variantsGuide: [
        { key: 'product_name',  label: 'product_name',                 required: true },
        { key: 'product_code',  label: 'product_code',                 required: true },
        { key: 'category',      label: 'category',                     required: true },
        { key: 'sub_category',  label: 'sub_category',                 required: false },
        { key: 'unit',          label: 'unit',                         required: true },
        { key: 'brand',         label: 'brand',                        required: false },
        { key: 'variant_name',  label: 'variant_name',                 required: true },
        { key: 'variant_code',  label: 'variant_code',                 required: true },
        { key: 'variant_cost',  label: 'variant_cost',                 required: true },
        { key: 'variant_price', label: 'variant_price',                required: true },
        { key: 'variant_wholesale', label: 'variant_wholesale',        required: false },
        { key: 'variant_min_price', label: 'variant_min_price',        required: false }
      ],
      serviceGuide: [
        { key: 'name',        label: 'name',           required: true },
        { key: 'code',        label: 'code',           required: true },
        { key: 'Retail price',label: 'Retail price',   required: true },
        { key: 'category',    label: 'category',       required: true },
        { key: 'sub_category',label: 'sub_category',   required: false },
        { key: 'unit',        label: 'unit',           required: true },
        { key: 'Wholesale price', label: 'Wholesale price', required: false },
        { key: 'Min price',       label: 'Min price',       required: false },
        { key: 'brand',       label: 'brand',          required: false },
        { key: 'note',        label: 'note',           required: false }
      ]
    };
  },
  computed: {
    typeTabs() {
      return [
        { value: 'single', label: this.$t('SingleProducts'), icon: 'store' },
        { value: 'variant', label: this.$t('VariantProducts'), icon: 'library' },
        { value: 'service', label: this.$t('ServiceProducts'), icon: 'wrench' }
      ];
    },
    activeGuide() {
      if (this.importType === 'single') return this.singlesGuide;
      if (this.importType === 'service') return this.serviceGuide;
      return this.variantsGuide;
    },
    canSubmit() {
      return !!this.file && this.errorMessages.length === 0;
    },
    prettySize() {
      return this.formatBytes(this.fileSize);
    },
    exampleHref() {
      if (this.importType === 'single') return '/import/exemples/single_products.xlsx';
      if (this.importType === 'service') return '/import/exemples/service_products.xlsx';
      return '/import/exemples/variant_products.xlsx';
    }
  },
  methods: {
    // ---------- UI helpers ----------
    toast(msg, title, variant) {
      if (this.$root && this.$root.$bvToast) {
        this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
      }
    },
    downloadExample() {
      window.open(this.exampleHref, '_blank', 'noopener');
    },
    switchType(type) {
      this.importType = type;
      // keep file, but clear previous error panel to avoid confusion
      this.clearErrors();
    },

    // ---------- DnD + browse ----------
    onDragOver() { this.isDragOver = true; },
    onDragLeave() { this.isDragOver = false; },
    onDrop(e) {
      this.isDragOver = false;
      var f = e && e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0] ? e.dataTransfer.files[0] : null;
      if (f) this.loadFile(f);
    },
    browse() {
      if (this.uploading) return;
      if (this.$refs && this.$refs.file) this.$refs.file.click();
    },
    onFileSelected(e) {
      const f = e && e.target && e.target.files && e.target.files[0] ? e.target.files[0] : null;
      if (!f) return;
      this.loadFile(f);
    },

    // ---------- File load + checks ----------
    loadFile(f) {
      this.clearErrors();
      const msgs = [];

      if (f.size > this.maxSize) {
        msgs.push('File is too large. Please upload a file under the 20MB limit.');
      }
      const name = f.name || '';
      const ext = name.split('.').pop().toLowerCase();
      if (['xlsx','xls'].indexOf(ext) === -1) {
        msgs.push('Unsupported file type. Please upload an .xlsx or .xls file.');
      }

      if (msgs.length) {
        this.errorMessages = msgs;
        // do not keep invalid file
        this.clearFile(false);
        return;
      }

      this.file = f;
      this.fileName = f.name;
      this.fileSize = f.size;
    },
    clearFile(resetInput) {
      if (typeof resetInput === 'undefined') resetInput = true;
      this.file = null; this.fileName = ''; this.fileSize = 0;
      if (resetInput && this.$refs && this.$refs.file) this.$refs.file.value = '';
    },
    clearErrors() {
      this.errorMessages = [];
      this.warningMessages = [];
    },
    formatBytes(bytes) {
      if (!bytes || bytes <= 0) return '0 B';
      var k = 1024; var sizes = ['B','KB','MB','GB','TB'];
      var i = Math.floor(Math.log(bytes) / Math.log(k));
      var v = (bytes / Math.pow(k, i)).toFixed(2);
      return v + ' ' + sizes[i];
    },

    // ---------- Error collectors ----------
    flattenLaravelErrors(errorsObj) {
      const out = [];
      if (!errorsObj || typeof errorsObj !== 'object') return out;
      Object.keys(errorsObj).forEach(k => {
        const v = errorsObj[k];
        if (Array.isArray(v)) {
          v.forEach(m => { if (m) out.push(String(m)); });
        } else if (v) {
          out.push(String(v));
        }
      });
      return out;
    },
    collectErrorsFromResponse(data) {
      const out = [];
      if (!data || typeof data !== 'object') return out;

      if (Array.isArray(data.messages)) {
        data.messages.forEach(m => { if (m) out.push(String(m)); });
      }
      if (data.message) {
        out.push(String(data.message));
      }
      if (data.errors) {
        out.push(...this.flattenLaravelErrors(data.errors));
      }
      if (data.details) {
        if (Array.isArray(data.details)) {
          data.details.forEach(m => { if (m) out.push(String(m)); });
        } else if (typeof data.details === 'string') {
          out.push(data.details);
        }
      }
      if (data.error && typeof data.error === 'string') {
        out.push(data.error);
      }

      // unique list
      const seen = {};
      return out.filter(m => (seen[m] ? false : (seen[m] = true)));
    },
    collectErrorsFromAxios(err) {
      if (err && err.response && err.response.status === 422) {
        const payload = err.response.data || {};
        const list = []
          .concat(this.flattenLaravelErrors(payload.errors))
          .concat(payload.message ? [String(payload.message)] : []);
        return list.length ? list : ['Validation failed. Please check your file and try again.'];
      }

      const payload = err && err.response ? err.response.data : null;
      const list = this.collectErrorsFromResponse(payload);
      if (list.length) return list;

      if (err && err.message) return [String(err.message)];
      return ['Something went wrong while uploading. Please try again.'];
    },

    // ---------- Submit ----------
    async submit() {
      if (!this.file) {
        this.errorMessages = ['Please choose a file to import.'];
        return;
      }

      this.clearErrors();
      this.uploading = true;
      this.progress = 0;
      NProgress.start(); NProgress.set(0.2);

      try {
        var fd = new FormData();
        fd.append('products', this.file);
        const typeMap = { single: 'is_single', variant: 'is_variant', service: 'is_service' };
        fd.append('type', typeMap[this.importType] || 'is_single');

        let endpoint = this.singleEndpoint;
        if (this.importType === 'variant') endpoint = this.variantEndpoint;
        else if (this.importType === 'service') endpoint = this.serviceEndpoint;

        const self = this;
        const response = await axios.post(endpoint, fd, {
          headers: { 'Content-Type': 'multipart/form-data' },
          onUploadProgress: function (pe) {
            if (pe && pe.total) {
              self.progress = Math.round((pe.loaded * 100) / pe.total);
            }
          }
        });

        const data = response && response.data ? response.data : null;
        const ok = data && (data.status === true || data.success === true);

        if (!ok) {
          const msgs = this.collectErrorsFromResponse(data);
          this.errorMessages = msgs.length ? msgs : ['Import failed. Please review your file and try again.'];
          this.toast('Check the error list and fix your file.', 'Import failed', 'danger');
          return;
        }

        this.toast('Imported successfully.', 'Success', 'success');
        this.$router.push({ name: 'index_products' });

      } catch (err) {
        this.errorMessages = this.collectErrorsFromAxios(err);
        this.toast('Check the error list and fix your file.', 'Import failed', 'danger');
      }
      finally {
        NProgress.done();
        this.uploading = false;
        this.progress = 0;
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pximp { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pximp { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pximp__lead { margin: var(--pxn-space-3) 0 var(--pxn-space-6); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }

.pximp__sec { margin-bottom: var(--pxn-space-6); }
.pximp__sec ::v-deep .pxn-card__body { display: flex; flex-direction: column; gap: var(--pxn-space-5); }

.pximp-dz {
  border: 2px dashed var(--pxn-border-strong); border-radius: var(--pxn-radius-lg);
  padding: var(--pxn-space-8) var(--pxn-space-6); cursor: pointer; text-align: center;
  background: var(--pxn-surface-2);
  transition: border-color var(--pxn-dur-1) var(--pxn-ease), background-color var(--pxn-dur-1) var(--pxn-ease);
}
.pximp-dz:hover { border-color: var(--pxn-primary-border); background: var(--pxn-primary-softer); }
.pximp-dz.is-dragover { border-color: var(--pxn-primary); background: var(--pxn-primary-soft); }
.pximp-dz__input { display: none; }
.pximp-dz__icon { color: var(--pxn-primary); }
.pximp-dz__title { margin: var(--pxn-space-3) 0 var(--pxn-space-2); font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pximp-dz__sub { margin: 0; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pximp-dz__file {
  display: inline-flex; align-items: center; gap: var(--pxn-space-3);
  margin-top: var(--pxn-space-4); padding: var(--pxn-space-2) var(--pxn-space-3);
  border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-pill); background: var(--pxn-surface); cursor: default;
}
.pximp-dz__filedot { width: 8px; height: 8px; border-radius: 50%; background: var(--pxn-primary); }
.pximp-dz__filename { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); }
.pximp-dz__filesize { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }

.pximp__example { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); }
.pximp__example ::v-deep .pxn-card__body { display: block; padding: var(--pxn-space-5); }
.pximp__example-head { display: flex; align-items: center; gap: var(--pxn-space-2); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pximp__example-p { margin: var(--pxn-space-3) 0; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pximp__req-badge {
  display: inline-block; padding: 1px var(--pxn-space-2); border-radius: var(--pxn-radius-xs);
  background: var(--pxn-success-soft); color: var(--pxn-success-ink);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
}
.pximp-extbl__wrap { overflow-x: auto; border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-sm); }
.pximp-extbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); white-space: nowrap; }
.pximp-extbl th, .pximp-extbl td { padding: var(--pxn-space-2) var(--pxn-space-4); border: 1px solid var(--pxn-border); text-align: left; }
.pximp-extbl th { background: var(--pxn-surface-2); font-weight: var(--pxn-fw-semibold); }
.pximp-extbl th.req { background: var(--pxn-success-soft); color: var(--pxn-success-ink); }

.pximp__notes { margin: var(--pxn-space-3) 0 0; padding-left: var(--pxn-space-6); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.pximp__notes li { margin-bottom: var(--pxn-space-2); }

.pximp__panel { margin: 0; }
.pximp__msglist { margin: 0; padding-left: var(--pxn-space-6); font-size: var(--pxn-fs-sm); }
.pximp__msglist li { margin-bottom: var(--pxn-space-1); }

.pximp__progress-row { display: flex; justify-content: space-between; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); margin-bottom: var(--pxn-space-2); }
.pximp__progress-track { height: 8px; border-radius: var(--pxn-radius-pill); background: var(--pxn-surface-3); overflow: hidden; }
.pximp__progress-bar { height: 100%; background: var(--pxn-primary); transition: width var(--pxn-dur-1) var(--pxn-ease); }

.pximp__actions { display: flex; gap: var(--pxn-space-3); flex-wrap: wrap; }

.pximp__guide { margin-bottom: var(--pxn-space-5); }
.pximp__chips { display: flex; flex-wrap: wrap; gap: var(--pxn-space-2); margin-bottom: var(--pxn-space-4); }
.pximp__chip {
  display: inline-block; padding: 4px var(--pxn-space-3); border-radius: var(--pxn-radius-pill);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
}
.pximp__chip.is-req { background: var(--pxn-success-soft); color: var(--pxn-success-ink); }
.pximp__chip.is-opt { background: var(--pxn-surface-2); color: var(--pxn-ink-2); border: 1px solid var(--pxn-border); }

.pximp__tip ::v-deep svg { vertical-align: -2px; margin-right: var(--pxn-space-2); }
</style>
