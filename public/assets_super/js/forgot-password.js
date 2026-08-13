document.querySelector('form').addEventListener('submit', function () {
    var btn = document.getElementById('btn-submit');
    btn.disabled = true;
    btn.classList.add('is-loading');
});
