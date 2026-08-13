document.addEventListener('DOMContentLoaded', function() {
    var data = window.PaymentGatewaysData || {};

    // Tab switching
    document.querySelectorAll('.gateway-tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var gateway = this.dataset.gateway;
            document.querySelectorAll('.gateway-tab-btn').forEach(function(b) { b.classList.remove('active'); });
            document.querySelectorAll('.gateway-panel').forEach(function(p) { p.style.display = 'none'; });
            this.classList.add('active');
            document.getElementById('panel-' + gateway).style.display = '';
        });
    });

    // Mode toggle styling
    document.querySelectorAll('.gateway-mode-radio').forEach(function(radio) {
        radio.addEventListener('change', function() {
            var parent = this.closest('.card-body-custom');
            parent.querySelectorAll('.gateway-mode-option').forEach(function(opt) { opt.classList.remove('selected'); });
            this.closest('.gateway-mode-option').classList.add('selected');
        });
    });

    // Toggle secret field visibility
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

    // Active toggle label
    document.querySelectorAll('input[type="checkbox"][name*="is_active"]').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var label = this.nextElementSibling;
            if (label) label.textContent = this.checked ? data.activeLabel : data.inactiveLabel;
        });
    });
});
