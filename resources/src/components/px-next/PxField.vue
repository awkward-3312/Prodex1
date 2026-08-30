<template>
  <div class="pxn-field" :class="{ 'is-invalid': !!error, 'is-disabled': disabled }">
    <label v-if="label" :for="id" class="pxn-field__label">
      {{ label }}
      <span v-if="required" class="pxn-field__req" aria-hidden="true">*</span>
      <span v-if="optional" class="pxn-field__opt">(opcional)</span>
    </label>
    <div class="pxn-field__control">
      <slot :id="id" :invalid="!!error" :describedby="describedby" />
    </div>
    <p v-if="hint && !error" :id="`${id}-hint`" class="pxn-field__hint">{{ hint }}</p>
    <p v-if="error" :id="`${id}-error`" class="pxn-field__error" role="alert">
      <lucide-icon name="alert-circle" :size="13" />{{ error }}
    </p>
  </div>
</template>

<script>
// PxField — label / hint / error scaffold. The control is slotted so it works
// with a native input, PxInput, PxSelect, a datepicker, anything. Wires the
// a11y ids (for/aria-describedby) via slot props.
let uid = 0;
export default {
  name: "PxField",
  props: {
    label: { type: String, default: null },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    optional: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    idFor: { type: String, default: null }
  },
  computed: {
    id() { return this.idFor || `pxn-f-${this._uid || (uid += 1)}`; },
    describedby() {
      return [this.hint && !this.error ? `${this.id}-hint` : null, this.error ? `${this.id}-error` : null]
        .filter(Boolean).join(" ") || null;
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-field { display: flex; flex-direction: column; gap: var(--pxn-space-3); }
.pxn-field__label {
  font-size: var(--pxn-fs-sm);
  font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink-2);
  display: inline-flex;
  align-items: baseline;
  gap: var(--pxn-space-2);
}
.pxn-field__req { color: var(--pxn-danger); }
.pxn-field__opt { color: var(--pxn-ink-3); font-weight: var(--pxn-fw-regular); font-size: var(--pxn-fs-xs); }
.pxn-field__hint { margin: 0; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxn-field__error {
  margin: 0;
  font-size: var(--pxn-fs-xs);
  color: var(--pxn-danger-ink);
  display: inline-flex;
  align-items: center;
  gap: var(--pxn-space-2);
}
.pxn-field.is-invalid ::v-deep .pxn-input,
.pxn-field.is-invalid ::v-deep .pxn-select {
  border-color: var(--pxn-danger);
}
</style>
