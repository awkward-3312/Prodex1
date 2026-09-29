<template>
  <label v-bind="plainAttrs()" class="pxn-check" :class="[`pxn-check--${type}`, { 'is-disabled': disabled, 'is-loading': loading }, $attrs.class]" :style="$attrs.style">
    <input
      v-bind="listeners()"
      class="pxn-check__native pxn-ring"
      :type="type === 'switch' ? 'checkbox' : type"
      :role="type === 'switch' ? 'switch' : null"
      :checked="isChecked"
      :name="name"
      :value="nativeValue"
      :disabled="disabled || loading"
      @change="onChange"
    />
    <span class="pxn-check__box" aria-hidden="true">
      <lucide-icon v-if="type === 'checkbox'" name="check" :size="12" class="pxn-check__tick" />
      <span v-else-if="type === 'radio'" class="pxn-check__dot"></span>
      <span v-else class="pxn-check__knob"><span v-if="loading" class="pxn-check__spin"></span></span>
    </span>
    <span v-if="$slots.default" class="pxn-check__label"><slot /></span>
  </label>
</template>

<script>
import { forwardListeners, forwardPlainAttrs } from "@/utils/forwardListeners";

// One component for checkbox / radio / switch — same label + focus behaviour.
export default {
  name: "PxCheck",
  inheritAttrs: false,
  // `v-model` nativo de Vue 3 siempre usa `modelValue`/`update:modelValue` (la opción `model:` de Vue 2 para
  // remapear el evento ya no existe/se ignora); se emite `update:modelValue` además de `change` (evento propio,
  // varios consumidores lo escuchan directo sin v-model).
  emits: ["update:modelValue", "change"],
  props: {
    type: { type: String, default: "checkbox" }, // checkbox | radio | switch
    modelValue: { type: [Boolean, String, Number, Array], default: false },
    nativeValue: { type: [String, Number], default: null },
    name: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false }
  },
  computed: {
    isChecked() {
      if (Array.isArray(this.modelValue)) return this.modelValue.includes(this.nativeValue);
      if (this.type === "radio") return this.modelValue === this.nativeValue;
      return !!this.modelValue;
    },
  },
  methods: {
    listeners() { return forwardListeners(this.$attrs); },
    plainAttrs() { return forwardPlainAttrs(this.$attrs); },
    onChange(e) {
      let next;
      if (Array.isArray(this.modelValue)) {
        next = this.modelValue.slice();
        const i = next.indexOf(this.nativeValue);
        e.target.checked ? (i === -1 && next.push(this.nativeValue)) : (i > -1 && next.splice(i, 1));
      } else if (this.type === "radio") {
        next = this.nativeValue;
      } else {
        next = e.target.checked;
      }
      this.$emit("update:modelValue", next);
      this.$emit("change", next);
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
.pxn-check.is-loading:not(.is-disabled) { opacity: 0.75; }

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
  position: relative;
  width: 34px; height: 20px;
  border-radius: 999px;
  padding: 2px;
  justify-content: flex-start;
  background: var(--pxn-surface-3);
  border-color: transparent;
  transition: background-color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-check--switch .pxn-check__box::before { content:""; position:absolute; top:50%; left:50%; width:44px; height:44px; transform:translate(-50%,-50%); }
.pxn-check--switch .pxn-check__knob {
  position: relative;
  width: 16px; height: 16px;
  border-radius: 999px;
  background: #fff;
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.28);
  transition: transform var(--pxn-dur-2) var(--pxn-ease);
}
.pxn-check--switch .pxn-check__native:checked + .pxn-check__box { background: var(--pxn-primary); }
.pxn-check--switch .pxn-check__native:checked + .pxn-check__box .pxn-check__knob { transform: translateX(14px); }
</style>

<style lang="scss" scoped>
.pxn-check--switch.is-loading,.pxn-check--switch.is-loading .pxn-check__box{cursor:progress}.pxn-check__spin{position:absolute;inset:0;margin:3px;border-radius:999px;border:2px solid var(--pxn-border-strong);border-top-color:var(--pxn-primary);animation:pxn-check-spin .6s linear infinite}@keyframes pxn-check-spin{to{transform:rotate(360deg)}}@media(prefers-reduced-motion:reduce){.pxn-check--switch .pxn-check__box,.pxn-check--switch .pxn-check__knob{transition:none}.pxn-check__spin{animation:none}}
</style>
