(function () {
    var header = document.getElementById('l5Header');
    if (header) {
        function onScroll() { header.classList.toggle('is-scrolled', window.scrollY > 10); }
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }
    var drawer = document.getElementById('l5Drawer');
    var openBtn = document.getElementById('l5OpenMenu');
    var closeBtn = document.getElementById('l5CloseMenu');
    function openDrawer() {
        if (!drawer) return;
        drawer.removeAttribute('hidden');
        drawer.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(function () { drawer.classList.add('is-open'); });
        if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    }
    function closeDrawer() {
        if (!drawer) return;
        drawer.classList.remove('is-open');
        if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        setTimeout(function () {
            drawer.setAttribute('hidden', 'hidden');
            drawer.setAttribute('aria-hidden', 'true');
        }, 280);
    }
    if (openBtn && drawer) openBtn.addEventListener('click', openDrawer);
    if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
    if (drawer) {
        drawer.addEventListener('click', function (e) { if (e.target === drawer) closeDrawer(); });
        drawer.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', closeDrawer); });
    }
    window.addEventListener('resize', function () { if (window.innerWidth >= 1024) closeDrawer(); });

    var lang = document.getElementById('l5Lang');
    var langBtn = document.getElementById('l5LangBtn');
    if (langBtn && lang) {
        langBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            lang.classList.toggle('is-open');
            langBtn.setAttribute('aria-expanded', lang.classList.contains('is-open'));
        });
        document.addEventListener('click', function () { lang.classList.remove('is-open'); });
    }

    var reveals = document.querySelectorAll('.l5-reveal');
    if (reveals.length && 'IntersectionObserver' in window) {
        var obs = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) {
                    en.target.classList.add('is-visible');
                    obs.unobserve(en.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -28px 0px' });
        reveals.forEach(function (el) { obs.observe(el); });
    } else {
        reveals.forEach(function (el) { el.classList.add('is-visible'); });
    }
})();

(function () {
    var banner = document.getElementById('cookieConsent');
    if (!banner) return;
    var consent = localStorage.getItem('cookie_consent');
    if (!consent) setTimeout(function () { banner.classList.add('is-visible'); }, 600);
    function acceptAll() {
        localStorage.setItem('cookie_consent', JSON.stringify({ necessary: true, analytics: true, marketing: true, timestamp: Date.now() }));
        banner.classList.remove('is-visible');
    }
    function rejectAll() {
        localStorage.setItem('cookie_consent', JSON.stringify({ necessary: true, analytics: false, marketing: false, timestamp: Date.now() }));
        banner.classList.remove('is-visible');
    }
    var a = document.getElementById('cookieAcceptBtn');
    var r = document.getElementById('cookieRejectBtn');
    var c = document.getElementById('cookieCustomizeBtn');
    var s = document.getElementById('cookieSaveBtn');
    if (a) a.addEventListener('click', acceptAll);
    if (r) r.addEventListener('click', rejectAll);
    if (c) c.addEventListener('click', function () {
        var p = document.getElementById('cookieCustomize');
        if (p) p.style.display = p.style.display === 'none' ? 'block' : 'none';
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
        banner.classList.remove('is-visible');
    });
    window.reopenCookieConsent = function () {
        localStorage.removeItem('cookie_consent');
        var p = document.getElementById('cookieCustomize');
        if (p) p.style.display = 'none';
        var ca = document.getElementById('cookieAnalytics');
        var cm = document.getElementById('cookieMarketing');
        if (ca) ca.checked = false;
        if (cm) cm.checked = false;
        banner.classList.add('is-visible');
    };
    var prefLink = document.getElementById('cookiePreferencesLink');
    if (prefLink) {
        prefLink.addEventListener('click', function (e) {
            e.preventDefault();
            window.reopenCookieConsent();
        });
    }
})();
