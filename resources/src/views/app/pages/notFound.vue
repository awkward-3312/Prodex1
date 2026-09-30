<template>
  <div class="px-next pxerr">
    <div class="pxerr-hero">
      <!-- Escena decorativa: astronauta perdido en el espacio, integrada como fondo de todo el
           hero (no un panel aislado). Puramente ilustrativa — aria-hidden completo. -->
      <div class="pxerr-scene" aria-hidden="true">
        <div class="pxerr-orbit"></div>
        <div class="pxerr-glow"></div>
        <div class="pxerr-stars">
          <div class="pxerr-starlayer pxerr-starlayer--1">
            <span v-for="s in starLayer(1)" :key="'l1-' + s.i" class="pxerr-star" :class="s.cls" :style="s.style"></span>
          </div>
          <div class="pxerr-starlayer pxerr-starlayer--2">
            <span v-for="s in starLayer(2)" :key="'l2-' + s.i" class="pxerr-star" :class="s.cls" :style="s.style"></span>
          </div>
          <div class="pxerr-starlayer pxerr-starlayer--3">
            <span v-for="s in starLayer(3)" :key="'l3-' + s.i" class="pxerr-star" :class="s.cls" :style="s.style"></span>
          </div>
        </div>

        <div class="pxerr-astro">
          <!-- Canvas lógico fiel al original (250×300, coordenadas 1:1) escalado como una
               sola unidad vía `--pxerr-astro-scale`. El float/rotate vive en `.pxerr-astro`
               (outer); el scale vive aquí (inner) — nunca compiten en el mismo `transform`. -->
          <div class="pxerr-astro__scale">
            <div class="pxerr-astro__schoolbag"></div>
            <div class="pxerr-astro__body">
              <div class="pxerr-astro__panel"></div>
            </div>
            <div class="pxerr-astro__arm pxerr-astro__arm--left"></div>
            <div class="pxerr-astro__arm pxerr-astro__arm--right"></div>
            <div class="pxerr-astro__leg pxerr-astro__leg--left"></div>
            <div class="pxerr-astro__leg pxerr-astro__leg--right"></div>
            <div class="pxerr-astro__head"></div>
          </div>
        </div>
      </div>

      <div class="pxerr-content">
        <span class="pxerr-code" aria-hidden="true">404</span>
        <h1 class="pxerr-title">{{ $t('error_404_title') }}</h1>
        <p class="pxerr-message">{{ $t('error_404_lost_in_space') }}</p>
        <div class="pxerr-actions">
          <px-button class="pxerr-btn pxerr-btn--primary" variant="primary" size="lg" icon="home" @click="goHome">{{ $t('Go_back_to_home') }}</px-button>
          <px-button v-if="canGoBack" class="pxerr-btn pxerr-btn--ghost" variant="ghost" size="lg" icon="arrow-left" @click="goBack">{{ $t('Back') }}</px-button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
// 404 — hero unificado (una sola superficie navy con la escena de fondo), no dos bloques
// separados. Copy propio (error_404_title / error_404_lost_in_space): agregadas correctamente
// a ES/EN (seeders de traducciones + tabla de traducciones del tenant), sin sacrificar el
// diseño por evitar crear claves nuevas. `Go_back_to_home`/`Back` siguen siendo las claves
// existentes de siempre.
//
// Prefijo `pxerr-*`: vive fuera del sistema px-next (es una vista, no un primitive), y queda
// listo para que #16 (403) y #17 (500) reutilicen `.pxerr-hero`/`.pxerr-scene`/`.pxerr-actions`
// sin colisionar con nada — pero esa extracción no se hace en #15.
import PxButton from "@/components/px-next/PxButton.vue";

// Posiciones/tamaños en % (no px fijos): la escena escala con cualquier tamaño de hero.
// Variación deliberada de tamaño/opacidad/timing para que no se vean puntos uniformes.
const STAR_LAYERS = [
  [
    { left: 6, top: 18, size: "lg", dur: 7 }, { left: 88, top: 12, size: "md", dur: 8.5 },
    { left: 34, top: 68, size: "sm", dur: 6.2 }, { left: 60, top: 82, size: "md", dur: 9.5 },
    { left: 16, top: 46, size: "sm", dur: 7.8 }, { left: 76, top: 55, size: "lg", dur: 8 }
  ],
  [
    { left: 46, top: 8, size: "sm", dur: 10 }, { left: 92, top: 40, size: "sm", dur: 8.8 },
    { left: 10, top: 74, size: "md", dur: 9.2 }, { left: 66, top: 30, size: "sm", dur: 7.4 },
    { left: 28, top: 90, size: "sm", dur: 11 }, { left: 82, top: 78, size: "md", dur: 9.8 }
  ],
  [
    { left: 20, top: 30, size: "sm", dur: 12 }, { left: 54, top: 60, size: "sm", dur: 10.5 },
    { left: 72, top: 15, size: "sm", dur: 13 }, { left: 40, top: 85, size: "sm", dur: 11.5 },
    { left: 95, top: 64, size: "sm", dur: 12.5 }
  ]
];

