<template>
  <!--
    C2B — envoltorio compartido de vue-select integrado con px-next.
    · Presentación española (estado sin opciones, aria-labels).
    · Anclado robusto: append-to-body + calculate-position propia
      (position:fixed, ancho = trigger, flip vertical, clamp al viewport,
      max-height con scroll interno). No lo recorta ningún card/overflow.
    · Funcionalidad intacta: v-model, búsqueda, clear, multiple, chips,
      teclado/Enter/Esc/Tab pasan tal cual (v-bind="$attrs" / v-on="$listeners").
    No cambia datos ni el value emitido: solo label/presentación.
  -->
  <v-select
    ref="vs"
    class="vspx"
    :class="{ 'vspx--invalid': invalid, 'vspx--disabled': disabled }"
    append-to-body
    :calculate-position="positionDropdown"
    :disabled="disabled"
    v-bind="$attrs"
    v-on="$listeners"
  >
    <template #no-options="slotProps">
      <slot name="no-options" v-bind="slotProps">No hay opciones disponibles</slot>
    </template>
  </v-select>
</template>

<script>
export default {
  name: "VsPx",
  inheritAttrs: false,
  props: {
    invalid: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false }
  },
  mounted() {
    this.$nextTick(this.localizeAria);
  },
  updated() {
    this.localizeAria();
  },
  methods: {
    localizeAria() {
      const root = this.$refs.vs && this.$refs.vs.$el;
      if (!root) return;
      const combo = root.querySelector(".vs__dropdown-toggle[aria-label]");
      if (combo) combo.setAttribute("aria-label", "Elegir opción");
      const search = root.querySelector(".vs__search");
      if (search) search.setAttribute("aria-label", "Buscar opción");
      const clear = root.querySelector(".vs__clear");
      if (clear) clear.setAttribute("aria-label", "Limpiar selección");
      root.querySelectorAll(".vs__deselect").forEach(el => el.setAttribute("aria-label", "Quitar"));
    },
    positionDropdown(dropdownList, component) {
      const toggle = component.$refs.toggle;
      dropdownList.classList.add("px-next", "vspx__menu", "pxn-scroll");
      const GAP = 4;
      const MARGIN = 8;
      const place = () => {
        if (!toggle) return;
        const r = toggle.getBoundingClientRect();
        const vw = document.documentElement.clientWidth;
        const vh = window.innerHeight;
        dropdownList.style.position = "fixed";
        dropdownList.style.width = Math.round(r.width) + "px";
        let left = r.left;
        if (left + r.width > vw - MARGIN) left = vw - MARGIN - r.width;
        if (left < MARGIN) left = MARGIN;
        dropdownList.style.left = Math.round(left) + "px";
        dropdownList.style.maxHeight = "";
        const natH = dropdownList.scrollHeight;
        const below = vh - r.bottom - GAP - MARGIN;
        const above = r.top - GAP - MARGIN;
        if (natH <= below || below >= above) {
          component.$el.classList.remove("vspx--up");
          dropdownList.style.top = Math.round(r.bottom + GAP) + "px";
          dropdownList.style.maxHeight = Math.round(Math.max(120, below)) + "px";
        } else {
          component.$el.classList.add("vspx--up");
          const h = Math.min(natH, Math.max(120, above));
          dropdownList.style.top = Math.round(r.top - GAP - h) + "px";
          dropdownList.style.maxHeight = Math.round(h) + "px";
        }
      };
      place();
      const on = () => place();
      window.addEventListener("resize", on);
      window.addEventListener("scroll", on, true);
      return () => {
        window.removeEventListener("resize", on);
        window.removeEventListener("scroll", on, true);
      };
    }
  }
};
</script>

<style lang="scss" scoped>
.vspx { width: 100%; }

/* ---- trigger / control (en árbol) ---- */
.vspx :deep(.vs__dropdown-toggle) {
  min-height: var(--pxn-control-h-md);
  padding: 0 var(--pxn-space-3) 3px;
  border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
  transition: border-color var(--pxn-dur-1) var(--pxn-ease), box-shadow var(--pxn-dur-1) var(--pxn-ease);
}
.vspx :deep(.vs__selected-options) {
  padding: 0;
  gap: var(--pxn-space-2);
  align-items: center;
}
.vspx :deep(.vs__search),
.vspx :deep(.vs__search:focus) {
  margin: 0;
  padding: 0 var(--pxn-space-2);
  color: var(--pxn-ink);
  font: inherit;
  font-size: var(--pxn-fs-body);
  line-height: var(--pxn-control-h-md);
}
.vspx :deep(.vs__search::placeholder) { color: var(--pxn-ink-3); }

