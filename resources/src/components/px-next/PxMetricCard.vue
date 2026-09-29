<template>
  <component
    :is="isLink ? 'router-link' : 'div'"
    v-bind="isLink ? { to } : {}"
    class="pxn-mc"
    :class="{ 'is-link': isLink }"
  >
    <template v-if="loading">
      <px-skeleton variant="lines" :rows="3" />
    </template>
    <template v-else>
      <header class="pxn-mc__head">
        <span v-if="icon" class="pxn-mc__chip" aria-hidden="true"><lucide-icon :name="icon" :size="16" /></span>
        <h3 class="pxn-mc__title">{{ label }}</h3>
        <span v-if="trend" class="pxn-mc__trend" :class="`is-${trendTone}`">
          <lucide-icon :name="trendIcon" :size="14" aria-hidden="true" />
          <span class="pxn-num">{{ trend }}</span>
          <span class="pxn-mc__sr">{{ trendSr }}</span>
        </span>
      </header>

      <p class="pxn-mc__value pxn-num" :title="fullValue">
        <span v-if="prefix" class="pxn-mc__affix" :aria-hidden="compact ? 'true' : null">{{ prefix }}</span><span class="pxn-mc__num" :aria-hidden="compact ? 'true' : null">{{ value }}</span><span v-if="suffix" class="pxn-mc__affix" :aria-hidden="compact ? 'true' : null">{{ suffix }}</span>
        <!-- cifra compacta ("L 1,4 B"): el lector de pantalla recibe la cifra íntegra -->
        <span v-if="compact" class="pxn-mc__sr">{{ fullValue }}</span>
      </p>

      <p v-if="sub || trendHint" class="pxn-mc__sub">
        <span v-if="sub">{{ sub }}</span>
        <span v-if="trend && trendHint" class="pxn-mc__hint">{{ trendHint }}</span>
      </p>

      <!-- Solo con un denominador/meta real: el padre entrega el porcentaje ya calculado -->
      <div v-if="hasProgress" class="pxn-mc__progress">
        <div
          class="pxn-mc__bar"
          role="progressbar"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-valuenow="clampedProgress"
          :aria-label="progressLabel || label"
          :aria-valuetext="progressLabel || null"
        >
          <span class="pxn-mc__fill" :style="{ width: clampedProgress + '%' }"></span>
        </div>
        <span v-if="progressLabel" class="pxn-mc__plabel">{{ progressLabel }}</span>
      </div>
    </template>
  </component>
</template>

<script>
import PxSkeleton from "@/components/PxSkeleton.vue";

// PxMetricCard: card de métrica (KPI). Solo presenta lo que el padre entrega: no calcula tendencias,
// metas ni tonos de negocio, y no anima el número (la cifra real se muestra de inmediato).
//  · trend + trendDirection (up|down|flat) + trendTone (success|danger|warning|neutral): la dirección no
//    implica el tono (un gasto que sube puede ser negativo); el glifo y el texto acompañan al color.
//  · progress (0–100) solo si existe una meta/denominador real; sin él no se dibuja barra.
//  · to: si la métrica navega, la card es un router-link real (foco, teclado); si no, es estática
//    (sin cursor, hover ni sombra de interacción).
export default {
  name: "PxMetricCard",
  components: { PxSkeleton },
  props: {
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    valueTitle: { type: [String, Number], default: null }, // cifra íntegra si `value` viene compactado
    prefix: { type: String, default: null },
    suffix: { type: String, default: null },
    icon: { type: String, default: null },
    sub: { type: String, default: null },
    trend: { type: String, default: null },
    trendDirection: { type: String, default: "flat" }, // up | down | flat
    trendTone: { type: String, default: "neutral" },   // success | danger | warning | neutral
    trendHint: { type: String, default: null },        // "vs período anterior"
    progress: { type: Number, default: null },
    progressLabel: { type: String, default: null },
    to: { type: [String, Object], default: null },
    loading: { type: Boolean, default: false }
  },
  computed: {
    isLink() { return !!this.to && !this.loading; },
    fullValue() {
      const v = this.valueTitle != null ? this.valueTitle : this.value;
      return `${this.prefix || ""}${v}${this.suffix || ""}`.trim();
    },
    compact() { return this.valueTitle != null && String(this.valueTitle) !== String(this.value); },
    trendIcon() { return this.trendDirection === "up" ? "trending-up" : this.trendDirection === "down" ? "trending-down" : "minus"; },
    trendSr() {
      const dir = this.trendDirection === "up" ? "Aumento" : this.trendDirection === "down" ? "Disminución" : "Sin cambio";
      return `${dir}${this.trendHint ? " " + this.trendHint : ""}`;
    },
    hasProgress() { return typeof this.progress === "number" && isFinite(this.progress); },
    clampedProgress() { return Math.max(0, Math.min(100, Math.round(this.progress))); }
  }
};
</script>

