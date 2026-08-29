<template>
  <div class="pxn-shell" role="img" aria-label="Maqueta visual del shell de PRODEX (hipótesis, no navegable)">
    <!-- rail -->
    <nav class="pxn-shell__rail" aria-hidden="true">
      <div class="pxn-shell__brand">P</div>
      <ul class="pxn-shell__modules">
        <li
          v-for="m in railMain"
          :key="m.key"
          class="pxn-shell__module"
          :class="{ 'is-active': m.key === active }"
          :title="m.label"
        >
          <lucide-icon :name="m.icon" :size="17" />
        </li>
      </ul>
      <div class="pxn-shell__rail-foot">
        <span
          v-for="m in railFoot"
          :key="m.key"
          class="pxn-shell__module"
          :title="m.label"
        ><lucide-icon :name="m.icon" :size="17" /></span>
      </div>
    </nav>

    <!-- optional context panel -->
    <aside v-if="showPanel" class="pxn-shell__panel pxn-scroll" aria-hidden="true">
      <div class="pxn-shell__panel-head">
        <span>{{ panelTitle }}</span>
        <lucide-icon name="chevron-left" :size="14" />
      </div>

      <template v-if="panelGroups.length">
        <div v-for="(g, gi) in panelGroups" :key="'g' + gi" class="pxn-shell__panel-group">
          <div class="pxn-shell__panel-grouptitle">{{ g.title }}</div>
          <ul class="pxn-shell__panel-list">
            <li v-for="(it, i) in g.items" :key="i" :class="{ 'is-active': gi === 0 && i === panelActive }">
              <lucide-icon :name="it.icon" :size="14" /><span>{{ it.label }}</span>
              <span v-if="it.count" class="pxn-shell__panel-count pxn-num">{{ formatCount(it.count) }}</span>
              <span v-else-if="it.cond" class="pxn-shell__panel-cond">{{ it.cond }}</span>
            </li>
          </ul>
        </div>
        <div v-if="panelReports && panelReports.length" class="pxn-shell__panel-group">
          <div class="pxn-shell__panel-grouptitle">Reportes del módulo</div>
          <ul class="pxn-shell__panel-list pxn-shell__panel-list--reports">
            <li v-for="(r, i) in panelReports" :key="i"><lucide-icon name="bar-chart-3" :size="13" /><span>{{ r }}</span></li>
          </ul>
        </div>
      </template>

      <ul v-else class="pxn-shell__panel-list">
        <li v-for="(it, i) in panelItems" :key="i" :class="{ 'is-active': i === panelActive }">
          <lucide-icon :name="it.icon" :size="14" /><span>{{ it.label }}</span>
          <span v-if="it.count" class="pxn-shell__panel-count pxn-num">{{ it.count }}</span>
        </li>
      </ul>
    </aside>

    <!-- main column -->
    <div class="pxn-shell__main">
      <header class="pxn-shell__topbar" aria-hidden="true">
        <div class="pxn-shell__branch">
          <span class="pxn-shell__branch-mark">SPS</span>
          <span class="pxn-shell__branch-name">{{ branchName }}</span>
          <lucide-icon name="chevron-down" :size="14" />
        </div>
        <div class="pxn-shell__omni">
          <lucide-icon name="search" :size="14" />
          <span>Buscar productos, ventas, clientes…</span>
          <kbd class="pxn-mono">⌘K</kbd>
        </div>
        <div class="pxn-shell__topbar-trail">
          <span class="pxn-shell__fresh">
            <span class="pxn-shell__fresh-dot"></span>
            Datos al día · 14:02
          </span>
          <span class="pxn-shell__icobtn"><lucide-icon name="bell" :size="16" /><i class="pxn-shell__badge"></i></span>
          <span class="pxn-shell__user">
            <span class="pxn-shell__avatar">BE</span>
            <span class="pxn-shell__user-text"><b>Betzabé Escobar</b><small>Administradora</small></span>
          </span>
        </div>
      </header>

      <div class="pxn-shell__canvas" aria-hidden="true">
        <div class="pxn-shell__skel pxn-shell__skel--title"></div>
        <div class="pxn-shell__skel-row">
          <div class="pxn-shell__skel pxn-shell__skel--kpi"></div>
          <div class="pxn-shell__skel pxn-shell__skel--kpi"></div>
          <div class="pxn-shell__skel pxn-shell__skel--kpi"></div>
        </div>
        <div class="pxn-shell__skel pxn-shell__skel--table"></div>
      </div>
    </div>

    <p class="pxn-shell__note">
      <lucide-icon name="atom" :size="13" />
      Hipótesis visual. No modifica <code>Sidebar.vue</code>, <code>VerticalSidebar.vue</code>,
      <code>TopNav</code> ni <code>friendlyNavigation</code>. Requiere aprobación antes de tocar la arquitectura de navegación.
    </p>
  </div>
