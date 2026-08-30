<template>
  <div class="pxn-select-wrap" v-click-outside="close">
    <button
      :id="id"
      ref="trigger"
      type="button"
      class="pxn-select pxn-ring"
      :class="{ 'is-open': open, 'is-placeholder': !selected }"
      :disabled="disabled"
      role="combobox"
      aria-haspopup="listbox"
      :aria-expanded="open ? 'true' : 'false'"
      :aria-controls="open ? listId : null"
      :aria-invalid="invalid ? 'true' : null"
      :aria-describedby="describedby"
      @click="toggle"
      @keydown="onTriggerKey"
    >
      <span class="pxn-select__value">{{ selected ? selected.label : (placeholder || '') }}</span>
      <lucide-icon name="chevron-down" :size="15" class="pxn-select__chev" />
    </button>

    <transition name="pxn-menu-pop">
      <ul
        v-if="open"
        :id="listId"
        ref="panel"
        class="pxn-select__panel pxn-scroll"
        :class="{ 'is-up': dropUp }"
        role="listbox"
        :aria-activedescendant="activeIndex >= 0 ? optionId(activeIndex) : null"
        tabindex="-1"
        @keydown="onPanelKey"
      >
        <li
          v-for="(opt, i) in normalized"
          :id="optionId(i)"
          :key="`${opt.value}-${i}`"
          class="pxn-select__opt"
          :class="{ 'is-active': i === activeIndex, 'is-selected': isSelected(opt) }"
          role="option"
          :aria-selected="isSelected(opt) ? 'true' : 'false'"
          @click="pick(opt)"
          @mousemove="activeIndex = i"
        >
          <span class="pxn-select__opt-label">{{ opt.label }}</span>
          <lucide-icon v-if="isSelected(opt)" name="check" :size="14" class="pxn-select__opt-check" />
        </li>
        <li v-if="!normalized.length" class="pxn-select__empty">Sin opciones</li>
      </ul>
    </transition>
  </div>
</template>

<script>
// px-next select — listbox propio (NO <select> nativo). El popup del <select>
// nativo lo dibuja el SO: no se puede anclar, dimensionar, limitar en altura ni
// llevar por encima de un modal de forma consistente dentro del shell. Este
// primitive resuelve todo eso: panel en position:fixed anclado al trigger (sin
// portal — el nodo vive en el subárbol del componente), con flip vertical,
// clamp al viewport, altura máxima con scroll, teclado y ARIA de listbox.

let seq = 0;

