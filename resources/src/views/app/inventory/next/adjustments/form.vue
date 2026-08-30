<template>
  <div class="px-next pxadjf">
    <!--
      C3.1 — Alta / edición de ajuste de inventario px-next (preview dev-only).
      Un solo componente para «crear» y «editar» (prop `mode`). Conserva el
      contrato de Create_Adjustment.vue / Edit_Adjustment.vue: mismos endpoints,
      mismo payload, mismas validaciones, suma/resta por línea, autocomplete por
      almacén, variantes, lotes (batches_for_adjustment) y show_product_data.
    -->
    <div v-if="!allowed" class="pxadjf__denied">
      <px-empty-state icon="lock" :title="deniedTitle" :description="deniedDesc" />
    </div>

    <template v-else>
      <div v-if="isLoading" class="pxadjf__pad"><px-skeleton variant="card" :rows="8" /></div>

      <px-alert v-else-if="loadError" tone="danger" title="No se pudo cargar el ajuste" class="pxadjf__alert">
        {{ loadError }}
        <template #actions><px-button size="sm" variant="secondary" @click="loadElements()">Reintentar</px-button></template>
      </px-alert>

      <validation-observer v-else ref="obs">
        <form @submit.prevent="submit">
          <px-page-header
            :title="isEdit ? 'Editar ajuste' : 'Nuevo ajuste'"
            :breadcrumbs="[{ label: 'Inventario' }, { label: 'Ajustes' }, { label: isEdit ? (adjustment.Ref || adjustment.id || '—') : 'Nuevo' }]"
          >
            <template #meta v-if="isEdit && adjustment.Ref">
              <span class="pxn-mono">{{ adjustment.Ref }}</span>
            </template>
            <template #actions>
              <px-button variant="ghost" icon="arrow-left" type="button" @click="goCancel">Cancelar</px-button>
              <px-button variant="primary" icon="check" type="submit" :loading="submitting" :disabled="hasBatchValidationErrors">
                {{ submitting ? 'Guardando…' : 'Guardar ajuste' }}
              </px-button>
            </template>
          </px-page-header>

          <div class="pxadjf__grid">
            <div class="pxadjf__main">
              <!-- ===== Datos del ajuste ===== -->
              <px-card title="Datos del ajuste" class="pxadjf__sec">
                <div class="pxadjf__row2">
                  <v-field name="Almacén" label="Almacén" required :rules="{ required: true }" v-slot="{ invalid, id }">
                    <vs-px
                      :input-id="id"
                      :invalid="invalid"
                      :disabled="details.length > 0"
                      v-model="adjustment.warehouse_id"
                      @input="onWarehouseChange"
                      :reduce="o => o.value"
                      placeholder="Elegir almacén"
                      :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
                    />
                  </v-field>
                  <v-field name="Fecha" label="Fecha" required :rules="{ required: true }" v-slot="{ invalid, id }">
                    <px-input :id="id" type="date" v-model="adjustment.date" :invalid="invalid" />
                  </v-field>
                </div>
                <p v-if="details.length > 0" class="pxadjf__hint">
                  <lucide-icon name="info" :size="13" /> El almacén queda fijo mientras haya productos en el ajuste.
                </p>
              </px-card>

              <!-- ===== Productos ===== -->
              <px-card title="Productos" class="pxadjf__sec">
                <div class="pxadjf-ac">
                  <px-button
                    type="button" variant="secondary" size="sm" icon-only icon="scan-line"
                    aria-label="Escanear" @click="scanOpen = true"
                  />
                  <div class="pxadjf-ac__box">
                    <input
                      class="pxadjf-ac__input pxn-ring"
                      placeholder="Escanear / buscar por código, nombre o código de barras"
                      @input="e => search_input = e.target.value"
                      @keyup="search()"
                      @focus="focused = true"
                      @blur="focused = false"
                      ref="ac"
                    />
                    <ul v-show="focused && product_filter.length" class="pxadjf-ac__list pxn-scroll">
                      <li v-for="pf in product_filter" :key="pf.id + '-' + (pf.product_variant_id || 0)"
                        class="pxadjf-ac__opt" @mousedown="pickProduct(pf)">{{ getResultValue(pf) }}</li>
                    </ul>
                  </div>
                </div>

                <div class="pxadjf-tbl__wrap pxn-scroll">
                  <table class="pxadjf-tbl">
                    <thead>
                      <tr>
                        <th style="width: 44px;">#</th>
                        <th>Código</th>
                        <th>Producto</th>
                        <th class="is-right" style="width: 130px;">Stock actual</th>
                        <th style="width: 170px;">Cantidad</th>
                        <th style="width: 150px;">Tipo</th>
                        <th style="width: 44px;"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-if="!details.length"><td colspan="7" class="pxadjf-tbl__empty">Añade productos al ajuste.</td></tr>
                      <template v-for="detail in details">
                        <tr :key="'r-' + detail.detail_id" :class="{ 'is-deleted': detail.del === 1 }">
                          <td class="pxn-num">{{ detail.detail_id }}</td>
                          <td class="pxn-mono">{{ detail.code }}</td>
                          <td>
                            <span class="pxadjf-tbl__name">{{ detail.name }}</span>
                            <px-badge v-if="detail.is_batch_tracked" tone="info" icon="package" :title="batchBadgeTitle(detail)">Lote</px-badge>
                            <px-badge v-if="detail.product_type === 'is_combo'" tone="neutral" icon="layers">Combo</px-badge>
                          </td>
                          <td class="is-right">
                            <span class="pxadjf-tbl__stock">{{ detail.current }} {{ detail.unit }}</span>
                          </td>
                          <td>
                            <div class="pxadjf-qty">
                              <px-button
                                v-if="detail.product_type !== 'is_combo'"
                                type="button" size="sm" variant="secondary" icon-only icon="minus"
                                aria-label="Restar" :disabled="detail.del === 1" @click="decrement(detail)"
                              />
                              <px-input
                                :value="String(detail.quantity == null ? '' : detail.quantity)"
                                inputmode="decimal"
                                :disabled="detail.product_type === 'is_combo' || detail.del === 1"
                                @input="val => onQtyInput(detail, val)"
                              />
                              <px-button
                                v-if="detail.product_type !== 'is_combo'"
                                type="button" size="sm" variant="secondary" icon-only icon="plus"
                                aria-label="Sumar" :disabled="detail.del === 1" @click="increment(detail)"
                              />
                            </div>
                          </td>
                          <td>
                            <px-select
                              v-if="detail.product_type !== 'is_combo'"
                              :value="detail.type"
                              :options="[{ value: 'add', label: 'Adición' }, { value: 'sub', label: 'Sustracción' }]"
                              :disabled="detail.del === 1"
                              @input="val => onTypeChange(detail, val)"
                            />
                            <span v-else class="pxadjf-tbl__typetxt">{{ detail.type === 'add' ? 'Adición' : 'Sustracción' }}</span>
                          </td>
                          <td class="is-center">
                            <px-button type="button" size="sm" variant="danger" icon-only icon="x" aria-label="Quitar" @click="removeLine(detail.detail_id)" />
                          </td>
                        </tr>
                        <tr v-if="detail.is_batch_tracked && detail.del !== 1" :key="'b-' + detail.detail_id" class="pxadjf-tbl__batchrow">
                          <td colspan="7">
                            <line-batch-picker :detail="detail" :decimals="priceDecimals" />
                          </td>
                        </tr>
                      </template>
                    </tbody>
                  </table>
                </div>
              </px-card>

              <!-- ===== Nota ===== -->
              <px-card title="Nota" class="pxadjf__sec">
                <px-field label="Nota del ajuste">
                  <template #default="{ id }">
                    <px-textarea :id="id" v-model="adjustment.notes" :rows="4" placeholder="Motivo del ajuste, referencia interna…" />
                  </template>
                </px-field>
              </px-card>

              <px-alert v-if="hasBatchValidationErrors" tone="warning" class="pxadjf__sec">
                {{ firstBatchErrorMessage }}
              </px-alert>

              <div class="pxadjf__spacer" aria-hidden="true"></div>
            </div>

            <!-- ===== Rail: resumen ===== -->
            <aside class="pxadjf__rail">
              <div class="pxadjf__rail-sticky">
                <px-card title="Resumen" class="pxadjf__summary">
                  <dl class="pxadjf__summary-dl">
                    <div><dt>Almacén</dt><dd>{{ warehouseName || '—' }}</dd></div>
                    <div><dt>Fecha</dt><dd>{{ adjustment.date || '—' }}</dd></div>
                    <div><dt>Líneas</dt><dd class="pxn-num">{{ activeLines.length }}</dd></div>
                    <div><dt>Adiciones</dt><dd class="pxn-num pxadjf__pos">+ {{ totalAdd }}</dd></div>
                    <div><dt>Sustracciones</dt><dd class="pxn-num pxadjf__neg">- {{ totalSub }}</dd></div>
                    <div><dt>Cambio neto</dt><dd class="pxn-num" :class="netChangeNum >= 0 ? 'pxadjf__pos' : 'pxadjf__neg'">{{ netChangeNum >= 0 ? '+' : '' }}{{ netChange }}</dd></div>
                  </dl>
                </px-card>
                <px-alert tone="info" bare class="pxadjf__tip">
                  <lucide-icon name="info" :size="13" />
                  Un ajuste no tiene coste; solo corrige la cantidad en stock del almacén elegido.
                </px-alert>
              </div>
            </aside>
          </div>

          <div class="pxadjf__actionbar">
            <div class="pxadjf__actionbar-inner">
              <span class="pxadjf__actionbar-hint"><lucide-icon name="info" :size="14" /> Revisa las líneas y guarda el ajuste.</span>
              <div class="pxadjf__actionbar-btns">
                <px-button variant="secondary" type="button" @click="goCancel">Cancelar</px-button>
                <px-button variant="primary" type="submit" icon="check" :loading="submitting" :disabled="hasBatchValidationErrors">
                  {{ submitting ? 'Guardando…' : 'Guardar ajuste' }}
                </px-button>
              </div>
            </div>
          </div>
        </form>
      </validation-observer>

      <px-modal v-model="scanOpen" title="Escáner de código de barras" size="md">
        <qrcode-scanner :qrbox="250" :fps="10" style="width:100%;" @result="onScan" />
      </px-modal>
    </template>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import { getPriceDecimals } from "@/utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxSelect from "@/components/px-next/PxSelect.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import VField from "@/views/app/products/next/edit/VField.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";
