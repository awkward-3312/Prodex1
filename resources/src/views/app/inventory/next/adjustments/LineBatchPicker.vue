<template>
  <!--
    C3.1 — Selector de lotes para una línea de ajuste (batch-tracked).
    Mismo comportamiento que Create/Edit legacy: elegir lote(s) de
    available_batches, repartir la cantidad de la línea entre ellos, validar
    contra qty_available cuando es "sub". Muta detail.batches en su sitio
    (el padre es dueño del array). Reutilizable para Daños/Traslados en C3.2+.
  -->
  <div class="lbp">
    <div class="lbp__head">
      <span class="lbp__title"><lucide-icon name="package" :size="13" /> Lotes</span>
      <span class="lbp__count">
        {{ (detail.batches || []).length }} línea(s)
        <template v-if="(detail.batches || []).length">
          · Total: {{ fmt(batchTotal) }} / {{ fmt(targetQty) }}
        </template>
      </span>
      <px-button type="button" size="sm" variant="secondary" icon="plus" @click="addRow">Añadir lote</px-button>
    </div>

    <div v-if="detail.batches_loading" class="lbp__msg">Cargando lotes…</div>

    <div v-else-if="detail.type !== 'add' && !(detail.available_batches && detail.available_batches.length)" class="lbp__msg lbp__msg--warn">
      <lucide-icon name="info" :size="13" /> No hay lotes disponibles para este producto en el almacén seleccionado.
    </div>

    <div v-else-if="!detail.batches || detail.batches.length === 0" class="lbp__msg">
      <lucide-icon name="info" :size="13" />
      <span v-if="detail.type === 'add'">En ajustes de «Adición», el detalle por lote solo se actualiza si indicas el lote explícitamente.</span>
      <span v-else>Pulsa «Añadir lote» para elegir uno (o déjalo vacío para asignación automática FEFO al guardar).</span>
    </div>

    <div v-else class="lbp-tbl__wrap pxn-scroll">
      <table class="lbp-tbl">
        <thead>
          <tr>
            <th style="min-width: 220px;">Nº de lote *</th>
            <th>Caducidad</th>
            <th class="is-right">Disponible</th>
            <th class="is-right" style="width: 120px;">Cantidad *</th>
            <th style="width: 44px;"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(b, bIdx) in detail.batches" :key="'b-' + bIdx">
            <td>
              <vs-px
                :value="b.product_batch_id"
                :reduce="o => o.value"
                placeholder="Elegir lote"
                :options="(detail.available_batches || []).map(ab => ({
                  label: ab.batch_no + (ab.expiry_date ? ' · Cad ' + ab.expiry_date : '') + ' · ' + ab.qty_available,
                  value: ab.id
                }))"
                @input="val => selectBatch(bIdx, val)"
              />
            </td>
            <td>
              <span v-if="b.expiry_date" class="lbp-pill" :class="expiryClass(b.expiry_date)">{{ b.expiry_date }}</span>
              <span v-else class="pxn-muted">—</span>
            </td>
            <td class="is-right pxn-num">{{ fmt(Number(b.qty_available) || 0) }}</td>
            <td>
              <px-input
                :value="String(b.qty == null ? '' : b.qty)"
                inputmode="decimal"
                placeholder="0"
                :invalid="detail.type !== 'add' && Number(b.qty) > (Number(b.qty_available) || 0)"
                @input="val => qtyInput(b, val)"
              />
            </td>
            <td class="is-center">
              <px-button type="button" size="sm" variant="danger" icon-only icon="x" aria-label="Quitar lote" @click="removeRow(bIdx)" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="detail.batches && detail.batches.length && mismatch" class="lbp__mismatch">
      <lucide-icon name="info" :size="13" />
      La suma de los lotes no coincide con la cantidad de la línea ({{ fmt(batchTotal) }} / {{ fmt(targetQty) }}).
    </div>
  </div>
</template>

