<template>
  <label
    class="pxn-cb"
    :class="{ 'is-checked': visualChecked, 'is-indeterminate': indeterminate, 'is-disabled': disabled, 'is-invalid': invalid }"
  >
    <input
      ref="input"
      class="pxn-cb__native"
      type="checkbox"
      :checked="isChecked"
      :name="name"
      :value="nativeValue"
      :disabled="disabled"
      :required="required"
      :aria-invalid="invalid ? 'true' : null"
      :aria-label="ariaLabel"
      @change="onChange"
    />
    <span class="pxn-cb__box" aria-hidden="true">
      <svg class="pxn-cb__svg" viewBox="0 0 64 64">
        <!-- Trazo original (Uiverse SelfMadeSystem/green-bobcat-29): un solo path que pasa de caja a check. -->
        <path
          class="pxn-cb__path"
          d="M 0 16 V 56 A 8 8 90 0 0 8 64 H 56 A 8 8 90 0 0 64 56 V 8 A 8 8 90 0 0 56 0 H 8 A 8 8 90 0 0 0 8 V 16 L 32 48 L 64 16 V 8 A 8 8 90 0 0 56 0 H 8 A 8 8 90 0 0 0 8 V 56 A 8 8 90 0 0 8 64 H 56 A 8 8 90 0 0 64 56 V 16"
          pathLength="575.0541381835938"
        />
        <!-- Indeterminado: trazo horizontal con el mismo lenguaje (dash que se dibuja). -->
        <path class="pxn-cb__dash" d="M 16 32 H 48" pathLength="32" />
      </svg>
    </span>
    <span v-if="$slots.default || label" class="pxn-cb__label"><slot>{{ label }}</slot></span>
  </label>
</template>

