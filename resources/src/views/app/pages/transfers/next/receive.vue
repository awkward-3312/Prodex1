<template>
  <div class="px-next pxtrc">
    <!--
      C3.26 — Recepción de traslados + incidencias px-next (preview dev-only).

      Internaliza en Vue px-next lo que hoy inyectan en vanilla-JS
      prodex-transfer-logistics.js (panel de entrantes + modal de recepción) y
      prodex-transfer-issues.js (incidencias + resolución), SIN tocar esos
      ficheros ni volver a interceptar endpoints compartidos. Cada petición usa
      su endpoint real:
        · GET  transfer-logistics/incoming                  → traslados en tránsito recibibles
        · GET  transfer-logistics/issues                    → incidencias (defectuoso / faltante)
        · POST transfer-logistics/issues/{id}/resolve       → resolución (requiere transfer_issue_manage)
        · GET  transfer-logistics/{id}                       → detalle de recepción de un traslado
        · POST transfer-logistics/{id}/receive              → registrar recepción (bueno/defectuoso/faltante)

      Idempotencia: el POST /receive lleva `request_token` (misma clave y formato
      que prodex-transfer-idempotency.js: sessionStorage 'prodex.transfer.receipt.request.<id>'
      = 'RCV-<uuid>'). Se limpia en éxito o 4xx; se conserva en 5xx / error de red
      para que reintentar no duplique el movimiento. Funciona con o sin el
      interceptor global instalado (éste no pisa un token ya presente).
    -->
    <div v-if="!canReceiveOrView" class="pxtrc__denied">
      <px-empty-state icon="lock" title="No tienes permiso para recibir traslados"
        description="Pide a un administrador el permiso «transfer_receive»." />
    </div>

    <template v-else>
      <!-- ===================== MODO: un traslado ===================== -->
      <template v-if="transferId">
        <div v-if="loadingOne" class="pxtrc__pad"><px-skeleton variant="card" :rows="8" /></div>

        <px-alert v-else-if="oneError" tone="danger" title="No se pudo cargar la recepción" class="pxtrc__alert">
          {{ oneError }}
          <template #actions><px-button size="sm" variant="secondary" @click="loadOne()">Reintentar</px-button></template>
        </px-alert>

        <template v-else>
          <px-page-header
            title="Recibir traslado"
            :breadcrumbs="[{ label: 'Inventario' }, { label: 'Traslados' }, { label: 'Recepción' }, { label: one.transfer && one.transfer.reference }]"
          >
            <template #meta>
              <span class="pxn-mono">{{ one.transfer && one.transfer.reference }}</span>
              <span><lucide-icon name="arrow-right" :size="13" /> {{ oneFrom }} → {{ oneTo }}</span>
              <px-badge :tone="logisticsTone(one.transfer && one.transfer.logistics_status)">
                {{ logisticsLabel(one.transfer && one.transfer.logistics_status) }}
              </px-badge>
            </template>
            <template #actions>
              <px-button variant="ghost" icon="arrow-left" type="button" @click="backToInbox">Volver</px-button>
            </template>
          </px-page-header>

          <px-alert v-if="!one.can_receive" tone="info" class="pxtrc__alert">
            Esta transferencia no está en un estado que puedas recibir ahora mismo
            (estado logístico: {{ logisticsLabel(one.transfer && one.transfer.logistics_status) }}).
          </px-alert>

          <px-card title="Líneas a recibir" class="pxtrc__sec">
            <div class="pxtrc-tbl__wrap pxn-scroll">
              <table class="pxtrc-tbl">
                <thead>
                  <tr>
                    <th>Producto</th>
                    <th class="is-right">Enviado</th>
                    <th class="is-right">Pendiente</th>
                    <th class="is-right pxtrc-th--good">Correcto</th>
                    <th class="is-right pxtrc-th--defective">Defectuoso</th>
                    <th class="is-right pxtrc-th--missing">Faltante</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in receiveRows" :key="row.transfer_detail_id">
                    <td>
                      <span class="pxtrc-tbl__name">{{ row.name }}</span>
                      <div class="pxn-mono pxtrc-tbl__code">{{ row.code }}</div>
                      <div v-if="(row.batches || []).length" class="pxtrc-tbl__batches">
                        <span v-for="(b, bi) in row.batches" :key="bi" class="pxtrc-batchpill">
                          {{ b.batch_no }}<template v-if="b.expiry_date"> · cad {{ b.expiry_date }}</template> · {{ fmtQty(b.qty_available != null ? b.qty_available : b.quantity) }}
                        </span>
                      </div>
                    </td>
                    <td class="is-right pxn-num">{{ fmtQty(row.quantity_sent) }} {{ row.unit }}</td>
                    <td class="is-right pxn-num">{{ fmtQty(row.quantity_remaining) }} {{ row.unit }}</td>
                    <td class="is-right">
                      <px-input class="pxtrc-in" inputmode="decimal" :value="String(row._good)" placeholder="0"
                        :disabled="!one.can_receive" @input="v => onCell(row, '_good', v)" />
                    </td>
                    <td class="is-right">
                      <px-input class="pxtrc-in" inputmode="decimal" :value="String(row._defective)" placeholder="0"
                        :disabled="!one.can_receive" @input="v => onCell(row, '_defective', v)" />
                    </td>
                    <td class="is-right">
                      <px-input class="pxtrc-in" inputmode="decimal" :value="String(row._missing)" placeholder="0"
                        :disabled="!one.can_receive" @input="v => onCell(row, '_missing', v)" />
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <p v-if="rowError" class="pxtrc__rowerr"><lucide-icon name="info" :size="13" /> {{ rowError }}</p>

            <div class="pxtrc__quick">
              <px-button size="sm" variant="ghost" icon="check-check" :disabled="!one.can_receive" @click="fillAllGood">
                Marcar todo lo pendiente como correcto
              </px-button>
            </div>
          </px-card>

          <px-card title="Notas de recepción" class="pxtrc__sec">
            <px-field label="Notas (opcional)">
              <template #default="{ id }">
                <px-textarea :id="id" v-model="notes" :rows="3" placeholder="Observaciones de la recepción…" />
              </template>
            </px-field>
          </px-card>

          <div class="pxtrc__actions">
            <px-button variant="secondary" type="button" @click="backToInbox">Cancelar</px-button>
            <px-button
              variant="primary" icon="package-check" type="button"
              :loading="submitting" :disabled="!one.can_receive || !!rowError || nothingEntered"
              @click="submitReceipt"
            >Registrar recepción</px-button>
          </div>

          <px-card v-if="(one.events || []).length" title="Eventos" class="pxtrc__sec">
            <ul class="pxtrc-ev">
              <li v-for="(ev, i) in one.events" :key="i">
                <strong>{{ eventLabel(ev.event_type) }}</strong>
                <span class="pxtrc-ev__when">{{ fmtDateTime(ev.created_at) }}</span>
              </li>
            </ul>
          </px-card>
        </template>
      </template>

      <!-- ===================== MODO: bandeja ===================== -->
      <template v-else>
        <px-page-header title="Recepciones entrantes" :breadcrumbs="[{ label: 'Inventario' }, { label: 'Traslados' }, { label: 'Recepciones' }]">
          <template #actions>
            <px-button variant="secondary" size="sm" icon="refresh-cw" :loading="loadingInbox" @click="loadInbox">Actualizar</px-button>
          </template>
        </px-page-header>

        <px-alert v-if="inboxError" tone="danger" title="No se pudo cargar la bandeja" class="pxtrc__alert">
          {{ inboxError }}
          <template #actions><px-button size="sm" variant="secondary" @click="loadInbox">Reintentar</px-button></template>
        </px-alert>

        <div v-if="loadingInbox && !incoming.length && !issues.length" class="pxtrc__pad">
          <px-skeleton variant="table" :rows="6" :columns="4" />
        </div>

        <template v-else>
          <px-card title="En tránsito hacia tus ubicaciones" class="pxtrc__sec">
            <div v-if="!incoming.length" class="pxtrc__empty">No hay traslados en tránsito por recibir.</div>
            <div v-else class="pxtrc-tbl__wrap pxn-scroll">
              <table class="pxtrc-tbl">
                <thead>
                  <tr>
                    <th>Referencia</th>
                    <th>Origen</th>
                    <th>Destino</th>
                    <th class="is-right">Productos</th>
                    <th>Despachado</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="t in incoming" :key="t.id">
                    <td class="pxn-mono">{{ t.reference }}</td>
                    <td>{{ t.from_warehouse || t.from_inventory_location || '—' }}</td>
                    <td>{{ t.to_warehouse || t.to_inventory_location || '—' }}</td>
                    <td class="is-right pxn-num">{{ fmtQty(t.items) }}</td>
                    <td>{{ fmtDateTime(t.dispatched_at) }}</td>
                    <td class="is-right">
                      <px-button size="sm" :variant="t.can_receive ? 'primary' : 'secondary'" icon="package-check"
                        :disabled="!t.can_receive" @click="openReceive(t.id)">Recibir</px-button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </px-card>

          <px-card class="pxtrc__sec">
            <template #header>
              <h3 class="pxtrc__cardtitle">
                Incidencias
                <px-badge v-if="openIssueCount" tone="danger">{{ openIssueCount }} abiertas</px-badge>
              </h3>
            </template>
            <div class="pxtrc__issuetabs">
              <button type="button" :class="{ 'is-on': issueTab === 'open' }" @click="issueTab = 'open'">Abiertas</button>
              <button type="button" :class="{ 'is-on': issueTab === 'all' }" @click="issueTab = 'all'">Historial</button>
            </div>
            <div v-if="!visibleIssues.length" class="pxtrc__empty">Sin incidencias {{ issueTab === 'open' ? 'abiertas' : 'registradas' }}.</div>
            <div v-else class="pxtrc-tbl__wrap pxn-scroll">
              <table class="pxtrc-tbl">
                <thead>
                  <tr>
                    <th>Referencia</th>
                    <th>Producto</th>
                    <th>Ruta</th>
                    <th class="is-right">Cantidad</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th v-if="canManageIssues"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="iss in visibleIssues" :key="iss.id">
                    <td class="pxn-mono">{{ iss.reference }}</td>
                    <td>
                      {{ iss.product_name }}
                      <span v-if="iss.variant_name" class="pxn-muted">· {{ iss.variant_name }}</span>
                    </td>
                    <td class="pxn-muted">{{ iss.from_warehouse }} → {{ iss.to_warehouse }}</td>
                    <td class="is-right pxn-num">{{ fmtQty(iss.quantity) }}</td>
                    <td><px-badge :tone="iss.type === 'missing' ? 'danger' : 'warning'">{{ discrepancyTypeLabel(iss.type) }}</px-badge></td>
                    <td>
                      <px-badge :tone="iss.resolution_status === 'open' ? 'warning' : 'success'">
                        {{ discrepancyResolutionStatusLabel(iss.resolution_status) }}
                      </px-badge>
                      <div v-if="iss.resolution_status !== 'open'" class="pxtrc-tbl__code">
                        {{ resolutionLabel(iss) }}
                      </div>
                    </td>
                    <td v-if="canManageIssues" class="is-right">
                      <px-button v-if="iss.resolution_status === 'open'" size="sm" variant="secondary" icon="wrench"
                        @click="openResolve(iss)">Resolver</px-button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </px-card>
        </template>
      </template>
    </template>

    <!-- ===== Modal resolver incidencia ===== -->
    <px-modal v-model="resolveOpen" title="Resolver incidencia" size="md">
      <div v-if="resolveTarget" class="pxtrc__rform">
        <p class="pxtrc__rmeta">
          <strong>{{ resolveTarget.reference }}</strong> — {{ resolveTarget.product_name }}
          · {{ discrepancyTypeLabel(resolveTarget.type) }} · {{ fmtQty(resolveTarget.quantity) }}
        </p>
        <px-field label="Resolución" required>
          <template #default="{ id }">
            <px-select :id="id" :value="resolveCode" :options="resolutionOptions" @input="v => resolveCode = v" />
          </template>
        </px-field>
        <px-field label="Referencia (nº de ajuste, doc…)" :optional="!referenceRequired" :required="referenceRequired">
          <template #default="{ id }"><px-input :id="id" v-model="resolveReference" placeholder="Opcional salvo conciliación por ajuste" /></template>
        </px-field>
        <px-field label="Notas" required>
          <template #default="{ id }"><px-textarea :id="id" v-model="resolveNotes" :rows="3" placeholder="Explica la resolución…" /></template>
        </px-field>
      </div>
      <template #footer="{ close }">
        <span class="pxtrc__grow" />
        <px-button variant="secondary" :disabled="resolving" @click="close">Cancelar</px-button>
        <px-button variant="primary" icon="check" :loading="resolving" :disabled="!resolveCode || !resolveNotes || (referenceRequired && !resolveReference)"
          @click="submitResolve">Resolver</px-button>
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
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxSelect from "@/components/px-next/PxSelect.vue";
import {
  logisticsLabel, logisticsTone, eventLabel,
  DISCREPANCY_TYPE_LABELS, DISCREPANCY_RESOLUTION_STATUS_LABELS
} from "./statusMaps.js";

