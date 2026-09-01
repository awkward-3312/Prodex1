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
  <div class="px-next pxn-shell pxn-shell--live" :class="{ 'is-navdrawer-open': navDrawerOpen }">
    <!-- ================= RIEL + PANEL (drawer en tablet/móvil) ================= -->
    <div
      id="pxn-shell-nav"
      ref="shellNav"
      class="pxn-shell__nav"
      :class="{ 'is-drawer': isCompact }"
      :role="isCompact ? 'dialog' : null"
      :aria-modal="isCompact && navDrawerOpen ? 'true' : null"
      aria-label="Navegación"
      @keydown="onNavKeydown"
    >
      <!-- ---- RIEL PRINCIPAL ---- -->
      <nav class="pxn-shell__rail" aria-label="Navegación principal">
        <router-link to="/app/dashboard" class="pxn-shell__brand pxn-ring" aria-label="Ir al panel">
          <img class="pxn-shell__brand-logo" :src="brandLogoSrc" alt="" @error="brandLogoBroken = true" />
        </router-link>

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
              <span class="pxn-shell__module-label">{{ m.label }}</span>
            </router-link>
            <span
              v-else
              class="pxn-shell__module is-pending"
              :title="m.label + ' — pendiente'"
              :aria-label="m.label + ' (pendiente)'"
              aria-disabled="true"
            >
              <lucide-icon :name="m.icon" :size="17" />
              <span class="pxn-shell__module-label">{{ m.label }}</span>
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
          >
            <lucide-icon :name="m.icon" :size="17" />
            <span class="pxn-shell__module-label">{{ m.label }}</span>
          </span>
        </div>
      </nav>

      <!-- ---- PANEL CONTEXTUAL ---- -->
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
                @click.native="navDrawerOpen = false"
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
    </div>

    <!-- Backdrop del drawer (tablet/móvil) -->
    <div class="pxn-shell__navbackdrop" aria-hidden="true" @click="closeNavDrawer"></div>

    <!-- ==================== COLUMNA PRINCIPAL ==================== -->
    <div class="pxn-shell__main">
      <header class="pxn-shell__topbar">
        <!-- Acceso al riel + panel contextual en tablet/móvil (no hover). -->
        <button
          ref="navToggle"
          type="button"
          class="pxn-shell__navtoggle pxn-ring"
          :aria-expanded="navDrawerOpen ? 'true' : 'false'"
          aria-haspopup="true"
          aria-controls="pxn-shell-nav"
          :aria-label="navDrawerOpen ? 'Cerrar navegación' : ('Abrir navegación — ' + activeDomainLabel)"
          @click="toggleNavDrawer"
        >
          <lucide-icon :name="navDrawerOpen ? 'x' : 'menu'" :size="18" />
          <span class="pxn-shell__navtoggle-label">{{ activeDomainLabel }}</span>
        </button>

        <!-- Funciones reales intactas (POS, idioma, dark mode, fullscreen,
             Existencias). Dentro del shell se ocultan visualmente los bloques
             redundantes/duplicados del parche global y de TopNav (logo grande,
             hamburger, dropdown de usuario, campana, píldora de contexto,
             botón de incidencias) y se reemplazan por controles px-next.
             No se toca TopNav global ni los scripts globales. -->
        <top-nav />

        <div class="pxn-topbar__rhs">

        <!-- Alcance de VISUALIZACIÓN por sucursal. Sólo filtra lecturas del
             panel (branch_id → dashboard_data). No toca contexto operativo. -->
        <div
          v-if="scopeBranches.length"
          ref="scopechip"
          class="pxn-scopechip"
          :class="{ 'is-open': scopeOpen }"
        >
          <button
            type="button"
            class="pxn-scopechip__btn pxn-ring"
            :aria-expanded="scopeOpen ? 'true' : 'false'"
            aria-haspopup="listbox"
            aria-label="Alcance de sucursal para el panel"
            @click="toggleScope"
          >
            <lucide-icon name="building-2" :size="15" class="pxn-scopechip__lead" />
            <span class="pxn-scopechip__label">{{ scopeLabel }}</span>
            <lucide-icon name="chevron-down" :size="14" class="pxn-scopechip__caret" />
          </button>

          <div v-if="scopeOpen" class="pxn-scopechip__menu" role="listbox">
            <button
              type="button" role="option"
              class="pxn-scopechip__opt"
              :class="{ 'is-active': !shellBranchId }"
              :aria-selected="!shellBranchId ? 'true' : 'false'"
              @click="pickBranch(0)"
            >
              <lucide-icon v-if="!shellBranchId" name="check" :size="14" class="pxn-scopechip__check" />
              <span v-else class="pxn-scopechip__check-sp" aria-hidden="true"></span>
              <span>Todas las sucursales</span>
            </button>
            <button
              v-for="b in scopeBranches"
              :key="b.id"
              type="button" role="option"
              class="pxn-scopechip__opt"
              :class="{ 'is-active': Number(shellBranchId) === Number(b.id) }"
              :aria-selected="Number(shellBranchId) === Number(b.id) ? 'true' : 'false'"
              @click="pickBranch(b.id)"
            >
              <lucide-icon v-if="Number(shellBranchId) === Number(b.id)" name="check" :size="14" class="pxn-scopechip__check" />
              <span v-else class="pxn-scopechip__check-sp" aria-hidden="true"></span>
              <span>{{ b.name }}</span>
            </button>
          </div>
        </div>

        <!-- Cluster de ATENCIÓN: incidencias operacionales + notificaciones.
             Mismo lenguaje px-next; funciones distintas. -->
        <div class="pxn-topbar__attn">

        <!-- Incidencias de transferencias — estado según abiertas reales.
             Abre el modal REAL del parche global. -->
        <button
          v-if="issuesAvailable"
          type="button"
          class="pxn-issues pxn-ring"
          :class="{ 'is-warn': issuesOpenCount > 0 }"
          :aria-label="issuesAria"
          :title="issuesAria"
          @click="openIssues"
        >
          <lucide-icon name="alert-triangle" :size="16" />
          <span v-if="issuesOpenCount > 0" class="pxn-issues__badge pxn-num">{{ issuesOpenCount > 99 ? '99+' : issuesOpenCount }}</span>
        </button>

        <!-- Campana px-next: el badge cuenta SÓLO notificaciones no leídas. -->
        <div ref="notif" class="pxn-notif" :class="{ 'is-open': notifOpen }">
          <button
            type="button"
            class="pxn-notif__btn pxn-ring"
            :aria-expanded="notifOpen ? 'true' : 'false'"
            aria-haspopup="menu"
            :aria-label="notifBadge > 0 ? notifBadge + ' notificaciones sin leer' : 'Notificaciones'"
            @click="toggleNotif"
          >
            <lucide-icon name="bell" :size="17" />
            <span v-if="notifBadge > 0" class="pxn-notif__badge pxn-num">{{ notifBadge > 99 ? '99+' : notifBadge }}</span>
          </button>

          <div v-if="notifOpen" class="pxn-notif__menu" role="menu">
            <div class="pxn-notif__head">
              <span>Notificaciones</span>
              <span v-if="notifBadge > 0" class="pxn-notif__count pxn-num">{{ notifBadge }} sin leer</span>
              <span v-else class="pxn-notif__count pxn-notif__count--clear">Al día</span>
            </div>
            <div v-if="notifLoading && !notifItems.length" class="pxn-notif__empty">Cargando…</div>
            <div v-else-if="!notifItems.length" class="pxn-notif__empty">No tienes notificaciones que requieran atención.</div>
            <ul v-else class="pxn-notif__list pxn-scroll">
              <li v-for="n in notifItems" :key="n.key">
                <button
                  type="button"
                  class="pxn-notif__item"
                  :class="{ 'is-unread': isUnread(n) }"
                  role="menuitem"
                  @click="openNotif(n)"
                >
                  <span class="pxn-notif__dot" aria-hidden="true"></span>
                  <span class="pxn-notif__text">
                    <strong>{{ n.title || 'Notificación' }}</strong>
                    <span>{{ n.message }}</span>
                  </span>
                </button>
              </li>
            </ul>
          </div>
        </div>

        </div><!-- /.pxn-topbar__attn -->

        <div ref="userchip" class="pxn-userchip" :class="{ 'is-open': userMenuOpen }">
          <button
            type="button"
            class="pxn-userchip__btn pxn-ring"
            :aria-expanded="userMenuOpen ? 'true' : 'false'"
            aria-haspopup="menu"
            :aria-label="'Cuenta de ' + displayName + (presentRoleLabel ? ' — ' + presentRoleLabel : '')"
            @click="toggleUserMenu"
          >
            <span class="pxn-userchip__meta">
              <span class="pxn-userchip__name">{{ displayName }}</span>
              <span class="pxn-userchip__role">{{ presentRoleLabel }}</span>
            </span>
            <span class="pxn-userchip__avatar">
              <img v-if="avatarUrl" :src="avatarUrl" :alt="displayName" @error="avatarBroken = true" />
              <span v-else class="pxn-userchip__initials" aria-hidden="true">{{ initials }}</span>
            </span>
            <lucide-icon name="chevron-down" :size="13" class="pxn-userchip__caret" />
          </button>

          <div v-if="userMenuOpen" class="pxn-userchip__menu" role="menu">
            <div class="pxn-userchip__menu-head">
              <span class="pxn-userchip__avatar pxn-userchip__avatar--lg">
                <img v-if="avatarUrl" :src="avatarUrl" :alt="displayName" />
                <span v-else class="pxn-userchip__initials" aria-hidden="true">{{ initials }}</span>
              </span>
              <span class="pxn-userchip__meta">
                <span class="pxn-userchip__name">{{ displayName }}</span>
                <span class="pxn-userchip__role">{{ presentRoleLabel }}</span>
              </span>
            </div>
            <div class="pxn-userchip__menu-sep" role="separator"></div>
            <router-link to="/app/profile" class="pxn-userchip__item" role="menuitem" @click.native="closeUserMenu">
              <lucide-icon name="user" :size="15" /><span>{{ $t('profil') }}</span>
            </router-link>
            <router-link
              v-if="canSystemSettings"
              to="/app/settings/System_settings"
              class="pxn-userchip__item"
              role="menuitem"
              @click.native="closeUserMenu"
            >
              <lucide-icon name="settings" :size="15" /><span>{{ $t('Settings') }}</span>
            </router-link>
            <div class="pxn-userchip__menu-sep" role="separator"></div>
            <button type="button" class="pxn-userchip__item pxn-userchip__item--danger" role="menuitem" @click="doLogout">
              <lucide-icon name="power" :size="15" /><span>{{ $t('logout') }}</span>
            </button>
          </div>
        </div>

        </div><!-- /.pxn-topbar__rhs -->
      </header>
      <div class="pxn-shell__canvas pxn-scroll">
        <slot />
      </div>
    </div>
  </div>
