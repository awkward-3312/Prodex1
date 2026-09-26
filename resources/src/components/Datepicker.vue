<template>
  <VueDatePicker
    :model-value="modelValue"
    :auto-apply="autoApply"
    :locale="dateFnsLocale"
    :format="format"
    :teleport="true"
    text-input
    :ui="{ input: inputClass }"
    :placeholder="placeholder"
    @update:model-value="onUpdate"
    @closed="$emit('closed')"
  />
</template>

<script>
// Reemplaza `vuejs-datepicker` (fecha simple, sin rango) por `@vuepic/vue-datepicker` (nativo Vue 3). Mismo
// contrato en las 4 vistas que lo consumen: `v-model` sigue siendo un objeto `Date` (nunca string — el propio
// consumidor lo convierte a "yyyy-MM-dd" él mismo en su handler de `@closed`, ver `formatFieldDate` en
// employee_create.vue/employee_edit.vue/payrolls.vue/employee_details.vue), `input-class`, `format` (solo texto
// visible del input, no afecta el valor del v-model), `placeholder`, evento `@closed`. `locale` usa el mismo
// mapeo ES/EN/AR de DateRangePicker.vue (objetos reales de `date-fns/locale`, no strings — ver ese componente para
// el porqué).
import { VueDatePicker } from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import './vue-datepicker-icon-fix.css';
import { es, enUS, ar } from 'date-fns/locale';

export default {
  name: 'Datepicker',
  components: { VueDatePicker },
  compatConfig: { MODE: 3 },
  props: {
    modelValue: { type: [Date, String], default: null },
    format: { type: String, default: 'yyyy-MM-dd' },
    inputClass: { type: String, default: undefined },
    placeholder: { type: String, default: undefined },
    autoApply: { type: Boolean, default: false },
  },
  emits: ['update:modelValue', 'closed'],
  computed: {
    dateFnsLocale() {
      const lang = (this.$i18n && this.$i18n.locale) || 'es';
      return { es, en: enUS, ar }[lang] || enUS;
    },
  },
  methods: {
    onUpdate(value) { this.$emit('update:modelValue', value); },
  },
};
</script>
