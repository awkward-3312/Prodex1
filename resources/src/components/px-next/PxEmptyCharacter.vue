<template>
  <span class="pxn-ec" :class="{ 'is-calm': calm }" aria-hidden="true">
    <span class="pxn-ec__body">
      <span class="pxn-ec__pupil pxn-ec__pupil--l"></span>
      <span class="pxn-ec__pupil pxn-ec__pupil--r"></span>
      <span class="pxn-ec__eye pxn-ec__eye--l"></span>
      <span class="pxn-ec__eye pxn-ec__eye--r"></span>
      <span v-for="a in solid" :key="a" class="pxn-ec__cell" :style="{ gridArea: a }"></span>
      <span v-for="c in feet" :key="c.a" class="pxn-ec__cell" :class="c.f ? 'is-f1' : 'is-f0'" :style="{ gridArea: c.a }"></span>
    </span>
    <span class="pxn-ec__shadow"></span>
  </span>
</template>

<script>
// Criatura pixelada del estado vacío (referencia: Uiverse BlackisPlay/sweet-frog-67). Solo CSS, decorativa.
// Grid 14×14 con las mismas áreas del original; los "pies" (an1…an18) alternan flicker0/flicker1.
const SOLID = ["top0", "top1", "top2", "top3", "top4", "st0", "st1", "st2", "st3", "st4", "st5"];
// flicker0: visible en la 1.ª mitad del ciclo · flicker1: visible en la 2.ª (0 = flicker0, 1 = flicker1)
const F = { an1: 0, an18: 0, an2: 1, an17: 1, an3: 1, an16: 1, an4: 1, an15: 1, an6: 0, an12: 0, an7: 0, an13: 0, an9: 1, an10: 1, an8: 0, an11: 0 };
// an5 y an14 existen en el grid pero, como en el original, no tienen relleno ni animación.
const FEET = Object.keys(F).map(a => ({ a, f: F[a] === 1 }));

export default {
  name: "PxEmptyCharacter",
  props: {
    calm: { type: Boolean, default: false } // cadencia más tranquila (1.2 s / 4 s) para comparar con la original (0.5 s / 3 s)
  },
  computed: {
    solid() { return SOLID; },
    feet() { return FEET; }
  }
};
</script>

<style lang="scss" scoped>
// --_c = una celda del grid (el original usa 10 px con scale 0.8 → 8 px). Todo el dibujo deriva de esta medida,
// así el stage ocupa su tamaño real (sin scale) y respeta el layout.
.pxn-ec { --_c: 8px; --_t: 0.5s; --_te: 3s; position: relative; display: block; flex: none; width: calc(14 * var(--_c)); height: calc(14 * var(--_c)); margin: var(--_c) 0 calc(5 * var(--_c)); } /* el margen inferior reserva el espacio de la sombra */
.pxn-ec.is-calm { --_t: 1.2s; --_te: 4s; }

.pxn-ec__body {
  position: relative;
  display: grid;
  width: 100%; height: 100%;
  grid-template-columns: repeat(14, 1fr);
  grid-template-rows: repeat(14, 1fr);
  grid-template-areas:
    "a1  a2  a3  a4  a5  top0  top0  top0  top0  a10 a11 a12 a13 a14"
    "b1  b2  b3  top1 top1 top1 top1 top1 top1 top1 top1 b12 b13 b14"
    "c1 c2 top2 top2 top2 top2 top2 top2 top2 top2 top2 top2 c13 c14"
    "d1 top3 top3 top3 top3 top3 top3 top3 top3 top3 top3 top3 top3 d14"
    "e1 top3 top3 top3 top3 top3 top3 top3 top3 top3 top3 top3 top3 e14"
    "f1 top3 top3 top3 top3 top3 top3 top3 top3 top3 top3 top3 top3 f14"
    "top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4"
    "top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4"
    "top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4"
    "top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4"
    "top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4"
    "top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4 top4"
    "st0 st0 an4 st1 an7 st2 an10 an10 st3 an13 st4 an16 st5 st5"
    "an1 an2 an3 an5 an6 an8 an9 an9 an11 an12 an14 an15 an17 an18";
  animation: pxn-ec-updown var(--_t) infinite;
}

.pxn-ec__cell { background-color: var(--pxn-primary); }
.pxn-ec__cell.is-f0 { animation: pxn-ec-flicker0 var(--_t) infinite; }
.pxn-ec__cell.is-f1 { animation: pxn-ec-flicker1 var(--_t) infinite; }

// Ojos: forma de "L" invertida hecha con dos rectángulos (::before 2×5 celdas, ::after 4×3), como el original.
.pxn-ec__eye { position: absolute; top: calc(3 * var(--_c)); width: calc(4 * var(--_c)); height: calc(5 * var(--_c)); }
.pxn-ec__eye--l { left: var(--_c); }
.pxn-ec__eye--r { right: calc(3 * var(--_c)); }
.pxn-ec__eye::before, .pxn-ec__eye::after { content: ""; position: absolute; display: block; background-color: var(--pxn-surface); }
.pxn-ec__eye::before { width: calc(2 * var(--_c)); height: calc(5 * var(--_c)); transform: translateX(var(--_c)); }
.pxn-ec__eye::after { width: calc(4 * var(--_c)); height: calc(3 * var(--_c)); transform: translateY(var(--_c)); }

.pxn-ec__pupil {
  position: absolute; z-index: 1; top: calc(5 * var(--_c));
  width: calc(2 * var(--_c)); height: calc(2 * var(--_c));
  background-color: var(--pxn-primary-ink);
  animation: pxn-ec-eyes var(--_te) infinite;
}
.pxn-ec__pupil--l { left: var(--_c); }
.pxn-ec__pupil--r { right: calc(5 * var(--_c)); }

// Sombra: elipse desenfocada bajo el cuerpo; solo cambia su opacidad (compositor), el blur se rasteriza una vez.
.pxn-ec__shadow {
  position: absolute; top: 80%; left: 0; display: block;
  width: 100%; height: 100%;
  border-radius: 50%;
  background-color: var(--pxn-ink);
  transform: rotateX(80deg);
  filter: blur(calc(2 * var(--_c)));
  animation: pxn-ec-shadow var(--_t) infinite;
}

@keyframes pxn-ec-updown { 0%, 49% { transform: translateY(0); } 50%, 100% { transform: translateY(calc(-1 * var(--_c))); } }
@keyframes pxn-ec-flicker0 { 0%, 49% { background-color: var(--pxn-primary); } 50%, 100% { background-color: transparent; } }
@keyframes pxn-ec-flicker1 { 0%, 49% { background-color: transparent; } 50%, 100% { background-color: var(--pxn-primary); } }
@keyframes pxn-ec-eyes { 0%, 49% { transform: translateX(0); } 50%, 99% { transform: translateX(var(--_c)); } 100% { transform: translateX(0); } }
@keyframes pxn-ec-shadow { 0%, 49% { opacity: 0.5; } 50%, 100% { opacity: 0.2; } }

// Sin movimiento: pose estática coherente (cuerpo abajo, pies alternados a la mitad, pupilas al centro).
@media (prefers-reduced-motion: reduce) {
  .pxn-ec__body, .pxn-ec__pupil, .pxn-ec__shadow { animation: none; }
  .pxn-ec__cell.is-f0, .pxn-ec__cell.is-f1 { animation: none; }
  .pxn-ec__cell.is-f1 { background-color: transparent; }
  .pxn-ec__shadow { opacity: 0.35; }
}
</style>
