(function () {
    var header = document.getElementById('l4Header');
    if (header) {
        function onScroll() { header.classList.toggle('is-scrolled', window.scrollY > 10); }
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }
    var drawer = document.getElementById('l4Drawer');
    var openBtn = document.getElementById('l4OpenMenu');
    var closeBtn = document.getElementById('l4CloseMenu');
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

    var lang = document.getElementById('l4Lang');
    var langBtn = document.getElementById('l4LangBtn');
    if (langBtn && lang) {
        langBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            lang.classList.toggle('is-open');
            langBtn.setAttribute('aria-expanded', lang.classList.contains('is-open'));
        });
        document.addEventListener('click', function () { lang.classList.remove('is-open'); });
    }

    var reveals = document.querySelectorAll('.l4-reveal');
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
