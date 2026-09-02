<template>
  <div class="px-next pxd">
    <!--
      C2A — Detalle de producto px-next (preview).
      Accesible por alias dev-only /app/_ui/producto/:id/detalle mientras
      /app/products/detail/:id sigue sirviendo la vista legacy. Tras el QA se
      hace el cutover; /app/products/detail-classic/:id queda de rollback.
      Conserva toda la información del detalle legacy. Endpoints existentes:
      GET get_product_detail_api/{id} + GET product_batches. El label de tipo
      se deriva de product.type (payload intacto); no reproduce el quirk
      is_combo → "Service".
    -->

    <div v-if="!can('products_view')" class="pxd__denied">
      <px-empty-state
        icon="lock"
        title="No tienes permiso para ver productos"
        description="Pide a un administrador el permiso «products_view»."
      />
    </div>

    <template v-else>
      <div v-if="initialLoading" class="pxd__pad">
        <px-skeleton variant="card" :rows="6" />
      </div>

      <px-alert v-else-if="error" tone="danger" title="No se pudo cargar el producto" class="pxd__alert">
        {{ error }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <template v-else-if="m">
        <px-page-header
          :title="m.name"
          :breadcrumbs="[{ label: 'Inventario' }, { label: 'Productos' }, { label: m.name }]"
        >
          <template #meta>
            <span class="pxn-mono">{{ m.code }}</span>
            <span><lucide-icon name="shapes" :size="13" /> {{ m.typeLabel }}</span>
            <span v-if="m.brand"><lucide-icon name="bookmark" :size="13" /> {{ m.brand }}</span>
            <span v-if="m.unit"><lucide-icon name="ruler" :size="13" /> {{ m.unit }}</span>
          </template>
          <template #actions>
            <px-button variant="ghost" icon="arrow-left" @click="goBack">Volver</px-button>
            <px-button variant="secondary" icon="printer" @click="print">Imprimir</px-button>
            <px-button
              v-if="can('products_edit')"
              variant="primary"
              icon="pencil"
              @click="goEdit"
            >Editar</px-button>
          </template>
        </px-page-header>

        <!-- KPIs de precio (no aplican a variable: el precio es por variante) -->
        <div v-if="m.type !== 'is_variant'" class="pxd__kpis">
          <px-stat
            v-if="m.type === 'is_single' || m.type === 'is_combo'"
            label="Costo"
            :value="money(m.cost)"
            :value-title="money(m.cost)"
            icon="wallet"
            bordered
          />
          <px-stat label="Precio" :value="money(m.price)" :value-title="money(m.price)" icon="tag" bordered />
          <px-stat
            label="Precio mayoreo"
            :value="money(m.wholesalePrice)"
            :value-title="money(m.wholesalePrice)"
            icon="store"
            bordered
          />
          <px-stat
            label="Precio mínimo"
            :value="money(m.minPrice)"
            :value-title="money(m.minPrice)"
            icon="chevrons-down"
            bordered
          />
          <px-stat
            v-if="m.type !== 'is_service'"
            label="Alerta de stock"
            :value="m.stockAlert != null ? num(m.stockAlert, 2) : '—'"
            icon="bell"
            bordered
          />
          <px-stat v-if="m.points" label="Puntos" :value="String(m.points)" icon="medal" bordered />
        </div>

        <!-- Zona 1: código de barras + galería (única franja a 2 columnas) -->
        <div class="pxd__top" :class="{ 'pxd__top--solo': !showBarcode }">
          <px-card v-if="showBarcode" class="pxd__barcode-card" title="Código de barras">
            <div class="pxd__barcode">
              <!-- barcode siempre negro sobre blanco: los escáneres lo exigen -->
              <barcode
                :format="m.typeBarcode"
                :value="m.code"
                :height="42"
                :width="2"
                :text-margin="0"
                font-options="bold"
                background="#ffffff"
                line-color="#101828"
              >
                <span class="pxn-muted">Código no válido para {{ m.typeBarcode }}</span>
              </barcode>
            </div>
          </px-card>

          <px-card class="pxd__gallery-card">
            <template #header>
              <h3 class="pxn-card__title">Galería</h3>
              <span v-if="m.hasImage" class="pxd__count">{{ m.gallery.length }}</span>
            </template>
            <div class="pxd__gallery">
              <div class="pxd__gallery-main" :class="{ 'is-empty': !m.hasImage }">
                <img
                  v-if="m.hasImage"
                  :src="activeUrl"
                  alt=""
                  loading="lazy"
                  decoding="async"
                  @error="onImgError($event)"
                />
                <span v-else class="pxd__gallery-empty">
                  <lucide-icon name="image-off" :size="30" />
                  <span>Sin imágenes</span>
                </span>
              </div>
              <div v-if="m.gallery.length > 1" class="pxd__thumbs">
                <button
                  v-for="(url, i) in m.galleryUrls"
                  :key="i"
                  type="button"
                  class="pxd__thumb pxn-ring"
                  :class="{ 'is-active': i === activeImage }"
                  :aria-label="`Imagen ${i + 1}`"
                  @click="activeImage = i"
                >
                  <img :src="url" alt="" loading="lazy" decoding="async" @error="onImgError($event)" />
                </button>
              </div>
            </div>
          </px-card>
        </div>

        <!-- El resto de secciones, a ancho completo -->
        <div class="pxd__stack">
            <px-card title="Información del producto" class="pxd__wide">
              <dl class="pxd__dl pxd__dl--cols">
                <div class="pxd__row"><dt>Tipo</dt><dd>{{ m.typeLabel }}</dd></div>
                <div class="pxd__row"><dt>Código</dt><dd class="pxn-mono">{{ m.code }}</dd></div>
                <div class="pxd__row"><dt>Nombre</dt><dd>{{ m.name }}</dd></div>
                <div class="pxd__row">
                  <dt>Categoría</dt>
                  <dd>
                    <span v-if="m.categories.length" class="pxd__tags">
                      <px-tag v-for="c in m.categories" :key="c" :label="c" :hue="c" />
                    </span>
                    <span v-else class="pxn-muted">—</span>
                  </dd>
                </div>
                <div v-if="m.subcategories.length" class="pxd__row">
                  <dt>Subcategoría</dt>
                  <dd><span class="pxd__tags"><px-tag v-for="s in m.subcategories" :key="s" :label="s" :hue="s" /></span></dd>
                </div>
                <div class="pxd__row"><dt>Marca</dt><dd :class="{ 'pxn-muted': !m.brand }">{{ m.brand || '—' }}</dd></div>

                <div v-if="m.type === 'is_single' || m.type === 'is_combo'" class="pxd__row">
                  <dt>Costo</dt><dd class="pxn-num">{{ money(m.cost) }}</dd>
                </div>
                <div v-if="m.type !== 'is_variant'" class="pxd__row"><dt>Precio</dt><dd class="pxn-num">{{ money(m.price) }}</dd></div>
                <div v-if="m.type !== 'is_variant'" class="pxd__row"><dt>Precio mayoreo</dt><dd class="pxn-num">{{ money(m.wholesalePrice) }}</dd></div>
                <div v-if="m.type !== 'is_variant'" class="pxd__row"><dt>Precio mínimo</dt><dd class="pxn-num">{{ money(m.minPrice) }}</dd></div>

                <div v-if="m.type !== 'is_service'" class="pxd__row"><dt>Unidad</dt><dd>{{ m.unit || '—' }}</dd></div>

                <div class="pxd__row"><dt>Impuesto</dt><dd class="pxn-num">{{ m.taxe != null ? num(m.taxe, 2) + ' %' : '—' }}</dd></div>
                <div v-if="m.taxe" class="pxd__row">
                  <dt>Método de impuesto</dt>
                  <dd><px-badge :tone="m.taxMethod === 'Exclusive' ? 'info' : 'neutral'">{{ m.taxMethod === 'Exclusive' ? 'Exclusivo' : 'Inclusivo' }}</px-badge></dd>
                </div>
                <div class="pxd__row">
                  <dt>Descuento</dt>
                  <dd><span v-if="m.discountText" class="pxn-num">{{ m.discountText }}</span><span v-else class="pxn-muted">Sin descuento</span></dd>
                </div>

                <div v-if="m.type !== 'is_service'" class="pxd__row"><dt>Alerta de stock</dt><dd class="pxn-num">{{ m.stockAlert != null ? num(m.stockAlert, 2) : '—' }}</dd></div>
                <div v-if="m.type !== 'is_service' && m.weight" class="pxd__row"><dt>Peso</dt><dd class="pxn-num">{{ num(m.weight, 2) }}</dd></div>
                <div v-if="m.points" class="pxd__row"><dt>Puntos</dt><dd class="pxn-num">{{ m.points }}</dd></div>
                <div v-if="m.gtin" class="pxd__row"><dt>Código de barras (GTIN)</dt><dd class="pxn-mono">{{ m.gtin }}</dd></div>
                <div v-if="m.typeBarcode" class="pxd__row"><dt>Simbología</dt><dd>{{ m.typeBarcode }}</dd></div>
              </dl>
            </px-card>

            <px-card
              v-if="m.warranty.period || m.warranty.terms || m.warranty.hasGuarantee"
              title="Garantía"
              class="pxd__wide"
            >
              <dl class="pxd__dl">
                <div v-if="m.warranty.period" class="pxd__row">
                  <dt>Periodo de garantía</dt>
                  <dd><px-badge tone="info">{{ m.warranty.period }} {{ warrantyUnitLabel(m.warranty.unit) }}</px-badge></dd>
                </div>
                <div v-if="m.warranty.terms" class="pxd__row"><dt>Términos</dt><dd>{{ m.warranty.terms }}</dd></div>
                <div v-if="m.warranty.hasGuarantee" class="pxd__row">
                  <dt>Garantía extendida</dt>
                  <dd><px-badge tone="success">{{ m.warranty.guaranteePeriod }} {{ warrantyUnitLabel(m.warranty.guaranteeUnit) }}</px-badge></dd>
                </div>
              </dl>
            </px-card>

            <px-card v-if="m.note" title="Notas" class="pxd__wide">
              <p class="pxd__note">{{ m.note }}</p>
            </px-card>

        <!-- Productos combinados -->
        <px-card v-if="m.type === 'is_combo' && m.comboRows.length" title="Productos combinados" class="pxd__wide">
          <px-table :columns="comboCols" :rows="m.comboRows" row-key="code">
            <template #cell-code="{ row }"><span class="pxn-mono">{{ row.code }}</span></template>
            <template #cell-quantity="{ row }"><span class="pxn-num">{{ num(row.quantity, 2) }}</span></template>
          </px-table>
        </px-card>

        <!-- Variantes -->
        <px-card v-if="m.type === 'is_variant' && m.variantRows.length" title="Variantes" class="pxd__wide">
          <px-table :columns="variantCols" :rows="m.variantRows" row-key="code">
            <template #cell-code="{ row }"><span class="pxn-mono">{{ row.code }}</span></template>
            <template #cell-cost="{ row }"><span class="pxn-num">{{ money(row.cost) }}</span></template>
            <template #cell-price="{ row }"><span class="pxn-num">{{ money(row.price) }}</span></template>
            <template #cell-wholesale="{ row }"><span class="pxn-num">{{ money(row.wholesale) }}</span></template>
            <template #cell-min_price="{ row }"><span class="pxn-num">{{ money(row.min_price) }}</span></template>
          </px-table>
        </px-card>

        <!-- Existencias por almacén (Simple) -->
        <px-card
          v-if="m.type === 'is_single' && m.warehouseStock.length"
          class="pxd__wide"
        >
          <template #header>
            <h3 class="pxn-card__title">Existencias por almacén</h3>
            <px-badge tone="info">Total: {{ num(m.totalStock, 2) }} {{ m.unit }}</px-badge>
          </template>
          <div class="pxd__whgrid">
            <div v-for="(w, i) in m.warehouseStock" :key="i" class="pxd__wh">
              <span class="pxd__wh-icon"><lucide-icon name="warehouse" :size="16" /></span>
              <div>
                <div class="pxd__wh-name">{{ w.warehouse }}</div>
                <div class="pxd__wh-qty pxn-num">
                  {{ w.qty != null ? num(w.qty, 2) : '—' }}<span class="pxd__wh-unit">{{ m.unit }}</span>
                </div>
              </div>
            </div>
          </div>
        </px-card>

        <!-- Existencias por variante -->
        <px-card
          v-if="m.type === 'is_variant' && m.warehouseVariantStock.length"
          title="Existencias por variante"
          class="pxd__wide"
        >
          <px-table :columns="whVariantCols" :rows="m.warehouseVariantStock" :row-key="''">
            <template #cell-qty="{ row }">
              <span class="pxn-num">{{ row.qty != null ? num(row.qty, 2) : '—' }} {{ m.unit }}</span>
            </template>
            <template #cell-variant="{ row }"><px-tag :label="row.variant" :hue="row.variant" /></template>
          </px-table>
        </px-card>

        <!-- Lotes (farmacia / batch tracked) -->
        <px-card v-if="m.isBatchTracked" class="pxd__wide">
          <template #header>
            <h3 class="pxn-card__title">Lotes</h3>
            <span class="pxd__batchbadges">
              <px-badge tone="neutral">{{ batchModel.rows.length }} lote(s)</px-badge>
              <px-badge v-if="batchModel.rows.length" tone="info">Total: {{ num(batchModel.totalQty, 2) }} {{ m.unit }}</px-badge>
              <px-badge v-if="batchModel.expiredCount" tone="danger">{{ batchModel.expiredCount }} vencido(s)</px-badge>
              <px-badge v-if="batchModel.nearCount" tone="warning">{{ batchModel.nearCount }} por vencer</px-badge>
            </span>
          </template>

          <div v-if="batchesLoading" class="pxd__pad pxn-muted">Cargando lotes…</div>
          <px-empty-state
            v-else-if="!batchModel.rows.length"
            icon="info"
            title="Sin lotes registrados"
            description="Este producto aún no tiene lotes."
          />
          <px-table v-else :columns="batchCols" :rows="batchModel.rows" row-key="id">
            <template #cell-batchNo="{ row }"><span class="pxn-mono">{{ row.batchNo }}</span></template>
            <template #cell-variantName="{ row }">
              <px-tag v-if="row.variantName" :label="row.variantName" :hue="row.variantName" />
              <span v-else class="pxn-muted">—</span>
            </template>
            <template #cell-mfgDate="{ row }">
              <span v-if="row.mfgDate">{{ row.mfgDateLabel || row.mfgDate }}</span>
              <span v-else class="pxn-muted">—</span>
            </template>
            <template #cell-expiryDate="{ row }">
              <span v-if="row.expiryDate" class="pxd__expiry" :class="`is-${row.expiryBucket || 'valid'}`">{{ row.expiryDateLabel || row.expiryDate }}</span>
              <span v-else class="pxn-muted">—</span>
              <div v-if="row.daysToExpiry != null" class="pxd__expiry-sub">
                <template v-if="row.daysToExpiry < 0">Venció hace {{ Math.abs(row.daysToExpiry) }} d</template>
                <template v-else>Vence en {{ row.daysToExpiry }} d</template>
              </div>
            </template>
            <template #cell-qty="{ row }"><span class="pxn-num">{{ num(row.qty, 2) }} {{ m.unit }}</span></template>
            <template #cell-unitCost="{ row }">
              <span v-if="row.unitCost != null" class="pxn-num">{{ money(row.unitCost) }}</span>
              <span v-else class="pxn-muted">—</span>
            </template>
            <template #cell-status="{ row }">
              <px-badge :tone="batchStatusTone(row.status)">{{ row.statusLabel || row.status }}</px-badge>
            </template>
          </px-table>
        </px-card>
        </div>
      </template>
    </template>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import VueBarcode from "vue-barcode";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxStat from "@/components/px-next/PxStat.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxTag from "@/components/px-next/PxTag.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import { adaptDetail, adaptBatches } from "./adapter";
import { makeProductFormatters } from "../format";
import { buildPrintHtml, openPrintWindow } from "./print";

const WARRANTY_UNIT = { days: "días", months: "meses", years: "años" };

export default {
  name: "ProductDetailNext",
  metaInfo: { title: "Detalle de producto" },
  components: {
    barcode: VueBarcode,
    PxPageHeader, PxBadge, PxButton, PxCard, PxStat, PxTable, PxTag, PxAlert, PxEmptyState
  },
  data() {
    return {
      initialLoading: true,
      error: null,
      m: null,
      activeImage: 0,
      batchesLoading: false,
      batchModel: { rows: [], totalQty: 0, expiredCount: 0, nearCount: 0, hasAnyVariant: false, expiryWarningDays: 90 },
      comboCols: [
        { key: "code", label: "Código" },
        { key: "name", label: "Nombre" },
        { key: "quantity", label: "Cantidad", align: "right", numeric: true }
      ],
      variantCols: [
        { key: "code", label: "Código" },
        { key: "name", label: "Nombre" },
        { key: "cost", label: "Costo", align: "right", numeric: true },
        { key: "price", label: "Precio", align: "right", numeric: true },
        { key: "wholesale", label: "Mayoreo", align: "right", numeric: true },
        { key: "min_price", label: "Precio mín.", align: "right", numeric: true }
      ],
      whVariantCols: [
        { key: "warehouse", label: "Almacén" },
        { key: "variant", label: "Variante" },
        { key: "qty", label: "Cantidad", align: "right", numeric: true }
      ]
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    fmt() {
      return makeProductFormatters({
        currency: this.currentUser && this.currentUser.currency,
        store: this.$store
      });
    },
    activeUrl() {
      const urls = (this.m && this.m.galleryUrls) || [];
      if (!urls.length) return this.m ? this.m.mainImageUrl : "";
      return urls[Math.min(this.activeImage, urls.length - 1)];
    },
    showBarcode() {
      return !!this.m && this.m.type !== "is_variant" && !!this.m.code && !!this.m.typeBarcode;
    },
    batchCols() {
      const cols = [{ key: "batchNo", label: "Lote" }];
      if (this.batchModel.hasAnyVariant) cols.push({ key: "variantName", label: "Variante" });
      cols.push(
        { key: "warehouseName", label: "Almacén" },
        { key: "mfgDate", label: "Fabricación" },
        { key: "expiryDate", label: "Caducidad" },
        { key: "qty", label: "Cantidad", align: "right", numeric: true },
        { key: "unitCost", label: "Costo unit.", align: "right", numeric: true },
        { key: "status", label: "Estado" }
      );
      return cols;
    }
  },
  created() {
    this.fetch();
  },
  watch: {
    // Vue Router reutiliza el componente al cambiar sólo el :id — recargar.
    "$route.params.id"() {
      this.m = null;
      this.batchModel = { rows: [], totalQty: 0, expiredCount: 0, nearCount: 0, hasAnyVariant: false, expiryWarningDays: 90 };
      this.fetch();
    }
  },
  methods: {
    can(p) {
      const list = Array.isArray(this.currentUserPermissions) ? this.currentUserPermissions : [];
      return list.includes(p);
    },
    money(v) {
      return v == null ? "—" : this.fmt.money(v);
    },
    num(v, dec = 0) {
      return v == null ? "—" : this.fmt.number(v, dec);
    },
    warrantyUnitLabel(u) {
      return WARRANTY_UNIT[u] || u || "";
    },
    batchStatusTone(s) {
      const k = String(s || "").toLowerCase();
      if (k === "active") return "success";
      if (k === "quarantined") return "warning";
      if (k === "expired" || k === "written_off") return "danger";
      return "neutral";
    },
    onImgError(e) {
      const fb = this.$imgUrl ? this.$imgUrl("products", "no-image.png") : "";
      if (fb && e.target.src !== fb) e.target.src = fb;
    },
    goBack() {
      this.$router.go(-1);
    },
    goEdit() {
      this.$router.push({ name: "edit_product", params: { id: this.m.id } });
    },

    async fetch() {
      this.initialLoading = this.m == null;
      this.error = null;
      const id = this.$route.params.id;
      try {
        const { data } = await window.axios.get("get_product_detail_api/" + id, {
          meta: { skipInitialLoader: true }
        });
        this.m = adaptDetail(data, this.$imgUrl && this.$imgUrl.bind(this));
        this.activeImage = 0;
        if (this.m.isBatchTracked) this.loadBatches();
      } catch (e) {
        this.error =
          (e && e.response && e.response.data && (e.response.data.message || e.response.data.error)) ||
          (e && e.message) ||
          "Error de red.";
      } finally {
        this.initialLoading = false;
      }
    },

    async loadBatches() {
      const id = this.$route.params.id;
      this.batchesLoading = true;
      try {
        const { data } = await window.axios.get("product_batches", {
          params: { product_id: id, limit: 200, SortField: "expiry_date", SortType: "asc" },
          meta: { skipInitialLoader: true }
        });
        this.batchModel = adaptBatches(data);
      } catch (e) {
        this.batchModel = { rows: [], totalQty: 0, expiredCount: 0, nearCount: 0, hasAnyVariant: false, expiryWarningDays: 90 };
      } finally {
        this.batchesLoading = false;
      }
    },

    print() {
      if (!this.m) return;
      const html = buildPrintHtml(
        this.m,
        { money: v => this.fmt.money(v), number: (v, d) => this.fmt.number(v, d) },
        this.m.isBatchTracked ? this.batchModel : null
      );
      const ok = openPrintWindow(html);
      if (!ok && this.$bvToast) {
        this.$bvToast.toast("Permite las ventanas emergentes para imprimir.", {
          title: "Imprimir",
          variant: "warning",
          solid: true
        });
      }
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxd {
  min-height: 100%;
  background: var(--pxn-bg);
  padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-11);
}
@media (max-width: 620px) {
  .pxd { padding: var(--pxn-space-6) var(--pxn-space-5) var(--pxn-space-10); }
}
.pxd__denied { padding: var(--pxn-space-12) 0; }
.pxd__pad { padding: var(--pxn-space-6) 0; }
.pxd__alert { margin-top: var(--pxn-space-5); }

.pxd__kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: var(--pxn-space-5);
  margin-top: var(--pxn-space-6);
}

/* Zona 1 — única franja a 2 columnas: código de barras + galería. En cuanto
   una de las columnas termina, el resto de secciones va a ancho completo
   (nada de grid 2-col continua con un lado vacío). */
.pxd__top {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(280px, 340px);
  gap: var(--pxn-space-6);
  margin-top: var(--pxn-space-6);
  align-items: start;
}
.pxd__top--solo { grid-template-columns: minmax(280px, 360px); }
@media (max-width: 900px) {
  .pxd__top,
  .pxd__top--solo { grid-template-columns: minmax(0, 1fr); }
}

.pxd__stack { display: flex; flex-direction: column; gap: var(--pxn-space-6); margin-top: var(--pxn-space-6); }
.pxd__wide { margin-top: 0; } /* el gap del stack ya separa las secciones */

.pxd__barcode {
  display: flex;
  justify-content: center;
  padding: var(--pxn-space-3) var(--pxn-space-4);
  background: #ffffff;
  border-radius: var(--pxn-radius-md);
  border: 1px solid var(--pxn-border);
  :deep(svg) { max-width: 100%; height: auto; display: block; }
}

.pxd__dl { display: flex; flex-direction: column; }
/* La ficha de Información, a ancho completo, fluye en 2 columnas para no dejar
   un hueco enorme entre etiqueta y valor. Cada fila es indivisible. */
.pxd__dl--cols { display: block; }
@media (min-width: 860px) {
  .pxd__dl--cols { column-count: 2; column-gap: var(--pxn-space-10); }
  .pxd__dl--cols .pxd__row { break-inside: avoid; }
}
.pxd__row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: var(--pxn-space-5);
  padding: var(--pxn-space-4) 0;
  border-bottom: 1px dashed var(--pxn-border);
  flex-wrap: wrap;
}
.pxd__row:last-child { border-bottom: 0; }
.pxd__row dt { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); font-weight: var(--pxn-fw-medium); }
.pxd__row dd {
  margin: 0;
  font-size: var(--pxn-fs-body);
  color: var(--pxn-ink);
  font-weight: var(--pxn-fw-medium);
  text-align: right;
  min-width: 0;
  word-break: break-word;
}
.pxd__tags { display: inline-flex; flex-wrap: wrap; gap: var(--pxn-space-2); justify-content: flex-end; }

.pxd__note { margin: 0; white-space: pre-wrap; color: var(--pxn-ink-2); line-height: var(--pxn-lh-normal); }

.pxd__count {
  margin-left: var(--pxn-space-3);
  background: var(--pxn-primary-soft);
  color: var(--pxn-primary-ink);
  font-size: var(--pxn-fs-xs);
  font-weight: var(--pxn-fw-semibold);
  padding: 2px var(--pxn-space-3);
  border-radius: var(--pxn-radius-pill);
}
.pxd__gallery { display: flex; flex-direction: column; gap: var(--pxn-space-4); }
.pxd__gallery-main {
  width: 100%;
  aspect-ratio: 1 / 1;
  max-height: 340px;
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface-2);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
.pxd__gallery-main img { max-width: 100%; max-height: 100%; object-fit: contain; }
/* Sin imágenes: caja compacta, no un cuadrado enorme. */
.pxd__gallery-main.is-empty {
  aspect-ratio: auto;
  height: 200px;
  min-height: 200px;
  max-height: 200px;
  color: var(--pxn-ink-disabled);
}
.pxd__gallery-empty { display: flex; flex-direction: column; align-items: center; gap: var(--pxn-space-3); font-size: var(--pxn-fs-sm); }
.pxd__thumbs { display: grid; grid-template-columns: repeat(auto-fill, minmax(56px, 1fr)); gap: var(--pxn-space-3); }
.pxd__thumb {
  padding: 0;
  border: 2px solid transparent;
  border-radius: var(--pxn-radius-sm);
  background: var(--pxn-surface-2);
  cursor: pointer;
  aspect-ratio: 1 / 1;
  overflow: hidden;
}
.pxd__thumb.is-active { border-color: var(--pxn-primary); }
.pxd__thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }

.pxd__whgrid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--pxn-space-4); }
.pxd__wh {
  display: flex;
  align-items: center;
  gap: var(--pxn-space-4);
  padding: var(--pxn-space-4) var(--pxn-space-5);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface-2);
}
.pxd__wh-icon {
  flex: none;
  width: 36px;
  height: 36px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-primary-soft);
  color: var(--pxn-primary-ink);
}
.pxd__wh-name { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); font-weight: var(--pxn-fw-medium); text-transform: uppercase; letter-spacing: 0.03em; }
.pxd__wh-qty { font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxd__wh-unit { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); margin-left: var(--pxn-space-2); font-weight: var(--pxn-fw-regular); }

.pxd__batchbadges { display: inline-flex; flex-wrap: wrap; gap: var(--pxn-space-2); }
.pxd__expiry { font-weight: var(--pxn-fw-medium); }
.pxd__expiry.is-expired { color: var(--pxn-danger-ink); }
.pxd__expiry.is-near { color: var(--pxn-warning-ink); }
.pxd__expiry.is-valid { color: var(--pxn-success-ink); }
.pxd__expiry-sub { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); margin-top: 1px; }
</style>
