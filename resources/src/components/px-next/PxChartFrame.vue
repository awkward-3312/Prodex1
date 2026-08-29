<template>
  <figure class="pxn-chartframe">
    <figcaption v-if="title || $slots.actions" class="pxn-chartframe__head">
      <div>
        <span class="pxn-chartframe__title">{{ title }}</span>
        <span v-if="unit" class="pxn-chartframe__unit">{{ unit }}</span>
      </div>
      <div v-if="$slots.actions" class="pxn-chartframe__actions"><slot name="actions" /></div>
    </figcaption>

    <div v-if="legend.length" class="pxn-chartframe__legend">
      <span v-for="l in legend" :key="l.label" class="pxn-chartframe__legenditem">
        <span class="pxn-chartframe__swatch" :style="{ background: l.color || 'var(--pxn-primary)' }"></span>
        {{ l.label }}
      </span>
    </div>

    <div class="pxn-chartframe__plot" :style="{ height: height + 'px' }">
      <slot>
        <div class="pxn-chartframe__placeholder">
          <lucide-icon name="bar-chart-3" :size="20" />
          <span>Área de gráfico</span>
          <small>PRODEX conserva su librería de charts actual; px-next sólo aporta el marco, ejes y leyenda.</small>
        </div>
      </slot>
    </div>

    <div v-if="$slots.footer" class="pxn-chartframe__foot"><slot name="footer" /></div>
  </figure>
</template>

<script>
// Frame / axis / legend chrome for a chart — NOT a charting library. px-next
// deliberately does not touch PRODEX's existing charts; this just gives them a
// consistent container, caption, legend and footnote.
export default {
  name: "PxChartFrame",
  props: {
    title: { type: String, default: null },
    unit: { type: String, default: null },
    legend: { type: Array, default: () => [] }, // [{ label, color }]
    height: { type: [Number, String], default: 220 }
  }
};
</script>

<style lang="scss" scoped>
.pxn-chartframe {
  margin: 0;
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-lg);
  background: var(--pxn-surface);
  padding: var(--pxn-space-6) var(--pxn-space-7) var(--pxn-space-5);
}
.pxn-chartframe__head { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--pxn-space-5); }
.pxn-chartframe__title { font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxn-chartframe__unit { margin-left: var(--pxn-space-3); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxn-chartframe__legend { display: flex; flex-wrap: wrap; gap: var(--pxn-space-5); margin-top: var(--pxn-space-4); }
.pxn-chartframe__legenditem { display: inline-flex; align-items: center; gap: var(--pxn-space-3); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-2); }
.pxn-chartframe__swatch { width: 10px; height: 10px; border-radius: 3px; }
.pxn-chartframe__plot { margin-top: var(--pxn-space-5); }
.pxn-chartframe__placeholder {
  height: 100%;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: var(--pxn-space-3);
  border: 1px dashed var(--pxn-border-strong);
  border-radius: var(--pxn-radius-md);
  color: var(--pxn-ink-3);
  text-align: center;
  padding: var(--pxn-space-6);
}
.pxn-chartframe__placeholder small { max-width: 40ch; font-size: var(--pxn-fs-xs); }
.pxn-chartframe__foot { margin-top: var(--pxn-space-4); padding-top: var(--pxn-space-4); border-top: 1px solid var(--pxn-border); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
</style>
