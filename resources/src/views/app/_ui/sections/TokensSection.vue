<template>
  <section>
    <section-head
      num="01"
      title="Tokens"
      desc="Todo el sistema se construye sobre estas variables. Color, espaciado, radios, sombras y motion — con la sombra reservada exclusivamente a elementos flotantes."
    />

    <div class="pxn-stack pxn-stack-8">
      <!-- color -->
      <div>
        <h3 class="tk-h3">Color · neutrales</h3>
        <div class="tk-swatches">
          <div v-for="c in neutrals" :key="c.var" class="tk-swatch">
            <span class="tk-swatch__chip" :style="{ background: `var(${c.var})` }"></span>
            <span class="tk-swatch__meta"><code>{{ c.var }}</code><small>{{ c.use }}</small></span>
          </div>
        </div>
      </div>

      <div>
        <h3 class="tk-h3">Color · primario del tenant <span class="tk-note">se deriva de <code>--primary-color</code> con <code>color-mix</code></span></h3>
        <div class="tk-swatches">
          <div v-for="c in primary" :key="c.var" class="tk-swatch">
            <span class="tk-swatch__chip" :style="{ background: `var(${c.var})` }"></span>
            <span class="tk-swatch__meta"><code>{{ c.var }}</code><small>{{ c.use }}</small></span>
          </div>
        </div>
      </div>

      <div>
        <h3 class="tk-h3">Color · semántico <span class="tk-note">solo estados — nunca decoración; siempre con icono + texto</span></h3>
        <div class="tk-swatches">
          <div v-for="c in semantic" :key="c.name" class="tk-swatch">
            <span class="tk-swatch__chip" :style="{ background: `var(--pxn-${c.name})` }"></span>
            <span class="tk-swatch__chip tk-swatch__chip--soft" :style="{ background: `var(--pxn-${c.name}-soft)`, borderColor: `var(--pxn-${c.name}-border)` }"></span>
            <span class="tk-swatch__meta"><code>--pxn-{{ c.name }}</code><small>{{ c.use }}</small></span>
          </div>
        </div>
      </div>

      <div>
        <h3 class="tk-h3">Color · etiquetas de entidad <span class="tk-note">auxiliar · baja saturación · separada del primario y de los estados · el color nunca es el único significado</span></h3>
        <div class="tk-swatches">
          <div v-for="h in tagHues" :key="h" class="tk-swatch">
            <span class="tk-swatch__chip tk-swatch__chip--soft" :style="{ background: `var(--pxn-tag-${h}-soft)` }">
              <span class="tk-tagdot" :style="{ background: `var(--pxn-tag-${h})` }"></span>
            </span>
            <span class="tk-swatch__meta"><code>--pxn-tag-{{ h }}</code></span>
          </div>
        </div>
      </div>

      <!-- spacing -->
      <div>
        <h3 class="tk-h3">Espaciado · base 4</h3>
        <div class="tk-scale">
          <div v-for="s in spacing" :key="s" class="tk-scale__item">
            <span class="tk-scale__bar" :style="{ width: `var(--pxn-space-${s})` }"></span>
            <code>--pxn-space-{{ s }}</code>
          </div>
        </div>
      </div>

      <!-- radius -->
      <div>
        <h3 class="tk-h3">Radios</h3>
        <div class="tk-radii">
          <div v-for="r in radii" :key="r.var" class="tk-radius">
            <span class="tk-radius__box" :style="{ borderRadius: `var(${r.var})` }"></span>
            <code>{{ r.var }}</code>
            <small>{{ r.use }}</small>
          </div>
        </div>
      </div>

      <!-- shadow -->
      <div>
        <h3 class="tk-h3">Sombra · solo elementos flotantes</h3>
        <div class="tk-shadows">
          <div v-for="s in shadows" :key="s.var" class="tk-shadow">
            <span class="tk-shadow__box" :style="{ boxShadow: `var(${s.var})` }"></span>
            <code>{{ s.var }}</code>
            <small>{{ s.use }}</small>
          </div>
          <div class="tk-shadow">
            <span class="tk-shadow__box tk-shadow__box--flat"></span>
            <code>—</code>
            <small>tarjetas y tablas estáticas: solo <code>1px</code> de hairline</small>
          </div>
        </div>
      </div>

      <!-- motion -->
      <div>
        <h3 class="tk-h3">Motion · funcional</h3>
        <div class="tk-motion">
          <button
            v-for="m in motions"
            :key="m.var"
            type="button"
            class="tk-motion__demo pxn-ring"
            :style="{ transitionDuration: `var(${m.var})`, transitionTimingFunction: 'var(--pxn-ease)' }"
          >
            <code>{{ m.var }}</code>
            <small>{{ m.use }}</small>
          </button>
        </div>
        <p class="tk-motion__note">
          <lucide-icon name="info" :size="13" />
          Pasa el cursor. Con <code>prefers-reduced-motion</code> las transiciones se anulan y el
          estado se comunica solo por color, posición y texto.
        </p>
      </div>
    </div>
  </section>
</template>

