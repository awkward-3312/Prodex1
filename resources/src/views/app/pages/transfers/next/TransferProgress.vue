<template>
  <section class="pxtp" :class="{ 'is-ready': ready }" :aria-label="'Seguimiento del traslado' + (reference ? ' ' + reference : '')">
    <div class="pxtp__route">
      <div class="pxtp__place">
        <span class="pxtp__place-k">Origen</span>
        <span class="pxtp__place-v" :title="from">{{ from || "—" }}</span>
      </div>
      <lucide-icon name="arrow-right" :size="16" class="pxtp__route-arrow" aria-hidden="true" />
      <div class="pxtp__place">
        <span class="pxtp__place-k">Destino</span>
        <span class="pxtp__place-v" :title="to">{{ to || "—" }}</span>
      </div>
    </div>
    <p v-if="summary" class="pxtp__summary">{{ summary }}</p>

    <ol class="pxtp__list">
      <li
        v-for="(s, i) in model.steps"
        :key="s.key"
        class="pxtp__step"
        :class="['is-' + s.state, { 'is-last': i === model.steps.length - 1, 'is-line-filled': s.state === 'done', 'is-pulse': pulseKey === s.key }]"
        :aria-current="s.state === 'current' ? 'step' : null"
      >
        <span class="pxtp__rail" aria-hidden="true">
          <span class="pxtp__circle">
            <span class="pxtp__num">{{ i + 1 }}</span>
            <svg v-if="s.state === 'rejected'" class="pxtp__icon" viewBox="0 0 16 16" width="16" height="16" fill="currentColor"><path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708"/></svg>
            <svg v-else class="pxtp__icon pxtp__icon--check" viewBox="0 0 16 16" width="16" height="16" fill="currentColor"><path d="M12.736 3.97a.733.733 0 0 1 1.047 0c.286.289.29.756.01 1.05L7.88 12.01a.733.733 0 0 1-1.065.02L3.217 8.384a.757.757 0 0 1 0-1.06.733.733 0 0 1 1.047 0l3.052 3.093 5.4-6.425z"/></svg>
          </span>
          <span v-if="i < model.steps.length - 1" class="pxtp__line"></span>
        </span>
        <div class="pxtp__body">
          <div class="pxtp__title">{{ s.title }}</div>
          <px-badge :tone="s.tone" :icon="s.flag ? 'alert-triangle' : null" class="pxtp__badge">{{ s.badge }}</px-badge>
          <div v-if="s.at" class="pxtp__time">{{ fmt(s.at) }}<template v-if="s.by"> · {{ s.by }}</template></div>
          <div v-if="s.note" class="pxtp__note">{{ s.note }}</div>
          <transition name="pxtp-truck">
            <transfer-truck v-if="s.transit" class="pxtp__truck" />
          </transition>
        </div>
      </li>
    </ol>

    <p v-if="model.unknown" class="pxtp__unknown" role="status">
      <lucide-icon name="info" :size="13" /> Estado no reconocido ({{ model.unknownValue }}). Se muestra solo lo confirmado.
    </p>
  </section>
</template>

<script>
import PxBadge from "@/components/px-next/PxBadge.vue";
import TransferTruck from "./TransferTruck.vue";
import { buildTransferSteps } from "./transferProgress.js";

// Seguimiento de UN traslado. Recibe los datos que el detalle ya cargó (no consulta el backend)
// y solo representa el estado real: las acciones (aprobar/despachar/recibir) siguen siendo del padre.
export default {
  name: "TransferProgress",
  components: { PxBadge, TransferTruck },
  props: {
    transfer: { type: Object, default: () => ({}) },
    workflow: { type: Object, default: () => ({}) },
    from: { type: String, default: "" },
    to: { type: String, default: "" },
    lines: { type: Number, default: 0 },
    units: { type: [Number, String], default: null }
  },
  data() {
    return { ready: false, pulseKey: null, pulseTimer: null, raf: null };
  },
  computed: {
    reference() { return this.transfer.Ref || ""; },
    model() {
      return buildTransferSteps({
        transfer: this.transfer,
        workflowTransfer: this.workflow && this.workflow.transfer,
        events: this.workflow && this.workflow.events
      });
    },
    currentKey() {
      const cur = this.model.steps.find(s => s.state === "current");
      return cur ? cur.key : null;
    },
    summary() {
      const parts = [];
      if (this.lines) parts.push(this.lines + (this.lines === 1 ? " línea" : " líneas"));
      const u = Number(this.units);
      if (Number.isFinite(u) && u > 0) parts.push(u + (u === 1 ? " unidad" : " unidades"));
      return parts.join(" · ");
    }
  },
  watch: {
    // Solo microseñal cuando el paso activo CAMBIA de verdad (no al montar).
    currentKey(now, before) {
      if (!this.ready || !now || now === before) return;
      this.pulseKey = now;
      clearTimeout(this.pulseTimer);
      this.pulseTimer = setTimeout(() => { this.pulseKey = null; }, 900);
    }
  },
  mounted() {
    // Las transiciones se habilitan después del primer pintado: al aparecer, todo es legible de inmediato.
    this.raf = requestAnimationFrame(() => { this.raf = requestAnimationFrame(() => { this.ready = true; }); });
  },
  beforeUnmount() {
    cancelAnimationFrame(this.raf);
    clearTimeout(this.pulseTimer);
  },
  methods: {
    fmt(v) {
      const d = new Date(v);
      if (isNaN(d.getTime())) return String(v);
      return d.toLocaleString([], { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
    }
  }
};
</script>

<style lang="scss" scoped>
.pxtp { --_c: 40px; }
@media (max-width: 620px) { .pxtp { --_c: 36px; } }

.pxtp__route { display: flex; align-items: center; gap: var(--pxn-space-5); min-width: 0; }
.pxtp__place { display: flex; flex-direction: column; gap: var(--pxn-space-1); min-width: 0; flex: 1 1 0; }
.pxtp__place-k { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); text-transform: uppercase; letter-spacing: 0.04em; }
.pxtp__place-v { font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); overflow-wrap: anywhere; }
.pxtp__route-arrow { flex: none; color: var(--pxn-ink-3); }
.pxtp__summary { margin: var(--pxn-space-4) 0 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }

