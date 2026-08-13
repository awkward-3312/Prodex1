(function () {
    function handleApprove(btn) {
        var data = window.PendingRegData || {};
        var trans = data.trans || {};
        var company = btn.getAttribute('data-reg-company') || '';
        var url = btn.getAttribute('data-approve-url');

        Swal.fire({
            title: trans.approvePaymentTitle,
            text: (trans.approvePaymentText || '').replace(':company', company),
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: trans.approve,
            confirmButtonColor: '#10b981',
            cancelButtonText: trans.cancel,
        }).then(function (result) {
            if (!result.isConfirmed) return;

            Swal.fire({
                title: trans.approvingTitle,
                html: trans.approvingHtml,
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: function () { Swal.showLoading(); },
            });

            var formData = new FormData();
            formData.append('_token', data.csrfToken);

            fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': data.csrfToken,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            })
            .then(function (r) {
                return r.json().then(function (b) { return { ok: r.ok, body: b }; });
            })
            .then(function (res) {
                if (!res.ok || !res.body || !res.body.success) {
                    Swal.fire({
                        title: trans.requestFailed,
                        text: (res.body && res.body.message) || '',
                        icon: 'error',
                        confirmButtonColor: '#ef4444',
                    });
                    return;
                }

                Swal.fire({
                    title: trans.approvedTitle,
                    text: res.body.message || '',
                    icon: 'success',
                    confirmButtonColor: '#10b981',
                }).then(function () { location.reload(); });
            })
            .catch(function (err) {
                Swal.fire({
                    title: trans.requestFailed,
                    text: (err && err.message) || '',
                    icon: 'error',
                    confirmButtonColor: '#ef4444',
                });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.btn-approve-registration').forEach(function (btn) {
            btn.addEventListener('click', function () { handleApprove(this); });
        });
    });
})();
