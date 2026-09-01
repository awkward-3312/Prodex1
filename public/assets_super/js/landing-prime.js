/* landing-prime — PRODEX página pública. Vanilla, sin dependencias.
   La lógica de recomendación NO vive aquí: se consulta a /pricing/recommend
   (PlanRecommendationService es la fuente única). Este archivo sincroniza
   controles, pinta el resultado y aporta motion funcional. */
(function () {
    "use strict";

    var docEl = document.documentElement;
    var lang = (docEl.getAttribute("lang") || "es").slice(0, 2);
    var nf = new Intl.NumberFormat(lang);
    var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    function money(sym, amount) {
        var n = Number(amount) || 0;
        var body = Math.abs(n % 1) < 0.005 ? nf.format(Math.round(n)) : nf.format(n);
        return sym + " " + body;
    }

    /* Singular cuando el tope es 1 ("1 Almacén", no "1 Almacenes").
       Etiquetas estáticas ES del catálogo; no altera la lógica de recomendación. */
    var LIMIT_SINGULAR = {
        "Productos": "Producto", "Usuarios": "Usuario", "Almacenes": "Almacén",
        "Clientes": "Cliente", "Proveedores": "Proveedor",
        "Mensajes de WhatsApp al mes": "Mensaje de WhatsApp al mes"
    };
    function singularLabel(it) {
        return (it && it.value === 1 && LIMIT_SINGULAR[it.label]) ? LIMIT_SINGULAR[it.label] : (it ? it.label : "");
    }

    /* ── Navbar: sombra al hacer scroll ───────────────────────────── */
    var nav = document.getElementById("lpNav");
    if (nav) {
        var onScroll = function () { nav.classList.toggle("is-scrolled", window.scrollY > 8); };
        onScroll();
        window.addEventListener("scroll", onScroll, { passive: true });
    }

    /* ── Menú móvil (con manejo de foco) ──────────────────────────── */
    var drawer = document.getElementById("lpDrawer");
    var panel = document.getElementById("lpDrawerPanel");
    var backdrop = document.getElementById("lpDrawerBackdrop");
    var openBtn = document.getElementById("lpMenuOpen");
    var closeBtn = document.getElementById("lpMenuClose");
    var rtl = docEl.getAttribute("dir") === "rtl";
    var lastFocus = null;
    function closedX() { return rtl ? "translateX(-100%)" : "translateX(100%)"; }
    function focusables() {
        return panel ? [].slice.call(panel.querySelectorAll('a[href], button:not([disabled]), input, [tabindex]:not([tabindex="-1"])')) : [];
    }
    function openDrawer() {
        if (!drawer || !panel) return;
        lastFocus = document.activeElement;
        panel.style.transform = closedX();
        drawer.hidden = false;
        document.body.style.overflow = "hidden";
        requestAnimationFrame(function () { panel.style.transform = "translateX(0)"; });
        if (openBtn) openBtn.setAttribute("aria-expanded", "true");
        if (closeBtn) closeBtn.focus();
    }
    function closeDrawer() {
        if (!drawer || !panel || drawer.hidden) return;
        panel.style.transform = closedX();
        document.body.style.overflow = "";
        if (openBtn) openBtn.setAttribute("aria-expanded", "false");
        var done = function () { drawer.hidden = true; };
        if (reduce) done(); else setTimeout(done, 280);
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    if (openBtn) openBtn.addEventListener("click", openDrawer);
    if (closeBtn) closeBtn.addEventListener("click", closeDrawer);
    if (backdrop) backdrop.addEventListener("click", closeDrawer);
    if (drawer) {
        drawer.addEventListener("click", function (e) {
            if (e.target.closest("a")) closeDrawer();
        });
        drawer.addEventListener("keydown", function (e) {
            if (e.key === "Escape") { e.preventDefault(); closeDrawer(); return; }
            if (e.key !== "Tab") return;
            var f = focusables();
            if (!f.length) return;
            var first = f[0], last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        });
        window.addEventListener("resize", function () {
            if (window.innerWidth >= 1024 && !drawer.hidden) closeDrawer();
        });
    }

    /* ── Selector de idioma (desktop) ─────────────────────────────── */
    var langBtn = document.getElementById("lpLangBtn");
    var langMenu = document.getElementById("lpLangMenu");
    if (langBtn && langMenu) {
        langBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            var willOpen = langMenu.hasAttribute("hidden");
            if (willOpen) langMenu.removeAttribute("hidden"); else langMenu.setAttribute("hidden", "");
            langBtn.setAttribute("aria-expanded", String(willOpen));
        });
        document.addEventListener("click", function () {
            langMenu.setAttribute("hidden", "");
            langBtn.setAttribute("aria-expanded", "false");
        });
        langMenu.addEventListener("keydown", function (e) { if (e.key === "Escape") { langMenu.setAttribute("hidden", ""); langBtn.focus(); } });
    }

    /* ── Reveal on scroll (una sola aparición sutil) ──────────────── */
    var reveals = [].slice.call(document.querySelectorAll(".lp-reveal"));
    if (reveals.length && !reduce && "IntersectionObserver" in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) { en.target.classList.add("is-in"); io.unobserve(en.target); }
            });
        }, { rootMargin: "0px 0px -6% 0px", threshold: 0.06 });
        reveals.forEach(function (el) { io.observe(el); });
    } else {
        reveals.forEach(function (el) { el.classList.add("is-in"); });
    }

    /* ── Showcase tabs (fade corto al cambiar de panel) ──────────── */
    var tablist = document.getElementById("lpShowcaseTabs");
    if (tablist) {
        var tabs = [].slice.call(tablist.querySelectorAll(".lp-tab"));
        var tpanels = [].slice.call(document.querySelectorAll(".lp-tabpanel"));
        function selectTab(tab) {
            var targetId = tab.getAttribute("aria-controls");
            tabs.forEach(function (t) {
                var on = t === tab;
                t.setAttribute("aria-selected", String(on));
                t.tabIndex = on ? 0 : -1;
            });
            tpanels.forEach(function (p) {
                if (p.id === targetId) {
                    p.hidden = false;
                    if (!reduce) {
                        p.classList.add("is-entering");
                        requestAnimationFrame(function () { requestAnimationFrame(function () { p.classList.remove("is-entering"); }); });
                    }
                } else {
                    p.hidden = true;
                }
            });
        }
        tabs.forEach(function (tab, i) {
            tab.addEventListener("click", function () { selectTab(tab); });
            tab.addEventListener("keydown", function (e) {
                var idx = e.key === "ArrowRight" ? i + 1 : e.key === "ArrowLeft" ? i - 1 : -1;
                if (idx < 0) return;
                e.preventDefault();
                var next = tabs[(idx + tabs.length) % tabs.length];
                next.focus(); selectTab(next);
            });
        });
    }

    /* ── Calculadora de precios ──────────────────────────────────── */
    var calc = document.getElementById("lpCalc");
    if (calc) initCalculator(calc);

    function initCalculator(root) {
        root.classList.add("lp-js-calc");
        var endpoint = root.getAttribute("data-endpoint");
        var sym = root.getAttribute("data-currency") || "L";
        var live = root.querySelector("[data-calc-live]");
        var t = {
            unlimited:     root.getAttribute("data-i18n-unlimited") || "∞",
            perMonth:      root.getAttribute("data-i18n-per-month") || "/mes",
            perYear:       root.getAttribute("data-i18n-per-year") || "/año",
            billedMonthly: root.getAttribute("data-i18n-billed-monthly") || "",
            billedYearly:  root.getAttribute("data-i18n-billed-yearly") || "",
            trial:         root.getAttribute("data-i18n-trial") || ":days",
            allModules:    root.getAttribute("data-i18n-all-modules") || "",
            customStart:   root.getAttribute("data-i18n-custom-start") || ":plan",
            free:          root.getAttribute("data-i18n-free") || "Free",
            liveOk:        root.getAttribute("data-i18n-live-ok") || ""
        };

        var cycle = "monthly";
        var fields = {};
        [].slice.call(root.querySelectorAll("[data-dim]")).forEach(function (wrap) {
            var dim = wrap.getAttribute("data-dim");
            var range = wrap.querySelector('input[type="range"]');
            var num = wrap.querySelector(".lp-step__val");
            var dec = wrap.querySelector('[data-step-dir="down"]');
            var inc = wrap.querySelector('[data-step-dir="up"]');
            var min = Number(range.min || 0);
            var max = Number(range.max || 100);
            var step = Number(range.step || 1);
            fields[dim] = { range: range };

            function paintTrack() {
                var pct = max > min ? ((Number(range.value) - min) / (max - min)) * 100 : 0;
                range.style.setProperty("--lp-fill", pct + "%");
            }
            function set(v, from) {
                v = Math.max(min, Math.min(max, Math.round(v)));
                range.value = String(v);
                if (num && from !== "num") num.value = String(v);
                paintTrack();
            }
            range.addEventListener("input", function () { set(Number(range.value), "range"); schedule(); });
            if (num) {
                num.addEventListener("input", function () {
                    var v = parseInt(num.value, 10);
                    if (!isNaN(v)) { set(v, "num"); schedule(); }
                });
                num.addEventListener("blur", function () { set(Number(range.value)); });
            }
            if (dec) dec.addEventListener("click", function () { set(Number(range.value) - step); schedule(); });
            if (inc) inc.addEventListener("click", function () { set(Number(range.value) + step); schedule(); });
            paintTrack();
        });

        var segBtns = [].slice.call(root.querySelectorAll(".lp-seg__btn"));
        segBtns.forEach(function (b) {
            b.addEventListener("click", function () {
                cycle = b.getAttribute("data-cycle") === "yearly" ? "yearly" : "monthly";
                segBtns.forEach(function (x) { x.setAttribute("aria-pressed", String(x === b)); });
                schedule();
            });
        });

        var timer = null, ctrl = null;
        function schedule() {
            root.setAttribute("data-swapping", "");
            if (timer) clearTimeout(timer);
            timer = setTimeout(fetchNow, 240);
        }
        function query() {
            var parts = ["cycle=" + cycle];
            Object.keys(fields).forEach(function (k) { parts.push(k + "=" + encodeURIComponent(fields[k].range.value)); });
            return parts.join("&");
        }
        function fetchNow() {
            if (!endpoint) { root.removeAttribute("data-swapping"); return; }
            if (ctrl && ctrl.abort) ctrl.abort();
            ctrl = ("AbortController" in window) ? new AbortController() : null;
            root.setAttribute("aria-busy", "true");
            fetch(endpoint + "?" + query(), {
                headers: { "Accept": "application/json" },
                signal: ctrl ? ctrl.signal : undefined
            })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
                .then(render)
                .catch(function (err) { /* fallo puntual: se conserva el último estado válido */ })
                .then(function () { root.removeAttribute("aria-busy"); root.removeAttribute("data-swapping"); });
        }

        function fill(sel, value) {
            [].slice.call(root.querySelectorAll(sel)).forEach(function (el) { el.textContent = value; });
        }
        function href(sel, url) {
            [].slice.call(root.querySelectorAll(sel)).forEach(function (el) {
                if (url) { el.setAttribute("href", url); el.removeAttribute("aria-disabled"); }
            });
        }
        function periodLabel(p) { return p === "year" ? t.perYear : t.perMonth; }
        function billNote(p) { return p === "year" ? t.billedYearly : t.billedMonthly; }
        function planAmount(plan) { return plan.is_free ? t.free : money(sym, plan.billed_amount); }

        function renderList(sel, items, render1) {
            var host = root.querySelector(sel);
            if (!host) return;
            host.textContent = "";
            items.forEach(function (it) {
                var li = document.createElement("li");
                render1(li, it);
                host.appendChild(li);
            });
        }

        var planCards = [].slice.call(document.querySelectorAll("#lpPlans [data-plan-id]"));
        function highlightPlan(id) {
            planCards.forEach(function (el) {
                el.classList.toggle("is-recommended", id != null && String(el.getAttribute("data-plan-id")) === String(id));
            });
        }

        function render(data) {
            root.setAttribute("data-status", data.recommendation_status);

            // Realce discreto de la card del plan recomendado (sin scroll ni salto).
            highlightPlan(data.recommendation_status === "ok" && data.recommended ? data.recommended.id : null);

            var maxSave = 0;
            (data.plans || []).forEach(function (p) {
                if (p.yearly_available && p.yearly_savings_percent > maxSave) maxSave = p.yearly_savings_percent;
            });
            var saveEl = root.querySelector("[data-calc-save]");
            if (saveEl) {
                if (maxSave > 0) { saveEl.hidden = false; saveEl.textContent = (saveEl.getAttribute("data-tmpl") || ":percent%").replace(":percent", maxSave); }
                else { saveEl.hidden = true; }
            }

            href("[data-calc-sales]", data.sales_url);

            var plan = data.recommendation_status === "ok" ? data.recommended
                     : data.recommendation_status === "custom" ? data.starting_point : null;

            if (plan) {
                fill("[data-calc-name]", plan.name);
                fill("[data-calc-amount]", planAmount(plan));
                fill("[data-calc-period]", plan.is_free ? "" : periodLabel(plan.billed_period));
                fill("[data-calc-billnote]", plan.is_free ? "" : billNote(plan.billed_period));
                href("[data-calc-cta]", plan.register_url);

                var trialEl = root.querySelector("[data-calc-trialnote]");
                if (trialEl) {
                    if (plan.is_trial && plan.trial_days > 0) { trialEl.hidden = false; trialEl.textContent = t.trial.replace(":days", plan.trial_days); }
                    else { trialEl.hidden = true; }
                }

                renderList("[data-calc-included]", plan.included || [], function (li, it) {
                    var strong = document.createElement("strong");
                    strong.textContent = it.unlimited ? t.unlimited : it.display;
                    li.appendChild(strong);
                    li.appendChild(document.createTextNode(" " + singularLabel(it)));
                });

                var feats = plan.features || [];
                renderList("[data-calc-features]", feats.length ? feats : [t.allModules], function (li, txt) { li.textContent = txt; });

                fill("[data-calc-startplan]", (t.customStart || ":plan").replace(":plan", plan.name));
            }

            // Anuncio no intrusivo para lectores de pantalla.
            if (live) {
                if (data.recommendation_status === "ok" && plan) {
                    live.textContent = (t.liveOk || "").replace(":plan", plan.name).replace(":amount", planAmount(plan));
                } else if (data.recommendation_status === "custom") {
                    live.textContent = root.getAttribute("data-i18n-live-custom") || "";
                } else {
                    live.textContent = root.getAttribute("data-i18n-live-nodata") || "";
                }
            }
        }
    }

    /* ── Cookie consent (localStorage, sin red) ──────────────────── */
    var cookie = document.getElementById("lpCookie");
    if (cookie) {
        var KEY = "cookie_consent";
        function saveConsent(obj) {
            try { localStorage.setItem(KEY, JSON.stringify(Object.assign({ timestamp: Date.now() }, obj))); } catch (e) {}
            cookie.setAttribute("data-hidden", "true");
        }
        var stored = null;
        try { stored = localStorage.getItem(KEY); } catch (e) {}
        if (!stored) setTimeout(function () { cookie.setAttribute("data-hidden", "false"); }, 700);
        var by = function (id) { return document.getElementById(id); };
        if (by("lpCookieAccept")) by("lpCookieAccept").addEventListener("click", function () { saveConsent({ necessary: true, analytics: true, marketing: true }); });
        if (by("lpCookieReject")) by("lpCookieReject").addEventListener("click", function () { saveConsent({ necessary: true, analytics: false, marketing: false }); });
        if (by("lpCookieCustomize")) by("lpCookieCustomize").addEventListener("click", function () { var p = by("lpCookiePanel"); if (p) p.hidden = !p.hidden; });
        if (by("lpCookieSave")) by("lpCookieSave").addEventListener("click", function () {
            var a = by("lpCookieAnalytics"), m = by("lpCookieMarketing");
            saveConsent({ necessary: true, analytics: a ? a.checked : false, marketing: m ? m.checked : false });
        });
        window.lpReopenCookies = function () {
            try { localStorage.removeItem(KEY); } catch (e) {}
            cookie.setAttribute("data-hidden", "false");
        };
        var prefs = by("lpCookiePrefs");
        if (prefs) prefs.addEventListener("click", function (e) { e.preventDefault(); window.lpReopenCookies(); });
    }
})();
