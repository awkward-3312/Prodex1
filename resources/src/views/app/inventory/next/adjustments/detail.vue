<template>
  <div class="px-next pxadjd">
    <!--
      C3.1 — Detalle de ajuste px-next (solo lectura). Ruta real:
      /app/adjustments/detail/:id (sigue legacy). Endpoint GET adjustments/detail/{id};
      PDF vía adjustment_pdf/{id}; impresión con documento propio.
    -->
    <div v-if="!can('adjustment_view')" class="pxadjd__denied">
      <px-empty-state icon="lock" title="No tienes permiso para ver ajustes"
        description="Pide a un administrador el permiso «adjustment_view»." />
    </div>

    <template v-else>
      <div v-if="isLoading" class="pxadjd__pad"><px-skeleton variant="card" :rows="6" /></div>

      <px-alert v-else-if="loadError" tone="danger" title="No se pudo cargar el ajuste" class="pxadjd__alert">
        {{ loadError }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <template v-else>
        <px-page-header
          title="Detalle de ajuste"
          :breadcrumbs="[{ label: 'Inventario' }, { label: 'Ajustes' }, { label: adjustment.Ref || $route.params.id }]"
        >
          <template #meta>
            <span class="pxn-mono">{{ adjustment.Ref || '—' }}</span>
            <span><lucide-icon name="store" :size="13" /> {{ adjustment.warehouse || '—' }}</span>
            <span>{{ adjustment.date || '—' }}</span>
          </template>
          <template #actions>
            <px-button variant="ghost" icon="arrow-left" type="button" @click="goBack">Volver</px-button>
            <px-button
              v-if="can('adjustment_edit')"
              variant="secondary" icon="pencil" type="button"
              @click="$router.push({ name: 'edit_adjustment', params: { id: $route.params.id } })"
            >Editar</px-button>
            <px-button variant="secondary" icon="file-text" type="button" @click="downloadPdf">PDF</px-button>
            <px-button variant="secondary" icon="printer" type="button" @click="printDoc">Imprimir</px-button>
          </template>
        </px-page-header>

        <div class="pxadjd__kpis">
          <px-card class="pxadjd__kpi"><div class="pxadjd__kpi-label">Líneas</div><div class="pxadjd__kpi-val pxn-num">{{ details.length }}</div></px-card>
          <px-card class="pxadjd__kpi is-pos"><div class="pxadjd__kpi-label">Adiciones</div><div class="pxadjd__kpi-val pxn-num">+ {{ totalAdd }}</div></px-card>
          <px-card class="pxadjd__kpi is-neg"><div class="pxadjd__kpi-label">Sustracciones</div><div class="pxadjd__kpi-val pxn-num">- {{ totalSub }}</div></px-card>
          <px-card class="pxadjd__kpi"><div class="pxadjd__kpi-label">Cambio neto</div><div class="pxadjd__kpi-val pxn-num" :class="netNum >= 0 ? 'is-pos-txt' : 'is-neg-txt'">{{ netNum >= 0 ? '+' : '' }}{{ net }}</div></px-card>
        </div>

        <px-card v-if="adjustment.created_by || adjustment.note" title="Información" class="pxadjd__info">
          <dl class="pxadjd__dl">
            <div v-if="adjustment.created_by"><dt>Creado por</dt><dd>{{ adjustment.created_by }}</dd></div>
            <div v-if="adjustment.note"><dt>Nota</dt><dd>{{ adjustment.note }}</dd></div>
          </dl>
        </px-card>

        <px-card title="Productos" class="pxadjd__products">
          <div v-if="!details.length" class="pxadjd__empty">Sin líneas.</div>
          <div v-else class="pxadjd-tbl__wrap pxn-scroll">
            <table class="pxadjd-tbl">
              <thead>
                <tr>
                  <th>Código</th>
                  <th>Producto</th>
                  <th class="is-right">Cantidad</th>
                  <th class="is-center">Tipo</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="(detail, idx) in details">
                  <tr :key="'r-' + idx">
                    <td class="pxn-mono">{{ detail.code }}</td>
                    <td>
                      <span class="pxadjd-tbl__name">{{ detail.name }}</span>
                      <px-badge v-if="detail.is_batch_tracked" tone="info" icon="package">Lote</px-badge>
                    </td>
                    <td class="is-right pxn-num is-strong">{{ fmt(detail.quantity) }} {{ detail.unit }}</td>
                    <td class="is-center">
                      <px-badge :tone="detail.type === 'add' ? 'success' : 'danger'" :icon="detail.type === 'add' ? 'plus' : 'minus'">
                        {{ detail.type === 'add' ? 'Adición' : 'Sustracción' }}
                      </px-badge>
                    </td>
                  </tr>
                  <tr v-if="detail.is_batch_tracked && (detail.batches || []).length" :key="'b-' + idx" class="pxadjd-tbl__batchrow">
                    <td colspan="4">
                      <div class="pxadjd-bat">
                        <div class="pxadjd-bat__head"><lucide-icon name="package" :size="13" /> Lotes · {{ detail.batches.length }} línea(s)</div>
                        <table class="pxadjd-bat__tbl">
                          <thead>
                            <tr>
                              <th>Nº de lote</th>
                              <th>Fabricación</th>
                              <th>Caducidad</th>
                              <th class="is-center">Dirección</th>
                              <th class="is-right">Cantidad</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr v-for="(b, bIdx) in detail.batches" :key="'ab-' + idx + '-' + bIdx">
                              <td class="pxn-mono">{{ b.batch_no || '—' }}</td>
                              <td>{{ b.mfg_date || '—' }}</td>
                              <td>
                                <span v-if="b.expiry_date" class="pxadjd-pill" :class="expiryClass(b.expiry_date)">{{ b.expiry_date }}</span>
                                <span v-else class="pxn-muted">—</span>
                              </td>
                              <td class="is-center">
                                <px-badge :tone="b.direction === 'in' ? 'success' : 'danger'">{{ b.direction === 'in' ? 'Entrada' : 'Salida' }}</px-badge>
                              </td>
                              <td class="is-right pxn-num">{{ fmt(b.qty) }} {{ detail.unit }}</td>
                            </tr>
                          </tbody>
                        </table>
                      </div>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </px-card>
      </template>
    </template>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import { getPriceDecimals } from "@/utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";

export default {
  name: "AdjustmentDetailNext",
  metaInfo: { title: "Detalle de ajuste" },
  components: { PxPageHeader, PxCard, PxButton, PxBadge, PxAlert, PxEmptyState },
  data() {
    return { isLoading: true, loadError: null, adjustment: {}, details: [] };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    totalAddNum() {
      return (this.details || []).filter(d => d.type === "add").reduce((s, d) => s + (Number(d.quantity) || 0), 0);
    },
    totalSubNum() {
      return (this.details || []).filter(d => d.type === "sub").reduce((s, d) => s + (Number(d.quantity) || 0), 0);
    },
    netNum() {
      return this.totalAddNum - this.totalSubNum;
    },
    totalAdd() { return this.fmt(this.totalAddNum); },
    totalSub() { return this.fmt(this.totalSubNum); },
    net() { return this.fmt(this.netNum); }
  },
  created() {
    this.fetch();
  },
  watch: {
    "$route.params.id"() {
      this.isLoading = true;
      this.fetch();
    }
  },
  methods: {
    can(p) {
      const list = Array.isArray(this.currentUserPermissions) ? this.currentUserPermissions : [];
      return list.includes(p);
    },
    fmt(n) {
      const v = Number(n);
      if (!Number.isFinite(v)) return "0";
      return v.toFixed(this.priceDecimals);
    },
    goBack() {
      this.$router.push({ name: "index_adjustment" });
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
    },
    fetch() {
      const id = this.$route.params.id;
      if (!id) return;
      this.loadError = null;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get(`adjustments/detail/${id}`)
        .then(response => {
          const data = response.data || {};
          this.adjustment = data.adjustment || {};
          this.details = data.details || [];
          NProgress.done();
          this.isLoading = false;
        })
        .catch(err => {
          NProgress.done();
          this.loadError =
            (err && err.response && err.response.data && (err.response.data.message || err.response.data.error)) ||
            (err && err.message) || "Error de red.";
          setTimeout(() => { this.isLoading = false; }, 300);
        });
    },
    downloadPdf() {
      const id = this.$route.params.id;
      if (!id) return;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get(`adjustment_pdf/${id}`, { responseType: "blob", headers: { "Content-Type": "application/json" } })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute("download", `Adjustment_${this.adjustment.Ref || id}.pdf`);
          document.body.appendChild(link);
          link.click();
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(() => setTimeout(() => NProgress.done(), 500));
    },
    printDoc() {
      const a = this.adjustment || {};
      const items = this.details || [];
      const esc = v => String(v == null ? "" : v).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
      const rows = items
        .map(d =>
          `<tr>
            <td>${esc(d.code)}</td>
            <td>${esc(d.name)}</td>
            <td style="text-align:right">${esc(this.fmt(d.quantity))} ${esc(d.unit)}</td>
            <td>${d.type === "add" ? "Adición" : "Sustracción"}</td>
          </tr>`
        )
        .join("");
      const html = `<!DOCTYPE html><html lang="es"><head><meta charset="utf-8" />
<title>Ajuste ${esc(a.Ref || "")}</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;padding:24px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#101828;font-size:12px;line-height:1.5;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  h1{font-size:18px;margin:0 0 4px}
  .meta{color:#475467;font-size:11px;margin-bottom:16px}
  .meta span{margin-right:16px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:7px 8px;border-bottom:1px solid #e4e7ec;text-align:left}
  th{background:#f2f4f7;font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#475467}
  .foot{margin-top:24px;padding-top:8px;border-top:1px solid #d0d5dd;color:#98a2b3;font-size:10px}
  @page{margin:12mm}
</style></head><body>
  <h1>Ajuste de inventario — ${esc(a.Ref || "")}</h1>
  <div class="meta">
    <span><strong>Fecha:</strong> ${esc(a.date || "")}</span>
    <span><strong>Almacén:</strong> ${esc(a.warehouse || "")}</span>
    ${a.created_by ? `<span><strong>Creado por:</strong> ${esc(a.created_by)}</span>` : ""}
  </div>
  ${a.note ? `<div class="meta"><strong>Nota:</strong> ${esc(a.note)}</div>` : ""}
  <table>
    <thead><tr><th>Código</th><th>Producto</th><th style="text-align:right">Cantidad</th><th>Tipo</th></tr></thead>
    <tbody>${rows || `<tr><td colspan="4">Sin líneas</td></tr>`}</tbody>
  </table>
  <div class="foot">Impreso ${esc(new Date().toLocaleString())} · PRODEX</div>
</body></html>`;
      const w = window.open("", "_blank", "width=920,height=780,scrollbars=yes");
      if (!w) { this.$root.$bvToast.toast("Permite las ventanas emergentes para imprimir.", { title: "Aviso", variant: "warning", solid: true }); return; }
      w.document.open();
      w.document.write(html);
      w.document.close();
      w.focus();
      setTimeout(() => { try { w.print(); w.close(); } catch (e) { /* noop */ } }, 400);
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxadjd { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxadjd { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxadjd__denied { padding: var(--pxn-space-12) 0; }
.pxadjd__pad { padding: var(--pxn-space-6) 0; }
.pxadjd__alert { margin-top: var(--pxn-space-5); }

.pxadjd__kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-4); margin-top: var(--pxn-space-6); }
@media (max-width: 720px) { .pxadjd__kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.pxadjd__kpi :deep(.pxn-card__body) { padding: var(--pxn-space-5); text-align: center; }
.pxadjd__kpi-label { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); text-transform: uppercase; letter-spacing: 0.04em; }
.pxadjd__kpi-val { font-size: var(--pxn-fs-h1); font-weight: var(--pxn-fw-bold); color: var(--pxn-ink); margin-top: var(--pxn-space-2); }
.pxadjd__kpi.is-pos { border-color: var(--pxn-success-border); }
.pxadjd__kpi.is-neg { border-color: var(--pxn-danger-border); }
.is-pos-txt { color: var(--pxn-success-ink); }
.is-neg-txt { color: var(--pxn-danger-ink); }

.pxadjd__info, .pxadjd__products { margin-top: var(--pxn-space-5); }
.pxadjd__dl { display: flex; flex-direction: column; gap: var(--pxn-space-3); }
.pxadjd__dl > div { display: flex; gap: var(--pxn-space-4); }
.pxadjd__dl dt { flex: none; width: 120px; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxadjd__dl dd { margin: 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink); }
.pxadjd__empty { padding: var(--pxn-space-6); text-align: center; color: var(--pxn-ink-3); font-size: var(--pxn-fs-sm); }

.pxadjd-tbl__wrap { overflow-x: auto; }
.pxadjd-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-body); }
.pxadjd-tbl th {
  text-align: left; padding: var(--pxn-space-4);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
  text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3);
  background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap;
}
.pxadjd-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); vertical-align: middle; }
.pxadjd-tbl tr:last-child td { border-bottom: 0; }
.pxadjd-tbl .is-right { text-align: right; }
.pxadjd-tbl .is-center { text-align: center; }
.pxadjd-tbl .is-strong { font-weight: var(--pxn-fw-semibold); }
.pxadjd-tbl__name { font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); margin-right: var(--pxn-space-2); }
.pxadjd-tbl__batchrow td { padding: 0 var(--pxn-space-4) var(--pxn-space-3); background: var(--pxn-bg); }