<script>
import PxButton from "@/components/px-next/PxButton.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  name: "LineBatchPicker",
  components: { PxButton, PxInput, "vs-px": VsPx },
  props: {
    detail: { type: Object, required: true },
    decimals: { type: Number, default: 2 },
    // Cantidad que deben sumar los lotes. Traslados la pasan en unidad BASE
    // (los lotes se cuentan en base); Ajustes/Daños la omiten y se usa
    // detail.quantity (esos módulos son 1:1).
    requiredBase: { type: Number, default: null }
  },
  computed: {
    targetQty() {
      return this.requiredBase != null && Number.isFinite(this.requiredBase)
        ? Number(this.requiredBase)
        : (Number(this.detail.quantity) || 0);
    },
    batchTotal() {
      const b = Array.isArray(this.detail.batches) ? this.detail.batches : [];
      return b.reduce((s, x) => s + (Number(x.qty) || 0), 0);
    },
    mismatch() {
      return Math.abs(this.batchTotal - this.targetQty) > 0.0001;
    }
  },
  methods: {
    fmt(n) {
      const v = Number(n);
      if (!Number.isFinite(v)) return "0";
      return v.toFixed(this.decimals);
    },
    addRow() {
      if (!Array.isArray(this.detail.batches)) this.$set(this.detail, "batches", []);
      this.detail.batches.push({
        product_batch_id: null,
        batch_no: "",
        expiry_date: null,
        qty_available: 0,
        qty: this.detail.batches.length === 0 ? this.targetQty : 0
      });
    },
    removeRow(idx) {
      if (!Array.isArray(this.detail.batches)) return;
      this.detail.batches.splice(idx, 1);
    },
    selectBatch(idx, batchId) {
      const list = Array.isArray(this.detail.available_batches) ? this.detail.available_batches : [];
      const row = this.detail.batches[idx];
      if (!row) return;
      const ab = list.find(x => x.id === batchId);
      this.$set(row, "product_batch_id", ab ? ab.id : null);
      this.$set(row, "batch_no", ab ? ab.batch_no : "");
      this.$set(row, "expiry_date", ab ? ab.expiry_date : null);
      this.$set(row, "qty_available", ab ? Number(ab.qty_available) || 0 : 0);
    },
    qtyInput(b, val) {
      const num = parseFloat(String(val).replace(",", "."));
      this.$set(b, "qty", Number.isFinite(num) ? num : 0);
    },
    expiryClass(dateStr) {
      if (!dateStr) return "is-none";
      const today = new Date(); today.setHours(0, 0, 0, 0);
      const exp = new Date(dateStr);
      if (isNaN(exp.getTime())) return "is-none";
      exp.setHours(0, 0, 0, 0);
      const days = Math.round((exp - today) / 86400000);
      if (days < 0) return "is-expired";
      if (days <= 30) return "is-soon";
      return "is-ok";
    }
  }
};
</script>

<style lang="scss" scoped>
.lbp {
  margin: var(--pxn-space-3) 0 var(--pxn-space-4);
  border: 1px solid var(--pxn-primary-border);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-primary-soft);
  overflow: hidden;
}
.lbp__head {
  display: flex; align-items: center; gap: var(--pxn-space-3); flex-wrap: wrap;
  padding: var(--pxn-space-3) var(--pxn-space-4);
  background: var(--pxn-primary);
  color: #fff;
}
.lbp__title { display: inline-flex; align-items: center; gap: var(--pxn-space-2); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; }
.lbp__count { font-size: var(--pxn-fs-xs); opacity: 0.9; flex: 1; }
.lbp__msg { padding: var(--pxn-space-4); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); display: flex; align-items: center; gap: var(--pxn-space-2); }
.lbp__msg--warn { color: var(--pxn-danger-ink); background: var(--pxn-danger-soft); }
.lbp-tbl__wrap { overflow-x: auto; background: var(--pxn-surface); }
.lbp-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.lbp-tbl th {
  text-align: left; padding: var(--pxn-space-3) var(--pxn-space-4);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
  text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-primary-ink);
  background: var(--pxn-primary-soft); border-bottom: 1px solid var(--pxn-primary-border); white-space: nowrap;
}
.lbp-tbl td { padding: var(--pxn-space-2) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); vertical-align: middle; }
.lbp-tbl tr:last-child td { border-bottom: 0; }
.lbp-tbl .is-right { text-align: right; }
.lbp-tbl .is-center { text-align: center; }
.lbp-tbl :deep(.pxn-input) { height: var(--pxn-control-h-sm); font-size: var(--pxn-fs-sm); text-align: right; }
.lbp-pill { display: inline-block; padding: 2px 8px; border-radius: var(--pxn-radius-pill); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); }
.lbp-pill.is-expired { background: var(--pxn-danger-soft); color: var(--pxn-danger-ink); }
.lbp-pill.is-soon { background: var(--pxn-warning-soft); color: var(--pxn-warning-ink); }
.lbp-pill.is-ok { background: var(--pxn-success-soft); color: var(--pxn-success-ink); }
.lbp-pill.is-none { background: var(--pxn-surface-3); color: var(--pxn-ink-3); }
.lbp__mismatch {
  display: flex; align-items: center; gap: var(--pxn-space-2);
  padding: var(--pxn-space-3) var(--pxn-space-4);
  background: var(--pxn-warning-soft); color: var(--pxn-warning-ink);
  font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium);
  border-top: 1px solid var(--pxn-warning-border);
}
</style>
