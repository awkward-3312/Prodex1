<template>
  <!--
    Landing de un dominio del shell px-next. El shell NO es todavía el layout
    persistente: la navegación real ocurre en el panel contextual (rutas reales
    que hoy salen del shell, igual que en Milestone 1).
    · Reportes → hub ligero con búsqueda (misma fuente: shell-nav.js).
    · Finanzas · RR. HH. · Configuración · Más → orientación breve.
  -->
  <div class="px-next pxn-domain">
    <!-- ============ HUB DE REPORTES ============ -->
    <div v-if="seg === 'reportes'" class="pxn-hub">
      <header class="pxn-hub__head">
        <h1 class="pxn-hub__title">Reportes</h1>
        <p class="pxn-hub__lead">
          {{ totalPermitted }} reportes disponibles según tu acceso.
          <template v-if="activeCat"> · Categoría: <strong>{{ activeCat.title }}</strong>
            <router-link :to="{ path: '/app/shell/reportes' }" class="pxn-hub__clearcat">ver todas</router-link>
          </template>
        </p>
        <div class="pxn-hub__search">
          <lucide-icon name="search" :size="15" />
          <input
            ref="search"
            v-model="query"
            type="search"
            class="pxn-hub__search-input"
            placeholder="Buscar reporte…"
            aria-label="Buscar reporte"
          />
          <button v-if="query" type="button" class="pxn-hub__search-clear" aria-label="Limpiar búsqueda" @click="query = ''">
            <lucide-icon name="x" :size="14" />
          </button>
        </div>
      </header>

      <div v-if="!filteredGroups.length" class="pxn-hub__empty">
        Sin reportes que coincidan con «{{ query }}».
      </div>

      <div v-for="g in filteredGroups" :key="g.key" class="pxn-hub__group">
        <div class="pxn-hub__grouptitle">{{ g.title }}</div>
        <ul class="pxn-hub__list">
          <li v-for="it in g.items" :key="it.route">
            <router-link :to="it.route" class="pxn-hub__item pxn-ring">
              <lucide-icon :name="it.icon" :size="15" class="pxn-hub__item-icon" />
              <span class="pxn-hub__item-label">{{ it.label }}</span>
              <lucide-icon name="arrow-up-right" :size="13" class="pxn-hub__item-ext" aria-hidden="true" />
            </router-link>
          </li>
        </ul>
      </div>
    </div>

    <!-- ============ ORIENTACIÓN (resto de dominios) ============ -->
    <div v-else class="pxn-domain__box">
      <span class="pxn-domain__icon" aria-hidden="true">
        <lucide-icon :name="icon" :size="22" />
      </span>
      <h1 class="pxn-domain__title">{{ title }}</h1>
      <p class="pxn-domain__hint">Elige una opción del panel para continuar.</p>
    </div>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import { SHELL_REPORTS } from "@/views/app/_ui/data/shell-nav";

const MAP = {
  finanzas: { title: "Finanzas", icon: "calculator" },
  reportes: { title: "Reportes", icon: "bar-chart-3" },
  rrhh: { title: "RR. HH.", icon: "id-card" },
  config: { title: "Configuración", icon: "settings" },
  mas: { title: "Más herramientas", icon: "layout-grid" }
};

function norm(s) {
  return String(s || "")
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "");
}

export default {
  name: "ShellDomainLanding",
  data() {
    return { query: "" };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    seg() {
      return (this.$route.path.split("/")[3] || "").toLowerCase();
    },
    title() {
      return (MAP[this.seg] || {}).title || "Dominio";
    },
    icon() {
      return (MAP[this.seg] || {}).icon || "layout-dashboard";
    },

    // Réplica del gate de Sidebar.vue — no duplica lógica de permisos/plan.
    planFeatures() {
      const ps = window.__planSummary;
      return ps && ps.has_plan && ps.features ? ps.features : {};
    },

    // Categoría seleccionada desde el panel contextual (?cat=<key>), si es válida.
    activeCat() {
      const k = String(this.$route.query.cat || "");
      return SHELL_REPORTS.find(c => c.key === k) || null;
    },

    // Catálogo filtrado por permiso + plan (mismo criterio que el panel).
    permittedGroups() {
      return SHELL_REPORTS.map(c => ({
        key: c.key,
        title: c.title,
        items: c.items.filter(it => this.itemAllowed(it))
      })).filter(g => g.items.length);
    },

    totalPermitted() {
      return this.permittedGroups.reduce((n, g) => n + g.items.length, 0);
    },

    // Búsqueda activa: filtra en frontend sobre TODO lo permitido (ignora ?cat,
    // para no esconder coincidencias de otras categorías). Sin búsqueda: respeta
    // la categoría elegida en el panel contextual.
    filteredGroups() {
      const q = norm(this.query).trim();
      if (!q) {
        return this.activeCat
          ? this.permittedGroups.filter(g => g.key === this.activeCat.key)
          : this.permittedGroups;
      }
      return this.permittedGroups
        .map(g => ({
          key: g.key,
          title: g.title,
          items: g.items.filter(it => norm(it.label).indexOf(q) !== -1 || norm(g.title).indexOf(q) !== -1)
        }))
        .filter(g => g.items.length);
    }
  },
  methods: {
    planFeature(key) {
      const f = this.planFeatures[key];
      return f ? f.enabled : true;
    },
    hasAnyPerm(perms) {
      if (!perms || !perms.length) return true;
      const mine = this.currentUserPermissions || [];
      return perms.some(p => mine.indexOf(p) !== -1);
    },
    itemAllowed(it) {
      if (it.plan && !this.planFeature(it.plan)) return false;
      return this.hasAnyPerm(it.anyPerm);
    }
  }
};
</script>

