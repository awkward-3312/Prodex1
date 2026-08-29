import Vue from "vue";
import VueCookies from "vue-cookies";
Vue.use(VueCookies);

export default (to, from, next) => {
  let accessToken = VueCookies.isKey("Stocky_token");
  if (!accessToken) {
    // "/app/sessions/signIn" is not a registered SPA route; go to the real
    // (Blade) login page. (This guard is currently unused — see router.js — but
    // keep the redirect correct in case it is re-enabled.)
    next(false);
    if (typeof window !== "undefined") window.location.replace("/login");
  } else {
    return next();
  }
};
