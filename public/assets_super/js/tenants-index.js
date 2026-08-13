var trans = window.TenantsIndexData.trans;
var csrfToken = window.TenantsIndexData.csrfToken;
var tenantsBaseUrl = window.TenantsIndexData.tenantsBaseUrl;
var verifiedTenants = {};
var verifiedCreds = {};

function toggleDbForm(tenantId) {
    var row = document.getElementById('db-form-' + tenantId);
    row.style.display = row.style.display === 'table-row' ? 'none' : 'table-row';
}

function testDbConnection(tenantId) {
    var form = document.getElementById('approve-form-' + tenantId) || document.getElementById('creds-form-' + tenantId);
    var resultDiv = document.getElementById('db-test-result-' + tenantId);

    var data = {
        db_host: form.querySelector('[name=db_host]').value.trim(),
        db_name: form.querySelector('[name=db_name]').value.trim(),
        db_username: form.querySelector('[name=db_username]').value.trim(),
        db_password: form.querySelector('[name=db_password]').value,
        db_port: form.querySelector('[name=db_port]').value.trim(),
    };

    resultDiv.style.display = 'block';

    if (!data.db_host || !data.db_name || !data.db_username || !data.db_port) {
        resultDiv.innerHTML = '<div style="padding:8px 12px; background:#fef2f2; border-radius:6px; font-size:0.82rem; color:#991b1b;"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + trans.fillDbFields + '</div>';
        return;
    }

    resultDiv.innerHTML = '<div style="padding:8px 12px; background:#e0e7ff; border-radius:6px; font-size:0.82rem; color:#4338ca;"><i class="bi bi-hourglass-split me-1"></i>' + trans.testingConnection + '</div>';

    fetch(tenantsBaseUrl + '/' + tenantId + '/test-connection', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json',
        },
        body: JSON.stringify(data),
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        var approveBtn = document.querySelector('[data-confirm-approve="' + tenantId + '"]');
        var saveBtn = document.querySelector('[data-swal-creds-save="' + tenantId + '"]');
        var isCredsForm = !!document.getElementById('creds-form-' + tenantId);
        if (res.success) {
            verifiedTenants[tenantId] = true;
            if (approveBtn) approveBtn.classList.remove('approve-locked');
            if (isCredsForm) { verifiedCreds[tenantId] = true; if (saveBtn) saveBtn.classList.remove('approve-locked'); }
            resultDiv.innerHTML = '<div style="padding:8px 12px; background:#ecfdf5; border-radius:6px; font-size:0.82rem; color:#065f46;"><i class="bi bi-check-circle-fill me-1"></i>' + res.message + '</div>';
        } else if (res.db_not_found) {
            verifiedTenants[tenantId] = false;
            if (approveBtn) approveBtn.classList.add('approve-locked');
            if (isCredsForm) { verifiedCreds[tenantId] = false; if (saveBtn) saveBtn.classList.add('approve-locked'); }
            resultDiv.innerHTML = '<div style="padding:10px 14px; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; font-size:0.82rem; color:#92400e;">'
                + '<div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-database-exclamation" style="font-size:1rem;"></i><span>' + res.message + '</span></div>'
                + '<button type="button" class="btn btn-sm" style="background:#10b981; color:#fff; border:none; border-radius:6px; font-size:0.78rem; font-weight:600; padding:5px 14px;" data-create-db="' + tenantId + '">'
                + '<i class="bi bi-database-add me-1"></i>' + trans.createDatabase + '</button>'
                + '</div>';
        } else {
            verifiedTenants[tenantId] = false;
            if (approveBtn) approveBtn.classList.add('approve-locked');
            if (isCredsForm) { verifiedCreds[tenantId] = false; if (saveBtn) saveBtn.classList.add('approve-locked'); }
            resultDiv.innerHTML = '<div style="padding:8px 12px; background:#fef2f2; border-radius:6px; font-size:0.82rem; color:#991b1b;"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + res.message + '</div>';
        }
    })
    .catch(function(err) {
        verifiedTenants[tenantId] = false;
        verifiedCreds[tenantId] = false;
        var approveBtn = document.querySelector('[data-confirm-approve="' + tenantId + '"]');
        if (approveBtn) approveBtn.classList.add('approve-locked');
        var saveBtn = document.querySelector('[data-swal-creds-save="' + tenantId + '"]');
        if (saveBtn) saveBtn.classList.add('approve-locked');
        resultDiv.innerHTML = '<div style="padding:8px 12px; background:#fef2f2; border-radius:6px; font-size:0.82rem; color:#991b1b;"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + trans.requestFailed + ': ' + err.message + '</div>';
    });
}

