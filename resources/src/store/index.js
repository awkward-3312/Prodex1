import Vuex from "vuex";
import Vue from "vue";
import largeSidebar from "./modules/largeSidebar";
import compactSidebar from "./modules/compactSidebar";
import config from "./modules/config";
import auth from "./modules/auth";
import shellScope from "./modules/shellScope";
import language from './modules/language';
// Load Vuex
Vue.use(Vuex);


// Create store
export default new Vuex.Store({
  modules: {
    language,
    auth,
    shellScope,
    largeSidebar,
    compactSidebar,
    config,
  }
});
