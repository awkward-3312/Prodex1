<template>
  <div class="px-next pxtrd">
    <!--
      C3.24 — Detalle de traslado + flujo de trabajo px-next (preview dev-only).
      Ruta real /app/transfers/detail/:id (sigue legacy).
      · GET transfers/{id}            → cabecera + líneas (TransferController@show)
      · GET transfer-workflow/{id}    → estados, línea de tiempo y acciones
                                        contextuales (TransferWorkflowController@payload)
      Acciones aprobar / rechazar / despachar se resuelven contra
      /api/transfer-workflow/{id}/{approve|reject|dispatch} y sólo se muestran si
      el backend las autoriza en `actions`. PDF por transfer_pdf/{id}; impresión
      con documento propio. Estados español-first sólo en presentación.
    -->
    <div v-if="!can('transfer_view')" class="pxtrd__denied">
      <px-empty-state icon="lock" title="No tienes permiso para ver traslados"
        description="Pide a un administrador el permiso «transfer_view»." />
    </div>

    <template v-else>
      <div v-if="isLoading" class="pxtrd__pad"><px-skeleton variant="card" :rows="8" /></div>

      <px-alert v-else-if="loadError" tone="danger" title="No se pudo cargar el traslado" class="pxtrd__alert">
        {{ loadError }}
        <template #actions><px-button size="sm" variant="secondary" @click="fetch()">Reintentar</px-button></template>
      </px-alert>

      <template v-else>
        <px-page-header
          title="Detalle de traslado"
          :breadcrumbs="[{ label: 'Inventario' }, { label: 'Traslados' }, { label: transfer.Ref || $route.params.id }]"
        >
          <template #meta>
            <span class="pxn-mono">{{ transfer.Ref || '—' }}</span>
            <span><lucide-icon name="arrow-right" :size="13" /> {{ fromLabel }} → {{ toLabel }}</span>
            <span>{{ transfer.date || '—' }}</span>
          </template>
          <template #actions>
            <px-button variant="ghost" icon="arrow-left" type="button" @click="goBack">Volver</px-button>
            <px-button
              v-if="can('transfer_edit')"
              variant="secondary" icon="pencil" type="button"
              @click="$router.push({ name: 'edit_transfer', params: { id: $route.params.id } })"
            >Editar</px-button>
            <px-button variant="secondary" icon="file-text" type="button" @click="downloadPdf">PDF</px-button>
            <px-button variant="secondary" icon="printer" type="button" @click="printDoc">Imprimir</px-button>
            <px-button
              v-if="can('transfer_delete')"
              variant="danger" icon="trash-2" type="button" @click="confirmOpen = true"
            >Eliminar</px-button>
          </template>
        </px-page-header>

        <!-- ===== Estados + acciones de flujo ===== -->
        <px-card class="pxtrd__flow">
          <div class="pxtrd__flow-states">
            <div class="pxtrd__state">
              <span class="pxtrd__state-k">Estado</span>
              <px-badge :tone="statutTone(transfer.statut)">{{ statutLabel(transfer.statut) }}</px-badge>
            </div>
            <div class="pxtrd__state">
              <span class="pxtrd__state-k">Aprobación</span>
              <px-badge :tone="approvalTone(transfer.approval_status)">{{ approvalLabel(transfer.approval_status) }}</px-badge>
            </div>
            <div class="pxtrd__state">
              <span class="pxtrd__state-k">Logística</span>
              <px-badge :tone="logisticsTone(logisticsStatus)">{{ logisticsLabel(logisticsStatus) }}</px-badge>
            </div>
            <div v-if="workflow.transfer && workflow.transfer.dispatched_at" class="pxtrd__state">
              <span class="pxtrd__state-k">Despachado</span>
              <span class="pxtrd__state-v">{{ fmtDateTime(workflow.transfer.dispatched_at) }}</span>
            </div>
            <div v-if="workflow.transfer && workflow.transfer.received_at" class="pxtrd__state">
              <span class="pxtrd__state-k">Recibido</span>
              <span class="pxtrd__state-v">{{ fmtDateTime(workflow.transfer.received_at) }}</span>
            </div>
          </div>

          <div v-if="workflowError" class="pxtrd__flow-warn">
            <lucide-icon name="info" :size="13" /> No se pudo cargar el flujo de trabajo ({{ workflowError }}).
          </div>

          <div v-if="hasAnyAction" class="pxtrd__flow-actions">
            <px-button
              v-if="actions.can_approve"
              variant="primary" icon="check" size="sm" :loading="acting === 'approve'"
              @click="approveOpen = true"
            >Aprobar</px-button>
            <px-button
              v-if="actions.can_reject"
              variant="danger" icon="x" size="sm" :loading="acting === 'reject'"
              @click="rejectOpen = true"
            >Rechazar</px-button>
            <px-button
              v-if="actions.can_dispatch"
              variant="secondary" icon="truck" size="sm" :loading="acting === 'dispatch'"
              @click="dispatchOpen = true"
            >Despachar</px-button>
          </div>
          <p v-else-if="!workflowError" class="pxtrd__flow-none">
            <lucide-icon name="info" :size="13" /> No hay acciones de flujo disponibles para tu usuario en el estado actual.
          </p>
        </px-card>

        <div class="pxtrd__kpis">
          <px-card class="pxtrd__kpi"><div class="pxtrd__kpi-label">Líneas</div><div class="pxtrd__kpi-val pxn-num">{{ details.length }}</div></px-card>
          <px-card class="pxtrd__kpi"><div class="pxtrd__kpi-label">Productos</div><div class="pxtrd__kpi-val pxn-num">{{ fmtQty(transfer.items) }}</div></px-card>
          <px-card class="pxtrd__kpi"><div class="pxtrd__kpi-label">Total</div><div class="pxtrd__kpi-val pxn-num">{{ money(transfer.GrandTotal) }}</div></px-card>
        </div>

        <px-card v-if="transfer.note" title="Nota" class="pxtrd__info">
          <p class="pxtrd__note">{{ transfer.note }}</p>
        </px-card>

        <px-card title="Productos" class="pxtrd__products">
          <div v-if="!details.length" class="pxtrd__empty">Sin líneas.</div>
          <div v-else class="pxtrd-tbl__wrap pxn-scroll">
            <table class="pxtrd-tbl">
              <thead>
                <tr>
                  <th>Código</th>
                  <th>Producto</th>
                  <th class="is-right">Cantidad</th>
                  <th class="is-right">Total</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="(detail, idx) in details">
                  <tr :key="'r-' + idx">
                    <td class="pxn-mono">{{ detail.code }}</td>
                    <td>
                      <span class="pxtrd-tbl__name">{{ detail.name }}</span>
                      <px-badge v-if="detail.is_batch_tracked" tone="info" icon="package">Lote</px-badge>
                    </td>
                    <td class="is-right pxn-num is-strong">{{ fmtQty(detail.quantity) }} {{ detail.unit }}</td>
                    <td class="is-right pxn-num">{{ money(detail.total) }}</td>
                  </tr>
                  <tr v-if="detail.is_batch_tracked && (detail.batches || []).length" :key="'b-' + idx" class="pxtrd-tbl__batchrow">
                    <td colspan="4">
                      <div class="pxtrd-bat">
                        <div class="pxtrd-bat__head"><lucide-icon name="package" :size="13" /> Lotes · {{ detail.batches.length }} línea(s)</div>
                        <table class="pxtrd-bat__tbl">
                          <thead>
                            <tr>
                              <th>Nº de lote</th>
                              <th>Fabricación</th>
                              <th>Caducidad</th>
                              <th class="is-right">Cantidad</th>
                              <th class="is-right">Coste unit.</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr v-for="(b, bIdx) in detail.batches" :key="'ab-' + idx + '-' + bIdx">
                              <td class="pxn-mono">{{ b.batch_no || '—' }}</td>
                              <td>{{ b.mfg_date || '—' }}</td>
                              <td>
                                <span v-if="b.expiry_date" class="pxtrd-pill" :class="expiryClass(b.expiry_date)">{{ b.expiry_date }}</span>
                                <span v-else class="pxn-muted">—</span>
                              </td>
                              <td class="is-right pxn-num">{{ fmtQty(b.qty) }} {{ detail.unit }}</td>
                              <td class="is-right pxn-num">{{ b.unit_cost != null ? money(b.unit_cost) : '—' }}</td>
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

        <px-card v-if="events.length" title="Línea de tiempo" class="pxtrd__timeline">
          <ol class="pxtrd-tl">
            <li v-for="ev in events" :key="ev.id" class="pxtrd-tl__item">
              <span class="pxtrd-tl__dot" :class="eventDotClass(ev.event_type)"></span>
              <div class="pxtrd-tl__body">
                <div class="pxtrd-tl__row">
                  <strong>{{ eventLabel(ev.event_type) }}</strong>
                  <span class="pxtrd-tl__when">{{ fmtDateTime(ev.created_at) }}</span>
                </div>
                <div class="pxtrd-tl__meta">
                  <span>{{ ev.actor_name || 'Sistema' }}</span>
                  <span v-if="ev.payload && ev.payload.reason" class="pxtrd-tl__reason">· {{ ev.payload.reason }}</span>
                </div>
              </div>
            </li>
          </ol>
        </px-card>
      </template>
    </template>

    <!-- ===== Modales de flujo ===== -->
    <px-modal v-model="approveOpen" title="Aprobar traslado" size="sm">
      <p class="pxtrd__confirm">¿Aprobar el traslado <strong>{{ transfer.Ref }}</strong>?</p>
      <template #footer="{ close }">
        <span class="pxtrd__grow" />
        <px-button variant="secondary" :disabled="acting === 'approve'" @click="close">Cancelar</px-button>
        <px-button variant="primary" icon="check" :loading="acting === 'approve'" @click="doApprove">Aprobar</px-button>
      </template>
    </px-modal>

    <px-modal v-model="rejectOpen" title="Rechazar traslado" size="sm">
      <p class="pxtrd__confirm">¿Rechazar el traslado <strong>{{ transfer.Ref }}</strong>?</p>
      <px-field label="Motivo (opcional)">
        <template #default="{ id }">
          <px-textarea :id="id" v-model="rejectReason" :rows="3" placeholder="Motivo del rechazo…" />
        </template>
      </px-field>
      <template #footer="{ close }">
        <span class="pxtrd__grow" />
        <px-button variant="secondary" :disabled="acting === 'reject'" @click="close">Cancelar</px-button>
        <px-button variant="danger" icon="x" :loading="acting === 'reject'" @click="doReject">Rechazar</px-button>
      </template>
    </px-modal>

    <px-modal v-model="dispatchOpen" title="Despachar traslado" size="sm">
      <p class="pxtrd__confirm">
        ¿Despachar el traslado <strong>{{ transfer.Ref }}</strong>? Esto descuenta el stock del origen y lo pone en tránsito hacia el destino.
      </p>
      <template #footer="{ close }">
        <span class="pxtrd__grow" />
        <px-button variant="secondary" :disabled="acting === 'dispatch'" @click="close">Cancelar</px-button>
        <px-button variant="primary" icon="truck" :loading="acting === 'dispatch'" @click="doDispatch">Despachar</px-button>
      </template>
    </px-modal>

    <px-modal v-model="confirmOpen" title="Eliminar traslado" size="sm">
      <p class="pxtrd__confirm">
        ¿Eliminar el traslado <strong>{{ transfer.Ref }}</strong>? Si ya movió stock, el backend revertirá los movimientos asociados.
      </p>
      <template #footer="{ close }">
        <span class="pxtrd__grow" />
        <px-button variant="secondary" :disabled="deleting" @click="close">Cancelar</px-button>
        <px-button variant="danger" icon="trash-2" :loading="deleting" @click="doDelete">Eliminar</px-button>
      </template>
    </px-modal>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import { getPriceDecimals, getPriceFormatSetting, formatPriceDisplay } from "@/utils/priceFormat";
