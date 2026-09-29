<template>
  <button
    type="button"
    class="pos-pay pxn-ring"
    :class="{ 'is-playing': active, 'is-block': block, 'is-lg': size === 'lg', 'is-loading': loading }"
    :disabled="disabled || loading"
    :aria-busy="loading ? 'true' : null"
    :aria-label="ariaLabel || label"
    :title="title"
    @click="onClick"
  >
    <span class="pos-pay__stage" aria-hidden="true">
      <span class="pos-pay__rig">
        <span class="pos-pay__card">
          <span class="pos-pay__card-line"></span>
          <span class="pos-pay__card-dots"></span>
        </span>
        <span class="pos-pay__terminal">
          <span class="pos-pay__terminal-line"></span>
          <span class="pos-pay__screen"><span class="pos-pay__dollar" :class="`is-len-${symbolLen}`">{{ symbol }}</span></span>
          <span class="pos-pay__keys pos-pay__keys--1"></span>
          <span class="pos-pay__keys pos-pay__keys--2"></span>
        </span>
      </span>
    </span>
    <span class="pos-pay__body">
      <span class="pos-pay__label">{{ label }}</span>
      <span v-if="$slots.amount" class="pos-pay__amount"><slot name="amount" /></span>
      <svg class="pos-pay__arrow" viewBox="0 0 451.846 451.847" aria-hidden="true">
        <path fill="currentColor" d="M345.441 248.292L151.154 442.573c-12.359 12.365-32.397 12.365-44.75 0-12.354-12.354-12.354-32.391 0-44.744L278.318 225.92 106.409 54.017c-12.354-12.359-12.354-32.394 0-44.748 12.354-12.359 32.391-12.359 44.75 0l194.287 194.284c6.177 6.18 9.262 14.271 9.262 22.366 0 8.099-3.091 16.196-9.267 22.373z" />
      </svg>
    </span>
  </button>
</template>

<script>
// Animación de "Pagar ahora" (referencia: Uiverse Admin12121/plastic-goose-38).
// El botón NO decide nada del POS: emite `press` y el padre corre sus guards.
// Si el padre confirma con controller.play(), se reproduce la secuencia
// tarjeta -> terminal -> símbolo de moneda. Nunca retrasa ni bloquea la acción.
// El estado final se mantiene mientras `hold` (modal de pago abierto) sea true y
// se libera al cerrarse; si el padre nunca lo activa, termina al acabar la secuencia.
const SEQUENCE_MS = 1500; // secuencia original: ~1.3 s
const LOCK_MS = 500;      // anti doble-click

export default {
  name: "PosPayButton",
  props: {
    label: { type: String, required: true },
    ariaLabel: { type: String, default: null },
    title: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    block: { type: Boolean, default: false },
    size: { type: String, default: "md" }, // md 44px | lg 48px
    currency: { type: String, default: "" }, // símbolo que ya usa el POS (currentUser.currency)
    hold: { type: Boolean, default: false }  // true mientras el modal de pago está abierto
  },
  watch: {
    hold(open) {
      if (!open) this.playing = false;
    }
  },
  computed: {
    // `hold` cubre cualquier vía que abra el modal (click, teclado, F4).
    active() { return this.playing || this.hold; },
    symbol() { return (this.currency || "").trim() || "$"; },
    symbolLen() { return Math.min(this.symbol.length, 4); }
  },
  data() {
    return { playing: false, locked: false, holdTimer: null, lockTimer: null };
  },
  beforeDestroy() {
    clearTimeout(this.holdTimer);
    clearTimeout(this.lockTimer);
  },
  methods: {
    onClick() {
      if (this.locked || this.disabled || this.loading) return;
      this.locked = true;
      this.lockTimer = setTimeout(() => { this.locked = false; }, LOCK_MS);
      this.$emit("press", { play: this.play });
    },
    play() {
      clearTimeout(this.holdTimer);
      // reinicia la animación si ya estaba activa
      this.playing = false;
      this.$nextTick(() => {
        this.playing = true;
        this.holdTimer = setTimeout(() => { if (!this.hold) this.playing = false; }, SEQUENCE_MS);
      });
    }
  }
};
</script>

