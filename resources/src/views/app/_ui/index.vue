<template>
  <div
    class="px-next pxn-doc"
    :data-pxn-type="typeface"
    :class="{ 'pxn-doc--compact': density === 'compact' }"
    :style="primaryOverride ? { '--primary-color': primaryOverride } : null"
  >
    <!--
      ============================================================================
      DIRECTION CONTRACT · px-next · "Panel de operación"  (Fase A playground)
      seed: brief-pinned (no roll) · references: 3 provided dashboards · mode: Operate
      ----------------------------------------------------------------------------
      THESIS: The surface behaves like a well-made control panel. Chrome is calm
        and generous; the data plane is dense and ledger-precise. It refuses the
        airy consumer-dashboard default where the demo table has six roomy rows
        and nothing scales to ERP volume.
      OWN-WORLD: Layered light neutrals (#f5f6f8 ground, white surface). Structure
        by hairlines (rgba(18,24,40,.09)); shadows only on floating elements
        (menus, modal, toast, hover-cards). Radius 16/12/8/pill. One tenant accent
        (--primary-color) for action + active state; fixed semantic set for status;
        a separate low-saturation auxiliary palette for entity tags. Tabular
        lining figures everywhere numbers stack.
      STORY: A multi-branch operator scans operational truth fast, trusts every
        number, acts (new sale, adjust stock, resolve a fiscal doc) without the
        interface getting in the way.
      FIRST VIEWPORT: Page header (28px bold, tight) with branch + freshness meta;
        a row of KPI figures rendered immediately (no count-up); then a dense,
        sortable, stickied table as the centre of gravity. Primary action sits
        top-right of the header.
      FORM: control panel / operational ledger. #1 of the pinned brief.
      FINISH: unreviewed and undocumented is unfinished; this build ends with the
        finish review, the verdict, DESIGN.md, and every shipping raster carrying
        its provenance.
      ============================================================================
    -->

    <div class="pxn-doc__frame">
      <!-- rail: section index -->
      <nav class="pxn-doc__nav pxn-scroll" aria-label="Secciones del sistema">
        <div class="pxn-doc__brand">
          <span class="pxn-doc__brand-mark">px</span>
          <span class="pxn-doc__brand-text">
            <b>px-next</b>
            <small>Design System · Fase A</small>
          </span>
        </div>
        <a
          v-for="s in sections"
          :key="s.id"
          :href="`#${s.id}`"
          class="pxn-doc__navlink"
          :class="{ 'is-active': activeSection === s.id }"
          @click="activeSection = s.id"
        >
          <lucide-icon :name="s.icon" :size="15" />
          <span>{{ s.label }}</span>
        </a>
        <p class="pxn-doc__navnote">
          Ruta de desarrollo <code>/app/_ui</code>. No existe en producción.
        </p>
      </nav>

      <!-- main -->
      <main class="pxn-doc__main pxn-scroll">
        <!-- controls bar -->
        <div class="pxn-doc__controls">
          <div class="pxn-doc__control">
            <span class="pxn-doc__control-label">Color del tenant</span>
            <div class="pxn-doc__swatches" role="group" aria-label="Color primario del tenant">
              <button
                v-for="p in primaries"
                :key="p.id"
                type="button"
                class="pxn-doc__swatch pxn-ring"
                :class="{ 'is-active': activePrimary === p.id }"
                :style="{ '--_c': p.value }"
                :aria-pressed="activePrimary === p.id ? 'true' : 'false'"
                @click="setPrimary(p)"
              >
                <span class="pxn-doc__swatch-dot"></span>{{ p.label }}
              </button>
            </div>
          </div>

          <div class="pxn-doc__control">
            <span class="pxn-doc__control-label">Tipografía</span>
            <div class="pxn-doc__seg" role="group" aria-label="Familia tipográfica">
              <button
                v-for="t in typefaces"
                :key="t.id"
                type="button"
                class="pxn-doc__segbtn pxn-ring"
                :class="{ 'is-active': typeface === t.id }"
                @click="typeface = t.id"
              >{{ t.label }}</button>
            </div>
          </div>

          <div class="pxn-doc__control">
            <span class="pxn-doc__control-label">Densidad</span>
            <div class="pxn-doc__seg" role="group" aria-label="Densidad de tabla">
              <button
                type="button"
                class="pxn-doc__segbtn pxn-ring"
                :class="{ 'is-active': density === 'comfortable' }"
                @click="density = 'comfortable'"
              >Estándar 44</button>
              <button
                type="button"
                class="pxn-doc__segbtn pxn-ring"
                :class="{ 'is-active': density === 'compact' }"
                @click="density = 'compact'"
              >Compacta 36</button>
            </div>
          </div>

          <p class="pxn-doc__control-hint">
            <lucide-icon name="info" :size="13" />
            Respeta <code>prefers-reduced-motion</code>. El motion comunica estado, no decora.
          </p>
        </div>

        <header class="pxn-doc__intro">
          <h1>PRODEX · px-next</h1>
          <p>
            Dirección visual <b>“Panel de operación”</b> para el rediseño de PRODEX: enterprise SaaS
            claro, el dato como protagonista, densidad ERP y personalización por tenant.
            Propuesta aislada — no reemplaza ninguna pantalla real.
          </p>
        </header>

        <component
          :is="s.component"
          v-for="s in sections"
          :id="s.id"
          :key="s.id"
          class="pxn-doc__section"
          :density="density"
          :country="country"
          @country="country = $event"
        />

        <footer class="pxn-doc__foot">
          <span>px-next · Fase A · {{ typefaceLabel }} · {{ activePrimaryLabel }} · densidad {{ density === 'compact' ? '36' : '44' }}</span>
        </footer>
      </main>
    </div>
  </div>
</template>

<script>
import TokensSection from "./sections/TokensSection.vue";
import TypeSection from "./sections/TypeSection.vue";
import PrimitivesSection from "./sections/PrimitivesSection.vue";
import DataDisplaySection from "./sections/DataDisplaySection.vue";
import TableSection from "./sections/TableSection.vue";
import NavigationSection from "./sections/NavigationSection.vue";
import FeedbackSection from "./sections/FeedbackSection.vue";
import ChartSection from "./sections/ChartSection.vue";
import IconographySection from "./sections/IconographySection.vue";
import ShellSection from "./sections/ShellSection.vue";
import ModuleMapSection from "./sections/ModuleMapSection.vue";

export default {
  name: "PxNextPlayground",
  components: {
    TokensSection, TypeSection, PrimitivesSection, DataDisplaySection, TableSection,
    NavigationSection, FeedbackSection, ChartSection, IconographySection, ShellSection,
    ModuleMapSection
  },
  data() {
    return {
      typeface: "plex",
      density: "comfortable",
      country: "HN",
      activeSection: "tokens",
      activePrimary: "tenant",
      tenantPrimary: null,
      typefaces: [
        { id: "plex", label: "IBM Plex Sans" },
        { id: "system", label: "system-ui" }
      ],
      primaries: [
        { id: "tenant", label: "Tenant actual", value: null },
        { id: "morado", label: "Morado", value: "#6d28d9" },
        { id: "teal", label: "Teal", value: "#0f766e" },
        { id: "azul", label: "Azul", value: "#1d4ed8" }
      ],
      sections: [
        { id: "tokens", label: "Tokens", icon: "layers", component: "TokensSection" },
        { id: "type", label: "Tipografía", icon: "pen", component: "TypeSection" },
        { id: "primitives", label: "Controles", icon: "mouse-pointer", component: "PrimitivesSection" },
        { id: "data", label: "Datos y estado", icon: "layout-grid", component: "DataDisplaySection" },
        { id: "table", label: "Tabla ERP", icon: "table", component: "TableSection" },
        { id: "navigation", label: "Navegación", icon: "arrow-right-left", component: "NavigationSection" },
        { id: "feedback", label: "Feedback y vacíos", icon: "message-square", component: "FeedbackSection" },
        { id: "chart", label: "Marco de gráfico", icon: "bar-chart-3", component: "ChartSection" },
        { id: "iconography", label: "Iconografía", icon: "puzzle", component: "IconographySection" },
        { id: "shell", label: "Shell (hipótesis)", icon: "layout-dashboard", component: "ShellSection" },
        { id: "modulemap", label: "B0 · Mapa de navegación", icon: "arrow-right-left", component: "ModuleMapSection" }
      ]
    };
  },
  computed: {
    primaryOverride() {
      const p = this.primaries.find(x => x.id === this.activePrimary);
      return p && p.value ? p.value : null;
    },
    typefaceLabel() { return (this.typefaces.find(t => t.id === this.typeface) || {}).label; },
    activePrimaryLabel() { return (this.primaries.find(p => p.id === this.activePrimary) || {}).label; }
  },
  mounted() {
    try {
      const c = getComputedStyle(document.documentElement).getPropertyValue("--primary-color").trim();
      this.tenantPrimary = c || "#663399";
      this.primaries[0].value = null; // "tenant actual" = inherit, no override
    } catch (e) { /* noop */ }

    // Keep the anchor-scroll offset exactly in sync with the real height of the
    // sticky controls bar (which grows when the reduced-motion hint wraps, when
    // the segmented control wraps, etc.), so a nav click / hash jump never lands
    // a heading behind it. Falls back to a safe constant if RO is unavailable.
    this.$nextTick(() => {
      this.measureSticky();
      if (typeof ResizeObserver !== "undefined") {
        this._ro = new ResizeObserver(() => this.measureSticky());
        const bar = this.$el.querySelector(".pxn-doc__controls");
        if (bar) this._ro.observe(bar);
      }
      window.addEventListener("resize", this.measureSticky, { passive: true });
    });

    this._io = new IntersectionObserver(
      entries => {
        entries.forEach(en => { if (en.isIntersecting) this.activeSection = en.target.id; });
      },
      { rootMargin: "-45% 0px -50% 0px" }
    );
    this.$nextTick(() => {
      this.$el.querySelectorAll(".pxn-doc__section").forEach(el => this._io.observe(el));
    });
  },
  beforeDestroy() {
    if (this._io) this._io.disconnect();
    if (this._ro) this._ro.disconnect();
    window.removeEventListener("resize", this.measureSticky);
  },
  methods: {
    setPrimary(p) { this.activePrimary = p.id; },
    measureSticky() {
      const bar = this.$el && this.$el.querySelector(".pxn-doc__controls");
      if (!bar) return;
      const cs = getComputedStyle(bar);
      const topGap = parseFloat(cs.top) || 0;
      const h = Math.ceil(bar.getBoundingClientRect().height + topGap + 20);
      this.$el.style.setProperty("--pxn-doc-sticky-h", `${h}px`);
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/index.scss"></style>

<style lang="scss" scoped>
.pxn-doc__frame {
  display: grid;
  grid-template-columns: 236px 1fr;
  min-height: 100vh;
  max-width: 1680px;
  margin: 0 auto;
}

/* nav rail */
.pxn-doc__nav {
  position: sticky;
  top: 0;
  align-self: start;
  height: 100vh;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: var(--pxn-space-6) var(--pxn-space-4);
  border-right: 1px solid var(--pxn-border);
  background: var(--pxn-surface);
}
.pxn-doc__brand { display: flex; align-items: center; gap: var(--pxn-space-4); padding: var(--pxn-space-3) var(--pxn-space-3) var(--pxn-space-6); }
.pxn-doc__brand-mark {
  width: 30px; height: 30px; border-radius: var(--pxn-radius-md);
  display: inline-flex; align-items: center; justify-content: center;
  background: var(--pxn-primary); color: var(--pxn-primary-contrast);
  font-family: var(--pxn-font-mono); font-weight: var(--pxn-fw-bold); font-size: 13px;
}
.pxn-doc__brand-text { display: flex; flex-direction: column; line-height: 1.25; }
.pxn-doc__brand-text b { font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-bold); letter-spacing: -0.01em; }
.pxn-doc__brand-text small { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }

.pxn-doc__navlink {
  display: flex; align-items: center; gap: var(--pxn-space-4);
  padding: var(--pxn-space-4) var(--pxn-space-4);
  border-radius: var(--pxn-radius-sm);
  font-size: var(--pxn-fs-sm);
  font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink-2);
}
.pxn-doc__navlink:hover { background: var(--pxn-surface-2); color: var(--pxn-ink); text-decoration: none; }
.pxn-doc__navlink.is-active { background: var(--pxn-primary-soft); color: var(--pxn-primary-ink); }
.pxn-doc__navnote { margin-top: auto; padding: var(--pxn-space-5) var(--pxn-space-4) 0; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); line-height: var(--pxn-lh-snug); }
.pxn-doc__navnote code { font-size: 0.92em; }

/* main */
.pxn-doc__main {
  min-width: 0;
  padding: var(--pxn-space-8) var(--pxn-space-10) var(--pxn-space-12);
}
.pxn-doc__controls {
  position: sticky;
  top: var(--pxn-space-5);
  z-index: 30;
  display: flex;
  align-items: flex-start;
  gap: var(--pxn-space-8);
  row-gap: var(--pxn-space-6);
  flex-wrap: wrap;
  padding: var(--pxn-space-5) var(--pxn-space-6);
  margin-bottom: var(--pxn-space-9);
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border-strong);
  border-radius: var(--pxn-radius-lg);
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.06), 0 8px 20px rgba(16, 24, 40, 0.08);
}
.pxn-doc__control { display: flex; flex-direction: column; gap: var(--pxn-space-3); }
.pxn-doc__control-label { font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.05em; color: var(--pxn-ink-3); }
.pxn-doc__control-hint {
  flex: 1 1 100%;
  display: flex; align-items: flex-start; gap: var(--pxn-space-3);
  margin: 0;
  padding-top: var(--pxn-space-4);
  border-top: 1px solid var(--pxn-border);
  font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3);
}
.pxn-doc__control-hint code { white-space: nowrap; }
.pxn-doc__control-hint > svg { flex: none; margin-top: 1px; }

