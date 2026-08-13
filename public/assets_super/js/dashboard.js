var verifiedTenants = {};

// Toggle DB configuration form
function toggleDbForm(tenantId) {
    var row = document.getElementById('db-form-' + tenantId);
    row.style.display = row.style.display === 'table-row' ? 'none' : 'table-row';
}

// Test DB connection via AJAX
function testDbConnection(tenantId) {
    var data = window.DashboardData || {};
    var trans = data.trans || {};
    var form = document.getElementById('approve-form-' + tenantId);
    var resultDiv = document.getElementById('db-test-result-' + tenantId);

    var payload = {
        db_host:     form.querySelector('[name=db_host]').value.trim(),
        db_name:     form.querySelector('[name=db_name]').value.trim(),
        db_username: form.querySelector('[name=db_username]').value.trim(),
        db_password: form.querySelector('[name=db_password]').value,
        db_port:     form.querySelector('[name=db_port]').value.trim(),
    };

    resultDiv.style.display = 'block';

    if (!payload.db_host || !payload.db_name || !payload.db_username || !payload.db_port) {
        resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + trans.fillDbFields + '</div>';
        return;
    }

    resultDiv.innerHTML = '<div class="ajax-result-info"><i class="bi bi-hourglass-split me-1"></i>' + trans.testingConnection + '</div>';

    fetch(data.tenantsUrl + '/' + tenantId + '/test-connection', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': data.csrfToken,
            'Accept': 'application/json',
        },
        body: JSON.stringify(payload),
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        var approveBtn = document.querySelector('[data-confirm-approve="' + tenantId + '"]');
        if (res.success) {
            verifiedTenants[tenantId] = true;
            if (approveBtn) approveBtn.classList.remove('approve-locked');
            resultDiv.innerHTML = '<div class="ajax-result-success"><i class="bi bi-check-circle-fill me-1"></i>' + res.message + '</div>';
        } else if (res.db_not_found) {
            verifiedTenants[tenantId] = false;
            if (approveBtn) approveBtn.classList.add('approve-locked');
            resultDiv.innerHTML = '<div style="padding:10px 14px; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; font-size:0.82rem; color:#92400e;">'
                + '<div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-database-exclamation" style="font-size:1rem;"></i><span>' + res.message + '</span></div>'
                + '<button type="button" class="btn btn-sm" style="background:#10b981; color:#fff; border:none; border-radius:6px; font-size:0.78rem; font-weight:600; padding:5px 14px;" data-create-db="' + tenantId + '">'
                + '<i class="bi bi-database-add me-1"></i>' + trans.createDatabase + '</button>'
                + '</div>';
        } else {
            verifiedTenants[tenantId] = false;
            if (approveBtn) approveBtn.classList.add('approve-locked');
            resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + res.message + '</div>';
        }
    })
    .catch(function(err) {
        verifiedTenants[tenantId] = false;
        var approveBtn = document.querySelector('[data-confirm-approve="' + tenantId + '"]');
        if (approveBtn) approveBtn.classList.add('approve-locked');
        resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + trans.requestFailed + ': ' + err.message + '</div>';
    });
}

