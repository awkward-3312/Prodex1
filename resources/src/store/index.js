import { createStore } from "vuex";
import largeSidebar from "./modules/largeSidebar";
import compactSidebar from "./modules/compactSidebar";
import config from "./modules/config";
import auth from "./modules/auth";
import shellScope from "./modules/shellScope";
import language from './modules/language';

// Vuex 4: `createStore(...)` en vez de `new Vuex.Store(...)`; se instala en la app real con `app.use(store)`
// (lo hace `platform/mount.js`), no con `Vue.use(Vuex)` (ese registro global ya no hace falta ni existe).
export default createStore({
  modules: {
    language,
    auth,
    shellScope,
    largeSidebar,
    compactSidebar,
    config,
  }
});