import LineBatchPicker from "./LineBatchPicker.vue";

// C3.1 — mismo contrato que Create_Adjustment.vue / Edit_Adjustment.vue.
export default {
  name: "AdjustmentFormNext",
  metaInfo() {
    return { title: this.isEdit ? "Editar ajuste" : "Nuevo ajuste" };
  },
  components: {
    PxPageHeader, PxCard, PxField, PxInput, PxTextarea, PxSelect, PxButton, PxBadge,
    PxAlert, PxEmptyState, PxModal, "v-field": VField, "vs-px": VsPx, "line-batch-picker": LineBatchPicker
  },
  props: {
    mode: { type: String, default: "create" } // create | edit
  },
  data() {
    return {
      isLoading: true,
      loadError: null,
      submitting: false,
      scanOpen: false,
      focused: false,
      timer: null,
      search_input: "",
      product_filter: [],
      warehouses: [],
      products: [],
      details: [],
      adjustment: {
        id: "",
        Ref: "",
        notes: "",
        warehouse_id: "",
        date: this.mode === "edit" ? "" : new Date().toISOString().slice(0, 10)
      }
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    isEdit() {
      return this.mode === "edit";
    },
    allowed() {
      const list = Array.isArray(this.currentUserPermissions) ? this.currentUserPermissions : [];
      return this.isEdit ? list.includes("adjustment_edit") : list.includes("adjustment_add");
    },
    deniedTitle() {
      return this.isEdit ? "No tienes permiso para editar ajustes" : "No tienes permiso para crear ajustes";
    },
    deniedDesc() {
      return "Pide a un administrador el permiso «" + (this.isEdit ? "adjustment_edit" : "adjustment_add") + "».";
    },
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    warehouseName() {
      const w = this.warehouses.find(x => String(x.id) === String(this.adjustment.warehouse_id));
      return w ? w.name : (this.adjustment.warehouse || "");
    },
    activeLines() {
      return (this.details || []).filter(d => d && d.del !== 1);
    },
    totalAddNum() {
      return this.activeLines.filter(d => d.type === "add").reduce((s, d) => s + (Number(d.quantity) || 0), 0);
    },
    totalSubNum() {
      return this.activeLines.filter(d => d.type === "sub").reduce((s, d) => s + (Number(d.quantity) || 0), 0);
    },
    netChangeNum() {
      return this.totalAddNum - this.totalSubNum;
    },
    totalAdd() {
      return this.fmt(this.totalAddNum);
    },
    totalSub() {
      return this.fmt(this.totalSubNum);
    },
    netChange() {
      return this.fmt(this.netChangeNum);
    },
    // Idéntico al legacy: cualquier línea batch-tracked con reparto de lotes
    // incompleto/ inválido bloquea el guardado. (Excluye del === 1 en edición.)
    hasBatchValidationErrors() {
      if (!Array.isArray(this.details)) return false;
      for (const d of this.details) {
        if (!d || !d.is_batch_tracked || d.del === 1) continue;
        const batches = Array.isArray(d.batches) ? d.batches : [];
        if (batches.length === 0) return true;
        const seen = new Set();
        for (const b of batches) {
          if (!b.product_batch_id) return true;
          const q = Number(b.qty);
          if (!(q > 0)) return true;
          if (d.type !== "add" && q > (Number(b.qty_available) || 0) + 0.01) return true;
          if (seen.has(b.product_batch_id)) return true;
          seen.add(b.product_batch_id);
        }
        const total = Math.round(batches.reduce((s, b) => s + (Number(b.qty) || 0), 0) * 10000) / 10000;
        const target = Math.round((Number(d.quantity) || 0) * 10000) / 10000;
        if (Math.abs(total - target) > 0.01) return true;
      }
      return false;
    },
    firstBatchErrorMessage() {
      if (!Array.isArray(this.details)) return "";
      for (const d of this.details) {
        if (!d || !d.is_batch_tracked || d.del === 1) continue;
        const batches = Array.isArray(d.batches) ? d.batches : [];
        const label = d.name || d.code || "";
        if (batches.length === 0) return "Selecciona un lote para " + label;
        const seen = new Set();
        for (const b of batches) {
          if (!b.product_batch_id) return "Selecciona un lote para " + label;
          const q = Number(b.qty);
          if (!(q > 0)) return "La cantidad del lote debe ser mayor que 0 para " + label;
          if (d.type !== "add" && q > (Number(b.qty_available) || 0) + 0.01) return "La cantidad del lote supera el stock disponible para " + label;
          if (seen.has(b.product_batch_id)) return "El mismo lote está seleccionado dos veces para " + label;
          seen.add(b.product_batch_id);
        }
        const total = Math.round(batches.reduce((s, b) => s + (Number(b.qty) || 0), 0) * 10000) / 10000;
        const target = Math.round((Number(d.quantity) || 0) * 10000) / 10000;
        if (Math.abs(total - target) > 0.01) {
          return "La suma de los lotes no coincide con la cantidad de la línea (" + total + " / " + target + ") — " + label;
        }
      }
      return "";
    }
  },
  watch: {
    // Caso de un único lote: reflejar la cantidad de la línea en el lote.
    details: {
      deep: true,
      handler(details) {
        if (!Array.isArray(details)) return;
        for (const d of details) {
          if (!d || !d.is_batch_tracked || d.del === 1) continue;
          const batches = Array.isArray(d.batches) ? d.batches : [];
          if (batches.length !== 1) continue;
          const b = batches[0];
          const lineQty = Number(d.quantity);
          const batchQty = Number(b.qty);
          if (Number.isFinite(lineQty) && lineQty > 0 && batchQty !== lineQty) this.$set(b, "qty", lineQty);
        }
      }
    }
  },
  created() {
    this.loadElements();
  },
  methods: {
    fmt(n) {
      const v = Number(n);
      if (!Number.isFinite(v)) return "0";
      return v.toFixed(this.priceDecimals);
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    },
    goCancel() {
      this.$router.push({ name: "index_adjustment" });
    },

    //------- carga inicial (create: GET adjustments/create · edit: GET adjustments/{id}/edit)
    loadElements() {
      this.loadError = null;
      this.isLoading = true;
      if (this.isEdit) {
        const id = this.$route.params.id;
        window.axios
          .get(`adjustments/${id}/edit`)
          .then(response => {
            this.adjustment = response.data.adjustment;
            this.details = response.data.details || [];
            this.warehouses = response.data.warehouses || [];
            this.getProductsByWarehouse(this.adjustment.warehouse_id);
            for (const d of this.details) {
              if (d && d.is_batch_tracked) this.fetchBatchesForDetail(d);
            }
            this.isLoading = false;
          })
          .catch(err => {
            this.loadError = this.errMsg(err);
            setTimeout(() => { this.isLoading = false; }, 300);
          });
      } else {
        window.axios
          .get("adjustments/create")
          .then(response => {
            this.warehouses = response.data.warehouses || [];
            this.isLoading = false;
          })
          .catch(err => {
            this.loadError = this.errMsg(err);
            setTimeout(() => { this.isLoading = false; }, 300);
          });
      }
    },
    errMsg(err) {
      return (
        (err && err.response && err.response.data && (err.response.data.message || err.response.data.error)) ||
        (err && err.message) || "Error de red."
      );
    },

    //------- autocomplete de productos (idéntico al legacy)
    onWarehouseChange(value) {
      this.search_input = "";
      this.product_filter = [];
      this.getProductsByWarehouse(value);
      if (Array.isArray(this.details)) {
        for (const d of this.details) {
          if (d && d.is_batch_tracked) {
            // Create limpia batches; Edit las conserva y fusiona disponibilidad.
            if (!this.isEdit) this.$set(d, "batches", []);
            this.fetchBatchesForDetail(d);
          }
        }
      }
    },
    getProductsByWarehouse(id) {
      if (!id) return;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get("get_Products_by_warehouse/" + id + "?stock=" + 0 + "&product_service=" + 0 + "&product_combo=" + 1)
        .then(response => { this.products = response.data || []; NProgress.done(); })
        .catch(() => { NProgress.done(); });
    },
    search() {
      if (this.timer) { clearTimeout(this.timer); this.timer = null; }
      if (this.search_input.length < 2) return (this.product_filter = []);
      if (this.adjustment.warehouse_id === "" || this.adjustment.warehouse_id == null) {
        this.makeToast("warning", "Elige primero un almacén.", "Aviso");
        return;
      }
      this.timer = setTimeout(() => {
        const exact = this.products.filter(p => p.code === this.search_input || (p.barcode || "").includes(this.search_input));
        if (exact.length === 1) {
          this.pickProduct(exact[0]);
        } else {
          const term = this.search_input.toLowerCase();
          this.product_filter = this.products.filter(p =>
            (p.name || "").toLowerCase().includes(term) ||
            (p.code || "").toLowerCase().includes(term) ||
            (p.barcode || "").toLowerCase().includes(term)
          );
          if (this.product_filter.length <= 0) this.makeToast("warning", "Producto no encontrado.", "Aviso");
        }
      }, 800);
    },
    getResultValue(result) {
      return result.code + " (" + result.name + ")";
    },
    showModal() { this.scanOpen = true; },
    onScan(decodedText) {
      this.search_input = decodedText;
      this.scanOpen = false;
      this.search();
    },
    pickProduct(result) {
      if (this.details.length > 0 && this.details.some(d => d.code === result.code)) {
        this.makeToast("warning", "El producto ya está en la lista.", "Aviso");
        this.search_input = "";
        if (this.$refs.ac) this.$refs.ac.value = "";
        this.product_filter = [];
        return;
      }
      const line = {
        id: this.isEdit ? 0 : "",
        code: result.code,
        current: result.qte,
        quantity: result.qte < 1 ? result.qte : 1,
        name: "",
        product_id: "",
        detail_id: "",
        product_variant_id: result.product_variant_id,
        unit: "",
        type: "add",
        is_batch_tracked: false
      };
      if (this.isEdit) line.del = 0;
      window.axios
        .get("/show_product_data/" + result.id + "/" + result.product_variant_id)
        .then(response => {
          line.product_id = response.data.id;
          line.name = response.data.name;
          line.unit = response.data.unit;
          line.is_batch_tracked = !!response.data.is_batch_tracked;
          this.addLine(line);
        });
      this.search_input = "";
      if (this.$refs.ac) this.$refs.ac.value = "";
      this.product_filter = [];
    },
    addLine(line) {
      if (this.details.length > 0) {
        line.detail_id = this.details[this.details.length - 1].detail_id + 1;
      } else {
        line.detail_id = 1;
      }
      this.details.push(line);
      const last = this.details[this.details.length - 1];
      if (last && last.is_batch_tracked) this.fetchBatchesForDetail(last);
    },
    removeLine(id) {
      for (let i = 0; i < this.details.length; i++) {
        if (id === this.details[i].detail_id) { this.details.splice(i, 1); break; }
      }
    },

    //------- lotes: fetch de disponibilidad (padre; la edición de filas vive en LineBatchPicker)
    fetchBatchesForDetail(detail) {
      if (!detail) return;
      if (!("batches_loading" in detail)) this.$set(detail, "batches_loading", false);
      if (!("available_batches" in detail)) this.$set(detail, "available_batches", []);
      if (!Array.isArray(detail.batches)) this.$set(detail, "batches", []);
      if (!detail.is_batch_tracked) { this.$set(detail, "batches_loading", false); return; }

      const wid = this.adjustment && this.adjustment.warehouse_id;
      const productId = detail.product_id || detail.id;
      if (!wid || !productId) { this.$set(detail, "batches_loading", false); return; }
      const variantSeg = detail.product_variant_id != null && detail.product_variant_id !== "" ? detail.product_variant_id : 0;

      // Edit: la API devuelve disponibilidad post-consumo; fusionamos la qty ya
      // asignada en las filas precargadas para no infravalorar «Disponible».
      const existingQtyById = {};
      if (this.isEdit) {
        for (const b of (Array.isArray(detail.batches) ? detail.batches : [])) {
          if (b && b.product_batch_id != null) {
            existingQtyById[b.product_batch_id] = (existingQtyById[b.product_batch_id] || 0) + (Number(b.qty) || 0);
          }
        }
      }

      this.$set(detail, "batches_loading", true);
      window.axios
        .get(`batches_for_adjustment/${productId}/${wid}/${variantSeg}`, { timeout: 15000 })
        .then(response => {
          const raw = response && response.data && Array.isArray(response.data.batches) ? response.data.batches : [];
          const list = this.isEdit
            ? raw.map(ab => ({ ...ab, qty_available: (Number(ab.qty_available) || 0) + (existingQtyById[ab.id] || 0) }))
            : raw;
          this.$set(detail, "available_batches", list);
          if (this.isEdit && Array.isArray(detail.batches)) {
            for (const b of detail.batches) {
              if (b && b.product_batch_id != null) {
                const ab = list.find(x => x.id === b.product_batch_id);
                this.$set(b, "qty_available", ab ? Number(ab.qty_available) || 0 : (existingQtyById[b.product_batch_id] || 0));
                this.$set(b, "batch_no", ab ? ab.batch_no : (b.batch_no || ""));
                this.$set(b, "expiry_date", ab ? ab.expiry_date : (b.expiry_date || null));
              }
            }
          }
        })
        .catch(() => { this.$set(detail, "available_batches", []); })
        .then(() => { this.$set(detail, "batches_loading", false); });
    },
    batchBadgeTitle(detail) {
      if (!detail) return "";
      return detail.type === "add"
        ? "En «Adición», el detalle por lote solo se actualiza si indicas el lote explícitamente."
        : "Se asignarán automáticamente los lotes que caducan antes (FEFO) al guardar.";
    },

    //------- cantidad por línea (idéntico al legacy: clamp a stock en «sub»)
    onQtyInput(detail, val) {
      const num = parseFloat(String(val).replace(",", "."));
      const q = Number.isFinite(num) ? num : "";
      if (detail.type === "sub" && q !== "" && q > Number(detail.current)) {
        this.makeToast("warning", "Stock insuficiente.", "Aviso");
        this.$set(detail, "quantity", Number(detail.current));
      } else {
        this.$set(detail, "quantity", q);
      }
    },
    onTypeChange(detail, val) {
      this.$set(detail, "type", val);
      if (val === "sub" && Number(detail.quantity) > Number(detail.current)) {
        this.makeToast("warning", "Stock insuficiente.", "Aviso");
        this.$set(detail, "quantity", Number(detail.current));
      }
    },
    increment(detail) {
      const next = (Number(detail.quantity) || 0) + 1;
      if (detail.type === "sub" && next > Number(detail.current)) {
        this.makeToast("warning", "Stock insuficiente.", "Aviso");
        return;
      }
      this.$set(detail, "quantity", next);
    },
    decrement(detail) {
      const next = (Number(detail.quantity) || 0) - 1;
      if (next > 0) this.$set(detail, "quantity", next);
    },

    //------- submit
    buildSubmitDetails() {
      return (this.details || []).map(d => {
        const out = Object.assign({}, d);
        delete out.available_batches;
        delete out.batches_loading;
        if (d.is_batch_tracked && Array.isArray(d.batches)) {
          out.batches = d.batches
            .filter(b => b && b.product_batch_id && Number(b.qty) > 0)
            .map(b => ({ product_batch_id: Number(b.product_batch_id), qty: Number(b.qty) || 0 }));
        } else {
          delete out.batches;
        }
        return out;
      });
    },
    verifiedForm() {
      if (this.details.length <= 0) {
        this.makeToast("warning", "Añade al menos un producto al ajuste.", "Aviso");
        return false;
      }
      let empty = 0;
      for (const d of this.details) {
        if (d.quantity === "" || d.quantity === 0) empty += 1;
      }
      if (empty > 0) {
        this.makeToast("warning", "Indica la cantidad en todas las líneas.", "Aviso");
        return false;
      }
      if (this.hasBatchValidationErrors) {
        this.makeToast("danger", "Hay lotes con cantidades inválidas.", "Error");
        return false;
      }
      return true;
    },
    submit() {
      this.$refs.obs.validate().then(ok => {
        if (!ok) {
          this.makeToast("danger", "Completa el formulario correctamente.", "Error");
          return;
        }
        if (!this.verifiedForm()) return;
        this.submitting = true;
        NProgress.start(); NProgress.set(0.1);
        const payload = {
          warehouse_id: this.adjustment.warehouse_id,
          date: this.adjustment.date,
          notes: this.adjustment.notes,
          details: this.buildSubmitDetails()
        };
        const req = this.isEdit
          ? window.axios.put(`adjustments/${this.$route.params.id}`, payload)
          : window.axios.post("adjustments", payload);
        req
          .then(() => {
            NProgress.done();
            this.submitting = false;
            this.$router.push({ name: "index_adjustment" });
            this.makeToast("success", this.isEdit ? "Ajuste actualizado." : "Ajuste creado.", "Éxito");
          })
          .catch(error => {
            NProgress.done();
            this.submitting = false;
            const details = error && error.errors && error.errors.details;
            if (details && details.length) this.makeToast("danger", details[0], "Error");
            else this.makeToast("danger", "Datos inválidos.", "Error");
          });
      });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxadjf { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) 0; }
@media (max-width: 620px) { .pxadjf { padding: var(--pxn-space-6) var(--pxn-space-5) 0; } }
.pxadjf__denied { padding: var(--pxn-space-12) 0; }
.pxadjf__pad { padding: var(--pxn-space-6) 0; }
.pxadjf__alert { margin-top: var(--pxn-space-5); }

.pxadjf__grid {
  display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: var(--pxn-space-6);
  margin-top: var(--pxn-space-6); padding-bottom: calc(var(--pxn-space-12) + 40px);
}
@media (max-width: 1080px) { .pxadjf__grid { grid-template-columns: minmax(0, 1fr); } }
.pxadjf__main { display: flex; flex-direction: column; gap: var(--pxn-space-6); min-width: 0; }
.pxadjf__sec { }
.pxadjf__row2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 720px) { .pxadjf__row2 { grid-template-columns: minmax(0, 1fr); } }
.pxadjf__hint { margin: var(--pxn-space-4) 0 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); display: flex; align-items: center; gap: var(--pxn-space-2); }
.pxadjf__spacer { height: var(--pxn-space-6); }

/* autocomplete */
.pxadjf-ac { display: flex; align-items: flex-start; gap: var(--pxn-space-3); margin-bottom: var(--pxn-space-5); }
.pxadjf-ac__box { position: relative; flex: 1 1 auto; min-width: 0; }
.pxadjf-ac__input {
  width: 100%; height: var(--pxn-control-h-md); padding: 0 var(--pxn-space-4);
  border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); color: var(--pxn-ink); font: inherit; font-size: var(--pxn-fs-body);
}
.pxadjf-ac__list {
  position: absolute; z-index: var(--pxn-z-dropdown, 1200); left: 0; right: 0; top: calc(100% + 4px);
  max-height: 240px; overflow-y: auto; margin: 0; padding: var(--pxn-space-3); list-style: none;
  background: var(--pxn-surface); border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md); box-shadow: var(--pxn-shadow-menu);
}
.pxadjf-ac__opt { padding: var(--pxn-space-3) var(--pxn-space-4); border-radius: var(--pxn-radius-sm); font-size: var(--pxn-fs-body); cursor: pointer; }
.pxadjf-ac__opt:hover { background: var(--pxn-surface-2); }