.pxadjd-bat { border: 1px solid var(--pxn-primary-border); border-radius: var(--pxn-radius-md); overflow: hidden; background: var(--pxn-primary-soft); }
.pxadjd-bat__head { display: flex; align-items: center; gap: var(--pxn-space-2); padding: var(--pxn-space-3) var(--pxn-space-4); background: var(--pxn-primary); color: #fff; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; }
.pxadjd-bat__tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); background: var(--pxn-surface); }
.pxadjd-bat__tbl th { text-align: left; padding: var(--pxn-space-3) var(--pxn-space-4); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-primary-ink); background: var(--pxn-primary-soft); border-bottom: 1px solid var(--pxn-primary-border); }
.pxadjd-bat__tbl td { padding: var(--pxn-space-2) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); }
.pxadjd-bat__tbl tr:last-child td { border-bottom: 0; }
.pxadjd-bat__tbl .is-right { text-align: right; }
.pxadjd-bat__tbl .is-center { text-align: center; }
.pxadjd-pill { display: inline-block; padding: 2px 8px; border-radius: var(--pxn-radius-pill); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); }
.pxadjd-pill.is-expired { background: var(--pxn-danger-soft); color: var(--pxn-danger-ink); }
.pxadjd-pill.is-soon { background: var(--pxn-warning-soft); color: var(--pxn-warning-ink); }
.pxadjd-pill.is-ok { background: var(--pxn-success-soft); color: var(--pxn-success-ink); }
.pxadjd-pill.is-none { background: var(--pxn-surface-3); color: var(--pxn-ink-3); }
</style>