.pxtp__list { list-style: none; margin: var(--pxn-space-8) 0 0; padding: 0; }
.pxtp__step { position: relative; display: grid; grid-template-columns: var(--_c) minmax(0, 1fr); column-gap: var(--pxn-space-6); padding-bottom: var(--pxn-space-9); }
.pxtp__step.is-last { padding-bottom: 0; }

.pxtp__rail { position: relative; display: block; }
.pxtp__circle {
  position: relative; z-index: 1; display: flex; align-items: center; justify-content: center;
  width: var(--_c); height: var(--_c); border-radius: 50%;
  border: 2px solid var(--pxn-border-strong); background: var(--pxn-surface); color: var(--pxn-ink-3);
  font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold);
}
.pxtp__num, .pxtp__icon { position: absolute; }
.pxtp__icon { opacity: 0; transform: scale(0.6); }

.pxtp__line { position: absolute; left: calc(var(--_c) / 2 - 1px); top: var(--_c); bottom: calc(var(--pxn-space-9) * -1); width: 2px; background: var(--pxn-border-strong); overflow: hidden; }
.pxtp__line::after { content: ""; position: absolute; inset: 0; background: var(--pxn-primary); transform: scaleY(0); transform-origin: top; }
.pxtp__step.is-line-filled .pxtp__line::after { transform: scaleY(1); }

// COMPLETADO: círculo sólido + check
.pxtp__step.is-done .pxtp__circle { background: var(--pxn-primary); border-color: var(--pxn-primary); color: var(--pxn-primary-contrast); }
.pxtp__step.is-done .pxtp__num { opacity: 0; transform: scale(0.6); }
.pxtp__step.is-done .pxtp__icon { opacity: 1; transform: scale(1); }

// ACTUAL: borde primario + anillo estático + número
.pxtp__step.is-current .pxtp__circle { border-color: var(--pxn-primary); color: var(--pxn-primary-ink); box-shadow: 0 0 0 4px var(--pxn-primary-soft); }

// PENDIENTE / NO APLICA: borde discontinuo y tenue
.pxtp__step.is-pending .pxtp__circle, .pxtp__step.is-skipped .pxtp__circle { border-style: dashed; color: var(--pxn-ink-disabled); }

// RECHAZADA: terminal alternativo con X
.pxtp__step.is-rejected .pxtp__circle { background: var(--pxn-danger-soft); border-color: var(--pxn-danger); color: var(--pxn-danger); }
.pxtp__step.is-rejected .pxtp__num { opacity: 0; transform: scale(0.6); }
.pxtp__step.is-rejected .pxtp__icon { opacity: 1; transform: scale(1); }

.pxtp__body { display: flex; flex-direction: column; align-items: flex-start; gap: var(--pxn-space-2); min-width: 0; padding-top: var(--pxn-space-2); }
.pxtp__title { font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); line-height: var(--pxn-lh-snug); }
.pxtp__step.is-pending .pxtp__title, .pxtp__step.is-skipped .pxtp__title { color: var(--pxn-ink-3); font-weight: var(--pxn-fw-medium); }
.pxtp__step.is-current .pxtp__title { color: var(--pxn-primary-ink); }
.pxtp__time { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); overflow-wrap: anywhere; }
.pxtp__note { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); overflow-wrap: anywhere; }
.pxtp__truck { margin-top: var(--pxn-space-4); }
.pxtp-truck-leave-active { transition: opacity 180ms var(--pxn-ease); }
.pxtp-truck-leave-to { opacity: 0; }
.pxtp__unknown { display: flex; align-items: center; gap: var(--pxn-space-2); margin: var(--pxn-space-6) 0 0; font-size: var(--pxn-fs-sm); color: var(--pxn-warning-ink); }

// Movimiento funcional: solo después del primer pintado y solo cuando el estado cambia.
.pxtp.is-ready {
  .pxtp__circle { transition: background-color 200ms var(--pxn-ease), border-color 200ms var(--pxn-ease), color 200ms var(--pxn-ease), box-shadow 240ms var(--pxn-ease); }
  .pxtp__num, .pxtp__icon { transition: opacity 180ms var(--pxn-ease), transform 220ms var(--pxn-ease); }
  .pxtp__line::after { transition: transform 420ms var(--pxn-ease); }
  .pxtp__step.is-pulse .pxtp__circle::after { animation: pxtp-ring 800ms var(--pxn-ease-out) 1; }
}
.pxtp__circle::after { content: ""; position: absolute; inset: -2px; border-radius: 50%; border: 2px solid var(--pxn-primary); opacity: 0; pointer-events: none; }
@keyframes pxtp-ring {
  0% { opacity: 0.55; transform: scale(1); }
  100% { opacity: 0; transform: scale(1.55); }
}

@media (prefers-reduced-motion: reduce) {
  .pxtp.is-ready .pxtp__circle, .pxtp.is-ready .pxtp__num, .pxtp.is-ready .pxtp__icon, .pxtp.is-ready .pxtp__line::after { transition: none; }
  .pxtp.is-ready .pxtp__step.is-pulse .pxtp__circle::after { animation: none; }
}
</style>
