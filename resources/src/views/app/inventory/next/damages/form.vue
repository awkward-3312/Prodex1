<template>
  <div class="px-next pxdmgf">
    <!--
      C3.2 — Alta / edición de daño px-next. Un solo componente para «crear» y
      «editar» (prop `mode`). Conserva el contrato de Create_Damage.vue /
      Edit_Damage.vue: mismos endpoints (damages/*), mismo payload, mismas
      validaciones, autocomplete por almacén, variantes, lotes
      (batches_for_damage) y show_product_data.
      DIFERENCIA CLAVE frente a Ajustes: un daño SOLO resta stock. Cada línea
      tiene type = "sub" de forma fija — no hay selector de tipo ni adiciones.
    -->
    <div v-if="!allowed" class="pxdmgf__denied">
      <px-empty-state icon="lock" :title="deniedTitle" :description="deniedDesc" />
    </div>

    <template v-else>
      <div v-if="isLoading" class="pxdmgf__pad"><px-skeleton variant="card" :rows="8" /></div>

      <px-alert v-else-if="loadError" tone="danger" title="No se pudo cargar el daño" class="pxdmgf__alert">
        {{ loadError }}
        <template #actions><px-button size="sm" variant="secondary" @click="loadElements()">Reintentar</px-button></template>
      </px-alert>

      <validation-observer v-else ref="obs">
        <form @submit.prevent="submit">
          <px-page-header
            :title="isEdit ? 'Editar daño' : 'Nuevo daño'"
            :breadcrumbs="[{ label: 'Inventario' }, { label: 'Daños' }, { label: isEdit ? (damage.Ref || damage.id || '—') : 'Nuevo' }]"
          >
            <template #meta v-if="isEdit && damage.Ref">
              <span class="pxn-mono">{{ damage.Ref }}</span>
            </template>
            <template #actions>
              <px-button variant="ghost" icon="arrow-left" type="button" @click="goCancel">Cancelar</px-button>
              <px-button variant="primary" icon="check" type="submit" :loading="submitting" :disabled="hasBatchValidationErrors">
                {{ submitting ? 'Guardando…' : 'Guardar daño' }}
              </px-button>
            </template>
          </px-page-header>

          <div class="pxdmgf__grid">
            <div class="pxdmgf__main">
              <!-- ===== Datos del daño ===== -->
              <px-card title="Datos del daño" class="pxdmgf__sec">
                <div class="pxdmgf__row2">
                  <v-field name="Almacén" label="Almacén" required :rules="{ required: true }" v-slot="{ invalid, id }">
                    <vs-px
                      :input-id="id"
                      :invalid="invalid"
                      :disabled="details.length > 0"
                      v-model="damage.warehouse_id"
                      @input="onWarehouseChange"
                      :reduce="o => o.value"
                      placeholder="Elegir almacén"
                      :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
                    />
                  </v-field>
                  <v-field name="Fecha" label="Fecha" required :rules="{ required: true }" v-slot="{ invalid, id }">
                    <px-input :id="id" type="date" v-model="damage.date" :invalid="invalid" />
                  </v-field>
                </div>
                <p v-if="details.length > 0" class="pxdmgf__hint">
                  <lucide-icon name="info" :size="13" /> El almacén queda fijo mientras haya productos en el daño.
                </p>
              </px-card>

              <!-- ===== Productos ===== -->
              <px-card title="Productos" class="pxdmgf__sec">
                <div class="pxdmgf-ac">
                  <px-button
                    type="button" variant="secondary" size="sm" icon-only icon="scan-line"
                    aria-label="Escanear" @click="scanOpen = true"
                  />
                  <div class="pxdmgf-ac__box">
                    <input
                      class="pxdmgf-ac__input pxn-ring"
                      placeholder="Escanear / buscar por código, nombre o código de barras"
                      @input="e => search_input = e.target.value"
                      @keyup="search()"
                      @focus="focused = true"
                      @blur="focused = false"
                      ref="ac"
                    />
                    <ul v-show="focused && product_filter.length" class="pxdmgf-ac__list pxn-scroll">
                      <li v-for="pf in product_filter" :key="pf.id + '-' + (pf.product_variant_id || 0)"
                        class="pxdmgf-ac__opt" @mousedown="pickProduct(pf)">{{ getResultValue(pf) }}</li>
                    </ul>
                  </div>
                </div>

                <div class="pxdmgf-tbl__wrap pxn-scroll">
                  <table class="pxdmgf-tbl">
                    <thead>
                      <tr>
                        <th style="width: 44px;">#</th>
                        <th>Código</th>
                        <th>Producto</th>
                        <th class="is-right" style="width: 130px;">Stock actual</th>
                        <th style="width: 170px;">Cantidad a descontar</th>
                        <th style="width: 44px;"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-if="!details.length"><td colspan="6" class="pxdmgf-tbl__empty">Añade productos al daño.</td></tr>
                      <template v-for="detail in details">
                        <tr :key="'r-' + detail.detail_id" :class="{ 'is-deleted': detail.del === 1 }">
                          <td class="pxn-num">{{ detail.detail_id }}</td>
                          <td class="pxn-mono">{{ detail.code }}</td>
                          <td>
                            <span class="pxdmgf-tbl__name">{{ detail.name }}</span>
                            <px-badge v-if="detail.is_batch_tracked" tone="info" icon="package" :title="batchBadgeTitle(detail)">Lote</px-badge>
                            <px-badge v-if="detail.product_type === 'is_combo'" tone="neutral" icon="layers">Combo</px-badge>
                          </td>
                          <td class="is-right">
                            <span class="pxdmgf-tbl__stock">{{ detail.current }} {{ detail.unit }}</span>
                          </td>
                          <td>
                            <div class="pxdmgf-qty">
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
                          <td class="is-center">
                            <px-button type="button" size="sm" variant="danger" icon-only icon="x" aria-label="Quitar" @click="removeLine(detail.detail_id)" />
                          </td>
                        </tr>
                        <tr v-if="detail.is_batch_tracked && detail.del !== 1" :key="'b-' + detail.detail_id" class="pxdmgf-tbl__batchrow">
                          <td colspan="6">
                            <line-batch-picker :detail="detail" :decimals="priceDecimals" />
                          </td>
                        </tr>
                      </template>
                    </tbody>
                  </table>
                </div>
              </px-card>

              <!-- ===== Nota ===== -->
              <px-card title="Nota" class="pxdmgf__sec">
                <px-field label="Nota del daño">
                  <template #default="{ id }">
                    <px-textarea :id="id" v-model="damage.notes" :rows="4" placeholder="Motivo del daño, referencia interna…" />
                  </template>
                </px-field>
              </px-card>

              <px-alert v-if="hasBatchValidationErrors" tone="warning" class="pxdmgf__sec">
                {{ firstBatchErrorMessage }}
              </px-alert>

              <div class="pxdmgf__spacer" aria-hidden="true"></div>
            </div>

            <!-- ===== Rail: resumen ===== -->
            <aside class="pxdmgf__rail">
              <div class="pxdmgf__rail-sticky">
                <px-card title="Resumen" class="pxdmgf__summary">
                  <dl class="pxdmgf__summary-dl">
                    <div><dt>Almacén</dt><dd>{{ warehouseName || '—' }}</dd></div>
                    <div><dt>Fecha</dt><dd>{{ damage.date || '—' }}</dd></div>
                    <div><dt>Líneas</dt><dd class="pxn-num">{{ activeLines.length }}</dd></div>
                    <div><dt>Total a descontar</dt><dd class="pxn-num pxdmgf__neg">- {{ totalSub }}</dd></div>
                  </dl>
                </px-card>
                <px-alert tone="info" bare class="pxdmgf__tip">
                  <lucide-icon name="info" :size="13" />
                  Un daño no tiene coste; solo resta la cantidad indicada del stock del almacén elegido.
                </px-alert>
              </div>
            </aside>
          </div>

          <div class="pxdmgf__actionbar">
            <div class="pxdmgf__actionbar-inner">
              <span class="pxdmgf__actionbar-hint"><lucide-icon name="info" :size="14" /> Revisa las líneas y guarda el daño.</span>
              <div class="pxdmgf__actionbar-btns">
                <px-button variant="secondary" type="button" @click="goCancel">Cancelar</px-button>
                <px-button variant="primary" type="submit" icon="check" :loading="submitting" :disabled="hasBatchValidationErrors">
                  {{ submitting ? 'Guardando…' : 'Guardar daño' }}
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
import PxButton from "@/components/px-next/PxButton.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import VField from "@/views/app/products/next/edit/VField.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";
import LineBatchPicker from "../adjustments/LineBatchPicker.vue";

// C3.2 — mismo contrato que Create_Damage.vue / Edit_Damage.vue.
export default {
  name: "DamageFormNext",
  metaInfo() {
    return { title: this.isEdit ? "Editar daño" : "Nuevo daño" };
  },
  components: {
    PxPageHeader, PxCard, PxField, PxInput, PxTextarea, PxButton, PxBadge,
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
      damage: {
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
      // Quirk histórico conservado: crear usa damage_view; editar usa adjustment_edit.
      return this.isEdit ? list.includes("adjustment_edit") : list.includes("damage_view");
    },
    deniedTitle() {
      return this.isEdit ? "No tienes permiso para editar daños" : "No tienes permiso para crear daños";
    },
    deniedDesc() {
      return "Pide a un administrador el permiso «" + (this.isEdit ? "adjustment_edit" : "damage_view") + "».";
    },
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    warehouseName() {
      const w = this.warehouses.find(x => String(x.id) === String(this.damage.warehouse_id));
      return w ? w.name : (this.damage.warehouse || "");
    },
    activeLines() {
      return (this.details || []).filter(d => d && d.del !== 1);
    },
    totalSubNum() {
      return this.activeLines.reduce((s, d) => s + (Number(d.quantity) || 0), 0);
    },
    totalSub() {
      return this.fmt(this.totalSubNum);
    },
    // Idéntico al legacy: cualquier línea batch-tracked con reparto de lotes
    // incompleto/inválido bloquea el guardado. (Excluye del === 1 en edición.)
    // En Daños siempre es "sub", así que la validación de disponibilidad aplica.
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
          if (q > (Number(b.qty_available) || 0) + 0.01) return true;
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
          if (q > (Number(b.qty_available) || 0) + 0.01) return "La cantidad del lote supera el stock disponible para " + label;
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
      this.$router.push({ name: "index_damage" });
    },

    //------- carga inicial (create: GET damages/create · edit: GET damages/{id}/edit)
    loadElements() {
      this.loadError = null;
      this.isLoading = true;
      if (this.isEdit) {
        const id = this.$route.params.id;
        window.axios
          .get(`damages/${id}/edit`)
          .then(response => {
            this.damage = response.data.damage;
            this.details = response.data.details || [];
            this.warehouses = response.data.warehouses || [];
            this.getProductsByWarehouse(this.damage.warehouse_id);
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
          .get("damages/create")
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
      if (this.damage.warehouse_id === "" || this.damage.warehouse_id == null) {
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
        type: "sub", // Daños: siempre resta
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

      const wid = this.damage && this.damage.warehouse_id;
      const productId = detail.product_id || detail.id;
      if (!wid || !productId) { this.$set(detail, "batches_loading", false); return; }
      const variantSeg = detail.product_variant_id != null && detail.product_variant_id !== "" ? detail.product_variant_id : 0;

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
        .get(`batches_for_damage/${productId}/${wid}/${variantSeg}`, { timeout: 15000 })
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
    batchBadgeTitle() {
      return "Se asignarán automáticamente los lotes que caducan antes (FEFO) al guardar si no los indicas.";
    },

    //------- cantidad por línea (idéntico al legacy: clamp a stock)
    onQtyInput(detail, val) {
      const num = parseFloat(String(val).replace(",", "."));
      const q = Number.isFinite(num) ? num : "";
      if (q !== "" && q > Number(detail.current)) {
        this.makeToast("warning", "Stock insuficiente.", "Aviso");
        this.$set(detail, "quantity", Number(detail.current));
      } else {
        this.$set(detail, "quantity", q);
      }
    },
    increment(detail) {
      const next = (Number(detail.quantity) || 0) + 1;
      if (next > Number(detail.current)) {
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
        this.makeToast("warning", "Añade al menos un producto al daño.", "Aviso");
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
          warehouse_id: this.damage.warehouse_id,
          date: this.damage.date,
          notes: this.damage.notes,
          details: this.buildSubmitDetails()
        };
        const req = this.isEdit
          ? window.axios.put(`damages/${this.$route.params.id}`, payload)
          : window.axios.post("damages", payload);
        req
          .then(() => {
            NProgress.done();
            this.submitting = false;
            this.$router.push({ name: "index_damage" });
            this.makeToast("success", this.isEdit ? "Daño actualizado." : "Daño creado.", "Éxito");
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
.pxdmgf { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) 0; }
@media (max-width: 620px) { .pxdmgf { padding: var(--pxn-space-6) var(--pxn-space-5) 0; } }
.pxdmgf__denied { padding: var(--pxn-space-12) 0; }
.pxdmgf__pad { padding: var(--pxn-space-6) 0; }
.pxdmgf__alert { margin-top: var(--pxn-space-5); }

.pxdmgf__grid {
  display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: var(--pxn-space-6);
  margin-top: var(--pxn-space-6); padding-bottom: calc(var(--pxn-space-12) + 40px);
}
@media (max-width: 1080px) { .pxdmgf__grid { grid-template-columns: minmax(0, 1fr); } }
.pxdmgf__main { display: flex; flex-direction: column; gap: var(--pxn-space-6); min-width: 0; }
.pxdmgf__row2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 720px) { .pxdmgf__row2 { grid-template-columns: minmax(0, 1fr); } }
.pxdmgf__hint { margin: var(--pxn-space-4) 0 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); display: flex; align-items: center; gap: var(--pxn-space-2); }
.pxdmgf__spacer { height: var(--pxn-space-6); }

/* autocomplete */
.pxdmgf-ac { display: flex; align-items: flex-start; gap: var(--pxn-space-3); margin-bottom: var(--pxn-space-5); }
.pxdmgf-ac__box { position: relative; flex: 1 1 auto; min-width: 0; }
.pxdmgf-ac__input {
  width: 100%; height: var(--pxn-control-h-md); padding: 0 var(--pxn-space-4);
  border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); color: var(--pxn-ink); font: inherit; font-size: var(--pxn-fs-body);
}
.pxdmgf-ac__list {
  position: absolute; z-index: var(--pxn-z-dropdown, 1200); left: 0; right: 0; top: calc(100% + 4px);
  max-height: 240px; overflow-y: auto; margin: 0; padding: var(--pxn-space-3); list-style: none;
  background: var(--pxn-surface); border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md); box-shadow: var(--pxn-shadow-menu);
}
.pxdmgf-ac__opt { padding: var(--pxn-space-3) var(--pxn-space-4); border-radius: var(--pxn-radius-sm); font-size: var(--pxn-fs-body); cursor: pointer; }
.pxdmgf-ac__opt:hover { background: var(--pxn-surface-2); }

