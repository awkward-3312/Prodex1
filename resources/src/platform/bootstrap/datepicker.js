// BFormDatepicker de PRODEX (Vue 3 puro). BootstrapVueNext no tiene un equivalente (su `BDatePicker` es otro control y su CSS no se carga), así que se
// escribe aquí con el marcado, las clases y el contrato MEDIDOS en BootstrapVue 2 (tests/e2e/specs/33-forms-parity.spec.js, escenarios "datepicker:"):
//   - modelo: cadena `YYYY-MM-DD` (o `''` sin fecha); `input(valor)` en cada cambio; el calendario se cierra al elegir un día (`noCloseOnSelect`)
//   - controles: botón con icono + etiqueta con la fecha larga localizada (`Intl`, locale del navegador salvo `locale`), o el `placeholder`
//   - menú `.dropdown-menu` con `.b-calendar` (cabecera, navegación año/mes/hoy, rejilla, ayuda) y pie con Hoy / Restablecer / Cerrar
//   - teclado en la rejilla: flechas ±1/±7 días, RePág/AvPág ±mes, Alt+RePág/AvPág ±año, Inicio = hoy, Enter/Espacio elige, Esc cierra
// El CSS (`.b-form-btn-label-control`, `.b-calendar…`) sigue siendo el de bootstrap-vue.css, que los temas importan hasta la fase 5C.
import { h, ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { pure } from './core.js';

let seq = 0;
const pad = (n) => String(n).padStart(2, '0');
const toYmd = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
function parseYmd(value) {
  if (value instanceof Date && !Number.isNaN(value.getTime())) return new Date(value.getFullYear(), value.getMonth(), value.getDate());
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
  if (!m) return null;
  const date = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
  return Number.isNaN(date.getTime()) ? null : date;
}
const addDays = (date, n) => new Date(date.getFullYear(), date.getMonth(), date.getDate() + n);
const addMonths = (date, n) => {
  const target = new Date(date.getFullYear(), date.getMonth() + n, 1);
  const last = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();
  return new Date(target.getFullYear(), target.getMonth(), Math.min(date.getDate(), last));
};

// BV2 marca como `aria-hidden` solo los iconos del botón; los de la navegación llevan `aria-label` ("chevron left"…)
const svg = (klass, children, label, hidden = false) => h('svg', {
  class: `b-icon bi ${klass}`, viewBox: '0 0 16 16', width: '1em', height: '1em', focusable: 'false', role: 'img', 'aria-label': label, 'aria-hidden': hidden ? 'true' : undefined, xmlns: 'http://www.w3.org/2000/svg', fill: 'currentColor',
}, children);
const path = (d, extra) => h('path', { d, ...(extra || {}) });
const CHEV = 'M11.354 1.646a.5.5 0 0 1 0 .708L5.707 8l5.647 5.646a.5.5 0 0 1-.708.708l-6-6a.5.5 0 0 1 0-.708l6-6a.5.5 0 0 1 .708 0z';
const CHEV2A = 'M8.354 1.646a.5.5 0 0 1 0 .708L2.707 8l5.647 5.646a.5.5 0 0 1-.708.708l-6-6a.5.5 0 0 1 0-.708l6-6a.5.5 0 0 1 .708 0z';
const CHEV2B = 'M12.354 1.646a.5.5 0 0 1 0 .708L6.707 8l5.647 5.646a.5.5 0 0 1-.708.708l-6-6a.5.5 0 0 1 0-.708l6-6a.5.5 0 0 1 .708 0z';
const wrapG = (children, flip) => h('g', { transform: 'translate(0 -0.5)' }, [flip ? h('g', { transform: 'translate(8 8) scale(-1 1) translate(-8 -8)' }, children) : h('g', children)]);
const iconCalendar = () => svg('bi-calendar', [h('g', [path('M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4H1z')])], 'calendar', true);
const iconCalendarFill = () => svg('bi-calendar-fill', [h('g', [path('M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V5h16V4H0V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5z')])], 'calendar fill', true);
const iconChevron = (flip) => svg('bi-chevron-left', [wrapG([path(CHEV, { 'fill-rule': 'evenodd' })], flip)], 'chevron left');
const iconChevron2 = (flip) => svg('bi-chevron-double-left', [wrapG([path(CHEV2A, { 'fill-rule': 'evenodd' }), path(CHEV2B, { 'fill-rule': 'evenodd' })], flip)], 'chevron double left');
const iconCircle = () => svg('bi-circle-fill', [wrapG([h('circle', { cx: 8, cy: 8, r: 8 })], false)], 'circle fill');

export const BFormDatepicker = /*#__PURE__*/ pure({
  name: 'BFormDatepicker',
  inheritAttrs: false,
  props: {
    modelValue: { type: [String, Date], default: '' },
    id: { type: String, default: undefined },
    name: { type: String, default: undefined },
    placeholder: { type: String, default: undefined },
    labelNoDateSelected: { type: String, default: 'No date selected' },
    size: { type: String, default: undefined },
    state: { type: [Boolean, null], default: null },
    disabled: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    locale: { type: [String, Array], default: undefined },
    min: { type: [String, Date], default: undefined },
    max: { type: [String, Date], default: undefined },
    startWeekday: { type: [Number, String], default: 0 },
    dateDisabledFn: { type: Function, default: undefined },
    resetButton: { type: Boolean, default: false },
    todayButton: { type: Boolean, default: false },
    closeButton: { type: Boolean, default: false },
    labelResetButton: { type: String, default: 'Reset' },
    labelTodayButton: { type: String, default: 'Select today' },
    labelCloseButton: { type: String, default: 'Close' },
    noCloseOnSelect: { type: Boolean, default: false },
    right: { type: Boolean, default: false },
    dropup: { type: Boolean, default: false },
    hideHeader: { type: Boolean, default: false },
    dark: { type: Boolean, default: false },
  },
  emits: ['update:modelValue', 'input', 'change', 'shown', 'hidden', 'context'],
  setup(props, { attrs, emit, expose }) {
    const uid = `BFormDatepicker__${(seq += 1)}`;
    const baseId = computed(() => props.id || uid);
    const root = ref(null);
    const button = ref(null);
    const grid = ref(null);
    const open = ref(false);
    const value = ref(props.modelValue || '');
    const selected = computed(() => parseYmd(value.value));
    const today = () => parseYmd(new Date());
    const active = ref(selected.value || today());
    const minDate = computed(() => parseYmd(props.min));
    const maxDate = computed(() => parseYmd(props.max));
    const lang = computed(() => {
      const wanted = Array.isArray(props.locale) ? props.locale : props.locale ? [props.locale] : [];
      try { return new Intl.DateTimeFormat(wanted.length ? wanted : undefined).resolvedOptions().locale; } catch (e) { return 'en-US'; }
    });
    const fmt = (options) => new Intl.DateTimeFormat(lang.value, options);
    const longLabel = (date) => fmt({ year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' }).format(date);

    watch(() => props.modelValue, (v) => {
      value.value = v || '';
      const d = parseYmd(value.value);
      if (d) active.value = d;
    });

    const setValue = (ymd) => {
      if (ymd === value.value) return;
      value.value = ymd;
      emit('input', ymd);
      emit('update:modelValue', ymd);
      emit('context', { selectedYMD: ymd, activeYMD: toYmd(active.value) });
    };
    const isDisabled = (date) => {
      if (props.disabled || props.readonly) return true;
      if (minDate.value && date < minDate.value) return true;
      if (maxDate.value && date > maxDate.value) return true;
      return !!(props.dateDisabledFn && props.dateDisabledFn(toYmd(date), date));
    };

    const show = () => { if (props.disabled || open.value) return; open.value = true; nextTick(() => { if (grid.value) grid.value.focus(); emit('shown'); }); };
    const hide = (refocus) => {
      if (!open.value) return;
      open.value = false;
      nextTick(() => emit('hidden'));
      if (refocus && button.value) button.value.focus();
    };
    const choose = (date) => {
      if (isDisabled(date)) return;
      setValue(toYmd(date));
      if (!props.noCloseOnSelect) hide(true);
    };

    const onDocClick = (event) => { if (open.value && root.value && !root.value.contains(event.target)) hide(false); };
    onMounted(() => document.addEventListener('mousedown', onDocClick));
    onBeforeUnmount(() => document.removeEventListener('mousedown', onDocClick));
    expose({ show, hide, focus: () => button.value && button.value.focus(), blur: () => button.value && button.value.blur() });

    const move = (date) => { active.value = date; };
    const onGridKey = (event) => {
      const map = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
      const key = event.key;
      if (key in map) { event.preventDefault(); move(addDays(active.value, map[key])); return; }
      // BV2: RePág = mes/año anterior, AvPág = siguiente (aunque `aria-keyshortcuts` de sus botones diga lo contrario)
      if (key === 'PageUp') { event.preventDefault(); move(addMonths(active.value, event.altKey ? -12 : -1)); return; }
      if (key === 'PageDown') { event.preventDefault(); move(addMonths(active.value, event.altKey ? 12 : 1)); return; }
      if (key === 'Home') { event.preventDefault(); move(today()); return; }
      if (key === 'End') { event.preventDefault(); move(selected.value || today()); return; }
      if (key === 'Enter' || key === ' ') { event.preventDefault(); choose(active.value); return; }
      if (key === 'Escape') { event.preventDefault(); hide(true); }
    };

    const cells = computed(() => {
      const first = new Date(active.value.getFullYear(), active.value.getMonth(), 1);
      const offset = (first.getDay() - Number(props.startWeekday) + 7) % 7;
      const start = addDays(first, -offset);
      // BV2 solo pinta las semanas que toca el mes (5 o 6 filas)
      const weeks = Math.ceil((offset + new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate()) / 7);
      return Array.from({ length: weeks }, (_, r) => Array.from({ length: 7 }, (_c, c) => addDays(start, r * 7 + c)));
    });

    const navButton = (title, keys, icon, onClick) => h('button', { class: 'btn btn-sm border-0 flex-fill btn-outline-secondary', title, type: 'button', 'aria-label': title, 'aria-keyshortcuts': keys, disabled: props.disabled || undefined, onClick }, [h('div', { 'aria-hidden': 'true' }, [icon])]);

    const renderCalendar = () => {
      const now = today();
      const weekdays = Array.from({ length: 7 }, (_, i) => {
        const day = addDays(new Date(2021, 7, 1), (i + Number(props.startWeekday)) % 7);
        return h('small', { class: 'col text-truncate', title: fmt({ weekday: 'long' }).format(day), 'aria-label': fmt({ weekday: 'long' }).format(day) }, fmt({ weekday: 'short' }).format(day));
      });
      const body = cells.value.map((row) => h('div', { class: 'row no-gutters' }, row.map((date) => {
        const ymd = toYmd(date);
        const outside = date.getMonth() !== active.value.getMonth();
        const isSel = !!selected.value && ymd === toYmd(selected.value);
        const isNow = ymd === toYmd(now);
        const isActive = ymd === toYmd(active.value);
        const off = isDisabled(date);
        const tone = isSel ? 'btn-primary' : isNow ? 'btn-outline-primary' : 'btn-outline-light';
        const text = isSel || isNow ? '' : outside || off ? 'text-muted' : 'text-dark';
        return h('div', {
          class: ['col p-0', off ? 'b-calendar-grid-disabled' : null],
          role: 'button', 'data-date': ymd, 'aria-hidden': outside ? 'true' : undefined, 'aria-disabled': off ? 'true' : undefined,
          'aria-label': `${longLabel(date)}${isSel ? ' (Selected date)' : ''}${isNow && !isSel ? ' (Today)' : ''}`,
          'aria-selected': isSel ? 'true' : undefined, 'aria-current': isSel ? 'date' : undefined,
          onClick: () => { active.value = date; choose(date); },
        }, [h('span', { class: ['btn border-0 rounded-circle text-nowrap', isActive && isSel ? 'focus active' : null, tone, text, outside ? null : 'font-weight-bold', off ? 'disabled' : null] }, String(date.getDate()))]);
      })));
      const buttons = [];
      if (props.todayButton) buttons.push(h('button', { class: 'btn btn-outline-primary btn-sm', type: 'button', 'aria-label': props.labelTodayButton, onClick: () => { const d = today(); active.value = d; choose(d); } }, props.labelTodayButton));
      if (props.resetButton) buttons.push(h('button', { class: 'btn btn-outline-danger btn-sm', type: 'button', 'aria-label': props.labelResetButton, onClick: () => { setValue(''); hide(true); } }, props.labelResetButton));
      if (props.closeButton) buttons.push(h('button', { class: 'btn btn-outline-secondary btn-sm', type: 'button', 'aria-label': props.labelCloseButton, onClick: () => hide(true) }, props.labelCloseButton));
      return h('div', { class: 'b-calendar b-form-date-calendar w-100' }, [
        h('div', { dir: 'ltr', lang: lang.value, role: 'group', class: 'b-calendar-inner', style: { width: '270px' } }, [
          props.hideHeader ? null : h('header', { class: 'b-calendar-header', title: 'Selected date' }, [
            h('output', { class: 'form-control form-control-sm text-center', role: 'status', tabindex: '-1', 'data-selected': value.value || undefined, 'aria-live': 'polite', 'aria-atomic': 'true' }, [
              h('bdi', { class: 'sr-only' }, ' (Selected date) '),
              h('bdi', selected.value ? longLabel(selected.value) : props.labelNoDateSelected),
            ]),
          ]),
          h('div', { role: 'group', 'aria-label': 'Calendar navigation', class: 'b-calendar-nav d-flex' }, [
            navButton('Previous year', 'Alt+PageDown', iconChevron2(false), () => move(addMonths(active.value, -12))),
            navButton('Previous month', 'PageDown', iconChevron(false), () => move(addMonths(active.value, -1))),
            navButton('Current month', 'Home', iconCircle(), () => move(today())),
            navButton('Next month', 'PageUp', iconChevron(true), () => move(addMonths(active.value, 1))),
            navButton('Next year', 'Alt+PageUp', iconChevron2(true), () => move(addMonths(active.value, 12))),
          ]),
          h('div', { ref: grid, role: 'application', tabindex: '0', 'data-month': `${active.value.getFullYear()}-${pad(active.value.getMonth() + 1)}`, 'aria-roledescription': 'Calendar', class: 'b-calendar-grid form-control h-auto text-center', onKeydown: onGridKey }, [
            h('div', { class: 'b-calendar-grid-caption text-center font-weight-bold', 'aria-live': 'polite', 'aria-atomic': 'true' }, fmt({ year: 'numeric', month: 'long' }).format(active.value)),
            h('div', { 'aria-hidden': 'true', class: 'b-calendar-grid-weekdays row no-gutters border-bottom' }, weekdays),
            h('div', { class: 'b-calendar-grid-body' }, body),
            h('div', { class: 'b-calendar-grid-help border-top small text-muted text-center bg-light' }, [h('div', { class: 'small' }, 'Use cursor keys to navigate calendar dates')]),
          ]),
          buttons.length ? h('footer', { class: 'b-calendar-footer' }, [h('div', { class: ['b-form-date-controls d-flex flex-wrap', buttons.length > 1 ? 'justify-content-between' : 'justify-content-end'] }, buttons)]) : null,
        ]),
      ]);
    };

    return () => {
      const { class: klass, style, ...rest } = attrs;
      const stateClass = props.state === true ? 'is-valid' : props.state === false ? 'is-invalid' : null;
      const sizeClass = props.size ? `form-control-${props.size}` : null;
      const label = selected.value ? longLabel(selected.value) : (props.placeholder ?? props.labelNoDateSelected);
      return h('div', {
        ...rest,
        ref: root,
        class: ['b-form-btn-label-control dropdown form-control', open.value ? 'show' : null, props.dropup ? 'dropup' : null, sizeClass, 'b-form-datepicker', stateClass, klass],
        style,
        role: 'group',
        'aria-disabled': props.disabled ? 'true' : undefined,
        'aria-invalid': props.state === false ? 'true' : undefined,
        'aria-required': props.required ? 'true' : undefined,
        id: baseId.value,
        lang: lang.value,
        'aria-labelledby': `${baseId.value}__value_`,
        onKeydown: (event) => { if (event.key === 'Escape' && open.value) { event.stopPropagation(); hide(true); } },
      }, [
        h('button', {
          ref: button, class: ['btn', props.size ? `btn-${props.size}` : null, 'h-auto'], type: 'button', 'aria-haspopup': 'dialog', 'aria-expanded': open.value ? 'true' : 'false', 'aria-invalid': props.state === false ? 'true' : undefined, id: `${baseId.value}__toggle_`,
          disabled: props.disabled || undefined, onClick: () => (open.value ? hide(false) : show()),
        }, [open.value ? iconCalendarFill() : iconCalendar()]),
        props.name ? h('input', { type: 'hidden', name: props.name, value: value.value || '' }) : null,
        h('div', { class: ['dropdown-menu', open.value ? 'show' : null, props.right ? 'dropdown-menu-right' : null], role: 'dialog', tabindex: '-1', 'aria-modal': 'false', id: `${baseId.value}__dialog_`, 'aria-labelledby': `${baseId.value}__value_` }, open.value ? [renderCalendar()] : []),
        h('label', { class: ['form-control', sizeClass, stateClass, selected.value ? null : 'text-muted'], 'aria-invalid': props.state === false ? 'true' : undefined, id: `${baseId.value}__value_`, for: baseId.value }, label),
      ]);
    };
  },
});
