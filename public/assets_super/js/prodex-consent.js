/*!
 * PRODEX — cookie consent + consent-gated analytics
 * -------------------------------------------------------------------------
 * Single owner of the visitor's cookie decision for every public page
 * (Blade landing + static SEO pages). No analytics / marketing script is
 * loaded or fired before the matching category has been granted.
 *
 * Public API (window.ProdexConsent):
 *   .get()               -> {necessary, analytics, marketing, v, timestamp} | null
 *   .has('analytics')    -> boolean
 *   .set({analytics, marketing})  persist + apply + broadcast
 *   .openPreferences()   re-open the banner/preferences
 *   .onChange(fn)        subscribe (also fired once on load if a decision exists)
 *
 * Config comes from this script tag's data-* attributes:
 *   data-ga-id            GA4 measurement id (G-XXXXXXXXXX). Empty => no analytics.
 *   data-consent-version  integer; bumping it re-prompts returning visitors.
 *   data-privacy-url      href for the "privacy policy" link in the injected banner.
 */
(function () {
    "use strict";

    var SCRIPT = document.currentScript;
    var GA_ID = (SCRIPT && SCRIPT.getAttribute("data-ga-id")) || "";
    var VERSION = parseInt((SCRIPT && SCRIPT.getAttribute("data-consent-version")) || "1", 10) || 1;
    var PRIVACY_URL = (SCRIPT && SCRIPT.getAttribute("data-privacy-url")) || "/privacy-policy";
    var KEY = "cookie_consent";

    var listeners = [];
    var gaLoaded = false;

    /* ---------------------------------------------------------------- state */

    function read() {
        try {
            var raw = localStorage.getItem(KEY);
            if (!raw) return null;
            var obj = JSON.parse(raw);
            if (!obj || typeof obj !== "object") return null;
            if ((obj.v || 1) < VERSION) return null; // stale schema -> re-ask
            return obj;
        } catch (e) {
            return null;
        }
    }

    function write(cats) {
        var payload = {
            v: VERSION,
            necessary: true,
            analytics: !!cats.analytics,
            marketing: !!cats.marketing,
            timestamp: Date.now(),
        };
        try {
            localStorage.setItem(KEY, JSON.stringify(payload));
        } catch (e) {}
        return payload;
    }

    function broadcast(state) {
        for (var i = 0; i < listeners.length; i++) {
            try {
                listeners[i](state);
            } catch (e) {}
        }
        try {
            window.dispatchEvent(new CustomEvent("prodex:consent", { detail: state }));
        } catch (e) {}
    }

    /* ------------------------------------------------------------ analytics */

    function loadGa() {
        if (gaLoaded || !GA_ID) return;
        gaLoaded = true;

        window.dataLayer = window.dataLayer || [];
        window.gtag = function () { window.dataLayer.push(arguments); };
        window.gtag("js", new Date());
        // Privacy-preserving defaults: no ad signals, IP anonymised, no cross-page ads.
        window.gtag("consent", "default", {
            ad_storage: "denied",
            ad_user_data: "denied",
            ad_personalization: "denied",
            analytics_storage: "granted",
        });
        window.gtag("config", GA_ID, { anonymize_ip: true, send_page_view: true });

        var s = document.createElement("script");
        s.async = true;
        s.src = "https://www.googletagmanager.com/gtag/js?id=" + encodeURIComponent(GA_ID);
        document.head.appendChild(s);
    }

    function applyAnalytics(granted) {
        if (!GA_ID) return;
        if (granted) {
            loadGa();
            if (window.gtag) window.gtag("consent", "update", { analytics_storage: "granted" });
        } else if (window.gtag) {
            window.gtag("consent", "update", { analytics_storage: "denied" });
        }
        if (!granted) {
            // best-effort removal of any GA cookies already dropped
            document.cookie.split(";").forEach(function (c) {
                var n = c.split("=")[0].trim();
                if (n.indexOf("_ga") === 0 || n === "_gid" || n.indexOf("_gat") === 0) {
                    document.cookie = n + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
                }
            });
        }
    }

    function apply(state) {
        applyAnalytics(!!state && !!state.analytics);
    }

    /* --------------------------------------------------------------- banner */

    var bannerEl = null;

    function ensureBanner() {
        var existing = document.getElementById("lpCookie");
        if (existing) return existing;

        // Static SEO pages have no Blade banner — inject a minimal accessible one.
        var el = document.createElement("div");
        el.id = "lpCookie";
        el.setAttribute("role", "dialog");
        el.setAttribute("aria-modal", "false");
        el.setAttribute("aria-label", "Preferencias de cookies");
        el.setAttribute("data-hidden", "true");
        el.style.cssText =
            "position:fixed;z-index:60;left:16px;right:16px;bottom:16px;max-width:420px;" +
            "background:#fff;border:1px solid #E7EAF0;border-radius:16px;padding:20px;" +
            "box-shadow:0 26px 60px -12px rgba(15,23,42,.24);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#334155";
        el.innerHTML =
            '<p style="font-weight:700;color:#0F172A;margin:0 0 6px">Cookies en PRODEX</p>' +
            '<p style="margin:0 0 12px;font-size:13px">Usamos cookies esenciales para que el sitio funcione y, si lo aceptas, ' +
            'cookies de analítica para entender el uso del sitio. ' +
            '<a href="' + PRIVACY_URL + '" style="color:#4F46E5">Política de privacidad</a></p>' +
            '<div style="display:flex;flex-wrap:wrap;gap:8px">' +
            '<button type="button" id="lpCookieAccept" style="flex:1;min-width:120px;background:#0F172A;color:#fff;border:0;border-radius:9999px;padding:9px 14px;font-weight:600;cursor:pointer">Aceptar todas</button>' +
            '<button type="button" id="lpCookieReject" style="flex:1;min-width:120px;background:#fff;color:#0F172A;border:1px solid #E7EAF0;border-radius:9999px;padding:9px 14px;font-weight:600;cursor:pointer">Rechazar no esenciales</button>' +
            '</div>';
        document.body.appendChild(el);
        return el;
    }

    function focusables() {
        if (!bannerEl) return [];
        return Array.prototype.slice.call(
            bannerEl.querySelectorAll('button,[href],input:not([disabled]),[tabindex]:not([tabindex="-1"])')
        ).filter(function (n) { return n.offsetParent !== null; });
    }

    function openBanner() {
        bannerEl = ensureBanner();
        bannerEl.setAttribute("data-hidden", "false");
        var f = focusables();
        if (f.length) f[0].focus();
    }

    function closeBanner() {
        if (bannerEl) bannerEl.setAttribute("data-hidden", "true");
    }

    function wireBanner() {
        bannerEl = ensureBanner();
        var byId = function (id) { return document.getElementById(id); };

        var accept = byId("lpCookieAccept");
        var reject = byId("lpCookieReject");
        var customize = byId("lpCookieCustomize");
        var save = byId("lpCookieSave");
        var panel = byId("lpCookiePanel");
        var aBox = byId("lpCookieAnalytics");
        var mBox = byId("lpCookieMarketing");
        var prefsLink = byId("lpCookiePrefs");

        if (accept) accept.addEventListener("click", function () { API.set({ analytics: true, marketing: true }); closeBanner(); });
        if (reject) reject.addEventListener("click", function () { API.set({ analytics: false, marketing: false }); closeBanner(); });
        if (customize) customize.addEventListener("click", function () {
            if (!panel) return;
            panel.hidden = !panel.hidden;
            customize.setAttribute("aria-expanded", panel.hidden ? "false" : "true");
            if (!panel.hidden) { var first = panel.querySelector("input,button"); if (first) first.focus(); }
        });
        if (save) save.addEventListener("click", function () {
            API.set({ analytics: aBox ? aBox.checked : false, marketing: mBox ? mBox.checked : false });
            closeBanner();
        });
        if (prefsLink) prefsLink.addEventListener("click", function (e) { e.preventDefault(); API.openPreferences(); });

        // Keyboard: ESC returns focus to the page without forcing a decision
        // (no choice => nothing non-essential runs, banner returns next visit).
        bannerEl.addEventListener("keydown", function (e) {
            if (e.key === "Escape") { closeBanner(); }
            if (e.key === "Tab") {
                var f = focusables();
                if (!f.length) return;
                var first = f[0], last = f[f.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        });
    }

    /* ------------------------------------------------------------------ API */

    var API = {
        get: read,
        has: function (cat) {
            var s = read();
            return !!s && !!s[cat];
        },
        set: function (cats) {
            var state = write(cats || {});
            // reflect into any open preferences checkboxes
            var a = document.getElementById("lpCookieAnalytics");
            var m = document.getElementById("lpCookieMarketing");
            if (a) a.checked = state.analytics;
            if (m) m.checked = state.marketing;
            apply(state);
            broadcast(state);
            return state;
        },
        openPreferences: function () {
            var stored = read();
            var a = document.getElementById("lpCookieAnalytics");
            var m = document.getElementById("lpCookieMarketing");
            if (a && stored) a.checked = !!stored.analytics;
            if (m && stored) m.checked = !!stored.marketing;
            var panel = document.getElementById("lpCookiePanel");
            if (panel) panel.hidden = false;
            openBanner();
        },
        onChange: function (fn) {
            if (typeof fn === "function") {
                listeners.push(fn);
                var s = read();
                if (s) { try { fn(s); } catch (e) {} }
            }
        },
    };

    window.ProdexConsent = API;

    // Thin analytics helper — silently no-op until analytics is granted + GA is up.
    window.ProdexAnalytics = {
        track: function (name, params) {
            if (!GA_ID || !API.has("analytics") || typeof window.gtag !== "function") return;
            window.gtag("event", String(name), params || {});
        },
    };

    /* --------------------------------------------------------------- bootstrap */

    function init() {
        wireBanner();
        var stored = read();
        if (stored) {
            apply(stored);
            broadcast(stored);
        } else {
            setTimeout(openBanner, 600);
        }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