.pxn-doc__swatches, .pxn-doc__seg { display: inline-flex; gap: var(--pxn-space-2); }
.pxn-doc__seg { padding: 2px; background: var(--pxn-surface-2); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); }
.pxn-doc__segbtn {
  height: 28px; padding: 0 var(--pxn-space-4);
  border: 0; border-radius: var(--pxn-radius-sm);
  background: transparent; font: inherit; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink-2); cursor: pointer;
}
.pxn-doc__segbtn.is-active { background: var(--pxn-surface); color: var(--pxn-ink); box-shadow: 0 1px 2px rgba(16,24,40,0.08); }

.pxn-doc__swatch {
  display: inline-flex; align-items: center; gap: var(--pxn-space-3);
  height: 30px; padding: 0 var(--pxn-space-4);
  border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); font: inherit; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink-2); cursor: pointer;
}
.pxn-doc__swatch.is-active { border-color: var(--_c); color: var(--pxn-ink); box-shadow: 0 0 0 1px var(--_c); }
.pxn-doc__swatch-dot { width: 10px; height: 10px; border-radius: 999px; background: var(--_c, var(--pxn-primary)); }

.pxn-doc__intro { max-width: 62ch; margin-bottom: var(--pxn-space-10); }
.pxn-doc__intro h1 { font-size: 34px; font-weight: var(--pxn-fw-bold); letter-spacing: -0.03em; line-height: 1.1; }
.pxn-doc__intro p { margin-top: var(--pxn-space-5); font-size: var(--pxn-fs-h3); color: var(--pxn-ink-2); line-height: var(--pxn-lh-normal); }

