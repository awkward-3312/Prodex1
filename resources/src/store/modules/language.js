import Vue from 'vue';
import VueLocalStorage from 'vue-localstorage';

Vue.use(VueLocalStorage);

const storedLanguage = Vue.localStorage.get('language');
const spanishDefaultApplied = Vue.localStorage.get('prodex_spanish_default_v1');

// One-time normalization for browsers that inherited the old platform default.
// After this marker is stored, users remain free to choose another language.
if (!spanishDefaultApplied && (!storedLanguage || storedLanguage === 'en')) {
  Vue.localStorage.set('language', 'es');
  Vue.localStorage.set('prodex_spanish_default_v1', '1');
}

const state = {
  language: Vue.localStorage.get('language') || 'es',
};

const getters = {
  getLanguage: state => state.language,
};

const mutations = {
  SET_LANGUAGE(state, lang) {
    Vue.localStorage.set('language', lang);
    Vue.localStorage.set('prodex_spanish_default_v1', '1');
    state.language = lang;
  },
};

const actions = {
  async setLanguage({ commit }, payload) {
    let selected = 'es';

    if (typeof payload === 'string') {
      selected = payload;
    } else if (Array.isArray(payload)) {
      selected = payload
        .map(l => l.substring(0, 2))
        .find(code => !!code) || 'es';
    }

    // Update localStorage & state
    commit('SET_LANGUAGE', selected);

    // These are best-effort background calls. They must never hijack navigation
    // (e.g. bounce the app to the NotFound page) if they fail, so they opt out
    // of the global axios error-redirect via meta.skipErrorRedirect.
    const bgMeta = { meta: { skipErrorRedirect: true } };

    // ✅ Also update backend (user default language in DB)
    try {
      await axios.post(`/languages_setting/set-default/${selected}`, {}, bgMeta);
    } catch (error) {
      console.warn('No se pudo sincronizar el idioma predeterminado con el servidor:', error);
    }

    // Sync locale to a cookie so Blade/PDF output uses the same language.
    try {
      await axios.post('/sync-locale', { locale: selected }, bgMeta);
    } catch (err) {
      console.warn('No se pudo sincronizar el idioma para documentos PDF:', err);
    }
  },
};

export default {
  state,
  getters,
  actions,
  mutations,
};
