<template>
  <div class="pxn-input-wrap" :class="{ 'has-prefix': !!prefix || !!iconLead, 'has-suffix': !!suffix || !!iconTrail }">
    <span v-if="iconLead" class="pxn-input__ico pxn-input__ico--lead"><lucide-icon :name="iconLead" :size="15" /></span>
    <span v-else-if="prefix" class="pxn-input__aff pxn-input__aff--lead">{{ prefix }}</span>
    <input
      :id="id"
      class="pxn-input pxn-ring"
      :class="{ 'pxn-num': numeric }"
      :type="type"
      :value="value"
      :placeholder="placeholder"
      :disabled="disabled"
      :readonly="readonly"
      :inputmode="inputmode"
      :aria-invalid="invalid ? 'true' : null"
      :aria-describedby="describedby"
      v-on="listeners"
    />
    <span v-if="iconTrail" class="pxn-input__ico pxn-input__ico--trail"><lucide-icon :name="iconTrail" :size="15" /></span>
    <span v-else-if="suffix" class="pxn-input__aff pxn-input__aff--trail">{{ suffix }}</span>
  </div>
</template>

<script>
export default {
  name: "PxInput",
  inheritAttrs: false,
  props: {
    value: { type: [String, Number], default: "" },
    type: { type: String, default: "text" },
    id: { type: String, default: null },
    placeholder: { type: String, default: null },
    prefix: { type: String, default: null },
    suffix: { type: String, default: null },
    iconLead: { type: String, default: null },
    iconTrail: { type: String, default: null },
    numeric: { type: Boolean, default: false },
    inputmode: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    describedby: { type: String, default: null }
  },
  computed: {
    listeners() {
      return { ...this.$listeners, input: e => this.$emit("input", e.target.value) };
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-input-wrap { position: relative; display: flex; align-items: center; }
.pxn-input {
  width: 100%;
  height: var(--pxn-control-h-md);
  padding: 0 var(--pxn-space-5);
  border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
  color: var(--pxn-ink);
  font: inherit;
  font-size: var(--pxn-fs-body);
  transition: border-color var(--pxn-dur-1) var(--pxn-ease), background-color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-input::placeholder { color: var(--pxn-ink-3); }
.pxn-input:hover:not(:disabled):not(:read-only) { border-color: var(--pxn-border-strong); }
.pxn-input:disabled { background: var(--pxn-surface-3); color: var(--pxn-ink-disabled); cursor: not-allowed; }
.pxn-input:read-only { background: var(--pxn-surface-2); }

.has-prefix .pxn-input { padding-left: calc(var(--pxn-space-5) + 22px); }
.has-suffix .pxn-input { padding-right: calc(var(--pxn-space-5) + 22px); }

.pxn-input__ico, .pxn-input__aff {
  position: absolute;
  display: inline-flex;
  align-items: center;
  color: var(--pxn-ink-3);
  font-size: var(--pxn-fs-sm);
  pointer-events: none;
}
.pxn-input__ico--lead, .pxn-input__aff--lead { left: var(--pxn-space-5); }
.pxn-input__ico--trail, .pxn-input__aff--trail { right: var(--pxn-space-5); }
</style>
