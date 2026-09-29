(function () {
    var header = document.getElementById('l6Header');
    if (header) {
        function onScroll() { header.classList.toggle('shadow-md', window.scrollY > 8); }
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }
    var drawer = document.getElementById('l6Drawer');
    var panel = document.getElementById('l6DrawerPanel');
    var openBtn = document.getElementById('l6OpenMenu');
    var closeBtn = document.getElementById('l6CloseMenu');
    function dt() { return document.documentElement.getAttribute('dir') === 'rtl' ? 'translateX(-100%)' : 'translateX(100%)'; }
    function openDrawer() {
        if (!drawer || !panel) return;
        panel.style.transform = dt();
        drawer.classList.remove('hidden');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(function () { panel.style.transform = 'translateX(0)'; });
        if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
    }
    function closeDrawer() {
        if (!drawer || !panel) return;
        panel.style.transform = dt();
        if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        setTimeout(function () { drawer.classList.add('hidden'); drawer.setAttribute('aria-hidden', 'true'); }, 280);
    }
    if (openBtn && drawer) openBtn.addEventListener('click', openDrawer);
    if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
    if (drawer) {
        drawer.querySelectorAll('[data-l6-drawer-backdrop], a').forEach(function (el) {
            el.addEventListener('click', function () { if (el.tagName === 'A' || el.hasAttribute('data-l6-drawer-backdrop')) closeDrawer(); });
        });
    }
    window.addEventListener('resize', function () { if (window.innerWidth >= 1024 && drawer && !drawer.classList.contains('hidden')) closeDrawer(); });
    var langBtn = document.getElementById('l6LangBtn');
    var langMenu = document.getElementById('l6LangMenu');
    if (langBtn && langMenu) {
        langBtn.addEventListener('click', function (e) { e.stopPropagation(); langMenu.classList.toggle('hidden'); });
        document.addEventListener('click', function () { langMenu.classList.add('hidden'); });
    }
    if (document.querySelector('.l6-price-tab')) {
        document.querySelectorAll('.l6-price-tab').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var cycle = btn.getAttribute('data-cycle');
                var wrap = btn.closest('.l6-price-tab-wrap') || btn.parentElement;
                if (!wrap) return;
                wrap.querySelectorAll('.l6-price-tab').forEach(function (b) {
                    b.classList.toggle('is-active', b.getAttribute('data-cycle') === cycle);
                });
                var hy = document.querySelector('.l6-plan-price[data-yearly]');
                document.querySelectorAll('.l6-plan-price[data-monthly]').forEach(function (el) {
                    el.classList.toggle('hidden', hy && cycle !== 'monthly');
                });
                document.querySelectorAll('.l6-plan-price[data-yearly]').forEach(function (el) {
                    el.classList.toggle('hidden', cycle !== 'yearly');
                });
            });
        });
    }
})();
