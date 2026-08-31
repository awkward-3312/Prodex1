<template>
  <div class="px-next pxtrf">
    <!--
      C3.25 — Alta / edición de traslado px-next (preview dev-only).
      Un solo componente para «crear» y «editar» (prop `mode`).

      Conserva el contrato real de create_transfer.vue / edit_transfer.vue:
        · POST transfers  /  PUT transfers/{id}
        · payload { transfer, details, GrandTotal }
        · coste / descuento / impuesto por línea (NO es la lógica de Ajustes)
        · variantes, unidades, cantidades, lotes, escáner
        · validaciones: ≥1 línea, origen ≠ destino, cantidad > 0, lotes válidos

      Diferencia clave con el legacy: este preview habla DIRECTAMENTE con la API
      location-aware (transfer-location/*), sin depender del interceptor vanilla
      prodex-transfer-location-ui.js. Cada petición lleva meta.prodexTransferLocation
      para que ese interceptor la deje pasar intacta (no re-intercepta nada).
        · GET transfer-location/options                       → orígenes + destinos por origen
        · GET transfer-location/{loc}/products                → catálogo del origen
        · GET transfer-location/{loc}/products/{p}?variant    → datos de producto
        · GET transfer-location/{loc}/batches/{p}/{v}          → lotes disponibles
      Al enviar, el payload fija from/to_inventory_location_id + los warehouse
      legacy (legacy_warehouse_id), exactamente como haría el interceptor.
    -->
    <div v-if="!allowed" class="pxtrf__denied">
      <px-empty-state icon="lock" :title="deniedTitle" :description="deniedDesc" />
    </div>

    <template v-else>
      <div v-if="isLoading" class="pxtrf__pad"><px-skeleton variant="card" :rows="8" /></div>

      <px-alert v-else-if="loadError" tone="danger" title="No se pudo cargar el traslado" class="pxtrf__alert">
        {{ loadError }}
        <template #actions><px-button size="sm" variant="secondary" @click="loadElements()">Reintentar</px-button></template>
      </px-alert>

      <px-alert v-else-if="legacyOnly" tone="warning" title="Este traslado es previo al modelo de ubicaciones" class="pxtrf__alert">
        Edítalo desde la pantalla clásica para no alterar sus movimientos de stock.
        <template #actions>
          <px-button size="sm" variant="secondary" icon="arrow-up-right"
            @click="$router.push({ name: 'edit_transfer', params: { id: $route.params.id } })">Abrir en clásico</px-button>
        </template>
      </px-alert>

      <px-alert v-else-if="notEditable" tone="warning" title="Este traslado no se puede editar" class="pxtrf__alert">
        {{ notEditable }}
        <template #actions>
          <px-button size="sm" variant="secondary" icon="eye"
            @click="$router.push({ name: 'detail_transfer', params: { id: $route.params.id } })">Ver detalle</px-button>
        </template>
      </px-alert>

      <validation-observer v-else ref="obs">
        <form @submit.prevent="submit">
          <px-page-header
            :title="isEdit ? 'Editar traslado' : 'Nuevo traslado'"
            :breadcrumbs="[{ label: 'Inventario' }, { label: 'Traslados' }, { label: isEdit ? (transfer.Ref || transfer.id || '—') : 'Nuevo' }]"
          >
            <template #meta v-if="isEdit && transfer.Ref">
              <span class="pxn-mono">{{ transfer.Ref }}</span>
            </template>
            <template #actions>
              <px-button variant="ghost" icon="arrow-left" type="button" @click="goCancel">Cancelar</px-button>
              <px-button variant="primary" icon="check" type="submit" :loading="submitting" :disabled="hasBatchValidationErrors">
                {{ submitting ? 'Guardando…' : 'Guardar traslado' }}
              </px-button>
            </template>
          </px-page-header>

          <div class="pxtrf__grid">
            <div class="pxtrf__main">
              <!-- ===== Datos del traslado ===== -->
              <px-card title="Datos del traslado" class="pxtrf__sec">
                <div class="pxtrf__row3">
                  <v-field name="Fecha" label="Fecha" required :rules="{ required: true }" v-slot="{ invalid, id }">
                    <px-input :id="id" type="date" v-model="transfer.date" :invalid="invalid" />
                  </v-field>
                  <v-field name="Origen" label="Origen" required :rules="{ required: true }" v-slot="{ invalid, id }">
                    <vs-px
                      :input-id="id"
                      :invalid="invalid"
                      :disabled="details.length > 0"
                      v-model="fromLocationId"
                      @input="onOriginChange"
                      :reduce="o => o.value"
                      placeholder="Elegir origen"
                      :options="sources.map(s => ({ label: s.name, value: s.id }))"
                    />
                  </v-field>
                  <v-field name="Destino" label="Destino" required :rules="{ required: true }" v-slot="{ invalid, id }">
                    <vs-px
                      :input-id="id"
                      :invalid="invalid"
                      :disabled="!fromLocationId"
                      v-model="toLocationId"
                      :reduce="o => o.value"
                      placeholder="Elegir destino"
                      :options="destinationOptions.map(d => ({ label: d.name, value: d.id }))"
                    />
                  </v-field>
                </div>
                <div class="pxtrf__row3">
                  <px-field label="Estado">
                    <template #default="{ id }">
                      <px-select
                        :id="id"
                        :value="transfer.statut"
                        :options="statutOptions"
                        @input="v => transfer.statut = v"
                      />
                    </template>
                  </px-field>
                  <div></div>
                  <div></div>
                </div>
                <p v-if="details.length > 0" class="pxtrf__hint">
                  <lucide-icon name="info" :size="13" /> El origen queda fijo mientras haya productos en el traslado.
                </p>
                <p v-if="businessRouting && fromLocationId && !destinationOptions.length" class="pxtrf__hint pxtrf__hint--warn">
                  <lucide-icon name="info" :size="13" /> Este origen no tiene destinos de traslado permitidos.
                </p>
              </px-card>

              <!-- ===== Productos ===== -->
              <px-card title="Productos" class="pxtrf__sec">
                <div class="pxtrf-ac">
                  <px-button
                    type="button" variant="secondary" size="sm" icon-only icon="scan-line"
                    aria-label="Escanear" @click="scanOpen = true"
                  />
                  <div class="pxtrf-ac__box">
                    <input
                      class="pxtrf-ac__input pxn-ring"
                      :placeholder="'Escanear / buscar por código, nombre o código de barras'"
                      @input="e => search_input = e.target.value"
                      @keyup="search()"
                      @focus="focused = true"
                      @blur="focused = false"
                      ref="ac"
                    />
                    <ul v-show="focused && product_filter.length" class="pxtrf-ac__list pxn-scroll">
                      <li v-for="pf in product_filter" :key="pf.id + '-' + (pf.product_variant_id || 0)"
                        class="pxtrf-ac__opt" @mousedown="pickProduct(pf)">{{ getResultValue(pf) }}</li>
                    </ul>
                  </div>
                </div>

                <div class="pxtrf-tbl__wrap pxn-scroll">
                  <table class="pxtrf-tbl">
                    <thead>
                      <tr>
                        <th style="width: 44px;">#</th>
                        <th>Producto</th>
                        <th class="is-right" style="width: 130px;">Coste neto</th>
                        <th class="is-right" style="width: 120px;">Stock</th>
                        <th style="width: 160px;">Cantidad</th>
                        <th class="is-right" style="width: 120px;">Descuento</th>
                        <th class="is-right" style="width: 110px;">Impuesto</th>
                        <th class="is-right" style="width: 130px;">Subtotal</th>
                        <th style="width: 76px;"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-if="!details.length"><td colspan="9" class="pxtrf-tbl__empty">Añade productos al traslado.</td></tr>
                      <template v-for="detail in details">
                        <tr :key="'r-' + detail.detail_id">
                          <td class="pxn-num">{{ detail.detail_id }}</td>
                          <td>
                            <div class="pxn-mono pxtrf-tbl__code">{{ detail.code }}</div>
                            <span class="pxtrf-tbl__name">{{ detail.name }}</span>
                            <div v-if="detail.warehouse_location" class="pxtrf-tbl__loc">
                              Ubicación: <strong>{{ detail.warehouse_location }}</strong>
                            </div>
                            <px-badge v-if="detail.is_batch_tracked" tone="info" icon="package">Lote</px-badge>
                          </td>
                          <td class="is-right pxn-num">{{ money(detail.Net_cost) }}</td>
                          <td class="is-right">
                            <span class="pxtrf-tbl__stock">{{ fmtQty(detail.stock) }} {{ detail.unitPurchase }}</span>
                          </td>
                          <td>
                            <div class="pxtrf-qty">
                              <px-button
                                type="button" size="sm" variant="secondary" icon-only icon="minus"
                                aria-label="Restar" @click="decrement(detail)"
                              />
                              <px-input
                                :value="String(detail.quantity == null ? '' : detail.quantity)"
                                inputmode="decimal"
                                @input="val => onQtyInput(detail, val)"
                              />
                              <px-button
                                type="button" size="sm" variant="secondary" icon-only icon="plus"
                                aria-label="Sumar" @click="increment(detail)"
                              />
                            </div>
                          </td>
                          <td class="is-right pxn-num">{{ money((detail.DiscountNet || 0) * (detail.quantity || 0)) }}</td>
                          <td class="is-right pxn-num">{{ money((detail.taxe || 0) * (detail.quantity || 0)) }}</td>
                          <td class="is-right pxn-num is-strong">{{ money(detail.subtotal || 0) }}</td>
                          <td class="is-center">
                            <px-button type="button" size="sm" variant="ghost" icon-only icon="pencil" aria-label="Editar línea" @click="openDetailModal(detail)" />
                            <px-button type="button" size="sm" variant="danger" icon-only icon="x" aria-label="Quitar" @click="removeLine(detail.detail_id)" />
                          </td>
                        </tr>
                        <tr v-if="detail.is_batch_tracked" :key="'b-' + detail.detail_id" class="pxtrf-tbl__batchrow">
                          <td colspan="9">
                            <line-batch-picker :detail="detail" :decimals="priceDecimals" :required-base="baseQty(detail)" />
                          </td>
                        </tr>
                      </template>
                    </tbody>
                  </table>
                </div>
              </px-card>

              <!-- ===== Totales del traslado ===== -->
              <px-card title="Ajustes de total" class="pxtrf__sec">
                <div class="pxtrf__row3">
                  <px-field label="Impuesto de la orden (%)">
                    <template #default="{ id }">
                      <px-input :id="id" inputmode="decimal" :value="String(transfer.tax_rate)" @input="v => onMoneyInput('tax_rate', v)" />
                    </template>
                  </px-field>
                  <px-field label="Descuento">
                    <template #default="{ id }">
                      <px-input :id="id" inputmode="decimal" :value="String(transfer.discount)" @input="v => onMoneyInput('discount', v)" />
                    </template>
                  </px-field>
                  <px-field label="Envío">
                    <template #default="{ id }">
                      <px-input :id="id" inputmode="decimal" :value="String(transfer.shipping)" @input="v => onMoneyInput('shipping', v)" />
                    </template>
                  </px-field>
                </div>
              </px-card>

              <!-- ===== Nota ===== -->
              <px-card title="Nota" class="pxtrf__sec">
                <px-field label="Nota del traslado">
                  <template #default="{ id }">
                    <px-textarea :id="id" v-model="transfer.notes" :rows="4" placeholder="Motivo, referencia interna…" />
                  </template>
                </px-field>
              </px-card>

              <px-alert v-if="hasBatchValidationErrors" tone="warning" class="pxtrf__sec">
                {{ firstBatchErrorMessage }}
              </px-alert>

              <div class="pxtrf__spacer" aria-hidden="true"></div>
            </div>

            <!-- ===== Rail: resumen ===== -->
            <aside class="pxtrf__rail">
              <div class="pxtrf__rail-sticky">
                <px-card title="Resumen" class="pxtrf__summary">
                  <dl class="pxtrf__summary-dl">
                    <div><dt>Origen</dt><dd>{{ fromName || '—' }}</dd></div>
                    <div><dt>Destino</dt><dd>{{ toName || '—' }}</dd></div>
                    <div><dt>Fecha</dt><dd>{{ transfer.date || '—' }}</dd></div>
                    <div><dt>Líneas</dt><dd class="pxn-num">{{ details.length }}</dd></div>
                    <div><dt>Impuesto</dt><dd class="pxn-num">{{ money(transfer.TaxNet) }} ({{ Number(transfer.tax_rate) || 0 }}%)</dd></div>
                    <div><dt>Descuento</dt><dd class="pxn-num">{{ money(transfer.discount) }}</dd></div>
                    <div><dt>Envío</dt><dd class="pxn-num">{{ money(transfer.shipping) }}</dd></div>
                    <div class="pxtrf__summary-total"><dt>Total</dt><dd class="pxn-num">{{ money(GrandTotal) }}</dd></div>
                  </dl>
                </px-card>
                <px-alert tone="info" bare class="pxtrf__tip">
                  <lucide-icon name="info" :size="13" />
                  Al guardar, el backend aprueba y despacha el traslado: descuenta el stock del origen y lo pone en tránsito.
                </px-alert>
              </div>
            </aside>
          </div>

          <div class="pxtrf__actionbar">
            <div class="pxtrf__actionbar-inner">
              <span class="pxtrf__actionbar-hint"><lucide-icon name="info" :size="14" /> Revisa las líneas y guarda el traslado.</span>
              <div class="pxtrf__actionbar-btns">
                <px-button variant="secondary" type="button" @click="goCancel">Cancelar</px-button>
                <px-button variant="primary" type="submit" icon="check" :loading="submitting" :disabled="hasBatchValidationErrors">
                  {{ submitting ? 'Guardando…' : 'Guardar traslado' }}
                </px-button>
              </div>
            </div>
          </div>
        </form>
      </validation-observer>

      <!-- ===== Modal editar línea ===== -->
      <px-modal v-model="detailModalOpen" :title="editDetail.name || 'Editar línea'" size="md">
        <div class="pxtrf__dform">
          <px-field label="Coste del producto">
            <template #default="{ id }"><px-input :id="id" inputmode="decimal" :value="String(editDetail.Unit_cost)" @input="v => editDetail.Unit_cost = numOr(v, editDetail.Unit_cost)" /></template>
          </px-field>
          <px-field label="Método de impuesto">
            <template #default="{ id }">
              <px-select :id="id" :value="editDetail.tax_method"
                :options="[{ value: '1', label: 'Exclusivo' }, { value: '2', label: 'Inclusivo' }]"
                @input="v => editDetail.tax_method = v" />
            </template>
          </px-field>
          <px-field label="Impuesto (%)">
            <template #default="{ id }"><px-input :id="id" inputmode="decimal" :value="String(editDetail.tax_percent)" @input="v => editDetail.tax_percent = numOr(v, editDetail.tax_percent)" /></template>
          </px-field>
          <px-field label="Método de descuento">
            <template #default="{ id }">
              <px-select :id="id" :value="editDetail.discount_Method"
                :options="[{ value: '1', label: 'Porcentaje %' }, { value: '2', label: 'Fijo' }]"
                @input="v => editDetail.discount_Method = v" />
            </template>
          </px-field>
          <px-field label="Descuento">
            <template #default="{ id }"><px-input :id="id" inputmode="decimal" :value="String(editDetail.discount)" @input="v => editDetail.discount = numOr(v, editDetail.discount)" /></template>
          </px-field>
          <px-field label="Unidad de compra">
            <template #default="{ id }">
              <vs-px :input-id="id" v-model="editDetail.purchase_unit_id" :reduce="o => o.value"
                placeholder="Elegir unidad"
                :options="units.map(u => ({ label: u.name, value: u.id }))" />
            </template>
          </px-field>
        </div>
        <template #footer="{ close }">
          <span class="pxtrf__grow" />
          <px-button variant="secondary" @click="close">Cancelar</px-button>
          <px-button variant="primary" icon="check" @click="applyDetailModal">Aplicar</px-button>
        </template>
      </px-modal>

      <px-modal v-model="scanOpen" title="Escáner de código de barras" size="md">
        <qrcode-scanner :qrbox="250" :fps="10" style="width:100%;" @result="onScan" />
      </px-modal>
    </template>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import { getPriceDecimals, getPriceFormatSetting, formatPriceDisplay } from "@/utils/priceFormat";
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
import LineBatchPicker from "@/views/app/inventory/next/adjustments/LineBatchPicker.vue";

const PXTL_META = { meta: { prodexTransferLocation: true, skipErrorRedirect: true } };

export default {
  name: "TransferFormNext",
  metaInfo() {
    return { title: this.isEdit ? "Editar traslado" : "Nuevo traslado" };
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
      legacyOnly: false,
      notEditable: null,
      submitting: false,
      scanOpen: false,
      focused: false,
      timer: null,
      search_input: "",
      product_filter: [],
      products: [],
      legacyPending: [],
      details: [],
      sources: [],
      destinationGroups: {},
      businessRouting: false,
      fromLocationId: "",
      toLocationId: "",
      units: [],
      detailModalOpen: false,
      editDetail: {},
      total: 0,
      GrandTotal: 0,
      transfer: {
        id: "",
        Ref: "",
        from_warehouse: "",
        to_warehouse: "",
        statut: this.mode === "edit" ? "" : "completed",
        notes: "",
        date: this.mode === "edit" ? "" : new Date().toISOString().slice(0, 10),
        items: 0,
        tax_rate: 0,
        TaxNet: 0,
        shipping: 0,
        discount: 0
      }
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    isEdit() { return this.mode === "edit"; },
    allowed() {
      const list = Array.isArray(this.currentUserPermissions) ? this.currentUserPermissions : [];
      return this.isEdit ? list.includes("transfer_edit") : list.includes("transfer_add");
    },
    deniedTitle() {
      return this.isEdit ? "No tienes permiso para editar traslados" : "No tienes permiso para crear traslados";
    },
    deniedDesc() {
      return "Pide a un administrador el permiso «" + (this.isEdit ? "transfer_edit" : "transfer_add") + "».";
    },
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    statutOptions() {
      return [
        { value: "completed", label: "Completado" },
        { value: "sent", label: "Enviado" },
        { value: "pending", label: "Pendiente" }
      ];
    },
    destinationOptions() {
      const rows = this.destinationGroups[String(this.fromLocationId)];
      return Array.isArray(rows) ? rows : [];
    },
    fromSource() {
      return this.sources.find(s => String(s.id) === String(this.fromLocationId)) || null;
    },
    toDestination() {
      return this.destinationOptions.find(d => String(d.id) === String(this.toLocationId)) || null;
    },
    fromName() { return this.fromSource ? this.fromSource.name : ""; },
    toName() { return this.toDestination ? this.toDestination.name : ""; },
    // Idéntico al legacy create/edit: cualquier línea batch-tracked con reparto
    // de lotes incompleto o inválido bloquea el guardado.
    hasBatchValidationErrors() {
      if (!Array.isArray(this.details)) return false;
      for (const d of this.details) {
        if (!d || !d.is_batch_tracked) continue;
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
        const target = Math.round(this.baseQty(d) * 10000) / 10000;
        if (Math.abs(total - target) > 0.01) return true;
      }
      return false;
    },
    firstBatchErrorMessage() {
      if (!Array.isArray(this.details)) return "";
      for (const d of this.details) {
        if (!d || !d.is_batch_tracked) continue;
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
        const target = Math.round(this.baseQty(d) * 10000) / 10000;
        if (Math.abs(total - target) > 0.01) {
          return "La suma de los lotes no coincide con la cantidad requerida (" + total + " / " + target + ") — " + label;
        }
      }
      return "";
    }
  },
  watch: {
    // Caso de un único lote: reflejar la cantidad BASE requerida de la línea en
    // el lote (los lotes se cuentan en unidad base; la línea, en unidad de compra).
    details: {
      deep: true,
      handler(details) {
        if (!Array.isArray(details)) return;
        for (const d of details) {
          if (!d || !d.is_batch_tracked) continue;
          const batches = Array.isArray(d.batches) ? d.batches : [];
          if (batches.length !== 1) continue;
          const b = batches[0];
          const base = this.baseQty(d);
          const batchQty = Number(b.qty);
          if (Number.isFinite(base) && base > 0 && batchQty !== base) this.$set(b, "qty", base);
        }
      }
    }
  },
  created() {
    if (this.allowed) this.loadElements();
  },
  methods: {
    // Convierte la cantidad de la línea (unidad de compra) a unidad base, con el
    // mismo contrato que TransferLocationDispatchService::toBaseQuantity del
    // backend. Sin factores hardcodeados: usa operator/operator_value del producto.
    baseQty(d) {
      const q = Number(d && d.quantity) || 0;
      const op = d && d.unit_operator;
      const ov = Number(d && d.unit_operator_value);
      if (!op || !Number.isFinite(ov) || ov <= 0) return q;
      return op === "/" ? q / ov : q * ov;
    },
    money(v) {
      const decimals = getPriceDecimals({ store: this.$store });
      const key = getPriceFormatSetting({ store: this.$store });
      const sym = (this.currentUser && this.currentUser.currency) || "";
      return (sym ? sym + " " : "") + formatPriceDisplay(Number(v) || 0, decimals, key);
    },
    fmtQty(v) {
      const n = Number(v);
      return Number.isFinite(n) ? String(n) : String(v == null ? "" : v);
    },
    numOr(v, fallback) {
      const n = parseFloat(String(v).replace(",", "."));
      return Number.isFinite(n) ? n : (fallback == null ? 0 : fallback);
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    },
    goCancel() { this.$router.push({ name: "index_transfer" }); },
    errMsg(err) {
      return (
        (err && err.response && err.response.data && (err.response.data.message || err.response.data.error)) ||
        (err && err.response && err.response.data && err.response.data.errors &&
          Object.values(err.response.data.errors)[0] && Object.values(err.response.data.errors)[0][0]) ||
        (err && err.message) || "Error de red."
      );
    },

    //------- carga inicial
    loadElements() {
      this.loadError = null;
      this.legacyOnly = false;
      this.isLoading = true;
      window.axios
        .get("transfer-location/options", PXTL_META)
        .then(response => {
          const opt = response.data || {};
          this.sources = Array.isArray(opt.sources) ? opt.sources : [];
          this.destinationGroups = opt.destination_groups || {};
          this.businessRouting = !!opt.business_routing;
          if (this.isEdit) return this.hydrateEdit();
          this.isLoading = false;
        })
        .catch(err => {
          this.loadError = this.errMsg(err);
          setTimeout(() => { this.isLoading = false; }, 300);
        });
    },
    hydrateEdit() {
      const id = this.$route.params.id;
      return Promise.all([
        window.axios.get(`transfers/${id}/edit`, PXTL_META),
        window.axios.get(`transfer-location/transfers/${id}/context`, PXTL_META),
        window.axios.get(`transfer-workflow/${id}`, PXTL_META).catch(() => ({ data: {} }))
      ]).then(([editRes, ctxRes, wfRes]) => {
        const data = editRes.data || {};
        const ctx = ctxRes.data || {};
        const wf = (wfRes && wfRes.data && wfRes.data.transfer) || {};
        this.transfer = Object.assign({}, this.transfer, data.transfer || {});
        this.transfer.notes = (data.transfer && (data.transfer.notes || data.transfer.note)) || "";
        this.transfer.tax_rate = Number(this.transfer.tax_rate) || 0;
        this.transfer.discount = Number(this.transfer.discount) || 0;
        this.transfer.shipping = Number(this.transfer.shipping) || 0;
        this.transfer.TaxNet = Number(this.transfer.TaxNet) || 0;

        if (!ctx.location_aware) {
          this.legacyOnly = true;
          this.isLoading = false;
          return;
        }

        // Defensa en profundidad: el backend rechaza (409) editar un traslado
        // location-aware que ya movió stock; aquí lo mostramos como bloqueado.
        const approval = String(wf.approval_status || this.transfer.approval_status || "");
        const logistics = String(wf.logistics_status || "pending");
        const statut = String(wf.status || this.transfer.statut || "");
        if (approval === "approved") {
          this.notEditable = "La transferencia ya fue aprobada y no puede editarse. Crea una nueva si necesitas cambios.";
        } else if (logistics && !["", "pending"].includes(logistics)) {
          this.notEditable = "La transferencia ya inició su flujo logístico (" + logistics + ") y no puede editarse.";
        } else if (["sent", "completed"].includes(statut) || wf.dispatched_at || wf.received_at) {
          this.notEditable = "La transferencia ya fue despachada y no puede editarse.";
        }
        if (this.notEditable) { this.isLoading = false; return; }

        this.fromLocationId = ctx.from.id;
        this.toLocationId = ctx.to.id;
        // Asegura que el destino elegido esté en la lista aunque el routing haya cambiado.
        const grp = this.destinationGroups[String(ctx.from.id)] || [];
        if (!grp.some(d => String(d.id) === String(ctx.to.id))) {
          this.$set(this.destinationGroups, String(ctx.from.id), grp.concat([{ id: ctx.to.id, name: ctx.to.name }]));
        }

        this.details = (data.details || []).map((d, i) => this.normalizeExistingLine(d, i));
        this.getProductsByLocation(ctx.from.id);
        for (const d of this.details) {
          if (d && d.is_batch_tracked) this.fetchBatchesForDetail(d);
        }
        this.recalc();
        this.isLoading = false;
      }).catch(err => {
        this.loadError = this.errMsg(err);
        setTimeout(() => { this.isLoading = false; }, 300);
      });
    },
    normalizeExistingLine(d, i) {
      const line = Object.assign({}, d);
      line.detail_id = i + 1;
      line.quantity = Number(d.quantity) || 0;
      line.Net_cost = Number(d.Net_cost) || 0;
      line.Unit_cost = Number(d.Unit_cost) || 0;
      line.DiscountNet = Number(d.DiscountNet) || 0;
      line.discount = Number(d.discount) || 0;
      line.taxe = Number(d.taxe) || 0;
      line.tax_percent = Number(d.tax_percent) || 0;
      line.subtotal = 0;
      line.is_batch_tracked = !!d.is_batch_tracked;
      line.unit_operator = d.unit_operator || null;
      line.unit_operator_value = d.unit_operator_value != null ? Number(d.unit_operator_value) : null;
      line.batches = Array.isArray(d.batches)
        ? d.batches.map(b => ({
            product_batch_id: Number(b.product_batch_id || b.id) || null,
            batch_no: b.batch_no || "",
            expiry_date: b.expiry_date || null,
            qty_available: Number(b.qty_available) || 0,
            qty: Number(b.qty) || 0
          }))
        : [];
      line.available_batches = [];
      line.batches_loading = false;
      return line;
    },
    // El endpoint legacy transfers/{id}/edit no trae la conversión de unidad;
    // la rellenamos desde el catálogo location-aware una vez cargado.
    applyUnitFactorFromCatalog() {
      if (!Array.isArray(this.products) || !Array.isArray(this.details)) return;
      for (const d of this.details) {
        if (!d) continue;
        const match = this.products.find(p =>
          String(p.product_id) === String(d.product_id) &&
          String(p.product_variant_id || 0) === String(d.product_variant_id || 0)
        );
        if (match) {
          this.$set(d, "unit_operator", match.unit_operator || null);
          this.$set(d, "unit_operator_value", match.unit_operator_value != null ? Number(match.unit_operator_value) : null);
        }
      }
    },

    //------- catálogo del origen
    onOriginChange(value) {
      this.search_input = "";
      this.product_filter = [];
      this.toLocationId = "";
      this.details = [];
      if (value) this.getProductsByLocation(value);
      else this.products = [];
    },
    getProductsByLocation(locId) {
      if (!locId) return;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get(`transfer-location/${locId}/products`, PXTL_META)
        .then(response => {
          const d = response && response.data;
          // El endpoint devuelve { products, legacy_pending }. Compatibilidad con
          // la forma antigua (array plano) por si un despliegue va desfasado.
          this.products = Array.isArray(d) ? d : (d && Array.isArray(d.products) ? d.products : []);
          this.legacyPending = d && Array.isArray(d.legacy_pending) ? d.legacy_pending : [];
          this.applyUnitFactorFromCatalog();
          this.recalc();
          NProgress.done();
        })
        .catch(() => { NProgress.done(); this.products = []; this.legacyPending = []; });
    },
    search() {
      if (this.timer) { clearTimeout(this.timer); this.timer = null; }
      if (this.search_input.length < 2) return (this.product_filter = []);
      if (!this.fromLocationId) {
        this.makeToast("warning", "Elige primero un origen.", "Aviso");
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
          if (this.product_filter.length <= 0) {
            const t = this.search_input.toLowerCase();
            const pending = (this.legacyPending || []).find(p =>
              (p.code || "").toLowerCase() === t ||
              (p.name || "").toLowerCase().includes(t)
            );
            if (pending) {
              const nLoc = Number(pending.location_quantity || 0);
              const detail = nLoc > 0
                ? `Por ubicación hay ${nLoc} y en inventario heredado ${pending.legacy_quantity}: quedan ${pending.pending_quantity} sin reconciliar.`
                : `Tiene ${pending.legacy_quantity} en inventario heredado del almacén de origen y 0 por ubicación.`;
              this.makeToast(
                "warning",
                `"${pending.name}": ${detail} El excedente no se puede trasladar hasta reconciliar el inventario por ubicación.`,
                "Divergencia de inventario pendiente"
              );
            } else {
              this.makeToast("warning", "Producto no encontrado.", "Aviso");
            }
          }
        }
      }, 800);
    },
    getResultValue(result) {
      return result.code + " (" + result.name + ")";
    },
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
      const stock = result.qte_purchase;
      const line = {
        id: this.isEdit ? 0 : "",
        code: result.code,
        stock: stock,
        fix_stock: result.qte,
        quantity: stock < 1 ? stock : 1,
        name: "",
        product_id: "",
        detail_id: "",
        product_variant_id: result.product_variant_id,
        unitPurchase: "",
        purchase_unit_id: "",
        fix_cost: "",
        Net_cost: 0,
        Unit_cost: 0,
        DiscountNet: 0,
        discount: 0,
        discount_Method: "",
        taxe: 0,
        tax_percent: 0,
        tax_method: "",
        warehouse_location: null,
        subtotal: 0,
        is_batch_tracked: false,
        unit_operator: result.unit_operator || null,
        unit_operator_value: result.unit_operator_value != null ? Number(result.unit_operator_value) : null,
        batches: [],
        available_batches: [],
        batches_loading: false
      };
      window.axios
        .get(`transfer-location/${this.fromLocationId}/products/${result.id}` +
          (result.product_variant_id ? `?product_variant_id=${result.product_variant_id}` : ""), PXTL_META)
        .then(response => {
          const p = response.data || {};
          line.product_id = p.id;
          if (p.unit_operator != null) line.unit_operator = p.unit_operator;
          if (p.unit_operator_value != null) line.unit_operator_value = Number(p.unit_operator_value);
          line.name = p.name;
          line.discount = Number(p.discount) || 0;
          line.DiscountNet = Number(p.DiscountNet) || 0;
          line.discount_Method = p.discount_method;
          line.Net_cost = Number(p.Net_cost) || 0;
          line.Unit_cost = Number(p.Unit_cost) || 0;
          line.taxe = Number(p.tax_cost) || 0;
          line.tax_method = p.tax_method;
          line.tax_percent = Number(p.tax_percent) || 0;
          line.unitPurchase = p.unitPurchase;
          line.fix_cost = p.fix_cost;
          line.purchase_unit_id = p.purchase_unit_id;
          line.is_batch_tracked = !!p.is_batch_tracked;
          line.warehouse_location = p.warehouse_location
            ? (p.warehouse_location.name
                ? `${p.warehouse_location.code} - ${p.warehouse_location.name}`
                : p.warehouse_location.code)
            : null;
          this.addLine(line);
          this.recalc();
        })
        .catch(err => { this.makeToast("danger", this.errMsg(err), "Error"); });
      this.search_input = "";
      if (this.$refs.ac) this.$refs.ac.value = "";
      this.product_filter = [];
    },
    addLine(line) {
      line.detail_id = this.details.length > 0 ? this.details[this.details.length - 1].detail_id + 1 : 1;
      this.details.push(line);
      const last = this.details[this.details.length - 1];
      if (last && last.is_batch_tracked) this.fetchBatchesForDetail(last);
    },
    removeLine(id) {
      for (let i = 0; i < this.details.length; i++) {
        if (id === this.details[i].detail_id) { this.details.splice(i, 1); break; }
      }
      this.recalc();
    },

    //------- lotes
    fetchBatchesForDetail(detail) {
      if (!detail || !detail.is_batch_tracked) return;
      if (!("batches_loading" in detail)) this.$set(detail, "batches_loading", false);
      if (!("available_batches" in detail)) this.$set(detail, "available_batches", []);
      if (!Array.isArray(detail.batches)) this.$set(detail, "batches", []);
      const locId = this.fromLocationId;
      const productId = detail.product_id || detail.id;
      if (!locId || !productId) { this.$set(detail, "batches_loading", false); return; }
      const variantSeg = detail.product_variant_id != null && detail.product_variant_id !== "" ? detail.product_variant_id : 0;

      const existingQtyById = {};
      for (const b of (Array.isArray(detail.batches) ? detail.batches : [])) {
        if (b && b.product_batch_id != null) {
          existingQtyById[b.product_batch_id] = (existingQtyById[b.product_batch_id] || 0) + (Number(b.qty) || 0);
        }
      }

      this.$set(detail, "batches_loading", true);
      window.axios
        .get(`transfer-location/${locId}/batches/${productId}/${variantSeg}`, PXTL_META)
        .then(response => {
          const raw = response && response.data && Array.isArray(response.data.batches) ? response.data.batches : [];
          const list = raw.map(ab => ({
            ...ab,
            qty_available: (Number(ab.qty_available) || 0) + (existingQtyById[ab.id] || 0)
          }));
          this.$set(detail, "available_batches", list);
          if (Array.isArray(detail.batches)) {
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

    //------- cantidad (legacy create: clamp a stock)
    onQtyInput(detail, val) {
      const num = parseFloat(String(val).replace(",", "."));
      let q = Number.isFinite(num) ? num : "";
      if (q !== "" && q > Number(detail.stock)) {
        this.makeToast("warning", "Stock insuficiente en el origen.", "Aviso");
        q = Number(detail.stock);
      }
      this.$set(detail, "quantity", q);
      this.recalc();
    },
    increment(detail) {
      const next = (Number(detail.quantity) || 0) + 1;
      if (next > Number(detail.stock)) { this.makeToast("warning", "Stock insuficiente en el origen.", "Aviso"); return; }
      this.$set(detail, "quantity", next);
      this.recalc();
    },
    decrement(detail) {
      const next = (Number(detail.quantity) || 0) - 1;
      if (next >= 1) { this.$set(detail, "quantity", next); this.recalc(); }
    },

    //------- editar línea (modal)
    openDetailModal(detail) {
      this.editDetail = {
        detail_id: detail.detail_id,
        name: detail.name,
        Unit_cost: detail.Unit_cost,
        tax_method: detail.tax_method,
        tax_percent: detail.tax_percent,
        discount_Method: detail.discount_Method,
        discount: detail.discount,
        purchase_unit_id: detail.purchase_unit_id,
        fix_cost: detail.fix_cost,
        fix_stock: detail.fix_stock,
        stock: detail.stock
      };
      this.units = [];
      window.axios
        .get("get_units?id=" + detail.product_id, PXTL_META)
        .then(({ data }) => { this.units = Array.isArray(data) ? data : []; })
        .catch(() => { this.units = []; });
      this.detailModalOpen = true;
    },
    applyDetailModal() {
      const target = this.details.find(d => d.detail_id === this.editDetail.detail_id);
      if (!target) { this.detailModalOpen = false; return; }
      const ed = this.editDetail;
      // Conversión de stock por unidad de compra (idéntico al legacy create).
      const unit = this.units.find(u => u.id == ed.purchase_unit_id);
      if (unit && ed.fix_stock !== "" && ed.fix_stock != null) {
        target.stock = unit.operator === "/"
          ? Number(ed.fix_stock) * Number(unit.operator_value)
          : Number(ed.fix_stock) / Number(unit.operator_value);
        target.unitPurchase = unit.ShortName;
      }
      // Cambiar de unidad recalcula la cantidad base requerida por los lotes.
      if (unit) {
        this.$set(target, "unit_operator", unit.operator || null);
        this.$set(target, "unit_operator_value", unit.operator_value != null ? Number(unit.operator_value) : null);
      }
      if (Number(target.stock) < Number(target.quantity)) target.quantity = Number(target.stock);
      else target.quantity = target.quantity || 1;

      target.Unit_cost = Number(ed.Unit_cost) || 0;
      target.tax_percent = Number(ed.tax_percent) || 0;
      target.tax_method = ed.tax_method;
      target.discount_Method = ed.discount_Method;
      target.discount = Number(ed.discount) || 0;
      target.purchase_unit_id = ed.purchase_unit_id;

      if (target.discount_Method == "2") {
        target.DiscountNet = target.discount;
      } else {
        target.DiscountNet = parseFloat((target.Unit_cost * target.discount) / 100) || 0;
      }
      if (target.tax_method == "1") {
        target.Net_cost = parseFloat(target.Unit_cost - target.DiscountNet) || 0;
        target.taxe = parseFloat((target.tax_percent * (target.Unit_cost - target.DiscountNet)) / 100) || 0;
      } else {
        target.taxe = parseFloat((target.Unit_cost - target.DiscountNet) * (target.tax_percent / 100)) || 0;
        target.Net_cost = parseFloat(target.Unit_cost - target.taxe - target.DiscountNet) || 0;
      }
      this.recalc();
      this.detailModalOpen = false;
    },

    //------- totales (idéntico a Calcul_Total del legacy)
    onMoneyInput(field, val) {
      const num = parseFloat(String(val).replace(",", "."));
      this.transfer[field] = Number.isFinite(num) ? num : 0;
      this.recalc();
    },
    recalc() {
      this.total = 0;
      for (let i = 0; i < this.details.length; i++) {
        const tax = (Number(this.details[i].taxe) || 0) * (Number(this.details[i].quantity) || 0);
        this.details[i].subtotal = parseFloat((Number(this.details[i].quantity) || 0) * (Number(this.details[i].Net_cost) || 0) + tax) || 0;
        this.total = parseFloat(this.total + this.details[i].subtotal);
      }
      const totalWithoutDiscount = parseFloat(this.total - (Number(this.transfer.discount) || 0));
      this.transfer.TaxNet = parseFloat((totalWithoutDiscount * (Number(this.transfer.tax_rate) || 0)) / 100) || 0;
      this.GrandTotal = parseFloat(totalWithoutDiscount + this.transfer.TaxNet + (Number(this.transfer.shipping) || 0)) || 0;
      this.GrandTotal = parseFloat(this.GrandTotal.toFixed(this.priceDecimals));
      this.transfer.items = this.details.reduce((s, d) => s + (Number(d.quantity) || 0), 0);
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
        this.makeToast("warning", "Añade al menos un producto al traslado.", "Aviso");
        return false;
      }
      if (String(this.fromLocationId) === String(this.toLocationId)) {
        this.makeToast("warning", "El origen y el destino no pueden ser iguales.", "Aviso");
        return false;
      }
      let empty = 0;
      for (const d of this.details) {
        if (d.quantity === "" || Number(d.quantity) === 0) empty += 1;
      }
      if (empty > 0) {
        this.makeToast("warning", "Indica la cantidad en todas las líneas.", "Aviso");
        return false;
      }
      if (this.hasBatchValidationErrors) {
        this.makeToast("danger", this.firstBatchErrorMessage || "Hay lotes con cantidades inválidas.", "Error");
        return false;
      }
      return true;
    },
    submit() {
      this.$refs.obs.validate().then(ok => {
        if (!ok) { this.makeToast("danger", "Completa el formulario correctamente.", "Error"); return; }
        if (!this.verifiedForm()) return;
        const src = this.fromSource;
        const dst = this.toDestination;
        if (!src || !dst) { this.makeToast("danger", "Origen o destino no válidos.", "Error"); return; }

        this.recalc();
        this.submitting = true;
        NProgress.start(); NProgress.set(0.1);

        const transferPayload = Object.assign({}, this.transfer, {
          from_inventory_location_id: Number(src.id),
          to_inventory_location_id: Number(dst.id),
          from_warehouse: Number(src.legacy_warehouse_id),
          to_warehouse: Number(dst.legacy_warehouse_id)
        });
        const body = { transfer: transferPayload, details: this.buildSubmitDetails(), GrandTotal: this.GrandTotal };
        const req = this.isEdit
          ? window.axios.put(`transfers/${this.$route.params.id}`, body, PXTL_META)
          : window.axios.post("transfers", body, PXTL_META);
        req
          .then(response => {
            NProgress.done();
            this.submitting = false;
            const t = (response && response.data && response.data.transfer) || {};
            let msg;
            if (this.isEdit) {
              msg = "Traslado actualizado.";
            } else if (t.auto_dispatched === false || t.approval_status === "pending") {
              msg = "Traslado creado como pendiente de aprobación. Un usuario con permiso de edición debe aprobarlo y despacharlo.";
            } else {
              msg = "Traslado creado y despachado.";
            }
            this.makeToast("success", msg, "Éxito");
            this.$router.push({ name: "index_transfer" });
          })
          .catch(err => {
            NProgress.done();
            this.submitting = false;
            const status = err && err.response && err.response.status;
            const code = err && err.response && err.response.data && err.response.data.code;
            if (this.isEdit && (status === 409 || code === "transfer_not_editable")) {
              // El traslado dejó de ser editable (p. ej. se aprobó/despachó entre
              // que se abrió el formulario y se guardó). Bloquéalo y explícalo.
              this.notEditable = this.errMsg(err);
              return;
            }
            this.makeToast("danger", this.errMsg(err), "Error");
          });
      });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxtrf { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) 0; }
@media (max-width: 620px) { .pxtrf { padding: var(--pxn-space-6) var(--pxn-space-5) 0; } }
.pxtrf__denied { padding: var(--pxn-space-12) 0; }
.pxtrf__pad { padding: var(--pxn-space-6) 0; }
.pxtrf__alert { margin-top: var(--pxn-space-5); }

.pxtrf__grid {
  display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: var(--pxn-space-6);
  margin-top: var(--pxn-space-6); padding-bottom: calc(var(--pxn-space-12) + 40px);
}
@media (max-width: 1080px) { .pxtrf__grid { grid-template-columns: minmax(0, 1fr); } }
.pxtrf__main { display: flex; flex-direction: column; gap: var(--pxn-space-6); min-width: 0; }
.pxtrf__row3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 720px) { .pxtrf__row3 { grid-template-columns: minmax(0, 1fr); } }
.pxtrf__hint { margin: var(--pxn-space-4) 0 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); display: flex; align-items: center; gap: var(--pxn-space-2); }
.pxtrf__hint--warn { color: var(--pxn-warning-ink); }
.pxtrf__spacer { height: var(--pxn-space-6); }

.pxtrf-ac { display: flex; align-items: flex-start; gap: var(--pxn-space-3); margin-bottom: var(--pxn-space-5); }
.pxtrf-ac__box { position: relative; flex: 1 1 auto; min-width: 0; }
.pxtrf-ac__input {
  width: 100%; height: var(--pxn-control-h-md); padding: 0 var(--pxn-space-4);
  border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); color: var(--pxn-ink); font: inherit; font-size: var(--pxn-fs-body);
}
.pxtrf-ac__list {
  position: absolute; z-index: var(--pxn-z-dropdown, 1200); left: 0; right: 0; top: calc(100% + 4px);
  max-height: 240px; overflow-y: auto; margin: 0; padding: var(--pxn-space-3); list-style: none;
  background: var(--pxn-surface); border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md); box-shadow: var(--pxn-shadow-menu);
}
.pxtrf-ac__opt { padding: var(--pxn-space-3) var(--pxn-space-4); border-radius: var(--pxn-radius-sm); font-size: var(--pxn-fs-body); cursor: pointer; }
.pxtrf-ac__opt:hover { background: var(--pxn-surface-2); }

.pxtrf-tbl__wrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); overflow-x: auto; background: var(--pxn-surface); }
.pxtrf-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-body); }
.pxtrf-tbl th {
  text-align: left; padding: var(--pxn-space-4);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
  text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3);
  background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap;
}
.pxtrf-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); vertical-align: middle; }
.pxtrf-tbl tr:last-child td { border-bottom: 0; }
.pxtrf-tbl .is-right { text-align: right; }
.pxtrf-tbl .is-center { text-align: center; white-space: nowrap; }
.pxtrf-tbl .is-strong { font-weight: var(--pxn-fw-semibold); }
.pxtrf-tbl__empty { text-align: center; color: var(--pxn-ink-3); padding: var(--pxn-space-6); }
.pxtrf-tbl__code { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxtrf-tbl__name { font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); margin-right: var(--pxn-space-2); }
.pxtrf-tbl__loc { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); margin: 2px 0; }
.pxtrf-tbl__stock {
  display: inline-block; padding: 2px var(--pxn-space-3); border-radius: var(--pxn-radius-pill);
  background: var(--pxn-warning-soft); color: var(--pxn-warning-ink); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
}
.pxtrf-tbl__batchrow td { padding: 0 var(--pxn-space-4) var(--pxn-space-3); border-bottom: 1px solid var(--pxn-border); background: var(--pxn-bg); }
.pxtrf-qty { display: flex; align-items: center; gap: var(--pxn-space-2); }
.pxtrf-qty :deep(.pxn-input) { text-align: center; height: var(--pxn-control-h-sm); }

.pxtrf__dform { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4); }
@media (max-width: 560px) { .pxtrf__dform { grid-template-columns: minmax(0, 1fr); } }
.pxtrf__grow { flex: 1; }

.pxtrf__rail { min-width: 0; }
@media (max-width: 1080px) { .pxtrf__rail { order: -1; } .pxtrf__rail-sticky { position: static; } .pxtrf__tip { display: none; } }
.pxtrf__rail-sticky { position: sticky; top: var(--pxn-space-6); display: flex; flex-direction: column; gap: var(--pxn-space-5); }
.pxtrf__summary-dl { display: flex; flex-direction: column; }
.pxtrf__summary-dl > div { display: flex; justify-content: space-between; gap: var(--pxn-space-4); padding: var(--pxn-space-3) 0; border-bottom: 1px dashed var(--pxn-border); }
.pxtrf__summary-dl > div:last-child { border-bottom: 0; }
.pxtrf__summary-dl dt { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxtrf__summary-dl dd { margin: 0; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); text-align: right; }
.pxtrf__summary-total dt, .pxtrf__summary-total dd { font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-bold); color: var(--pxn-ink); }
.pxtrf__tip :deep(svg) { vertical-align: -2px; margin-right: var(--pxn-space-2); }

.pxtrf__actionbar {
  position: sticky; bottom: 0; left: 0; right: 0; z-index: var(--pxn-z-sticky);
  margin: 0 calc(-1 * var(--pxn-space-9)); padding: var(--pxn-space-4) var(--pxn-space-9);
  background: var(--pxn-surface); border-top: 1px solid var(--pxn-border);
  box-shadow: 0 -4px 16px rgba(16, 24, 40, 0.06);
}
@media (max-width: 620px) { .pxtrf__actionbar { margin: 0 calc(-1 * var(--pxn-space-5)); padding: var(--pxn-space-4) var(--pxn-space-5); } }
.pxtrf__actionbar-inner { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-4); flex-wrap: wrap; }
.pxtrf__actionbar-hint { display: inline-flex; align-items: center; gap: var(--pxn-space-2); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxtrf__actionbar-btns { display: flex; gap: var(--pxn-space-3); }
</style>