export default {
  name: "PxSelect",
  directives: {
    // Mismo patrón que PxMenu: cierre al hacer click fuera del componente.
    "click-outside": {
      bind(el, binding) {
        el.__pxnOutside = e => { if (!el.contains(e.target)) binding.value(e); };
        setTimeout(() => document.addEventListener("click", el.__pxnOutside), 0);
      },
      unbind(el) { document.removeEventListener("click", el.__pxnOutside); }
    }
  },
  props: {
    value: { type: [String, Number], default: "" },
    options: { type: Array, default: () => [] },
    id: { type: String, default: null },
    placeholder: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    describedby: { type: String, default: null }
  },
  data() {
    seq += 1;
    return { open: false, dropUp: false, activeIndex: -1, uid: `pxn-sel-${seq}`, typeahead: "", typeaheadTimer: null };
  },
  computed: {
    normalized() {
      return this.options.map(o => (o && typeof o === "object" ? { value: o.value, label: String(o.label) } : { value: o, label: String(o) }));
    },
    selected() {
      return this.normalized.find(o => String(o.value) === String(this.value)) || null;
    },
    listId() { return `${this.uid}-list`; }
  },
  watch: {
    disabled(v) { if (v) this.close(); }
  },
  beforeDestroy() {
    this.teardownListeners();
    if (this.typeaheadTimer) clearTimeout(this.typeaheadTimer);
  },
  methods: {
    optionId(i) { return `${this.uid}-opt-${i}`; },
    isSelected(opt) { return String(opt.value) === String(this.value); },

    toggle() { this.open ? this.close() : this.openPanel(); },
    openPanel() {
      if (this.disabled || this.open) return;
      this.open = true;
      const sel = this.normalized.findIndex(o => String(o.value) === String(this.value));
      this.activeIndex = sel >= 0 ? sel : (this.normalized.length ? 0 : -1);
      this.$nextTick(() => {
        this.position();
        this.setupListeners();
        this.scrollActiveIntoView();
      });
    },
    close() {
      if (!this.open) return;
      this.open = false;
      this.teardownListeners();
    },
    closeAndFocus() {
      const wasOpen = this.open;
      this.close();
      if (wasOpen && this.$refs.trigger) this.$refs.trigger.focus();
    },

    setupListeners() {
      this._onScroll = () => this.schedulePosition();
      this._onResize = () => this.schedulePosition();
      window.addEventListener("scroll", this._onScroll, true);
      window.addEventListener("resize", this._onResize);
    },
    teardownListeners() {
      if (this._onScroll) window.removeEventListener("scroll", this._onScroll, true);
      if (this._onResize) window.removeEventListener("resize", this._onResize);
      this._onScroll = this._onResize = null;
      if (this._raf) { cancelAnimationFrame(this._raf); this._raf = null; }
    },
    schedulePosition() {
      if (this._raf) return;
      this._raf = requestAnimationFrame(() => { this._raf = null; this.position(); });
    },

    position() {
      const trigger = this.$refs.trigger;
      const panel = this.$refs.panel;
      if (!trigger || !panel) return;

      const tr = trigger.getBoundingClientRect();
      const vw = document.documentElement.clientWidth;
      const vh = window.innerHeight;
      const GAP = 4;
      const MARGIN = 8;

      // Ancho: al menos el del trigger; puede crecer con el contenido, nunca
      // más que el viewport. Se mide con offsetWidth (ignora el transform de la
      // transición de entrada).
      panel.style.minWidth = `${Math.round(tr.width)}px`;
      panel.style.maxWidth = `${Math.round(vw - MARGIN * 2)}px`;
      panel.style.width = "";
      const natW = panel.offsetWidth;
      const panelW = Math.min(Math.max(natW, tr.width), vw - MARGIN * 2);

      let left = tr.left;
      if (left + panelW > vw - MARGIN) left = vw - MARGIN - panelW;
      if (left < MARGIN) left = MARGIN;

      const spaceBelow = vh - tr.bottom - GAP - MARGIN;
      const spaceAbove = tr.top - GAP - MARGIN;
      panel.style.maxHeight = "";
      const natH = panel.scrollHeight;

      let top;
      let maxH;
      if (natH <= spaceBelow || spaceBelow >= spaceAbove) {
        this.dropUp = false;
        top = tr.bottom + GAP;
        maxH = Math.max(120, spaceBelow);
      } else {
        this.dropUp = true;
        maxH = Math.max(120, spaceAbove);
        top = tr.top - GAP - Math.min(natH, maxH);
      }

      panel.style.left = `${Math.round(left)}px`;
      panel.style.top = `${Math.round(top)}px`;
      panel.style.width = `${Math.round(panelW)}px`;
      panel.style.maxHeight = `${Math.round(maxH)}px`;
    },

    pick(opt) {
      if (String(opt.value) !== String(this.value)) this.$emit("input", opt.value);
      this.closeAndFocus();
    },

    move(dir) {
      const n = this.normalized.length;
      if (!n) return;
      let i = this.activeIndex;
      i = (i + dir + n) % n;
      this.activeIndex = i;
      this.scrollActiveIntoView();
    },
    scrollActiveIntoView() {
      this.$nextTick(() => {
        const panel = this.$refs.panel;
        if (!panel || this.activeIndex < 0) return;
        const el = panel.children[this.activeIndex];
        if (el && el.scrollIntoView) el.scrollIntoView({ block: "nearest" });
      });
    },

    // Foco permanece en el trigger (patrón listbox con aria-activedescendant),
    // así que este handler cubre tanto el estado cerrado como el abierto.
    onTriggerKey(e) {
      const k = e.key;
      if (!this.open) {
        if (k === "ArrowDown" || k === "ArrowUp" || k === "Enter" || k === " " || k === "Spacebar") {
          e.preventDefault();
          e.stopPropagation();
          this.openPanel();
        } else if (k && k.length === 1) {
          e.stopPropagation();
          this.onType(k);
        }
        return;
      }
      // Con el panel abierto el select se queda con TODA la tecla: nunca debe
      // llegar a handlers de ancestros (p. ej. el Esc que cierra un PxModal).
      switch (k) {
        case "ArrowDown": e.preventDefault(); e.stopPropagation(); this.move(1); break;
        case "ArrowUp": e.preventDefault(); e.stopPropagation(); this.move(-1); break;
        case "Home": e.preventDefault(); e.stopPropagation(); this.activeIndex = 0; this.scrollActiveIntoView(); break;
        case "End": e.preventDefault(); e.stopPropagation(); this.activeIndex = this.normalized.length - 1; this.scrollActiveIntoView(); break;
        case "Enter":
        case " ":
        case "Spacebar":
          e.preventDefault();
          e.stopPropagation();
          if (this.activeIndex >= 0) this.pick(this.normalized[this.activeIndex]);
          break;
        case "Escape": e.preventDefault(); e.stopPropagation(); this.closeAndFocus(); break;
        case "Tab": this.close(); break;
        default:
          if (k && k.length === 1) { e.preventDefault(); e.stopPropagation(); this.onType(k); }
      }
    },
    onPanelKey(e) { this.onTriggerKey(e); },
    onType(ch) {
      this.typeahead = (this.typeahead + ch).toLowerCase();
      if (this.typeaheadTimer) clearTimeout(this.typeaheadTimer);
      this.typeaheadTimer = setTimeout(() => { this.typeahead = ""; }, 500);
      const match = this.normalized.findIndex(o => o.label.toLowerCase().startsWith(this.typeahead));
      if (match >= 0) {
        if (this.open) { this.activeIndex = match; this.scrollActiveIntoView(); }
        else { this.$emit("input", this.normalized[match].value); }
      }
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-select-wrap { position: relative; display: flex; }

.pxn-select {
  width: 100%;
  height: var(--pxn-control-h-md);
  display: inline-flex;
  align-items: center;
  gap: var(--pxn-space-4);
  padding: 0 calc(var(--pxn-space-5) + 20px) 0 var(--pxn-space-5);
  border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
  color: var(--pxn-ink);
  font: inherit;
  font-size: var(--pxn-fs-body);
  text-align: left;
  cursor: pointer;
  transition: border-color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-select:hover:not(:disabled) { border-color: var(--pxn-border-strong); }
.pxn-select.is-open { border-color: var(--pxn-primary); }
.pxn-select:disabled { background: var(--pxn-surface-3); color: var(--pxn-ink-disabled); cursor: not-allowed; }
.pxn-select.is-placeholder .pxn-select__value { color: var(--pxn-ink-3); }

.pxn-select__value {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pxn-select__chev {
  position: absolute;
  right: var(--pxn-space-5);
  top: 50%;
  transform: translateY(-50%);
  color: var(--pxn-ink-3);
  pointer-events: none;
  transition: transform var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-select.is-open .pxn-select__chev { transform: translateY(-50%) rotate(180deg); }

.pxn-select__panel {
  position: fixed;
  left: 0;
  top: 0;
  z-index: var(--pxn-z-dropdown, 1200);
  margin: 0;
  padding: var(--pxn-space-3);
  list-style: none;
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md);
  box-shadow: var(--pxn-shadow-menu);
  overflow-y: auto;
}

.pxn-select__opt {
  display: flex;
  align-items: center;
  gap: var(--pxn-space-4);
  padding: var(--pxn-space-4) var(--pxn-space-4);
  border-radius: var(--pxn-radius-sm);
  font-size: var(--pxn-fs-body);
  color: var(--pxn-ink);
  cursor: pointer;
}
.pxn-select__opt.is-active { background: var(--pxn-surface-2); }
.pxn-select__opt.is-selected { color: var(--pxn-primary-ink); font-weight: var(--pxn-fw-medium); }
.pxn-select__opt.is-selected.is-active { background: var(--pxn-primary-soft); }
.pxn-select__opt-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pxn-select__opt-check { flex: none; color: var(--pxn-primary); }
.pxn-select__empty { padding: var(--pxn-space-4); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }

.pxn-menu-pop-enter-active { transition: opacity var(--pxn-dur-2) var(--pxn-ease-out), transform var(--pxn-dur-2) var(--pxn-ease-out); }
.pxn-menu-pop-leave-active { transition: opacity var(--pxn-dur-1) var(--pxn-ease-in), transform var(--pxn-dur-1) var(--pxn-ease-in); }
.pxn-menu-pop-enter, .pxn-menu-pop-leave-to { opacity: 0; transform: translateY(-4px) scale(0.98); }
.pxn-select__panel.is-up.pxn-menu-pop-enter,
.pxn-select__panel.is-up.pxn-menu-pop-leave-to { transform: translateY(4px) scale(0.98); }
</style>
