<template>
  <div id="ui-probe" class="p-3">
    <h1 class="h6 text-muted">Sonda de componentes Bootstrap 5 / BootstrapVueNext (solo desarrollo)</h1>
    <component :is="def" v-if="def" :key="n" ref="inner" />
  </div>
</template>

<script>
// SOLO DESARROLLO (ruta /app/_ui?probe=ui, ver router.js; no existe en producción). Monta una plantilla con los wrappers de BootstrapVueNext
// (`platform/bootstrap`, registro local, como hacen las vistas) para que los E2E comprueben su DOM, su comportamiento y su aspecto sobre Bootstrap 5.
// `window.__pxProbe(template, { data, methods, watch, created, wait })` → { html, missing } (missing: etiquetas b-* sin wrapper). Antes de la fase 5C
// esta sonda también montaba BootstrapVue 2 para comparar; ese paquete ya no existe (los contratos medidos quedan en tests/e2e/data/*.json).
// `window.__pxCutover()` → diagnóstico de la hoja de estilos activa (Bootstrap 5 sí, Bootstrap 4 / bootstrap-vue.css no).
import * as bootstrap from "@/platform/bootstrap";

const isComponent = (v) => v && typeof v === "object" && (v.name || v.__name || v.setup || v.render);

function cutoverReport() {
  const texts = [];
  let sheets = 0;
  for (const sheet of Array.from(document.styleSheets)) {
    try {
      sheets += 1;
      texts.push(Array.from(sheet.cssRules).map((r) => r.cssText).join("\n"));
    } catch (e) { /* hoja de otro origen */ }
  }
  const css = texts.join("\n");
  const root = getComputedStyle(document.documentElement);
  const probe = document.createElement("div");
  document.body.appendChild(probe);
  const has = (selector) => css.includes(selector);
  const display = (klass) => { probe.className = klass; return getComputedStyle(probe).display; };
  const marginLeft = (klass) => { probe.className = klass; return getComputedStyle(probe).marginLeft; };
  const report = {
    sheets,
    bootstrap5: {
      rootVariables: !!root.getPropertyValue("--bs-body-font-family").trim() || has("--bs-body-font-family"),
      formSelectRule: has(".form-select"),
      formCheckRule: has(".form-check-input"),
      visuallyHidden: has(".visually-hidden"),
      utilityMs2: marginLeft("ms-2"),
    },
    bootstrap4: {
      customControlRule: has(".custom-control{") || has(".custom-control {"),
      customSelectRule: has(".custom-select{") || has(".custom-select {"),
      inputGroupPrependRule: has(".input-group-prepend"),
      formRowRule: has(".form-row"),
      srOnlyRule: has(".sr-only"),
      btnBlockDisplayFromClass: display("btn-block"),
    },
    bootstrapVue2: {
      avatarRule: has(".b-avatar"),
      spinbuttonRule: has(".b-form-spinbutton"),
      toastRule: has(".b-toast"),
    },
  };
  probe.remove();
  return report;
}

export default {
  name: "UiProbe",
  data() {
    return { def: null, n: 0 };
  },
  mounted() {
    window.__pxProbe = async (template, { data = {}, methods = {}, watch = {}, created = null, wait = 80 } = {}) => {
      const components = {};
      const directives = {};
      for (const [key, value] of Object.entries(bootstrap)) {
        if (key === "vBTooltip") directives["b-tooltip"] = value;
        else if (key === "vBToggle") directives["b-toggle"] = value;
        else if (key === "vBPopover") directives["b-popover"] = value;
        else if (/^B[A-Z]/.test(key) && isComponent(value)) components[key] = value;
      }
      const state = JSON.parse(JSON.stringify(data));
      this.def = {
        template: `<div class="probe-root">${template}</div>`,
        components,
        directives,
        data: () => state,
        methods: Object.fromEntries(Object.entries(methods).map(([k, src]) => [k, new Function(`return (${src})`)()])),
      };
      if (Object.keys(watch).length) this.def.watch = Object.fromEntries(Object.entries(watch).map(([k, src]) => [k, new Function(`return (${src})`)()]));
      if (created) this.def.created = new Function(`return (${created})`)();
      this.n += 1;
      await this.$nextTick();
      await new Promise((r) => setTimeout(r, wait));
      const root = this.$el.querySelector(".probe-root");
      const missing = [...new Set((template.match(/<b-[a-z0-9-]+/g) || []).map((t) => t.slice(1).split("-").map((x) => x[0].toUpperCase() + x.slice(1)).join("")))].filter((name) => !components[name]);
      return { html: root ? root.innerHTML : "", missing };
    };
    // datos REACTIVOS de la plantilla montada (contadores que muta la plantilla; también se pueden escribir desde el test)
    window.__pxProbeData = () => this.$refs.inner && this.$refs.inner.$data;
    window.__pxProbeVm = () => this.$el.querySelector(".probe-root");
    // instancia de la plantilla montada (para llamar a sus métodos: `$refs.obs.validate()`, `setErrors`…)
    window.__pxProbeInner = () => this.$refs.inner;
    window.__pxCutover = cutoverReport;
  },
  beforeUnmount() {
    delete window.__pxProbe;
    delete window.__pxProbeVm;
    delete window.__pxProbeData;
    delete window.__pxProbeInner;
    delete window.__pxCutover;
  },
};
</script>
