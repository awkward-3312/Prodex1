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
import Vue from "vue";

// BV2 corre aquí bajo @vue/compat y necesita dos adaptaciones que ya NO existen en la aplicación (fase 5B las retiró con el último formulario de BV2):
//  - BFormSelect / BFormCheckbox / BFormRadio pintan `<select>` / `<input>` con `directives: [{ name: 'model' }]`: en Vue 3 esa directiva exige
//    `onUpdate:modelValue` en el vnode ("el[assignKey] is not a function"); se añade un asignador vacío (BV2 ya actualiza su valor con su `change`).
//  - `v-b-visible` (BFormTextarea con `max-rows`) y `v-b-hover` (BFormDatepicker) traen hooks de Vue 2 (`bind/componentUpdated/unbind`).
const noop = () => {};
function markModelInputs(vnode) {
  if (!vnode || typeof vnode !== "object") return;
  if (Array.isArray(vnode)) { vnode.forEach(markModelInputs); return; }
  if (typeof vnode.type === "string" && vnode.dirs) {
    vnode.props = vnode.props || {};
    if (!vnode.props["onUpdate:modelValue"]) vnode.props["onUpdate:modelValue"] = noop;
  }
  if (Array.isArray(vnode.children)) vnode.children.forEach(markModelInputs);
}
let patched = false;
function patchBootstrapVue2ForProbe() {
  if (patched || !String(Vue.version).startsWith("3")) return;
  patched = true;
  [BV2.VBVisible, BV2.VBHover].forEach((directive) => {
    if (!directive || directive.mounted) return;
    directive.mounted = directive.bind;
    directive.updated = directive.componentUpdated;
    directive.unmounted = directive.unbind;
    delete directive.bind; delete directive.componentUpdated; delete directive.unbind;
  });
  [BV2.BFormSelect, BV2.BFormCheckbox, BV2.BFormRadio].forEach((Component) => {
    const options = Component && (Component.options || Component);
    if (!options || typeof options.render !== "function" || options.render.__pxCompatPatched) return;
    const original = options.render;
    options.render = function patchedRender(h) { const vnode = original.call(this, h); markModelInputs(vnode); return vnode; };
    options.render.__pxCompatPatched = true;
  });
}
patchBootstrapVue2ForProbe();

const isComponent = (v) => v && typeof v === "object" && (v.name || v.__name || v.setup || v.render);

export default {
  name: "BvProbe",
  data() {
    return { def: null, n: 0 };
  },
  mounted() {
    window.__pxProbe = async (template, { bvn = false, data = {}, methods = {}, watch = {}, created = null, wait = 80 } = {}) => {
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
      if (Object.keys(watch).length) this.def.watch = Object.fromEntries(Object.entries(watch).map(([k, src]) => [k, new Function(`return (${src})`)()]));
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
    // instancia de la plantilla montada (para llamar a sus métodos: `$refs.obs.validate()`, `setErrors`…)
    window.__pxProbeInner = () => this.$refs.inner;
  },
  beforeUnmount() {
    delete window.__pxProbe;
    delete window.__pxProbeVm;
    delete window.__pxProbeData;
    delete window.__pxProbeInner;
  },
};
</script>
