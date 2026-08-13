document.addEventListener('DOMContentLoaded', function() {
    // Password toggle
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

    // Role toggle → show/hide permissions
    var roleSelect = document.getElementById('roleSelect');
    var permBlock = document.getElementById('permissionsBlock');

    function togglePerms() {
        permBlock.classList.toggle('permissions-block-hidden', roleSelect.value !== 'admin');
    }
    roleSelect.addEventListener('change', togglePerms);
    togglePerms();
});
