<template>
  <transition name="pxn-modal">
    <div
      v-if="value"
      class="pxn-modal__scrim"
      @mousedown.self="onScrim"
      @keydown.esc="close('esc')"
    >
      <div
        ref="dialog"
        class="pxn-modal"
        :class="`pxn-modal--${size}`"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="`${uid}-title`"
        tabindex="-1"
      >
        <header class="pxn-modal__head">
          <div>
            <h2 :id="`${uid}-title`" class="pxn-modal__title">{{ title }}</h2>
            <p v-if="subtitle" class="pxn-modal__subtitle">{{ subtitle }}</p>
          </div>
          <button type="button" class="pxn-modal__x pxn-ring" aria-label="Cerrar" @click="close('x')">
            <lucide-icon name="x" :size="16" />
          </button>
        </header>

        <div class="pxn-modal__body pxn-scroll"><slot /></div>

        <footer v-if="$slots.footer || $scopedSlots.footer" class="pxn-modal__foot"><slot name="footer" :close="() => close('footer')" /></footer>
      </div>
    </div>
  </transition>
</template>

<script>
// Floating surface → shadow + scrim. Focus is trapped; Esc and scrim close
// unless `persistent`. Entry: scrim fades, dialog rises 8px + fades.
export default {
  name: "PxModal",
  model: { prop: "value", event: "close" },
  props: {
    value: { type: Boolean, default: false },
    title: { type: String, default: "" },
    subtitle: { type: String, default: null },
    size: { type: String, default: "md" }, // sm | md | lg
    persistent: { type: Boolean, default: false }
  },
  data() { return { uid: `pxn-m-${this._uid}` }; },
  watch: {
    value(open) {
      if (open) {
        this._prevFocus = document.activeElement;
        document.addEventListener("keydown", this.trap, true);
        this.$nextTick(() => this.$refs.dialog && this.$refs.dialog.focus());
      } else {
        document.removeEventListener("keydown", this.trap, true);
        if (this._prevFocus && this._prevFocus.focus) this._prevFocus.focus();
      }
    }
  },
  beforeDestroy() { document.removeEventListener("keydown", this.trap, true); },
  methods: {
    close(reason) {
      if (this.persistent && (reason === "esc" || reason === "scrim")) return;
      this.$emit("close", false);
    },
    onScrim() { this.close("scrim"); },
    trap(e) {
      if (e.key !== "Tab" || !this.$refs.dialog) return;
      const f = this.$refs.dialog.querySelectorAll(
        'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'
      );
      if (!f.length) return;
      const first = f[0];
      const last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-modal__scrim {
  position: fixed;
  inset: 0;
  z-index: var(--pxn-z-modal);
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 10vh var(--pxn-space-6) var(--pxn-space-8);
  background: rgba(16, 24, 40, 0.42);
  overflow-y: auto;
}
.pxn-modal {
  width: 100%;
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-lg);
  box-shadow: var(--pxn-shadow-modal);
  outline: none;
  display: flex;
  flex-direction: column;
  max-height: 80vh;
}
.pxn-modal--sm { max-width: 400px; }
.pxn-modal--md { max-width: 540px; }
.pxn-modal--lg { max-width: 760px; }

.pxn-modal__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--pxn-space-5);
  padding: var(--pxn-space-6) var(--pxn-space-7);
  border-bottom: 1px solid var(--pxn-border);
}
.pxn-modal__title { font-size: var(--pxn-fs-h1); font-weight: var(--pxn-fw-semibold); letter-spacing: -0.015em; color: var(--pxn-ink); }
.pxn-modal__subtitle { margin-top: 2px; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxn-modal__x {
  flex: none;
  display: inline-flex; align-items: center; justify-content: center;
  width: 30px; height: 30px;
  border: 0; border-radius: var(--pxn-radius-sm);
  background: transparent; color: var(--pxn-ink-3);
  cursor: pointer;
}
.pxn-modal__x:hover { background: var(--pxn-surface-2); color: var(--pxn-ink); }
.pxn-modal__body { padding: var(--pxn-space-7); overflow-y: auto; font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); line-height: var(--pxn-lh-normal); }
.pxn-modal__foot {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--pxn-space-4);
  padding: var(--pxn-space-5) var(--pxn-space-7);
  border-top: 1px solid var(--pxn-border);
  background: var(--pxn-surface-2);
}

.pxn-modal-enter-active, .pxn-modal-leave-active { transition: opacity var(--pxn-dur-3) var(--pxn-ease); }
.pxn-modal-enter-active .pxn-modal, .pxn-modal-leave-active .pxn-modal { transition: opacity var(--pxn-dur-3) var(--pxn-ease), transform var(--pxn-dur-3) var(--pxn-ease); }
.pxn-modal-enter, .pxn-modal-leave-to { opacity: 0; }
.pxn-modal-enter .pxn-modal, .pxn-modal-leave-to .pxn-modal { opacity: 0; transform: translateY(8px); }
</style>