</template>

<script>
export default {
  name: "PxShellMock",
  props: {
    modules: { type: Array, required: true }, // [{ key, label, icon, foot? }]
    active: { type: String, default: "dashboard" },
    showPanel: { type: Boolean, default: true },
    panelTitle: { type: String, default: "Inventario" },
    panelItems: { type: Array, default: () => [] }, // flat fallback
    panelGroups: { type: Array, default: () => [] }, // [{ title, items: [{ label, icon, count?, cond? }] }]
    panelReports: { type: Array, default: () => [] },
    panelActive: { type: Number, default: 0 },
    branchName: { type: String, default: "Sucursal San Pedro Sula" }
  },
  computed: {
    railMain() { return this.modules.filter(m => !m.foot); },
    railFoot() {
      const foot = this.modules.filter(m => m.foot);
      return foot.length ? foot : [
        { key: "help", label: "Ayuda", icon: "life-buoy" },
        { key: "settings", label: "Configuración", icon: "settings" }
      ];
    }
  },
  methods: {
    formatCount(n) {
      return typeof n === "number" && n >= 1000 ? n.toLocaleString("es-HN") : n;
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-shell {
  display: grid;
  grid-template-columns: 56px auto 1fr;
  grid-template-rows: 1fr auto;
  gap: 0;
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-xl);
  background: var(--pxn-bg);
  overflow: hidden;
  height: 480px;
}
.pxn-shell__note {
  grid-column: 1 / -1;
  display: flex; align-items: center; gap: var(--pxn-space-3);
  margin: 0;
  padding: var(--pxn-space-4) var(--pxn-space-6);
  border-top: 1px solid var(--pxn-border);
  background: var(--pxn-surface-2);
  font-size: var(--pxn-fs-xs);
  color: var(--pxn-ink-3);
}
.pxn-shell__note code { font-size: 0.92em; color: var(--pxn-ink-2); }

/* rail */
.pxn-shell__rail {
  display: flex; flex-direction: column; align-items: center;
  gap: var(--pxn-space-5);
  padding: var(--pxn-space-5) 0;
  background: var(--pxn-surface);
  border-right: 1px solid var(--pxn-border);
}
.pxn-shell__brand {
  width: 30px; height: 30px; border-radius: var(--pxn-radius-md);
  display: flex; align-items: center; justify-content: center;
  background: var(--pxn-primary); color: var(--pxn-primary-contrast);
  font-weight: var(--pxn-fw-bold); font-size: 15px;
}
.pxn-shell__modules { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--pxn-space-2); flex: 1; }
.pxn-shell__rail-foot { display: flex; flex-direction: column; gap: var(--pxn-space-2); }
.pxn-shell__module {
  position: relative;
  width: 36px; height: 36px; border-radius: var(--pxn-radius-md);
  display: flex; align-items: center; justify-content: center;
  color: var(--pxn-ink-3);
}
/* Estado activo INEQUÍVOCO (requisito B0): marca de posición + fondo + color */
.pxn-shell__module.is-active { background: var(--pxn-primary-soft); color: var(--pxn-primary-ink); }
.pxn-shell__module.is-active::before {
  content: "";
  position: absolute;
  left: -10px; top: 7px; bottom: 7px;
  width: 3px; border-radius: 0 3px 3px 0;
  background: var(--pxn-primary);
}

/* panel */
.pxn-shell__panel { width: 232px; background: var(--pxn-surface); border-right: 1px solid var(--pxn-border); padding: var(--pxn-space-5); overflow-y: auto; max-height: 100%; }
.pxn-shell__panel-head {
  display: flex; align-items: center; justify-content: space-between;
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
  text-transform: uppercase; letter-spacing: 0.05em; color: var(--pxn-ink-3);
  padding: 0 var(--pxn-space-3) var(--pxn-space-4);
}
.pxn-shell__panel-group { margin-bottom: var(--pxn-space-4); }
.pxn-shell__panel-grouptitle {
  padding: var(--pxn-space-3) var(--pxn-space-3) var(--pxn-space-2);
  font-size: 10px; font-weight: var(--pxn-fw-semibold);
  text-transform: uppercase; letter-spacing: 0.06em; color: var(--pxn-ink-3);
}
.pxn-shell__panel-cond { font-size: 9px; color: var(--pxn-ink-3); background: var(--pxn-surface-3); padding: 1px 4px; border-radius: 3px; white-space: nowrap; }
.pxn-shell__panel-list--reports li { color: var(--pxn-ink-3); font-size: var(--pxn-fs-xs); }
.pxn-shell__panel-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }
.pxn-shell__panel-list li {
  display: flex; align-items: center; gap: var(--pxn-space-3);
  padding: var(--pxn-space-3) var(--pxn-space-3);
  border-radius: var(--pxn-radius-sm);
  font-size: var(--pxn-fs-sm);
  color: var(--pxn-ink-2);
}
.pxn-shell__panel-list li.is-active { background: var(--pxn-primary-soft); color: var(--pxn-primary-ink); font-weight: var(--pxn-fw-medium); }
.pxn-shell__panel-list li span:nth-child(2) { flex: 1; }
.pxn-shell__panel-count { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }

