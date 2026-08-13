document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = this.getAttribute('data-copy');
            var icon = this.querySelector('i');
            navigator.clipboard.writeText(text).then(function () {
                btn.classList.add('copied');
                icon.className = 'bi bi-check-lg';
                setTimeout(function () {
                    btn.classList.remove('copied');
                    icon.className = 'bi bi-clipboard';
                }, 2000);
            });
        });
    });
});
