<template>
  <div class="px-next pxosi">
    <!--
      C3.4 — Importación de existencias iniciales px-next. Ruta real:
      /app/products/opening_stock_import (name opening_stock_import).
      Conserva EXACTAMENTE: Excel (.xlsx/.xls, 20MB), modos Simple y Variantes,
      validaciones de archivo, metadata (opening-stock/import/meta), errores por
      fila, resultados/resumen de importación, endpoints actuales
      (opening-stock/import/single | /variants) y permiso opening_stock_import.
      No reinterpreta el formato del archivo ni cambia el contrato backend.
    -->
    <div v-if="!can('opening_stock_import')" class="pxosi__denied">
      <px-empty-state icon="lock" title="No tienes permiso para importar existencias"
        description="Pide a un administrador el permiso «opening_stock_import»." />
    </div>

    <template v-else>
      <px-page-header
        title="Importar existencias iniciales"
        :breadcrumbs="[{ label: 'Productos' }, { label: 'Importar existencias' }]"
      >
        <template #actions>
          <px-button variant="ghost" icon="arrow-left" @click="$router.push({ name: 'index_products' })">Volver a productos</px-button>
        </template>
      </px-page-header>

      <p class="pxosi__lead">Añade cantidades iniciales de stock a partir de un archivo Excel.</p>

      <div class="pxosi__grid">
        <div class="pxosi__main">
          <px-card class="pxosi__sec">
            <px-tabs :tabs="typeTabs" :value="importType" @input="switchType" />

            <div class="pxosi__wh">
              <px-field label="Almacén" required>
                <template #default="{ id }">
                  <vs-px
                    :input-id="id"
                    :invalid="warehouseTouched && !warehouse_id"
                    v-model="warehouse_id"
                    :reduce="o => o.value"
                    placeholder="Elegir almacén"
                    :options="warehouseOptions"
                  />
                </template>
              </px-field>
              <p class="pxosi__whhint">El stock se añadirá a este almacén.</p>
              <p v-if="warehouseTouched && !warehouse_id" class="pxosi__wherr">Elige un almacén.</p>
            </div>

            <div
              class="pxosi-dz"
              :class="{ 'is-dragover': isDragOver, 'has-file': !!file }"
              @dragover.prevent="onDragOver"
              @dragleave.prevent="onDragLeave"
              @drop.prevent="onDrop"
              @click="browse"
            >
              <input ref="file" type="file" class="pxosi-dz__input" @change="onFileSelected" :accept="accept" />
              <div class="pxosi-dz__inner">
                <div class="pxosi-dz__icon"><lucide-icon name="upload" :size="26" /></div>
                <p class="pxosi-dz__title">Haz clic o suelta tu archivo Excel aquí</p>
                <p class="pxosi-dz__sub">Formatos permitidos: XLSX, XLS · Tamaño máximo: 20 MB</p>

                <div v-if="file" class="pxosi-dz__file" @click.stop>
                  <span class="pxosi-dz__filedot"></span>
                  <div class="pxosi-dz__filemeta">
                    <div class="pxosi-dz__filename">{{ fileName }}</div>
                    <div class="pxosi-dz__filesize">{{ prettySize }}</div>
                  </div>
                  <px-button size="sm" variant="danger" icon="x" @click="clearFile()">Quitar</px-button>
                </div>
              </div>
            </div>

            <px-card class="pxosi__example" flush>
              <div class="pxosi__example-head"><lucide-icon name="info" :size="15" /> Formato de ejemplo</div>
              <template v-if="importType === 'single'">
                <p class="pxosi__example-p">Una fila por producto. Las columnas marcadas son obligatorias.</p>
                <div class="pxosi-extbl__wrap pxn-scroll">
                  <table class="pxosi-extbl">
                    <thead><tr><th class="req">product_code</th><th class="req">qty</th></tr></thead>
                    <tbody>
                      <tr><td>TSHIRT-BLUE</td><td>10</td></tr>
                      <tr><td>MUG-COF-01</td><td>25</td></tr>
                    </tbody>
                  </table>
                </div>
                <ul class="pxosi__notes">
                  <li><strong>product_code</strong>: debe existir ya en Productos.</li>
                  <li><strong>qty</strong>: cantidad a añadir al stock actual.</li>
                </ul>
              </template>
              <template v-else>
                <p class="pxosi__example-p">Una fila por variante. Las columnas marcadas son obligatorias.</p>
                <div class="pxosi-extbl__wrap pxn-scroll">
                  <table class="pxosi-extbl">
                    <thead><tr><th class="req">product_code</th><th class="req">variant_code</th><th class="req">qty</th></tr></thead>
                    <tbody>
                      <tr><td>TSHIRT-100</td><td>TSHIRT-100-S</td><td>5</td></tr>
                      <tr><td>TSHIRT-100</td><td>TSHIRT-100-M</td><td>8</td></tr>
                      <tr><td>TSHIRT-100</td><td>TSHIRT-100-L</td><td>7</td></tr>
                    </tbody>
                  </table>
                </div>
                <ul class="pxosi__notes">
                  <li><strong>product_code</strong> y <strong>variant_code</strong>: deben existir y corresponderse.</li>
                  <li><strong>qty</strong>: cantidad a añadir al stock de la variante.</li>
                </ul>
              </template>
              <div class="pxosi__example-dl">
                <px-button size="sm" variant="secondary" icon="file-spreadsheet" :href="exampleHref">Descargar ejemplo</px-button>
              </div>
            </px-card>

            <px-alert v-if="errorMessages.length" tone="danger" title="La importación falló. Corrige lo siguiente:" class="pxosi__panel">
              <ul class="pxosi__msglist">
                <li v-for="(err, idx) in errorMessages" :key="'err-' + idx">{{ err }}</li>
              </ul>
            </px-alert>

            <px-alert v-if="warningMessages.length" tone="warning" title="Avisos" class="pxosi__panel">
              <ul class="pxosi__msglist">
                <li v-for="(w, idx) in warningMessages" :key="'warn-' + idx">{{ w }}</li>
              </ul>
            </px-alert>

            <div v-if="uploading" class="pxosi__progress">
              <div class="pxosi__progress-row"><span>Subiendo</span><span class="pxn-num">{{ progress }}%</span></div>
              <div class="pxosi__progress-track"><div class="pxosi__progress-bar" :style="{ width: progress + '%' }"></div></div>
            </div>

            <div class="pxosi__actions">
              <px-button variant="primary" icon="upload" :loading="uploading" :disabled="!canSubmit || uploading" @click="submit">
                {{ uploading ? 'Procesando…' : 'Importar ahora' }}
              </px-button>
              <px-button variant="ghost" icon="x" :disabled="(!file && !warehouse_id) || uploading" @click="resetAll">Reiniciar</px-button>
            </div>
          </px-card>
        </div>

        <aside class="pxosi__rail">
          <px-card title="Columnas requeridas" class="pxosi__guide">
            <div class="pxosi__chips">
              <span class="pxosi__chip">product_code</span>
              <span v-if="importType === 'variant'" class="pxosi__chip">variant_code</span>
              <span class="pxosi__chip">qty</span>
            </div>
            <ul class="pxosi__notes">
              <li><strong>product_code</strong> — debe existir en Productos.</li>
              <li v-if="importType === 'variant'"><strong>variant_code</strong> — debe existir y pertenecer al producto.</li>
              <li><strong>qty</strong> — número positivo.</li>
            </ul>
          </px-card>
          <px-alert tone="info" bare class="pxosi__tip">
            <lucide-icon name="info" :size="13" /> Los archivos grandes pueden tardar más en procesarse.
          </px-alert>
        </aside>
      </div>
    </template>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxTabs from "@/components/px-next/PxTabs.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  name: "OpeningStockImportNext",
  metaInfo: { title: "Importar existencias iniciales" },
  components: {
    PxPageHeader, PxCard, PxTabs, PxField, PxButton, PxAlert, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      // endpoints — sin cambios respecto al legacy
      metaEndpoint: "opening-stock/import/meta",
      singleEndpoint: "opening-stock/import/single",
      variantEndpoint: "opening-stock/import/variants",

      importType: "single", // single | variant
      warehouse_id: "",
      warehouseTouched: false,
      warehouses: [],

      file: null,
      fileName: "",
      fileSize: 0,

      uploading: false,
      progress: 0,

      errorMessages: [],
      warningMessages: [],

      isDragOver: false,

      maxSize: 20 * 1024 * 1024,
      accept: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,.xlsx,.xls"
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    typeTabs() {
      return [
        { value: "single", label: "Productos simples", icon: "store" },
        { value: "variant", label: "Productos con variantes", icon: "library" }
      ];
    },
    warehouseOptions() {
      return (this.warehouses || []).map(w => ({ label: w.name, value: w.id }));
    },
    canSubmit() {
      return !!this.file && !!this.warehouse_id && this.errorMessages.length === 0;
    },
    prettySize() {
      return this.formatBytes(this.fileSize);
    },
    exampleHref() {
      return this.importType === "single"
        ? "/import/exemples/opening_stock_single.xlsx"
        : "/import/exemples/opening_stock_variants.xlsx";
    }
  },
  created() {
    this.loadWarehouses();
  },
  methods: {
    can(p) {
      const list = Array.isArray(this.currentUserPermissions) ? this.currentUserPermissions : [];
      return list.includes(p);
    },
    toast(msg, title, variant) {
      if (this.$root && this.$root.$bvToast) {
        this.$root.$bvToast.toast(msg, { title, variant, solid: true });
      }
    },
    switchType(type) {
      this.importType = type;
      this.clearErrors();
    },
    onDragOver() { this.isDragOver = true; },
    onDragLeave() { this.isDragOver = false; },
    onDrop(e) {
      this.isDragOver = false;
      const files = e && e.dataTransfer && e.dataTransfer.files;
      const f = files && files[0] ? files[0] : null;
      if (f) this.loadFile(f);
    },
    browse() {
      if (this.uploading) return;
      if (this.$refs && this.$refs.file) this.$refs.file.click();
    },
    onFileSelected(e) {
      const files = e && e.target && e.target.files;
      const f = files && files[0] ? files[0] : null;
      if (!f) return;
      this.loadFile(f);
    },
    loadFile(f) {
      this.clearErrors();
      const msgs = [];
      if (f.size > this.maxSize) msgs.push("El archivo es demasiado grande. Sube un archivo de menos de 20 MB.");
      const name = f.name || "";
      const ext = name.split(".").pop().toLowerCase();
      if (["xlsx", "xls"].indexOf(ext) === -1) msgs.push("Tipo de archivo no admitido. Sube un archivo .xlsx o .xls.");
      if (msgs.length) { this.errorMessages = msgs; this.clearFile(false); return; }
      this.file = f; this.fileName = f.name; this.fileSize = f.size;
    },
    clearFile(resetInput) {
      if (typeof resetInput === "undefined") resetInput = true;
      this.file = null; this.fileName = ""; this.fileSize = 0;
      if (resetInput && this.$refs && this.$refs.file) this.$refs.file.value = "";
    },
    resetAll() {
      this.clearFile(true);
      this.warehouse_id = "";
      this.warehouseTouched = false;
      this.clearErrors();
    },
    clearErrors() {
      this.errorMessages = [];
      this.warningMessages = [];
    },
    formatBytes(bytes) {
      if (!bytes || bytes <= 0) return "0 B";
      const k = 1024;
      const sizes = ["B", "KB", "MB", "GB", "TB"];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      const v = (bytes / Math.pow(k, i)).toFixed(2);
      return v + " " + sizes[i];
    },

    // --- colectores de errores (idénticos al legacy: objeto o array) ---
    flattenLaravelErrorsObject(errorsObj) {
      const out = [];
      if (!errorsObj || typeof errorsObj !== "object") return out;
      for (const k in errorsObj) {
        if (!Object.prototype.hasOwnProperty.call(errorsObj, k)) continue;
        const v = errorsObj[k];
        if (Array.isArray(v)) {
          for (let i = 0; i < v.length; i++) if (v[i]) out.push(String(v[i]));
        } else if (v) out.push(String(v));
      }
      return out;
    },
    collectErrorsFromResponse(data) {
      let out = [];
      if (!data || typeof data !== "object") return out;
      if (data.message && typeof data.message === "string") out.push(data.message);
      if (Array.isArray(data.errors)) {
        for (let i = 0; i < data.errors.length; i++) if (data.errors[i]) out.push(String(data.errors[i]));
      } else if (data.errors && typeof data.errors === "object") {
        out = out.concat(this.flattenLaravelErrorsObject(data.errors));
      }
      if (Array.isArray(data.details)) {
        for (let j = 0; j < data.details.length; j++) if (data.details[j]) out.push(String(data.details[j]));
      } else if (data.details && typeof data.details === "string") {
        out.push(data.details);
      }
      if (data.error && typeof data.error === "string") out.push(data.error);
      const seen = {};
      const unique = [];
      for (let u = 0; u < out.length; u++) {
        if (!seen[out[u]]) { seen[out[u]] = true; unique.push(out[u]); }
      }
      return unique;
    },
    collectErrorsFromAxios(err) {
      if (err && err.response && err.response.status === 422) {
        const payload = err.response.data || {};
        let list = [];
        if (Array.isArray(payload.errors)) {
          list = list.concat(payload.errors);
        } else if (payload.errors && typeof payload.errors === "object") {
          list = list.concat(this.flattenLaravelErrorsObject(payload.errors));
        }
        if (payload.message) list.push(String(payload.message));
        return list.length ? list : ["La validación falló. Revisa tu archivo e inténtalo de nuevo."];
      }
      const payload2 = err && err.response ? err.response.data : null;
      const list2 = this.collectErrorsFromResponse(payload2);
      if (list2.length) return list2;
      if (err && err.message) return [String(err.message)];
      return ["Algo salió mal durante la subida. Inténtalo de nuevo."];
    },

    async submit() {
      this.warehouseTouched = true;
      if (!this.warehouse_id) {
        this.errorMessages = ["Elige un almacén."];
        return;
      }
      if (!this.file) {
        this.errorMessages = ["Elige un archivo para importar."];
        return;
      }

      this.clearErrors();
      this.uploading = true;
      this.progress = 0;
      NProgress.start(); NProgress.set(0.2);

      try {
        const fd = new FormData();
        fd.append("warehouse_id", this.warehouse_id);
        fd.append("products", this.file);

        const endpoint = this.importType === "single" ? this.singleEndpoint : this.variantEndpoint;
        const self = this;

        const response = await window.axios.post(endpoint, fd, {
          headers: { "Content-Type": "multipart/form-data" },
          onUploadProgress(pe) {
            if (pe && pe.total) self.progress = Math.round((pe.loaded * 100) / pe.total);
          }
        });

        const data = response && response.data ? response.data : null;
        const ok = data && (data.status === true || data.success === true);

        if (!ok) {
          const msgs = this.collectErrorsFromResponse(data);
          this.errorMessages = msgs.length ? msgs : ["La importación falló. Revisa tu archivo e inténtalo de nuevo."];
          this.toast("Revisa la lista de errores y corrige tu archivo.", "Importación fallida", "danger");
          return;
        }

        this.toast("Existencias iniciales importadas correctamente.", "Éxito", "success");
        this.$router.push({ name: "index_products" });
      } catch (err) {
        this.errorMessages = this.collectErrorsFromAxios(err);
        this.toast("Revisa la lista de errores y corrige tu archivo.", "Importación fallida", "danger");
      } finally {
        NProgress.done();
        this.uploading = false;
        this.progress = 0;
      }
    },

    async loadWarehouses() {
      try {
        const { data } = await window.axios.get(this.metaEndpoint);
        this.warehouses = data && data.warehouses ? data.warehouses : [];
      } catch (e) {
        this.warehouses = [];
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxosi { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxosi { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxosi__denied { padding: var(--pxn-space-12) 0; }
.pxosi__lead { margin: var(--pxn-space-3) 0 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }

.pxosi__grid { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: var(--pxn-space-6); margin-top: var(--pxn-space-6); }
@media (max-width: 1080px) { .pxosi__grid { grid-template-columns: minmax(0, 1fr); } }
.pxosi__main { min-width: 0; }
.pxosi__sec :deep(.pxn-card__body) { display: flex; flex-direction: column; gap: var(--pxn-space-5); }

.pxosi__wh { }
.pxosi__whhint { margin: var(--pxn-space-2) 0 0; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxosi__wherr { margin: var(--pxn-space-2) 0 0; font-size: var(--pxn-fs-xs); color: var(--pxn-danger-ink); }

.pxosi-dz {
  border: 2px dashed var(--pxn-border-strong); border-radius: var(--pxn-radius-lg);
  padding: var(--pxn-space-8) var(--pxn-space-6); cursor: pointer; text-align: center;
  background: var(--pxn-surface-2); transition: border-color var(--pxn-dur-1) var(--pxn-ease), background-color var(--pxn-dur-1) var(--pxn-ease);
}
.pxosi-dz:hover { border-color: var(--pxn-primary-border); background: var(--pxn-primary-softer); }
.pxosi-dz.is-dragover { border-color: var(--pxn-primary); background: var(--pxn-primary-soft); }
.pxosi-dz__input { display: none; }
.pxosi-dz__icon { color: var(--pxn-primary); }
.pxosi-dz__title { margin: var(--pxn-space-3) 0 var(--pxn-space-2); font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxosi-dz__sub { margin: 0; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxosi-dz__file {
  display: inline-flex; align-items: center; gap: var(--pxn-space-3);
  margin-top: var(--pxn-space-4); padding: var(--pxn-space-2) var(--pxn-space-3);
  border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-pill); background: var(--pxn-surface); cursor: default;
}
.pxosi-dz__filedot { width: 8px; height: 8px; border-radius: 50%; background: var(--pxn-primary); }
.pxosi-dz__filename { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); }
.pxosi-dz__filesize { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }

.pxosi__example { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); }
.pxosi__example :deep(.pxn-card__body) { display: block; padding: var(--pxn-space-5); }
.pxosi__example-head { display: flex; align-items: center; gap: var(--pxn-space-2); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxosi__example-p { margin: var(--pxn-space-3) 0; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxosi-extbl__wrap { overflow-x: auto; border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-sm); }
.pxosi-extbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxosi-extbl th, .pxosi-extbl td { padding: var(--pxn-space-2) var(--pxn-space-4); border: 1px solid var(--pxn-border); text-align: left; }
.pxosi-extbl th { background: var(--pxn-surface-2); font-weight: var(--pxn-fw-semibold); }
.pxosi-extbl th.req { background: var(--pxn-success-soft); color: var(--pxn-success-ink); }
.pxosi__notes { margin: var(--pxn-space-3) 0 0; padding-left: var(--pxn-space-6); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.pxosi__notes li { margin-bottom: var(--pxn-space-2); }
.pxosi__example-dl { margin-top: var(--pxn-space-4); }

.pxosi__panel { margin: 0; }
.pxosi__msglist { margin: 0; padding-left: var(--pxn-space-6); font-size: var(--pxn-fs-sm); }
.pxosi__msglist li { margin-bottom: var(--pxn-space-1); }

.pxosi__progress { }
.pxosi__progress-row { display: flex; justify-content: space-between; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); margin-bottom: var(--pxn-space-2); }
.pxosi__progress-track { height: 8px; border-radius: var(--pxn-radius-pill); background: var(--pxn-surface-3); overflow: hidden; }
.pxosi__progress-bar { height: 100%; background: var(--pxn-primary); transition: width var(--pxn-dur-1) var(--pxn-ease); }

.pxosi__actions { display: flex; gap: var(--pxn-space-3); flex-wrap: wrap; }

.pxosi__rail { min-width: 0; display: flex; flex-direction: column; gap: var(--pxn-space-5); }
@media (max-width: 1080px) { .pxosi__rail { order: -1; } }
.pxosi__chips { display: flex; flex-wrap: wrap; gap: var(--pxn-space-2); }
.pxosi__chip {
  display: inline-block; padding: 4px var(--pxn-space-3); border-radius: var(--pxn-radius-pill);
  background: var(--pxn-success-soft); color: var(--pxn-success-ink);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
}
.pxosi__tip :deep(svg) { vertical-align: -2px; margin-right: var(--pxn-space-2); }
</style>
