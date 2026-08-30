<template>
  <div class="pxn-menu" v-click-outside="close">
    <span ref="trigger" class="pxn-menu__trigger" @click="toggle" @keydown.down.prevent="open">
      <slot name="trigger" :open="isOpen" :toggle="toggle" />
    </span>

    <transition name="pxn-menu-pop">
      <div
        v-if="isOpen"
        ref="panel"
        class="pxn-menu__panel pxn-scroll"
        :class="`is-${align}`"
        role="menu"
        @keydown.esc="close"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
      >
        <template v-for="(item, i) in items">
          <div v-if="item.divider" :key="`d${i}`" class="pxn-menu__divider" role="separator"></div>
          <div v-else-if="item.header" :key="`h${i}`" class="pxn-menu__header">{{ item.header }}</div>
          <button
            v-else
            :key="`i${i}`"
            ref="item"
            class="pxn-menu__item pxn-ring"
            :class="{ 'is-danger': item.tone === 'danger', 'is-disabled': item.disabled }"
            role="menuitem"
            type="button"
            :disabled="item.disabled"
            @click="pick(item)"
          >
            <lucide-icon v-if="item.icon" :name="item.icon" :size="15" class="pxn-menu__ico" />
            <span class="pxn-menu__label">{{ item.label }}</span>
            <span v-if="item.hint" class="pxn-menu__hint pxn-mono">{{ item.hint }}</span>
          </button>
        </template>
      </div>
    </transition>
  </div>
</template>

<script>
// Floating action menu (used by PxKebab and any "more actions" trigger).
// Floating → it gets a shadow. Opens with a short scale+fade+rise; reduced
// motion drops the movement (see _base.scss global override).
export default {
  name: "PxMenu",
  directives: {
    "click-outside": {
      bind(el, binding) {
        el.__pxnOutside = e => { if (!el.contains(e.target)) binding.value(e); };
        setTimeout(() => document.addEventListener("click", el.__pxnOutside), 0);
      },
      unbind(el) { document.removeEventListener("click", el.__pxnOutside); }
    }
  },
  props: {
    items: { type: Array, required: true }, // [{ label, icon?, hint?, tone?, disabled? } | { divider:true } | { header:'…' }]
    align: { type: String, default: "end" } // start | end
  },
  data() { return { isOpen: false }; },
  methods: {
    toggle() { this.isOpen ? this.close() : this.open(); },
    open() {
      this.isOpen = true;
      this.$nextTick(() => {
        const first = (this.$refs.item || []).find(b => !b.disabled);
        if (first) first.focus();
      });
    },
    close() {
      if (!this.isOpen) return;
      this.isOpen = false;
      const t = this.$refs.trigger;
      if (t && t.firstElementChild) t.firstElementChild.focus();
    },
    move(dir) {
      const btns = (this.$refs.item || []).filter(b => !b.disabled);
      if (!btns.length) return;
      const cur = btns.indexOf(document.activeElement);
      const next = (cur + dir + btns.length) % btns.length;
      btns[next].focus();
    },
    pick(item) {
      if (item.disabled) return;
      this.$emit("select", item);
      this.close();
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-menu { position: relative; display: inline-flex; }
.pxn-menu__trigger { display: inline-flex; }
.pxn-menu__panel {
  position: absolute;
  top: calc(100% + 6px);
  min-width: 200px;
  max-height: 320px;
  overflow-y: auto;
  padding: var(--pxn-space-3);
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md);
  box-shadow: var(--pxn-shadow-menu);
  z-index: var(--pxn-z-menu);
}
.pxn-menu__panel.is-end { right: 0; }
.pxn-menu__panel.is-start { left: 0; }

.pxn-menu__item {
  display: flex;
  align-items: center;
  gap: var(--pxn-space-4);
  width: 100%;
  padding: var(--pxn-space-4) var(--pxn-space-4);
  border: 0;
  border-radius: var(--pxn-radius-sm);
  background: transparent;
  font: inherit;
  font-size: var(--pxn-fs-body);
  color: var(--pxn-ink);
  text-align: left;
  cursor: pointer;
}
.pxn-menu__item:hover:not(.is-disabled) { background: var(--pxn-surface-2); }
.pxn-menu__item.is-danger { color: var(--pxn-danger-ink); }
.pxn-menu__item.is-danger:hover { background: var(--pxn-danger-soft); }
.pxn-menu__item.is-disabled { color: var(--pxn-ink-disabled); cursor: not-allowed; }
.pxn-menu__ico { flex: none; color: var(--pxn-ink-3); }
.is-danger .pxn-menu__ico { color: currentColor; }
.pxn-menu__label { flex: 1; }
.pxn-menu__hint { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxn-menu__divider { height: 1px; margin: var(--pxn-space-3) 0; background: var(--pxn-border); }
.pxn-menu__header {
  padding: var(--pxn-space-3) var(--pxn-space-4) var(--pxn-space-2);
  font-size: var(--pxn-fs-xs);
  font-weight: var(--pxn-fw-semibold);
  color: var(--pxn-ink-3);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.pxn-menu-pop-enter-active { transition: opacity var(--pxn-dur-2) var(--pxn-ease-out), transform var(--pxn-dur-2) var(--pxn-ease-out); }
.pxn-menu-pop-leave-active { transition: opacity var(--pxn-dur-1) var(--pxn-ease-in), transform var(--pxn-dur-1) var(--pxn-ease-in); }
.pxn-menu-pop-enter, .pxn-menu-pop-leave-to { opacity: 0; transform: translateY(-4px) scale(0.98); }
</style>
