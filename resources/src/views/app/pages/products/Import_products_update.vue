<template>
  <div class="px-next pximpu">
    <px-page-header
      :title="$t('ImportProductsUpdateOnly')"
      :breadcrumbs="[{ label: $t('Products') }, { label: $t('ImportProductsUpdateOnly') }]"
    >
      <template #actions>
        <px-button variant="ghost" icon="arrow-left" @click="$router.push({ name: 'index_products' })">{{ $t('BackToList') }}</px-button>
      </template>
    </px-page-header>

    <p class="pximpu__lead">{{ $t('ImportUpdateSubtitle') }}</p>

    <px-card class="pximpu__sec">
      <!-- Dropzone -->
      <div
        class="pximpu-dz"
        :class="{ 'is-dragover': isDragOver, 'has-file': file }"
        @dragover.prevent="onDragOver"
        @dragleave.prevent="onDragLeave"
        @drop.prevent="onDrop"
        @click="browse"
      >
        <input ref="file" type="file" class="pximpu-dz__input" @change="onFileSelected" :accept="accept" />
        <div class="pximpu-dz__icon"><lucide-icon name="upload" :size="26" /></div>
        <p class="pximpu-dz__title">{{ $t('Click_Or_Drop_CSV_Excel') }}</p>
        <p class="pximpu-dz__sub">{{ $t('Allowed_Format_CSV_Excel') }}</p>
        <div v-if="file" class="pximpu-dz__file" @click.stop>
          <span class="pximpu-dz__filedot"></span>
          <div class="pximpu-dz__filemeta">
            <div class="pximpu-dz__filename">{{ fileName }}</div>
            <div class="pximpu-dz__filesize">{{ prettySize }}</div>
          </div>
          <px-button size="sm" variant="danger" icon="x" @click="clearFile()">{{ $t('Remove') }}</px-button>
        </div>
      </div>

      <!-- File format -->
      <px-card class="pximpu__example" flush>
        <div class="pximpu__example-head"><lucide-icon name="info" :size="15" /> {{ $t('FileFormat') }}</div>
        <p class="pximpu__example-p">
          {{ $t('ImportUpdateFileMustHave3Columns') }}
          <span class="pximpu__req-badge">code</span>,
          <span class="pximpu__req-badge">cost</span>, {{ $t('And') }}
          <span class="pximpu__req-badge">retail_price</span>. {{ $t('ProductsMatchedByCode') }}
        </p>
        <div class="pximpu-extbl__wrap pxn-scroll">
          <table class="pximpu-extbl">
            <thead>
              <tr><th class="req">code</th><th class="req">cost</th><th class="req">retail_price</th></tr>
            </thead>
            <tbody>
              <tr><td>PROD-001</td><td>10.50</td><td>19.99</td></tr>
              <tr><td>PROD-002</td><td>5.25</td><td>12.50</td></tr>
              <tr><td>PROD-003</td><td>8.00</td><td>15.00</td></tr>
            </tbody>
          </table>
        </div>
        <ul class="pximpu__notes">
          <li><strong>code</strong> — {{ $t('ImportUpdateNoteCodeMatch') }}</li>
          <li><strong>cost</strong> — {{ $t('ImportUpdateNoteCost') }}</li>
          <li><strong>retail_price</strong> — {{ $t('ImportUpdateNoteRetailPrice') }}</li>
          <li>{{ $t('ImportUpdateNoteOnlyMatching') }}</li>
        </ul>
      </px-card>

      <px-alert v-if="errorMessages.length" tone="danger" :title="$t('Import_Failed_Fix_Below')" class="pximpu__panel">
        <ul class="pximpu__msglist">
          <li v-for="(err, idx) in errorMessages" :key="'err-' + idx">{{ err }}</li>
        </ul>
      </px-alert>

      <px-alert v-if="successMessage" tone="success" :title="successMessage" class="pximpu__panel">
        <div v-if="importResults" class="pximpu__results">
          <div>{{ $t('Updated') }}: {{ importResults.updated }} {{ $t('ProductsLower') }}</div>
          <div v-if="importResults.not_found > 0" class="pximpu__results-warn">
            {{ $t('NotFound') }}: {{ importResults.not_found }} {{ $t('CodesLower') }}
          </div>
          <div v-if="importResults.errors > 0" class="pximpu__results-err">
            {{ $t('Errors') }}: {{ importResults.errors }}
          </div>
        </div>
      </px-alert>

      <px-alert v-if="warningMessages.length" tone="warning" :title="$t('Warnings')" class="pximpu__panel">
        <ul class="pximpu__msglist">
          <li v-for="(w, idx) in warningMessages" :key="'warn-' + idx">{{ w }}</li>
        </ul>
      </px-alert>

      <div v-if="uploading" class="pximpu__progress">
        <div class="pximpu__progress-row"><span>{{ $t('Uploading') }}</span><span class="pxn-num">{{ progress }}%</span></div>
        <div class="pximpu__progress-track"><div class="pximpu__progress-bar" :style="{ width: progress + '%' }"></div></div>
      </div>

      <div class="pximpu__actions">
        <px-button variant="primary" icon="upload" :loading="uploading" :disabled="!canSubmit || uploading" @click="submit">
          {{ uploading ? $t('Processing') : $t('UpdateProducts') }}
        </px-button>
        <px-button variant="secondary" icon="file-spreadsheet" @click="downloadExample">{{ $t('Download_exemple') }}</px-button>
        <px-button variant="ghost" icon="x" :disabled="!file || uploading" @click="clearFile()">{{ $t('Reset') }}</px-button>
      </div>
    </px-card>

    <px-card :title="$t('ImportantNotes')" class="pximpu__guide">
      <ul class="pximpu__notes">
        <li v-html="$t('ImportUpdateOnlyUpdatesFields')"></li>
        <li v-html="$t('ImportUpdateMatchedByCode')"></li>
        <li>{{ $t('ImportUpdateSkippedIfMissing') }}</li>
        <li>{{ $t('ImportUpdateOtherFieldsUnchanged') }}</li>
        <li>{{ $t('ImportUpdateCsvOrExcel') }}</li>
      </ul>
    </px-card>

    <px-alert tone="info" bare class="pximpu__tip">
      <lucide-icon name="info" :size="13" /> <strong>{{ $t('HeadsUp') }}</strong> — {{ $t('LargeFilesMayTakeLongerCodesMatch') }}
    </px-alert>
  </div>
