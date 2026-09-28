<template>
  <div
    class="pxn-search"
    :class="[`pxn-search--${size}`, { 'is-disabled': disabled, 'is-readonly': readonly, 'has-clear': canClear }]"
  >
    <lucide-icon name="search" :size="16" class="pxn-search__icon" aria-hidden="true" />
    <input
      ref="input"
      v-model="inner"
      class="pxn-search__input"
      type="search"
      :disabled="disabled"
      :readonly="readonly"
      v-bind="$attrs"
      v-on="listeners"
    />
    <button
      v-if="canClear"
      type="button"
      class="pxn-search__clear pxn-ring"
      :aria-label="clearLabel"
      :title="clearLabel"
      @click="clear"
    >
      <lucide-icon name="x" :size="14" aria-hidden="true" />
    </button>
  </div>
</template>

<script>
// PxSearchInput: campo de búsqueda/filtro de texto. Solo presenta y devuelve el texto; NO busca.
// Referencia visual: Uiverse alexruix/slippery-frog-10 (icono integrado, fondo suave que pasa a superficie
// con borde de acento y anillo al hover/focus), adaptada a los tokens --pxn-*.
//
// Contrato:
//  · v-model (`modelValue` / `update:modelValue`): sin estado interno; el valor siempre es el del padre.
//    Respeta composición IME (v-model nativo no emite hasta `compositionend`).
//  · El padre decide debounce, petición, submit y resultados. Aquí NO hay temporizadores.
//  · Enter no se intercepta: `@keyup.enter`, `@keydown.enter` o el `submit` de un <form> siguen funcionando.
//  · Escape: solo desde este input y solo si hay texto → limpia y hace preventDefault (anula el borrado
//    nativo de type=search). NO detiene la propagación: un modal/command palette ancestro conserva la
//    autoridad para cerrarse con ese mismo Escape si así lo decide.
//  · `clear` se emite además de `update:modelValue` cuando el usuario limpia con la X o con Escape.
//  · Nombre accesible: el consumidor pasa `aria-label` (u otro atributo nativo); el resto de atributos
//    (id, name, autocomplete, maxlength, aria-*) llega al <input>. Eventos nativos (focus, blur, keydown,
//    change, compositionstart…) llegan tal cual. No hay `@input` nativo: use v-model o `update:modelValue`.
//  · `placeholder` y `clear-label` los pone el padre (i18n).
export default {
  name: "PxSearchInput",
  inheritAttrs: false,
  model: { prop: "modelValue", event: "update:modelValue" },
  props: {
    modelValue: { type: String, default: "" }, // type="search" siempre produce texto; sin consumidor real de Number
    size: { type: String, default: "md" }, // md = altura de control de la foundation (38 px) · lg = 44 px (táctil)
    disabled: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    clearLabel: { type: String, default: "Limpiar búsqueda" }
  },
  computed: {
    inner: {
      get() { return this.modelValue == null ? "" : this.modelValue; },
      set(v) { this.$emit("update:modelValue", v); }
    },
    hasValue() { return String(this.inner) !== ""; },
    canClear() { return this.hasValue && !this.disabled && !this.readonly; },
    listeners() {
      // `input`/`update:modelValue` los gestiona v-model; el resto de eventos del padre pasa al <input>.
      const { input, "update:modelValue": _u, keydown, ...rest } = this.$listeners; // eslint-disable-line no-unused-vars
      return {
        ...rest,
        keydown: e => {
          this.onKeydown(e);
          if (typeof keydown === "function") keydown(e);
          else if (Array.isArray(keydown)) keydown.forEach(fn => fn(e));
        }
      };
    }
  },
  methods: {
    focus() { if (this.$refs.input) this.$refs.input.focus(); },
    clear() {
      if (!this.canClear) return;
      this.$emit("update:modelValue", "");
      this.$emit("clear");
      this.focus();
    },
    onKeydown(e) {
      if (e.key !== "Escape" || e.isComposing || !this.canClear) return;
      e.preventDefault(); // evita además el borrado nativo de type=search (Chrome/Safari): un solo camino
      this.clear(); // sin stopPropagation: un modal/command palette ancestro conserva su propio Escape
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-search {
  --_h: var(--pxn-control-h-md);
  position: relative;
  display: flex;
  align-items: center;
  width: 100%;
  min-width: 0;
  height: var(--_h);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface-2);
  color: var(--pxn-ink);
  transition: background-color 180ms var(--pxn-ease), border-color 180ms var(--pxn-ease), box-shadow 180ms var(--pxn-ease);
}
.pxn-search--lg { --_h: var(--pxn-control-h-lg); }

.pxn-search:hover:not(.is-disabled):not(.is-readonly) { background: var(--pxn-surface); border-color: var(--pxn-border-strong); }
.pxn-search:focus-within:not(.is-disabled):not(.is-readonly) {
  background: var(--pxn-surface);
  border-color: var(--pxn-primary);
  box-shadow: 0 0 0 3px var(--pxn-focus-ring);
}

.pxn-search__icon {
  position: absolute;
  left: var(--pxn-space-5);
  flex: none;
  color: var(--pxn-ink-3);
  pointer-events: none;
  transition: color 180ms var(--pxn-ease);
}
.pxn-search:focus-within:not(.is-disabled):not(.is-readonly) .pxn-search__icon { color: var(--pxn-primary-ink); }

.pxn-search__input {
  flex: 1 1 auto;
  width: 100%;
  min-width: 0;
  height: 100%;
  padding: 0 var(--pxn-space-5) 0 calc(var(--pxn-space-5) + 16px + var(--pxn-space-4));
  border: 0;
  border-radius: inherit;
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: var(--pxn-fs-body);
  text-overflow: ellipsis;
  appearance: none;
}
.pxn-search__input::placeholder { color: var(--pxn-ink-3); }
.pxn-search__input:focus, .pxn-search__input:focus-visible { outline: none; box-shadow: none; }
// El clear propio sustituye al nativo de type=search (un solo botón X). Se conserva el resto de la semántica search.
.pxn-search__input::-webkit-search-cancel-button,
.pxn-search__input::-webkit-search-decoration { -webkit-appearance: none; appearance: none; display: none; }

// Espacio para la X solo cuando existe.
.pxn-search.has-clear .pxn-search__input { padding-right: calc(var(--_h) - 4px); }

.pxn-search__clear {
  position: absolute;
  right: 4px;
  display: inline-flex; align-items: center; justify-content: center;
  width: calc(var(--_h) - 10px); height: calc(var(--_h) - 10px);
  padding: 0;
  border: 0;
  border-radius: var(--pxn-radius-sm);
  background: transparent;
  color: var(--pxn-ink-3);
  cursor: pointer;
  transition: background-color 180ms var(--pxn-ease), color 180ms var(--pxn-ease);
}
.pxn-search__clear:hover { background: var(--pxn-surface-3); color: var(--pxn-ink); }

.pxn-search.is-disabled { background: var(--pxn-surface-3); cursor: not-allowed; }
.pxn-search.is-disabled .pxn-search__input { color: var(--pxn-ink-disabled); cursor: not-allowed; }
.pxn-search.is-disabled .pxn-search__input::placeholder,
.pxn-search.is-disabled .pxn-search__icon { color: var(--pxn-ink-disabled); }
.pxn-search.is-readonly { background: var(--pxn-surface-2); }

@media (prefers-reduced-motion: reduce) {
  .pxn-search, .pxn-search__icon, .pxn-search__clear { transition: none; }
}
</style>
