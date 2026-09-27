<template>
  <div class="v-select" :class="[stateClasses, $attrs.class]" :style="$attrs.style">
    <div
      ref="toggle"
      class="vs__dropdown-toggle"
      role="combobox"
      :aria-expanded="String(open)"
      aria-label="Search for option"
      @mousedown="onToggleMousedown"
    >
      <div class="vs__selected-options">
        <span v-for="option in selectedOptions" :key="optionKey(option)" class="vs__selected">
          <slot name="selected-option" v-bind="normalizeForSlot(option)">{{ optionLabel(option) }}</slot>
          <button
            v-if="multiple"
            :disabled="disabled"
            type="button"
            class="vs__deselect"
            :title="`Deselect ${optionLabel(option)}`"
            :aria-label="`Deselect ${optionLabel(option)}`"
            @click.stop="deselect(option)"
          >&times;</button>
        </span>
        <input
          ref="search"
          v-model="search"
          class="vs__search"
          :disabled="disabled"
          :placeholder="showPlaceholder ? placeholder : null"
          :aria-label="'Search'"
          @keydown.down.prevent="onArrow(1)"
          @keydown.up.prevent="onArrow(-1)"
          @keydown.enter.prevent="onEnter"
          @keydown.esc="onEscape"
          @keydown.delete="onBackspace"
          @focus="onFocus"
          @blur="onBlur"
        />
      </div>
      <div class="vs__actions">
        <button
          v-show="showClearButton"
          :disabled="disabled"
          type="button"
          class="vs__clear"
          title="Clear Selected"
          aria-label="Clear Selected"
          @click.stop="clearSelection"
        >&times;</button>
        <span class="vs__open-indicator" />
      </div>
    </div>
    <transition name="vs__fade">
      <ul v-if="open" ref="dropdownMenu" v-append-to-body class="vs__dropdown-menu" role="listbox" tabindex="-1" @mousedown.prevent>
        <li
          v-for="(option, index) in filteredOptions"
          :key="optionKey(option)"
          role="option"
          class="vs__dropdown-option"
          :class="{
            'vs__dropdown-option--selected': isSelected(option),
            'vs__dropdown-option--highlight': index === pointer,
          }"
          @mouseover="pointer = index"
          @click.prevent.stop="select(option)"
        >
          <slot name="option" v-bind="normalizeForSlot(option)">{{ optionLabel(option) }}</slot>
        </li>
        <li v-if="filteredOptions.length === 0" class="vs__no-options">
          <slot name="no-options">Sorry, no matching options.</slot>
        </li>
      </ul>
    </transition>
  </div>
</template>

<script>
// Reemplaza el paquete `vue-select` (ver `platform/compat/vue-select.js`, retirado) por un componente Vue 3 nativo
// de PRODEX que reproduce el contrato realmente usado en las 73 vistas que consumen `<v-select>` (auditado con
// `/tmp/vselect_files.txt`): v-model, :options, :reduce, :placeholder, :disabled, :multiple, :clearable,
// :close-on-select, label (nombre de la clave del label en las opciones), append-to-body + :calculate-position
// (reutiliza la MISMA directiva `vSelectAppendToBody` de `platform/directives/append-to-body.js`), y los slots
// #option/#selected-option/#no-options.
// v-model nativo de Vue 3: prop `modelValue` + evento `update:modelValue`. 9 vistas usan la API explícita vieja
// (`:value="x" @input="v => x = v"`, sin v-model) en vez del azúcar — se preserva aparte: prop `value` como alias
// (se usa solo si `modelValue` no llega) y evento `input` normal emitido junto a `update:modelValue` en cada
// cambio. Ninguna de las dos formas es un mecanismo de compat de Vue 2: son props/eventos propios y corrientes.
// Estado explícitamente NO usado en ninguna vista real (taggable, AJAX/loading, getOptionLabel): no se implementa.
// Clases y variables CSS (`.vs__*`, `--vs-*`) idénticas a las del paquete retirado — mismo `dist/vue-select.css`
// copiado en `vue-select.css` junto a este componente — para que las ~15 vistas con overrides `::v-deep(.vs__...)`
// sigan funcionando sin tocarlas.
import { vSelectAppendToBody } from '../platform/directives/append-to-body.js';
import './vue-select.css';