</template>

<script>
import NProgress from 'nprogress';
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
// axios assumed globally available

export default {
  metaInfo: {
    title: "Actualizar productos (solo actualización)"
  },
  components: {
    PxPageHeader, PxCard, PxButton, PxAlert
  },
  data() {
    return {
      // endpoint
      endpoint: 'products/import/update-only',

      // file state
      file: null,
      fileName: '',
      fileSize: 0,

      // ui state
      uploading: false,
      progress: 0,
      successMessage: '',
      importResults: null,

      // multi-error support
      errorMessages: [],
      warningMessages: [],

      // dnd
      isDragOver: false,

      // limits
      maxSize: 20 * 1024 * 1024, // 20MB
      accept: '.csv,.xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv',
    };
  },
  computed: {
    canSubmit() {
      return !!this.file && this.errorMessages.length === 0;
    },
    prettySize() {
      return this.formatBytes(this.fileSize);
    },
    exampleHref() {
      return '/import/exemples/update_products.csv';
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
      this.successMessage = '';
      this.importResults = null;
      const msgs = [];

      if (f.size > this.maxSize) {
        msgs.push('File is too large. Please upload a file under the 20MB limit.');
      }
      const name = f.name || '';
      const ext = name.split('.').pop().toLowerCase();
      if (['xlsx','xls','csv'].indexOf(ext) === -1) {
        msgs.push('Unsupported file type. Please upload a .csv, .xlsx or .xls file.');
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
      this.successMessage = '';
      this.importResults = null;
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
      this.successMessage = '';
      this.importResults = null;
      this.uploading = true;
      this.progress = 0;
      NProgress.start(); NProgress.set(0.2);

      try {
        var fd = new FormData();
        fd.append('products', this.file);

        const self = this;
        const response = await axios.post(this.endpoint, fd, {
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
          this.errorMessages = msgs.length ? msgs : [this.$t('ImportFailedReviewFile')];
          this.toast(this.$t('Check_the_error_list_and_fix_your_file'), this.$t('Import_failed'), 'danger');
          return;
        }

        // Success - show results
        this.importResults = {
          updated: data.updated || 0,
          not_found: data.not_found || 0,
          errors: data.errors || 0
        };
        this.successMessage = data.message || this.$t('ProductsUpdatedSuccessfully');
        this.toast(this.successMessage, this.$t('Success'), 'success');

        // Redirect to products index after showing success message
        setTimeout(() => {
          this.$router.push({ name: 'index_products' });
        }, 2000);

      } catch (err) {
        this.errorMessages = this.collectErrorsFromAxios(err);
        this.toast(this.$t('Check_the_error_list_and_fix_your_file'), this.$t('Import_failed'), 'danger');
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
.pximpu { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pximpu { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pximpu__lead { margin: var(--pxn-space-3) 0 var(--pxn-space-6); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }

.pximpu__sec { margin-bottom: var(--pxn-space-6); }
.pximpu__sec ::v-deep .pxn-card__body { display: flex; flex-direction: column; gap: var(--pxn-space-5); }

.pximpu-dz {
  border: 2px dashed var(--pxn-border-strong); border-radius: var(--pxn-radius-lg);
  padding: var(--pxn-space-8) var(--pxn-space-6); cursor: pointer; text-align: center;
  background: var(--pxn-surface-2);
  transition: border-color var(--pxn-dur-1) var(--pxn-ease), background-color var(--pxn-dur-1) var(--pxn-ease);
}
.pximpu-dz:hover { border-color: var(--pxn-primary-border); background: var(--pxn-primary-softer); }
.pximpu-dz.is-dragover { border-color: var(--pxn-primary); background: var(--pxn-primary-soft); }
.pximpu-dz__input { display: none; }
.pximpu-dz__icon { color: var(--pxn-primary); }
.pximpu-dz__title { margin: var(--pxn-space-3) 0 var(--pxn-space-2); font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pximpu-dz__sub { margin: 0; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pximpu-dz__file {
  display: inline-flex; align-items: center; gap: var(--pxn-space-3);
  margin-top: var(--pxn-space-4); padding: var(--pxn-space-2) var(--pxn-space-3);
  border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-pill); background: var(--pxn-surface); cursor: default;
}
.pximpu-dz__filedot { width: 8px; height: 8px; border-radius: 50%; background: var(--pxn-primary); }
.pximpu-dz__filename { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); }
.pximpu-dz__filesize { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }

.pximpu__example { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); }
.pximpu__example ::v-deep .pxn-card__body { display: block; padding: var(--pxn-space-5); }
.pximpu__example-head { display: flex; align-items: center; gap: var(--pxn-space-2); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pximpu__example-p { margin: var(--pxn-space-3) 0; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pximpu__req-badge {
  display: inline-block; padding: 1px var(--pxn-space-2); border-radius: var(--pxn-radius-xs);
  background: var(--pxn-success-soft); color: var(--pxn-success-ink);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
}
.pximpu-extbl__wrap { overflow-x: auto; border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-sm); }
.pximpu-extbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); white-space: nowrap; }
.pximpu-extbl th, .pximpu-extbl td { padding: var(--pxn-space-2) var(--pxn-space-4); border: 1px solid var(--pxn-border); text-align: left; }
.pximpu-extbl th { background: var(--pxn-surface-2); font-weight: var(--pxn-fw-semibold); }
.pximpu-extbl th.req { background: var(--pxn-success-soft); color: var(--pxn-success-ink); }

.pximpu__notes { margin: var(--pxn-space-3) 0 0; padding-left: var(--pxn-space-6); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.pximpu__notes li { margin-bottom: var(--pxn-space-2); }

.pximpu__panel { margin: 0; }
.pximpu__msglist { margin: 0; padding-left: var(--pxn-space-6); font-size: var(--pxn-fs-sm); }
.pximpu__msglist li { margin-bottom: var(--pxn-space-1); }
.pximpu__results { font-size: var(--pxn-fs-sm); display: flex; flex-direction: column; gap: 2px; }
.pximpu__results-warn { color: var(--pxn-warning-ink); }
.pximpu__results-err { color: var(--pxn-danger-ink); }

.pximpu__progress-row { display: flex; justify-content: space-between; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); margin-bottom: var(--pxn-space-2); }
.pximpu__progress-track { height: 8px; border-radius: var(--pxn-radius-pill); background: var(--pxn-surface-3); overflow: hidden; }
.pximpu__progress-bar { height: 100%; background: var(--pxn-primary); transition: width var(--pxn-dur-1) var(--pxn-ease); }

.pximpu__actions { display: flex; gap: var(--pxn-space-3); flex-wrap: wrap; }

.pximpu__guide { margin-bottom: var(--pxn-space-5); }

.pximpu__tip ::v-deep svg { vertical-align: -2px; margin-right: var(--pxn-space-2); }
</style>