function createTenantDatabase(tenantId) {
    var form = document.getElementById('approve-form-' + tenantId) || document.getElementById('creds-form-' + tenantId);
    var resultDiv = document.getElementById('db-test-result-' + tenantId);

    var data = {
        db_host: form.querySelector('[name=db_host]').value,
        db_name: form.querySelector('[name=db_name]').value,
        db_username: form.querySelector('[name=db_username]').value,
        db_password: form.querySelector('[name=db_password]').value,
        db_port: form.querySelector('[name=db_port]').value,
    };

    resultDiv.innerHTML = '<div style="padding:8px 12px; background:#e0e7ff; border-radius:6px; font-size:0.82rem; color:#4338ca;"><i class="bi bi-hourglass-split me-1"></i>' + trans.creatingDatabase + '</div>';

    fetch(tenantsBaseUrl + '/' + tenantId + '/create-database', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json',
        },
        body: JSON.stringify(data),
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        var approveBtn = document.querySelector('[data-confirm-approve="' + tenantId + '"]');
        if (res.success) {
            // Database created — re-test the connection so the same
            // "Connection successful" UX (green message + button unlock) is shown.
            testDbConnection(tenantId);
        } else {
            verifiedTenants[tenantId] = false;
            if (approveBtn) approveBtn.classList.add('approve-locked');
            resultDiv.innerHTML = '<div style="padding:8px 12px; background:#fef2f2; border-radius:6px; font-size:0.82rem; color:#991b1b;"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + res.message + '</div>';
        }
    })
    .catch(function(err) {
        verifiedTenants[tenantId] = false;
        var approveBtn = document.querySelector('[data-confirm-approve="' + tenantId + '"]');
        if (approveBtn) approveBtn.classList.add('approve-locked');
        resultDiv.innerHTML = '<div style="padding:8px 12px; background:#fef2f2; border-radius:6px; font-size:0.82rem; color:#991b1b;"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + trans.requestFailed + ': ' + err.message + '</div>';
    });
}

function swalFormSubmit(btn, opts) {
    var form = btn.closest('form');
    if (!form) return;
    Swal.fire({
        title: opts.title || '',
        text: opts.text || undefined,
        html: opts.html || undefined,
        icon: opts.icon || 'warning',
        showCancelButton: true,
        confirmButtonText: opts.confirmText || '',
        confirmButtonColor: opts.color || '#6366f1',
        cancelButtonText: trans.cancelText,
        cancelButtonColor: '#64748b',
        reverseButtons: true,
    }).then(function(result) {
        if (result.isConfirmed) {
            var submit = document.createElement('button');
            submit.type = 'submit';
            submit.style.display = 'none';
            form.appendChild(submit);
            submit.click();
            submit.remove();
        }
    });
}

