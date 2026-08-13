// Password toggle via event delegation
document.querySelectorAll('.toggle-password[data-target]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var fieldId = btn.getAttribute('data-target');
        var input = document.getElementById(fieldId);
        var wrapper = input.closest('.input-wrapper');
        var open = wrapper.querySelector('.eye-open');
        var closed = wrapper.querySelector('.eye-closed');

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
});

document.querySelector('form').addEventListener('submit', function () {
    var btn = document.getElementById('btn-submit');
    btn.disabled = true;
    btn.classList.add('is-loading');
});