</template>

<script>
import { mapGetters, mapActions } from "vuex";
import TopNav from "@/containers/layouts/largeSidebar/TopNav.vue";
import { SHELL_RAIL, SHELL_RAIL_PENDING, SHELL_FOOT } from "@/views/app/_ui/data/shell-nav";

export default {
  name: "PxShell",
  components: { TopNav },
  data() {
    return {
      railWired: SHELL_RAIL,
      railPending: SHELL_RAIL_PENDING,
      foot: SHELL_FOOT,
      // Logo del rail (misma fuente que TopNav: settings.logo → fallback oficial)
      brandLogoBroken: false,
      // Profile chip (topbar)
      userMenuOpen: false,
      profile: null,      // GET /api/Get_user_profile → firstname/lastname/role_id
      roleName: "",        // resuelto contra GET /api/roles (best-effort)
      avatarBroken: false,
      // Selector de alcance de sucursal
      scopeOpen: false,
      // Campana px-next
      notifOpen: false,
      notifItems: [],
      notifLoading: false,
      notifSeen: {},       // { firma: ts } — alertas siempre-activas "vistas" (localStorage, por usuario)
      notifTimer: null,
      // Incidencias de transferencias (fuente real que alimenta el modal global)
      issuesAvailable: false,
      issuesOpenCount: 0,
      issuesTimer: null,
      // Navegación adaptable (drawer en tablet/móvil)
      isCompact: false,
      navDrawerOpen: false
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    ...mapGetters("shellScope", ["shellBranchId", "shellBranches", "shellScopeLabel"]),

    // ---- Logo del rail ------------------------------------------------
    // Reutiliza settings.logo (misma fuente que TopNav). Fallback: marca
    // oficial PRODEX (logo-default.png) ya presente en el sistema.
    brandLogoSrc() {
      const logo =
        !this.brandLogoBroken && this.currentUser && this.currentUser.logo
          ? this.currentUser.logo
          : "logo-default.png";
      return this.$imgUrl("settings", logo);
    },

    // ---- Selector de alcance de sucursal --------------------------------
    scopeBranches() {
      return this.shellBranches || [];
    },
    scopeLabel() {
      return this.shellScopeLabel;
    },

    // ---- Incidencias de transferencias -------------------------------
    issuesAria() {
      if (this.issuesOpenCount > 0) {
        const n = this.issuesOpenCount;
        return n + (n === 1 ? " incidencia de transferencias abierta" : " incidencias de transferencias abiertas");
      }
      return "Incidencias de transferencias";
    },

    // ---- Badge de la campana = notificaciones NO LEÍDAS ----------------
    // Eventos reales: cuentan mientras `unread` (persistido server-side por read_at).
    // Alertas siempre-activas (sin read_endpoint): cuentan salvo que su FIRMA
    // (key + título + mensaje) esté en el set "visto" del usuario. Si la condición
    // cambia (p. ej. 11 → 12 productos), la firma cambia y vuelve a contar.
    notifBadge() {
      return this.notifItems.reduce((n, it) => {
        if (it.read_endpoint) return n + (it.unread ? 1 : 0);
        return n + (this.notifSeen[this.notifSig(it)] ? 0 : 1);
      }, 0);
    },

    // Nombre real: firstname + lastname del perfil; si no, el username del store.
    displayName() {
      const p = this.profile || {};
      const full = [p.firstname, p.lastname].filter(Boolean).join(" ").trim();
      return full || (this.currentUser && this.currentUser.username) || "Usuario";
    },

    // Rol real resuelto por id; sin dato → cadena vacía.
    roleLabel() {
      return this.roleName || "";
    },

    // Presentación en español SÓLO para la UI. No modifica el rol real.
    presentRoleLabel() {
      const map = {
        owner: "Propietario",
        "co-owner": "Copropietario",
        administrator: "Administrador",
        admin: "Administrador"
      };
      const key = String(this.roleName || "").trim().toLowerCase();
      return map[key] || this.roleName || "Cuenta";
    },

    // Iniciales para el fallback elegante cuando no hay foto.
    initials() {
      const src = this.displayName.replace(/\s+/g, " ").trim();
      const parts = src.split(" ");
      const a = (parts[0] || "").charAt(0);
      const b = parts.length > 1 ? (parts[parts.length - 1] || "").charAt(0) : "";
      return (a + b).toUpperCase() || "U";
    },

    // URL de avatar sólo si hay foto propia (no el placeholder por defecto).
    avatarUrl() {
      if (this.avatarBroken) return null;
      const raw =
        (this.profile && this.profile.avatar) ||
        (this.currentUser && this.currentUser.avatar) ||
        "";
      if (!raw || raw === "no_avatar.png") return null;
      return this.$imgUrl("avatar", raw);
    },

    // Mismo gate que TopNav.vue para el acceso a ajustes del sistema.
    canSystemSettings() {
      return (
        this.currentUserPermissions &&
        this.currentUserPermissions.indexOf("setting_system") !== -1
      );
    },

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

    // Etiqueta del dominio activo para el botón de navegación (tablet/móvil).
    activeDomainLabel() {
      return (this.activeRailEntry && this.activeRailEntry.label) || "Menú";
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
    },

    // ---- Profile chip -----------------------------------------------------
    ...mapActions(["logout"]),

    // Datos reales del usuario autenticado. Endpoints ya usados por la app
    // (perfil + catálogo de roles); ambos best-effort, sin romper el shell.
    loadUserMeta() {
      const ax = window.axios;
      if (!ax) return;
      ax.get("Get_user_profile")
        .then(r => {
          this.profile = (r && r.data && r.data.user) || null;
          const rid = this.profile && this.profile.role_id;
          if (!rid) return;
          return ax
            .get("roles", { params: { limit: -1, page: 1, SortField: "id", SortType: "asc" } })
            .then(rr => {
              const list = (rr && rr.data && rr.data.roles) || [];
              const hit = list.find(x => Number(x.id) === Number(rid));
              if (hit && hit.name) this.roleName = hit.name;
            })
            .catch(() => {
              /* rol restringido para este usuario: se usa el fallback "Cuenta" */
            });
        })
        .catch(() => {
          /* sin perfil: displayName cae al username del store */
        });
    },
    toggleUserMenu() {
      this.userMenuOpen = !this.userMenuOpen;
      if (this.userMenuOpen) { this.scopeOpen = false; this.notifOpen = false; }
    },
    closeUserMenu() {
      this.userMenuOpen = false;
    },
    doLogout() {
      this.userMenuOpen = false;
      this.logout(); // acción Vuex real: POST /logout + window.location.replace('/login')
    },

    // ---- Selector de alcance de sucursal --------------------------------
    toggleScope() {
      this.scopeOpen = !this.scopeOpen;
      if (this.scopeOpen) { this.userMenuOpen = false; this.notifOpen = false; }
    },
    pickBranch(id) {
      this.scopeOpen = false;
      this.$store.dispatch("shellScope/selectBranch", {
        branchId: id,
        userId: this.currentUser && this.currentUser.id
      });
    },

    // ---- Campana px-next -----------------------------------------------
    notifSeenKey() {
      const uid = (this.currentUser && this.currentUser.id) || "anon";
      return "pxn_notif_seen__" + uid;
    },
    loadNotifSeen() {
      try {
        this.notifSeen = JSON.parse(window.localStorage.getItem(this.notifSeenKey()) || "{}") || {};
      } catch (e) {
        this.notifSeen = {};
      }
    },
    persistNotifSeen() {
      try {
        window.localStorage.setItem(this.notifSeenKey(), JSON.stringify(this.notifSeen));
      } catch (e) {
        /* almacenamiento no disponible */
      }
    },
    // Firma estable de una alerta siempre-activa. Cambia si cambia su contenido.
    notifSig(it) {
      const s = String(it.key || "") + "|" + String(it.title || "") + "|" + String(it.message || "");
      let h = 5381;
      for (let i = 0; i < s.length; i++) h = ((h << 5) + h + s.charCodeAt(i)) | 0;
      return String(h >>> 0);
    },
    isUnread(n) {
      if (n.read_endpoint) return !!n.unread;
      return !this.notifSeen[this.notifSig(n)];
    },
    fetchNotifs() {
      const ax = window.axios;
      if (!ax) return;
      this.notifLoading = true;
      ax.get("/api/notification-center", {
        baseURL: "",
        meta: { skipErrorRedirect: true, skipInitialLoader: true }
      })
        .then(r => {
          const d = (r && r.data) || {};
          this.notifItems = Array.isArray(d.notifications) ? d.notifications : [];
          this.pruneNotifSeen();
        })
        .catch(() => {
          /* 401/403/red: se deja el estado previo */
        })
        .finally(() => {
          this.notifLoading = false;
        });
    },
    // Descarta firmas "vistas" que ya no corresponden a ninguna alerta viva,
    // para que el almacenamiento no crezca sin límite.
    pruneNotifSeen() {
      const live = {};
      this.notifItems.forEach(it => {
        if (!it.read_endpoint) live[this.notifSig(it)] = true;
      });
      const next = {};
      let changed = false;
      Object.keys(this.notifSeen).forEach(k => {
        if (live[k]) next[k] = this.notifSeen[k];
        else changed = true;
      });
      if (changed) {
        this.notifSeen = next;
        this.persistNotifSeen();
      }
    },
    toggleNotif() {
      this.notifOpen = !this.notifOpen;
      if (this.notifOpen) {
        this.userMenuOpen = false;
        this.scopeOpen = false;
        this.markVisibleRead();
      }
    },
    // Al abrir: los eventos con read_endpoint → read_at server-side; las alertas
    // siempre-activas → firma al set "visto" (localStorage por usuario).
    markVisibleRead() {
      const ax = window.axios;
      const posts = [];
      const seenNext = Object.assign({}, this.notifSeen);
      const now = Date.now();
      this.notifItems.forEach(it => {
        if (it.read_endpoint) {
          if (it.unread && ax) {
            posts.push(
              ax
                .post(it.read_endpoint, {}, { baseURL: "", meta: { skipErrorRedirect: true, skipInitialLoader: true } })
                .catch(() => {})
            );
          }
        } else {
          seenNext[this.notifSig(it)] = now;
        }
      });
      this.notifSeen = seenNext;
      this.persistNotifSeen();
      if (posts.length) Promise.all(posts).then(() => this.fetchNotifs());
      else this.notifItems = this.notifItems.slice();
    },
    openNotif(n) {
      const ax = window.axios;
      const go = () => {
        if (n.action) {
          if (n.action.charAt(0) === "/") this.$router.push(n.action).catch(() => {});
          else window.location.href = n.action;
        }
        this.notifOpen = false;
      };
      if (n.read_endpoint && ax) {
        ax
          .post(n.read_endpoint, {}, { baseURL: "", meta: { skipErrorRedirect: true, skipInitialLoader: true } })
          .then(() => this.fetchNotifs())
          .catch(() => {})
          .finally(go);
      } else {
        if (!n.read_endpoint) {
          this.notifSeen = Object.assign({}, this.notifSeen, { [this.notifSig(n)]: Date.now() });
          this.persistNotifSeen();
        }
        go();
      }
    },

    // ---- Cierre común (click-away / Escape) ----------------------------
    onDocClick(e) {
      const outside = ref => {
        const el = this.$refs[ref];
        return !el || !el.contains || !el.contains(e.target);
      };
      if (this.userMenuOpen && outside("userchip")) this.userMenuOpen = false;
      if (this.scopeOpen && outside("scopechip")) this.scopeOpen = false;
      if (this.notifOpen && outside("notif")) this.notifOpen = false;
    },
    onKeydown(e) {
      if (e.key !== "Escape") return;
      this.userMenuOpen = false;
      this.scopeOpen = false;
      this.notifOpen = false;
      if (this.navDrawerOpen) this.closeNavDrawer();
    },

    // ---- Incidencias de transferencias ------------------------------
    // Fuente real (la misma que alimenta el modal del parche global).
    fetchIssues() {
      const ax = window.axios;
      if (!ax) return;
      ax.get("/api/transfer-logistics/issues", {
        baseURL: "",
        meta: { skipErrorRedirect: true, skipInitialLoader: true }
      })
        .then(r => {
          this.issuesAvailable = true;
          this.issuesOpenCount = Number((r && r.data && r.data.open_count) || 0);
        })
        .catch(() => {
          // Sin acceso (403) → no se muestra el control en el shell.
          this.issuesAvailable = false;
        });
    },
    // Abre el modal REAL (Abiertas / Historial / Actualizar) del parche global,
    // disparando su botón (oculto dentro del shell). No reimplementa el modal.
    openIssues() {
      const btn = document.getElementById("px-transfer-issues-btn");
      if (btn) btn.click();
    },

    // ---- Navegación adaptable (drawer tablet/móvil) -----------------
    toggleNavDrawer() {
      if (!this.isCompact) return; // en desktop el riel/panel están inline
      this.navDrawerOpen = !this.navDrawerOpen;
      if (this.navDrawerOpen) {
        this.userMenuOpen = false;
        this.scopeOpen = false;
        this.notifOpen = false;
        this.$nextTick(() => {
          const root = this.$refs.shellNav;
          const f = root && root.querySelector('a[href], button:not([disabled])');
          if (f) f.focus();
        });
      } else if (this.$refs.navToggle) {
        this.$refs.navToggle.focus();
      }
    },
    closeNavDrawer() {
      if (!this.navDrawerOpen) return;
      this.navDrawerOpen = false;
      if (this.$refs.navToggle) this.$refs.navToggle.focus();
    },
    // Focus trap + Escape mientras el drawer está abierto (sólo en compacto).
    onNavKeydown(e) {
      if (e.key === "Escape") {
        this.closeNavDrawer();
        return;
      }
      if (e.key !== "Tab" || !this.isCompact || !this.navDrawerOpen) return;
      const root = this.$refs.shellNav;
      if (!root) return;
      const f = root.querySelectorAll(
        'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'
      );
      if (!f.length) return;
      const first = f[0];
      const last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    },
    onCompactChange(e) {
      this.isCompact = e.matches;
      if (!e.matches) this.navDrawerOpen = false; // al volver a desktop, sin drawer
    },

    // Hidrata scope + notificaciones cuando ya hay usuario identificado.
    initUserScopedState() {
      const uid = this.currentUser && this.currentUser.id;
      if (!uid) return;
      this.$store.dispatch("shellScope/hydrate", uid);
      this.loadNotifSeen();
      this.fetchNotifs();
    }
  },
  watch: {
    "currentUser.id"(id) {
      if (id) this.initUserScopedState();
    },
    // Al navegar a una página real (fuera de /app/shell/<dominio>) se cierra el
    // drawer. Cambiar de dominio dentro del shell lo mantiene abierto para
    // poder elegir después una opción del panel.
    "$route.path"(p) {
      if (!/^\/app\/shell\/(panel|ventas|inventario|compras)\/?$/.test(p || "")) {
        this.navDrawerOpen = false;
      }
    }
  },
  mounted() {
    this.loadUserMeta();
    this.initUserScopedState();
    this.fetchNotifs(); // arranque inmediato aunque el usuario aún no esté hidratado
    this.fetchIssues();
    this.notifTimer = window.setInterval(this.fetchNotifs, 30000);
    this.issuesTimer = window.setInterval(this.fetchIssues, 30000);
    // matchMedia: modo compacto (drawer) en tablet/móvil.
    this._compactMq = window.matchMedia("(max-width: 960px)");
    this.isCompact = this._compactMq.matches;
    if (this._compactMq.addEventListener) this._compactMq.addEventListener("change", this.onCompactChange);
    else if (this._compactMq.addListener) this._compactMq.addListener(this.onCompactChange);
    document.addEventListener("click", this.onDocClick, true);
    document.addEventListener("keydown", this.onKeydown);
  },
  beforeDestroy() {
    if (this.notifTimer) window.clearInterval(this.notifTimer);
    if (this.issuesTimer) window.clearInterval(this.issuesTimer);
    if (this._compactMq) {
      if (this._compactMq.removeEventListener) this._compactMq.removeEventListener("change", this.onCompactChange);
      else if (this._compactMq.removeListener) this._compactMq.removeListener(this.onCompactChange);
    }
    document.removeEventListener("click", this.onDocClick, true);
    document.removeEventListener("keydown", this.onKeydown);
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
  grid-template-columns: auto 1fr;
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
  overflow: visible; /* deja salir el popover del profile chip bajo el topbar */
}

/* Contenedor riel + panel. En desktop es la 1ª columna del grid;
   en tablet/móvil se convierte en drawer (ver @media más abajo). */
.pxn-shell__nav {
  display: flex;
  min-width: 0;
  min-height: 0;
  background: var(--pxn-surface);
}
.pxn-shell__nav > .pxn-shell__rail,
.pxn-shell__nav > .pxn-shell__panel { min-height: 0; }
.pxn-shell__navbackdrop { display: none; }
.pxn-shell__navtoggle { display: none; }

/* riel */
.pxn-shell__rail {
  flex: none;
  width: 56px;
  display: flex; flex-direction: column; align-items: center;
  gap: var(--pxn-space-5);
  padding: var(--pxn-space-5) 0;
  background: var(--pxn-surface);
  border-right: 1px solid var(--pxn-border);
}
.pxn-shell__module-label { display: none; }
.pxn-shell__modules { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; align-items: center; gap: var(--pxn-space-2); flex: 1; }
.pxn-shell__rail-foot { display: flex; flex-direction: column; align-items: center; gap: var(--pxn-space-2); }
.pxn-shell__brand {
  width: 44px; height: 44px;
  border-radius: var(--pxn-radius-md);
  display: flex; align-items: center; justify-content: center;
  padding: 3px;
  background: var(--pxn-surface);
  border: 1px solid transparent;
  text-decoration: none;
  transition: border-color var(--pxn-dur-1) var(--pxn-ease), background var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-shell__brand:hover { text-decoration: none; border-color: var(--pxn-border); background: var(--pxn-surface-hover); }
.pxn-shell__brand:focus-visible { border-color: var(--pxn-border-strong); }
.pxn-shell__brand-logo {
  display: block;
  max-width: 100%; max-height: 100%;
  width: auto; height: auto;
  object-fit: contain;
}
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
  display: flex;
  align-items: center;
  gap: var(--pxn-space-5);
  height: 56px;
  padding-right: var(--pxn-space-6);
  border-bottom: 1px solid var(--pxn-border);
  background: var(--pxn-surface);
}

/* El <top-nav /> real trae su propio .main-header; lo encajamos en la rejilla. */
.pxn-shell__topbar ::v-deep .main-header {
  position: static;
  flex: 1;
  min-width: 0;
  height: 100%;
  box-shadow: none;
  margin: 0;
}

/* ---- Bloques redundantes/duplicados que se ocultan DENTRO del shell -------
   (sin tocar TopNav global ni los scripts globales):
   · .logo / .menu-toggle  → el logo vive en el rail; el hamburger no aplica
   · #user-dd              → profile chip px-next
   · #notif-dd             → campana px-next
   · .px-operational-context → selector de alcance px-next
   · #px-transfer-issues-btn → control de incidencias px-next               */
.pxn-shell__topbar ::v-deep .main-header > .logo,
.pxn-shell__topbar ::v-deep .main-header > .menu-toggle,
.pxn-shell__topbar ::v-deep #user-dd,
.pxn-shell__topbar ::v-deep #notif-dd,
.pxn-shell__topbar ::v-deep .px-operational-context,
.pxn-shell__topbar ::v-deep #px-transfer-issues-btn { display: none !important; }

/* El topbar comienza justo después del rail: el contenido real de TopNav
   (nav-right) se alinea a la izquierda del cluster px-next. */
.pxn-shell__topbar ::v-deep .main-header .header-part-right.nav-right {
  gap: var(--pxn-space-3);
}

/* ---- Jerarquía 1: POS = acción primaria --------------------------------- */
.pxn-shell__topbar ::v-deep .main-header .nav-right .btn.btn-primary {
  height: 36px;
  padding: 0 var(--pxn-space-5);
  border-radius: var(--pxn-radius-pill);
  background: var(--pxn-primary);
  border: 1px solid var(--pxn-primary);
  color: var(--pxn-primary-contrast);
  font-weight: var(--pxn-fw-semibold);
  box-shadow: none;
}
.pxn-shell__topbar ::v-deep .main-header .nav-right .btn.btn-primary:hover,
.pxn-shell__topbar ::v-deep .main-header .nav-right .btn.btn-primary:focus,
.pxn-shell__topbar ::v-deep .main-header .nav-right .btn.btn-primary:active {
  background: var(--pxn-primary-hover) !important;
  border-color: var(--pxn-primary-hover) !important;
  color: var(--pxn-primary-contrast) !important;
}
.pxn-shell__topbar ::v-deep .main-header .nav-right .btn.btn-primary svg { color: currentColor; }

/* ---- Jerarquía 5: Existencias = consulta secundaria -------------------- */
.pxn-shell__topbar ::v-deep #px-stock-visibility-nav {
  height: 36px; margin: 0;
  border-radius: var(--pxn-radius-md);
  border: 1px solid var(--pxn-border);
  background: var(--pxn-surface);
  color: var(--pxn-ink-2);
  font-weight: var(--pxn-fw-medium);
}
.pxn-shell__topbar ::v-deep #px-stock-visibility-nav:hover {
  border-color: var(--pxn-border-strong);
  background: var(--pxn-surface-hover);
  color: var(--pxn-ink);
}