function confirmApprove(tenantId) {
    if (!verifiedTenants[tenantId]) {
        Swal.fire({
            title: trans.testConnectionFirst,
            text: trans.testConnectionFirstText,
            icon: 'warning',
            confirmButtonColor: '#f59e0b',
        });
        return;
    }
    var form = document.getElementById('approve-form-' + tenantId);
    if (!form) { console.error('Form not found: approve-form-' + tenantId); return; }
    var host = form.querySelector('[name=db_host]').value.trim();
    var name = form.querySelector('[name=db_name]').value.trim();
    if (!host || !name) return;

    // Re-verify the DB connection with the current form values right before provisioning,
    // so the heavy provisioning modal never opens against broken credentials.
    Swal.fire({
        title: trans.verifyingConnection,
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: function() { Swal.showLoading(); },
    });

    var verifyPayload = {
        db_host:     host,
        db_name:     name,
        db_username: form.querySelector('[name=db_username]').value.trim(),
        db_password: form.querySelector('[name=db_password]').value,
        db_port:     form.querySelector('[name=db_port]').value.trim(),
    };

    fetch(tenantsBaseUrl + '/' + tenantId + '/test-connection', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json',
        },
        body: JSON.stringify(verifyPayload),
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        var approveBtn = document.querySelector('[data-confirm-approve="' + tenantId + '"]');
        var resultDiv = document.getElementById('db-test-result-' + tenantId);

        if (!res.success) {
            verifiedTenants[tenantId] = false;
            if (approveBtn) approveBtn.classList.add('approve-locked');

            if (res.db_not_found) {
                // DB missing but server creds are valid — render the same amber
                // panel + Create Database button used by the initial test click.
                if (resultDiv) {
                    resultDiv.style.display = 'block';
                    resultDiv.innerHTML = '<div style="padding:10px 14px; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; font-size:0.82rem; color:#92400e;">'
                        + '<div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-database-exclamation" style="font-size:1rem;"></i><span>' + res.message + '</span></div>'
                        + '<button type="button" class="btn btn-sm" style="background:#10b981; color:#fff; border:none; border-radius:6px; font-size:0.78rem; font-weight:600; padding:5px 14px;" data-create-db="' + tenantId + '">'
                        + '<i class="bi bi-database-add me-1"></i>' + trans.createDatabase + '</button>'
                        + '</div>';
                }
                Swal.fire({
                    title: trans.connectionCheckFailed,
                    text: res.message || '',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: trans.createDatabase,
                    confirmButtonColor: '#10b981',
                    cancelButtonText: trans.cancelText,
                }).then(function(result) {
                    if (result.isConfirmed) {
                        createTenantDatabase(tenantId);
                    }
                });
                return;
            }

            if (resultDiv) {
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '<div style="padding:8px 12px; background:#fef2f2; border-radius:6px; font-size:0.82rem; color:#991b1b;"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + (res.message || '') + '</div>';
            }
            Swal.fire({
                title: trans.connectionCheckFailed,
                text: res.message || '',
                icon: 'error',
                confirmButtonColor: '#ef4444',
            });
            return;
        }

        // Connection verified — proceed to the approve confirmation + provisioning flow.
        Swal.fire({
            title: trans.approveTenantTitle,
            html: trans.approveTenantHtml.replace(':name', name).replace(':host', host),
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: trans.yesApprove,
            confirmButtonColor: '#10b981',
            cancelButtonText: trans.cancelText,
        }).then(function(result) {
            if (!result.isConfirmed) return;

            Swal.fire({
                title: trans.provisioningTitle,
                html: trans.provisioningHtml,
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: function() { Swal.showLoading(); },
            });

            var formData = new FormData(form);
            fetch(form.action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                body: formData,
                redirect: 'manual',
            }).then(function() {
                pollApproveStatus(tenantId);
            }).catch(function() {
                pollApproveStatus(tenantId);
            });
        });
    })
    .catch(function(err) {
        Swal.fire({
            title: trans.connectionCheckFailed,
            text: trans.requestFailed + ': ' + (err && err.message ? err.message : ''),
            icon: 'error',
            confirmButtonColor: '#ef4444',
        });
    });
}

