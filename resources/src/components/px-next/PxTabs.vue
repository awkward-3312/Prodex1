<template>
  <div class="pxn-tabs" :class="`pxn-tabs--${variant}`" role="tablist">
    <button
      v-for="t in tabs"
      :key="t.value"
      ref="tab"
      class="pxn-tabs__tab pxn-ring"
      :class="{ 'is-active': t.value === value, 'is-disabled': t.disabled }"
      role="tab"
      type="button"
      :aria-selected="t.value === value ? 'true' : 'false'"
      :disabled="t.disabled"
      @click="select(t)"
      @keydown="onKey($event)"
    >
      <lucide-icon v-if="t.icon" :name="t.icon" :size="15" />
      <span>{{ t.label }}</span>
      <span v-if="t.count != null" class="pxn-tabs__count pxn-num">{{ t.count }}</span>
    </button>
  </div>
</template>

<script>
// Tabs. `line` = underline indicator that slides; `pill` = segmented control.
// Keyboard: arrow keys move focus + selection, Home/End jump.
export default {
  name: "PxTabs",
  props: {
    tabs: { type: Array, required: true }, // [{ value, label, icon?, count?, disabled? }]
    value: { type: [String, Number], required: true },
    variant: { type: String, default: "line" } // line | pill
  },
  methods: {
    select(t) { if (!t.disabled) this.$emit("input", t.value); },
    onKey(e) {
      const idx = this.tabs.findIndex(t => t.value === this.value);
      let next = idx;
      if (e.key === "ArrowRight" || e.key === "ArrowDown") next = (idx + 1) % this.tabs.length;
      else if (e.key === "ArrowLeft" || e.key === "ArrowUp") next = (idx - 1 + this.tabs.length) % this.tabs.length;
      else if (e.key === "Home") next = 0;
      else if (e.key === "End") next = this.tabs.length - 1;
      else return;
      e.preventDefault();
      this.$emit("input", this.tabs[next].value);
      this.$nextTick(() => this.$refs.tab[next] && this.$refs.tab[next].focus());
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-tabs { display: flex; align-items: center; gap: var(--pxn-space-2); max-width: 100%; overflow-x: auto; scrollbar-width: none; }
.pxn-tabs::-webkit-scrollbar { display: none; }
.pxn-tabs--pill { display: inline-flex; overflow: visible; }
.pxn-tabs__tab {
  display: inline-flex;
  flex: none;
  align-items: center;
  gap: var(--pxn-space-3);
  height: 34px;
  padding: 0 var(--pxn-space-5);
  border: 0;
  background: transparent;
  font: inherit;
  font-size: var(--pxn-fs-body);
  font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink-2);
  cursor: pointer;
  white-space: nowrap;
  transition: color var(--pxn-dur-1) var(--pxn-ease), background-color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-tabs__tab:hover:not(.is-disabled) { color: var(--pxn-ink); }
.pxn-tabs__tab.is-disabled { color: var(--pxn-ink-disabled); cursor: not-allowed; }
.pxn-tabs__count {
  padding: 1px 6px;
  border-radius: var(--pxn-radius-pill);
  background: var(--pxn-surface-3);
  color: var(--pxn-ink-2);
  font-size: var(--pxn-fs-xs);
  font-weight: var(--pxn-fw-semibold);
}

/* line */
.pxn-tabs--line { gap: var(--pxn-space-5); border-bottom: 1px solid var(--pxn-border); }
.pxn-tabs--line .pxn-tabs__tab {
  padding: 0 var(--pxn-space-2);
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  transition: color var(--pxn-dur-1) var(--pxn-ease), border-color var(--pxn-dur-2) var(--pxn-ease);
}
.pxn-tabs--line .pxn-tabs__tab.is-active { color: var(--pxn-primary-ink); border-bottom-color: var(--pxn-primary); }

/* pill / segmented */
.pxn-tabs--pill {
  padding: 3px;
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface-2);
  border: 1px solid var(--pxn-border);
}
.pxn-tabs--pill .pxn-tabs__tab { height: 30px; border-radius: var(--pxn-radius-sm); }
.pxn-tabs--pill .pxn-tabs__tab.is-active {
  background: var(--pxn-surface);
  color: var(--pxn-ink);
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.08);
}
</style>
