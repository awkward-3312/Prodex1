// BFormFile de PRODEX (Vue 3 puro). BootstrapVueNext emite un `input.form-control` (BS5) sin el aspecto `custom-file` que usa toda la aplicación,
// y no reproduce el contrato de BootstrapVue 2, así que el componente se escribe aquí con el marcado y el contrato MEDIDOS en BV2
// (tests/e2e/specs/33-forms-parity.spec.js, escenarios "file:"):
//   - marcado: div.custom-file.b-form-file > input.custom-file-input + label.custom-file-label[data-browse] > span.d-block.form-file-text
//   - modelo: `File` (o `null`) y, con `multiple`, `File[]` (aunque haya un solo archivo); vacío → `null`
//   - eventos por selección: `change(Event nativo)` → `input(valor)` → el modelo cambia (los observadores ven el valor ya asignado)
//   - modelo a `null` desde fuera: limpia el <input> y emite `input(null)`; el evento `reset` del formulario también lo limpia
//   - arrastrar y soltar sobre el control: muestra `dropPlaceholder`, filtra por `accept` y emite `change(Event del drop)` → `input(valor)`, como BV2
//   - texto por defecto "No file chosen" y botón "Browse" (`data-browse`), como en BV2 (la capa de idioma de PRODEX ya los traduce)
import { h, ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { pure } from './core.js';

const asList = (value) => (Array.isArray(value) ? value : value ? [value] : []);
let seq = 0;

// Coincidencia de `accept` (".png", "image/*", "application/pdf") con un archivo.
function accepted(file, accept) {
  if (!accept) return true;
  const rules = String(Array.isArray(accept) ? accept.join(',') : accept).split(',').map((s) => s.trim().toLowerCase()).filter(Boolean);
  if (!rules.length) return true;
  const name = (file.name || '').toLowerCase();
  const type = (file.type || '').toLowerCase();
  return rules.some((rule) => {
    if (rule.startsWith('.')) return name.endsWith(rule);
    if (rule.endsWith('/*')) return type.startsWith(rule.slice(0, -1));
    return type === rule;
  });
}

export const BFormFile = /*#__PURE__*/ pure({
  name: 'BFormFile',
  inheritAttrs: false,
  props: {
    modelValue: { default: null },
    id: { type: String, default: undefined },
    name: { type: String, default: undefined },
    accept: { type: [String, Array], default: '' },
    multiple: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    capture: { type: [Boolean, String], default: false },
    directory: { type: Boolean, default: false },
    size: { type: String, default: undefined },
    state: { type: [Boolean, null], default: null },
    placeholder: { type: String, default: 'No file chosen' },
    dropPlaceholder: { type: String, default: 'Drop files here' },
    browseText: { type: String, default: 'Browse' },
    noDrop: { type: Boolean, default: false },
    plain: { type: Boolean, default: false },
    fileNameFormatter: { type: Function, default: undefined },
    autofocus: { type: Boolean, default: false },
  },
  emits: ['update:modelValue', 'input', 'change', 'reset', 'focus', 'blur'],
  setup(props, { attrs, emit, expose }) {
    const input = ref(null);
    const dragging = ref(false);
    const uid = `BFormFile__${(seq += 1)}`;
    const inputId = computed(() => props.id || uid);
    const selected = ref(props.multiple ? asList(props.modelValue) : props.modelValue || null);

    const assign = (files) => {
      // BV2: sin `multiple` el valor es `File` o `null`; con `multiple`, siempre un arreglo.
      const next = props.multiple ? files : files[0] || null;
      selected.value = next;
      emit('input', next);
      emit('update:modelValue', next);
    };

    const onChange = (event) => {
      const files = Array.from(event.target.files || []);
      emit('change', event);
      assign(files);
    };

    const clear = () => {
      if (input.value) input.value.value = '';
      selected.value = props.multiple ? [] : null;
    };
    watch(() => props.modelValue, (value) => {
      const list = asList(value);
      if (list.length) { selected.value = props.multiple ? list : value; return; }
      // El modelo se vació desde fuera: limpia el <input> y avisa (BV2 devolvía `input(null)`).
      const had = asList(selected.value).length > 0;
      clear();
      if (had) emit('input', props.multiple ? [] : null);
    });

    const onFormReset = () => { clear(); emit('reset'); };
    onMounted(() => {
      if (input.value && input.value.form) input.value.form.addEventListener('reset', onFormReset);
      if (props.autofocus && input.value) input.value.focus();
    });
    onBeforeUnmount(() => { if (input.value && input.value.form) input.value.form.removeEventListener('reset', onFormReset); });

    const onDrop = (event) => {
      event.preventDefault();
      dragging.value = false;
      if (props.disabled || props.noDrop) return;
      let files = Array.from((event.dataTransfer && event.dataTransfer.files) || []).filter((file) => accepted(file, props.accept));
      if (!props.multiple) files = files.slice(0, 1);
      if (files.length) {
        // BV2 también deja los archivos soltados en el <input> (así llegan en un envío nativo del formulario)
        try {
          const transfer = new DataTransfer();
          files.forEach((file) => transfer.items.add(file));
          if (input.value) input.value.files = transfer.files;
        } catch (e) { /* navegadores sin DataTransfer constructible */ }
        emit('change', event);
        assign(files);
      }
    };
    const onDragOver = (event) => {
      event.preventDefault();
      if (props.disabled || props.noDrop) return;
      dragging.value = true;
    };
    const onDragLeave = (event) => {
      event.preventDefault();
      dragging.value = false;
    };

    expose({ reset: () => { clear(); emit('input', props.multiple ? [] : null); }, focus: () => input.value && input.value.focus(), blur: () => input.value && input.value.blur() });

    const labelText = computed(() => {
      if (dragging.value) return props.dropPlaceholder;
      const list = asList(selected.value);
      if (!list.length) return props.placeholder;
      if (props.fileNameFormatter) return props.fileNameFormatter(list);
      return list.map((file) => file.name).join(', ');
    });

    return () => {
      const { class: klass, style, ...inputAttrs } = attrs;
      const stateClass = props.state === true ? 'is-valid' : props.state === false ? 'is-invalid' : null;
      const field = h('input', {
        ...inputAttrs,
        ref: input,
        type: 'file',
        id: inputId.value,
        name: props.name || undefined,
        class: [props.plain ? 'form-control-file' : 'custom-file-input', stateClass],
        accept: (Array.isArray(props.accept) ? props.accept.join(',') : props.accept) || undefined,
        multiple: props.multiple || undefined,
        disabled: props.disabled || undefined,
        required: props.required || undefined,
        capture: props.capture || undefined,
        webkitdirectory: props.directory || undefined,
        'aria-required': props.required ? 'true' : undefined,
        style: props.plain ? undefined : { zIndex: -5 },
        onChange,
        onFocus: (event) => emit('focus', event),
        onBlur: (event) => emit('blur', event),
      });
      if (props.plain) return field;
      return h('div', {
        class: ['custom-file', 'b-form-file', props.size ? `b-custom-control-${props.size}` : null, stateClass, dragging.value ? 'dragging' : null, klass],
        style,
        onDragover: onDragOver,
        onDragleave: onDragLeave,
        onDrop,
      }, [
        field,
        h('label', { class: 'custom-file-label', for: inputId.value, 'data-browse': props.browseText }, [
          h('span', { class: 'd-block form-file-text', style: { pointerEvents: 'none' } }, labelText.value),
        ]),
      ]);
    };
  },
});
