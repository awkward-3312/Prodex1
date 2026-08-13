<template>
  <div class="serial-numbers-field">
    <div class="d-flex align-items-center justify-content-between mb-2" style="flex-wrap: wrap; gap: 6px;">
      <div style="font-size: 13px; font-weight: 600; color: #1e293b;">
        <lucide-icon name="scan-barcode" style="width:16px;height:16px;vertical-align:-3px;" />
        {{ mode === 'select' ? $t('Select_Serials') : $t('Enter_Serials') }}
      </div>
      <span
        class="badge"
        :class="isComplete ? 'badge-success' : 'badge-warning'"
        style="font-size: 12px;"
      >
        {{ $t('Serials_Count') }}: {{ serials.length }} / {{ requiredCountInt }}
      </span>
    </div>

    <!-- ENTRY MODE: purchase — manual / scan / bulk / CSV -->
    <template v-if="mode !== 'select'">
      <div class="input-group input-group-sm mb-2">
        <input
          type="text"
          class="form-control"
          :placeholder="$t('Scan_Or_Enter_Serial')"
          v-model="scanInput"
          :disabled="disabled"
          @keyup.enter.prevent="addFromScan"
        />
        <div class="input-group-append">
          <button type="button" class="btn btn-primary" :disabled="disabled" @click="addFromScan">
            {{ $t('Add_Serial') }}
          </button>
          <button type="button" class="btn btn-outline-secondary" :disabled="disabled" @click="showBulk = !showBulk">
            <lucide-icon name="clipboard-list" style="width:14px;height:14px;" />
          </button>
          <button type="button" class="btn btn-outline-secondary" :disabled="disabled" @click="triggerFile">
            <lucide-icon name="file-up" style="width:14px;height:14px;" /> {{ $t('Import_Serials_CSV') }}
          </button>
        </div>
      </div>

      <div v-if="showBulk" class="mb-2">
        <textarea
          class="form-control form-control-sm"
          rows="3"
          :placeholder="$t('Bulk_Paste_Serials')"
          v-model="bulkText"
          :disabled="disabled"
        ></textarea>
        <button type="button" class="btn btn-sm btn-primary mt-1" :disabled="disabled" @click="applyBulk">
          {{ $t('Add_Serial') }}
        </button>
      </div>

      <input ref="fileInput" type="file" accept=".csv,.txt,.xlsx,.xls" class="d-none" @change="onFileChange" />
    </template>

    <!-- SELECT MODE: sale — pick from available stock -->
    <template v-else>
      <div class="input-group input-group-sm mb-2">
        <input
          type="text"
          class="form-control"
          :placeholder="$t('Scan_Or_Enter_Serial')"
          v-model="scanInput"
          :disabled="disabled"
          @keyup.enter.prevent="selectFromScan"
        />
        <div class="input-group-append">
          <button type="button" class="btn btn-primary" :disabled="disabled" @click="selectFromScan">
            {{ $t('Add_Serial') }}
          </button>
        </div>
      </div>

      <div v-if="loadingAvailable" class="text-muted" style="font-size: 12px;">…</div>
      <div v-else-if="available.length === 0" class="text-warning" style="font-size: 12px;">
        {{ $t('No_Serials_Available') }}
      </div>
      <div v-else class="serial-available-list" style="max-height: 160px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px;">
        <div v-for="s in available" :key="s.id" class="form-check" style="font-size: 13px;">
          <input
            class="form-check-input"
            type="checkbox"
            :id="'serial-'+_uid+'-'+s.id"
            :value="s.serial_number"
            :checked="isSelected(s.serial_number)"
            :disabled="disabled"
            @change="toggleSelect(s.serial_number)"
          />
          <label class="form-check-label" :for="'serial-'+_uid+'-'+s.id">{{ s.serial_number }}</label>
        </div>
      </div>
    </template>

    <!-- Selected / entered chips -->
    <div class="mt-2 d-flex" style="flex-wrap: wrap; gap: 6px;">
      <span
        v-for="(serial, idx) in serials"
        :key="serial"
        class="badge badge-pill badge-light"
        style="border:1px solid #cbd5e1; font-size: 12px; padding: 6px 10px;"
      >
        {{ serial }}
        <a href="#" class="text-danger ml-1" @click.prevent="removeSerial(idx)" :title="$t('Remove')">&times;</a>
      </span>
    </div>

    <div v-if="error" class="text-danger mt-1" style="font-size: 12px;">{{ error }}</div>
    <div v-if="!isComplete" class="text-muted mt-1" style="font-size: 12px;">
      {{ requiredCountInt }} {{ $t('Serials_Required_Count') }}
    </div>
  </div>
</template>

