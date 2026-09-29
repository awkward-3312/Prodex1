/*!
 * PRODEX — consentimiento de cookies + analítica condicionada al consentimiento
 * -------------------------------------------------------------------------
 * Único responsable de la decisión de cookies del visitante en el sitio público (landing Blade,
 * páginas SEO y páginas legales). Ningún script de analítica se carga o ejecuta antes de conceder
 * la categoría correspondiente. Las cookies estrictamente necesarias (sesión, CSRF, idioma y la
 * propia preferencia de consentimiento) no dependen de esta decisión.
 *
 * API pública (window.ProdexConsent):
 *   .get()               -> {v, necessary, analytics, marketing, timestamp} | null   (null = sin decisión válida)
 *   .has('analytics')    -> boolean
 *   .set({analytics})    persistir + aplicar + difundir
 *   .openPreferences()   reabre las preferencias (la decisión vigente NO se borra)
 *   .onChange(fn)        suscribirse (también se ejecuta una vez al cargar si existe una decisión)
 *
 * Configuración (atributos data-* de esta etiqueta script, emitidos por central/partials/analytics.blade.php):
 *   data-ga-id            id de medición GA4 (G-XXXXXXXXXX). Vacío => no hay tecnología no esencial
 *                         (no se muestra banner: no hay nada que consentir).
 *   data-consent-version  entero; incrementarlo vuelve a solicitar el consentimiento.
 *   data-privacy-url      enlace a la política de privacidad.
 * Textos: JSON traducido en <script type="application/json" id="prodex-consent-i18n"> (misma partial).
 *
 * Modelo de categorías: `necessary` (siempre activa) y `analytics` (GA4, la única tecnología no esencial
 * instalada). No existe categoría de marketing porque no hay ninguna tecnología publicitaria; el campo
 * `marketing` se conserva en el registro (siempre false) por compatibilidad.
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

    /* ---------------------------------------------------------------- estado */

    // Devuelve la decisión vigente o null. Fail-safe: cualquier valor ausente, corrupto, de versión
    // anterior o con tipos inesperados cuenta como "sin decisión" => no se habilita nada no esencial.
    function read() {
        try {
            var raw = localStorage.getItem(KEY);
            if (!raw) return null;
            var obj = JSON.parse(raw);
            if (!obj || typeof obj !== "object" || Array.isArray(obj)) return null;
            if (typeof obj.v !== "number" || obj.v < VERSION) return null; // sin versión o esquema obsoleto -> volver a preguntar
            if (typeof obj.analytics !== "boolean") return null;
            return obj;
        } catch (e) {
            return null;
        }
    }

    function write(cats) {
        var payload = {
            v: VERSION,
            necessary: true,
            analytics: cats.analytics === true,
            marketing: false,
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

    /* ------------------------------------------------------------ analítica */

    function loadGa() {
        if (gaLoaded || !GA_ID) return;
        gaLoaded = true;

        window.dataLayer = window.dataLayer || [];
        window.gtag = function () { window.dataLayer.push(arguments); };
        // Google Consent Mode: todo denegado por defecto; la concesión de analítica se comunica con `update`.
        window.gtag("consent", "default", {
            ad_storage: "denied",
            ad_user_data: "denied",
            ad_personalization: "denied",
            analytics_storage: "denied",
        });
        window.gtag("js", new Date());
        window.gtag("consent", "update", { analytics_storage: "granted" });
        window.gtag("config", GA_ID, { anonymize_ip: true, send_page_view: true });

        var s = document.createElement("script");
        s.async = true;
        s.src = "https://www.googletagmanager.com/gtag/js?id=" + encodeURIComponent(GA_ID);
        document.head.appendChild(s);
    }

    // Elimina solo las cookies propias de GA (_ga, _ga_<ID>, _gid, _gat*) en este dominio y sus subdominios.
    // Las cookies de terceros que JavaScript first-party no puede tocar quedan fuera de alcance.
    function clearGaCookies() {
        var host = location.hostname;
        var parts = host.split(".");
        var domains = [undefined, host];
        for (var i = 1; i < parts.length - 1; i++) domains.push("." + parts.slice(i).join("."));
        document.cookie.split(";").forEach(function (c) {
            var n = c.split("=")[0].trim();
            if (n === "_ga" || n.indexOf("_ga_") === 0 || n === "_gid" || n.indexOf("_gat") === 0) {
                domains.forEach(function (d) {
                    document.cookie = n + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/" + (d ? "; domain=" + d : "");
                });
            }
        });
    }

    function applyAnalytics(granted) {
        if (!GA_ID) return;
        if (granted) {
            window["ga-disable-" + GA_ID] = false;
            loadGa();
            if (window.gtag) window.gtag("consent", "update", { analytics_storage: "granted" });
        } else {
            // Revocación: se detienen envíos futuros, se comunica denied y se limpian las cookies GA propias.
            window["ga-disable-" + GA_ID] = true;
            if (window.gtag) window.gtag("consent", "update", { analytics_storage: "denied" });
            clearGaCookies();
        }
    }

    function apply(state) {
        applyAnalytics(!!state && state.analytics === true);
    }

    /* ------------------------------------------------------------------- UI */

    var T = { // textos de respaldo (los reales llegan traducidos desde la partial)
        title: "Tu privacidad importa",
        text: "Usamos cookies esenciales para que el sitio funcione y, con tu permiso, cookies de analítica para entender cómo se usa.",
        policy: "Política de privacidad",
        accept: "Aceptar",
        reject: "Rechazar no esenciales",
        customize: "Configurar",
        save: "Guardar preferencias",
        prefsTitle: "Preferencias de cookies",
        necessary: "Necesarias",
        necessaryDesc: "Requeridas para que el sitio funcione. Siempre activas.",
        analytics: "Analíticas",
        analyticsDesc: "Nos ayudan a entender cómo se usa el sitio.",
        onlyNecessary: "Este sitio solo usa cookies necesarias para funcionar.",
        close: "Cerrar",
    };
    try {
        var i18nEl = document.getElementById("prodex-consent-i18n");
        if (i18nEl) {
            var loaded = JSON.parse(i18nEl.textContent || "{}");
            for (var k in loaded) if (Object.prototype.hasOwnProperty.call(loaded, k) && loaded[k]) T[k] = loaded[k];
        }
    } catch (e) {}

    var ui = null; // { root, banner, prefs, ... }
    var lastFocus = null;

    function el(tag, attrs, children) {
        var n = document.createElement(tag);
        for (var a in attrs || {}) {
            if (a === "text") n.textContent = attrs[a];
            else n.setAttribute(a, attrs[a]);
        }
        (children || []).forEach(function (c) { if (c) n.appendChild(c); });
        return n;
    }

    function buildUi() {
        if (ui) return ui;
        var titleId = "pxc-title", descId = "pxc-desc", prefsTitleId = "pxc-prefs-title";

        var policyLink = el("a", { href: PRIVACY_URL + "#cookies", text: T.policy });
        var desc = el("p", { class: "pxc__text", id: descId }, [document.createTextNode(T.text + " "), policyLink]);

        var accept = el("button", { type: "button", class: "pxc__btn pxc__btn--primary", "data-pxc": "accept", text: T.accept });
        var reject = el("button", { type: "button", class: "pxc__btn pxc__btn--secondary", "data-pxc": "reject", text: T.reject });
        var customize = el("button", { type: "button", class: "pxc__link", "data-pxc": "customize", "aria-expanded": "false", "aria-controls": "pxc-prefs", text: T.customize });
        var actions = el("div", { class: "pxc__actions" }, [customize, reject, accept]);

        // Preferencias: solo categorías reales (necessary + analytics).
        var necInput = el("input", { type: "checkbox", id: "pxc-necessary", checked: "", disabled: "" });
        var necRow = el("div", { class: "pxc__cat" }, [
            el("div", { class: "pxc__cat-info" }, [
                el("label", { class: "pxc__cat-name", for: "pxc-necessary", text: T.necessary }),
                el("p", { class: "pxc__cat-desc", text: T.necessaryDesc }),
            ]),
            el("span", { class: "pxc__switch is-locked" }, [necInput, el("span", { class: "pxc__slider", "aria-hidden": "true" })]),
        ]);
        var anInput = el("input", { type: "checkbox", id: "pxc-analytics", "data-pxc": "analytics" });
        var anRow = el("div", { class: "pxc__cat" }, [
            el("div", { class: "pxc__cat-info" }, [
                el("label", { class: "pxc__cat-name", for: "pxc-analytics", text: T.analytics }),
                el("p", { class: "pxc__cat-desc", text: T.analyticsDesc }),
            ]),
            el("span", { class: "pxc__switch" }, [anInput, el("span", { class: "pxc__slider", "aria-hidden": "true" })]),
        ]);
        var save = el("button", { type: "button", class: "pxc__btn pxc__btn--primary", "data-pxc": "save", text: T.save });
        var acceptAll = el("button", { type: "button", class: "pxc__btn pxc__btn--secondary", "data-pxc": "accept", text: T.accept });
        var close = el("button", { type: "button", class: "pxc__link", "data-pxc": "close", text: T.close });
        var prefs = el("div", { class: "pxc__prefs", id: "pxc-prefs", hidden: "" }, [
            el("h3", { class: "pxc__prefs-title", id: prefsTitleId, text: T.prefsTitle }),
            necRow,
            GA_ID ? anRow : el("p", { class: "pxc__cat-desc", text: T.onlyNecessary }),
            el("div", { class: "pxc__actions" }, GA_ID ? [close, acceptAll, save] : [close]),
        ]);

        var root = el("section", { class: "pxc", id: "pxcConsent", role: "dialog", "aria-modal": "false", "aria-labelledby": titleId, "aria-describedby": descId, hidden: "" }, [
            el("h2", { class: "pxc__title", id: titleId, text: T.title }),
            desc,
            actions,
            prefs,
        ]);
        document.body.appendChild(root);

        root.addEventListener("click", function (e) {
            var b = e.target.closest ? e.target.closest("[data-pxc]") : null;
            if (!b) return;
            var act = b.getAttribute("data-pxc");
            if (act === "accept") { API.set({ analytics: true }); closeUi(true); }
            else if (act === "reject") { API.set({ analytics: false }); closeUi(true); }
            else if (act === "save") { API.set({ analytics: anInput.checked }); closeUi(true); }
            else if (act === "customize") { togglePrefs(); }
            else if (act === "close") { closeUi(true); }
        });
        // Teclado: Escape nunca cuenta como consentimiento; con decisión previa cierra el panel y devuelve el foco.
        root.addEventListener("keydown", function (e) {
            if (e.key !== "Escape") return;
            if (!prefs.hidden && !read()) { togglePrefs(false); customize.focus(); return; }
            if (read()) closeUi(true);
        });

        ui = { root: root, prefs: prefs, customize: customize, anInput: anInput, actions: actions, desc: desc, title: root.querySelector(".pxc__title") };
        return ui;
    }

    function togglePrefs(force) {
        var u = buildUi();
        var open = typeof force === "boolean" ? force : u.prefs.hidden;
        u.prefs.hidden = !open;
        u.customize.setAttribute("aria-expanded", open ? "true" : "false");
        u.root.classList.toggle("is-prefs", open);
        if (open) { var f = u.prefs.querySelector("input:not([disabled]),button"); if (f) f.focus(); }
    }

    function openUi(mode) {
        var u = buildUi();
        var stored = read();
        u.anInput.checked = !!(stored && stored.analytics);
        u.root.hidden = false;
        // Con decisión previa (reapertura) se muestra directamente el panel de preferencias.
        var reopen = mode === "prefs";
        u.root.classList.toggle("is-reopen", reopen);
        u.actions.hidden = reopen;
        u.desc.hidden = reopen;
        u.title.hidden = reopen;
        togglePrefs(reopen);
        requestAnimationFrame(function () { u.root.classList.add("is-open"); });
    }

    function closeUi(restoreFocus) {
        if (!ui) return;
        ui.root.classList.remove("is-open");
        var done = function () { ui.root.hidden = true; };
        // Sin transición (reduced-motion) `transitionend` no llega: se cierra al siguiente frame.
        var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        if (reduce) done(); else setTimeout(done, 220);
        if (restoreFocus && lastFocus && document.contains(lastFocus)) { try { lastFocus.focus(); } catch (e) {} }
        lastFocus = null;
    }

    /* ------------------------------------------------------------------ API */

    var API = {
        get: read,
        has: function (cat) {
            var s = read();
            return !!s && s[cat] === true;
        },
        set: function (cats) {
            var state = write(cats || {});
            apply(state);
            broadcast(state);
            return state;
        },
        openPreferences: function () {
            lastFocus = document.activeElement;
            openUi("prefs");
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

    // Helper ligero de analítica: no hace nada hasta que se conceda analítica y GA esté disponible.
    window.ProdexAnalytics = {
        track: function (name, params) {
            if (!GA_ID || !API.has("analytics") || typeof window.gtag !== "function") return;
            window.gtag("event", String(name), params || {});
        },
    };

    /* --------------------------------------------------------------- arranque */

    function init() {
        // Enlaces "Preferencias de cookies" del pie de página (una por variante de landing).
        document.addEventListener("click", function (e) {
            var a = e.target.closest ? e.target.closest("#cookiePreferencesLink, #lpCookiePrefs, [data-consent-open]") : null;
            if (!a) return;
            e.preventDefault();
            API.openPreferences();
        });

        var stored = read();
        if (stored) {
            apply(stored);
            broadcast(stored);
        } else if (GA_ID) {
            // Sin decisión válida: solo lo necesario y se muestra el banner (sin plazo ni scroll que cuenten como consentimiento).
            openUi("banner");
        }
        // Sin tecnología no esencial (GA_ID vacío) no hay nada que consentir: no se muestra banner.
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