export default {
  name: "PageNotFound",
  components: { PxButton },
  metaInfo: { title: "404" },
  data() {
    return {
      canGoBack: false
    };
  },
  created() {
    // Heurística segura: si no hay historial real que devuelva a algo útil dentro de PRODEX
    // (el usuario entró directo por URL), no ofrecemos "Volver atrás" — solo la salida al inicio.
    this.canGoBack = typeof window !== "undefined" && window.history.length > 1;
  },
  methods: {
    starLayer(layer) {
      return STAR_LAYERS[layer - 1].map((s, i) => ({
        i,
        cls: "pxerr-star--" + s.size,
        style: { left: s.left + "%", top: s.top + "%", animationDuration: s.dur + "s" }
      }));
    },
    goHome() {
      this.$router.push("/app/dashboard").catch(() => {});
    },
    goBack() {
      this.$router.back();
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxerr {
  min-height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--pxn-space-6);
}

.pxerr-hero {
  --pxerr-navy: #081428;
  --pxerr-navy-2: var(--prodex-ink);
  --pxerr-cyan: var(--prodex-aqua);
  --pxerr-star: #eef4ff;
  position: relative;
  overflow: hidden;
  width: 100%;
  max-width: 1200px;
  min-height: 72vh;
  max-height: 680px;
  display: grid;
  place-items: center;
  border-radius: var(--pxn-radius-lg);
  color: #fff;
  background:
    radial-gradient(60% 55% at 74% 68%, color-mix(in srgb, var(--pxerr-cyan) 20%, transparent) 0%, transparent 60%),
    radial-gradient(90% 70% at 18% 22%, var(--pxerr-navy-2) 0%, transparent 60%),
    linear-gradient(155deg, var(--pxerr-navy-2) 0%, var(--pxerr-navy) 68%);
}

.pxerr-scene { position: absolute; inset: 0; z-index: 0; }
.pxerr-orbit {
  position: absolute;
  top: 8%; right: -12%;
  width: 62%; aspect-ratio: 1;
  border: 1px solid rgba(6, 182, 212, 0.16);
  border-radius: 50%;
  transform: rotate(-18deg);
}
.pxerr-glow {
  position: absolute;
  top: 30%; right: 10%;
  width: 34%; aspect-ratio: 1;
  border-radius: 50%;
  background: radial-gradient(circle, color-mix(in srgb, var(--pxerr-cyan) 35%, transparent) 0%, transparent 70%);
  filter: blur(6px);
}

.pxerr-stars { position: absolute; inset: 0; }
.pxerr-starlayer { position: absolute; inset: 0; }
.pxerr-star {
  position: absolute;
  border-radius: 50%;
  background: var(--pxerr-star);
  opacity: 0;
  animation-name: pxerr-twinkle-fall;
  animation-timing-function: linear;
  animation-iteration-count: infinite;
}
.pxerr-star--sm { width: 2px; height: 2px; }
.pxerr-star--md { width: 3px; height: 3px; }
.pxerr-star--lg { width: 4px; height: 4px; box-shadow: 0 0 4px 1px rgba(255, 255, 255, 0.35); }
.pxerr-starlayer--2 .pxerr-star { animation-delay: -3s; opacity: 0; }
.pxerr-starlayer--3 .pxerr-star { animation-delay: -6s; }

@keyframes pxerr-twinkle-fall {
  0% { opacity: 0; transform: translateY(0); }
  12% { opacity: .9; }
  100% { opacity: 0; transform: translateY(120%); }
}

/* Astronauta: geometría fiel 1:1 al original de Uiverse (canvas lógico 250×300, todas las
   piezas en las coordenadas exactas del original — nada reinterpretado). Dos capas con
   responsabilidades separadas para que scale() y float/rotate() nunca compitan en el mismo
   `transform`:
   OUTER (.pxerr-astro): posición dentro de la escena + flotación (translateY + rotate parcial,
     NO el giro completo de 360° del original).
   INNER (.pxerr-astro__scale): fija 250×300, solo `transform: scale(--pxerr-astro-scale)` desde
     la esquina superior izquierda — así su huella visual coincide exactamente con el tamaño
     (250·scale × 300·scale) reservado por el outer, y toda pieza interna escala como UNA unidad. */
.pxerr-astro {
  --pxerr-astro-scale: 0.82;
  position: absolute;
  z-index: 2;
  top: 50%; right: 10%;
  width: calc(250px * var(--pxerr-astro-scale));
  height: calc(300px * var(--pxerr-astro-scale));
  margin-top: calc(-150px * var(--pxerr-astro-scale));
  animation: pxerr-float 6.5s ease-in-out infinite;
}
.pxerr-astro__scale {
  position: absolute;
  top: 0; left: 0;
  width: 250px; height: 300px;
  transform: scale(var(--pxerr-astro-scale));
  transform-origin: top left;
}
@keyframes pxerr-float {
  0%   { transform: translateY(0) rotate(-4deg); }
  50%  { transform: translateY(-10px) rotate(4deg); }
  100% { transform: translateY(0) rotate(-4deg); }
}

.pxerr-astro__schoolbag {
  position: absolute; z-index: 1;
  width: 100px; height: 150px;
  top: calc(50% - 75px); left: calc(50% - 50px);
  background: #94b7ca;
  border-radius: 50px 50px 0 0 / 30px 30px 0 0;
}
.pxerr-astro__body {
  position: absolute; z-index: 2;
  width: 85px; height: 100px;
  top: 105px; left: calc(50% - 41px);
  background: linear-gradient(90deg, #e3e8eb 0%, #e3e8eb 50%, #fbfdfa 50%, #fbfdfa 100%);
  border-radius: 40px / 20px;
}
.pxerr-astro__panel {
  position: absolute;
  width: 60px; height: 40px;
  top: 20px; left: calc(50% - 30px);
  background: #b7cceb;
}
.pxerr-astro__panel::before {
  content: "";
  position: absolute;
  width: 30px; height: 5px;
  top: 9px; left: 7px;
  background: #fbfdfa;
  box-shadow: 0 9px 0 #fbfdfa, 0 18px 0 #fbfdfa;
}
.pxerr-astro__panel::after {
  content: "";
  position: absolute;
  width: 8px; height: 8px;
  top: 9px; right: 7px;
  background: #fbfdfa;
  border-radius: 50%;
  box-shadow: 0 14px 0 2px #fbfdfa;
}
.pxerr-astro__arm { position: absolute; z-index: 2; width: 80px; height: 30px; top: 121px; }
.pxerr-astro__arm--left { left: 30px; background: #e3e8eb; border-radius: 0 0 0 39px; }
.pxerr-astro__arm--right { right: 30px; background: #fbfdfa; border-radius: 0 0 39px 0; }
.pxerr-astro__arm--left::before,
.pxerr-astro__arm--right::before {
  content: "";
  position: absolute;
  width: 30px; height: 70px;
  top: -40px;
}
.pxerr-astro__arm--left::before { left: 0; background: #e3e8eb; border-radius: 50px 50px 0 120px / 50px 50px 0 110px; }
.pxerr-astro__arm--right::before { right: 0; background: #fbfdfa; border-radius: 50px 50px 120px 0 / 50px 50px 110px 0; }
.pxerr-astro__arm--left::after,
.pxerr-astro__arm--right::after {
  content: "";
  position: absolute;
  width: 30px; height: 10px;
  top: -24px;
}
.pxerr-astro__arm--left::after { left: 0; background: #6e91a4; }
.pxerr-astro__arm--right::after { right: 0; background: #b6d2e0; }
.pxerr-astro__leg { position: absolute; z-index: 2; width: 30px; height: 40px; bottom: 70px; }
.pxerr-astro__leg--left { left: 76px; background: #e3e8eb; transform: rotate(20deg); }
.pxerr-astro__leg--right { right: 73px; background: #fbfdfa; transform: rotate(-20deg); }
.pxerr-astro__leg--left::before,
.pxerr-astro__leg--right::before {
  content: "";
  position: absolute;
  width: 50px; height: 25px;
  bottom: -26px;
}
.pxerr-astro__leg--left::before { left: -20px; background: #e3e8eb; border-radius: 30px 0 0 0; border-bottom: 10px solid #6d96ac; }
.pxerr-astro__leg--right::before { right: -20px; background: #fbfdfa; border-radius: 0 30px 0 0; border-bottom: 10px solid #b0cfe4; }
.pxerr-astro__head {
  position: absolute; z-index: 3;
  width: 97px; height: 80px;
  top: 34px; left: calc(50% - 47.5px);
  background: linear-gradient(90deg, #e3e8eb 0%, #e3e8eb 50%, #fbfdfa 50%, #fbfdfa 100%);
  border-radius: 50%;
}
.pxerr-astro__head::after {
  content: "";
  position: absolute;
  width: 60px; height: 50px;
  top: calc(50% - 25px); left: calc(50% - 30px);
  background: linear-gradient(180deg, #15aece 0%, #15aece 50%, #0391bf 50%, #0391bf 100%);
  border-radius: 15px;
}
.pxerr-astro__head::before {
  content: "";
  position: absolute;
  width: 12px; height: 25px;
  top: calc(50% - 12.5px); left: -4px;
  background: #618095;
  border-radius: 5px;
  box-shadow: 92px 0 0 #618095;
}

/* -------------------------------------------------------------------------
   Contenido: comparte la misma superficie navy (nada de card aislada).
   ------------------------------------------------------------------------- */
.pxerr-content {
  position: relative;
  z-index: 3;
  width: 100%;
  max-width: 640px;
  padding: var(--pxn-space-11) var(--pxn-space-11) var(--pxn-space-11) clamp(28px, 6vw, 72px);
  display: flex;
  flex-direction: column;
  gap: var(--pxn-space-4);
}

.pxerr-code {
  display: block;
  font-size: clamp(88px, 13vw, 148px);
  font-weight: var(--pxn-fw-bold);
  line-height: .9;
  letter-spacing: -0.03em;
  background: linear-gradient(180deg, #ffffff 0%, var(--pxerr-cyan) 130%);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  text-shadow: 0 0 34px color-mix(in srgb, var(--pxerr-cyan) 45%, transparent);
}
.pxerr-title {
  margin: 0;
  font-size: clamp(22px, 3vw, 28px);
  font-weight: var(--pxn-fw-semibold);
  color: #fff;
}
.pxerr-message {
  margin: 0;
  max-width: 42ch;
  font-size: var(--pxn-fs-body);
  color: rgba(255, 255, 255, 0.72);
}
.pxerr-actions { display: flex; flex-wrap: wrap; gap: var(--pxn-space-4); margin-top: var(--pxn-space-4); }

/* PxButton asume superficie clara (ghost/secondary): sobre esta superficie navy sólo el
   botón primario funciona sin retoque; el secundario se adapta aquí, de forma escoped,
   sin tocar PxButton.vue. */
.pxerr-btn--ghost {
  :deep(.pxn-btn){ color: #fff; }
}
.pxerr-actions :deep(.pxn-btn--ghost){
  border: 1px solid rgba(255, 255, 255, 0.28);
  color: #fff;
}
.pxerr-actions :deep(.pxn-btn--ghost:hover:not([disabled])){
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
}
.pxerr-actions :deep(.pxn-btn--ghost:focus-visible){
  box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.45);
}

@media (max-width: 860px) {
  .pxerr-hero { grid-template-columns: none; min-height: auto; }
  /* `top` fijo en px (no % del alto total de .pxerr-content, que varía con el largo del
     mensaje): así el astronauta siempre queda dentro del padding-top reservado, sin
     solaparse nunca con el 404/título de abajo. Solo cambia el multiplicador de escala y
     el anclaje horizontal — la geometría interna (.pxerr-astro__scale) no se toca. */
  .pxerr-astro {
    --pxerr-astro-scale: 0.5;
    top: 20px; left: 50%; right: auto; margin-top: 0;
    margin-left: calc(-125px * var(--pxerr-astro-scale));
  }
  .pxerr-content {
    max-width: none;
    align-items: center;
    text-align: center;
    padding: 244px var(--pxn-space-6) var(--pxn-space-8);
  }
  .pxerr-message { max-width: 34ch; }
  .pxerr-actions { justify-content: center; width: 100%; }
  .pxerr-actions :deep(.pxn-btn){ flex: 1 1 auto; min-width: 0; }
}

@media (min-width: 861px) and (max-width: 1080px) {
  .pxerr-content { max-width: 460px; padding-right: var(--pxn-space-8); }
  .pxerr-astro { --pxerr-astro-scale: 0.62; right: 6%; }
}

@media (prefers-reduced-motion: reduce) {
  .pxerr-star, .pxerr-astro { animation: none; }
  .pxerr-star { opacity: .6; }
  .pxerr-astro { transform: none; }
}
</style>
