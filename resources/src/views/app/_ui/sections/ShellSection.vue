<template>
  <section>
    <section-head
      num="10"
      title="Shell — hipótesis visual (B0)"
      desc="Rail de iconos + panel contextual + topbar, poblado con el mapa real de módulos. Compara riel compacto vs extendido y el panel contextual de los 4 dominios complejos. NO toca Sidebar.vue, VerticalSidebar.vue, TopNav ni friendlyNavigation. La arquitectura real se decide tras aprobar B0."
    />

    <div class="sh-controls">
      <div class="sh-seg" role="group" aria-label="Alternativa de riel">
        <button type="button" class="sh-segbtn pxn-ring" :class="{ 'is-active': variant === 'compact' }" @click="variant = 'compact'">
          Compacto ({{ railCompact.length }})
        </button>
        <button type="button" class="sh-segbtn pxn-ring" :class="{ 'is-active': variant === 'extended' }" @click="variant = 'extended'">
          Extendido ({{ railExtended.length }})
        </button>
      </div>
      <label class="sh-panelpick">
        Panel contextual
        <select v-model="activePanel">
          <option v-for="(p, k) in panels" :key="k" :value="k">{{ p.label }}</option>
        </select>
      </label>
    </div>

    <px-shell-mock
      :modules="railModules"
      :active="activePanel"
      :panel-title="panels[activePanel].label"
      :panel-groups="panels[activePanel].groups"
      :panel-reports="panels[activePanel].reportsInline"
      branch-name="Sucursal San Pedro Sula"
    />

    <div class="sh-notes">
      <div class="sh-note">
        <lucide-icon name="building-2" :size="15" />
        <div><b>Contexto operativo siempre visible</b><span>Sucursal / almacén activo en el topbar, junto a la búsqueda global.</span></div>
      </div>
      <div class="sh-note">
        <lucide-icon name="refresh-cw" :size="15" />
        <div><b>Frescura de datos — patrón propuesto</b><span>Indicador «datos al día · hora». Su adopción real depende de que los endpoints puedan respaldarlo; aquí es una propuesta de UX, no una función existente.</span></div>
      </div>
      <div class="sh-note">
        <lucide-icon name="scan-line" :size="15" />
        <div><b>POS fuera del shell</b><span>Botón «Abrir POS» en el topbar (cambio de contexto a pantalla completa). No entra al riel ni al panel contextual. Su navegación interna no se rediseña en esta fase.</span></div>
      </div>
      <div class="sh-note">
        <lucide-icon name="lock" :size="15" />
        <div><b>Fuera de alcance de B0</b><span>Maqueta para aprobar el mapa. No se conecta a rutas ni sustituye el shell actual.</span></div>
      </div>
    </div>
  </section>
</template>

<script>
import SectionHead from "./_SectionHead.vue";
import { PxShellMock } from "@/components/px-next";
import { RAIL_COMPACT, RAIL_EXTENDED, CONTEXT_PANELS } from "../data/module-map";

export default {
  name: "ShellSection",
  components: { SectionHead, PxShellMock },
  props: { density: String, country: String },
  data() {
    return {
      variant: "compact",
      activePanel: "inventario",
      railCompact: RAIL_COMPACT,
      railExtended: RAIL_EXTENDED,
      panels: CONTEXT_PANELS
    };
  },
  computed: {
    railModules() {
      const src = this.variant === "compact" ? this.railCompact : this.railExtended;
      const rail = src.map(r => ({ key: r.key, label: r.label, icon: r.icon }));
      rail.push({ key: "config", label: "Configuración", icon: "settings", foot: true });
      rail.push({ key: "mas", label: "Más herramientas", icon: "more-vertical", foot: true });
      return rail;
    }
  }
};
</script>

<style lang="scss" scoped>
.sh-controls { display: flex; align-items: flex-end; gap: var(--pxn-space-6); flex-wrap: wrap; margin-bottom: var(--pxn-space-6); }
.sh-seg { display: inline-flex; padding: 3px; gap: 2px; border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); }
.sh-segbtn { height: 30px; padding: 0 var(--pxn-space-5); border: 0; border-radius: var(--pxn-radius-sm); background: transparent; font: inherit; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink-2); cursor: pointer; }
.sh-segbtn.is-active { background: var(--pxn-surface); color: var(--pxn-ink); box-shadow: 0 1px 2px rgba(16, 24, 40, 0.08); }
.sh-panelpick { display: inline-flex; flex-direction: column; gap: var(--pxn-space-2); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink-2); }
.sh-panelpick select { height: 32px; border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); padding: 0 var(--pxn-space-4); font: inherit; font-size: var(--pxn-fs-sm); }

.sh-notes { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); margin-top: var(--pxn-space-7); }
@media (max-width: 820px) { .sh-notes { grid-template-columns: minmax(0, 1fr); } }
.sh-note { display: flex; gap: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); }
.sh-note > svg { flex: none; color: var(--pxn-ink-3); margin-top: 1px; }
.sh-note b { display: block; font-size: var(--pxn-fs-sm); color: var(--pxn-ink); margin-bottom: 2px; }
.sh-note span { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); line-height: var(--pxn-lh-snug); }
</style>
