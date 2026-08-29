<template>
  <div class="pxn-select-wrap">
    <select
      :id="id"
      class="pxn-select pxn-ring"
      :value="value"
      :disabled="disabled"
      :aria-invalid="invalid ? 'true' : null"
      :aria-describedby="describedby"
      v-on="listeners"
    >
      <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
      <option v-for="opt in normalized" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
    </select>
    <lucide-icon name="chevron-down" :size="15" class="pxn-select__chev" />
  </div>
</template>

<script>
export default {
  name: "PxSelect",
  props: {
    value: { type: [String, Number], default: "" },
    options: { type: Array, default: () => [] },
    id: { type: String, default: null },
    placeholder: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    describedby: { type: String, default: null }
  },
  computed: {
    normalized() {
      return this.options.map(o => (typeof o === "object" ? o : { value: o, label: o }));
    },
    listeners() {
      return { ...this.$listeners, change: e => this.$emit("input", e.target.value) };
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-select-wrap { position: relative; display: flex; }
.pxn-select {
  width: 100%;
  height: var(--pxn-control-h-md);
  padding: 0 calc(var(--pxn-space-5) + 20px) 0 var(--pxn-space-5);
  border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
  color: var(--pxn-ink);
  font: inherit;
  font-size: var(--pxn-fs-body);
  appearance: none;
  cursor: pointer;
  transition: border-color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-select:hover:not(:disabled) { border-color: var(--pxn-border-strong); }
.pxn-select:disabled { background: var(--pxn-surface-3); color: var(--pxn-ink-disabled); cursor: not-allowed; }
.pxn-select__chev {
  position: absolute;
  right: var(--pxn-space-5);
  top: 50%;
  transform: translateY(-50%);
  color: var(--pxn-ink-3);
  pointer-events: none;
}
</style>
