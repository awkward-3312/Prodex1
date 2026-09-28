<template>
  <label class="pxn-check" :class="[`pxn-check--${type}`, { 'is-disabled': disabled, 'is-loading': loading }]">
    <input
      class="pxn-check__native pxn-ring"
      :type="type === 'switch' ? 'checkbox' : type"
      :role="type === 'switch' ? 'switch' : null"
      :checked="isChecked"
      :name="name"
      :value="nativeValue"
      :disabled="disabled || loading"
      v-bind="$attrs"
      v-on="listeners"
    />
    <span class="pxn-check__box" aria-hidden="true">
      <lucide-icon v-if="type === 'checkbox'" name="check" :size="12" class="pxn-check__tick" />
      <span v-else-if="type === 'radio'" class="pxn-check__dot"></span>
      <span v-else class="pxn-check__knob">
        <span v-if="loading" class="pxn-check__spin"></span>
      </span>
    </span>
    <span v-if="$slots.default" class="pxn-check__label"><slot /></span>
  </label>
</template>

<script>
// Un componente para checkbox / radio / switch: mismo comportamiento de label y focus.
//
// `type="switch"` (#12): CONTROLADO — `modelValue` es la única fuente de verdad, no hay `checked`
// interno que pueda desincronizarse. El consumidor decide la estrategia async (pesimista: esperar
// confirmación del backend antes de cambiar `modelValue`; u optimista + rollback). Este componente
// NO conoce axios/fetch/permisos: solo emite la intención (`change`, igual que el resto de la familia
// px-next — PxCheckbox, PxFileUpload) y, con `loading`, bloquea la interacción sin mover el knob a un
// estado futuro no confirmado (la posición siempre deriva de `modelValue`, que el padre aún no cambió).
export default {
  name: "PxCheck",
  inheritAttrs: false, // aria-label/aria-* del consumidor deben llegar al <input> real, no al <label> raíz
  model: { prop: "modelValue", event: "change" },
  props: {
    type: { type: String, default: "checkbox" }, // checkbox | radio | switch
    modelValue: { type: [Boolean, String, Number, Array], default: false },
    nativeValue: { type: [String, Number], default: null },
    name: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false } // solo con sentido real en type="switch"; bloquea sin mover el knob
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
          // Controlado de verdad: el navegador ya volteó `checked` como parte del click, ANTES de este
          // handler. Lo revertimos al valor real (isChecked) en el mismo tick, antes de cualquier repintado,
          // así que el knob solo se mueve cuando `modelValue` cambia de verdad. Un consumidor optimista que
          // actualiza `modelValue` dentro de este mismo evento no ve salto ni parpadeo (Vue aplica ambos
          // cambios de DOM antes del próximo frame); uno pesimista mantiene la posición hasta que confirme.
          const checkedNow = e.target.checked;
          e.target.checked = this.isChecked;
          if (Array.isArray(this.modelValue)) {
            const next = this.modelValue.slice();
            const i = next.indexOf(this.nativeValue);
            checkedNow ? (i === -1 && next.push(this.nativeValue)) : (i > -1 && next.splice(i, 1));
            this.$emit("change", next);
          } else if (this.type === "radio") {
            this.$emit("change", this.nativeValue);
          } else {
            this.$emit("change", checkedNow);
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
// Área táctil ~44×44 sin agrandar el track visible: pseudo-elemento invisible dentro del label
// (clic en cualquier punto de un <label> activa el input asociado; no necesita listener propio).
.pxn-check--switch .pxn-check__box::before {
  content: "";
  position: absolute;
  top: 50%; left: 50%;
  width: 44px; height: 44px;
  transform: translate(-50%, -50%);
}
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

.pxn-check--switch.is-loading { cursor: progress; }
.pxn-check--switch.is-loading .pxn-check__box { cursor: progress; }
.pxn-check__spin {
  position: absolute; inset: 0; margin: 3px;
  border-radius: 999px;
  border: 2px solid var(--pxn-border-strong);
  border-top-color: var(--pxn-primary);
  animation: pxn-check-spin 0.6s linear infinite;
}
@keyframes pxn-check-spin { to { transform: rotate(360deg); } }

@media (prefers-reduced-motion: reduce) {
  .pxn-check--switch .pxn-check__box,
  .pxn-check--switch .pxn-check__knob { transition: none; }
  .pxn-check__spin { animation: none; }
}
</style>
