document.addEventListener('DOMContentLoaded', function() {
    var gatewaySelect = document.getElementById('gatewaySelect');
    var sections = document.querySelectorAll('.gateway-section');

    function toggleSections() {
        sections.forEach(function(section) {
            section.style.display = section.id === 'gw-' + gatewaySelect.value ? '' : 'none';
        });
    }

    gatewaySelect.addEventListener('change', toggleSections);
    toggleSections();

    // Toggle secret visibility
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
