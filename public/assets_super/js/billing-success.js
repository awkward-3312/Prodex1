(function () {
    var data           = window.SuccessData || {};
    var confirmed      = data.confirmed;
    if (confirmed) return;

    var statusUrl      = data.statusUrl;
    var confirmUrl     = data.confirmUrl;
    var csrfToken      = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    var currencySymbol = data.currencySymbol;

    var attempts       = 0;
    var maxAttempts    = 40;   // ~80 s total then give up
    var confirmCalled  = false; // call the capture endpoint exactly once

    function show(id) { document.getElementById(id).classList.remove('d-none'); }
    function hide(id) { document.getElementById(id).classList.add('d-none'); }

    function fillDetails(data) {
        if (data.invoice_number) document.getElementById('detailInvoice').textContent  = data.invoice_number;
        if (data.plan_name)      document.getElementById('detailPlan').textContent      = data.plan_name;
        if (data.billing_cycle)  document.getElementById('detailCycle').textContent     = data.billing_cycle.charAt(0).toUpperCase() + data.billing_cycle.slice(1);
        if (data.amount)         document.getElementById('detailAmount').textContent    = currencySymbol + data.amount + ' ' + (data.currency || 'USD');
        if (data.gateway_label)  document.getElementById('detailGateway').textContent   = data.gateway_label;
        if (data.valid_until) {
            document.getElementById('detailValidUntil').textContent = data.valid_until;
            show('detailValidUntilRow');
        }
    }

    function showConfirmed(data) {
        hide('verifyingState');
        show('confirmedState');
        show('paymentDetails');
        show('confirmedActions');
        fillDetails(data);
    }

    function showFailed() {
        hide('verifyingState');
        show('failedState');
        show('failedActions');
    }

    // Call the capture endpoint once, then re-poll to pick up the result.
    async function triggerConfirm() {
        if (confirmCalled) return;
        confirmCalled = true;
        try {
            await fetch(confirmUrl, {
                method:  'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            });
            // Don't act on the response here — just let the next poll pick up the new status.
        } catch (e) { /* network error, poll will handle it */ }
    }

    async function poll() {
        attempts++;

        // After 3 polls (~6 s) with no result, trigger the server-side capture once.
        if (attempts === 3 && !confirmCalled) {
            await triggerConfirm();
        }

        try {
            var res  = await fetch(statusUrl, { headers: { 'Accept': 'application/json' } });
            var data = await res.json();

            if (data.is_paid) { showConfirmed(data); return; }
            if (data.status === 'failed') { showFailed(); return; }
        } catch (e) { /* keep polling */ }

        if (attempts >= maxAttempts) { showFailed(); return; }

        setTimeout(poll, attempts < 10 ? 2000 : 5000);
    }

    poll();
})();