/* ---- Jerarquía 6: utilidades (tema · pantalla completa · idioma) ------
   agrupadas y visualmente secundarias, sin bordes individuales pesados. */
.pxn-shell__topbar ::v-deep .main-header .nav-right .nav-icon-btn,
.pxn-shell__topbar ::v-deep .main-header #lang-dd .dropdown-toggle-no-caret {
  width: 34px !important; height: 34px !important;
  border: 0 !important;
  background: transparent !important;
  color: var(--pxn-ink-3) !important;
  border-radius: var(--pxn-radius-md) !important;
  box-shadow: none !important;
}
.pxn-shell__topbar ::v-deep .main-header .nav-right .nav-icon-btn:hover,
.pxn-shell__topbar ::v-deep .main-header #lang-dd .dropdown-toggle-no-caret:hover {
  background: var(--pxn-surface-2) !important;
  color: var(--pxn-ink-2) !important;
  border-color: transparent !important;
}
/* Encierra el trío de utilidades en un contenedor sutil. */
.pxn-shell__topbar ::v-deep .main-header .nav-right {
  align-items: center;
}

.pxn-shell__canvas { flex: 1; min-height: 0; overflow: auto; background: var(--pxn-bg); }

/* -------------------------------------------------------------------------
   Cluster px-next del topbar (a la derecha del contenido de TopNav).
   ------------------------------------------------------------------------- */
