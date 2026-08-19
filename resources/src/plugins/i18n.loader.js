import Vue from 'vue';
import VueI18n from 'vue-i18n';
import axios from 'axios';
import { supportMessages } from './support.i18n';
import { bundledUiMessages, readableMissingTranslation } from './ui.fallback.i18n';
import { installSpanishUiGuard } from '../utils/spanishUiGuard';
import { installSpanishSettingsUiGuard } from '../utils/spanishSettingsUiGuard';
import { installSpanishDocumentTitleGuard } from '../utils/spanishDocumentTitleGuard';
import { installSpanishLegacyDocumentGuard } from '../utils/spanishLegacyDocumentGuard';
import { installSpanishApiFeedbackGuard } from '../utils/spanishApiFeedbackGuard';
import { installSpanishSettingsRequestGuard } from '../utils/spanishSettingsRequestGuard';
import { installSpanishCommerceIntegrationGuard } from '../utils/spanishCommerceIntegrationGuard';
import { installSpanishPermissionsUiGuard } from '../utils/spanishPermissionsUiGuard';

Vue.use(VueI18n);

export const loadI18n = async () => {
  const userLang = localStorage.getItem('language') || 'es';
  const useSpanishUiGuards = String(userLang).toLowerCase().startsWith('es');

  // The request guard is language-safe: it only replaces an invalid/empty
  // default_language value with "es" and preserves any explicit selection.
  installSpanishSettingsRequestGuard();

  // DOM/API guards are compatibility fallbacks for legacy English UI strings.
  // They must only run for Spanish so an explicitly selected language is never
  // overwritten by the Spanish compatibility layer.
  if (useSpanishUiGuards) {
    installSpanishUiGuard();
    installSpanishSettingsUiGuard();
    installSpanishDocumentTitleGuard();
    installSpanishLegacyDocumentGuard();
    installSpanishApiFeedbackGuard();
    installSpanishCommerceIntegrationGuard();
    installSpanishPermissionsUiGuard();
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