/* valor seleccionado legible (single): sin empujar layout, sin quedar casi invisible */
.vspx :deep(.vs__selected) {
  margin: 0;
  padding: 0 var(--pxn-space-2);
  color: var(--pxn-ink);
  font-size: var(--pxn-fs-body);
}
.vspx.vs--single.vs--open :deep(.vs__selected) {
  position: absolute;
  opacity: 0.7;
}
/* chips (multiple) alineados */
.vspx.vs--multiple :deep(.vs__selected) {
  background: var(--pxn-primary-soft);
  color: var(--pxn-primary-ink);
  border: 1px solid var(--pxn-primary-border);
  border-radius: var(--pxn-radius-sm);
  padding: 2px var(--pxn-space-2) 2px var(--pxn-space-3);
  margin: 3px 0;
}
.vspx :deep(.vs__deselect) {
  margin-left: var(--pxn-space-2);
  fill: var(--pxn-primary-ink);
  opacity: 0.7;
}
.vspx :deep(.vs__deselect:hover) { opacity: 1; }

/* iconos clear / chevron alineados verticalmente y con el ink correcto */
.vspx :deep(.vs__actions) {
  padding: 0 var(--pxn-space-2) 0 var(--pxn-space-1);
  gap: var(--pxn-space-1);
  align-items: center;
}
.vspx :deep(.vs__open-indicator),
.vspx :deep(.vs__clear) { fill: var(--pxn-ink-3); }
.vspx :deep(.vs__clear:hover),
.vspx :deep(.vs__open-indicator:hover) { fill: var(--pxn-ink-2); }

/* un solo focus ring px-next, sin doble borde */
.vspx.vs--open :deep(.vs__dropdown-toggle),
.vspx:focus-within :deep(.vs__dropdown-toggle) {
  border-color: var(--pxn-primary);
  box-shadow: 0 0 0 3px var(--pxn-primary-soft);
  outline: none;
}
.vspx :deep(.vs__dropdown-toggle:focus) { outline: none; }

.vspx.vspx--invalid :deep(.vs__dropdown-toggle) { border-color: var(--pxn-danger); }
.vspx.vspx--invalid:focus-within :deep(.vs__dropdown-toggle) { box-shadow: 0 0 0 3px var(--pxn-danger-soft); }

.vspx.vspx--disabled :deep(.vs__dropdown-toggle),
.vspx.vs--disabled :deep(.vs__dropdown-toggle) {
  background: var(--pxn-surface-3);
  cursor: not-allowed;
}
.vspx.vs--disabled :deep(.vs__search),
.vspx.vs--disabled :deep(.vs__open-indicator) { background: transparent; }

/* vue-select dibuja el caret hacia arriba cuando abre hacia arriba */
.vspx.vspx--up :deep(.vs__dropdown-toggle) { border-radius: var(--pxn-radius-md); }
</style>

<style lang="scss">
/* ---- menú (append-to-body: fuera de .px-next del árbol, por eso NO scoped;
   el elemento lleva la clase .px-next para resolver los tokens --pxn-*) ---- */
.vspx__menu.vs__dropdown-menu {
  z-index: var(--pxn-z-dropdown, 1200);
  padding: var(--pxn-space-2);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
  box-shadow: var(--pxn-shadow-menu, 0 8px 24px rgba(16, 24, 40, 0.12));
  overflow-y: auto;
}
.vspx__menu .vs__dropdown-option {
  padding: var(--pxn-space-3) var(--pxn-space-4);
  border-radius: var(--pxn-radius-sm);
  color: var(--pxn-ink);
  font-size: var(--pxn-fs-body);
}
.vspx__menu .vs__dropdown-option--highlight {
  background: var(--pxn-primary-soft);
  color: var(--pxn-primary-ink);
}
.vspx__menu .vs__dropdown-option--selected { font-weight: var(--pxn-fw-semibold); }
.vspx__menu .vs__no-options {
  padding: var(--pxn-space-4);
  text-align: left;
  color: var(--pxn-ink-3);
  font-size: var(--pxn-fs-sm);
}
</style>
