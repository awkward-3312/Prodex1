<template>
  <div class="px-next pxerr">
    <div class="pxerr-hero pxerr-hero--pencil">
      <!-- Escena decorativa: geometría 1:1 de Uiverse (gustavofusco/rare-pug-90), fuente de
           verdad para el lápiz — no se redibuja ni se simplifica. Puramente ilustrativa —
           aria-hidden completo, sin anunciar circle/polygon/eraser. -->
      <div class="pxerr-scene" aria-hidden="true">
        <div class="pxerr-halo"></div>
        <svg xmlns="http://www.w3.org/2000/svg" height="200px" width="200px" viewBox="0 0 200 200" class="pencil" aria-hidden="true" focusable="false">
          <defs>
            <clipPath id="pxerr403-pencil-eraser-spa">
              <rect height="30" width="30" ry="5" rx="5"></rect>
            </clipPath>
          </defs>
          <circle transform="rotate(-113,100,100)" stroke-linecap="round" stroke-dashoffset="439.82" stroke-dasharray="439.82 439.82" stroke-width="2" stroke="currentColor" fill="none" r="70" class="pencil__stroke"></circle>
          <g transform="translate(100,100)" class="pencil__rotate">
            <g fill="none">
              <circle transform="rotate(-90)" stroke-dashoffset="402" stroke-dasharray="402.12 402.12" stroke-width="30" stroke="#12467a" r="64" class="pencil__body1"></circle>
              <circle transform="rotate(-90)" stroke-dashoffset="465" stroke-dasharray="464.96 464.96" stroke-width="10" stroke="#06b6d4" r="74" class="pencil__body2"></circle>
              <circle transform="rotate(-90)" stroke-dashoffset="339" stroke-dasharray="339.29 339.29" stroke-width="10" stroke="#0b1f3a" r="54" class="pencil__body3"></circle>
            </g>
            <g transform="rotate(-90) translate(49,0)" class="pencil__eraser">
              <g class="pencil__eraser-skew">
                <rect height="30" width="30" ry="5" rx="5" fill="#8fd7e6"></rect>
                <rect clip-path="url(#pxerr403-pencil-eraser-spa)" height="30" width="5" fill="#3fb6cf"></rect>
                <rect height="20" width="30" fill="#eef2f5"></rect>
                <rect height="20" width="15" fill="#b9c2c9"></rect>
                <rect height="20" width="5" fill="#d6dde1"></rect>
                <rect height="2" width="30" y="6" fill="rgba(15,23,42,0.2)"></rect>
                <rect height="2" width="30" y="13" fill="rgba(15,23,42,0.2)"></rect>
              </g>
            </g>
            <g transform="rotate(-90) translate(49,-30)" class="pencil__point">
              <polygon points="15 0,30 30,0 30" fill="hsl(33,90%,70%)"></polygon>
              <polygon points="15 0,6 30,0 30" fill="hsl(33,90%,50%)"></polygon>
              <polygon points="15 0,20 10,10 10" fill="hsl(223,10%,10%)"></polygon>
            </g>
          </g>
        </svg>
      </div>

      <div class="pxerr-content">
        <span class="pxerr-code" aria-hidden="true">403</span>
        <h1 class="pxerr-title">{{ $t('error_403_title') }}</h1>
        <p class="pxerr-message">{{ $t('error_403_no_permission') }}</p>
        <div class="pxerr-actions">
          <px-button class="pxerr-btn pxerr-btn--primary" variant="primary" size="lg" icon="home" @click="goHome">{{ $t('Go_back_to_home') }}</px-button>
          <px-button v-if="canGoBack" class="pxerr-btn pxerr-btn--ghost" variant="ghost" size="lg" icon="arrow-left" @click="goBack">{{ $t('Back') }}</px-button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
// 403 — misma familia visual que #15 (tipografía, spacing, botones, tokens), concepto propio:
// "acceso restringido", no "ruta perdida en el espacio". Reutiliza el SVG original de Uiverse
// (gustavofusco/rare-pug-90) 1:1 — geometría, clipPath, stroke-dasharray y las 8 animaciones
// (pencilBody1/2/3, pencilEraser, pencilEraserSkew, pencilPoint, pencilRotate, pencilStroke) sin
// redibujar ni simplificar. Solo la paleta se mapea a navy/cyan PRODEX (wood/graphite del punto
// se conservan para que siga leyéndose como lápiz).
//
// Este 403 SÍ tiene un flujo real: el interceptor global de axios (`main.js`) hace
// `router.push({name:'not_authorize'})` cuando una carga de página (GET, no una acción puntual)
// recibe 403 del backend — confirmado con un usuario QA sin el permiso de una sección real.
import PxButton from "@/components/px-next/PxButton.vue";

