document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.swal-confirm-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var config = JSON.parse(this.getAttribute('data-swal-confirm'));
            var f = this;
            Swal.fire({
                title: config.title,
                text: config.text,
                icon: config.icon,
                showCancelButton: true,
                confirmButtonText: config.confirmButtonText,
                confirmButtonColor: config.confirmButtonColor,
            }).then(function(result) {
                if (result.isConfirmed) {
                    f.submit();
                }
            });
        });
    });
});
