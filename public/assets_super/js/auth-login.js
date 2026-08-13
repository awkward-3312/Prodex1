(function() {
    const form = document.getElementById('login_form');
    const submitBtn = document.getElementById('login_submit_btn');
    const showButtons = document.querySelectorAll('.toggle-password');

    showButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const target = document.getElementById(btn.dataset.target);
            const isHidden = target.type === 'password';
            target.type = isHidden ? 'text' : 'password';
            btn.textContent = isHidden ? 'Hide' : 'Show';
        });
    });

    if (!form) return;
    let submitted = false;
    form.addEventListener('submit', () => {
        if (submitted) return;
        submitted = true;
        submitBtn.disabled = true;
        submitBtn.classList.add('auth-is-loading');
    });
})();
