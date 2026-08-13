// Retry button
document.getElementById('retryBtn').addEventListener('click', function() {
    window.location.reload();
});

(function () {
    var statusUrl   = window.WorkspaceData.statusUrl;
    var trans       = window.WorkspaceData.trans;

    var statusText  = document.getElementById('statusText');
    var statusHint  = document.getElementById('statusHint');
    var statusBox   = document.getElementById('statusBox');
    var errorBox    = document.getElementById('errorBox');
    var spinner     = document.getElementById('spinner');
    var successIcon = document.getElementById('successIcon');
    var failIcon    = document.getElementById('failIcon');
    var actionBtns  = document.getElementById('actionButtons');
    var progressBar = document.getElementById('progressBar');
    var mainTitle   = document.getElementById('mainTitle');
    var mainSub     = document.getElementById('mainSubtitle');
    var elapsedEl   = document.getElementById('elapsedTime');

    var pollCount = 0;
    var maxPolls  = 120;
    var startTime = Date.now();

    // Elapsed timer
    setInterval(function () {
        var s = Math.floor((Date.now() - startTime) / 1000);
        if (s < 60) {
            elapsedEl.textContent = s + 's';
        } else {
            elapsedEl.textContent = Math.floor(s / 60) + 'm ' + (s % 60) + 's';
        }
    }, 1000);

    function setStep(id, state) {
        var el = document.getElementById(id);
        if (!el) return;
        el.className = state;
    }

    function setProgress(pct, cls) {
        progressBar.style.width = pct + '%';
        progressBar.className = 'progress-fill' + (cls ? ' ' + cls : '');
    }

    function showError(msg, solution) {
        spinner.style.display = 'none';
        failIcon.style.display = 'flex';
        statusBox.className = 'status-box status-failed';

        errorBox.innerHTML = '';
        var msgEl = document.createElement('div');
        msgEl.className = 'error-message';
        msgEl.textContent = msg;
        errorBox.appendChild(msgEl);

        if (solution) {
            var solEl = document.createElement('div');
            solEl.className = 'error-solution';
            solEl.textContent = solution;
            errorBox.appendChild(solEl);
        }

        errorBox.style.display = 'block';
        actionBtns.classList.add('visible');
        setProgress(100, 'progress-failed');
        mainTitle.textContent = trans.somethingWentWrong;
        mainSub.textContent = trans.somethingWentWrongSub;
    }

    function showSuccess() {
        spinner.style.display = 'none';
        successIcon.style.display = 'flex';
        statusBox.className = 'status-box status-success';
        setProgress(100, 'progress-success');
    }

    function poll() {
        pollCount++;

        if (pollCount > maxPolls) {
            statusText.textContent = trans.takingLonger;
            statusHint.textContent = trans.somethingMayHaveGoneWrong;
            showError(trans.timedOut, trans.timedOutSolution);
            return;
        }

        fetch(statusUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
            .then(function (result) {
                if (!result.ok) {
                    throw new Error('');
                }

                var st = result.data.status || 'pending';

                if (st === 'active' && result.data.ready_url) {
                    setStep('step-payment', 'done');
                    setStep('step-tenant', 'done');
                    setStep('step-db', 'done');
                    setStep('step-ready', 'done');
                    showSuccess();
                    statusText.textContent = trans.workspaceReady;
                    statusHint.textContent = trans.redirectingNow;
                    mainTitle.textContent = trans.allSet;
                    mainSub.textContent = trans.workspaceCreatedSuccess;
                    setTimeout(function () { window.location.href = result.data.ready_url; }, 1200);
                    return;
                }

                // Payment was cancelled / definitively failed — go back to checkout
                if (st === 'cancelled' && result.data.checkout_url) {
                    window.location.href = result.data.checkout_url;
                    return;
                }

                if (st === 'failed') {
                    setStep('step-payment', 'done');
                    setStep('step-tenant', 'failed');
                    statusText.textContent = trans.provisioningFailed;
                    statusHint.textContent = trans.couldNotFinishSetup;
                    showError(
                        result.data.error || trans.provisioningFailedError,
                        result.data.solution || trans.provisioningFailedSolution
                    );
                    return;
                }

                if (st === 'pending') {
                    // Redirect to dedicated under-review page
                    if (result.data.review_url) {
                        window.location.href = result.data.review_url;
                        return;
                    }
                    // Fallback: show inline message
                    setStep('step-payment', 'done');
                    setStep('step-tenant', 'active');
                    setProgress(30);
                    spinner.style.display = 'none';
                    statusBox.style.background = 'rgba(245, 158, 11, 0.06)';
                    statusBox.style.borderColor = 'rgba(245, 158, 11, 0.15)';
                    statusText.textContent = trans.awaitingAdminApproval;
                    statusHint.textContent = trans.registrationBeingReviewed;
                    mainTitle.textContent = trans.registrationUnderReview;
                    mainSub.textContent = trans.paymentReceivedAdminReview;
                    setTimeout(poll, 10000);
                    return;
                } else if (st === 'processing') {
                    setStep('step-payment', 'active');
                    setProgress(20);
                    statusText.textContent = trans.verifyingPayment;
                    statusHint.textContent = trans.confirmingPayment;
                } else if (st === 'provisioning') {
                    setStep('step-payment', 'done');
                    setStep('step-tenant', 'active');
                    setStep('step-db', 'active');
                    setProgress(60);
                    statusText.textContent = trans.settingUpWorkspace;
                    statusHint.textContent = result.data.tenant_url
                        ? trans.workspaceWillBeAt + ' ' + result.data.tenant_url
                        : trans.creatingDatabase;
                } else if (st === 'paid') {
                    setStep('step-payment', 'done');
                    setStep('step-tenant', 'active');
                    setProgress(40);
                    statusText.textContent = trans.paymentConfirmed;
                    statusHint.textContent = trans.creatingWorkspaceNow;
                }

                setTimeout(poll, 2500);
            })
            .catch(function () {
                if (pollCount > 3) {
                    showError(trans.unableToCheck, trans.networkErrorSolution);
                    return;
                }
                setTimeout(poll, 2500);
            });
    }

    poll();
})();