.pxn-doc__section {
  display: block;
  padding: var(--pxn-space-11) 0;
  border-top: 1px solid var(--pxn-border);
  scroll-margin-top: var(--pxn-doc-sticky-h, 148px);
}
.pxn-doc__section:first-of-type { border-top: 0; padding-top: 0; }

.pxn-doc__foot { margin-top: var(--pxn-space-11); padding-top: var(--pxn-space-6); border-top: 1px solid var(--pxn-border); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }

@media (max-width: 1080px) {
  .pxn-doc__frame { grid-template-columns: 1fr; }
  .pxn-doc__nav {
    position: static; height: auto; flex-direction: row; flex-wrap: wrap;
    border-right: 0; border-bottom: 1px solid var(--pxn-border);
  }
  .pxn-doc__brand { width: 100%; }
  .pxn-doc__navnote { display: none; }
  .pxn-doc__main { padding: var(--pxn-space-8) var(--pxn-space-8) var(--pxn-space-11); }
  .pxn-doc__intro h1 { font-size: 28px; }
}
@media (max-width: 560px) {
  .pxn-doc__controls { top: 0; gap: var(--pxn-space-6); }
  .pxn-doc__main { padding: var(--pxn-space-7) var(--pxn-space-6) var(--pxn-space-11); }
}
</style>