<script>
export default {
  name: "SerialNumbersField",
  props: {
    value: { type: Array, default: () => [] },
    mode: { type: String, default: "entry" }, // 'entry' | 'select'
    requiredCount: { type: [Number, String], default: 0 },
    productId: { type: [Number, String], default: null },
    productVariantId: { type: [Number, String], default: null },
    warehouseId: { type: [Number, String], default: null },
    // Optional override for select mode: fetch the candidate serials from a
    // different endpoint (e.g. sold-on-sale for returns) instead of in-stock.
    fetchUrl: { type: String, default: "serial_numbers/available" },
    fetchParams: { type: Object, default: null },
    disabled: { type: Boolean, default: false }
  },
  data() {
    return {
      scanInput: "",
      bulkText: "",
      showBulk: false,
      available: [],
      loadingAvailable: false,
      error: ""
    };
  },
  computed: {
    serials() {
      return Array.isArray(this.value) ? this.value : [];
    },
    requiredCountInt() {
      return Math.round(Number(this.requiredCount) || 0);
    },
    isComplete() {
      return this.serials.length === this.requiredCountInt && this.requiredCountInt > 0;
    },
    // Effective request params for select mode — drives the re-fetch watcher.
    effectiveParams() {
      if (this.fetchParams) return this.fetchParams;
      if (!this.productId || !this.warehouseId) return null;
      const p = { product_id: this.productId, warehouse_id: this.warehouseId };
      if (this.productVariantId) p.product_variant_id = this.productVariantId;
      return p;
    }
  },
  watch: {
    effectiveParams: {
      deep: true,
      handler() { this.refreshAvailable(); }
    }
  },
  mounted() {
    if (this.mode === "select") {
      this.refreshAvailable();
    }
  },
  methods: {
    // Split a raw string/array into clean tokens.
    tokenize(raw) {
      let parts = [];
      if (Array.isArray(raw)) {
        parts = raw;
      } else if (typeof raw === "string") {
        parts = raw.split(/[\r\n,;\t]+/);
      }
      return parts.map(s => String(s).trim()).filter(s => s !== "");
    },
    hasSerial(serial) {
      const key = serial.toLowerCase();
      return this.serials.some(s => s.toLowerCase() === key);
    },
    emit(list) {
      this.$emit("input", list);
    },
    // ENTRY mode add
    addSerials(raw) {
      this.error = "";
      const tokens = this.tokenize(raw);
      const next = this.serials.slice();
      for (const t of tokens) {
        if (next.some(s => s.toLowerCase() === t.toLowerCase())) {
          this.error = this.$t("Duplicate_Serial") + ": " + t;
          continue;
        }
        next.push(t);
      }
      this.emit(next);
    },
    addFromScan() {
      if (!this.scanInput) return;
      this.addSerials(this.scanInput);
      this.scanInput = "";
    },
    applyBulk() {
      if (!this.bulkText) return;
      this.addSerials(this.bulkText);
      this.bulkText = "";
      this.showBulk = false;
    },
    triggerFile() {
      this.$refs.fileInput && this.$refs.fileInput.click();
    },
    async onFileChange(e) {
      const file = e.target.files && e.target.files[0];
      if (!file) return;
      try {
        const XLSX = await import("xlsx");
        const data = await file.arrayBuffer();
        const wb = XLSX.read(data, { type: "array" });
        const tokens = [];
        wb.SheetNames.forEach(name => {
          const rows = XLSX.utils.sheet_to_json(wb.Sheets[name], { header: 1, blankrows: false });
          rows.forEach(row => {
            (row || []).forEach(cell => {
              if (cell !== null && cell !== undefined && String(cell).trim() !== "") {
                tokens.push(String(cell).trim());
              }
            });
          });
        });
        this.addSerials(tokens);
      } catch (err) {
        this.error = "Import failed";
      } finally {
        e.target.value = "";
      }
    },
    removeSerial(idx) {
      const next = this.serials.slice();
      next.splice(idx, 1);
      this.emit(next);
    },
    // SELECT mode
    refreshAvailable() {
      if (this.mode !== "select") return;
      const params = this.effectiveParams;
      if (!params) {
        this.available = [];
        return;
      }
      this.loadingAvailable = true;
      axios
        .get(this.fetchUrl, { params })
        .then(res => {
          this.available = (res.data && res.data.serials) || [];
        })
        .catch(() => { this.available = []; })
        .finally(() => { this.loadingAvailable = false; });
    },
    isSelected(serial) {
      return this.hasSerial(serial);
    },
    toggleSelect(serial) {
      this.error = "";
      if (this.isSelected(serial)) {
        const next = this.serials.filter(s => s.toLowerCase() !== serial.toLowerCase());
        this.emit(next);
      } else {
        this.emit(this.serials.concat([serial]));
      }
    },
    selectFromScan() {
      if (!this.scanInput) return;
      const tokens = this.tokenize(this.scanInput);
      this.scanInput = "";
      for (const t of tokens) {
        const match = this.available.find(s => s.serial_number.toLowerCase() === t.toLowerCase());
        if (!match) {
          this.error = this.$t("No_Serials_Available") + " (" + t + ")";
          continue;
        }
        if (!this.isSelected(match.serial_number)) {
          this.emit(this.serials.concat([match.serial_number]));
        }
      }
    }
  }
};
</script>
