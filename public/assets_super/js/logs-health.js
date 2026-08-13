document.addEventListener('DOMContentLoaded', function () {

    var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function toast(msg, type) {
        if (typeof Swal !== 'undefined') {
            var Toast = Swal.mixin({
                toast: true, position: 'top-end', showConfirmButton: false, timer: 3200,
            });
            Toast.fire({ icon: type || 'success', title: msg });
        } else {
            alert(msg);
        }
    }

    function confirmAction(text, confirmText) {
        if (typeof Swal === 'undefined') return Promise.resolve({ isConfirmed: confirm(text) });
        return Swal.fire({
            title: 'Are you sure?',
            text: text,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#018A00',
            cancelButtonColor: '#64748b',
            confirmButtonText: confirmText || 'Yes',
            reverseButtons: true,
        });
    }

    function postJson(url, body, method) {
        return fetch(url, {
            method: method || 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : null,
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                return { ok: r.ok, status: r.status, data: j };
            });
        });
    }

    // ── Refresh button ────────────────────────────────
    var btnRefresh = document.getElementById('btnRefreshPage');
    if (btnRefresh) {
        btnRefresh.addEventListener('click', function () {
            window.location.reload();
        });
    }

    // ── Clear resolved ────────────────────────────────
    var btnClear = document.getElementById('btnClearResolved');
    if (btnClear) {
        btnClear.addEventListener('click', function () {
            var url = btnClear.getAttribute('data-url');
            confirmAction('Delete all resolved logs? This cannot be undone.', 'Delete').then(function (res) {
                if (!res.isConfirmed) return;
                postJson(url).then(function (resp) {
                    if (resp.ok && resp.data.success) {
                        toast(resp.data.message || 'Resolved logs cleared.', 'success');
                        setTimeout(function () { window.location.reload(); }, 700);
                    } else {
                        toast((resp.data && resp.data.message) || 'Request failed.', 'error');
                    }
                });
            });
        });
    }

    // ── Inline row actions: resolve / reopen / delete / recheck ────
    document.querySelectorAll('.btn-log-action').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var action = btn.getAttribute('data-action');
            var url    = btn.getAttribute('data-url');

            if (action === 'delete') {
                confirmAction('Delete this log permanently?', 'Delete').then(function (res) {
                    if (!res.isConfirmed) return;
                    postJson(url, null, 'DELETE').then(function (resp) {
                        if (resp.ok && resp.data.success) {
                            toast(resp.data.message || 'Deleted.', 'success');
                            setTimeout(function () { window.location.reload(); }, 500);
                        } else {
                            toast((resp.data && resp.data.message) || 'Delete failed.', 'error');
                        }
                    });
                });
                return;
            }

            if (action === 'recheck') {
                btn.disabled = true;
                var originalHtml = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Checking…';
                postJson(url).then(function (resp) {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                    var status = resp.data && resp.data.status;
                    var msg    = (resp.data && resp.data.message) || 'Re-check complete.';
                    if (status === 'ok') {
                        toast(msg, 'success');
                        setTimeout(function () { window.location.reload(); }, 900);
                    } else if (status === 'fail') {
                        toast(msg, 'warning');
                    } else {
                        toast(msg, 'info');
                    }
                });
                return;
            }

            var confirmMsg = action === 'resolve'
                ? 'Mark this log as resolved?'
                : (action === 'reopen' ? 'Reopen this log?' : 'Confirm action?');
            var confirmBtn = action === 'resolve' ? 'Resolve' : (action === 'reopen' ? 'Reopen' : 'Yes');

            confirmAction(confirmMsg, confirmBtn).then(function (res) {
                if (!res.isConfirmed) return;
                postJson(url).then(function (resp) {
                    if (resp.ok && resp.data.success) {
                        toast(resp.data.message || 'Done.', 'success');
                        setTimeout(function () { window.location.reload(); }, 500);
                    } else {
                        toast((resp.data && resp.data.message) || 'Request failed.', 'error');
                    }
                });
            });
        });
    });

    // ── Detail page: resolve with note (opens modal) ──
    var btnResolveWithNote = document.getElementById('btnResolveWithNote');
    var btnConfirmResolve  = document.getElementById('btnConfirmResolve');
    var modalEl            = document.getElementById('resolveModal');
    var noteEl             = document.getElementById('resolveNote');
    var modalInstance      = null;

    if (btnResolveWithNote && modalEl && window.bootstrap) {
        // Hoist the modal to <body> so it escapes any ancestor stacking
        // context (e.g. .main-body / .main-content) that would otherwise keep
        // it beneath Bootstrap's body-level .modal-backdrop.
        if (modalEl.parentNode !== document.body) {
            document.body.appendChild(modalEl);
        }
        modalInstance = new bootstrap.Modal(modalEl);
        btnResolveWithNote.addEventListener('click', function () {
            if (noteEl) noteEl.value = '';
            modalInstance.show();
        });
    }
    if (btnConfirmResolve && btnResolveWithNote) {
        btnConfirmResolve.addEventListener('click', function () {
            var url  = btnResolveWithNote.getAttribute('data-url');
            var note = noteEl ? noteEl.value : '';
            btnConfirmResolve.disabled = true;
            postJson(url, { note: note }).then(function (resp) {
                btnConfirmResolve.disabled = false;
                if (resp.ok && resp.data.success) {
                    if (modalInstance) modalInstance.hide();
                    toast(resp.data.message || 'Resolved.', 'success');
                    setTimeout(function () { window.location.reload(); }, 600);
                } else {
                    toast((resp.data && resp.data.message) || 'Request failed.', 'error');
                }
            });
        });
    }

    // ── Row action dropdowns: escape overflow clipping ──
    // The logs table lives inside .table-responsive (overflow-x: auto) and
    // .table-wrap (overflow: hidden), which clip the default Popper-absolute
    // dropdown menu. Re-init with strategy: 'fixed' so the menu renders outside.
    if (window.bootstrap && window.bootstrap.Dropdown) {
        document.querySelectorAll('.logs-table [data-bs-toggle="dropdown"]').forEach(function (toggle) {
            var existing = bootstrap.Dropdown.getInstance(toggle);
            if (existing) existing.dispose();
            new bootstrap.Dropdown(toggle, {
                popperConfig: function (defaultConfig) {
                    return Object.assign({}, defaultConfig, { strategy: 'fixed' });
                }
            });
        });
    }

    // ── Auto-submit filter changes ─────────────────────
    var filterForm = document.getElementById('filterForm');
    if (filterForm) {
        filterForm.querySelectorAll('select, input[type="date"]').forEach(function (el) {
            el.addEventListener('change', function () { filterForm.submit(); });
        });
    }

});
