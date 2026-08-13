import Vue from 'vue';
import VueLocalStorage from 'vue-localstorage';

Vue.use(VueLocalStorage);

const state = {
  language: Vue.localStorage.get('language') || 'en',
};

const getters = {
  getLanguage: state => state.language,
};

const mutations = {
  SET_LANGUAGE(state, lang) {
    Vue.localStorage.set('language', lang);
    state.language = lang;
  },
};

const actions = {
  async setLanguage({ commit }, payload) {
    let selected = 'en';

    if (typeof payload === 'string') {
      selected = payload;
    } else if (Array.isArray(payload)) {
      selected = payload
        .map(l => l.substring(0, 2))
        .find(code => !!code) || 'en';
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
      console.warn('Failed to sync default language to backend:', error);
    }

    // Sync locale to a cookie so Blade PDFs use same language (ar -> Arabic, else -> English)
    try {
      await axios.post('/sync-locale', { locale: selected }, bgMeta);
    } catch (err) {
      console.warn('Failed to sync locale for PDFs:', err);
    }
  },
};

export default {
  state,
  getters,
  actions,
  mutations,
};
