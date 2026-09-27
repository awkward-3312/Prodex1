/**
 * Puente explícito para los scripts sueltos de `resources/static/prodex-*.js` (no son módulos y no pueden importar nada).
 *
 * Hasta ahora esos scripts obtenían el router, los permisos y las funciones del plan leyendo la instancia interna de Vue
 * que Vue 2 cuelga de los elementos del DOM. Eso no existe en Vue 3, y bajo `@vue/compat` fallaba en silencio. Este puente
 * los sustituye por funciones registradas de forma explícita: la app las publica una vez al arrancar en
 * `window.__prodexBridge` y los scripts las llaman.
 *
 * Es un adaptador TEMPORAL: se elimina cuando esos scripts se sustituyan por código de la app.
 */

const noop = () => {};

export function createLegacyBridge({ navigate, getPermissions, getPlanSummary } = {}) {
  let editor = null;

  return {
    /** Navega dentro de la SPA; devuelve una promesa que nunca rechaza (como `router.push(...).catch(noop)`). */
    navigate(path) {
      if (typeof navigate !== 'function') return Promise.resolve();
      try {
        return Promise.resolve(navigate(path)).catch(noop);
      } catch (error) {
        return Promise.resolve();
      }
    },

    /** Permisos del usuario actual (array de nombres; vacío si aún no cargaron). */
    getPermissions() {
      const list = typeof getPermissions === 'function' ? getPermissions() : null;
      return Array.isArray(list) ? list : [];
    },

    /** ¿Está habilitada la función del plan? Misma regla que el menú: sin plan o sin la clave => habilitada. */
    planFeature(key) {
      const summary = typeof getPlanSummary === 'function' ? getPlanSummary() : null;
      const features = summary && summary.has_plan && summary.features ? summary.features : {};
      const feature = features[key];
      return feature ? !!feature.enabled : true;
    },

    /** Devuelve el editor de permisos registrado (o null): `{ get(): string[], set(next: string[]) }`. */
    permissionEditor() {
      return editor;
    },

    /** Registra el editor de permisos que renderiza el formulario de roles. Devuelve la función que lo retira. */
    registerPermissionEditor(next) {
      editor = next;
      return () => {
        if (editor === next) editor = null;
      };
    },
  };
}

/** Publica el puente en `window.__prodexBridge`. Devuelve la función que lo retira. */
export function installLegacyBridge(options, target = typeof window !== 'undefined' ? window : undefined) {
  const bridge = createLegacyBridge(options);
  if (target) target.__prodexBridge = bridge;
  return () => {
    if (target && target.__prodexBridge === bridge) delete target.__prodexBridge;
  };
}