function pollApproveStatus(tenantId) {
    var statusUrl = tenantsBaseUrl + '/' + tenantId + '/provisioning-status';
    var seconds = 0;

    var timer = setInterval(function() {
        seconds += 3;
        var stepEl = document.getElementById('swal-step');
        if (stepEl) {
            var mins = Math.floor(seconds / 60);
            var secs = seconds % 60;
            stepEl.textContent = 'Elapsed: ' + (mins > 0 ? mins + 'm ' : '') + secs + 's';
        }

        fetch(statusUrl, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.status === 'active') {
                clearInterval(timer);
                Swal.fire({
                    title: trans.tenantApprovedTitle,
                    text: trans.tenantApprovedText,
                    icon: 'success',
                    confirmButtonColor: '#10b981',
                }).then(function() { location.reload(); });
            } else if (data.status === 'failed') {
                clearInterval(timer);
                Swal.fire({
                    title: trans.provisioningFailedTitle,
                    text: data.error || '',
                    icon: 'error',
                    confirmButtonColor: '#ef4444',
                }).then(function() { location.reload(); });
            }
        })
        .catch(function() { /* keep polling */ });
    }, 3000);
}

function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-reject-trigger').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var tenantId = this.getAttribute('data-tenant-id');
            var tenantName = this.getAttribute('data-tenant-name');
            document.getElementById('rejectModalTenantName').textContent = trans.rejectRegistrationFor.replace(':name', tenantName);
            document.getElementById('rejectForm').action = tenantsBaseUrl + '/' + tenantId + '/reject';
            document.getElementById('rejectModal').style.display = 'flex';
        });
    });

    var modal = document.getElementById('rejectModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeRejectModal();
        });
    }

    var btnRefresh = document.getElementById('btnRefreshPage');
    if (btnRefresh) {
        btnRefresh.addEventListener('click', function() {
            window.location.reload();
        });
    }

    var statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        statusFilter.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
    }

    document.querySelectorAll('[data-toggle-db]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            toggleDbForm(this.dataset.toggleDb);
        });
    });

    document.querySelectorAll('[data-test-db]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            testDbConnection(this.dataset.testDb);
        });
    });

    document.querySelectorAll('[data-confirm-approve]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            confirmApprove(this.dataset.confirmApprove);
        });
    });

    document.querySelectorAll('form[id^="approve-form-"]').forEach(function(form) {
        var tenantId = form.id.replace('approve-form-', '');
        form.querySelectorAll('.db-form-input').forEach(function(field) {
            field.addEventListener('input', function() {
                verifiedTenants[tenantId] = false;
                var approveBtn = document.querySelector('[data-confirm-approve="' + tenantId + '"]');
                if (approveBtn) approveBtn.classList.add('approve-locked');
                var resultDiv = document.getElementById('db-test-result-' + tenantId);
                if (resultDiv) { resultDiv.style.display = 'none'; resultDiv.innerHTML = ''; }
            });
        });
    });

    document.querySelectorAll('[data-toggle-pwd]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var f = document.getElementById(this.dataset.togglePwd);
            f.type = f.type === 'password' ? 'text' : 'password';
        });
    });

    document.querySelectorAll('[data-swal-creds-save]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var tid = this.dataset.swalCredsSave;
            if (!verifiedCreds[tid]) {
                Swal.fire({
                    title: trans.testConnectionFirst,
                    text: trans.testBeforeSavingText,
                    icon: 'warning',
                    confirmButtonColor: '#f59e0b',
                });
                return;
            }
            swalFormSubmit(this, JSON.parse(this.dataset.swalOpts));
        });
    });

    document.querySelectorAll('form[id^="creds-form-"]').forEach(function(form) {
        var tenantId = form.id.replace('creds-form-', '');
        form.querySelectorAll('.db-form-input').forEach(function(field) {
            field.addEventListener('input', function() {
                verifiedCreds[tenantId] = false;
                var saveBtn = document.querySelector('[data-swal-creds-save="' + tenantId + '"]');
                if (saveBtn) saveBtn.classList.add('approve-locked');
                var resultDiv = document.getElementById('db-test-result-' + tenantId);
                if (resultDiv) { resultDiv.style.display = 'none'; resultDiv.innerHTML = ''; }
            });
        });
    });

    var btnCloseReject = document.getElementById('btnCloseRejectModal');
    if (btnCloseReject) {
        btnCloseReject.addEventListener('click', function() {
            closeRejectModal();
        });
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-create-db]');
        if (btn) {
            createTenantDatabase(btn.dataset.createDb);
        }
    });
});