/* main */
.pxn-shell__main { display: flex; flex-direction: column; min-width: 0; }
.pxn-shell__topbar {
  display: flex; align-items: center; gap: var(--pxn-space-5);
  height: 52px; padding: 0 var(--pxn-space-6);
  background: var(--pxn-surface);
  border-bottom: 1px solid var(--pxn-border);
}
.pxn-shell__branch {
  display: inline-flex; align-items: center; gap: var(--pxn-space-3);
  height: 32px; padding: 0 var(--pxn-space-4);
  border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md);
  font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2);
}
.pxn-shell__branch-mark {
  font-size: 10px; font-weight: var(--pxn-fw-bold);
  background: var(--pxn-tag-slate-soft); color: var(--pxn-tag-slate-ink);
  padding: 2px 4px; border-radius: 3px;
}
.pxn-shell__branch-name { max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pxn-shell__omni {
  flex: 1; max-width: 380px;
  display: inline-flex; align-items: center; gap: var(--pxn-space-3);
  height: 32px; padding: 0 var(--pxn-space-4);
  background: var(--pxn-surface-2); border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md);
  font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3);
}
.pxn-shell__omni kbd {
  margin-left: auto; font-size: 10px; padding: 1px 5px;
  background: var(--pxn-surface); border: 1px solid var(--pxn-border); border-radius: 4px;
}
.pxn-shell__topbar-trail { margin-left: auto; display: inline-flex; align-items: center; gap: var(--pxn-space-5); }
.pxn-shell__fresh {
  display: inline-flex; align-items: center; gap: var(--pxn-space-3);
  font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3);
}
.pxn-shell__fresh-dot { width: 6px; height: 6px; border-radius: 999px; background: var(--pxn-success); }
.pxn-shell__icobtn { position: relative; color: var(--pxn-ink-3); display: inline-flex; }
.pxn-shell__badge { position: absolute; top: -2px; right: -2px; width: 6px; height: 6px; border-radius: 999px; background: var(--pxn-danger); }
.pxn-shell__user { display: inline-flex; align-items: center; gap: var(--pxn-space-3); }
.pxn-shell__avatar {
  width: 26px; height: 26px; border-radius: 999px;
  display: inline-flex; align-items: center; justify-content: center;
  background: var(--pxn-tag-indigo-soft); color: var(--pxn-tag-indigo-ink);
  font-size: 10px; font-weight: var(--pxn-fw-bold);
}
.pxn-shell__user-text { display: flex; flex-direction: column; line-height: 1.2; }
.pxn-shell__user-text b { font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxn-shell__user-text small { font-size: 10px; color: var(--pxn-ink-3); }

.pxn-shell__canvas { flex: 1; padding: var(--pxn-space-7); display: flex; flex-direction: column; gap: var(--pxn-space-5); }
.pxn-shell__skel { background: var(--pxn-surface-2); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); }
.pxn-shell__skel--title { width: 220px; height: 26px; }
.pxn-shell__skel-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--pxn-space-5); }
.pxn-shell__skel--kpi { height: 76px; }
.pxn-shell__skel--table { flex: 1; min-height: 120px; }

@media (max-width: 720px) {
  .pxn-shell { grid-template-columns: 56px 1fr; }
  .pxn-shell__panel { display: none; }
}
</style>
