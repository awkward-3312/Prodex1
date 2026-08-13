var trans = window.TenantShowData.trans;
var csrfToken = window.TenantShowData.csrfToken;
var statusUrl = window.TenantShowData.statusUrl;
var testConnectionUrl = window.TenantShowData.testConnectionUrl;
var testConnectionPreUrl = window.TenantShowData.testConnectionPreUrl;
var createDatabasePreUrl = window.TenantShowData.createDatabasePreUrl;
var createDatabaseUrl = window.TenantShowData.createDatabaseUrl;

function swalAction(btn, opts) {
    var form = btn.closest('form');
    if (!form) return;

    var isApprove = form.id === 'approveForm';
    var isHeavy = form.id === 'form-reprovision' || form.id === 'form-reset-db' || form.id === 'form-rerun-migrations' || form.id === 'form-reseed' || isApprove;
    var isAsync = form.id === 'form-reprovision' || isApprove;

    Swal.fire({
        title: opts.title || '',
        text: opts.text || undefined,
        html: opts.html || undefined,
        icon: opts.icon || 'warning',
        showCancelButton: true,
        confirmButtonText: opts.confirmText || '',
        confirmButtonColor: opts.color || '#6366f1',
        cancelButtonText: opts.cancelText || trans.cancelText,
        cancelButtonColor: opts.cancelColor || '#64748b',
        reverseButtons: true,
    }).then(function(result) {
        if (result.isConfirmed) {
            if (isHeavy) {
                var heavyTitle = isApprove ? trans.approvingTitle : trans.processingTitle;
                Swal.fire({
                    title: heavyTitle,
                    html: isAsync ? trans.provisioningHtml : trans.processingHtml,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: function() { Swal.showLoading(); }
                });
            }

            if (isAsync) {
                var formData = new FormData(form);
                fetch(form.action, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken },
                    body: formData,
                    redirect: 'manual',
                }).then(function() {
                    pollProvisioningStatus();
                }).catch(function() {
                    pollProvisioningStatus();
                });
            } else {
                var submit = document.createElement('button');
                submit.type = 'submit';
                submit.style.display = 'none';
                form.appendChild(submit);
                submit.click();
                submit.remove();
            }
        }
    });
}

var pollTimer = null;
var POLL_MAX_SECONDS = 600;
function pollProvisioningStatus() {
    var seconds = 0;

    pollTimer = setInterval(function() {
        seconds += 3;
        var stepEl = document.getElementById('swal-step');
        if (stepEl) {
            var mins = Math.floor(seconds / 60);
            var secs = seconds % 60;
            stepEl.textContent = 'Elapsed: ' + (mins > 0 ? mins + 'm ' : '') + secs + 's';
        }

        if (seconds >= POLL_MAX_SECONDS) {
            clearInterval(pollTimer);
            Swal.fire({
                title: trans.provisioningTimedOut,
                text: trans.provisioningTimedOutText,
                icon: 'warning',
                confirmButtonColor: '#f59e0b',
            }).then(function() { location.reload(); });
            return;
        }

        fetch(statusUrl, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.status === 'active') {
                clearInterval(pollTimer);
                Swal.fire({
                    title: trans.successTitle,
                    text: trans.provisioningSuccessText,
                    icon: 'success',
                    confirmButtonColor: '#6366f1',
                }).then(function() { location.reload(); });
            } else if (data.status === 'failed') {
                clearInterval(pollTimer);
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

function testTenantConnection() {
    var resultDiv = document.getElementById('db-test-result');
    var btn = document.getElementById('testConnBtn');

    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<div class="ajax-result-info"><i class="bi bi-hourglass-split me-1"></i>' + trans.testingConnection + '</div>';
    btn.disabled = true;

    fetch(testConnectionUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json',
        },
        body: JSON.stringify({}),
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        if (res.success) {
            resultDiv.innerHTML = '<div class="ajax-result-success"><i class="bi bi-check-circle-fill me-1"></i>' + res.message + '</div>';
        } else if (res.db_not_found) {
            resultDiv.innerHTML = '<div style="padding:10px 14px; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; font-size:0.82rem; color:#92400e;">'
                + '<div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-database-exclamation" style="font-size:1rem;"></i><span>' + res.message + '</span></div>'
                + '<button type="button" class="btn btn-sm" style="background:#10b981; color:#fff; border:none; border-radius:6px; font-size:0.78rem; font-weight:600; padding:5px 14px;" id="btnCreateTenantDb">'
                + '<i class="bi bi-database-add me-1"></i>' + trans.createDatabase + '</button>'
                + '</div>';
        } else {
            resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + res.message + '</div>';
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>Request failed: ' + err.message + '</div>';
    });
}

function createTenantEffectiveDb() {
    var resultDiv = document.getElementById('db-test-result');
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<div class="ajax-result-info"><i class="bi bi-hourglass-split me-1"></i>' + (trans.creatingDatabase || 'Creating database...') + '</div>';

    fetch(createDatabaseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json',
        },
        body: JSON.stringify({}),
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            // Database created — re-run the connection test so the green
            // "Connection successful" UX appears.
            testTenantConnection();
        } else {
            resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + (res.message || '') + '</div>';
        }
    })
    .catch(function(err) {
        resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>Request failed: ' + err.message + '</div>';
    });
}