import {
  statutLabel, approvalLabel, logisticsLabel, eventLabel,
  statutTone, approvalTone, logisticsTone
} from "./statusMaps.js";

export default {
  name: "TransferDetailNext",
  metaInfo: { title: "Detalle de traslado" },
  components: { PxPageHeader, PxCard, PxButton, PxBadge, PxAlert, PxEmptyState, PxModal, PxField, PxTextarea },
  data() {
    return {
      isLoading: true,
      loadError: null,
      transfer: {},
      details: [],
      workflow: {},
      workflowError: null,
      acting: null,
      approveOpen: false,
      rejectOpen: false,
      rejectReason: "",
      dispatchOpen: false,
      confirmOpen: false,
      deleting: false
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    actions() {
      return (this.workflow && this.workflow.actions) || {};
    },
    events() {
      return (this.workflow && Array.isArray(this.workflow.events)) ? this.workflow.events : [];
    },
    logisticsStatus() {
      return (this.workflow.transfer && this.workflow.transfer.logistics_status) || this.transfer.logistics_status || "pending";
    },
    // El endpoint `transfers/{id}` devuelve el nombre del almacén legacy; el
    // payload de workflow trae la etiqueta real de la ubicación. Preferimos ésta.
    fromLabel() {
      return (this.workflow.transfer && this.workflow.transfer.from) || this.transfer.from_warehouse || "—";
    },
    toLabel() {
      return (this.workflow.transfer && this.workflow.transfer.to) || this.transfer.to_warehouse || "—";
    },
    hasAnyAction() {
      const a = this.actions;
      return !!(a.can_approve || a.can_reject || a.can_dispatch);
    }
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
    statutLabel, approvalLabel, logisticsLabel, eventLabel, statutTone, approvalTone, logisticsTone,
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
    fmtDateTime(v) {
      if (!v) return "—";
      const d = new Date(v);
      if (isNaN(d.getTime())) return String(v);
      return d.toLocaleString();
    },
    goBack() { this.$router.push({ name: "index_transfer" }); },
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
    eventDotClass(type) {
      const t = String(type || "").toLowerCase();
      if (t.includes("reject")) return "is-danger";
      if (t.includes("discrepancy")) return "is-warn";
      if (t.includes("approved") || t.includes("received") || t.includes("dispatch")) return "is-ok";
      return "is-neutral";
    },
    errMsg(err) {
      return (
        (err && err.response && err.response.data && (err.response.data.message || err.response.data.error)) ||
        (err && err.errors && Object.values(err.errors)[0] && Object.values(err.errors)[0][0]) ||
        (err && err.message) || "Error de red."
      );
    },
    fetch() {
      const id = this.$route.params.id;
      if (!id) return;
      this.loadError = null;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get(`transfers/${id}`)
        .then(response => {
          const data = response.data || {};
          this.transfer = data.transfer || {};
          this.details = data.details || [];
          NProgress.done();
          this.isLoading = false;
          this.loadWorkflow();
        })
        .catch(err => {
          NProgress.done();
          this.loadError = this.errMsg(err);
          setTimeout(() => { this.isLoading = false; }, 300);
        });
    },
    loadWorkflow() {
      const id = this.$route.params.id;
      if (!id) return;
      this.workflowError = null;
      window.axios
        .get(`transfer-workflow/${id}`, { meta: { skipErrorRedirect: true } })
        .then(response => { this.workflow = response.data || {}; })
        .catch(err => { this.workflow = {}; this.workflowError = this.errMsg(err); });
    },
    runAction(kind, url, body) {
      this.acting = kind;
      NProgress.start(); NProgress.set(0.1);
      return window.axios
        .post(url, body || {})
        .then(response => {
          this.acting = null;
          NProgress.done();
          if (response && response.data) this.workflow = response.data;
          this.approveOpen = this.rejectOpen = this.dispatchOpen = false;
          this.rejectReason = "";
          this.makeToast("success", "Acción aplicada.", "Éxito");
          this.fetch();
        })
        .catch(err => {
          this.acting = null;
          NProgress.done();
          this.makeToast("danger", this.errMsg(err), "Error");
        });
    },
    doApprove() {
      this.runAction("approve", `transfer-workflow/${this.$route.params.id}/approve`, {});
    },
    doReject() {
      this.runAction("reject", `transfer-workflow/${this.$route.params.id}/reject`, { reason: this.rejectReason || null });
    },
    doDispatch() {
      this.runAction("dispatch", `transfer-workflow/${this.$route.params.id}/dispatch`, {});
    },
    doDelete() {
      this.deleting = true;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .delete(`transfers/${this.$route.params.id}`)
        .then(() => {
          this.deleting = false;
          this.confirmOpen = false;
          NProgress.done();
          this.makeToast("success", "Traslado eliminado.", "Éxito");
          this.$router.push({ name: "index_transfer" });
        })
        .catch(err => {
          this.deleting = false;
          NProgress.done();
          this.makeToast("danger", this.errMsg(err), "Error");
        });
    },
    downloadPdf() {
      const id = this.$route.params.id;
      if (!id) return;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .get(`transfer_pdf/${id}`, { responseType: "blob", headers: { "Content-Type": "application/json" } })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute("download", `Transfer_${this.transfer.Ref || id}.pdf`);
          document.body.appendChild(link);
          link.click();
          document.body.removeChild(link);
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(() => { setTimeout(() => NProgress.done(), 500); this.makeToast("danger", "No se pudo descargar el PDF.", "Error"); });
    },
    printDoc() {
      const t = this.transfer || {};
      const items = this.details || [];
      const esc = v => String(v == null ? "" : v).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
      const rows = items
        .map(d =>
          `<tr>
            <td>${esc(d.code)}</td>
            <td>${esc(d.name)}</td>
            <td style="text-align:right">${esc(this.fmtQty(d.quantity))} ${esc(d.unit)}</td>
            <td style="text-align:right">${esc(this.money(d.total))}</td>
          </tr>`
        )
        .join("");
      const html = `<!DOCTYPE html><html lang="es"><head><meta charset="utf-8" />
<title>Traslado ${esc(t.Ref || "")}</title>
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
  <h1>Traslado — ${esc(t.Ref || "")}</h1>
  <div class="meta">
    <span><strong>Fecha:</strong> ${esc(t.date || "")}</span>
    <span><strong>Origen:</strong> ${esc(t.from_warehouse || "")}</span>
    <span><strong>Destino:</strong> ${esc(t.to_warehouse || "")}</span>
    <span><strong>Estado:</strong> ${esc(this.statutLabel(t.statut))}</span>
    <span><strong>Aprobación:</strong> ${esc(this.approvalLabel(t.approval_status))}</span>
  </div>
  ${t.note ? `<div class="meta"><strong>Nota:</strong> ${esc(t.note)}</div>` : ""}
  <table>
    <thead><tr><th>Código</th><th>Producto</th><th style="text-align:right">Cantidad</th><th style="text-align:right">Total</th></tr></thead>
    <tbody>${rows || `<tr><td colspan="4">Sin líneas</td></tr>`}</tbody>
  </table>
  <div class="foot">Impreso ${esc(new Date().toLocaleString())} · PRODEX</div>
</body></html>`;
      const w = window.open("", "_blank", "width=920,height=780,scrollbars=yes");
      if (!w) { this.makeToast("warning", "Permite las ventanas emergentes para imprimir.", "Aviso"); return; }
      w.document.open();
      w.document.write(html);
      w.document.close();
      w.focus();
      setTimeout(() => { try { w.print(); w.close(); } catch (e) { /* noop */ } }, 400);
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxtrd { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxtrd { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxtrd__denied { padding: var(--pxn-space-12) 0; }
.pxtrd__pad { padding: var(--pxn-space-6) 0; }
.pxtrd__alert { margin-top: var(--pxn-space-5); }

.pxtrd__flow { margin-top: var(--pxn-space-6); }
.pxtrd__flow :deep(.pxn-card__body) { padding: var(--pxn-space-5); }
.pxtrd__flow-states { display: flex; flex-wrap: wrap; gap: var(--pxn-space-6); }
.pxtrd__state { display: flex; flex-direction: column; gap: var(--pxn-space-2); }
.pxtrd__state-k { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); text-transform: uppercase; letter-spacing: 0.04em; }
.pxtrd__state-v { font-size: var(--pxn-fs-sm); color: var(--pxn-ink); }
.pxtrd__flow-actions { display: flex; flex-wrap: wrap; gap: var(--pxn-space-3); margin-top: var(--pxn-space-5); padding-top: var(--pxn-space-5); border-top: 1px solid var(--pxn-border); }
.pxtrd__flow-none, .pxtrd__flow-warn { display: flex; align-items: center; gap: var(--pxn-space-2); margin: var(--pxn-space-5) 0 0; padding-top: var(--pxn-space-4); border-top: 1px solid var(--pxn-border); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxtrd__flow-warn { color: var(--pxn-warning-ink); }

.pxtrd__kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-4); margin-top: var(--pxn-space-5); }
@media (max-width: 620px) { .pxtrd__kpis { grid-template-columns: minmax(0, 1fr); } }
.pxtrd__kpi :deep(.pxn-card__body) { padding: var(--pxn-space-5); text-align: center; }
.pxtrd__kpi-label { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); text-transform: uppercase; letter-spacing: 0.04em; }
.pxtrd__kpi-val { font-size: var(--pxn-fs-h1); font-weight: var(--pxn-fw-bold); color: var(--pxn-ink); margin-top: var(--pxn-space-2); }

.pxtrd__info, .pxtrd__products, .pxtrd__timeline { margin-top: var(--pxn-space-5); }
.pxtrd__note { margin: 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.pxtrd__empty { padding: var(--pxn-space-6); text-align: center; color: var(--pxn-ink-3); font-size: var(--pxn-fs-sm); }

.pxtrd-tbl__wrap { overflow-x: auto; }
.pxtrd-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-body); }
.pxtrd-tbl th {
  text-align: left; padding: var(--pxn-space-4);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
  text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3);
  background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap;
}
.pxtrd-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); vertical-align: middle; }
.pxtrd-tbl tr:last-child td { border-bottom: 0; }
.pxtrd-tbl .is-right { text-align: right; }
.pxtrd-tbl .is-strong { font-weight: var(--pxn-fw-semibold); }
.pxtrd-tbl__name { font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); margin-right: var(--pxn-space-2); }
.pxtrd-tbl__batchrow td { padding: 0 var(--pxn-space-4) var(--pxn-space-3); background: var(--pxn-bg); }

