<template>
  <px-chart-frame :title="title" :unit="unit" :legend="legend" :height="height">
    <template v-if="$slots.actions" #actions><slot name="actions" /></template>
    <apexchart
      v-if="ready && hasData"
      :type="type"
      :height="height"
      :options="mergedOptions"
      :series="series"
    />
    <px-empty-state
      v-else-if="ready && !hasData"
      inline
      icon="bar-chart-3"
      :title="emptyTitle"
      description="Sin movimientos en el rango y almacén seleccionados."
    />
    <div v-else class="pxaf-skeleton"><px-skeleton variant="block" height="100%" /></div>
    <template v-if="$slots.footer" #footer><slot name="footer" /></template>
  </px-chart-frame>
</template>

<script>
import VueApexCharts from "vue-apexcharts";
import { PxChartFrame, PxEmptyState } from "@/components/px-next";

// ApexCharts re-estilado a los tokens px-next (color, tipografía, motion).
// Se conserva la librería de charts del proyecto; sólo cambia el estilo.
// El color se toma de la paleta de data-viz --pxn-chart-* (NO de las semánticas).
function cssVar(name, fallback) {
  try {
    // Resolver contra un nodo dentro de .px-next (donde viven los tokens),
    // con fallback al root para --primary-color del shell.
    const scope = document.querySelector(".px-next") || document.documentElement;
    const v = getComputedStyle(scope).getPropertyValue(name).trim();
    if (v) return v;
    const rootV = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return rootV || fallback;
  } catch (e) { return fallback; }
}

// Rampa categórica px-next resuelta a color real (Apex no acepta var()).
// Slot 1 = acento del tenant (--primary-color, doble indirección poco fiable
// vía getComputedStyle → se lee directo). Slots 2–8 son hex literales.
function chartRamp() {
  const primary = cssVar("--primary-color", "#6d28d9");
  const fb = ["#3d4859", "#2e7d5b", "#b9761c", "#b23a5b", "#6a7f2e", "#8a5a44", "#b0568f"];
  const tail = fb.map((f, i) => cssVar(`--pxn-chart-${i + 2}`, f) || f);
  return [primary, ...tail];
}

export default {
  name: "PxApexFrame",
  components: { apexchart: VueApexCharts, PxChartFrame, PxEmptyState },
  props: {
    title: { type: String, default: null },
    unit: { type: String, default: null },
    type: { type: String, default: "bar" },
    series: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
    legend: { type: Array, default: () => [] },
    height: { type: [Number, String], default: 240 },
    emptyTitle: { type: String, default: "Sin datos para graficar" }
  },
  data() {
    return { ready: false };
  },
  computed: {
    hasData() {
      // Series planas (pie/donut): [n, n, ...]. Series con nombre (bar/area/line):
      // [{ name, data: [n, ...] }, ...].
      return this.series.some(s => {
        if (typeof s === "number") return Number(s) !== 0;
        const d = Array.isArray(s) ? s : s && s.data;
        return Array.isArray(d) ? d.some(v => Number(v)) : Number(d) !== 0;
      });
    },
    baseOptions() {
      const ramp = chartRamp();
      const ink3 = cssVar("--pxn-chart-label", "#838d9b");
      const grid = cssVar("--pxn-chart-grid", "rgba(18,24,40,0.08)");
      const axis = cssVar("--pxn-chart-axis", "rgba(18,24,40,0.14)");
      const reduced = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
      return {
        chart: {
          fontFamily: "inherit",
          foreColor: ink3,
          toolbar: { show: false },
          animations: { enabled: !reduced, speed: 240, easing: "easeout" },
          parentHeightOffset: 0
        },
        colors: ramp,
        grid: { borderColor: grid, strokeDashArray: 0, padding: { left: 4, right: 4 } },
        dataLabels: { enabled: false },
        stroke: { width: this.type === "area" || this.type === "line" ? 2 : 0, curve: "smooth" },
        fill: this.type === "area"
          ? { type: "gradient", gradient: { shadeIntensity: 0.1, opacityFrom: 0.22, opacityTo: 0.02, stops: [0, 100] } }
          : { opacity: 1 },
        plotOptions: {
          bar: { borderRadius: 4, columnWidth: "55%", borderRadiusApplication: "end" },
          pie: { donut: { size: "68%" } }
        },
        legend: { show: false },
        tooltip: {
          theme: "light",
          style: { fontFamily: "inherit", fontSize: "12px" },
          y: { formatter: v => (typeof v === "number" ? v.toLocaleString() : v) }
        },
        xaxis: {
          axisBorder: { color: axis },
          axisTicks: { color: axis },
          tickPlacement: "on",
          labels: {
            style: { colors: ink3, fontSize: "11px" },
            rotate: -45,
            rotateAlways: false,
            hideOverlappingLabels: true,
            trim: false
          }
        },
        yaxis: {
          labels: {
            style: { colors: ink3, fontSize: "11px" },
            formatter: v => {
              if (typeof v !== "number" || !isFinite(v)) return v;
              const n = Math.abs(v);
              if (n >= 1e6) return (v / 1e6).toFixed(1) + "M";
              if (n >= 1e3) return (v / 1e3).toFixed(0) + "k";
              return Math.round(v);
            }
          }
        },
        states: { hover: { filter: { type: "darken", value: 0.9 } } }
      };
    },
    mergedOptions() {
      return this.deepMerge(this.baseOptions, this.options || {});
    }
  },
  mounted() {
    this.$nextTick(() => { this.ready = true; });
  },
  methods: {
    deepMerge(a, b) {
      const out = Array.isArray(a) ? a.slice() : { ...a };
      Object.keys(b || {}).forEach(k => {
        if (b[k] && typeof b[k] === "object" && !Array.isArray(b[k]) && a[k] && typeof a[k] === "object") {
          out[k] = this.deepMerge(a[k], b[k]);
        } else {
          out[k] = b[k];
        }
      });
      return out;
    }
  }
};
</script>

<style lang="scss" scoped>
.pxaf-skeleton { height: 100%; min-height: 160px; }
</style>