/* editable lines table */
.pxadjf-tbl__wrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); overflow-x: auto; background: var(--pxn-surface); }
.pxadjf-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-body); }
.pxadjf-tbl th {
  text-align: left; padding: var(--pxn-space-4);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
  text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3);
  background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap;
}
.pxadjf-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); vertical-align: middle; }
.pxadjf-tbl tr:last-child td { border-bottom: 0; }
.pxadjf-tbl .is-right { text-align: right; }
.pxadjf-tbl .is-center { text-align: center; }
.pxadjf-tbl__empty { text-align: center; color: var(--pxn-ink-3); padding: var(--pxn-space-6); }
.pxadjf-tbl__name { font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); margin-right: var(--pxn-space-2); }
.pxadjf-tbl__stock {
  display: inline-block; padding: 2px var(--pxn-space-3); border-radius: var(--pxn-radius-pill);
  background: var(--pxn-warning-soft); color: var(--pxn-warning-ink); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
}
.pxadjf-tbl__typetxt { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.pxadjf-tbl tr.is-deleted td { opacity: 0.45; text-decoration: line-through; }
.pxadjf-tbl__batchrow td { padding: 0 var(--pxn-space-4) var(--pxn-space-3); border-bottom: 1px solid var(--pxn-border); background: var(--pxn-bg); }

.pxadjf-qty { display: flex; align-items: center; gap: var(--pxn-space-2); }
.pxadjf-qty :deep(.pxn-input) { text-align: center; height: var(--pxn-control-h-sm); }
.pxadjf-tbl :deep(.pxn-select) { height: var(--pxn-control-h-sm); font-size: var(--pxn-fs-sm); }

/* rail */
.pxadjf__rail { min-width: 0; }
@media (max-width: 1080px) { .pxadjf__rail { order: -1; } .pxadjf__rail-sticky { position: static; } .pxadjf__tip { display: none; } }
.pxadjf__rail-sticky { position: sticky; top: var(--pxn-space-6); display: flex; flex-direction: column; gap: var(--pxn-space-5); }
.pxadjf__summary-dl { display: flex; flex-direction: column; }
.pxadjf__summary-dl > div { display: flex; justify-content: space-between; gap: var(--pxn-space-4); padding: var(--pxn-space-3) 0; border-bottom: 1px dashed var(--pxn-border); }
.pxadjf__summary-dl > div:last-child { border-bottom: 0; }
.pxadjf__summary-dl dt { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxadjf__summary-dl dd { margin: 0; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); text-align: right; }
.pxadjf__pos { color: var(--pxn-success-ink); }
.pxadjf__neg { color: var(--pxn-danger-ink); }
.pxadjf__tip :deep(svg) { vertical-align: -2px; margin-right: var(--pxn-space-2); }

/* action bar */
.pxadjf__actionbar {
  position: sticky; bottom: 0; left: 0; right: 0; z-index: var(--pxn-z-sticky);
  margin: 0 calc(-1 * var(--pxn-space-9)); padding: var(--pxn-space-4) var(--pxn-space-9);
  background: var(--pxn-surface); border-top: 1px solid var(--pxn-border);
  box-shadow: 0 -4px 16px rgba(16, 24, 40, 0.06);
}
@media (max-width: 620px) { .pxadjf__actionbar { margin: 0 calc(-1 * var(--pxn-space-5)); padding: var(--pxn-space-4) var(--pxn-space-5); } }
.pxadjf__actionbar-inner { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-4); flex-wrap: wrap; }
.pxadjf__actionbar-hint { display: inline-flex; align-items: center; gap: var(--pxn-space-2); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxadjf__actionbar-btns { display: flex; gap: var(--pxn-space-3); }
</style>