const META = { meta: { skipErrorRedirect: true } };
const TOKEN_PREFIX = "prodex.transfer.receipt.request.";

function uuid() {
  if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
  return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, c => {
    const r = (Math.random() * 16) | 0;
    return (c === "x" ? r : (r & 0x3) | 0x8).toString(16);
  });
}

export default {
  name: "TransferReceiveNext",
  metaInfo: { title: "Recepción de traslados" },
  components: {
    PxPageHeader, PxCard, PxButton, PxBadge, PxAlert, PxEmptyState, PxModal,
    PxField, PxInput, PxTextarea, PxSelect
  },
  data() {
    return {
      // bandeja
      loadingInbox: false,
      inboxError: null,
      incoming: [],
      issues: [],
      issueResolutions: { missing: [], defective: [] },
      canManageIssues: false,
      issueTab: "open",
      // un traslado
      loadingOne: false,
      oneError: null,
      one: {},
      rows: [],
      notes: "",
      submitting: false,
      // resolver
      resolveOpen: false,
      resolveTarget: null,
      resolveCode: "",
      resolveReference: "",
      resolveNotes: "",
      resolving: false
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    transferId() {
      return this.$route.params.id ? Number(this.$route.params.id) : null;
    },
    canReceiveOrView() {
      const list = Array.isArray(this.currentUserPermissions) ? this.currentUserPermissions : [];
      return list.includes("transfer_receive") || list.includes("transfer_view") || list.includes("transfer_issue_manage");
    },
    receiveRows() {
      return this.rows;
    },
    openIssueCount() {
      return (this.issues || []).filter(i => i.resolution_status === "open").length;
    },
    visibleIssues() {
      if (this.issueTab === "open") return (this.issues || []).filter(i => i.resolution_status === "open");
      return this.issues || [];
    },
    oneFrom() {
      return (this.one.transfer && (this.one.transfer.from_warehouse || this.one.transfer.from_inventory_location)) || "—";
    },
    oneTo() {
      return (this.one.transfer && (this.one.transfer.to_warehouse || this.one.transfer.to_inventory_location)) || "—";
    },
    rowError() {
      for (const r of this.rows) {
        const g = Number(r._good) || 0;
        const d = Number(r._defective) || 0;
        const m = Number(r._missing) || 0;
        if (g < 0 || d < 0 || m < 0) return "Las cantidades no pueden ser negativas.";
        if (g + d + m > Number(r.quantity_remaining) + 0.001) {
          return "La suma correcto + defectuoso + faltante supera lo pendiente en «" + r.name + "».";
        }
      }
      return "";
    },
    nothingEntered() {
      return !this.rows.some(r => (Number(r._good) || 0) + (Number(r._defective) || 0) + (Number(r._missing) || 0) > 0);
    },
    referenceRequired() {
      return this.resolveCode === "reconciled_by_adjustment";
    },
    resolutionOptions() {
      const t = this.resolveTarget && this.resolveTarget.type;
      const list = (t && this.issueResolutions[t]) || [];
      return [{ value: "", label: "Elegir resolución" }].concat(list.map(o => ({ value: o.value, label: o.label })));
    }
  },
  created() {
    if (!this.canReceiveOrView) return;
    if (this.transferId) this.loadOne();
    else this.loadInbox();
  },
  watch: {
    "$route.params.id"() {
      if (!this.canReceiveOrView) return;
      if (this.transferId) this.loadOne();
      else this.loadInbox();
    }
  },
  methods: {
    logisticsLabel, logisticsTone, eventLabel,
    discrepancyTypeLabel(t) { return DISCREPANCY_TYPE_LABELS[t] || t; },
    discrepancyResolutionStatusLabel(s) { return DISCREPANCY_RESOLUTION_STATUS_LABELS[s] || s; },
    fmtQty(v) {
      const n = Number(v);
      return Number.isFinite(n) ? String(n) : String(v == null ? "" : v);
    },
    fmtDateTime(v) {
      if (!v) return "—";
      const d = new Date(v);
      return isNaN(d.getTime()) ? String(v) : d.toLocaleString();
    },
    errMsg(err) {
      return (
        (err && err.response && err.response.data && (err.response.data.message || err.response.data.error)) ||
        (err && err.response && err.response.data && err.response.data.errors &&
          Object.values(err.response.data.errors)[0] && Object.values(err.response.data.errors)[0][0]) ||
        (err && err.message) || "Error de red."
      );
    },
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    },
    resolutionLabel(iss) {
      const pool = [].concat(this.issueResolutions.missing || [], this.issueResolutions.defective || []);
      const hit = pool.find(o => o.value === iss.resolution_code);
      const base = hit ? hit.label : (iss.resolution_code || "—");
      return iss.resolution_reference ? base + " · " + iss.resolution_reference : base;
    },

    //------- bandeja
    loadInbox() {
      this.loadingInbox = true;
      this.inboxError = null;
      Promise.all([
        window.axios.get("transfer-logistics/incoming", META).catch(e => ({ _err: e })),
        window.axios.get("transfer-logistics/issues", META).catch(e => ({ _err: e }))
      ]).then(([inc, iss]) => {
        if (inc._err && iss._err) { this.inboxError = this.errMsg(inc._err); }
        if (!inc._err) this.incoming = (inc.data && inc.data.transfers) || [];
        if (!iss._err) {
          this.issues = (iss.data && iss.data.issues) || [];
          this.issueResolutions = (iss.data && iss.data.resolutions) || { missing: [], defective: [] };
          this.canManageIssues = !!(iss.data && iss.data.can_manage);
        }
        this.loadingInbox = false;
      });
    },
    openReceive(id) {
      this.$router.push({ name: "transfer_reception", params: { id: String(id) } });
    },
    backToInbox() {
      this.$router.push({ name: "transfer_receptions" });
    },

    //------- un traslado
    loadOne() {
      this.loadingOne = true;
      this.oneError = null;
      window.axios
        .get("transfer-logistics/" + this.transferId, META)
        .then(response => {
          this.one = response.data || {};
          this.rows = ((this.one.details) || []).map(d => Object.assign({}, d, {
            _good: "", _defective: "", _missing: ""
          }));
          this.loadingOne = false;
        })
        .catch(err => {
          this.oneError = this.errMsg(err);
          setTimeout(() => { this.loadingOne = false; }, 300);
        });
    },
    onCell(row, key, val) {
      const num = parseFloat(String(val).replace(",", "."));
      this.$set(row, key, Number.isFinite(num) ? num : "");
    },
    fillAllGood() {
      for (const r of this.rows) {
        this.$set(r, "_good", Number(r.quantity_remaining) || 0);
        this.$set(r, "_defective", "");
        this.$set(r, "_missing", "");
      }
    },
    tokenKey() { return TOKEN_PREFIX + this.transferId; },
    getToken() {
      let t;
      try { t = window.sessionStorage.getItem(this.tokenKey()); } catch (e) { t = null; }
      if (!t) {
        t = "RCV-" + uuid();
        try { window.sessionStorage.setItem(this.tokenKey(), t); } catch (e) { /* noop */ }
      }
      return t;
    },
    clearToken() {
      try { window.sessionStorage.removeItem(this.tokenKey()); } catch (e) { /* noop */ }
    },
    submitReceipt() {
      if (this.rowError || this.nothingEntered) return;
      const items = this.rows
        .map(r => ({
          transfer_detail_id: r.transfer_detail_id,
          quantity_good: Number(r._good) || 0,
          quantity_defective: Number(r._defective) || 0,
          quantity_missing: Number(r._missing) || 0
        }))
        .filter(i => i.quantity_good + i.quantity_defective + i.quantity_missing > 0);
      if (!items.length) return;

      this.submitting = true;
      NProgress.start(); NProgress.set(0.1);
      const payload = { request_token: this.getToken(), notes: this.notes || null, items };
      window.axios
        .post("transfer-logistics/" + this.transferId + "/receive", payload, META)
        .then(response => {
          NProgress.done();
          this.submitting = false;
          this.clearToken();
          const st = response.data && response.data.transfer && response.data.transfer.logistics_status;
          const open = response.data && response.data.open_discrepancies;
          let msg = "Recepción registrada (" + logisticsLabel(st) + ").";
          if (open) msg += " Incidencias abiertas: " + open + ".";
          this.makeToast(open ? "warning" : "success", msg, "Recepción");
          if (st === "received" || st === "received_with_issues") this.backToInbox();
          else this.loadOne();
        })
        .catch(err => {
          NProgress.done();
          this.submitting = false;
          const status = err && err.response && err.response.status;
          // 4xx → petición inválida: token consumido/inservible, se descarta.
          // 5xx / red → se conserva para que el reintento sea idempotente.
          if (status && status >= 400 && status < 500) this.clearToken();
          this.makeToast("danger", this.errMsg(err), "Error");
        });
    },

    //------- resolver incidencia
    openResolve(iss) {
      this.resolveTarget = iss;
      this.resolveCode = "";
      this.resolveReference = "";
      this.resolveNotes = "";
      this.resolveOpen = true;
    },
    submitResolve() {
      if (!this.resolveTarget || !this.resolveCode || !this.resolveNotes) return;
      if (this.referenceRequired && !this.resolveReference) return;
      this.resolving = true;
      NProgress.start(); NProgress.set(0.1);
      window.axios
        .post("transfer-logistics/issues/" + this.resolveTarget.id + "/resolve", {
          resolution_code: this.resolveCode,
          resolution_reference: this.resolveReference || null,
          resolution_notes: this.resolveNotes
        }, META)
        .then(() => {
          NProgress.done();
          this.resolving = false;
          this.resolveOpen = false;
          this.makeToast("success", "Incidencia resuelta.", "Éxito");
          this.loadInbox();
        })
        .catch(err => {
          NProgress.done();
          this.resolving = false;
          this.makeToast("danger", this.errMsg(err), "Error");
        });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxtrc { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxtrc { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxtrc__denied { padding: var(--pxn-space-12) 0; }
.pxtrc__pad { padding: var(--pxn-space-6) 0; }
.pxtrc__alert { margin-top: var(--pxn-space-5); }
.pxtrc__sec { margin-top: var(--pxn-space-5); }
.pxtrc__cardtitle { margin: 0; display: flex; align-items: center; gap: var(--pxn-space-3); font-size: var(--pxn-fs-h3, 15px); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxtrc__empty { padding: var(--pxn-space-6); text-align: center; color: var(--pxn-ink-3); font-size: var(--pxn-fs-sm); }

.pxtrc-tbl__wrap { overflow-x: auto; }
.pxtrc-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-body); }
.pxtrc-tbl th {
  text-align: left; padding: var(--pxn-space-4);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
  text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3);
  background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap;
}
.pxtrc-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); vertical-align: middle; }
.pxtrc-tbl tr:last-child td { border-bottom: 0; }
.pxtrc-tbl .is-right { text-align: right; }
.pxtrc-tbl__name { font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); }
.pxtrc-tbl__code { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); margin-top: 2px; }
.pxtrc-tbl__batches { margin-top: var(--pxn-space-2); display: flex; flex-wrap: wrap; gap: var(--pxn-space-2); }
.pxtrc-batchpill { display: inline-block; padding: 1px var(--pxn-space-2); border-radius: var(--pxn-radius-pill); background: var(--pxn-primary-soft); color: var(--pxn-primary-ink); font-size: var(--pxn-fs-xs); }
.pxtrc-th--good { color: var(--pxn-success-ink); }
.pxtrc-th--defective { color: var(--pxn-warning-ink); }
.pxtrc-th--missing { color: var(--pxn-danger-ink); }
.pxtrc-in { max-width: 96px; margin-left: auto; }
.pxtrc-in :deep(.pxn-input) { text-align: right; height: var(--pxn-control-h-sm); }

