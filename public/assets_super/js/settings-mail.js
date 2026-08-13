document.addEventListener('DOMContentLoaded', function() {
    var mailerSelect = document.getElementById('mailerSelect');
    var smtpSettings = document.getElementById('smtpSettings');

    function toggleSmtp() {
        smtpSettings.style.display = mailerSelect.value === 'smtp' ? '' : 'none';
    }

    mailerSelect.addEventListener('change', toggleSmtp);
    toggleSmtp();

    // Encryption radio styling
    document.querySelectorAll('.encryption-radio').forEach(function(radio) {
        radio.addEventListener('change', function() {
            document.querySelectorAll('.encryption-option').forEach(function(opt) { opt.classList.remove('selected'); });
            this.closest('.encryption-option').classList.add('selected');
        });
    });

    // Toggle password visibility
    document.querySelectorAll('.btn-toggle-secret').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var input = this.previousElementSibling;
            var icon = this.querySelector('i');
            if (input.type === 'password') {
                if (input.value === '••••••••') return;
                input.type = 'text';
                icon.className = 'bi bi-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'bi bi-eye';
            }
        });
    });

    // Clear placeholder on focus for secret fields
    document.querySelectorAll('.secret-field').forEach(function(input) {
        input.addEventListener('focus', function() {
            if (this.value === '••••••••') {
                this.value = '';
                this.type = 'text';
            }
        });
    });
});