<style lang="scss" scoped>
@mixin pay-active {
  .pos-pay__stage { width: 100%; }
  .pos-pay__card { animation: pos-pay-card 1.2s cubic-bezier(0.645, 0.045, 0.355, 1) both; }
  .pos-pay__terminal { animation: pos-pay-terminal 1s cubic-bezier(0.165, 0.84, 0.44, 1) both; }
  .pos-pay__dollar { animation: pos-pay-dollar 0.3s 1s backwards; }
}

.pos-pay {
  --_h: 44px;
  --_stage: 44px;
  --_s: 0.36; // rig original 130x120 -> escala al alto del botón
  position: relative;
  display: inline-flex;
  align-items: center;
  height: var(--_h);
  min-width: 168px;
  padding: 0;
  border: 1px solid var(--pxn-primary);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-primary);
  color: var(--pxn-primary-contrast);
  font: inherit;
  font-size: 15px;
  font-weight: var(--pxn-fw-semibold, 600);
  line-height: 1;
  white-space: nowrap;
  cursor: pointer;
  overflow: hidden;
  user-select: none;
  touch-action: manipulation;
  transition: background-color var(--pxn-dur-1) var(--pxn-ease),
    transform 0.3s ease-in-out;
}
.pos-pay.is-lg { --_h: 48px; --_stage: 48px; --_s: 0.4; }
.pos-pay.is-block { display: flex; width: 100%; }

// El POS anula el foco de todo `button` con !important (`.pos-codecanyon button:focus-visible`);
// la clase doblada sube la especificidad para que el CTA conserve un foco visible.
.pos-pay.pos-pay:focus-visible {
  outline: 2px solid var(--pxn-primary) !important;
  outline-offset: 2px !important;
}
.pos-pay[disabled] { opacity: 0.55; cursor: not-allowed; }
.pos-pay.is-loading { cursor: progress; }

.pos-pay__stage {
  position: absolute;
  inset: 0 auto 0 0;
  width: var(--_stage);
  background: var(--pxn-primary-active);
  overflow: hidden;
  z-index: 1;
  transition: width 0.3s ease-in-out;
}
// rig = el lienzo 130x120 original, centrado y escalado (conserva px y keyframes exactos)
.pos-pay__rig {
  position: absolute;
  left: 50%;
  top: 50%;
  width: 130px;
  height: 120px;
  margin: -60px 0 0 -65px;
  transform: scale(var(--_s));
}

