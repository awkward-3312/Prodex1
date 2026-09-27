<template>
  <VueDatePicker
    :model-value="internalValue"
    range
    :auto-apply="autoApply"
    :week-start="weekStart"
    :locale="dateFnsLocale"
    :enable-time-picker="timePicker"
    :enable-seconds="timePickerSeconds"
    :teleport="true"
    @update:model-value="onUpdate"
  >
    <template v-if="$slots.input" #trigger="slotProps">
      <slot name="input" v-bind="slotProps" :startDate="slotStartDate" :endDate="slotEndDate" />
    </template>
  </VueDatePicker>
</template>

<script>
// Reemplaza `vue2-daterange-picker` (ver `platform/compat/daterange-picker.js`, retirado) por `@vuepic/vue-datepicker`
// en modo `range` (nativo Vue 3). Mismo contrato observable en las 33 vistas que lo consumen:
//   - v-model: objeto `{ startDate: Date, endDate: Date }` (NO el array `[Date, Date]` que usa la librería nueva
//     por dentro — se traduce en los dos sentidos aquí, así ninguna vista cambia su forma de leer/escribir el rango).
//   - prop `locale-data`: solo `firstDay` tiene efecto real (los demás campos --Label, cancelLabel, daysOfWeek,
//     monthNames-- eran textos/nombres que la librería vieja pedía explícitos; la nueva ya trae sus propios
//     locales ES/EN/AR vía `locale`, tomado del idioma activo de vue-i18n).
//   - `autoApply`, `showDropdowns`, `opens`, `drops`: `autoApply` se traduce 1:1 (`auto-apply`); los otros tres eran
//     matices de UI de la librería vieja sin daño funcional si cambian ligeramente de comportamiento visual (la
//     nueva siempre permite navegar mes/año y se auto-posiciona).
//   - slot con nombre `input` (disparador personalizado): recibe `{ startDate, endDate }` igual que antes.
//   - evento `@update`: se sigue emitiendo tras cada cambio aplicado; ningún consumidor lee su payload (todos leen
//     `this.dateRange` ya actualizado por el v-model), así que no hace falta reproducir el payload original.
//   - `time-picker`/`time-picker-seconds` (solo sales_report.vue/seller_report.vue, rango CON hora): se traducen a
//     `enable-time-picker`/`enable-seconds` de la librería nueva — el objeto `Date` del v-model ya incluye la hora
//     por sí mismo, sin transformación extra.
import { VueDatePicker } from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import './vue-datepicker-icon-fix.css';
// El prop `locale` de la librería es tipado como `Locale` de `date-fns` (NO un código de idioma en string) — pasar
// un string ("es") deja los textos internos de formato de fecha sin `Locale` real y falla ("Cannot read properties
// of undefined (reading 'preprocessor')", un método interno del propio `date-fns` que solo existe en el objeto).
import { es, enUS, ar } from 'date-fns/locale';

export default {
  name: 'DateRangePicker',
  components: { VueDatePicker },
  // v-model nativo de Vue 3: `modelValue`/`update:modelValue`.
  props: {
    modelValue: { type: Object, default: () => ({ startDate: null, endDate: null }) },
    localeData: { type: Object, default: () => ({}) },
    autoApply: { type: Boolean, default: false },
    timePicker: { type: Boolean, default: false },
    timePickerSeconds: { type: Boolean, default: false },
  },
  emits: ['update:modelValue', 'update'],
  computed: {
    internalStartDate() { return this.modelValue && this.modelValue.startDate; },
    internalEndDate() { return this.modelValue && this.modelValue.endDate; },
    internalValue() {
      return this.internalStartDate && this.internalEndDate ? [this.internalStartDate, this.internalEndDate] : null;
    },
    // vue2-daterange-picker garantizaba que `picker.startDate`/`picker.endDate` del slot `input` FUERAN siempre
    // objetos `Date` válidos (normalizaba internamente cualquier v-model inicial inválido/vacío a "hoy"), sin
    // importar lo que el consumidor pusiera en el v-model al montar — varias vistas llaman `.toJSON()`/otros
    // métodos de `Date` directo sobre esos valores del slot asumiendo eso. `internalStartDate`/`internalEndDate`
    // arriba son el valor CRUDO del v-model (puede ser `""`, `null`… — correcto para decidir si `internalValue` va
    // al picker o no); estos de aquí son SOLO para el slot, con el mismo respaldo a "hoy" que tenía la librería vieja.
    slotStartDate() { return this.internalStartDate instanceof Date ? this.internalStartDate : new Date(); },
    slotEndDate() { return this.internalEndDate instanceof Date ? this.internalEndDate : new Date(); },
    weekStart() {
      const firstDay = this.localeData && this.localeData.firstDay;
      return typeof firstDay === 'number' ? firstDay : 1;
    },
    dateFnsLocale() {
      const lang = (this.$i18n && this.$i18n.locale) || 'es';
      return { es, en: enUS, ar }[lang] || enUS;
    },
  },
  methods: {
    onUpdate(value) {
      const [startDate, endDate] = Array.isArray(value) ? value : [null, null];
      this.$emit('update:modelValue', { startDate, endDate });
      this.$emit('update', { startDate, endDate });
    },
  },
};
</script>
