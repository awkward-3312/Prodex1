<template>
  <!--
    C2B — envoltorio de campo validado. Combina ValidationProvider (vee-validate v3)
    con la presentación de PxField SIN scoped-slot intermedio: el control con
    v-model se renderiza como hijo plano de <div class="pxn-field__control">, para
    que la autodetección de v-model de vee-validate lo encuentre. Reutiliza las
    clases .pxn-field* de production.scss (aspecto idéntico a PxField).
    · Expone `id` en el slot para enlazar <label for> ↔ control.
    · No muestra el error hasta que el campo se toca (blur) o se valida (submit),
      replicando el comportamiento de `getValidationState` del legacy.
  -->
  <validation-provider
    :name="name"
    :rules="rules"
    :vid="vid"
    v-slot="v"
    tag="div"
    class="pxn-field"
    :class="{ 'is-invalid': shown(v), 'is-disabled': disabled }"
  >
    <label v-if="label" :for="id" class="pxn-field__label">
      {{ label }}
      <span v-if="required" class="pxn-field__req" aria-hidden="true">*</span>
      <span v-if="optional" class="pxn-field__opt">(opcional)</span>
    </label>
    <div class="pxn-field__control">
      <slot :id="id" :invalid="shown(v)" />
    </div>
    <p v-if="hint && !shown(v)" class="pxn-field__hint">{{ hint }}</p>
    <p v-if="shown(v)" class="pxn-field__error" role="alert">
      <lucide-icon name="alert-circle" :size="13" />{{ msg(v) }}
    </p>
  </validation-provider>
</template>

<script>
let uid = 0;
export default {
  name: "VField",
  props: {
    name: { type: String, required: true },
    label: { type: String, default: null },
    rules: { type: [Object, String], default: "" },
    required: { type: Boolean, default: false },
    optional: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    hint: { type: String, default: null },
    vid: { type: String, default: undefined },
    idFor: { type: String, default: null },
    // Error del servidor a mostrar aunque vee-validate no falle (p. ej. code_exist).
    forceError: { type: String, default: null }
  },
  computed: {
    id() {
      return this.idFor || `pxe-f-${this._uid || (uid += 1)}`;
    }
  },
  methods: {
    firstError(v) {
      return v && Array.isArray(v.errors) && v.errors.length ? v.errors[0] : "";
    },
    // Solo tras interacción (blur → touched) o validación explícita (submit).
    shown(v) {
      if (this.forceError) return true;
      const interacted = !!v && (v.touched || v.validated);
      return interacted && !!this.firstError(v);
    },
    msg(v) {
      return this.firstError(v) || this.forceError || "";
    }
  }
};
</script>