/* editable lines table */
.pxdmgf-tbl__wrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); overflow-x: auto; background: var(--pxn-surface); }
.pxdmgf-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-body); }
.pxdmgf-tbl th {
  text-align: left; padding: var(--pxn-space-4);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
  text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3);
  background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap;
}
.pxdmgf-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); vertical-align: middle; }
.pxdmgf-tbl tr:last-child td { border-bottom: 0; }
.pxdmgf-tbl .is-right { text-align: right; }
.pxdmgf-tbl .is-center { text-align: center; }
.pxdmgf-tbl__empty { text-align: center; color: var(--pxn-ink-3); padding: var(--pxn-space-6); }
.pxdmgf-tbl__name { font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); margin-right: var(--pxn-space-2); }
.pxdmgf-tbl__stock {
  display: inline-block; padding: 2px var(--pxn-space-3); border-radius: var(--pxn-radius-pill);
  background: var(--pxn-warning-soft); color: var(--pxn-warning-ink); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
}
.pxdmgf-tbl tr.is-deleted td { opacity: 0.45; text-decoration: line-through; }
.pxdmgf-tbl__batchrow td { padding: 0 var(--pxn-space-4) var(--pxn-space-3); border-bottom: 1px solid var(--pxn-border); background: var(--pxn-bg); }

