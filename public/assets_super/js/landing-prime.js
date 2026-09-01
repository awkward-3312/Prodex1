/* landing-prime — PRODEX página pública. Vanilla, sin dependencias.
   La lógica de recomendación NO vive aquí: se consulta a /pricing/recommend
   (PlanRecommendationService es la fuente única). Este archivo solo sincroniza
   controles y pinta el resultado. */
(function () {
    "use strict";

    var lang = (document.documentElement.getAttribute("lang") || "es").slice(0, 2);
    var nf = new Intl.NumberFormat(lang);

    function money(sym, amount) {
        var n = Number(amount) || 0;
        var body = Math.abs(n % 1) < 0.005 ? nf.format(Math.round(n)) : nf.format(n);
        return sym + " " + body;
    }

    /* ── Navbar sticky ─────────────────────────────────────────────── */
    var nav = document.getElementById("lpNav");
    if (nav) {
        var onScroll = function () { nav.classList.toggle("is-scrolled", window.scrollY > 8); };
        onScroll();
        window.addEventListener("scroll", onScroll, { passive: true });
    }

    /* ── Menú móvil ────────────────────────────────────────────────── */
    var drawer = document.getElementById("lpDrawer");
    var panel = document.getElementById("lpDrawerPanel");
    var openBtn = document.getElementById("lpMenuOpen");
    var closeBtn = document.getElementById("lpMenuClose");
    var rtl = document.documentElement.getAttribute("dir") === "rtl";
    function closedX() { return rtl ? "translateX(-100%)" : "translateX(100%)"; }
    function openDrawer() {
        if (!drawer || !panel) return;
        panel.style.transform = closedX();
        drawer.hidden = false;
        document.body.style.overflow = "hidden";
        requestAnimationFrame(function () { panel.style.transform = "translateX(0)"; });
        if (openBtn) openBtn.setAttribute("aria-expanded", "true");
    }
    function closeDrawer() {
        if (!drawer || !panel) return;
        panel.style.transform = closedX();
        document.body.style.overflow = "";
        if (openBtn) openBtn.setAttribute("aria-expanded", "false");
        setTimeout(function () { drawer.hidden = true; }, 260);
    }
    if (openBtn) openBtn.addEventListener("click", openDrawer);
    if (closeBtn) closeBtn.addEventListener("click", closeDrawer);
    if (drawer) {
        drawer.addEventListener("click", function (e) {
            if (e.target === drawer || e.target.closest("a")) closeDrawer();
        });
        window.addEventListener("keydown", function (e) { if (e.key === "Escape") closeDrawer(); });
        window.addEventListener("resize", function () {
            if (window.innerWidth >= 1024 && !drawer.hidden) closeDrawer();
        });
    }

    /* ── Selector de idioma ────────────────────────────────────────── */
    var langBtn = document.getElementById("lpLangBtn");
    var langMenu = document.getElementById("lpLangMenu");
    if (langBtn && langMenu) {
        langBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            var open = langMenu.hasAttribute("hidden");
            if (open) { langMenu.removeAttribute("hidden"); } else { langMenu.setAttribute("hidden", ""); }
            langBtn.setAttribute("aria-expanded", String(open));
        });
        document.addEventListener("click", function () {
            langMenu.setAttribute("hidden", "");
            langBtn.setAttribute("aria-expanded", "false");
        });
    }

    /* ── Reveal on scroll ──────────────────────────────────────────── */
    var reveals = [].slice.call(document.querySelectorAll(".lp-reveal"));
    if (reveals.length && "IntersectionObserver" in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) { en.target.classList.add("is-in"); io.unobserve(en.target); }
            });
        }, { rootMargin: "0px 0px -8% 0px", threshold: 0.08 });
        reveals.forEach(function (el) { io.observe(el); });
    } else {
        reveals.forEach(function (el) { el.classList.add("is-in"); });
    }

    /* ── Showcase tabs ─────────────────────────────────────────────── */
    var tablist = document.getElementById("lpShowcaseTabs");
    if (tablist) {
        var tabs = [].slice.call(tablist.querySelectorAll(".lp-tab"));
        var panels = [].slice.call(document.querySelectorAll(".lp-tabpanel"));
        function selectTab(tab) {
            tabs.forEach(function (t) {
                var on = t === tab;
                t.setAttribute("aria-selected", String(on));
                t.tabIndex = on ? 0 : -1;
            });
            panels.forEach(function (p) { p.hidden = p.id !== tab.getAttribute("aria-controls"); });
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

    /* ── Calculadora de precios ────────────────────────────────────── */
    var calc = document.getElementById("lpCalc");
    if (calc) initCalculator(calc);

    function initCalculator(root) {
        var endpoint = root.getAttribute("data-endpoint");
        var sym = root.getAttribute("data-currency") || "L";
        var t = {
            unlimited:     root.getAttribute("data-i18n-unlimited") || "∞",
            perMonth:      root.getAttribute("data-i18n-per-month") || "/mes",
            perYear:       root.getAttribute("data-i18n-per-year") || "/año",
            billedMonthly: root.getAttribute("data-i18n-billed-monthly") || "",
            billedYearly:  root.getAttribute("data-i18n-billed-yearly") || "",
            trial:         root.getAttribute("data-i18n-trial") || ":days",
            allModules:    root.getAttribute("data-i18n-all-modules") || "",
            customStart:   root.getAttribute("data-i18n-custom-start") || ":plan",
            free:          root.getAttribute("data-i18n-free") || "Free"
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
            fields[dim] = { range: range, num: num, min: min, max: max, step: step };

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

        var timer = null;
        var ctrl = null;
        function schedule() {
            if (timer) clearTimeout(timer);
            timer = setTimeout(fetchNow, 260);
        }
        function query() {
            var parts = ["cycle=" + cycle];
            Object.keys(fields).forEach(function (k) {
                parts.push(k + "=" + encodeURIComponent(fields[k].range.value));
            });
            return parts.join("&");
        }
        function fetchNow() {
            if (!endpoint) return;
            if (ctrl && ctrl.abort) ctrl.abort();
            ctrl = ("AbortController" in window) ? new AbortController() : null;
            root.setAttribute("aria-busy", "true");
            fetch(endpoint + "?" + query(), {
                headers: { "Accept": "application/json" },
                signal: ctrl ? ctrl.signal : undefined
            })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
                .then(render)
                .catch(function (e) { if (e !== 20 && e && e.name !== "AbortError") { /* deja el último estado válido */ } })
                .then(function () { root.removeAttribute("aria-busy"); });
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

        function planAmount(plan) {
            if (plan.is_free) return t.free;
            return money(sym, plan.billed_amount);
        }

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

        function render(data) {
            root.setAttribute("data-status", data.recommendation_status);

            // Ahorro anual (máximo real entre los planes públicos).
            var maxSave = 0;
            (data.plans || []).forEach(function (p) {
                if (p.yearly_available && p.yearly_savings_percent > maxSave) maxSave = p.yearly_savings_percent;
            });
            var saveEl = root.querySelector("[data-calc-save]");
            if (saveEl) {
                if (maxSave > 0) {
                    saveEl.hidden = false;
                    saveEl.textContent = (saveEl.getAttribute("data-tmpl") || ":percent%").replace(":percent", maxSave);
                } else {
                    saveEl.hidden = true;
                }
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
                    if (plan.is_trial && plan.trial_days > 0) {
                        trialEl.hidden = false;
                        trialEl.textContent = t.trial.replace(":days", plan.trial_days);
                    } else {
                        trialEl.hidden = true;
                    }
                }

                renderList("[data-calc-included]", plan.included || [], function (li, it) {
                    var strong = document.createElement("strong");
                    strong.textContent = it.unlimited ? t.unlimited : it.display;
                    li.appendChild(strong);
                    li.appendChild(document.createTextNode(" " + it.label));
                });

                var feats = plan.features || [];
                renderList("[data-calc-features]", feats.length ? feats : [t.allModules], function (li, txt) {
                    li.textContent = txt;
                });

                fill("[data-calc-startplan]", (t.customStart || ":plan").replace(":plan", plan.name));
            }
        }

        // Estado inicial ya viene renderizado por Blade; solo pintamos los tracks.
    }

    /* ── Cookie consent (localStorage, sin red) ────────────────────── */
    var cookie = document.getElementById("lpCookie");
    if (cookie) {
        var KEY = "cookie_consent";
        function hide() { cookie.setAttribute("data-hidden", "true"); }
        function save(obj) {
            try { localStorage.setItem(KEY, JSON.stringify(Object.assign({ timestamp: Date.now() }, obj))); } catch (e) {}
            hide();
        }
        var stored = null;
        try { stored = localStorage.getItem(KEY); } catch (e) {}
        if (!stored) setTimeout(function () { cookie.setAttribute("data-hidden", "false"); }, 700);
        var by = function (id) { return document.getElementById(id); };
        if (by("lpCookieAccept")) by("lpCookieAccept").addEventListener("click", function () { save({ necessary: true, analytics: true, marketing: true }); });
        if (by("lpCookieReject")) by("lpCookieReject").addEventListener("click", function () { save({ necessary: true, analytics: false, marketing: false }); });
        if (by("lpCookieCustomize")) by("lpCookieCustomize").addEventListener("click", function () {
            var p = by("lpCookiePanel"); if (p) p.hidden = !p.hidden;
        });
        if (by("lpCookieSave")) by("lpCookieSave").addEventListener("click", function () {
            var a = by("lpCookieAnalytics"), m = by("lpCookieMarketing");
            save({ necessary: true, analytics: a ? a.checked : false, marketing: m ? m.checked : false });
        });
        window.lpReopenCookies = function () {
            try { localStorage.removeItem(KEY); } catch (e) {}
            cookie.setAttribute("data-hidden", "false");
        };
        var link = by("lpCookiePrefs");
        if (link) link.addEventListener("click", function (e) { e.preventDefault(); window.lpReopenCookies(); });
    }
})();
