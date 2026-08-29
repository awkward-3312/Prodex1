<template>
  <textarea
    :id="id"
    class="pxn-textarea pxn-ring"
    :value="value"
    :rows="rows"
    :placeholder="placeholder"
    :disabled="disabled"
    :readonly="readonly"
    :aria-invalid="invalid ? 'true' : null"
    :aria-describedby="describedby"
    v-on="listeners"
  ></textarea>
</template>

<script>
export default {
  name: "PxTextarea",
  props: {
    value: { type: String, default: "" },
    id: { type: String, default: null },
    rows: { type: [Number, String], default: 3 },
    placeholder: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    describedby: { type: String, default: null }
  },
  computed: {
    listeners() { return { ...this.$listeners, input: e => this.$emit("input", e.target.value) }; }
  }
};
</script>

<style lang="scss" scoped>
.pxn-textarea {
  width: 100%;
  min-height: 72px;
  padding: var(--pxn-space-4) var(--pxn-space-5);
  border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
  color: var(--pxn-ink);
  font: inherit;
  font-size: var(--pxn-fs-body);
  line-height: var(--pxn-lh-snug);
  resize: vertical;
  transition: border-color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-textarea::placeholder { color: var(--pxn-ink-3); }
.pxn-textarea:hover:not(:disabled):not(:read-only) { border-color: var(--pxn-border-strong); }
.pxn-textarea:disabled { background: var(--pxn-surface-3); color: var(--pxn-ink-disabled); cursor: not-allowed; }
</style>