.pxtrc__rowerr { display: flex; align-items: center; gap: var(--pxn-space-2); margin: var(--pxn-space-4) 0 0; font-size: var(--pxn-fs-sm); color: var(--pxn-danger-ink); }
.pxtrc__quick { margin-top: var(--pxn-space-4); }
.pxtrc__actions { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-5); }

.pxtrc__issuetabs { display: flex; gap: var(--pxn-space-2); margin-bottom: var(--pxn-space-4); }
.pxtrc__issuetabs button {
  border: 1px solid var(--pxn-border); background: var(--pxn-surface); color: var(--pxn-ink-2);
  padding: var(--pxn-space-2) var(--pxn-space-4); border-radius: var(--pxn-radius-pill);
  font-size: var(--pxn-fs-sm); cursor: pointer;
}
.pxtrc__issuetabs button.is-on { background: var(--pxn-primary); color: #fff; border-color: var(--pxn-primary); }

.pxtrc-ev { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--pxn-space-3); }
.pxtrc-ev li { display: flex; justify-content: space-between; gap: var(--pxn-space-4); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.pxtrc-ev__when { color: var(--pxn-ink-3); font-size: var(--pxn-fs-xs); }

.pxtrc__rform { display: flex; flex-direction: column; gap: var(--pxn-space-4); }
.pxtrc__rmeta { margin: 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.pxtrc__grow { flex: 1; }
</style>
