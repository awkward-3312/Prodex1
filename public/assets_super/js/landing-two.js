// Navbar scroll effect
(function() {
    var nav = document.querySelector('.navbar-landing');
    window.addEventListener('scroll', function() {
        nav.classList.toggle('scrolled', window.scrollY > 20);
    });
})();

// Mobile menu toggle
(function() {
    var menu = document.getElementById('navbarMain');
    var toggler = document.querySelector('.navbar-toggler');
    if (!menu || !toggler) return;

    var nav = document.getElementById('mainNav');
    function openMenu() {
        menu.classList.add('show');
        toggler.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
        menu.scrollTop = 0;
        nav.classList.remove('menu-scrolled');
    }
    function closeMenu() {
        menu.classList.remove('show');
        toggler.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        nav.classList.remove('menu-scrolled');
    }

    menu.addEventListener('scroll', function() {
        nav.classList.toggle('menu-scrolled', menu.scrollTop > 10);
    });

    toggler.addEventListener('click', function() {
        if (menu.classList.contains('show')) {
            closeMenu();
        } else {
            openMenu();
        }
    });

    menu.querySelectorAll('.nav-link').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth < 992) closeMenu();
        });
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth >= 992) closeMenu();
    });
})();

// Active nav link on scroll
(function() {
    var sections = document.querySelectorAll('section[id]');
    var navLinks = document.querySelectorAll('.navbar-landing .nav-link[href^="#"]');
    if (!sections.length || !navLinks.length) return;
    window.addEventListener('scroll', function() {
        var scrollY = window.scrollY + 100;
        sections.forEach(function(sec) {
            var top = sec.offsetTop, h = sec.offsetHeight, id = sec.getAttribute('id');
            if (scrollY >= top && scrollY < top + h) {
                navLinks.forEach(function(l) { l.classList.toggle('active', l.getAttribute('href') === '#' + id); });
            }
        });
    });
})();

// Scroll-triggered fade-in animations
(function() {
    var els = document.querySelectorAll('.fade-up');
    if (!els.length) return;
    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
    els.forEach(function(el) { observer.observe(el); });
})();

// Language switcher toggles
(function() {
    var desktopBtn = document.getElementById('langSwitcherBtn');
    var mobileBtn = document.getElementById('langSwitcherMobileBtn');
    if (desktopBtn) {
        desktopBtn.addEventListener('click', function() {
            document.getElementById('langSwitcher').classList.toggle('open');
        });
    }
    if (mobileBtn) {
        mobileBtn.addEventListener('click', function() {
            document.getElementById('langSwitcherMobile').classList.toggle('open');
        });
    }
})();

// Close language dropdown on click outside
document.addEventListener('click', function(e) {
    var sw = document.getElementById('langSwitcher');
    if (sw && !sw.contains(e.target)) sw.classList.remove('open');
    var swm = document.getElementById('langSwitcherMobile');
    if (swm && !swm.contains(e.target)) swm.classList.remove('open');
});

// Cookie consent
function initCookieConsent() {
    var banner = document.getElementById('cookieConsent');
    if (!banner) return;

    var consent = localStorage.getItem('cookie_consent');
    if (!consent) {
        setTimeout(function() { banner.classList.add('show'); }, 800);
    }

    function cookieAcceptAll() {
        var prefs = { necessary: true, analytics: true, marketing: true, timestamp: Date.now() };
        localStorage.setItem('cookie_consent', JSON.stringify(prefs));
        banner.classList.remove('show');
    }

    function cookieRejectAll() {
        var prefs = { necessary: true, analytics: false, marketing: false, timestamp: Date.now() };
        localStorage.setItem('cookie_consent', JSON.stringify(prefs));
        banner.classList.remove('show');
    }

    function cookieToggleCustomize() {
        var panel = document.getElementById('cookieCustomize');
        if (panel) panel.classList.toggle('show');
    }

    function cookieSavePreferences() {
        var analytics = document.getElementById('cookieAnalytics');
        var marketing = document.getElementById('cookieMarketing');
        var prefs = {
            necessary: true,
            analytics: analytics ? analytics.checked : false,
            marketing: marketing ? marketing.checked : false,
            timestamp: Date.now()
        };
        localStorage.setItem('cookie_consent', JSON.stringify(prefs));
        banner.classList.remove('show');
    }

    function reopenCookieConsent() {
        localStorage.removeItem('cookie_consent');
        var panel = document.getElementById('cookieCustomize');
        if (panel) panel.classList.remove('show');
        var analytics = document.getElementById('cookieAnalytics');
        var marketing = document.getElementById('cookieMarketing');
        if (analytics) analytics.checked = false;
        if (marketing) marketing.checked = false;
        banner.classList.add('show');
    }

    window.reopenCookieConsent = reopenCookieConsent;

    var bind = function(id, handler) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', handler);
    };
    bind('cookieAcceptBtn', cookieAcceptAll);
    bind('cookieRejectBtn', cookieRejectAll);
    bind('cookieCustomizeBtn', cookieToggleCustomize);
    bind('cookieSaveBtn', cookieSavePreferences);

    var prefLink = document.getElementById('cookiePreferencesLink');
    if (prefLink) {
        prefLink.addEventListener('click', function(e) {
            e.preventDefault();
            reopenCookieConsent();
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCookieConsent);
} else {
    initCookieConsent();
}
