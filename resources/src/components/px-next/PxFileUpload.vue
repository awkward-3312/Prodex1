<template>
  <div
    class="pxn-fu"
    :class="{ 'is-drag': dragging, 'is-disabled': disabled, 'is-invalid': !!shownError, 'has-file': files.length > 0, 'is-busy': uploading }"
  >
    <!-- La zona completa es un <label>: el clic (y Enter/Space sobre el input nativo enfocado) abre el selector sin JS. -->
    <label
      class="pxn-fu__zone"
      :for="inputId"
      @dragenter.prevent="onDragEnter"
      @dragover.prevent="onDragOver"
      @dragleave.prevent="onDragLeave"
      @drop.prevent="onDrop"
    >
      <input
        :id="inputId"
        ref="input"
        class="pxn-fu__native"
        type="file"
        :accept="accept || null"
        :multiple="multiple"
        :disabled="disabled || uploading"
        :aria-describedby="describedBy"
        :aria-invalid="shownError ? 'true' : null"
        @change="onInputChange"
      />

      <!-- Vacío / arrastrando -->
      <span v-if="!files.length" class="pxn-fu__prompt">
        <span class="pxn-fu__icon" aria-hidden="true"><lucide-icon name="upload" :size="22" /></span>
        <span class="pxn-fu__title">{{ dragging ? dropText : label }}</span>
        <span v-if="help" :id="helpId" class="pxn-fu__help">{{ help }}</span>
        <span class="pxn-fu__browse">{{ browseText }}</span>
      </span>

      <!-- Archivo(s) seleccionado(s) -->
      <span v-else class="pxn-fu__files">
        <span v-for="(f, i) in files" :key="fileKey(f, i)" class="pxn-fu__file">
          <span class="pxn-fu__fileicon" aria-hidden="true"><lucide-icon name="file" :size="20" /></span>
          <span class="pxn-fu__meta">
            <span class="pxn-fu__name" :title="f.name">{{ f.name }}</span>
            <span class="pxn-fu__size">{{ formatSize(f.size) }}</span>
          </span>
          <span class="pxn-fu__actions">
            <button v-if="!multiple" type="button" class="pxn-fu__btn" :disabled="disabled || uploading" @click.prevent="openPicker">{{ replaceText }}</button>
            <button
              type="button"
              class="pxn-fu__btn pxn-fu__btn--icon"
              :disabled="disabled || uploading"
              :aria-label="removeText + ': ' + f.name"
              :title="removeText"
              @click.prevent.stop="removeAt(i)"
            ><lucide-icon name="trash-2" :size="16" aria-hidden="true" /></button>
          </span>
        </span>
        <span v-if="dragging" class="pxn-fu__drophint">{{ dropText }}</span>
      </span>

      <!-- Subida en curso: progreso real si el padre lo conoce; si no, estado indeterminado -->
      <span v-if="uploading" class="pxn-fu__busy">
        <template v-if="typeof progress === 'number'">
          <span class="pxn-fu__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="Math.round(progress)" :aria-label="uploadingText">
            <span class="pxn-fu__barfill" :style="{ width: Math.max(0, Math.min(100, progress)) + '%' }"></span>
          </span>
          <span class="pxn-fu__pct">{{ Math.round(progress) }}%</span>
        </template>
        <px-loader v-else size="sm" :label="uploadingText" />
      </span>
    </label>

    <p v-if="shownError" :id="errorId" class="pxn-fu__error" role="alert">
      <lucide-icon name="alert-circle" :size="14" aria-hidden="true" />
      <span>{{ shownError }}</span>
    </p>
  </div>
</template>

<script>
import PxLoader from "./PxLoader.vue";

// PxFileUpload: selección de archivos por clic, teclado y arrastrar-soltar. Solo gestiona el archivo y la UI:
// NO sube nada (no conoce endpoints); el padre decide cuándo y dónde subir, y muestra éxito/errores del backend.
// v-model = File (o File[] con `multiple`), sin convertir a base64. La validación de cliente (tipo/tamaño/cantidad)
// no sustituye a la del backend, que sigue siendo la autoridad.
let uid = 0;

const DEFAULT_MESSAGES = {
  type: "«{name}» no es un tipo de archivo permitido.",
  size: "«{name}» supera el tamaño máximo de {max}.",
  multiple: "Solo puedes seleccionar un archivo.",
  folder: "«{name}» es una carpeta; selecciona archivos.",
  empty: "«{name}» está vacío."
};

