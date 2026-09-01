<template>
  <!--
    px-next · Shell funcional (navegable) — "Panel de operación".
    Continúa la maqueta aprobada `PxShellMock.vue` (mismas clases `.pxn-shell__*`,
    mismos tokens `--pxn-*`) pero con navegación REAL:
      · riel  → <router-link> a /app/shell/<dominio> (4 dominios core cableados)
      · panel → items reales filtrados por permiso/plan (mismo mecanismo que Sidebar.vue)
      · topbar→ <top-nav /> real embebido (POS, idioma, notificaciones, usuario,
                perfil/config/logout intactos — sin reimplementar)
      · área  → <slot /> con la página real (dashboard/next, index_sale, …)
    NO sustituye largeSidebar/index.vue. NO toca Sidebar.vue / VerticalSidebar.vue /
    TopNav.vue / router real. Vive sólo bajo /app/shell.
  -->
  <div class="px-next pxn-shell pxn-shell--live">
    <!-- ==================== RIEL PRINCIPAL ==================== -->
    <nav class="pxn-shell__rail" aria-label="Navegación principal">
      <router-link to="/app/dashboard" class="pxn-shell__brand pxn-ring" aria-label="PRODEX — ir al inicio">P</router-link>

      <ul class="pxn-shell__modules">
        <li v-for="m in visibleRail" :key="m.key">
          <router-link
            v-if="!m.pending"
            :to="m.to"
            class="pxn-shell__module pxn-ring"
            :class="{ 'is-active': m.key === activeDomain }"
            :title="m.label"
            :aria-label="m.label"
            :aria-current="m.key === activeDomain ? 'page' : null"
          >
            <lucide-icon :name="m.icon" :size="17" />
          </router-link>
          <span
            v-else
            class="pxn-shell__module is-pending"
            :title="m.label + ' — pendiente'"
            :aria-label="m.label + ' (pendiente)'"
            aria-disabled="true"
          >
            <lucide-icon :name="m.icon" :size="17" />
          </span>
        </li>
      </ul>

      <div class="pxn-shell__rail-foot">
        <span
          v-for="m in foot"
          :key="m.key"
          class="pxn-shell__module is-pending"
          :title="m.label + ' — pendiente'"
          :aria-label="m.label + ' (pendiente)'"
          aria-disabled="true"
        ><lucide-icon :name="m.icon" :size="17" /></span>
      </div>
    </nav>

    <!-- ==================== PANEL CONTEXTUAL ==================== -->
    <aside v-if="activePanel" class="pxn-shell__panel pxn-scroll" :aria-label="'Opciones de ' + activePanel.title">
      <div class="pxn-shell__panel-head">
        <span>{{ activePanel.title }}</span>
      </div>

      <div v-for="(g, gi) in visiblePanelGroups" :key="'g' + gi" class="pxn-shell__panel-group">
        <div class="pxn-shell__panel-grouptitle">{{ g.title }}</div>
        <ul class="pxn-shell__panel-list">
          <li v-for="(it, i) in g.items" :key="i">
            <router-link
              :to="it.to || it.route"
              class="pxn-shell__panel-link"
              :class="{ 'is-active': isActiveItem(it) }"
              :aria-current="isActiveItem(it) ? 'page' : null"
            >
              <lucide-icon :name="it.icon" :size="14" />
              <span>{{ it.label }}</span>
              <span v-if="it.route && !it.to" class="pxn-shell__panel-ext" aria-hidden="true">
                <lucide-icon name="arrow-up-right" :size="12" />
              </span>
            </router-link>
          </li>
        </ul>
      </div>

      <div v-if="activePanel.reportsInline && activePanel.reportsInline.length" class="pxn-shell__panel-group">
        <div class="pxn-shell__panel-grouptitle">Reportes del módulo</div>
        <ul class="pxn-shell__panel-list pxn-shell__panel-list--reports">
          <li v-for="(r, i) in activePanel.reportsInline" :key="'r' + i" class="pxn-shell__panel-static">
            <lucide-icon name="bar-chart-3" :size="13" /><span>{{ r }}</span>
            <span class="pxn-shell__panel-cond">pendiente</span>
          </li>
        </ul>
      </div>
    </aside>

    <!-- ==================== COLUMNA PRINCIPAL ==================== -->
    <div class="pxn-shell__main">
      <header class="pxn-shell__topbar">
        <top-nav />
      </header>
      <div class="pxn-shell__canvas pxn-scroll">
        <slot />
      </div>
    </div>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import TopNav from "@/containers/layouts/largeSidebar/TopNav.vue";