function confirmApprove(tenantId) {
    var data = window.DashboardData || {};
    var trans = data.trans || {};
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

    fetch(data.tenantsUrl + '/' + tenantId + '/test-connection', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': data.csrfToken,
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
                headers: { 'X-CSRF-TOKEN': data.csrfToken },
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
    var data = window.DashboardData || {};
    var trans = data.trans || {};
    var statusUrl = data.tenantsUrl + '/' + tenantId + '/provisioning-status';
    var seconds = 0;

    var timer = setInterval(function() {
        seconds += 3;
        var stepEl = document.getElementById('swal-step');
        if (stepEl) {
            var mins = Math.floor(seconds / 60);
            var secs = seconds % 60;
            stepEl.textContent = 'Elapsed: ' + (mins > 0 ? mins + 'm ' : '') + secs + 's';
        }

        if (seconds >= 600) {
            clearInterval(timer);
            Swal.fire({
                title: trans.provisioningFailedTitle,
                text: trans.provisioningTimedOutText,
                icon: 'warning',
                confirmButtonColor: '#f59e0b',
            }).then(function() { location.reload(); });
            return;
        }

        fetch(statusUrl, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': data.csrfToken }
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.status === 'active') {
                clearInterval(timer);
                Swal.fire({
                    title: trans.tenantApprovedTitle,
                    text: trans.tenantApprovedText,
                    icon: 'success',
                    confirmButtonColor: '#10b981',
                }).then(function() { location.reload(); });
            } else if (res.status === 'failed') {
                clearInterval(timer);
                Swal.fire({
                    title: trans.provisioningFailedTitle,
                    text: res.error || '',
                    icon: 'error',
                    confirmButtonColor: '#ef4444',
                }).then(function() { location.reload(); });
            }
        })
        .catch(function() { /* keep polling */ });
    }, 3000);
}

function createTenantDatabase(tenantId) {
    var data = window.DashboardData || {};
    var trans = data.trans || {};
    var form = document.getElementById('approve-form-' + tenantId);
    var resultDiv = document.getElementById('db-test-result-' + tenantId);

    var payload = {
        db_host:     form.querySelector('[name=db_host]').value,
        db_name:     form.querySelector('[name=db_name]').value,
        db_username: form.querySelector('[name=db_username]').value,
        db_password: form.querySelector('[name=db_password]').value,
        db_port:     form.querySelector('[name=db_port]').value,
    };

    resultDiv.innerHTML = '<div class="ajax-result-info"><i class="bi bi-hourglass-split me-1"></i>' + trans.creatingDatabase + '</div>';

    fetch(data.tenantsUrl + '/' + tenantId + '/create-database', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': data.csrfToken,
            'Accept': 'application/json',
        },
        body: JSON.stringify(payload),
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
            resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + res.message + '</div>';
        }
    })
    .catch(function(err) {
        var approveBtn = document.querySelector('[data-confirm-approve="' + tenantId + '"]');
        verifiedTenants[tenantId] = false;
        if (approveBtn) approveBtn.classList.add('approve-locked');
        resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + trans.requestFailed + ': ' + err.message + '</div>';
    });
}

