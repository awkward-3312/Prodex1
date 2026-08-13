// Password toggle
document.getElementById('togglePasswordBtn').addEventListener('click', function() {
    var input = document.getElementById('password');
    var open  = document.getElementById('eye-open');
    var closed = document.getElementById('eye-closed');

    if (input.type === 'password') {
        input.type = 'text';
        open.style.display = 'none';
        closed.style.display = 'block';
    } else {
        input.type = 'password';
        open.style.display = 'block';
        closed.style.display = 'none';
    }
});

document.querySelector('form').addEventListener('submit', function () {
    var btn = document.getElementById('btn-login');
    btn.disabled = true;
    btn.classList.add('is-loading');
});
