<template>
  <section>
    <section-head
      num="07"
      title="Feedback y estados vacíos"
      desc="Alertas, estados vacíos, carga (skeleton reutilizado del sistema actual) y notificaciones transitorias. El movimiento comunica «pasó algo, aquí» — nada más."
    />

    <h3 class="fb-h3">Alertas</h3>
    <div class="fb-stack">
      <px-alert tone="info" title="Recepción parcial pendiente">
        La orden <b class="pxn-mono">OC-2026-0188</b> tiene 6 de 22 líneas sin recibir. La diferencia queda como faltante hasta el cierre.
      </px-alert>
      <px-alert tone="success" title="Factura emitida">
        Documento <b class="pxn-mono">001-001-01-00045213</b> emitido y enviado al cliente.
      </px-alert>
      <px-alert tone="warning" title="Lotes próximos a vencer" dismissible>
        23 lotes vencen en los próximos 30 días en 2 sucursales.
        <template #actions><px-button size="sm" variant="secondary">Ver lotes</px-button></template>
      </px-alert>
      <px-alert tone="danger" title="Secuencia fiscal por agotarse">
        Quedan 786 folios en el rango autorizado. Solicite una nueva autorización antes del <b>31/12/2026</b>.
      </px-alert>
      <px-alert tone="info" bare>Variante <code>bare</code>: barra lateral, para usar dentro de listas y paneles densos.</px-alert>
    </div>

    <h3 class="fb-h3">Estados vacíos</h3>
    <div class="pxn-grid pxn-grid-3">
      <px-card flush><px-empty-state icon="inbox" title="Sin traslados" description="No hay traslados en tránsito hacia esta sucursal." /></px-card>
      <px-card flush>
        <px-empty-state tone="info" icon="file-plus" title="Aún no hay productos" description="Importe un catálogo o cree el primer producto para empezar.">
          <px-button size="sm" variant="primary">Crear producto</px-button>
        </px-empty-state>
      </px-card>
      <px-card flush>
        <px-empty-state tone="warning" icon="x-circle" title="Sin conexión" description="El POS sigue operando. Las ventas se sincronizarán al recuperar la red." />
      </px-card>
    </div>

    <h3 class="fb-h3">Carga · skeleton <span class="fb-note">reutiliza <code>&lt;px-skeleton&gt;</code> existente — adopta la paleta px-next vía alias</span></h3>
    <div class="fb-loadrow">
      <div>
        <button type="button" class="fb-toggle pxn-ring" @click="loading = !loading">
          {{ loading ? 'Mostrar datos' : 'Simular carga' }}
        </button>
      </div>
      <px-card flush>
        <div class="fb-loadbox">
          <px-skeleton v-if="loading" variant="table" :rows="5" :columns="5" />
          <table v-else class="fb-mini pxn-num">
            <thead><tr><th>Documento</th><th>Cliente</th><th class="r">Total</th><th>Estado</th></tr></thead>
            <tbody>
              <tr v-for="s in sales.slice(0, 5)" :key="s.id">
                <td class="pxn-mono">{{ s.doc }}</td>
                <td class="l">{{ s.customer }}</td>
                <td class="r">{{ money(s.total) }}</td>
                <td><px-badge :tone="saleState[s.state].badge" :icon="saleState[s.state].icon">{{ saleState[s.state].label }}</px-badge></td>
              </tr>
            </tbody>
          </table>
        </div>
      </px-card>
      <div class="fb-skelvariants">
        <px-skeleton variant="lines" :rows="3" />
        <px-skeleton variant="card" />
        <px-skeleton variant="control" />
      </div>
    </div>

    <h3 class="fb-h3">Notificaciones transitorias</h3>
    <div class="fb-toastrow">
      <px-button size="sm" variant="secondary" @click="toast('success', 'Ajuste de existencias guardado')">Éxito</px-button>
      <px-button size="sm" variant="secondary" @click="toast('info', 'Traslado enviado a CD Zona Norte')">Info</px-button>
      <px-button size="sm" variant="secondary" @click="toast('warning', 'La conexión con el lector se perdió')">Aviso</px-button>
      <px-button size="sm" variant="secondary" @click="toast('danger', 'No se pudo emitir la factura fiscal')">Error</px-button>
    </div>

    <px-toast :items="toasts" @dismiss="dismiss" />
  </section>
</template>

<script>
import SectionHead from "./_SectionHead.vue";
import { PxAlert, PxButton, PxEmptyState, PxCard, PxBadge, PxToast } from "@/components/px-next";
import { SALES, SALE_STATE } from "../data/mock";
import { money as fmtMoney } from "../data/format";

export default {
  name: "FeedbackSection",
  components: { SectionHead, PxAlert, PxButton, PxEmptyState, PxCard, PxBadge, PxToast },
  props: { density: String, country: { type: String, default: "HN" } },
  data() {
    return { loading: true, sales: SALES, saleState: SALE_STATE, toasts: [], _tid: 0 };
  },
  methods: {
    money(v) { return fmtMoney(v, { country: this.country }); },
    toast(tone, message) {
      const id = ++this._tid;
      this.toasts.push({ id, tone, message });
      setTimeout(() => this.dismiss(id), 3600);
    },
    dismiss(id) { this.toasts = this.toasts.filter(t => t.id !== id); }
  }
};
</script>

<style lang="scss" scoped>
.fb-h3 { font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); margin: var(--pxn-space-9) 0 var(--pxn-space-5); }
.fb-h3:first-of-type { margin-top: 0; }
.fb-note { margin-left: var(--pxn-space-3); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-regular); color: var(--pxn-ink-3); }
.fb-stack { display: flex; flex-direction: column; gap: var(--pxn-space-4); }

.fb-loadrow { display: grid; grid-template-columns: 130px 1fr 160px; gap: var(--pxn-space-6); align-items: start; }
@media (max-width: 900px) { .fb-loadrow { grid-template-columns: minmax(0, 1fr); } }
.fb-toggle { height: 32px; padding: 0 var(--pxn-space-5); border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); font: inherit; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-medium); cursor: pointer; }
.fb-loadbox { padding: var(--pxn-space-5); }
.fb-mini { width: 100%; border-collapse: separate; border-spacing: 0; font-size: var(--pxn-fs-sm); }
.fb-mini th, .fb-mini td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); white-space: nowrap; text-align: left; }
.fb-mini thead th { background: var(--pxn-surface-2); font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-2); }
.fb-mini .r { text-align: right; }
.fb-mini .l { color: var(--pxn-ink); }
.fb-mini td { color: var(--pxn-ink-2); }
.fb-mini tr:last-child td { border-bottom: 0; }
.fb-skelvariants { display: flex; flex-direction: column; gap: var(--pxn-space-4); }

.fb-toastrow { display: flex; flex-wrap: wrap; gap: var(--pxn-space-4); }
</style>
