import { createI18n } from 'vue-i18n';
import axios from 'axios';
import { supportMessages } from './support.i18n';
import { bundledUiMessages, readableMissingTranslation } from './ui.fallback.i18n';
import { installSpanishUiGuard } from '../utils/spanishUiGuard';
import { installSpanishDocumentTitleGuard } from '../utils/spanishDocumentTitleGuard';
import { installSpanishApiFeedbackGuard } from '../utils/spanishApiFeedbackGuard';
import { installSpanishSettingsRequestGuard } from '../utils/spanishSettingsRequestGuard';

// vue-i18n 9+ ya no se instala con el patrón `Vue.use` + constructor de clase de la 8 (componentes de Vue 2):
// se crea con `createI18n({...})` y se instala en la app real de Vue 3 con `app.use(i18n)` (ver
// `mountWithRouter` en `platform/compat/vue-router.js`, que ya acepta plugins de app además del router). `legacy:
// true` mantiene el mixin global que expone `$t`/`$tc`/`$te`/`$d`/`$n`/`$i18n` en cada instancia — el mismo
// contrato observable que la 8, sin tocar los ~13.300 `$t(...)` existentes en las vistas.

function permanentlyDisableLegacyDomTranslators() {
  if (typeof window === 'undefined') return;

  // Some older code still tries to toggle this flag when components mount or
  // unmount. Make the flag read-only and permanently true so those legacy
  // observers can never be re-enabled later in the SPA lifecycle.
  try {
    Object.defineProperty(window, '__prodexSuspendLegacyUiTranslations', {
      configurable: false,
      enumerable: false,
      get() { return true; },
      set() {},
    });
  } catch (e) {
    window.__prodexSuspendLegacyUiTranslations = true;
  }

  // If any legacy observers were installed by an older bundle before this
  // loader ran, disconnect the known instances defensively. The current build
  // no longer installs these observers, but this makes navigation/reload races
  // safe during deployments and cache transitions.
  [
    '__prodexSpanishSettingsUiObserver',
    '__prodexSpanishLegacyDocumentObserver',
    '__prodexSpanishCommerceIntegrationObserver',
    '__prodexSpanishPermissionsObserver',
  ].forEach(key => {
    const observer = window[key];
    if (observer && typeof observer.disconnect === 'function') {
      try { observer.disconnect(); } catch (e) {}
    }
    try { window[key] = null; } catch (e) {}
  });
}

export const loadI18n = async () => {
  const userLang = localStorage.getItem('language') || 'es';
  const useSpanishUiGuards = String(userLang).toLowerCase().startsWith('es');

  // Global DOM rewriting is permanently disabled. PRODEX translations must be
  // rendered by Vue i18n/source strings, never by whole-document observers that
  // mutate text nodes after Vue renders them.
  permanentlyDisableLegacyDomTranslators();

  // The request guard is language-safe: it only replaces an invalid/empty
  // default_language value with "es" and preserves any explicit selection.
  installSpanishSettingsRequestGuard();

  // Keep only compatibility helpers that do not observe and rewrite the whole
  // application DOM. The document-title observer is isolated to <title> only.
  if (useSpanishUiGuards) {
    installSpanishUiGuard();
    installSpanishDocumentTitleGuard();
    installSpanishApiFeedbackGuard();
  }

  let dbMessages = {};
  try {
    const isBaseURLSet = axios.defaults.baseURL && axios.defaults.baseURL !== '/';
    const endpoint = isBaseURLSet
      ? `translations/${userLang}`
      : `/api/translations/${userLang}`;

    const response = await axios.get(endpoint);
    dbMessages = response.data;
  } catch (error) {
    console.warn("No se pudieron cargar las traducciones desde la base de datos.");
  }

  const messages = {
    [userLang]: Object.assign(
      {},
      supportMessages(userLang),
      bundledUiMessages(userLang),
      dbMessages || {}
    )
  };

  const i18n = createI18n({
    legacy: true,
    locale: userLang,
    fallbackLocale: 'es',
    messages,
    silentTranslationWarn: true,
    silentFallbackWarn: true,
    missing: (locale, key) => readableMissingTranslation(locale, key),
  });

  // Red de seguridad: el mixin de `legacy: true` resuelve `this.$t`/`$tc`/`$te`/`$d`/`$n` mientras el componente
  // está vivo, igual que la 8 — pero la 8 los daba por `Vue.prototype`, un método permanente, independiente del
  // ciclo de vida de CUALQUIER instancia; la 9+ los resuelve por instancia. Un `.then()`/`.catch()` de una llamada
  // async iniciada en `created()`/`mounted()` que resuelve DESPUÉS de que el componente se desmontó (p. ej. una
  // pestaña con `v-if` que el usuario ya cerró) encuentra `this.$t` ya no disponible por esa vía bajo la 9+, algo
  // que la 8 nunca exponía como fallo (visto en `woocommerce/SettingsTab.vue`: `axios...then(...this.$t(...))`
  // corriendo tras desmontar bajo carga). Se registran también en `app.config.globalProperties` (equivalente de la
  // 3 a `Vue.prototype`): Vue resuelve `this.$t` ahí SOLO si el mixin no lo dejó ya resuelto, así que no cambia el
  // comportamiento normal, solo cubre ese caso límite exacto sin tocar cada callback async de la aplicación.
  const originalInstall = i18n.install.bind(i18n);
  i18n.install = (app, ...args) => {
    originalInstall(app, ...args);
    app.config.globalProperties.$t = (...a) => i18n.global.t(...a);
    app.config.globalProperties.$tc = (...a) => i18n.global.tc(...a);
    app.config.globalProperties.$te = (...a) => i18n.global.te(...a);
    app.config.globalProperties.$d = (...a) => i18n.global.d(...a);
    app.config.globalProperties.$n = (...a) => i18n.global.n(...a);
  };

  // Compat: código existente (router.js, store/modules/auth.js) lee/escribe `i18n.locale`, `i18n.getLocaleMessage`,
  // `i18n.setLocaleMessage` directamente sobre la instancia devuelta por `loadI18n()` — el patrón de vue-i18n 8. En
  // la 9+ esas APIs viven en `i18n.global` (`i18n` en sí solo expone `install`/`global`/`mode`). En vez de tocar
  // cada call site, se exponen como passthrough delgado hacia `i18n.global`, así el contrato de fuera se mantiene
  // exacto.
  Object.defineProperty(i18n, 'locale', {
    get: () => i18n.global.locale,
    set: (value) => { i18n.global.locale = value; },
  });
  ['t', 'te', 'tc', 'd', 'n', 'getLocaleMessage', 'setLocaleMessage', 'mergeLocaleMessage'].forEach((method) => {
    i18n[method] = (...args) => i18n.global[method](...args);
  });

  return i18n;
};