.pxtrd-bat { border: 1px solid var(--pxn-primary-border); border-radius: var(--pxn-radius-md); overflow: hidden; background: var(--pxn-primary-soft); }
.pxtrd-bat__head { display: flex; align-items: center; gap: var(--pxn-space-2); padding: var(--pxn-space-3) var(--pxn-space-4); background: var(--pxn-primary); color: #fff; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; }
.pxtrd-bat__tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); background: var(--pxn-surface); }
.pxtrd-bat__tbl th { text-align: left; padding: var(--pxn-space-3) var(--pxn-space-4); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-primary-ink); background: var(--pxn-primary-soft); border-bottom: 1px solid var(--pxn-primary-border); }
.pxtrd-bat__tbl td { padding: var(--pxn-space-2) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); }
.pxtrd-bat__tbl tr:last-child td { border-bottom: 0; }
.pxtrd-bat__tbl .is-right { text-align: right; }
.pxtrd-pill { display: inline-block; padding: 2px 8px; border-radius: var(--pxn-radius-pill); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); }
.pxtrd-pill.is-expired { background: var(--pxn-danger-soft); color: var(--pxn-danger-ink); }
.pxtrd-pill.is-soon { background: var(--pxn-warning-soft); color: var(--pxn-warning-ink); }
.pxtrd-pill.is-ok { background: var(--pxn-success-soft); color: var(--pxn-success-ink); }
.pxtrd-pill.is-none { background: var(--pxn-surface-3); color: var(--pxn-ink-3); }

.pxtrd-tl { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--pxn-space-4); }
.pxtrd-tl__item { display: flex; gap: var(--pxn-space-4); }
.pxtrd-tl__dot { flex: none; width: 10px; height: 10px; margin-top: 4px; border-radius: 50%; background: var(--pxn-neutral-border); }
.pxtrd-tl__dot.is-ok { background: var(--pxn-success-ink); }
.pxtrd-tl__dot.is-danger { background: var(--pxn-danger-ink); }
.pxtrd-tl__dot.is-warn { background: var(--pxn-warning-ink); }
.pxtrd-tl__body { flex: 1; min-width: 0; }
.pxtrd-tl__row { display: flex; justify-content: space-between; gap: var(--pxn-space-4); font-size: var(--pxn-fs-sm); color: var(--pxn-ink); }
.pxtrd-tl__when { color: var(--pxn-ink-3); font-size: var(--pxn-fs-xs); white-space: nowrap; }
.pxtrd-tl__meta { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); margin-top: 2px; }
.pxtrd-tl__reason { color: var(--pxn-ink-2); }

.pxtrd__confirm { margin: 0 0 var(--pxn-space-4); font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.pxtrd__grow { flex: 1; }
</style>
