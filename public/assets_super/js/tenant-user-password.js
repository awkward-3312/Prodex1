(function () {
    var trans = window.TenantUserPasswordData && window.TenantUserPasswordData.trans ? window.TenantUserPasswordData.trans : {};
    var csrfToken = window.TenantUserPasswordData && window.TenantUserPasswordData.csrfToken ? window.TenantUserPasswordData.csrfToken : '';

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function submitPassword(url, password) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = url;
        form.style.display = 'none';

        var tokenInput = document.createElement('input');
        tokenInput.type = 'hidden';
        tokenInput.name = '_token';
        tokenInput.value = csrfToken;
        form.appendChild(tokenInput);

        var pwdInput = document.createElement('input');
        pwdInput.type = 'hidden';
        pwdInput.name = 'password';
        pwdInput.value = password;
        form.appendChild(pwdInput);

        var confirmInput = document.createElement('input');
        confirmInput.type = 'hidden';
        confirmInput.name = 'password_confirmation';
        confirmInput.value = password;
        form.appendChild(confirmInput);

        document.body.appendChild(form);
        form.submit();
    }

    function openDialog(btn) {
        var url = btn.getAttribute('data-change-password-url');
        var name = btn.getAttribute('data-user-name') || '';

        Swal.fire({
            title: trans.title || 'Change password',
            html:
                '<p class="mb-3 text-muted" style="font-size:0.9rem;">' +
                    (trans.subtitle || 'Set a new password for') + ' <strong>' + escapeHtml(name) + '</strong>' +
                '</p>' +
                '<input id="swal-password" type="password" class="swal2-input" placeholder="' + escapeHtml(trans.newPassword || 'New password') + '" autocomplete="new-password">' +
                '<input id="swal-password-confirm" type="password" class="swal2-input" placeholder="' + escapeHtml(trans.confirmPassword || 'Confirm password') + '" autocomplete="new-password">',
            focusConfirm: false,
            showCancelButton: true,
            confirmButtonText: trans.confirm || 'Change password',
            cancelButtonText: trans.cancel || 'Cancel',
            confirmButtonColor: '#6366f1',
            cancelButtonColor: '#64748b',
            reverseButtons: true,
            preConfirm: function () {
                var pwd = document.getElementById('swal-password').value;
                var confirm = document.getElementById('swal-password-confirm').value;

                if (!pwd || pwd.length < 8) {
                    Swal.showValidationMessage(trans.tooShort || 'Password must be at least 8 characters.');
                    return false;
                }
                if (pwd !== confirm) {
                    Swal.showValidationMessage(trans.mismatch || 'Passwords do not match.');
                    return false;
                }
                return pwd;
            },
        }).then(function (result) {
            if (result.isConfirmed && result.value) {
                submitPassword(url, result.value);
            }
        });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-change-password-url]');
        if (!btn) return;
        e.preventDefault();
        openDialog(btn);
    });
})();