.pxn-topbar__rhs {
  flex: none;
  display: flex;
  align-items: center;
  gap: var(--pxn-space-5);
}
.pxn-topbar__attn {
  display: flex;
  align-items: center;
  gap: var(--pxn-space-3);
}

/* -------------------------------------------------------------------------
   Incidencias de transferencias — neutro si 0 abiertas, warning si > 0.
   Abre el modal REAL del parche global.
   ------------------------------------------------------------------------- */
.pxn-issues {
  position: relative;
  width: 36px; height: 36px;
  display: inline-flex; align-items: center; justify-content: center;
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
  color: var(--pxn-ink-3);
  cursor: pointer;
  transition: border-color var(--pxn-dur-1) var(--pxn-ease), background var(--pxn-dur-1) var(--pxn-ease), color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-issues:hover { background: var(--pxn-surface-hover); border-color: var(--pxn-border-strong); color: var(--pxn-ink-2); }
/* Estado real: hay incidencias abiertas → semántica de warning. */
.pxn-issues.is-warn {
  border-color: var(--pxn-warning-border);
  background: var(--pxn-warning-soft);
  color: var(--pxn-warning-ink);
}
.pxn-issues.is-warn:hover {
  border-color: var(--pxn-warning);
  background: var(--pxn-warning-soft);
  color: var(--pxn-warning-ink);
}
.pxn-issues__badge {
  position: absolute; top: -5px; right: -5px;
  min-width: 16px; height: 16px; padding: 0 4px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: var(--pxn-radius-pill);
  background: var(--pxn-warning); color: #fff;
  font-size: 10px; font-weight: var(--pxn-fw-bold); line-height: 1;
}

/* -------------------------------------------------------------------------
   Profile chip — bloque de perfil px-next (reemplaza el avatar-botón).
   Fondo blanco, radio, hairline + sombra sutil sólo al abrir/hover.
   ------------------------------------------------------------------------- */
.pxn-userchip { position: relative; flex: none; }
/* Composición desktop:  [ NOMBRE      (avatar) ]
                          [ Rol                 ]
   Menos "botón": radio suave, hairline muy sutil, sombra apenas perceptible. */
.pxn-userchip__btn {
  display: flex;
  align-items: center;
  gap: var(--pxn-space-4);
  max-width: 260px;
  padding: var(--pxn-space-1) var(--pxn-space-3) var(--pxn-space-1) var(--pxn-space-5);
  border: 1px solid transparent;
  border-radius: var(--pxn-radius-md);
  background: transparent;
  color: var(--pxn-ink);
  font: inherit;
  cursor: pointer;
  transition: border-color var(--pxn-dur-1) var(--pxn-ease),
    background var(--pxn-dur-1) var(--pxn-ease), box-shadow var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-userchip__btn:hover {
  background: var(--pxn-surface);
  border-color: var(--pxn-border);
  box-shadow: var(--pxn-shadow-card-hover);
}
.pxn-userchip.is-open .pxn-userchip__btn {
  background: var(--pxn-surface);
  border-color: var(--pxn-border);
  box-shadow: var(--pxn-shadow-card-hover);
}

.pxn-userchip__avatar {
  flex: none;
  width: 36px; height: 36px;
  border-radius: var(--pxn-radius-pill);
  overflow: hidden;
  display: inline-flex; align-items: center; justify-content: center;
  background: var(--pxn-primary-soft);
  color: var(--pxn-primary-ink);
  font-size: var(--pxn-fs-sm);
  font-weight: var(--pxn-fw-semibold);
  letter-spacing: 0.01em;
}
.pxn-userchip__avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pxn-userchip__avatar--lg { width: 42px; height: 42px; }
.pxn-userchip__initials { line-height: 1; }

.pxn-userchip__meta {
  display: flex; flex-direction: column; align-items: flex-start;
  min-width: 0; line-height: 1.25;
}
.pxn-userchip__btn .pxn-userchip__meta { align-items: flex-end; text-align: right; }
.pxn-userchip__name {
  max-width: 160px;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  font-size: var(--pxn-fs-sm);
  font-weight: var(--pxn-fw-semibold);
  color: var(--pxn-ink);
}
.pxn-userchip__role {
  max-width: 160px;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  font-size: var(--pxn-fs-xs);
  color: var(--pxn-ink-3);
}
.pxn-userchip__caret {
  flex: none; margin-left: calc(var(--pxn-space-3) * -1);
  color: var(--pxn-ink-disabled);
  transition: transform var(--pxn-dur-1) var(--pxn-ease), color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-userchip.is-open .pxn-userchip__caret { transform: rotate(180deg); color: var(--pxn-ink-3); }

/* Popover */
.pxn-userchip__menu {
  position: absolute;
  top: calc(100% + var(--pxn-space-3));
  right: 0;
  min-width: 236px;
  padding: var(--pxn-space-3);
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-lg);
  box-shadow: var(--pxn-shadow-menu);
  z-index: var(--pxn-z-menu);
}
.pxn-userchip__menu-head {
  display: flex; align-items: center; gap: var(--pxn-space-4);
  padding: var(--pxn-space-4) var(--pxn-space-4) var(--pxn-space-5);
}
.pxn-userchip__menu-sep { height: 1px; margin: var(--pxn-space-2) 0; background: var(--pxn-border); }
.pxn-userchip__item {
  display: flex; align-items: center; gap: var(--pxn-space-4);
  width: 100%;
  padding: var(--pxn-space-4);
  border: 0; border-radius: var(--pxn-radius-sm);
  background: transparent;
  font: inherit;
  font-size: var(--pxn-fs-sm);
  color: var(--pxn-ink-2);
  text-align: left;
  text-decoration: none;
  cursor: pointer;
  transition: background var(--pxn-dur-1) var(--pxn-ease), color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-userchip__item:hover { background: var(--pxn-surface-2); color: var(--pxn-ink); text-decoration: none; }
.pxn-userchip__item > svg { flex: none; color: var(--pxn-ink-3); }
.pxn-userchip__item:hover > svg { color: var(--pxn-ink-2); }
.pxn-userchip__item--danger { color: var(--pxn-danger-ink); }
.pxn-userchip__item--danger > svg { color: var(--pxn-danger); }
.pxn-userchip__item--danger:hover { background: var(--pxn-danger-soft); color: var(--pxn-danger-ink); }
.pxn-userchip__item--danger:hover > svg { color: var(--pxn-danger); }

/* -------------------------------------------------------------------------
   Selector de alcance de sucursal (view scope) — pill px-next compacta.
   ------------------------------------------------------------------------- */
.pxn-scopechip { position: relative; flex: none; }
.pxn-scopechip__btn {
  display: inline-flex; align-items: center; gap: var(--pxn-space-3);
  max-width: 220px;
  height: 34px; padding: 0 var(--pxn-space-4);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-pill);
  background: var(--pxn-surface);
  color: var(--pxn-ink-2);
  font: inherit; font-size: var(--pxn-fs-sm);
  cursor: pointer;
  transition: border-color var(--pxn-dur-1) var(--pxn-ease), background var(--pxn-dur-1) var(--pxn-ease), color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-scopechip__btn:hover { background: var(--pxn-surface-hover); border-color: var(--pxn-border-strong); color: var(--pxn-ink); }
.pxn-scopechip.is-open .pxn-scopechip__btn { border-color: var(--pxn-primary-border); color: var(--pxn-ink); }
.pxn-scopechip__lead { flex: none; color: var(--pxn-ink-3); }
.pxn-scopechip__label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: var(--pxn-fw-medium); }
.pxn-scopechip__caret { flex: none; color: var(--pxn-ink-3); transition: transform var(--pxn-dur-1) var(--pxn-ease); }
.pxn-scopechip.is-open .pxn-scopechip__caret { transform: rotate(180deg); }

.pxn-scopechip__menu {
  position: absolute;
  top: calc(100% + var(--pxn-space-3));
  right: 0;
  min-width: 232px; max-width: 300px;
  padding: var(--pxn-space-2);
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-lg);
  box-shadow: var(--pxn-shadow-menu);
  z-index: var(--pxn-z-menu);
}
.pxn-scopechip__opt {
  display: flex; align-items: center; gap: var(--pxn-space-3);
  width: 100%;
  padding: var(--pxn-space-3) var(--pxn-space-4);
  border: 0; border-radius: var(--pxn-radius-sm);
  background: transparent;
  font: inherit; font-size: var(--pxn-fs-sm);
  color: var(--pxn-ink-2);
  text-align: left; cursor: pointer;
  transition: background var(--pxn-dur-1) var(--pxn-ease), color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-scopechip__opt:hover { background: var(--pxn-surface-2); color: var(--pxn-ink); }
.pxn-scopechip__opt.is-active { color: var(--pxn-primary-ink); font-weight: var(--pxn-fw-medium); }
.pxn-scopechip__check { flex: none; color: var(--pxn-primary); }
.pxn-scopechip__check-sp { flex: none; width: 14px; height: 14px; }
.pxn-scopechip__opt > span:last-child { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* -------------------------------------------------------------------------
   Campana px-next — badge = notificaciones no leídas.
   ------------------------------------------------------------------------- */
.pxn-notif { position: relative; flex: none; }
.pxn-notif__btn {
  position: relative;
  width: 36px; height: 36px;
  display: inline-flex; align-items: center; justify-content: center;
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
  color: var(--pxn-ink-3);
  cursor: pointer;
  transition: border-color var(--pxn-dur-1) var(--pxn-ease), background var(--pxn-dur-1) var(--pxn-ease), color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-notif__btn:hover { background: var(--pxn-surface-hover); border-color: var(--pxn-border-strong); color: var(--pxn-ink-2); }
.pxn-notif.is-open .pxn-notif__btn { border-color: var(--pxn-primary-border); color: var(--pxn-ink-2); }
.pxn-notif__badge {
  position: absolute; top: -5px; right: -5px;
  min-width: 16px; height: 16px; padding: 0 4px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: var(--pxn-radius-pill);
  background: var(--pxn-danger); color: #fff;
  font-size: 10px; font-weight: var(--pxn-fw-bold); line-height: 1;
}
.pxn-notif__menu {
  position: absolute;
  top: calc(100% + var(--pxn-space-3));
  right: 0;
  width: 340px; max-width: calc(100vw - 24px);
  background: var(--pxn-surface);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-lg);
  box-shadow: var(--pxn-shadow-menu);
  z-index: var(--pxn-z-menu);
  overflow: hidden;
}
.pxn-notif__head {
  display: flex; align-items: center; justify-content: space-between;
  padding: var(--pxn-space-5) var(--pxn-space-5) var(--pxn-space-4);
  font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink);
  border-bottom: 1px solid var(--pxn-border);
}
.pxn-notif__count { font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink-3); }
.pxn-notif__count--clear { color: var(--pxn-success-ink); }
.pxn-notif__empty { padding: var(--pxn-space-8) var(--pxn-space-5); text-align: center; font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxn-notif__list { list-style: none; margin: 0; padding: var(--pxn-space-2); max-height: min(420px, 64vh); overflow-y: auto; }
.pxn-notif__item {
  display: flex; align-items: flex-start; gap: var(--pxn-space-3);
  width: 100%;
  padding: var(--pxn-space-4);
  border: 0; border-radius: var(--pxn-radius-sm);
  background: transparent;
  font: inherit; text-align: left; cursor: pointer;
  transition: background var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-notif__item:hover { background: var(--pxn-surface-2); }
.pxn-notif__dot {
  flex: none; width: 7px; height: 7px; margin-top: 5px;
  border-radius: var(--pxn-radius-pill);
  background: var(--pxn-border-strong);
}
.pxn-notif__item.is-unread .pxn-notif__dot { background: var(--pxn-primary); }
.pxn-notif__text { min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.pxn-notif__text strong {
  font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink-2);
}
.pxn-notif__item.is-unread .pxn-notif__text strong { color: var(--pxn-ink); font-weight: var(--pxn-fw-semibold); }
.pxn-notif__text span { font-size: var(--pxn-fs-xs); line-height: var(--pxn-lh-snug); color: var(--pxn-ink-3); }

/* -------------------------------------------------------------------------
   Botón de navegación (tablet/móvil). Oculto en desktop.
   ------------------------------------------------------------------------- */
.pxn-shell__navtoggle {
  align-items: center;
  gap: var(--pxn-space-3);
  height: 36px;
  padding: 0 var(--pxn-space-4) 0 var(--pxn-space-3);
  border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
  color: var(--pxn-ink-2);
  font: inherit; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium);
  cursor: pointer;
  flex: none;
  transition: border-color var(--pxn-dur-1) var(--pxn-ease), background var(--pxn-dur-1) var(--pxn-ease), color var(--pxn-dur-1) var(--pxn-ease);
}
.pxn-shell__navtoggle:hover { border-color: var(--pxn-border-strong); background: var(--pxn-surface-hover); color: var(--pxn-ink); }
.pxn-shell__navtoggle-label { max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* Chip de usuario colapsa a avatar y selector de sucursal a icono. */
@media (max-width: 1100px) {
  .pxn-userchip__btn { max-width: none; padding: var(--pxn-space-2); gap: 0; }
  .pxn-userchip__meta,
  .pxn-userchip__caret { display: none; }
  .pxn-scopechip__btn { max-width: none; padding: 0; width: 34px; justify-content: center; }
  .pxn-scopechip__label,
  .pxn-scopechip__caret { display: none; }
  .pxn-topbar__rhs { gap: var(--pxn-space-4); }
}

/* =======================================================================
   TABLET / MÓVIL (<= 960px): riel + panel viven en un DRAWER accesible por
   el botón de navegación del topbar. No depende de hover. Navegación táctil
   completa, focus trap, Escape y click-away (backdrop).
   ======================================================================= */
@media (max-width: 960px) {
  .pxn-shell--live { grid-template-columns: 1fr; }

  .pxn-shell__navtoggle { display: inline-flex; }

  .pxn-shell__nav.is-drawer {
    position: fixed; top: 0; left: 0; bottom: 0;
    width: 288px; max-width: 86vw;
    flex-direction: column;
    z-index: var(--pxn-z-modal);
    box-shadow: var(--pxn-shadow-modal);
    overflow-y: auto;
    visibility: hidden;
    transform: translateX(-100%);
    transition: transform var(--pxn-dur-2) var(--pxn-ease), visibility 0s var(--pxn-dur-2);
  }
  .pxn-shell.is-navdrawer-open .pxn-shell__nav.is-drawer {
    visibility: visible;
    transform: translateX(0);
    transition: transform var(--pxn-dur-2) var(--pxn-ease), visibility 0s;
  }

  .pxn-shell__navbackdrop {
    display: block;
    position: fixed; inset: 0;
    z-index: calc(var(--pxn-z-modal) - 1);
    background: rgba(16, 24, 40, 0.44);
    opacity: 0; visibility: hidden;
    transition: opacity var(--pxn-dur-2) var(--pxn-ease), visibility 0s var(--pxn-dur-2);
  }
  .pxn-shell.is-navdrawer-open .pxn-shell__navbackdrop {
    opacity: 1; visibility: visible;
    transition: opacity var(--pxn-dur-2) var(--pxn-ease), visibility 0s;
  }
  .pxn-shell.is-navdrawer-open .pxn-shell__canvas { overflow: hidden; }

  /* Riel: filas etiquetadas, ancho completo, targets táctiles cómodos. */
  .pxn-shell__rail {
    flex-direction: column; align-items: stretch;
    width: 100%; flex: none;
    gap: var(--pxn-space-2);
    padding: var(--pxn-space-5) var(--pxn-space-4);
    border-right: 0;
    border-bottom: 1px solid var(--pxn-border);
  }
  .pxn-shell__brand { align-self: flex-start; margin-bottom: var(--pxn-space-3); }
  .pxn-shell__modules { flex: none; width: 100%; align-items: stretch; }
  .pxn-shell__rail-foot { align-items: stretch; width: 100%; margin-top: var(--pxn-space-3); }
  .pxn-shell__module {
    width: 100%; height: 44px;
    justify-content: flex-start;
    gap: var(--pxn-space-4);
    padding: 0 var(--pxn-space-4);
  }
  .pxn-shell__module-label {
    display: inline;
    font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium);
    letter-spacing: -0.01em;
  }
  .pxn-shell__module.is-active::before { left: 0; border-radius: 0 3px 3px 0; }

  /* Panel: ancho completo bajo el riel, con su propio scroll. */
  .pxn-shell__panel {
    width: 100%; flex: 1 1 auto;
    border-right: 0;
    padding: var(--pxn-space-5) var(--pxn-space-4);
  }
  .pxn-shell__panel-link { min-height: 40px; }
}

@media (max-width: 560px) {
  .pxn-shell__topbar { gap: var(--pxn-space-3); padding-right: var(--pxn-space-4); }
  .pxn-shell__navtoggle { padding: 0 var(--pxn-space-3); }
  .pxn-shell__navtoggle-label { display: none; }
  .pxn-topbar__rhs { gap: var(--pxn-space-3); }
  .pxn-topbar__attn { gap: var(--pxn-space-2); }
  .pxn-shell__topbar ::v-deep .main-header .header-part-right.nav-right { gap: var(--pxn-space-2); }
  .pxn-notif__menu { position: fixed; top: 58px; right: 12px; left: 12px; width: auto; max-width: none; }
}

@media (prefers-reduced-motion: reduce) {
  .pxn-shell__brand,
  .pxn-shell__navtoggle,
  .pxn-shell__nav.is-drawer,
  .pxn-shell__navbackdrop,
  .pxn-userchip__btn,
  .pxn-userchip__caret,
  .pxn-userchip__item,
  .pxn-scopechip__btn,
  .pxn-scopechip__caret,
  .pxn-scopechip__opt,
  .pxn-notif__btn,
  .pxn-notif__item,
  .pxn-issues { transition: none; }
}
</style>