import { SHELL_RAIL, SHELL_RAIL_PENDING, SHELL_FOOT } from "@/views/app/_ui/data/shell-nav";

export default {
  name: "PxShell",
  components: { TopNav },
  data() {
    return {
      railWired: SHELL_RAIL,
      railPending: SHELL_RAIL_PENDING,
      foot: SHELL_FOOT
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),

    // Réplica EXACTA de Sidebar.vue::planFeatures — misma fuente, mismo default.
    planFeatures() {
      const ps = window.__planSummary;
      return ps && ps.has_plan && ps.features ? ps.features : {};
    },

    // Dominio activo derivado de la ruta real del shell.
    activeDomain() {
      const seg = (this.$route.path.split("/")[3] || "panel").toLowerCase();
      return ["panel", "ventas", "inventario", "compras"].indexOf(seg) !== -1 ? seg : "panel";
    },

    // Riel visible = wired (gate real permiso + plan) seguido de pendientes (informativos).
    visibleRail() {
      const wired = this.railWired.filter(m => {
        if (m.always) return true;
        if (m.plan && !this.planFeature(m.plan)) return false;
        return this.hasAnyPerm(m.anyPerm);
      });
      return wired.concat(this.railPending);
    },

    activeRailEntry() {
      return this.railWired.find(m => m.key === this.activeDomain) || null;
    },

    activePanel() {
      return this.activeRailEntry ? this.activeRailEntry.panel : null;
    },

    // Grupos del panel con items filtrados por permiso/plan; grupos vacíos fuera.
    visiblePanelGroups() {
      if (!this.activePanel || !this.activePanel.groups) return [];
      return this.activePanel.groups
        .map(g => ({
          title: g.title,
          items: g.items.filter(it => {
            if (it.plan && !this.planFeature(it.plan)) return false;
            return this.hasAnyPerm(it.anyPerm);
          })
        }))
        .filter(g => g.items.length);
    }
  },
  methods: {
    // Réplica EXACTA de Sidebar.vue::planFeature.
    planFeature(key) {
      const f = this.planFeatures[key];
      return f ? f.enabled : true;
    },
    // Mismo patrón de gate que Sidebar.vue: al menos uno de los permisos.
    hasAnyPerm(perms) {
      if (!perms || !perms.length) return true;
      const mine = this.currentUserPermissions || [];
      return perms.some(p => mine.indexOf(p) !== -1);
    },
    isActiveItem(it) {
      if (!it.to) return false;
      return this.$route.path === it.to || this.$route.path.indexOf(it.to + "/") === 0;
    }
  }
};
</script>

<style lang="scss" scoped>
/* -------------------------------------------------------------------------
   Continuidad con PxShellMock.vue: mismas clases, mismos tokens. Aquí sólo
   los ajustes para la versión a pantalla completa y navegable.
   ------------------------------------------------------------------------- */
.pxn-shell {
  display: grid;
  grid-template-columns: 56px auto 1fr;
  grid-template-rows: 1fr;
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-xl);
  background: var(--pxn-bg);
  overflow: hidden;
  height: 480px;
}
.pxn-shell--live {
  height: 100vh;
  border: 0;
  border-radius: 0;
}

