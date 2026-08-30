<template>
  <section>
    <section-head
      num="08"
      title="Marco de gráfico"
      desc="px-next NO reemplaza la librería de charts de PRODEX. Aporta el contenedor: título, unidad, leyenda, ejes y nota al pie — para que todos los gráficos vivan en la misma caja. El SVG de abajo es un dibujo mínimo, no una librería."
    />

    <div class="pxn-grid pxn-grid-2">
      <px-chart-frame
        title="Volumen de ventas"
        unit="miles de L · últimos 7 días"
        :legend="[{ label: 'Ventas', color: 'var(--pxn-primary)' }, { label: 'Meta diaria', color: 'var(--pxn-ink-3)' }]"
        :height="200"
      >
        <svg class="cs-svg" viewBox="0 0 320 180" preserveAspectRatio="none" role="img" aria-label="Barras de volumen de ventas por día">
          <line v-for="g in 4" :key="g" :x1="0" :x2="320" :y1="g * 40" :y2="g * 40" class="cs-grid" />
          <line x1="0" y1="60" x2="320" y2="60" class="cs-goal" />
          <g v-for="(b, i) in bars" :key="i">
            <rect
              :x="i * 44 + 12"
              :y="180 - b.value * 1.5"
              width="24"
              :height="b.value * 1.5"
              rx="4"
              class="cs-bar"
              :class="{ 'is-peak': b.value === peak }"
            />
            <text :x="i * 44 + 24" y="176" class="cs-xlabel">{{ b.label }}</text>
          </g>
        </svg>
        <template #footer>El día de mayor volumen fue el viernes con L 340,200.00 · 41 % sobre la meta.</template>
      </px-chart-frame>

      <px-chart-frame title="Composición de inventario" unit="por categoría" :height="200">
        <div class="cs-bars">
          <div v-for="c in mix" :key="c.label" class="cs-track">
            <div class="cs-track__head"><span>{{ c.label }}</span><span class="pxn-num pxn-muted">{{ c.pct }} %</span></div>
            <div class="cs-track__rail"><div class="cs-track__fill" :style="{ width: c.pct + '%' }"></div></div>
          </div>
        </div>
        <template #footer>Farmacia y Abarrotes concentran el 61 % del valor de inventario.</template>
      </px-chart-frame>
    </div>

    <px-chart-frame title="Sin datos" unit="ejemplo de estado vacío en el marco" :height="180">
      <px-empty-state inline icon="bar-chart-3" title="Aún no hay suficientes datos" description="El gráfico aparecerá cuando existan al menos 7 días de operación." />
    </px-chart-frame>
  </section>
</template>

<script>
import SectionHead from "./_SectionHead.vue";
import { PxChartFrame, PxEmptyState } from "@/components/px-next";
import { VOLUME_BARS } from "../data/mock";
export default {
  name: "ChartSection",
  components: { SectionHead, PxChartFrame, PxEmptyState },
  props: { density: String, country: String },
  data() {
    return {
      bars: VOLUME_BARS,
      mix: [
        { label: "Farmacia", pct: 34 },
        { label: "Abarrotes", pct: 27 },
        { label: "Bebidas", pct: 16 },
        { label: "Ferretería", pct: 12 },
        { label: "Cuidado personal", pct: 7 },
        { label: "Limpieza", pct: 4 }
      ]
    };
  },
  computed: { peak() { return Math.max(...this.bars.map(b => b.value)); } }
};
</script>

<style lang="scss" scoped>
.cs-svg { width: 100%; height: 100%; }
.cs-grid { stroke: var(--pxn-border); stroke-width: 1; }
.cs-goal { stroke: var(--pxn-ink-3); stroke-width: 1; stroke-dasharray: 3 3; }
.cs-bar { fill: color-mix(in srgb, var(--pxn-primary) 32%, #fff); transition: fill var(--pxn-dur-1) var(--pxn-ease); }
.cs-bar.is-peak { fill: var(--pxn-primary); }
.cs-xlabel { fill: var(--pxn-ink-3); font-size: 9px; text-anchor: middle; }

.cs-bars { display: flex; flex-direction: column; gap: var(--pxn-space-4); height: 100%; justify-content: center; }
.cs-track { display: flex; flex-direction: column; gap: var(--pxn-space-2); }
.cs-track__head { display: flex; justify-content: space-between; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-2); }
.cs-track__rail { height: 8px; background: var(--pxn-surface-3); border-radius: 999px; overflow: hidden; }
.cs-track__fill { height: 100%; background: var(--pxn-primary); border-radius: 999px; }
</style>