.pos-pay__card {
  position: absolute;
  left: 30px;
  top: 37px;
  z-index: 10;
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 70px;
  height: 46px;
  border-radius: 6px;
  background: var(--pxn-surface);
  box-shadow: 9px 9px 9px -2px color-mix(in srgb, #000 35%, transparent);
}
.pos-pay__card-line {
  width: 65px;
  height: 13px;
  margin-top: 7px;
  border-radius: 2px;
  background: var(--pxn-primary-soft);
}
.pos-pay__card-dots {
  width: 8px;
  height: 8px;
  margin: 10px 0 0 -30px;
  border-radius: 50%;
  background: var(--pxn-primary);
  box-shadow: 0 -10px 0 0 var(--pxn-primary-ink), 0 10px 0 0 var(--pxn-primary-border);
  transform: rotate(90deg);
}

.pos-pay__terminal {
  position: absolute;
  left: 33px;
  top: 120px;
  z-index: 11;
  width: 63px;
  height: 75px;
  border-radius: 6px;
  background: var(--pxn-surface-3);
  overflow: hidden;
}
.pos-pay__terminal-line {
  position: absolute;
  top: 8px;
  right: 8px;
  width: 47px;
  height: 9px;
  border-radius: 0 0 3px 3px;
  background: var(--pxn-ink-2);
  &::before {
    content: "";
    position: absolute;
    top: -8px;
    width: 47px;
    height: 9px;
    background: var(--pxn-ink-3);
  }
}
.pos-pay__screen {
  position: absolute;
  top: 22px;
  right: 8px;
  width: 47px;
  height: 23px;
  border-radius: 3px;
  background: var(--pxn-surface);
}
.pos-pay__dollar {
  position: absolute;
  inset: 0 auto auto 0;
  width: 100%;
  font-size: 16px;
  text-align: center;
  color: var(--pxn-primary-ink);
  &.is-len-3 { font-size: 13px; }
  &.is-len-4 { font-size: 10px; }
}
.pos-pay__keys {
  position: absolute;
  left: 25px;
  width: 12px;
  height: 12px;
  border-radius: 2px;
  transform: rotate(90deg);
}
.pos-pay__keys--1 { top: 52px; background: var(--pxn-ink-3); box-shadow: 0 -18px 0 0 var(--pxn-ink-3), 0 18px 0 0 var(--pxn-ink-3); }
.pos-pay__keys--2 { top: 68px; background: var(--pxn-ink-disabled); box-shadow: 0 -18px 0 0 var(--pxn-ink-disabled), 0 18px 0 0 var(--pxn-ink-disabled); }

.pos-pay__body {
  display: flex;
  align-items: center;
  flex: 1;
  gap: 12px;
  padding: 0 16px 0 calc(var(--_stage) + 14px);
}
.pos-pay__label { flex: 0 1 auto; }
.pos-pay__amount { margin-left: auto; font-family: "JetBrains Mono", monospace; }
.pos-pay__arrow { flex: 0 0 auto; width: 12px; height: 12px; margin-left: auto; opacity: 0.75; }
.pos-pay__amount + .pos-pay__arrow { margin-left: 0; }
.pos-pay.is-block .pos-pay__body { justify-content: flex-start; }

.pos-pay.is-playing:not([disabled]) { @include pay-active; }
@media (hover: hover) and (pointer: fine) {
  .pos-pay:hover:not([disabled]) {
    background: var(--pxn-primary-hover);
    transform: scale(1.03);
    @include pay-active;
  }
}

@keyframes pos-pay-card {
  0% { transform: translateY(0); }
  50% { transform: translateY(-70px) rotate(90deg); }
  60% { transform: translateY(-70px) rotate(90deg); }
  100% { transform: translateY(-8px) rotate(90deg); }
}
@keyframes pos-pay-terminal {
  50% { transform: translateY(0); }
  100% { transform: translateY(-70px); }
}
@keyframes pos-pay-dollar {
  0% { opacity: 0; transform: translateY(-5px); }
  100% { opacity: 1; transform: translateY(0); }
}

// Sin movimiento: mismo estado final (terminal + "$"), sin desplazamientos ni escala.
@media (prefers-reduced-motion: reduce) {
  .pos-pay, .pos-pay__stage { transition: none; }
  .pos-pay.is-playing:not([disabled]),
  .pos-pay:hover:not([disabled]) { transform: none; }
  .pos-pay.is-playing:not([disabled]) .pos-pay__card,
  .pos-pay.is-playing:not([disabled]) .pos-pay__terminal,
  .pos-pay.is-playing:not([disabled]) .pos-pay__dollar,
  .pos-pay:hover:not([disabled]) .pos-pay__card,
  .pos-pay:hover:not([disabled]) .pos-pay__terminal,
  .pos-pay:hover:not([disabled]) .pos-pay__dollar { animation: none; }
  .pos-pay:hover:not([disabled]):not(.is-playing) .pos-pay__stage { width: var(--_stage); }
  .pos-pay.is-playing:not([disabled]) .pos-pay__card { opacity: 0; }
  .pos-pay.is-playing:not([disabled]) .pos-pay__terminal { transform: translateY(-70px); }
}
</style>