<script>
import SectionHead from "./_SectionHead.vue";
export default {
  name: "TokensSection",
  components: { SectionHead },
  props: { density: String, country: String },
  data() {
    return {
      neutrals: [
        { var: "--pxn-bg", use: "fondo de página" },
        { var: "--pxn-surface", use: "superficie elevada / tarjeta" },
        { var: "--pxn-surface-2", use: "relleno tenue / encabezado de tabla" },
        { var: "--pxn-surface-3", use: "hundido / campo deshabilitado" },
        { var: "--pxn-ink", use: "texto principal / cifras" },
        { var: "--pxn-ink-2", use: "texto secundario" },
        { var: "--pxn-ink-3", use: "leyendas / placeholder" },
        { var: "--pxn-border", use: "hairline (estructura por defecto)" }
      ],
      primary: [
        { var: "--pxn-primary", use: "acción / activo" },
        { var: "--pxn-primary-hover", use: "hover de acción" },
        { var: "--pxn-primary-soft", use: "fila seleccionada / botón sutil" },
        { var: "--pxn-primary-ink", use: "texto sobre fondo claro (links)" },
        { var: "--pxn-focus-ring", use: "anillo de foco" }
      ],
      semantic: [
        { name: "success", use: "emitido / disponible / positivo" },
        { name: "warning", use: "por vencer / atención" },
        { name: "danger", use: "anulado / cuarentena / error" },
        { name: "info", use: "en tránsito / informativo" },
        { name: "neutral", use: "borrador / inactivo" }
      ],
      tagHues: ["slate", "indigo", "teal", "plum", "clay", "moss"],
      spacing: [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
      radii: [
        { var: "--pxn-radius-sm", use: "chips" },
        { var: "--pxn-radius-md", use: "controles" },
        { var: "--pxn-radius-lg", use: "tarjetas" },
        { var: "--pxn-radius-xl", use: "contenedores de shell" },
        { var: "--pxn-radius-pill", use: "badges / switches" }
      ],
      shadows: [
        { var: "--pxn-shadow-menu", use: "menús / dropdowns / popovers" },
        { var: "--pxn-shadow-modal", use: "modales" },
        { var: "--pxn-shadow-card-hover", use: "tarjeta interactiva en hover" }
      ],
      motions: [
        { var: "--pxn-dur-1", use: "hover / tinte / estado pequeño" },
        { var: "--pxn-dur-2", use: "menú / indicador de tab" },
        { var: "--pxn-dur-3", use: "modal / superficie mayor" }
      ]
    };
  }
};
</script>

<style lang="scss" scoped>
.tk-h3 { font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); margin-bottom: var(--pxn-space-5); }
.tk-note { margin-left: var(--pxn-space-3); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-regular); color: var(--pxn-ink-3); }
.tk-note code, .tk-h3 code { font-size: 0.92em; }

.tk-swatches { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: var(--pxn-space-4); }
.tk-swatch { display: flex; align-items: center; gap: var(--pxn-space-4); padding: var(--pxn-space-4) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); min-width: 0; }
.tk-swatch__meta { min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.tk-swatch code { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tk-swatch small { font-size: 10px; color: var(--pxn-ink-3); line-height: var(--pxn-lh-snug); }
.tk-swatch__chip { width: 26px; height: 26px; border-radius: var(--pxn-radius-sm); border: 1px solid var(--pxn-border); flex: none; display: inline-flex; align-items: center; justify-content: center; }
.tk-swatch__chip--soft { border-style: solid; }
.tk-tagdot { width: 8px; height: 8px; border-radius: 2px; }

.tk-scale { display: flex; flex-direction: column; gap: var(--pxn-space-3); }
.tk-scale__item { display: flex; align-items: center; gap: var(--pxn-space-5); }
.tk-scale__bar { height: 14px; background: var(--pxn-surface-3); border: 1px solid var(--pxn-border-strong); border-radius: 3px; flex: none; }
.tk-scale__item code { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }

.tk-radii, .tk-shadows { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: var(--pxn-space-5); }
.tk-radius, .tk-shadow { display: flex; flex-direction: column; gap: var(--pxn-space-3); }
.tk-radius__box { width: 100%; height: 56px; background: var(--pxn-surface); border: 1px solid var(--pxn-border-strong); }
.tk-radius code, .tk-shadow code { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-2); }
.tk-radius small, .tk-shadow small { font-size: 10px; color: var(--pxn-ink-3); }
.tk-shadow__box { width: 100%; height: 56px; background: var(--pxn-surface); border-radius: var(--pxn-radius-md); border: 1px solid var(--pxn-border); }
.tk-shadow__box--flat { box-shadow: none; }

.tk-motion { display: flex; gap: var(--pxn-space-5); flex-wrap: wrap; }
.tk-motion__demo {
  display: flex; flex-direction: column; gap: 2px;
  padding: var(--pxn-space-5) var(--pxn-space-6);
  border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); cursor: pointer;
  transition-property: transform, background-color, border-color;
}
.tk-motion__demo:hover { transform: translateY(-3px); background: var(--pxn-primary-soft); border-color: var(--pxn-primary-border); }
.tk-motion__demo code { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-2); }
.tk-motion__demo small { font-size: 10px; color: var(--pxn-ink-3); }
.tk-motion__note { display: inline-flex; align-items: center; gap: var(--pxn-space-3); margin-top: var(--pxn-space-5); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
</style>