/* riel */
.pxn-shell__rail {
  display: flex; flex-direction: column; align-items: center;
  gap: var(--pxn-space-5);
  padding: var(--pxn-space-5) 0;
  background: var(--pxn-surface);
  border-right: 1px solid var(--pxn-border);
}
.pxn-shell__modules { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; align-items: center; gap: var(--pxn-space-2); flex: 1; }
.pxn-shell__rail-foot { display: flex; flex-direction: column; align-items: center; gap: var(--pxn-space-2); }
.pxn-shell__brand {
  width: 30px; height: 30px; border-radius: var(--pxn-radius-md);
  display: flex; align-items: center; justify-content: center;
  background: var(--pxn-primary); color: var(--pxn-primary-contrast);
  font-weight: var(--pxn-fw-bold); font-size: 15px;
  text-decoration: none;
}
.pxn-shell__brand:hover { text-decoration: none; }
.pxn-shell__module {
  position: relative;
  width: 36px; height: 36px; border-radius: var(--pxn-radius-md);
  display: flex; align-items: center; justify-content: center;
  color: var(--pxn-ink-3);
  text-decoration: none;
  transition: color var(--pxn-dur-1) var(--pxn-ease), background var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-shell__module:hover { text-decoration: none; }
a.pxn-shell__module:hover { background: var(--pxn-surface-2); color: var(--pxn-ink-2); }
/* Estado activo INEQUÍVOCO (requisito B0): barra de posición + fondo + color + peso */
.pxn-shell__module.is-active { background: var(--pxn-primary-soft); color: var(--pxn-primary-ink); }
.pxn-shell__module.is-active::before {
  content: "";
  position: absolute;
  left: -10px; top: 7px; bottom: 7px;
  width: 3px; border-radius: 0 3px 3px 0;
  background: var(--pxn-primary);
}
.pxn-shell__module.is-pending { color: var(--pxn-ink-disabled); cursor: not-allowed; }

/* panel contextual */
.pxn-shell__panel { width: 232px; background: var(--pxn-surface); border-right: 1px solid var(--pxn-border); padding: var(--pxn-space-5); overflow-y: auto; }
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
.pxn-shell__panel-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }
.pxn-shell__panel-link {
  display: flex; align-items: center; gap: var(--pxn-space-3);
  padding: var(--pxn-space-3);
  border-radius: var(--pxn-radius-sm);
  font-size: var(--pxn-fs-sm);
  color: var(--pxn-ink-2);
  text-decoration: none;
  transition: color var(--pxn-dur-1) var(--pxn-ease), background var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-shell__panel-link:hover { text-decoration: none; background: var(--pxn-surface-2); color: var(--pxn-ink); }
.pxn-shell__panel-link > span:first-of-type { flex: 1; }
.pxn-shell__panel-link.is-active { background: var(--pxn-primary-soft); color: var(--pxn-primary-ink); font-weight: var(--pxn-fw-medium); }
.pxn-shell__panel-ext { color: var(--pxn-ink-3); display: inline-flex; }
.pxn-shell__panel-static {
  display: flex; align-items: center; gap: var(--pxn-space-3);
  padding: var(--pxn-space-3);
  font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3);
}
.pxn-shell__panel-static > span:first-of-type { flex: 1; }
.pxn-shell__panel-cond { font-size: 9px; color: var(--pxn-ink-3); background: var(--pxn-surface-3); padding: 1px 4px; border-radius: 3px; white-space: nowrap; }
.pxn-shell__panel-list--reports li { color: var(--pxn-ink-3); }

/* columna principal */
.pxn-shell__main { display: flex; flex-direction: column; min-width: 0; }
.pxn-shell__topbar {
  flex: none;
  border-bottom: 1px solid var(--pxn-border);
  background: var(--pxn-surface);
}
/* El <top-nav /> real trae su propio .main-header; lo dejamos operar tal cual,
   sólo lo encajamos en la rejilla del shell. */
.pxn-shell__topbar ::v-deep .main-header {
  position: static;
  width: 100%;
  box-shadow: none;
  margin: 0;
}
.pxn-shell__canvas { flex: 1; min-height: 0; overflow: auto; background: var(--pxn-bg); }

@media (max-width: 900px) {
  .pxn-shell--live { grid-template-columns: 56px 1fr; }
  .pxn-shell--live .pxn-shell__panel { display: none; }
}
</style>
