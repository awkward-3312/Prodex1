document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-swal-confirm]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var config = JSON.parse(this.getAttribute('data-swal-confirm'));
            var form = this.closest('form');
            Swal.fire({
                title: config.title,
                text: config.text,
                icon: config.icon,
                showCancelButton: true,
                confirmButtonText: config.confirmButtonText,
                confirmButtonColor: config.confirmButtonColor,
            }).then(function(result) {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
});
