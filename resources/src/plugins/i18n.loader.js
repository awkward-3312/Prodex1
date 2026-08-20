import Vue from 'vue';
import VueI18n from 'vue-i18n';
import axios from 'axios';
import { supportMessages } from './support.i18n';
import { bundledUiMessages, readableMissingTranslation } from './ui.fallback.i18n';
import { installSpanishUiGuard } from '../utils/spanishUiGuard';
import { installSpanishDocumentTitleGuard } from '../utils/spanishDocumentTitleGuard';
import { installSpanishApiFeedbackGuard } from '../utils/spanishApiFeedbackGuard';
import { installSpanishSettingsRequestGuard } from '../utils/spanishSettingsRequestGuard';

Vue.use(VueI18n);

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

  const i18n = new VueI18n({
    locale: userLang,
    fallbackLocale: 'es',
    messages,
    silentTranslationWarn: true,
    missing: (locale, key) => readableMissingTranslation(locale, key),
  });

  return i18n;
};