export default {
  name: "PageNotAuthorized",
  components: { PxButton },
  metaInfo: { title: "403" },
  data() {
    return {
      canGoBack: false
    };
  },
  created() {
    this.canGoBack = typeof window !== "undefined" && window.history.length > 1;
  },
  methods: {
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
  position: relative;
  overflow: hidden;
  width: 100%;
  max-width: 1200px;
  min-height: 72vh;
  max-height: 680px;
  display: grid;
  place-items: center;
  border-radius: var(--pxn-radius-lg);
  background: var(--pxn-bg);
  border: 1px solid var(--pxn-border);
}

.pxerr-scene {
  position: absolute;
  z-index: 0;
  top: 50%; right: 6%;
  transform: translateY(-50%);
  width: clamp(220px, 30vw, 340px);
  height: clamp(220px, 30vw, 340px);
  display: flex;
  align-items: center;
  justify-content: center;
}
.pxerr-halo {
  position: absolute;
  inset: 8%;
  border-radius: 50%;
  background: radial-gradient(circle, color-mix(in srgb, var(--pxn-primary) 16%, transparent) 0%, color-mix(in srgb, #0f2a4a 10%, transparent) 55%, transparent 78%);
}

.pencil {
  position: relative;
  z-index: 1;
  display: block;
  width: clamp(160px, 20vw, 220px);
  height: auto;
  color: var(--pxn-ink-3);
}
.pencil__body1, .pencil__body2, .pencil__body3, .pencil__eraser, .pencil__eraser-skew, .pencil__point, .pencil__rotate, .pencil__stroke {
  animation-duration: 3s;
  animation-timing-function: linear;
  animation-iteration-count: infinite;
}
.pencil__body1, .pencil__body2, .pencil__body3 { transform: rotate(-90deg); }
.pencil__body1 { animation-name: pencilBody1; }
.pencil__body2 { animation-name: pencilBody2; }
.pencil__body3 { animation-name: pencilBody3; }
.pencil__eraser { animation-name: pencilEraser; transform: rotate(-90deg) translate(49px, 0); }
.pencil__eraser-skew { animation-name: pencilEraserSkew; animation-timing-function: ease-in-out; }
.pencil__point { animation-name: pencilPoint; transform: rotate(-90deg) translate(49px, -30px); }
.pencil__rotate { animation-name: pencilRotate; }
.pencil__stroke { animation-name: pencilStroke; transform: translate(100px, 100px) rotate(-113deg); }

@keyframes pencilBody1 {
  from, to { stroke-dashoffset: 351.86; transform: rotate(-90deg); }
  50% { stroke-dashoffset: 150.8; transform: rotate(-225deg); }
}
@keyframes pencilBody2 {
  from, to { stroke-dashoffset: 406.84; transform: rotate(-90deg); }
  50% { stroke-dashoffset: 174.36; transform: rotate(-225deg); }
}
@keyframes pencilBody3 {
  from, to { stroke-dashoffset: 296.88; transform: rotate(-90deg); }
  50% { stroke-dashoffset: 127.23; transform: rotate(-225deg); }
}
@keyframes pencilEraser {
  from, to { transform: rotate(-45deg) translate(49px, 0); }
  50% { transform: rotate(0deg) translate(49px, 0); }
}
@keyframes pencilEraserSkew {
  from, 32.5%, 67.5%, to { transform: skewX(0); }
  35%, 65% { transform: skewX(-4deg); }
  37.5%, 62.5% { transform: skewX(8deg); }
  40%, 45%, 50%, 55%, 60% { transform: skewX(-15deg); }
  42.5%, 47.5%, 52.5%, 57.5% { transform: skewX(15deg); }
}
@keyframes pencilPoint {
  from, to { transform: rotate(-90deg) translate(49px, -30px); }
  50% { transform: rotate(-225deg) translate(49px, -30px); }
}
@keyframes pencilRotate {
  from { transform: translate(100px, 100px) rotate(0); }
  to { transform: translate(100px, 100px) rotate(720deg); }
}
@keyframes pencilStroke {
  from { stroke-dashoffset: 439.82; transform: translate(100px, 100px) rotate(-113deg); }
  50% { stroke-dashoffset: 164.93; transform: translate(100px, 100px) rotate(-113deg); }
  75%, to { stroke-dashoffset: 439.82; transform: translate(100px, 100px) rotate(112deg); }
}
/* stroke-dashoffset es un atributo SVG, no una propiedad CSS: al quitar la animación cada pieza
   vuelve a su transform/atributo ya definidos arriba (los mismos del original) — nada queda
   girado a medias ni con el trazo incompleto. */
@media (prefers-reduced-motion: reduce) {
  .pencil__body1, .pencil__body2, .pencil__body3, .pencil__eraser, .pencil__eraser-skew, .pencil__point, .pencil__rotate, .pencil__stroke {
    animation: none;
  }
}

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
  background: linear-gradient(180deg, #0f2a4a 0%, var(--pxn-primary) 130%);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}
.pxerr-title {
  margin: 0;
  font-size: clamp(22px, 3vw, 28px);
  font-weight: var(--pxn-fw-semibold);
  color: var(--pxn-ink);
}
.pxerr-message {
  margin: 0;
  max-width: 42ch;
  font-size: var(--pxn-fs-body);
  color: var(--pxn-ink-2);
}
.pxerr-actions { display: flex; flex-wrap: wrap; gap: var(--pxn-space-4); margin-top: var(--pxn-space-4); }

@media (max-width: 860px) {
  .pxerr-hero { min-height: auto; padding-bottom: var(--pxn-space-6); }
  .pxerr-scene { position: relative; top: 0; right: 0; transform: none; margin: var(--pxn-space-8) auto 0; width: clamp(160px, 42vw, 220px); height: clamp(160px, 42vw, 220px); order: 1; }
  .pencil { width: clamp(130px, 38vw, 180px); }
  .pxerr-content { order: 2; max-width: none; align-items: center; text-align: center; padding: var(--pxn-space-6); }
  .pxerr-message { max-width: 34ch; }
  .pxerr-actions { justify-content: center; width: 100%; }
  .pxerr-actions :deep(.pxn-btn){ flex: 1 1 auto; min-width: 0; }
  .pxerr-hero { display: flex; flex-direction: column; align-items: center; }
}

@media (min-width: 861px) and (max-width: 1080px) {
  .pxerr-content { max-width: 460px; padding-right: var(--pxn-space-8); }
  .pxerr-scene { right: 4%; width: clamp(190px, 26vw, 260px); height: clamp(190px, 26vw, 260px); }
}
</style>
