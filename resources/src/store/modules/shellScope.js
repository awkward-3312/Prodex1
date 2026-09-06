// =============================================================================
// shellScope — alcance de VISUALIZACIÓN del shell px-next (view/analytics scope).
// -----------------------------------------------------------------------------
// Guarda la sucursal seleccionada en el topbar del shell y la comparte con las
// páginas del shell (hoy: el Panel). Es SÓLO un filtro de lectura: se traduce a
// `branch_id` en las consultas del dashboard. NO altera contexto operativo,
// permisos, default warehouse, ubicación de movimientos, caja ni POS.
//
// Persistencia: Vuex (durante la navegación) + localStorage por usuario (al
// recargar). Si la sucursal guardada deja de ser válida para el usuario, se
// vuelve de forma segura a "Todas".
// =============================================================================

const LS_KEY = "pxn_shell_scope";

function readLS() {
  try {
    return JSON.parse(window.localStorage.getItem(LS_KEY) || "null") || null;
  } catch (e) {
    return null;
  }
}

function writeLS(userId, branchId) {
  try {
    window.localStorage.setItem(
      LS_KEY,
      JSON.stringify({ userId: String(userId || ""), branchId: Number(branchId) || 0 })
    );
  } catch (e) {
    /* almacenamiento no disponible: el scope vive sólo en memoria esta sesión */
  }
}

export default {
  namespaced: true,
  state: {
    branchId: 0,        // 0 = alcance completo permitido (todas las sucursales del usuario)
    branches: [],       // [{ id, name }] — autoridad: respuesta de dashboard_data
    isOwner: false,     // dashboard_data → scope.is_owner. Sólo afecta el texto del selector.
    hydrated: false
  },
  getters: {
    shellBranchId: s => s.branchId,
    shellBranches: s => s.branches,
    shellIsOwner: s => s.isOwner,
    // Etiqueta "todas": del negocio para el propietario, "mis sucursales" para el resto.
    shellAllScopeLabel: s => (s.isOwner ? "Todas las sucursales" : "Todas mis sucursales"),
    shellScopeLabel: (s, g) => {
      // Una sola sucursal en el alcance: no hay nada que elegir → su nombre.
      if (s.branches.length === 1) return s.branches[0].name;
      if (!s.branchId) return g.shellAllScopeLabel;
      const b = s.branches.find(x => Number(x.id) === Number(s.branchId));
      return b ? b.name : g.shellAllScopeLabel;
    }
  },
  mutations: {
    setBranchId(s, id) {
      s.branchId = Number(id) || 0;
    },
    setBranches(s, list) {
      s.branches = Array.isArray(list) ? list.map(b => ({ id: Number(b.id), name: String(b.name) })) : [];
    },
    setIsOwner(s, v) {
      s.isOwner = !!v;
    },
    setHydrated(s, v) {
      s.hydrated = !!v;
    }
  },
  actions: {
    // Restaura la última selección del usuario al arrancar el shell.
    hydrate({ commit, state }, userId) {
      if (state.hydrated || !userId) return;
      const saved = readLS();
      if (saved && String(saved.userId) === String(userId) && saved.branchId) {
        commit("setBranchId", saved.branchId);
      }
      commit("setHydrated", true);
    },

    // Publica el catálogo de sucursales visible (viene de dashboard_data) y
    // revalida la selección actual contra él.
    syncBranches({ commit, state }, { branches, isOwner, userId }) {
      commit("setBranches", branches);
      if (isOwner !== undefined) commit("setIsOwner", isOwner);
      const ids = state.branches.map(b => Number(b.id));
      if (state.branchId && ids.indexOf(state.branchId) === -1) {
        // perdió permiso / dejó de existir → alcance permitido seguro
        commit("setBranchId", 0);
        writeLS(userId, 0);
      }
    },

    selectBranch({ commit }, { branchId, userId }) {
      const id = Number(branchId) || 0;
      commit("setBranchId", id);
      writeLS(userId, id);
    }
  }
};
