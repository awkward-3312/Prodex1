<template>
  <div id="bv-probe" class="p-3">
    <h1 class="h6 text-muted">Sonda de contratos BootstrapVue 2 / BootstrapVueNext (solo desarrollo)</h1>
    <component :is="def" v-if="def" :key="n" ref="inner" />
  </div>
</template>

<script>
// SOLO DESARROLLO (ruta /app/_ui?probe=bv, ver router.js): monta una plantilla con los `b-*` de BootstrapVue 2 (globales) o con los wrappers de
// BootstrapVueNext (`platform/bootstrap`, registro local, como hacen las vistas) para que el E2E compare el DOM y el comportamiento de ambas.
// `window.__pxProbe(template, { bvn, data, methods })` → devuelve { html, missing } (missing: etiquetas b-* sin wrapper BVN). No existe en producción.
import * as bootstrap from "@/platform/bootstrap";
// BV2 completo (solo esta sonda de desarrollo): el registro global de la app ya solo tiene formularios; el lado "BV2" registra aquí todos los `b-*`.
import * as BV2 from "bootstrap-vue/dist/bootstrap-vue.esm.js";

const isComponent = (v) => v && typeof v === "object" && (v.name || v.__name || v.setup || v.render);

export default {
  name: "BvProbe",
  data() {
    return { def: null, n: 0 };
  },
  mounted() {
    window.__pxProbe = async (template, { bvn = false, data = {}, methods = {}, created = null, wait = 80 } = {}) => {
      const components = {};
      const directives = {};
      if (!bvn) {
        for (const [key, value] of Object.entries(BV2)) if (/^B[A-Z]/.test(key) && value && (typeof value === "object" || typeof value === "function") && !/Plugin$/.test(key)) components[key] = value;
        directives["b-tooltip"] = BV2.VBTooltip;
        directives["b-toggle"] = BV2.VBToggle;
      } else {
        for (const [key, value] of Object.entries(bootstrap)) {
          if (key === "vBTooltip") directives["b-tooltip"] = value;
          else if (key === "vBToggle") directives["b-toggle"] = value;
          else if (key === "vBPopover") directives["b-popover"] = value;
          else if (/^B[A-Z]/.test(key) && isComponent(value)) components[key] = value;
        }
      }
      const state = JSON.parse(JSON.stringify(data));
      this.def = {
        template: `<div class="probe-root">${template}</div>`,
        components,
        directives,
        data: () => state,
        methods: Object.fromEntries(Object.entries(methods).map(([k, src]) => [k, new Function(`return (${src})`)()])),
      };
      if (created) this.def.created = new Function(`return (${created})`)();
      this.n += 1;
      await this.$nextTick();
      await new Promise((r) => setTimeout(r, wait));
      const root = this.$el.querySelector(".probe-root");
      const missing = bvn ? [...new Set((template.match(/<b-[a-z0-9-]+/g) || []).map((t) => t.slice(1).split("-").map((x) => x[0].toUpperCase() + x.slice(1)).join("")))].filter((name) => !components[name]) : [];
      return { html: root ? root.innerHTML : "", missing };
    };
    // datos REACTIVOS de la plantilla montada (contadores que muta la plantilla; también se pueden escribir desde el test)
    window.__pxProbeData = () => this.$refs.inner && this.$refs.inner.$data;
    window.__pxProbeVm = () => this.$el.querySelector(".probe-root");
  },
  beforeUnmount() {
    delete window.__pxProbe;
    delete window.__pxProbeVm;
    delete window.__pxProbeData;
  },
};
</script>
