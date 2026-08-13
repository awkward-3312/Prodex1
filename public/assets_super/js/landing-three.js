(function () {
    var header = document.getElementById('l3Header');
    if (header) {
        function onScroll() {
            header.classList.toggle('shadow-md', window.scrollY > 12);
        }
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    var drawer = document.getElementById('l3Drawer');
    var panel = document.getElementById('l3DrawerPanel');
    var openBtn = document.getElementById('l3OpenMenu');
    var closeBtn = document.getElementById('l3CloseMenu');
    function drawerClosedTransform() {
        return document.documentElement.getAttribute('dir') === 'rtl' ? 'translateX(-100%)' : 'translateX(100%)';
    }
    function openDrawer() {
        if (!drawer || !panel) return;
        panel.style.transform = drawerClosedTransform();
        drawer.classList.remove('hidden');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(function () {
            panel.style.transform = 'translateX(0)';
        });
        if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
    }
    function closeDrawer() {
        if (!drawer || !panel) return;
        panel.style.transform = drawerClosedTransform();
        if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        setTimeout(function () {
            drawer.classList.add('hidden');
            drawer.setAttribute('aria-hidden', 'true');
        }, 280);
    }
    if (openBtn && drawer) openBtn.addEventListener('click', openDrawer);
    if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
    if (drawer) {
        drawer.querySelectorAll('[data-l3-drawer-backdrop], a').forEach(function (el) {
            el.addEventListener('click', function (e) {
                if (el.tagName === 'A' || el.hasAttribute('data-l3-drawer-backdrop')) closeDrawer();
            });
        });
    }
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 1024 && drawer && !drawer.classList.contains('hidden')) closeDrawer();
    });

    var lang = document.getElementById('l3Lang');
    var langBtn = document.getElementById('l3LangBtn');
    var langMenu = document.getElementById('l3LangMenu');
    if (langBtn && lang && langMenu) {
        langBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            langMenu.classList.toggle('hidden');
            langBtn.setAttribute('aria-expanded', !langMenu.classList.contains('hidden'));
        });
        document.addEventListener('click', function () {
            langMenu.classList.add('hidden');
            langBtn.setAttribute('aria-expanded', 'false');
        });
    }

    if (document.querySelector('.l3-price-tab')) {
        document.querySelectorAll('.l3-price-tab').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var cycle = btn.getAttribute('data-cycle');
                var wrap = btn.closest('.mb-16') || btn.parentElement;
                if (!wrap) return;
                wrap.querySelectorAll('.l3-price-tab').forEach(function (b) {
                    var on = b.getAttribute('data-cycle') === cycle;
                    b.classList.toggle('bg-white', on);
                    b.classList.toggle('shadow-sm', on);
                    b.classList.toggle('text-slate-900', on);
                    b.classList.toggle('text-slate-500', !on);
                });
                var hasYearly = document.querySelector('.l3-plan-price[data-yearly]');
                document.querySelectorAll('.l3-plan-price[data-monthly]').forEach(function (el) {
                    el.classList.toggle('hidden', hasYearly && cycle !== 'monthly');
                });
                document.querySelectorAll('.l3-plan-price[data-yearly]').forEach(function (el) {
                    el.classList.toggle('hidden', cycle !== 'yearly');
                });
            });
        });
    }
})();

(function () {
    var banner = document.getElementById('cookieConsent');
    if (!banner) return;
    function showBanner() {
        banner.classList.remove('translate-y-[120%]', 'opacity-0', 'pointer-events-none');
        banner.classList.add('translate-y-0', 'opacity-100');
    }
    var consent = localStorage.getItem('cookie_consent');
    if (!consent) setTimeout(showBanner, 600);
    function acceptAll() {
        localStorage.setItem('cookie_consent', JSON.stringify({ necessary: true, analytics: true, marketing: true, timestamp: Date.now() }));
        banner.classList.add('translate-y-[120%]', 'opacity-0', 'pointer-events-none');
    }
    function rejectAll() {
        localStorage.setItem('cookie_consent', JSON.stringify({ necessary: true, analytics: false, marketing: false, timestamp: Date.now() }));
        banner.classList.add('translate-y-[120%]', 'opacity-0', 'pointer-events-none');
    }
    var a = document.getElementById('cookieAcceptBtn');
    var r = document.getElementById('cookieRejectBtn');
    var c = document.getElementById('cookieCustomizeBtn');
    var s = document.getElementById('cookieSaveBtn');
    if (a) a.addEventListener('click', acceptAll);
    if (r) r.addEventListener('click', rejectAll);
    if (c) c.addEventListener('click', function () {
        var p = document.getElementById('cookieCustomize');
        if (p) p.classList.toggle('hidden');
    });
    if (s) s.addEventListener('click', function () {
        var ca = document.getElementById('cookieAnalytics');
        var cm = document.getElementById('cookieMarketing');
        localStorage.setItem('cookie_consent', JSON.stringify({
            necessary: true,
            analytics: ca ? ca.checked : false,
            marketing: cm ? cm.checked : false,
            timestamp: Date.now()
        }));
        banner.classList.add('translate-y-[120%]', 'opacity-0', 'pointer-events-none');
    });
    window.reopenCookieConsent = function () {
        localStorage.removeItem('cookie_consent');
        var p = document.getElementById('cookieCustomize');
        if (p) p.classList.add('hidden');
        var ca = document.getElementById('cookieAnalytics');
        var cm = document.getElementById('cookieMarketing');
        if (ca) ca.checked = false;
        if (cm) cm.checked = false;
        showBanner();
    };
    var prefLink = document.getElementById('cookiePreferencesLink');
    if (prefLink) {
        prefLink.addEventListener('click', function (e) {
            e.preventDefault();
            window.reopenCookieConsent();
        });
    }
})();
