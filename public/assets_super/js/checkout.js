document.addEventListener('DOMContentLoaded', function() {
    var data = window.CheckoutData || {};
    var prices = data.prices || {};
    var currencySymbol = data.currencySymbol || '';
    var currencyCode = data.currencyCode || '';
    var trans = data.trans || {};

    var offlineSection = document.getElementById('offlineSection');
    var payBtnText = document.getElementById('payBtnText');
    var secureNote = document.getElementById('secureNote');
    var uploadArea = document.getElementById('uploadArea');
    var paymentProof = document.getElementById('paymentProof');
    var uploadPlaceholder = document.getElementById('uploadPlaceholder');
    var uploadPreview = document.getElementById('uploadPreview');
    var uploadFileName = document.getElementById('uploadFileName');
    var removeFileBtn = document.getElementById('removeFile');

    function currentAmount() {
        var cycle = document.querySelector('.cycle-radio:checked');
        return cycle ? prices[cycle.value].toFixed(2) : prices.monthly.toFixed(2);
    }

    function updateDisplay(cycle) {
        var amount = prices[cycle].toFixed(2);
        document.getElementById('displayCycle').textContent = cycle.charAt(0).toUpperCase() + cycle.slice(1);
        document.getElementById('displayAmount').textContent = currencySymbol + amount;
        document.getElementById('displayTotal').textContent = currencySymbol + amount;
        var offlineAmountEl = document.getElementById('offlineAmount');
        if (offlineAmountEl) offlineAmountEl.textContent = amount;
        updateOfflineVisibility();
    }

    function updateOfflineVisibility() {
        var selected = document.querySelector('.gateway-radio:checked');
        if (!selected) return;
        var isOffline = selected.value === 'offline';
        var amount = currentAmount();

        if (offlineSection) offlineSection.style.display = isOffline ? 'block' : 'none';
        if (payBtnText) {
            payBtnText.innerHTML = isOffline
                ? trans.submitPaymentProof
                : trans.pay + ' ' + currencySymbol + '<span id="payAmount">' + amount + '</span> ' + currencyCode;
        }
        if (secureNote) {
            secureNote.innerHTML = isOffline
                ? '<i class="bi bi-shield-check"></i> ' + trans.proofReviewed
                : '<i class="bi bi-shield-check"></i> ' + trans.securePayment;
        }
    }

    document.querySelectorAll('.cycle-radio').forEach(function(radio) {
        radio.addEventListener('change', function() {
            document.querySelectorAll('.cycle-option').forEach(function(o) { o.classList.remove('selected'); });
            this.closest('.cycle-option').classList.add('selected');
            updateDisplay(this.value);
        });
    });

    document.querySelectorAll('.gateway-radio').forEach(function(radio) {
        radio.addEventListener('change', function() {
            document.querySelectorAll('.gateway-option').forEach(function(o) { o.classList.remove('selected'); });
            this.closest('.gateway-option').classList.add('selected');
            updateOfflineVisibility();
        });
    });

    // Initial state
    updateOfflineVisibility();

    // Upload area interactions
    if (uploadArea) {
        uploadArea.addEventListener('click', function(e) {
            if (e.target.closest('#removeFile')) return;
            paymentProof.click();
        });
        uploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('drag-over');
        });
        uploadArea.addEventListener('dragleave', function() {
            this.classList.remove('drag-over');
        });
        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');
            if (e.dataTransfer.files.length) {
                paymentProof.files = e.dataTransfer.files;
                showFilePreview(e.dataTransfer.files[0]);
            }
        });
    }

    if (paymentProof) {
        paymentProof.addEventListener('change', function() {
            if (this.files.length) showFilePreview(this.files[0]);
        });
    }

    function showFilePreview(file) {
        var maxSize = 5 * 1024 * 1024;
        if (file.size > maxSize) {
            alert(trans.fileTooLarge);
            paymentProof.value = '';
            return;
        }
        uploadPlaceholder.style.display = 'none';
        uploadPreview.style.display = 'block';
        uploadFileName.textContent = file.name;
    }

    if (removeFileBtn) {
        removeFileBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            paymentProof.value = '';
            uploadPlaceholder.style.display = '';
            uploadPreview.style.display = 'none';
        });
    }

    // Form submit: prevent double-click
    document.getElementById('checkoutForm').addEventListener('submit', function(e) {
        var btn = document.getElementById('payBtn');
        if (btn.dataset.submitting === '1') { e.preventDefault(); return; }
        btn.dataset.submitting = '1';
        btn.disabled = true;
        var selected = document.querySelector('.gateway-radio:checked');
        var isOffline = selected && selected.value === 'offline';
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> ' + (isOffline ? trans.submitting : trans.redirecting);
    });
});
