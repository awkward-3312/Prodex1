document.addEventListener('DOMContentLoaded', function() {
    var data = window.ProfileData || {};

    // Avatar auto-submit on file select
    var avatarInput = document.getElementById('avatarInput');
    if (avatarInput) {
        avatarInput.addEventListener('change', function() {
            document.getElementById('avatarForm').submit();
        });
    }

    document.querySelectorAll('.btn-pw-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var input = this.closest('.position-relative').querySelector('input');
            var icon = this.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'bi bi-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'bi bi-eye';
            }
        });
    });

    var pwInput = document.getElementById('newPasswordInput');
    var strengthWrap = document.getElementById('passwordStrength');
    var strengthFill = document.getElementById('strengthFill');
    var strengthText = document.getElementById('strengthText');

    if (pwInput) {
        pwInput.addEventListener('input', function() {
            var v = this.value;
            if (!v) { strengthWrap.style.display = 'none'; return; }
            strengthWrap.style.display = 'flex';

            var score = 0;
            if (v.length >= 8) score++;
            if (v.length >= 12) score++;
            if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
            if (/\d/.test(v)) score++;
            if (/[^a-zA-Z0-9]/.test(v)) score++;

            var levels = [
                { w: '20%',  bg: '#ef4444', t: data.strengthVeryWeak },
                { w: '40%',  bg: '#f59e0b', t: data.strengthWeak },
                { w: '60%',  bg: '#f59e0b', t: data.strengthFair },
                { w: '80%',  bg: '#10b981', t: data.strengthStrong },
                { w: '100%', bg: '#059669', t: data.strengthVeryStrong },
            ];
            var l = levels[Math.min(score, levels.length - 1)];
            strengthFill.style.width = l.w;
            strengthFill.style.background = l.bg;
            strengthText.textContent = l.t;
            strengthText.style.color = l.bg;
        });
    }
});
