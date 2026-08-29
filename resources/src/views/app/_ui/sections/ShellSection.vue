<template>
  <section>
    <section-head
      num="10"
      title="Shell — hipótesis visual"
      desc="Rail de iconos + panel contextual + topbar. Se muestra con los módulos reales de PRODEX pero NO toca Sidebar.vue, VerticalSidebar.vue, TopNav ni friendlyNavigation. Cambiar la arquitectura de navegación requiere aprobación aparte."
    />

    <px-shell-mock
      :modules="modules"
      active="inventory"
      panel-title="Inventario"
      :panel-items="panel"
      :panel-active="1"
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
        <lucide-icon name="layout-dashboard" :size="15" />
        <div><b>Panel contextual opcional</b><span>Sub-navegación, vistas guardadas o filtros del módulo. Colapsable; desaparece por debajo de 720 px.</span></div>
      </div>
      <div class="sh-note">
        <lucide-icon name="lock" :size="15" />
        <div><b>Fuera de alcance de Fase A</b><span>Esto es una maqueta para aprobación visual. No se conecta a rutas ni sustituye el shell actual.</span></div>
      </div>
    </div>
  </section>
</template>

<script>
import SectionHead from "./_SectionHead.vue";
import { PxShellMock } from "@/components/px-next";
import { MODULES, INVENTORY_PANEL } from "../data/mock";
export default {
  name: "ShellSection",
  components: { SectionHead, PxShellMock },
  props: { density: String, country: String },
  data() { return { modules: MODULES, panel: INVENTORY_PANEL }; }
};
</script>

<style lang="scss" scoped>
.sh-notes { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); margin-top: var(--pxn-space-7); }
@media (max-width: 820px) { .sh-notes { grid-template-columns: minmax(0, 1fr); } }
.sh-note { display: flex; gap: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); }
.sh-note > svg { flex: none; color: var(--pxn-ink-3); margin-top: 1px; }
.sh-note b { display: block; font-size: var(--pxn-fs-sm); color: var(--pxn-ink); margin-bottom: 2px; }
.sh-note span { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); line-height: var(--pxn-lh-snug); }
</style>
