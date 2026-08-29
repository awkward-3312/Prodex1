<template>
  <section>
    <section-head
      num="11"
      title="B0 · Arquitectura de información y mapa de navegación"
      desc="Análisis de la navegación real de PRODEX para decidir qué es un módulo principal (riel) y qué es navegación secundaria (panel contextual). NO cambia la navegación real. module-map.js NO es todavía la fuente de verdad — la arquitectura se decide tras aprobar B0."
    />

    <px-alert tone="warning" title="Sin telemetría de navegación">
      No existe tracking de uso (ni frontend ni backend). La columna <b>frecuencia</b> es
      <b>heurística</b> con base declarada por fila: importancia operacional, posición actual,
      transversalidad, nº de rutas/subrutas o rol habitual del usuario.
    </px-alert>

    <!-- Tabs -->
    <px-tabs v-model="tab" :tabs="tabs" class="mm-tabs" />

    <!-- 1 · Dominios -->
    <div v-show="tab === 'domains'" class="mm-pane">
      <p class="mm-lead">Evaluados por <b>flujo de negocio real</b>, no por parecido de nombre.</p>
      <div class="mm-domains">
        <px-card v-for="d in domains" :key="d.key">
          <div class="mm-domain">
            <div class="mm-domain__head">
              <lucide-icon :name="d.icon" :size="16" />
              <b>{{ d.label }}</b>
            </div>
            <p class="mm-domain__thesis">{{ d.thesis }}</p>
            <p v-if="d.flow !== '—'" class="mm-domain__flow"><span>Flujo</span>{{ d.flow }}</p>
          </div>
        </px-card>
      </div>
    </div>

    <!-- 2 · Inventario / matriz -->
    <div v-show="tab === 'matrix'" class="mm-pane">
      <div class="mm-matrix__filters">
        <label>Dominio
          <select v-model="filterDomain">
            <option value="">Todos</option>
            <option v-for="d in domains" :key="d.key" :value="d.key">{{ d.label }}</option>
          </select>
        </label>
        <label>Ubicación propuesta
          <select v-model="filterPlace">
            <option value="">Todas</option>
            <option v-for="p in places" :key="p" :value="p">{{ placeLabel(p) }}</option>
          </select>
        </label>
        <span class="mm-matrix__count pxn-num">{{ filteredModules.length }} de {{ modules.length }}</span>
      </div>
      <div class="mm-matrix__scroll pxn-scroll">
        <table class="mm-matrix">
          <thead>
            <tr>
              <th>Destino</th><th>Ruta</th><th>Grupo actual</th><th>Origen</th>
              <th>Permiso</th><th>Plan</th><th class="r">Rutas</th>
              <th>Dominio</th><th>Ubicación propuesta</th><th class="r">Frec.</th><th>Justificación</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in filteredModules" :key="m.key">
              <td class="is-strong">{{ m.label }}</td>
              <td class="pxn-mono">{{ m.dest }}</td>
              <td>{{ m.parentNow }}</td>
              <td>
                <px-tag v-for="o in m.origin" :key="o" :label="o" :hue="originHue(o)" />
              </td>
              <td class="pxn-mono mm-matrix__perm">{{ m.perm }}</td>
              <td><px-badge v-if="m.plan" tone="info" icon="lock">{{ m.plan }}</px-badge><span v-else class="pxn-muted">—</span></td>
              <td class="r pxn-num">{{ m.routes }}</td>
              <td>{{ domainLabel(m.domain) }}</td>
              <td><px-badge :tone="placeTone(m.place)" :icon="placeIcon(m.place)">{{ placeLabel(m.place) }}</px-badge></td>
              <td class="r"><px-badge :tone="freqTone(m.freq)">{{ m.freq }}</px-badge></td>
              <td class="mm-matrix__basis">{{ m.basis }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 3 · Riel compacto vs extendido -->
    <div v-show="tab === 'rail'" class="mm-pane">
      <p class="mm-lead">
        <b>Base predeterminada aprobada (B0): riel compacto.</b> El riel extendido es
        <b>condicional</b> a módulos/estructura habilitados — aparece solo, no es un conmutador manual.
      </p>
      <div class="mm-rails">
        <px-card title="Riel compacto · BASE" :subtitle="`${railCompact.length} módulos + Configuración/Más al pie`">
          <ul class="mm-raillist">
            <li v-for="r in railCompact" :key="r.key"><lucide-icon :name="r.icon" :size="16" />{{ r.label }}</li>
            <li class="is-util"><lucide-icon name="settings" :size="16" />Configuración</li>
            <li class="is-util"><lucide-icon name="more-vertical" :size="16" />Más herramientas</li>
          </ul>
          <template #footer>
            <span>Los 4 dominios de rotación diaria (Ventas · Inventario · Compras · Finanzas) siempre visibles. Iconos reconocibles sin tooltip permanente. Panel contextual ≤ 8 ítems.</span>
          </template>
        </px-card>
        <px-card title="Riel extendido" :subtitle="`${railExtended.length} módulos — sólo si la estructura lo justifica`">
          <ul class="mm-raillist">
            <li v-for="r in railExtended" :key="r.key">
              <lucide-icon :name="r.icon" :size="16" />{{ r.label }}
              <span v-if="r.cond" class="mm-raillist__cond">{{ r.cond }}</span>
            </li>
          </ul>
          <template #footer>
            <span>+3 dominios condicionales. A 12 iconos el operador escanea un riel más largo y necesita tooltip para «Organización» y otros menos obvios. Justificado sólo si esos dominios están activos.</span>
          </template>
        </px-card>
      </div>
      <div class="mm-railcrit">
        <h4>Criterios evaluados</h4>
        <ul>
          <li><b>Carga cognitiva:</b> 7–9 se abarca de un vistazo; 12 obliga a escaneo secuencial.</li>
          <li><b>Reconocibilidad de iconos:</b> Ventas/Compras/Inventario/Finanzas/Reportes/RR.HH. tienen iconos inequívocos; Organización/Suscripciones no.</li>
          <li><b>Necesidad de tooltips:</b> compacto → sólo en hover; extendido → tooltip permanente recomendable.</li>
          <li><b>Longitud del panel contextual:</b> con dominios amplios (Inventario, Finanzas) el panel llega a 3 grupos / ~12 ítems — manejable; si se parte en más módulos, cada panel queda anémico.</li>
          <li><b>Frecuencia de cambio entre módulos:</b> el ciclo diario es Ventas↔Inventario↔Compras↔Finanzas; esos 4 no pueden estar a un nivel de profundidad.</li>
        </ul>
      </div>
    </div>

    <!-- 4 · POS + condicionales -->
    <div v-show="tab === 'pos'" class="mm-pane">
      <px-card title="Tratamiento del POS" subtitle="Fuera del rediseño — sólo se define el acceso">
        <p class="mm-q">{{ pos.question }}</p>
        <p class="mm-today"><b>Hoy:</b> {{ pos.today }}</p>
        <ul class="mm-poslist">
          <li v-for="(p, i) in pos.proposal" :key="i">{{ p }}</li>
        </ul>
      </px-card>

      <px-card title="Módulos condicionales por plan / permiso" class="mm-cond">
        <p class="mm-lead">{{ conditional.principle }}</p>
        <table class="mm-condtable">
          <thead><tr><th>Caso</th><th>Tratamiento</th></tr></thead>
          <tbody>
            <tr v-for="(r, i) in conditional.rules" :key="i">
              <td class="is-strong">{{ r.case }}</td><td>{{ r.action }}</td>
            </tr>
          </tbody>
        </table>
      </px-card>
    </div>

    <!-- 5 · Decisiones B0 (resueltas) -->
    <div v-show="tab === 'risks'" class="mm-pane">
      <p class="mm-lead">Decisiones definitivas de B0. La arquitectura de navegación real se implementa en una fase posterior.</p>
      <div class="mm-decisions">
        <px-card v-for="d in decisions" :key="d.id">
          <div class="mm-decision">
            <div class="mm-decision__head">
              <span class="mm-decision__id pxn-mono">{{ d.id }}</span>
              <b>{{ d.title }}</b>
              <px-badge tone="success" icon="check">resuelta</px-badge>
            </div>
            <p>{{ d.decision }}</p>
          </div>
        </px-card>
      </div>

      <px-card title="Requisito futuro del riel" subtitle="A verificar en la implementación de navegación real (fase posterior)" class="mm-req">
        <ul class="mm-reqlist">
          <li v-for="(r, i) in railRequirements" :key="i"><lucide-icon name="check" :size="14" />{{ r }}</li>
        </ul>
      </px-card>
    </div>
  </section>
</template>

<script>
import SectionHead from "./_SectionHead.vue";
import { PxAlert, PxTabs, PxCard, PxBadge, PxTag } from "@/components/px-next";
import {
  DOMAINS, MODULES, RAIL_COMPACT, RAIL_EXTENDED, RAIL_REQUIREMENTS, POS_TREATMENT,
  CONDITIONAL_TREATMENT, DECISIONS
} from "../data/module-map";

const PLACE = {
  rail:   { label: "Riel", tone: "success", icon: "check" },
  "rail?":{ label: "Riel ext.", tone: "info", icon: "chevron-up" },
  panel:  { label: "Panel contextual", tone: "neutral", icon: "layout-dashboard" },
  topbar: { label: "Topbar", tone: "neutral", icon: "layout-dashboard" },
  config: { label: "Configuración", tone: "neutral", icon: "settings" },
  cuenta: { label: "Cuenta PRODEX", tone: "neutral", icon: "credit-card" },
  mas:    { label: "Más herramientas", tone: "warning", icon: "more-vertical" },
  pos:    { label: "Acceso POS (aparte)", tone: "danger", icon: "scan-line" }
};

export default {
  name: "ModuleMapSection",
  components: { SectionHead, PxAlert, PxTabs, PxCard, PxBadge, PxTag },
  props: { density: String, country: String },
  data() {
    return {
      tab: "domains",
      tabs: [
        { value: "domains", label: "Dominios", icon: "layout-grid" },
        { value: "matrix", label: "Inventario / matriz", icon: "table", count: MODULES.length },
        { value: "rail", label: "Riel compacto vs extendido", icon: "layout-dashboard" },
        { value: "pos", label: "POS + condicionales", icon: "scan-line" },
        { value: "risks", label: "Decisiones B0", icon: "flag", count: DECISIONS.length }
      ],
      domains: DOMAINS,
      modules: MODULES,
      railCompact: RAIL_COMPACT,
      railExtended: RAIL_EXTENDED,
      pos: POS_TREATMENT,
      conditional: CONDITIONAL_TREATMENT,
      decisions: DECISIONS,
      railRequirements: RAIL_REQUIREMENTS,
      filterDomain: "",
      filterPlace: "",
      places: Object.keys(PLACE)
    };
  },
  computed: {
    filteredModules() {
      return this.modules.filter(m =>
        (!this.filterDomain || m.domain === this.filterDomain) &&
        (!this.filterPlace || m.place === this.filterPlace)
      );
    }
  },
  methods: {
    domainLabel(k) { const d = this.domains.find(x => x.key === k); return d ? d.label : k; },
    placeLabel(p) { return (PLACE[p] || { label: p }).label; },
    placeTone(p) { return (PLACE[p] || { tone: "neutral" }).tone; },
    placeIcon(p) { return (PLACE[p] || { icon: "circle" }).icon; },
    freqTone(f) { return f === "alta" ? "success" : f === "media" ? "warning" : "neutral"; },
    originHue(o) {
      return { "Sidebar": "slate", "VSidebar": "plum", "router": "teal", "runtime-router (main.js)": "clay", "nav-v3": "indigo" }[o] ||
        (o.indexOf("Sidebar (People)") === 0 ? "slate" : "moss");
    }
  }
};
</script>

<style lang="scss" scoped>
.mm-tabs { margin: var(--pxn-space-7) 0 var(--pxn-space-7); }
.mm-pane { display: block; }
.mm-lead { font-size: var(--pxn-fs-body); color: var(--pxn-ink-2); margin-bottom: var(--pxn-space-6); max-width: 80ch; }

.mm-domains { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--pxn-space-5); }
.mm-domain__head { display: flex; align-items: center; gap: var(--pxn-space-3); color: var(--pxn-ink); }
.mm-domain__head b { font-size: var(--pxn-fs-h3); }
.mm-domain__thesis { margin: var(--pxn-space-4) 0 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.mm-domain__flow { margin: var(--pxn-space-4) 0 0; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.mm-domain__flow span { display: inline-block; font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; margin-right: var(--pxn-space-3); color: var(--pxn-ink-3); }

.mm-matrix__filters { display: flex; align-items: flex-end; gap: var(--pxn-space-5); margin-bottom: var(--pxn-space-5); flex-wrap: wrap; }
.mm-matrix__filters label { display: flex; flex-direction: column; gap: var(--pxn-space-2); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink-2); }
.mm-matrix__filters select { height: 32px; border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); padding: 0 var(--pxn-space-4); font: inherit; font-size: var(--pxn-fs-sm); }
.mm-matrix__count { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.mm-matrix__scroll { overflow-x: auto; border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); }
.mm-matrix { width: 100%; min-width: 1180px; border-collapse: separate; border-spacing: 0; font-size: var(--pxn-fs-sm); }
.mm-matrix th, .mm-matrix td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); text-align: left; vertical-align: top; }
.mm-matrix thead th { position: sticky; top: 0; background: var(--pxn-surface-2); font-size: var(--pxn-fs-xs); text-transform: uppercase; letter-spacing: 0.03em; color: var(--pxn-ink-2); white-space: nowrap; z-index: 1; }
.mm-matrix td { color: var(--pxn-ink-2); }
.mm-matrix td.is-strong { color: var(--pxn-ink); font-weight: var(--pxn-fw-medium); white-space: nowrap; }
.mm-matrix td.r, .mm-matrix th.r { text-align: right; }
.mm-matrix .pxn-mono { font-size: var(--pxn-fs-xs); }
.mm-matrix__perm { max-width: 160px; white-space: normal; color: var(--pxn-ink-3); }
.mm-matrix__basis { min-width: 260px; white-space: normal; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.mm-matrix tr:last-child td { border-bottom: 0; }

.mm-rails { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-6); }
@media (max-width: 900px) { .mm-rails { grid-template-columns: minmax(0, 1fr); } }
.mm-raillist { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }
.mm-raillist li { display: flex; align-items: center; gap: var(--pxn-space-4); padding: var(--pxn-space-4) var(--pxn-space-4); border-radius: var(--pxn-radius-sm); font-size: var(--pxn-fs-body); color: var(--pxn-ink); background: var(--pxn-surface-2); }
.mm-raillist li.is-util { color: var(--pxn-ink-3); background: transparent; border: 1px dashed var(--pxn-border-strong); }
.mm-raillist__cond { margin-left: auto; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.mm-railcrit { margin-top: var(--pxn-space-7); padding: var(--pxn-space-6); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.mm-railcrit h4 { font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-semibold); margin-bottom: var(--pxn-space-4); }
.mm-railcrit ul { margin: 0; padding-left: var(--pxn-space-6); display: flex; flex-direction: column; gap: var(--pxn-space-3); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }

.mm-q { font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); margin-bottom: var(--pxn-space-4); }
.mm-today { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); margin-bottom: var(--pxn-space-5); }
.mm-poslist { margin: 0; padding-left: var(--pxn-space-6); display: flex; flex-direction: column; gap: var(--pxn-space-4); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); }
.mm-cond { margin-top: var(--pxn-space-6); }
.mm-condtable, .mm-decisions table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: var(--pxn-fs-sm); margin-top: var(--pxn-space-4); }
.mm-condtable th, .mm-condtable td { padding: var(--pxn-space-4) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); text-align: left; vertical-align: top; }
.mm-condtable thead th { font-size: var(--pxn-fs-xs); text-transform: uppercase; letter-spacing: 0.03em; color: var(--pxn-ink-2); background: var(--pxn-surface-2); }
.mm-condtable td { color: var(--pxn-ink-2); }
.mm-condtable td.is-strong { color: var(--pxn-ink); font-weight: var(--pxn-fw-medium); width: 38%; }

.mm-decisions { display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: var(--pxn-space-5); }
.mm-decision__head { display: flex; align-items: center; gap: var(--pxn-space-3); }
.mm-decision__id { font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-bold); padding: 2px 6px; border-radius: 4px; background: var(--pxn-primary-soft); color: var(--pxn-primary-ink); }
.mm-decision__head b { font-size: var(--pxn-fs-h3); color: var(--pxn-ink); }
.mm-decision p:not(.mm-decision__needs) { margin: var(--pxn-space-4) 0 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.mm-decision__needs { margin: var(--pxn-space-4) 0 0; display: flex; align-items: center; gap: var(--pxn-space-3); font-size: var(--pxn-fs-sm); color: var(--pxn-primary-ink); font-weight: var(--pxn-fw-medium); }
.mm-req { margin-top: var(--pxn-space-6); }
.mm-reqlist { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--pxn-space-4); }
.mm-reqlist li { display: flex; align-items: flex-start; gap: var(--pxn-space-3); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); line-height: var(--pxn-lh-snug); }
.mm-reqlist li > svg { flex: none; margin-top: 2px; color: var(--pxn-success); }
</style>