var editDbVerified = false;

function testFormConnection() {
    var form = document.getElementById('db-edit-form');
    var resultDiv = document.getElementById('db-edit-test-result');
    var btnSave = document.getElementById('btnSaveCreds');

    var data = {
        db_host: form.querySelector('[name=db_host]').value,
        db_name: form.querySelector('[name=db_name]').value,
        db_username: form.querySelector('[name=db_username]').value,
        db_password: form.querySelector('[name=db_password]').value,
        db_port: form.querySelector('[name=db_port]').value,
    };

    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<div class="ajax-result-info"><i class="bi bi-hourglass-split me-1"></i>' + trans.testingConnection + '</div>';

    fetch(testConnectionUrl, {
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
        if (res.success) {
            editDbVerified = true;
            if (btnSave) btnSave.classList.remove('approve-locked');
            resultDiv.innerHTML = '<div class="ajax-result-success"><i class="bi bi-check-circle-fill me-1"></i>' + res.message + '</div>';
        } else {
            editDbVerified = false;
            if (btnSave) btnSave.classList.add('approve-locked');
            resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + res.message + '</div>';
        }
    })
    .catch(function(err) {
        editDbVerified = false;
        if (btnSave) btnSave.classList.add('approve-locked');
        resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>Request failed: ' + err.message + '</div>';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var approveDbVerified = false;
    var btnApprove = document.getElementById('btnApprove');

    document.querySelectorAll('[data-swal-action]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var self = this;

            if (this.id === 'btnApprove') {
                if (!approveDbVerified) {
                    Swal.fire({
                        title: trans.testConnectionFirst,
                        text: trans.testConnectionFirstText,
                        icon: 'warning',
                        confirmButtonColor: '#f59e0b',
                    });
                    return;
                }

                // Pre-flight: re-verify the DB connection with current form values
                // right before provisioning, so the heavy provisioning modal never
                // opens against broken credentials.
                var approveForm = document.getElementById('approveForm');
                if (!approveForm) {
                    swalAction(self, JSON.parse(self.dataset.swalAction));
                    return;
                }

                var verifyPayload = {
                    db_host:     approveForm.querySelector('[name=db_host]').value.trim(),
                    db_name:     approveForm.querySelector('[name=db_name]').value.trim(),
                    db_username: approveForm.querySelector('[name=db_username]').value.trim(),
                    db_password: approveForm.querySelector('[name=db_password]').value,
                    db_port:     approveForm.querySelector('[name=db_port]').value.trim(),
                };

                Swal.fire({
                    title: trans.verifyingConnection,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: function() { Swal.showLoading(); },
                });

                fetch(testConnectionPreUrl, {
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
                    if (res.success) {
                        swalAction(self, JSON.parse(self.dataset.swalAction));
                        return;
                    }

                    approveDbVerified = false;
                    if (btnApprove) btnApprove.classList.add('approve-locked');
                    var resultDiv = document.getElementById('approve-db-test-result');

                    if (res.db_not_found) {
                        // DB missing but server creds are valid — render the same amber
                        // panel + Create Database button used by the initial test click.
                        if (resultDiv) {
                            resultDiv.style.display = 'block';
                            resultDiv.innerHTML = '<div style="padding:10px 14px; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; font-size:0.82rem; color:#92400e;">'
                                + '<div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-database-exclamation" style="font-size:1rem;"></i><span>' + res.message + '</span></div>'
                                + '<button type="button" class="btn btn-sm" style="background:#10b981; color:#fff; border:none; border-radius:6px; font-size:0.78rem; font-weight:600; padding:5px 14px;" id="btnCreateApproveDb">'
                                + '<i class="bi bi-database-add me-1"></i>' + trans.createDatabase + '</button>'
                                + '</div>';
                        }
                        // Offer the create action directly from the swal too.
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
                                var createBtn = document.getElementById('btnCreateApproveDb');
                                if (createBtn) createBtn.click();
                            }
                        });
                        return;
                    }

                    if (resultDiv) {
                        resultDiv.style.display = 'block';
                        resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + (res.message || '') + '</div>';
                    }
                    Swal.fire({
                        title: trans.connectionCheckFailed,
                        text: res.message || '',
                        icon: 'error',
                        confirmButtonColor: '#ef4444',
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
                return;
            }

            if (this.id === 'btnSaveCreds') {
                if (!editDbVerified) {
                    Swal.fire({
                        title: trans.testConnectionFirst,
                        text: trans.testBeforeSavingText,
                        icon: 'warning',
                        confirmButtonColor: '#f59e0b',
                    });
                    return;
                }

                // Pre-flight: re-verify the DB connection with current form values
                // right before saving credentials, so a stale "verified" state can't
                // push broken credentials onto an already-provisioned tenant.
                var credsForm = document.getElementById('form-update-creds');
                if (!credsForm) {
                    swalAction(self, JSON.parse(self.dataset.swalAction));
                    return;
                }

                var credsPayload = {
                    db_host:     credsForm.querySelector('[name=db_host]').value.trim(),
                    db_name:     credsForm.querySelector('[name=db_name]').value.trim(),
                    db_username: credsForm.querySelector('[name=db_username]').value.trim(),
                    db_password: credsForm.querySelector('[name=db_password]').value,
                    db_port:     credsForm.querySelector('[name=db_port]').value.trim(),
                };

                Swal.fire({
                    title: trans.verifyingConnection,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: function() { Swal.showLoading(); },
                });

                fetch(testConnectionUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(credsPayload),
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) {
                        editDbVerified = false;
                        var btnSave = document.getElementById('btnSaveCreds');
                        if (btnSave) btnSave.classList.add('approve-locked');
                        var resultDiv = document.getElementById('db-edit-test-result');
                        if (resultDiv) {
                            resultDiv.style.display = 'block';
                            resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + (res.message || '') + '</div>';
                        }
                        Swal.fire({
                            title: trans.connectionCheckFailed,
                            text: res.message || '',
                            icon: 'error',
                            confirmButtonColor: '#ef4444',
                        });
                        return;
                    }
                    swalAction(self, JSON.parse(self.dataset.swalAction));
                })
                .catch(function(err) {
                    Swal.fire({
                        title: trans.connectionCheckFailed,
                        text: trans.requestFailed + ': ' + (err && err.message ? err.message : ''),
                        icon: 'error',
                        confirmButtonColor: '#ef4444',
                    });
                });
                return;
            }
            swalAction(this, JSON.parse(this.dataset.swalAction));
        });
    });

    var testConnBtn = document.getElementById('testConnBtn');
    if (testConnBtn) {
        testConnBtn.addEventListener('click', function() {
            testTenantConnection();
        });
    }

    var btnToggleDbEdit = document.getElementById('btnToggleDbEdit');
    if (btnToggleDbEdit) {
        btnToggleDbEdit.addEventListener('click', function() {
            var el = document.getElementById('db-edit-form');
            el.style.display = el.style.display === 'none' ? 'block' : 'none';
        });
    }

    var btnToggleDbPassword = document.getElementById('btnToggleDbPassword');
    if (btnToggleDbPassword) {
        btnToggleDbPassword.addEventListener('click', function() {
            var f = document.getElementById('db-password-field');
            f.type = f.type === 'password' ? 'text' : 'password';
        });
    }

    var btnTestFormConnection = document.getElementById('btnTestFormConnection');
    if (btnTestFormConnection) {
        btnTestFormConnection.addEventListener('click', function() {
            testFormConnection();
        });
    }

    var dbEditForm = document.getElementById('form-update-creds');
    if (dbEditForm) {
        dbEditForm.querySelectorAll('input[name^="db_"]').forEach(function(field) {
            field.addEventListener('input', function() {
                editDbVerified = false;
                var btnSave = document.getElementById('btnSaveCreds');
                if (btnSave) btnSave.classList.add('approve-locked');
                var resultDiv = document.getElementById('db-edit-test-result');
                if (resultDiv) { resultDiv.style.display = 'none'; resultDiv.innerHTML = ''; }
            });
        });
    }

    var btnCancelDbEdit = document.getElementById('btnCancelDbEdit');
    if (btnCancelDbEdit) {
        btnCancelDbEdit.addEventListener('click', function() {
            document.getElementById('db-edit-form').style.display = 'none';
        });
    }

    var btnTestApproveDb = document.getElementById('btnTestApproveDb');

    if (btnTestApproveDb) {
        btnTestApproveDb.addEventListener('click', function() {
            var form = document.getElementById('approveForm');
            var resultDiv = document.getElementById('approve-db-test-result');

            var data = {
                db_host: form.querySelector('[name=db_host]').value.trim(),
                db_name: form.querySelector('[name=db_name]').value.trim(),
                db_username: form.querySelector('[name=db_username]').value.trim(),
                db_password: form.querySelector('[name=db_password]').value,
                db_port: form.querySelector('[name=db_port]').value.trim(),
            };

            if (!data.db_host || !data.db_name || !data.db_username || !data.db_port) {
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + trans.fillDbFields + '</div>';
                return;
            }

            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<div class="ajax-result-info"><i class="bi bi-hourglass-split me-1"></i>' + trans.testingConnection + '</div>';

            fetch(testConnectionPreUrl, {
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
                if (res.success) {
                    approveDbVerified = true;
                    if (btnApprove) btnApprove.classList.remove('approve-locked');
                    resultDiv.innerHTML = '<div class="ajax-result-success"><i class="bi bi-check-circle-fill me-1"></i>' + res.message + '</div>';
                } else if (res.db_not_found) {
                    approveDbVerified = false;
                    if (btnApprove) btnApprove.classList.add('approve-locked');
                    resultDiv.innerHTML = '<div style="padding:10px 14px; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; font-size:0.82rem; color:#92400e;">'
                        + '<div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-database-exclamation" style="font-size:1rem;"></i><span>' + res.message + '</span></div>'
                        + '<button type="button" class="btn btn-sm" style="background:#10b981; color:#fff; border:none; border-radius:6px; font-size:0.78rem; font-weight:600; padding:5px 14px;" id="btnCreateApproveDb">'
                        + '<i class="bi bi-database-add me-1"></i>' + trans.createDatabase + '</button>'
                        + '</div>';
                } else {
                    approveDbVerified = false;
                    if (btnApprove) btnApprove.classList.add('approve-locked');
                    resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + res.message + '</div>';
                }
            })
            .catch(function(err) {
                approveDbVerified = false;
                if (btnApprove) btnApprove.classList.add('approve-locked');
                resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>Request failed: ' + err.message + '</div>';
            });
        });

        document.querySelectorAll('.approve-db-field').forEach(function(field) {
            field.addEventListener('input', function() {
                approveDbVerified = false;
                if (btnApprove) btnApprove.classList.add('approve-locked');
                var resultDiv = document.getElementById('approve-db-test-result');
                if (resultDiv) {
                    resultDiv.style.display = 'none';
                    resultDiv.innerHTML = '';
                }
            });
        });
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('#btnCreateApproveDb');
        if (!btn) return;

        var form = document.getElementById('approveForm');
        var resultDiv = document.getElementById('approve-db-test-result');
        if (!form || !resultDiv) return;

        var data = {
            db_host: form.querySelector('[name=db_host]').value.trim(),
            db_name: form.querySelector('[name=db_name]').value.trim(),
            db_username: form.querySelector('[name=db_username]').value.trim(),
            db_password: form.querySelector('[name=db_password]').value,
            db_port: form.querySelector('[name=db_port]').value.trim(),
        };

        resultDiv.innerHTML = '<div class="ajax-result-info"><i class="bi bi-hourglass-split me-1"></i>Creating database...</div>';

        fetch(createDatabasePreUrl, {
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
            if (res.success) {
                // Database created — re-test the connection so the same
                // "Connection successful" UX (green message + button unlock) is shown.
                var testBtn = document.getElementById('btnTestApproveDb');
                if (testBtn) testBtn.click();
            } else {
                approveDbVerified = false;
                if (btnApprove) btnApprove.classList.add('approve-locked');
                resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + res.message + '</div>';
            }
        })
        .catch(function(err) {
            approveDbVerified = false;
            if (btnApprove) btnApprove.classList.add('approve-locked');
            resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>Request failed: ' + err.message + '</div>';
        });
    });

    // Delegated handler for the Create Database button shown in the top-level
    // "Test Connection" result when a provisioned tenant's DB is missing.
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('#btnCreateTenantDb');
        if (!btn) return;
        createTenantEffectiveDb();
    });

    // ── Custom Domain verification ─────────────────────────────
    document.querySelectorAll('.js-verify-custom-domain').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var url = this.dataset.url;
            var original = this.innerHTML;
            var self = this;
            self.disabled = true;
            self.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>' + (trans.customDomainVerifying || 'Verifying…');

            fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: '{}',
            })
            .then(function(r) { return r.json().then(function(data) { return { status: r.status, data: data }; }); })
            .then(function(res) {
                self.disabled = false;
                self.innerHTML = original;
                if (res.data && res.data.ok) {
                    Swal.fire({
                        icon: 'success',
                        title: trans.customDomainVerifiedTitle || 'Verified',
                        text: res.data.message || '',
                        confirmButtonColor: '#10b981',
                    }).then(function() { location.reload(); });
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: trans.customDomainFailedTitle || 'Verification failed',
                        text: (res.data && res.data.message) || 'DNS check failed.',
                        confirmButtonColor: '#f59e0b',
                    });
                }
            })
            .catch(function(err) {
                self.disabled = false;
                self.innerHTML = original;
                Swal.fire({
                    icon: 'error',
                    title: trans.requestFailed || 'Request failed',
                    text: err.message || '',
                    confirmButtonColor: '#ef4444',
                });
            });
        });
    });
});