export default {
  name: 'VSelect',
  directives: { appendToBody: vSelectAppendToBody },
  inheritAttrs: false,
  props: {
    modelValue: { default: undefined },
    value: { default: undefined },
    options: { type: Array, default: () => [] },
    reduce: { type: Function, default: (option) => option },
    label: { type: String, default: 'label' },
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    multiple: { type: Boolean, default: false },
    clearable: { type: Boolean, default: true },
    closeOnSelect: { type: Boolean, default: true },
    appendToBody: { type: Boolean, default: false },
  },
  emits: ['update:modelValue', 'input', 'search'],
  data() {
    return { open: false, search: '', pointer: 0 };
  },
  computed: {
    stateClasses() {
      return {
        'vs--multiple': this.multiple,
        'vs--single': !this.multiple,
        'vs--open': this.open,
        'vs--disabled': this.disabled,
        'vs--searchable': true,
      };
    },
    // `modelValue` (v-model nativo) manda; `value` es el alias de la API explícita vieja, solo si no llega el otro.
    internalValue() { return this.modelValue !== undefined ? this.modelValue : this.value; },
    // El v-model puede traer el valor "reducido" (p. ej. un id) en vez del objeto opción completo: para mostrar
    // la etiqueta correcta hay que ubicar, dentro de `options`, cuál opción produce ese valor al pasar por `reduce`.
    selectedOptions() {
      const values = this.multiple ? (Array.isArray(this.internalValue) ? this.internalValue : []) : (this.internalValue == null ? [] : [this.internalValue]);
      return values.map((v) => this.findOptionFor(v));
    },
    showPlaceholder() {
      return this.selectedOptions.length === 0 && !this.search;
    },
    showClearButton() {
      return this.clearable && !this.disabled && this.selectedOptions.length > 0;
    },
    filteredOptions() {
      const term = this.search.trim().toLowerCase();
      const list = term ? this.options.filter((o) => this.optionLabel(o).toLowerCase().includes(term)) : this.options;
      // no repetir en el listado lo ya elegido (modo múltiple)
      return this.multiple ? list.filter((o) => !this.isSelected(o)) : list;
    },
  },
  watch: {
    filteredOptions() { this.pointer = 0; },
  },
  methods: {
    optionLabel(option) {
      if (option == null) return '';
      if (typeof option === 'object') return option[this.label] != null ? String(option[this.label]) : '';
      return String(option);
    },
    optionKey(option) {
      return option && typeof option === 'object' ? (option[this.label] ?? JSON.stringify(option)) : option;
    },
    normalizeForSlot(option) {
      return option && typeof option === 'object' ? option : { [this.label]: option };
    },
    // busca, entre `options`, la opción cuyo `reduce(...)` coincide con el valor crudo del v-model
    findOptionFor(value) {
      const match = this.options.find((o) => this.reduce(o) === value);
      return match !== undefined ? match : value;
    },
    isSelected(option) {
      const reduced = this.reduce(option);
      return this.selectedOptions.some((o) => this.reduce(o) === reduced);
    },
    emitValue(next) {
      this.$emit('update:modelValue', next);
      this.$emit('input', next);
    },
    select(option) {
      const reduced = this.reduce(option);
      if (this.multiple) {
        const current = Array.isArray(this.internalValue) ? this.internalValue : [];
        this.emitValue(current.includes(reduced) ? current : [...current, reduced]);
        this.search = '';
        if (this.closeOnSelect) this.open = false;
        this.$nextTick(() => this.$refs.search && this.$refs.search.focus());
      } else {
        this.emitValue(reduced);
        this.search = '';
        if (this.closeOnSelect) this.open = false;
      }
    },
    deselect(option) {
      const reduced = this.reduce(option);
      const current = Array.isArray(this.internalValue) ? this.internalValue : [];
      this.emitValue(current.filter((v) => v !== reduced));
    },
    clearSelection() {
      this.emitValue(this.multiple ? [] : null);
      this.search = '';
    },
    onToggleMousedown(event) {
      if (this.disabled) return;
      if (event.target === this.$refs.search) { this.open = true; return; }
      this.open = !this.open;
      if (this.open) this.$nextTick(() => this.$refs.search && this.$refs.search.focus());
    },
    onArrow(step) {
      if (!this.open) { this.open = true; return; }
      const max = this.filteredOptions.length - 1;
      if (max < 0) return;
      this.pointer = Math.min(max, Math.max(0, this.pointer + step));
    },
    onEnter() {
      if (!this.open) { this.open = true; return; }
      const option = this.filteredOptions[this.pointer];
      if (option !== undefined) this.select(option);
    },
    onEscape() { this.open = false; this.search = ''; },
    onBackspace() {
      if (this.search) return;
      if (this.multiple) {
        const current = Array.isArray(this.internalValue) ? this.internalValue : [];
        if (current.length) this.emitValue(current.slice(0, -1));
      } else if (this.clearable && this.internalValue != null) {
        this.emitValue(null);
      }
    },
    onFocus() { if (!this.disabled) this.open = true; },
    onBlur() { this.open = false; },
    // Método requerido por `vSelectAppendToBody` (llamado como `context.calculatePosition(el, context, pos)`).
    // `calculate-position` no es una prop declarada (no puede coexistir con este método del mismo nombre): llega
    // como atributo suelto en `$attrs` (`VsPx.vue` la usa para su anclado avanzado — flip vertical, clamp al
    // viewport — y se delega tal cual). Sin ella, posiciona el menú bajo el disparador con su mismo ancho.
    calculatePosition(dropdownList, component, { width, left, top }) {
      const custom = this.$attrs.calculatePosition || this.$attrs['calculate-position'];
      if (typeof custom === 'function') return custom(dropdownList, component, { width, left, top });
      dropdownList.style.position = 'fixed';
      dropdownList.style.width = width;
      dropdownList.style.left = left;
      dropdownList.style.top = top;
    },
  },
};
</script>