export default {
  name: "PxFileUpload",
  components: { PxLoader },
  model: { prop: "modelValue", event: "change" },
  props: {
    modelValue: { type: [File, Array], default: null },
    accept: { type: String, default: "" },       // ".xlsx,.xls,image/*,application/pdf"
    multiple: { type: Boolean, default: false },
    maxSize: { type: Number, default: 0 },        // bytes; 0 = sin límite de cliente
    disabled: { type: Boolean, default: false },
    label: { type: String, default: "Arrastra tu archivo aquí" },
    dropText: { type: String, default: "Suelta el archivo para seleccionarlo" },
    help: { type: String, default: "" },
    browseText: { type: String, default: "Seleccionar archivo" },
    replaceText: { type: String, default: "Reemplazar" },
    removeText: { type: String, default: "Quitar archivo" },
    uploadingText: { type: String, default: "Subiendo archivo" },
    error: { type: String, default: "" },         // error del padre (p. ej. respuesta del backend)
    uploading: { type: Boolean, default: false }, // la subida la controla el padre
    progress: { type: Number, default: null },    // 0-100 reales; null = indeterminado
    messages: { type: Object, default: () => ({}) } // sobrescribe DEFAULT_MESSAGES (i18n del padre)
  },
  data() {
    uid += 1;
    return { dragging: false, depth: 0, rejectMsg: "", id: uid };
  },
  computed: {
    inputId() { return `pxn-fu-${this.id}`; },
    helpId() { return `pxn-fu-help-${this.id}`; },
    errorId() { return `pxn-fu-err-${this.id}`; },
    files() {
      if (Array.isArray(this.modelValue)) return this.modelValue;
      return this.modelValue ? [this.modelValue] : [];
    },
    shownError() { return this.error || this.rejectMsg; },
    describedBy() {
      const ids = [];
      if (this.help && !this.files.length) ids.push(this.helpId);
      if (this.shownError) ids.push(this.errorId);
      return ids.join(" ") || null;
    },
    acceptTokens() {
      return this.accept.split(",").map(t => t.trim().toLowerCase()).filter(Boolean);
    }
  },
  mounted() {
    // Red de seguridad: si el arrastre termina fuera de la zona (soltar en otro sitio, Esc), no queda el estado activo.
    window.addEventListener("drop", this.resetDrag);
    window.addEventListener("dragend", this.resetDrag);
  },
  beforeDestroy() {
    window.removeEventListener("drop", this.resetDrag);
    window.removeEventListener("dragend", this.resetDrag);
  },
  methods: {
    resetDrag() { this.depth = 0; this.dragging = false; },
    msg(key, vars) {
      const tpl = this.messages[key] || DEFAULT_MESSAGES[key];
      return tpl.replace(/\{(\w+)\}/g, (_, k) => (vars && vars[k] != null ? vars[k] : ""));
    },
    formatSize(bytes) {
      if (!bytes) return "0 B";
      const units = ["B", "KB", "MB", "GB"];
      const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
      const v = bytes / Math.pow(1024, i);
      return (i === 0 ? String(bytes) : v.toFixed(v >= 10 ? 0 : 1)) + " " + units[i];
    },
    fileKey(f, i) { return `${f.name}-${f.size}-${f.lastModified}-${i}`; },
    openPicker() {
      if (this.disabled || this.uploading) return;
      if (this.$refs.input) this.$refs.input.click();
    },
    typeOk(file) {
      if (!this.acceptTokens.length) return true;
      const name = (file.name || "").toLowerCase();
      const type = (file.type || "").toLowerCase();
      return this.acceptTokens.some(t => {
        if (t.charAt(0) === ".") return name.endsWith(t);
        if (t.endsWith("/*")) return type.startsWith(t.slice(0, -1));
        return type === t;
      });
    },
    // ÚNICO pipeline de validación: el input nativo y el drop terminan aquí.
    process(list, dirNames) {
      const files = Array.from(list || []);
      const rejected = [];
      (dirNames || []).forEach(n => rejected.push({ name: n, reason: "folder", message: this.msg("folder", { name: n }) }));

      if (!this.multiple && files.length > 1) {
        files.forEach(f => rejected.push({ file: f, name: f.name, reason: "multiple", message: this.msg("multiple") }));
        return this.finish([], rejected);
      }
      const valid = [];
      files.forEach(f => {
        if (!this.typeOk(f)) rejected.push({ file: f, name: f.name, reason: "type", message: this.msg("type", { name: f.name }) });
        else if (this.maxSize && f.size > this.maxSize) rejected.push({ file: f, name: f.name, reason: "size", message: this.msg("size", { name: f.name, max: this.formatSize(this.maxSize) }) });
        else if (f.size === 0) rejected.push({ file: f, name: f.name, reason: "empty", message: this.msg("empty", { name: f.name }) });
        else valid.push(f);
      });
      this.finish(valid, rejected);
    },
    finish(valid, rejected) {
      if (rejected.length) {
        this.rejectMsg = rejected[0].message; // el archivo válido anterior se conserva
        this.$emit("reject", rejected);
      } else {
        this.rejectMsg = "";
      }
      if (!valid.length) return;
      this.rejectMsg = rejected.length ? this.rejectMsg : "";
      if (this.multiple) {
        const next = this.files.slice();
        valid.forEach(v => {
          if (!next.some(x => x.name === v.name && x.size === v.size && x.lastModified === v.lastModified)) next.push(v);
        });
        this.$emit("change", next);
      } else {
        this.$emit("change", valid[0]);
      }
    },
    onInputChange(e) {
      const input = e.target;
      const list = input.files;
      // Cancelar el selector no debe borrar el archivo actual.
      if (list && list.length) this.process(list);
      input.value = ""; // permite volver a elegir el mismo archivo
    },
    removeAt(i) {
      this.rejectMsg = "";
      if (this.multiple) {
        const next = this.files.slice();
        const [removed] = next.splice(i, 1);
        this.$emit("change", next);
        this.$emit("remove", removed);
      } else {
        const removed = this.files[0];
        this.$emit("change", null);
        this.$emit("remove", removed);
      }
    },

    /* --- drag & drop --- */
    hasFiles(e) {
      const t = e.dataTransfer && e.dataTransfer.types;
      return !!t && Array.prototype.indexOf.call(t, "Files") !== -1;
    },
    onDragEnter(e) {
      if (!this.hasFiles(e) || this.disabled || this.uploading) return;
      this.depth += 1; // contador: los hijos disparan enter/leave sin que la zona pierda el drag
      this.dragging = true;
    },
    onDragOver(e) {
      if (!this.hasFiles(e)) return;
      if (e.dataTransfer) e.dataTransfer.dropEffect = this.disabled || this.uploading ? "none" : "copy";
    },
    onDragLeave(e) {
      if (!this.hasFiles(e)) return;
      this.depth = Math.max(0, this.depth - 1);
      if (this.depth === 0) this.dragging = false;
    },
    onDrop(e) {
      this.depth = 0;
      this.dragging = false;
      if (this.disabled || this.uploading || !this.hasFiles(e)) return;
      const dt = e.dataTransfer;
      // Las carpetas no se tratan como archivo: se detectan por sus entradas y se rechazan con mensaje.
      const dirNames = [];
      const skip = new Set();
      if (dt.items && dt.items.length) {
        for (let i = 0; i < dt.items.length; i++) {
          const it = dt.items[i];
          if (it.kind !== "file") continue;
          const entry = typeof it.webkitGetAsEntry === "function" ? it.webkitGetAsEntry() : null;
          if (entry && entry.isDirectory) { dirNames.push(entry.name); skip.add(it.getAsFile && it.getAsFile()); }
        }
      }
      const files = Array.from(dt.files || []).filter(f => !skip.has(f) && !(dirNames.indexOf(f.name) !== -1 && f.size === 0 && !f.type));
      this.process(files, dirNames);
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-fu { display: flex; flex-direction: column; gap: var(--pxn-space-3); min-width: 0; }

.pxn-fu__zone {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--pxn-space-4);
  min-height: 168px;
  padding: var(--pxn-space-7) var(--pxn-space-6);
  border: 2px dashed var(--pxn-border-control);
  border-radius: var(--pxn-radius-lg);
  background: var(--pxn-surface);
  color: var(--pxn-ink-2);
  text-align: center;
  cursor: pointer;
  transition: border-color 160ms var(--pxn-ease), background-color 160ms var(--pxn-ease);
}
.pxn-fu__zone:hover { border-color: var(--pxn-primary-border); background: var(--pxn-surface-hover); }
.pxn-fu.is-drag .pxn-fu__zone { border-color: var(--pxn-primary); border-style: solid; background: var(--pxn-primary-softer); }
.pxn-fu.is-invalid .pxn-fu__zone { border-color: var(--pxn-danger); }
.pxn-fu.is-disabled .pxn-fu__zone { cursor: not-allowed; background: var(--pxn-surface-2); border-color: var(--pxn-border); color: var(--pxn-ink-disabled); }
.pxn-fu.is-busy .pxn-fu__zone { cursor: progress; }

// Input nativo real: cubre la zona (opacity 0), enfocable y con teclado nativo; nunca display:none.
.pxn-fu__native { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: inherit; z-index: 0; }
.pxn-fu__zone:has(.pxn-fu__native:focus-visible) { outline: 2px solid var(--pxn-primary) !important; outline-offset: 2px !important; }
@supports not selector(:has(*)) { .pxn-fu__native:focus-visible { opacity: 1; outline: 2px solid var(--pxn-primary); } }

.pxn-fu__prompt, .pxn-fu__files { position: relative; z-index: 1; display: flex; flex-direction: column; align-items: center; gap: var(--pxn-space-3); max-width: 100%; pointer-events: none; }
.pxn-fu__icon { display: inline-flex; align-items: center; justify-content: center; width: 48px; height: 48px; border-radius: var(--pxn-radius-lg); background: var(--pxn-primary-soft); color: var(--pxn-primary-ink); }
.pxn-fu.is-disabled .pxn-fu__icon { background: var(--pxn-surface-3); color: var(--pxn-ink-disabled); }
.pxn-fu__title { font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxn-fu.is-disabled .pxn-fu__title { color: var(--pxn-ink-3); }
.pxn-fu__help { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); max-width: 46ch; }
.pxn-fu__browse { font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-medium); color: var(--pxn-primary-ink); text-decoration: underline; text-underline-offset: 3px; }
.pxn-fu.is-disabled .pxn-fu__browse { color: var(--pxn-ink-disabled); }

.pxn-fu__files { width: 100%; }
.pxn-fu__file { display: flex; align-items: center; gap: var(--pxn-space-5); width: 100%; max-width: 520px; padding: var(--pxn-space-4) var(--pxn-space-5); border: 1px solid var(--pxn-success-border); border-radius: var(--pxn-radius-md); background: var(--pxn-success-soft); text-align: left; animation: pxn-fu-in 180ms var(--pxn-ease-out) 1 both; }
.pxn-fu.is-invalid .pxn-fu__file { border-color: var(--pxn-border); background: var(--pxn-surface-2); }
.pxn-fu__fileicon { flex: none; display: inline-flex; color: var(--pxn-success-ink); }
.pxn-fu__meta { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.pxn-fu__name { font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pxn-fu__size { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxn-fu__actions { flex: none; display: inline-flex; align-items: center; gap: var(--pxn-space-2); pointer-events: auto; position: relative; z-index: 2; }
.pxn-fu__drophint { font-size: var(--pxn-fs-sm); color: var(--pxn-primary-ink); font-weight: var(--pxn-fw-medium); }

.pxn-fu__btn {
  appearance: none; min-height: 32px; padding: 0 var(--pxn-space-4);
  border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); color: var(--pxn-ink); font: inherit; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium);
  cursor: pointer; transition: background-color 120ms var(--pxn-ease);
}
.pxn-fu__btn:hover:not([disabled]) { background: var(--pxn-surface-2); }
.pxn-fu__btn[disabled] { cursor: not-allowed; color: var(--pxn-ink-disabled); }
.pxn-fu__btn--icon { display: inline-flex; align-items: center; justify-content: center; width: 32px; padding: 0; color: var(--pxn-danger); }
// El POS y otras vistas anulan el foco global de <button>: el anillo se declara aquí con especificidad propia.
.pxn-fu__btn.pxn-fu__btn:focus-visible { outline: 2px solid var(--pxn-primary) !important; outline-offset: 2px !important; }

.pxn-fu__busy { position: relative; z-index: 1; display: flex; align-items: center; gap: var(--pxn-space-4); width: 100%; max-width: 520px; justify-content: center; }
.pxn-fu__bar { flex: 1; height: 8px; border-radius: var(--pxn-radius-pill); background: var(--pxn-surface-3); overflow: hidden; }
.pxn-fu__barfill { display: block; height: 100%; background: var(--pxn-primary); transition: width 160ms linear; }
.pxn-fu__pct { min-width: 4ch; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); text-align: right; }

.pxn-fu__error { display: flex; align-items: flex-start; gap: var(--pxn-space-3); margin: 0; font-size: var(--pxn-fs-sm); color: var(--pxn-danger-ink); overflow-wrap: anywhere; }
.pxn-fu__error :deep(svg) { flex: none; margin-top: 2px; }

@keyframes pxn-fu-in { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }

@media (max-width: 480px) {
  .pxn-fu__zone { min-height: 148px; padding: var(--pxn-space-6) var(--pxn-space-4); }
  .pxn-fu__file { flex-wrap: wrap; }
  .pxn-fu__actions { margin-left: auto; }
}
@media (prefers-reduced-motion: reduce) {
  .pxn-fu__zone, .pxn-fu__btn, .pxn-fu__barfill { transition: none; }
  .pxn-fu__file { animation: none; }
}
</style>
