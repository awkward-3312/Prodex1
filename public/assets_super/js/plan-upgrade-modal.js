(function() {
    var dismissBtn = document.getElementById('upgrade-prompt-dismiss');
    if (dismissBtn) {
        dismissBtn.addEventListener('click', function() {
            var overlay = document.getElementById('upgrade-prompt-overlay');
            if (overlay) overlay.remove();
        });
    }
})();
