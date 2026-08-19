import Vue from 'vue';
import VueI18n from 'vue-i18n';
import axios from 'axios';
import { supportMessages } from './support.i18n';
import { bundledUiMessages, readableMissingTranslation } from './ui.fallback.i18n';
import { installSpanishUiGuard } from '../utils/spanishUiGuard';
import { installSpanishSettingsUiGuard } from '../utils/spanishSettingsUiGuard';

Vue.use(VueI18n);

export const loadI18n = async () => {
  installSpanishUiGuard();
  installSpanishSettingsUiGuard();
  const userLang = localStorage.getItem('language') || 'es';

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