// Reject modal logic
function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    var data = window.DashboardData || {};
    var trans = data.trans || {};

    // Toggle DB form buttons (event delegation)
    document.addEventListener('click', function(e) {
        var toggleBtn = e.target.closest('[data-toggle-db]');
        if (toggleBtn) {
            toggleDbForm(toggleBtn.getAttribute('data-toggle-db'));
        }
        var testBtn = e.target.closest('[data-test-db]');
        if (testBtn) {
            testDbConnection(testBtn.getAttribute('data-test-db'));
        }
        var approveBtn = e.target.closest('[data-confirm-approve]');
        if (approveBtn) {
            confirmApprove(approveBtn.getAttribute('data-confirm-approve'));
        }
        var createDbBtn = e.target.closest('[data-create-db]');
        if (createDbBtn) {
            createTenantDatabase(createDbBtn.getAttribute('data-create-db'));
        }
    });

    // Re-lock approve button when DB fields change
    document.querySelectorAll('form[id^="approve-form-"]').forEach(function(form) {
        var tenantId = form.id.replace('approve-form-', '');
        form.querySelectorAll('input[name^="db_"]').forEach(function(field) {
            field.addEventListener('input', function() {
                verifiedTenants[tenantId] = false;
                var approveBtn = document.querySelector('[data-confirm-approve="' + tenantId + '"]');
                if (approveBtn) approveBtn.classList.add('approve-locked');
                var resultDiv = document.getElementById('db-test-result-' + tenantId);
                if (resultDiv) { resultDiv.style.display = 'none'; resultDiv.innerHTML = ''; }
            });
        });
    });

    // Close reject modal button
    var closeRejectBtn = document.getElementById('closeRejectModalBtn');
    if (closeRejectBtn) {
        closeRejectBtn.addEventListener('click', function() {
            closeRejectModal();
        });
    }

    document.querySelectorAll('.btn-reject-trigger').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var tenantId = this.getAttribute('data-tenant-id');
            var tenantName = this.getAttribute('data-tenant-name');
            document.getElementById('rejectModalTenantName').textContent = trans.rejectRegistrationFor.replace(':name', tenantName);
            document.getElementById('rejectForm').action = data.tenantsUrl + '/' + tenantId + '/reject';
            document.getElementById('rejectModal').style.display = 'flex';
        });
    });

    var modal = document.getElementById('rejectModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeRejectModal();
        });
    }

    // Charts
    var chartData = data.charts || {};
    var baseChartOptions = {
        chart: { toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        grid: { borderColor: '#f1f5f9', strokeDashArray: 4, xaxis: { lines: { show: false } } },
        tooltip: { theme: 'light', style: { fontFamily: 'Plus Jakarta Sans' } }
    };

    new ApexCharts(document.querySelector("#chart-tenants"), Object.assign({}, baseChartOptions, {
        chart: Object.assign({}, baseChartOptions.chart, { type: 'area', height: 300 }),
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2.5 },
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 90, 100] } },
        colors: ['#14B8A6'],
        series: [{ name: chartData.newTenantsLabel, data: chartData.tenantsData }],
        xaxis: { categories: chartData.tenantsCategories, labels: { style: { colors: '#94a3b8', fontSize: '12px', fontWeight: 500 } }, axisBorder: { show: false }, axisTicks: { show: false } },
        yaxis: { labels: { style: { colors: '#94a3b8', fontSize: '12px', fontWeight: 500 } }, min: 0 },
        markers: { size: 4, colors: ['#14B8A6'], strokeColors: '#fff', strokeWidth: 2, hover: { sizeOffset: 3 } }
    })).render();

    new ApexCharts(document.querySelector("#chart-subscriptions"), Object.assign({}, baseChartOptions, {
        chart: Object.assign({}, baseChartOptions.chart, { type: 'donut', height: 300 }),
        labels: chartData.subscriptionLabels,
        series: chartData.subscriptionSeries,
        colors: chartData.subscriptionColors,
        legend: { position: 'bottom', horizontalAlign: 'center', fontSize: '13px', fontWeight: 500, markers: { radius: 4 }, itemMargin: { horizontal: 8, vertical: 4 } },
        plotOptions: { pie: { donut: { size: '70%', labels: { show: true, name: { fontSize: '14px', fontWeight: 600 }, value: { fontSize: '22px', fontWeight: 800 }, total: { show: true, fontSize: '13px', fontWeight: 600, color: '#94a3b8' } } } } },
        dataLabels: { enabled: false },
        stroke: { width: 3, colors: ['#fff'] }
    })).render();

    var planCategories = chartData.planCategories;
    var planData = chartData.planData;

    if (planCategories.length) {
        new ApexCharts(document.querySelector("#chart-plans"), Object.assign({}, baseChartOptions, {
            chart: Object.assign({}, baseChartOptions.chart, { type: 'bar', height: 300 }),
            plotOptions: { bar: { horizontal: true, borderRadius: 8, barHeight: '60%', distributed: true } },
            dataLabels: { enabled: true, style: { fontSize: '13px', fontWeight: 700 } },
            colors: ['#14B8A6', '#2DD4BF', '#5EEAD4', '#99F6E4', '#CCFBF1'],
            series: [{ name: chartData.subscriptionsLabel, data: planData }],
            xaxis: { categories: planCategories, labels: { style: { colors: '#94a3b8', fontSize: '12px', fontWeight: 500 } } },
            yaxis: { labels: { style: { colors: '#64748b', fontSize: '13px', fontWeight: 600 } } },
            legend: { show: false }
        })).render();
    } else {
        document.querySelector("#chart-plans").innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="bi bi-bar-chart"></i></div><h3>' + chartData.noDataLabel + '</h3><p>' + chartData.noDataDesc + '</p></div>';
    }
});