.pxdmgf-qty { display: flex; align-items: center; gap: var(--pxn-space-2); }
.pxdmgf-qty :deep(.pxn-input) { text-align: center; height: var(--pxn-control-h-sm); }

/* rail */
.pxdmgf__rail { min-width: 0; }
@media (max-width: 1080px) { .pxdmgf__rail { order: -1; } .pxdmgf__rail-sticky { position: static; } .pxdmgf__tip { display: none; } }
.pxdmgf__rail-sticky { position: sticky; top: var(--pxn-space-6); display: flex; flex-direction: column; gap: var(--pxn-space-5); }
.pxdmgf__summary-dl { display: flex; flex-direction: column; }
.pxdmgf__summary-dl > div { display: flex; justify-content: space-between; gap: var(--pxn-space-4); padding: var(--pxn-space-3) 0; border-bottom: 1px dashed var(--pxn-border); }
.pxdmgf__summary-dl > div:last-child { border-bottom: 0; }
.pxdmgf__summary-dl dt { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxdmgf__summary-dl dd { margin: 0; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); text-align: right; }
.pxdmgf__neg { color: var(--pxn-danger-ink); }
.pxdmgf__tip :deep(svg) { vertical-align: -2px; margin-right: var(--pxn-space-2); }

/* action bar */
.pxdmgf__actionbar {
  position: sticky; bottom: 0; left: 0; right: 0; z-index: var(--pxn-z-sticky);
  margin: 0 calc(-1 * var(--pxn-space-9)); padding: var(--pxn-space-4) var(--pxn-space-9);
  background: var(--pxn-surface); border-top: 1px solid var(--pxn-border);
  box-shadow: 0 -4px 16px rgba(16, 24, 40, 0.06);
}
@media (max-width: 620px) { .pxdmgf__actionbar { margin: 0 calc(-1 * var(--pxn-space-5)); padding: var(--pxn-space-4) var(--pxn-space-5); } }
.pxdmgf__actionbar-inner { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-4); flex-wrap: wrap; }
.pxdmgf__actionbar-hint { display: inline-flex; align-items: center; gap: var(--pxn-space-2); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxdmgf__actionbar-btns { display: flex; gap: var(--pxn-space-3); }
</style>
