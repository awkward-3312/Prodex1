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

export const loadI18n = async () => {
  const userLang = localStorage.getItem('language') || 'es';
  const useSpanishUiGuards = String(userLang).toLowerCase().startsWith('es');

  // Never allow legacy whole-document DOM translators to run. Several older
  // compatibility guards used MutationObserver on document.body/documentElement
  // and then rewrote text nodes/attributes in response to Vue DOM mutations.
  // On dynamic screens this can create observer feedback storms and monopolize
  // the browser main thread. PRODEX translations must come from Vue i18n/source
  // strings instead of mutating rendered DOM globally.
  if (typeof window !== 'undefined') {
    window.__prodexSuspendLegacyUiTranslations = true;
  }

  // The request guard is language-safe: it only replaces an invalid/empty
  // default_language value with "es" and preserves any explicit selection.
  installSpanishSettingsRequestGuard();

  // Keep only non-global compatibility helpers. The former settings, legacy
  // document, commerce and permissions DOM observers are intentionally not
  // installed because they observe the entire application and mutate DOM from
  // inside MutationObserver callbacks.
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