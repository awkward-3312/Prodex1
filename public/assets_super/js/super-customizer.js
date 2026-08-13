(function () {
    var DEFAULT_COLOR = '#018A00';
    var STORAGE_KEY = 'super_primary_color';

    var fab = document.getElementById('superCustomizerFab');
    var drawer = document.getElementById('superCustomizerDrawer');
    var backdrop = document.getElementById('superCustomizerBackdrop');
    var closeBtn = document.getElementById('superCustomizerClose');
    var swatchWrap = document.getElementById('superCustomizerSwatches');
    var colorInput = document.getElementById('superCustomizerColorInput');
    var hexInput = document.getElementById('superCustomizerHexInput');
    var resetBtn = document.getElementById('superCustomizerReset');

    if (!fab || !drawer || !backdrop) return;

    function isValidHex(v) { return /^#([0-9a-f]{6})$/i.test(v); }

    function hexToRgb(hex) {
        var clean = hex.replace('#', '');
        return {
            r: parseInt(clean.substring(0, 2), 16),
            g: parseInt(clean.substring(2, 4), 16),
            b: parseInt(clean.substring(4, 6), 16)
        };
    }

    function rgbToHex(r, g, b) {
        function pad(v) { var s = Math.max(0, Math.min(255, Math.round(v))).toString(16); return s.length === 1 ? '0' + s : s; }
        return '#' + pad(r) + pad(g) + pad(b);
    }

    function darken(rgb, amount) {
        return { r: rgb.r * (1 - amount), g: rgb.g * (1 - amount), b: rgb.b * (1 - amount) };
    }

    function applyColor(hex) {
        if (!isValidHex(hex)) return;
        var rgb = hexToRgb(hex);
        var darkRgb = darken(rgb, 0.15);
        var darkHex = rgbToHex(darkRgb.r, darkRgb.g, darkRgb.b);

        var css = ':root{'
            + '--color-primary:' + hex + ';'
            + '--color-primary-dark:' + darkHex + ';'
            + '--color-primary-light:rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',0.10);'
            + '--color-primary-lighter:rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',0.05);'
            + '--bs-link-color-rgb:' + rgb.r + ',' + rgb.g + ',' + rgb.b + ';'
            + '}'
            + 'body.dark-mode{'
            + '--color-primary:' + hex + ';'
            + '--color-primary-dark:' + darkHex + ';'
            + '--color-primary-light:rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',0.14);'
            + '--color-primary-lighter:rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',0.07);'
            + '--bs-link-color-rgb:' + rgb.r + ',' + rgb.g + ',' + rgb.b + ';'
            + '}';

        var styleEl = document.getElementById('super-customizer-vars');
        if (!styleEl) {
            styleEl = document.createElement('style');
            styleEl.id = 'super-customizer-vars';
            document.head.appendChild(styleEl);
        }
        styleEl.textContent = css;

        if (colorInput) colorInput.value = hex;
        if (hexInput) hexInput.value = hex.toUpperCase();
        syncSwatchActive(hex);
    }

    function syncSwatchActive(hex) {
        if (!swatchWrap) return;
        var swatches = swatchWrap.querySelectorAll('.super-customizer-swatch');
        swatches.forEach(function (sw) {
            if ((sw.getAttribute('data-color') || '').toLowerCase() === hex.toLowerCase()) {
                sw.classList.add('is-active');
            } else {
                sw.classList.remove('is-active');
            }
        });
    }

    function persist(hex) {
        try { localStorage.setItem(STORAGE_KEY, hex); } catch (e) {}
    }

    function openDrawer() {
        drawer.classList.add('is-open');
        backdrop.classList.add('is-open');
        fab.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
    }
    function closeDrawer() {
        drawer.classList.remove('is-open');
        backdrop.classList.remove('is-open');
        fab.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
    }

    var initial = DEFAULT_COLOR;
    try {
        var saved = localStorage.getItem(STORAGE_KEY);
        if (saved && isValidHex(saved)) initial = saved;
    } catch (e) {}
    applyColor(initial);

    fab.addEventListener('click', function () {
        if (drawer.classList.contains('is-open')) closeDrawer(); else openDrawer();
    });
    closeBtn.addEventListener('click', closeDrawer);
    backdrop.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && drawer.classList.contains('is-open')) closeDrawer();
    });

    if (swatchWrap) {
        swatchWrap.addEventListener('click', function (e) {
            var btn = e.target.closest('.super-customizer-swatch');
            if (!btn) return;
            var hex = btn.getAttribute('data-color');
            if (!hex) return;
            applyColor(hex);
            persist(hex);
        });
    }

    if (colorInput) {
        colorInput.addEventListener('input', function () {
            var hex = colorInput.value;
            if (!isValidHex(hex)) return;
            applyColor(hex);
            persist(hex);
        });
    }

    if (hexInput) {
        hexInput.addEventListener('input', function () {
            var raw = hexInput.value.trim();
            if (raw && raw[0] !== '#') raw = '#' + raw;
            if (isValidHex(raw)) {
                applyColor(raw);
                persist(raw);
            }
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
            applyColor(DEFAULT_COLOR);
        });
    }
})();