<style lang="scss" scoped>
.pxn-mc {
  --_pad: var(--pxn-space-7);
  display: flex;
  flex-direction: column;
  gap: var(--pxn-space-4);
  min-width: 0;
  padding: var(--_pad);
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-lg);
  color: inherit;
  text-decoration: none;
}

.pxn-mc__head { display: flex; align-items: center; gap: var(--pxn-space-4); min-width: 0; }
.pxn-mc__chip {
  flex: none; display: inline-flex; align-items: center; justify-content: center;
  width: 32px; height: 32px; border-radius: 50%;
  background: var(--pxn-primary-soft); color: var(--pxn-primary-ink);
}
.pxn-mc__title { margin: 0; flex: 1; min-width: 0; font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pxn-mc__trend { flex: none; display: inline-flex; align-items: center; gap: 2px; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); }
.pxn-mc__trend.is-success { color: var(--pxn-success-ink); }
.pxn-mc__trend.is-danger { color: var(--pxn-danger-ink); }
.pxn-mc__trend.is-warning { color: var(--pxn-warning-ink); }
.pxn-mc__trend.is-neutral { color: var(--pxn-ink-3); }

.pxn-mc__value {
  display: flex; align-items: baseline; gap: 0.12em; min-width: 0; max-width: 100%; margin: 0;
  font-size: var(--pxn-fs-kpi); font-weight: var(--pxn-fw-bold); line-height: var(--pxn-lh-tight);
  color: var(--pxn-ink); letter-spacing: -0.02em;
}
.pxn-mc__num { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pxn-mc__affix { flex: none; font-size: 0.62em; font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink-2); }
.pxn-mc__sub { display: flex; flex-wrap: wrap; align-items: center; gap: var(--pxn-space-3); margin: 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxn-mc__hint { color: var(--pxn-ink-3); }

.pxn-mc__progress { display: flex; flex-direction: column; gap: var(--pxn-space-3); margin-top: var(--pxn-space-2); }
.pxn-mc__bar { height: 8px; border-radius: var(--pxn-radius-pill); background: var(--pxn-surface-3); overflow: hidden; }
.pxn-mc__fill { display: block; height: 100%; border-radius: inherit; background: var(--pxn-primary); transition: width 300ms var(--pxn-ease); }
.pxn-mc__plabel { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-2); }

.pxn-mc__sr { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }

// Solo las cards que navegan parecen y se comportan como interactivas.
.pxn-mc.is-link { cursor: pointer; transition: transform 200ms var(--pxn-ease), border-color 200ms var(--pxn-ease), box-shadow 200ms var(--pxn-ease); }
.pxn-mc.is-link:hover, .pxn-mc.is-link:focus-visible { transform: translateY(-2px); border-color: var(--pxn-border-strong); box-shadow: var(--pxn-shadow-card-hover); }
.pxn-mc.is-link:focus-visible { outline: 2px solid var(--pxn-primary) !important; outline-offset: 2px !important; }

@media (prefers-reduced-motion: reduce) {
  .pxn-mc.is-link, .pxn-mc__fill { transition: none; }
  .pxn-mc.is-link:hover, .pxn-mc.is-link:focus-visible { transform: none; }
}
</style>
