<template>
  <div class="pxn-loader" :class="`pxn-loader--${size}`" role="status">
    <span class="pxn-loader__stage" aria-hidden="true">
      <span class="pxn-loader__layer pxn-loader__layer--1"></span>
      <span class="pxn-loader__layer pxn-loader__layer--2"></span>
      <span class="pxn-loader__layer pxn-loader__layer--3"></span>
    </span>
    <span v-if="label" class="pxn-loader__label" :class="{ 'is-sr': hideLabel }">{{ label }}</span>
  </div>
</template>

<script>
// PxLoader: "hay una operación en curso" (guardando, generando, importando…). Solo visual: el padre decide
// cuándo montarlo y qué texto lleva. No sustituye a PxSkeleton (carga de contenido con estructura).
// Referencia: Uiverse Nawsome/ugly-skunk-66 (tres capas que cambian de ancho, alto, posición y radio, 3 s).
export default {
  name: "PxLoader",
  props: {
    size: { type: String, default: "md" }, // sm | md | lg
    label: { type: String, default: "" }, // texto de estado; sin label el loader no se anuncia
    hideLabel: { type: Boolean, default: false } // oculta el texto solo visualmente (sigue accesible)
  }
};
</script>

<style lang="scss" scoped>
// --_u = 1 unidad del diseño original (lg = 160×100 px). Todo se deriva de esta variable: no hay transform:scale(),
// así que el layout reserva exactamente el tamaño escalado.
.pxn-loader { --_u: 0.6px; display: inline-flex; flex-direction: column; align-items: center; gap: var(--pxn-space-4); max-width: 100%; }
.pxn-loader--sm { --_u: 0.3px; }
.pxn-loader--md { --_u: 0.6px; }
.pxn-loader--lg { --_u: 1px; }

// Escenario propio: el loader nunca se ancla a la página. `contain` limita el reflow de width/height a estas 3 capas.
.pxn-loader__stage {
  position: relative;
  flex: none;
  display: block;
  width: calc(160 * var(--_u));
  height: calc(130 * var(--_u));
  contain: layout paint size style;
}
// El origen (0,0) del diseño original está a 60 u del borde superior y en el centro horizontal.
.pxn-loader__layer {
  position: absolute;
  left: 50%;
  top: calc(60 * var(--_u));
  animation-duration: 3s;
  animation-timing-function: cubic-bezier(0.55, 0.3, 0.24, 0.99);
  animation-iteration-count: infinite;
}
.pxn-loader__layer--1 {
  z-index: 10;
  width: calc(160 * var(--_u)); height: calc(100 * var(--_u));
  margin-left: calc(-80 * var(--_u)); margin-top: calc(-50 * var(--_u));
  border-radius: calc(5 * var(--_u));
  background: var(--pxn-primary-ink);
  animation-name: pxn-loader-dot1;
}
.pxn-loader__layer--2 {
  z-index: 11;
  width: calc(150 * var(--_u)); height: calc(90 * var(--_u));
  margin-left: calc(-75 * var(--_u)); margin-top: calc(-45 * var(--_u));
  border-radius: calc(3 * var(--_u));
  background: var(--pxn-primary);
  animation-name: pxn-loader-dot2;
}
.pxn-loader__layer--3 {
  z-index: 12;
  width: calc(40 * var(--_u)); height: calc(20 * var(--_u));
  margin-left: calc(-20 * var(--_u)); margin-top: calc(50 * var(--_u));
  border-radius: 0 0 calc(5 * var(--_u)) calc(5 * var(--_u));
  background: var(--pxn-primary-border);
  animation-name: pxn-loader-dot3;
}

.pxn-loader__label { font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); text-align: center; line-height: var(--pxn-lh-snug); }
.pxn-loader__label.is-sr {
  position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden;
  clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
}

// Coreografía original (3%,97% / 30%,36% / 63%,69%), en unidades --_u.
@keyframes pxn-loader-dot1 {
  3%, 97% { width: calc(160 * var(--_u)); height: calc(100 * var(--_u)); margin-top: calc(-50 * var(--_u)); margin-left: calc(-80 * var(--_u)); }
  30%, 36% { width: calc(80 * var(--_u)); height: calc(120 * var(--_u)); margin-top: calc(-60 * var(--_u)); margin-left: calc(-40 * var(--_u)); }
  63%, 69% { width: calc(40 * var(--_u)); height: calc(80 * var(--_u)); margin-top: calc(-40 * var(--_u)); margin-left: calc(-20 * var(--_u)); }
}
@keyframes pxn-loader-dot2 {
  3%, 97% { height: calc(90 * var(--_u)); width: calc(150 * var(--_u)); margin-left: calc(-75 * var(--_u)); margin-top: calc(-45 * var(--_u)); }
  30%, 36% { width: calc(70 * var(--_u)); height: calc(96 * var(--_u)); margin-left: calc(-35 * var(--_u)); margin-top: calc(-48 * var(--_u)); }
  63%, 69% { width: calc(32 * var(--_u)); height: calc(60 * var(--_u)); margin-left: calc(-16 * var(--_u)); margin-top: calc(-30 * var(--_u)); }
}
@keyframes pxn-loader-dot3 {
  3%, 97% { height: calc(20 * var(--_u)); width: calc(40 * var(--_u)); margin-left: calc(-20 * var(--_u)); margin-top: calc(50 * var(--_u)); }
  30%, 36% { width: calc(8 * var(--_u)); height: calc(8 * var(--_u)); margin-left: calc(-5 * var(--_u)); margin-top: calc(49 * var(--_u)); border-radius: calc(8 * var(--_u)); }
  63%, 69% { width: calc(16 * var(--_u)); height: calc(4 * var(--_u)); margin-left: calc(-8 * var(--_u)); margin-top: calc(-37 * var(--_u)); border-radius: calc(10 * var(--_u)); }
}

// Sin movimiento: estado en reposo reconocible (las tres capas apiladas); el texto de estado se conserva.
@media (prefers-reduced-motion: reduce) {
  .pxn-loader__layer { animation: none; }
}
</style>
