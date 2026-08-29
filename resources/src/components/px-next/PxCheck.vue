<template>
  <label class="pxn-check" :class="[`pxn-check--${type}`, { 'is-disabled': disabled }]">
    <input
      class="pxn-check__native pxn-ring"
      :type="type === 'switch' ? 'checkbox' : type"
      :checked="isChecked"
      :name="name"
      :value="nativeValue"
      :disabled="disabled"
      v-on="listeners"
    />
    <span class="pxn-check__box" aria-hidden="true">
      <lucide-icon v-if="type === 'checkbox'" name="check" :size="12" class="pxn-check__tick" />
      <span v-else-if="type === 'radio'" class="pxn-check__dot"></span>
      <span v-else class="pxn-check__knob"></span>
    </span>
    <span v-if="$slots.default" class="pxn-check__label"><slot /></span>
  </label>
</template>

<script>
// One component for checkbox / radio / switch — same label + focus behaviour.
export default {
  name: "PxCheck",
  model: { prop: "modelValue", event: "change" },
  props: {
    type: { type: String, default: "checkbox" }, // checkbox | radio | switch
    modelValue: { type: [Boolean, String, Number, Array], default: false },
    nativeValue: { type: [String, Number], default: null },
    name: { type: String, default: null },
    disabled: { type: Boolean, default: false }
  },
  computed: {
    isChecked() {
      if (Array.isArray(this.modelValue)) return this.modelValue.includes(this.nativeValue);
      if (this.type === "radio") return this.modelValue === this.nativeValue;
      return !!this.modelValue;
    },
    listeners() {
      return {
        ...this.$listeners,
        change: e => {
          if (Array.isArray(this.modelValue)) {
            const next = this.modelValue.slice();
            const i = next.indexOf(this.nativeValue);
            e.target.checked ? (i === -1 && next.push(this.nativeValue)) : (i > -1 && next.splice(i, 1));
            this.$emit("change", next);
          } else if (this.type === "radio") {
            this.$emit("change", this.nativeValue);
          } else {
            this.$emit("change", e.target.checked);
          }
        }
      };
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-check {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: var(--pxn-space-4);
  cursor: pointer;
  font-size: var(--pxn-fs-body);
  color: var(--pxn-ink);
  min-height: 24px;
}
.pxn-check.is-disabled { cursor: not-allowed; opacity: 0.55; }

.pxn-check__native {
  position: absolute;
  inset: 0;
  width: 100%; height: 100%;
  min-width: 24px; min-height: 24px;
  margin: 0;
  opacity: 0;
  cursor: inherit;
}
.pxn-check__box {
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px; height: 18px;
  border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-xs);
  background: var(--pxn-surface);
  color: transparent;
  transition: background-color var(--pxn-dur-1) var(--pxn-ease), border-color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-check--radio .pxn-check__box { border-radius: 999px; }
.pxn-check__native:hover + .pxn-check__box { border-color: var(--pxn-border-strong); }
.pxn-check__native:focus-visible + .pxn-check__box { box-shadow: 0 0 0 3px var(--pxn-focus-ring); }
.pxn-check__native:checked + .pxn-check__box {
  background: var(--pxn-primary);
  border-color: var(--pxn-primary);
  color: var(--pxn-primary-contrast);
}
.pxn-check__dot { width: 8px; height: 8px; border-radius: 999px; background: currentColor; }

/* switch */
.pxn-check--switch .pxn-check__box {
  width: 34px; height: 20px;
  border-radius: 999px;
  padding: 2px;
  justify-content: flex-start;
  background: var(--pxn-surface-3);
  border-color: transparent;
}
.pxn-check--switch .pxn-check__knob {
  width: 16px; height: 16px;
  border-radius: 999px;
  background: #fff;
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.28);
  transition: transform var(--pxn-dur-2) var(--pxn-ease);
}
.pxn-check--switch .pxn-check__native:checked + .pxn-check__box { background: var(--pxn-primary); }
.pxn-check--switch .pxn-check__native:checked + .pxn-check__box .pxn-check__knob { transform: translateX(14px); }
</style>
