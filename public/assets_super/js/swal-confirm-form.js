document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-swal-confirm]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            return swalConfirm(e, JSON.parse(this.dataset.swalConfirm));
        });
    });
});
