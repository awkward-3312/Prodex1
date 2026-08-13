document.addEventListener('DOMContentLoaded', function() {
    var data = window.PlansFormData || {};

    // Set unlimited buttons (event delegation)
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.limit-unlimited-btn');
        if (btn) {
            var input = btn.closest('.limit-input-wrap').querySelector('input');
            if (input.value === '-1' || input.value === '') {
                input.value = '';
                input.placeholder = data.unlimitedLabel;
            } else {
                input.value = -1;
            }
            input.focus();
        }
    });

    document.getElementById('isTrialToggle').addEventListener('change', function() {
        document.getElementById('trialDaysGroup').style.display = this.checked ? '' : 'none';
    });

    // Delete plan form swal confirm (edit page only)
    var deletePlanForm = document.getElementById('deletePlanForm');
    if (deletePlanForm) {
        deletePlanForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var config = JSON.parse(this.getAttribute('data-swal-confirm'));
            var form = this;
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
    }
});