<script>
// PxCheckbox: checkbox del design system con el trazo animado caja → check.
// Mismo contrato que PxCheck (v-model = modelValue / evento `change` con el valor, booleano o array).
// El <input type="checkbox"> nativo es el control (foco, teclado, label, formularios); el SVG es decorativo.
export default {
  name: "PxCheckbox",
  props: {
    modelValue: { type: [Boolean, Array], default: false },
    nativeValue: { type: [String, Number], default: null }, // valor del ítem cuando modelValue es un array
    name: { type: String, default: null },
    label: { type: String, default: null },
    ariaLabel: { type: String, default: null }, // obligatorio si no hay label visible
    disabled: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    indeterminate: { type: Boolean, default: false }
  },
  computed: {
    isChecked() {
      if (Array.isArray(this.modelValue)) return this.modelValue.includes(this.nativeValue);
      return !!this.modelValue;
    },
    // Indeterminado y marcado son excluyentes visualmente.
    visualChecked() {
      return this.isChecked && !this.indeterminate;
    }
  },
  watch: {
    indeterminate() { this.syncDom(); },
    isChecked() { this.syncDom(); }
  },
  mounted() {
    this.syncDom();
  },
  methods: {
    // `indeterminate` solo existe como propiedad DOM. También se re-afirma `checked` porque, si el padre
    // no acepta el cambio, Vue no vuelve a parchear un valor que no cambió y el DOM quedaría desfasado.
    syncDom() {
      const el = this.$refs.input;
      if (!el) return;
      el.indeterminate = this.indeterminate;
      el.checked = this.isChecked;
    },
    onChange(e) {
      const checked = e.target.checked;
      if (Array.isArray(this.modelValue)) {
        const next = this.modelValue.slice();
        const i = next.indexOf(this.nativeValue);
        if (checked) { if (i === -1) next.push(this.nativeValue); } else if (i > -1) next.splice(i, 1);
        this.$emit("update:modelValue", next); this.$emit("change", next);
      } else {
        this.$emit("update:modelValue", checked); this.$emit("change", checked);
      }
      this.$nextTick(this.syncDom);
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-cb {
  --_size: 18px;
  --_stroke: var(--pxn-ink-3);
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: var(--pxn-space-4);
  min-height: 24px;
  font-size: var(--pxn-fs-body);
  color: var(--pxn-ink);
  cursor: pointer;
}
.pxn-cb.is-disabled { cursor: not-allowed; color: var(--pxn-ink-3); }

// Control nativo: real, enfocable y conectado al label; solo oculto visualmente (nunca display:none).
.pxn-cb__native {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  min-width: 24px;
  min-height: 24px;
  margin: 0;
  opacity: 0;
  cursor: inherit;
}
// Táctil: el área interactiva crece sin agrandar el layout (útil en tablas densas).
@media (pointer: coarse) {
  .pxn-cb__native { inset: -10px; width: calc(100% + 20px); height: calc(100% + 20px); }
}

.pxn-cb__box {
  flex: none;
  display: inline-flex;
  width: var(--_size);
  height: var(--_size);
  border-radius: 4px;
  background: var(--pxn-surface);
  transition: background-color 250ms ease;
}
.pxn-cb__svg { display: block; width: 100%; height: 100%; overflow: visible; }

// Trazo: caja (241 de 575) → check (70.5, offset -262.27). Valores y 0.5s ease del original.
.pxn-cb__path {
  fill: none;
  stroke: var(--_stroke);
  stroke-width: 6;
  stroke-linecap: round;
  stroke-linejoin: round;
  transition: stroke-dasharray 0.5s ease, stroke-dashoffset 0.5s ease, stroke 0.15s ease;
  stroke-dasharray: 241 9999999;
  stroke-dashoffset: 0;
}
.pxn-cb__dash {
  fill: none;
  stroke: var(--pxn-primary-contrast);
  stroke-width: 6;
  stroke-linecap: round;
  transition: stroke-dasharray 0.3s ease;
  stroke-dasharray: 0 9999999;
}

.pxn-cb:hover:not(.is-disabled) { --_stroke: var(--pxn-ink-2); }

// MARCADO: fondo primario + check blanco
.pxn-cb.is-checked .pxn-cb__box { background: var(--pxn-primary); }
.pxn-cb.is-checked .pxn-cb__path {
  stroke: var(--pxn-primary-contrast);
  stroke-dasharray: 70.5096664428711 9999999;
  stroke-dashoffset: -262.2723388671875;
}

// INDETERMINADO: fondo primario + guion; el trazo caja/check se retira
.pxn-cb.is-indeterminate .pxn-cb__box { background: var(--pxn-primary); }
.pxn-cb.is-indeterminate .pxn-cb__path { stroke: var(--pxn-primary-contrast); stroke-dasharray: 0 9999999; stroke-dashoffset: 0; }
.pxn-cb.is-indeterminate .pxn-cb__dash { stroke-dasharray: 32 9999999; }

// INVÁLIDO: danger + trazo más grueso (no solo color)
.pxn-cb.is-invalid { --_stroke: var(--pxn-danger); }
.pxn-cb.is-invalid:hover:not(.is-disabled) { --_stroke: var(--pxn-danger-ink); }
.pxn-cb.is-invalid:not(.is-checked):not(.is-indeterminate) .pxn-cb__path { stroke-width: 8; }
.pxn-cb.is-invalid.is-checked .pxn-cb__box, .pxn-cb.is-invalid.is-indeterminate .pxn-cb__box { background: var(--pxn-danger); }

// DESHABILITADO: legible (sin opacidad baja), estado conservado
.pxn-cb.is-disabled { --_stroke: var(--pxn-ink-disabled); }
.pxn-cb.is-disabled .pxn-cb__box { background: var(--pxn-surface-3); }
.pxn-cb.is-disabled.is-checked .pxn-cb__box, .pxn-cb.is-disabled.is-indeterminate .pxn-cb__box { background: var(--pxn-ink-disabled); }
.pxn-cb.is-disabled.is-checked .pxn-cb__path { stroke: var(--pxn-surface); }

// Foco visible: en la caja (el input es transparente). !important: el POS anula el foco globalmente.
.pxn-cb__native:focus-visible ~ .pxn-cb__box {
  outline: 2px solid var(--pxn-primary) !important;
  outline-offset: 2px !important;
}
.pxn-cb.is-invalid .pxn-cb__native:focus-visible ~ .pxn-cb__box { outline-color: var(--pxn-danger) !important; }

.pxn-cb__label { min-width: 0; line-height: var(--pxn-lh-snug); overflow-wrap: anywhere; }

@media (prefers-reduced-motion: reduce) {
  .pxn-cb__box, .pxn-cb__path, .pxn-cb__dash { transition: none; }
}
</style>