<style lang="scss" scoped>
.pxn-domain {
  min-height: 100%;
  background: var(--pxn-bg);
}

/* ---- Orientación breve (Finanzas / RR. HH. / Config / Más) ---- */
.pxn-domain__box {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--pxn-space-4);
  max-width: 360px;
  margin: 0 auto;
  padding: var(--pxn-space-12) var(--pxn-space-8);
  text-align: center;
}
.pxn-domain__icon {
  width: 48px; height: 48px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: var(--pxn-radius-lg);
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border);
  color: var(--pxn-ink-3);
}
.pxn-domain__title { margin: 0; font-size: var(--pxn-fs-h1); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); letter-spacing: -0.02em; }
.pxn-domain__hint { margin: 0; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); line-height: var(--pxn-lh-snug); }

/* ---- Hub de Reportes ---- */
.pxn-hub {
  max-width: 900px;
  margin: 0 auto;
  padding: var(--pxn-space-9) var(--pxn-space-9) var(--pxn-space-12);
}
@media (max-width: 620px) {
  .pxn-hub { padding: var(--pxn-space-6) var(--pxn-space-5) var(--pxn-space-10); }
}
.pxn-hub__head { margin-bottom: var(--pxn-space-8); }
.pxn-hub__title { margin: 0 0 var(--pxn-space-3); font-size: var(--pxn-fs-display); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); letter-spacing: -0.02em; }
.pxn-hub__lead { margin: 0 0 var(--pxn-space-6); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); line-height: var(--pxn-lh-snug); }
.pxn-hub__lead strong { color: var(--pxn-ink-2); font-weight: var(--pxn-fw-medium); }
.pxn-hub__clearcat { margin-left: var(--pxn-space-3); font-size: var(--pxn-fs-xs); color: var(--pxn-primary-ink); }
.pxn-hub__clearcat:hover { text-decoration: underline; }

.pxn-hub__search {
  display: flex; align-items: center; gap: var(--pxn-space-3);
  max-width: 420px;
  height: var(--pxn-control-h-md);
  padding: 0 var(--pxn-space-4);
  border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
  color: var(--pxn-ink-3);
  transition: border-color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-hub__search:focus-within { border-color: var(--pxn-primary-border); }
.pxn-hub__search-input {
  flex: 1; min-width: 0;
  border: 0; background: transparent; outline: none;
  font: inherit; font-size: var(--pxn-fs-sm); color: var(--pxn-ink);
}
.pxn-hub__search-input::placeholder { color: var(--pxn-ink-3); }
.pxn-hub__search-clear {
  flex: none; display: inline-flex; padding: 2px;
  border: 0; background: transparent; color: var(--pxn-ink-3); cursor: pointer;
  border-radius: var(--pxn-radius-xs);
}
.pxn-hub__search-clear:hover { color: var(--pxn-ink); background: var(--pxn-surface-2); }

.pxn-hub__group { margin-bottom: var(--pxn-space-7); }
.pxn-hub__grouptitle {
  padding: var(--pxn-space-2) 0 var(--pxn-space-3);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
  text-transform: uppercase; letter-spacing: 0.06em; color: var(--pxn-ink-3);
  border-bottom: 1px solid var(--pxn-border);
}
.pxn-hub__list {
  list-style: none; margin: 0; padding: var(--pxn-space-2) 0 0;
  display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 2px var(--pxn-space-5);
}
@media (max-width: 620px) { .pxn-hub__list { grid-template-columns: minmax(0, 1fr); } }
.pxn-hub__item {
  display: flex; align-items: center; gap: var(--pxn-space-3);
  padding: var(--pxn-space-3) var(--pxn-space-3);
  border-radius: var(--pxn-radius-sm);
  font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2);
  text-decoration: none;
  transition: background var(--pxn-dur-1) var(--pxn-ease), color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-hub__item:hover { background: var(--pxn-surface-2); color: var(--pxn-ink); text-decoration: none; }
.pxn-hub__item-icon { flex: none; color: var(--pxn-ink-3); }
.pxn-hub__item:hover .pxn-hub__item-icon { color: var(--pxn-ink-2); }
.pxn-hub__item-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pxn-hub__item-ext { flex: none; color: var(--pxn-ink-disabled); }
.pxn-hub__item:hover .pxn-hub__item-ext { color: var(--pxn-ink-3); }

.pxn-hub__empty {
  padding: var(--pxn-space-10) 0;
  font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3);
}

@media (prefers-reduced-motion: reduce) {
  .pxn-hub__search, .pxn-hub__item, .pxn-hub__item-icon, .pxn-hub__item-ext { transition: none; }
}
</style>
