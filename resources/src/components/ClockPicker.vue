<template>
  <VueDatePicker
    :model-value="internalValue"
    time-picker
    :teleport="true"
    :placeholder="placeholder"
    @update:model-value="onUpdate"
  />
</template>

<script>
// Reemplaza `@pencilpix/vue2-clock-picker` (widget Vue 2 de carátula de reloj) por `@vuepic/vue-datepicker` en modo
// `time-picker` (nativo Vue 3). Mismo contrato en las 2 vistas que lo consumen: `v-model` sigue siendo un STRING
// "HH:mm" (ver comentarios de attendance.vue/office_shift.vue: "entrega HH:mm") — la librería nueva usa un objeto
// `{ hours, minutes }` por dentro; se traduce en los dos sentidos aquí, así el payload al backend no cambia.
import { VueDatePicker } from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import './vue-datepicker-icon-fix.css';

export default {
  name: 'ClockPicker',
  components: { VueDatePicker },
  props: {
    modelValue: { type: String, default: null },
    placeholder: { type: String, default: undefined },
  },
  emits: ['update:modelValue'],
  computed: {
    internalValue() {
      if (!this.modelValue || typeof this.modelValue !== 'string') return null;
      const [h, m] = this.modelValue.split(':').map((n) => parseInt(n, 10));
      if (Number.isNaN(h) || Number.isNaN(m)) return null;
      return { hours: h, minutes: m, seconds: 0 };
    },
  },
  methods: {
    onUpdate(value) {
      if (!value) { this.$emit('update:modelValue', null); return; }
      const hh = String(value.hours).padStart(2, '0');
      const mm = String(value.minutes).padStart(2, '0');
      this.$emit('update:modelValue', `${hh}:${mm}`);
    },
  },
};
</script>
