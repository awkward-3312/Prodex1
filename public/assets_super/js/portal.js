setTimeout(function() {
    var app = document.getElementById('portal-app');
    if (app && app.querySelector('.portal-loading') && app.querySelector('.portal-loading').textContent.indexOf('Loading') !== -1) {
        var fallback = document.getElementById('portal-fallback');
        if (fallback) fallback.style.display = 'block';
    }
}, 4000);
