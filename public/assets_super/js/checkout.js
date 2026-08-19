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

    // Multiple-bank selector. The server keeps the first active account in the
    // legacy fields and stores all active accounts in the instructions summary.
    // Convert that summary into a clear bank picker instead of showing one long
    // paragraph to the customer.
    function setupBankSelector() {
        if (!offlineSection) return;
        var instructionsBox = offlineSection.querySelector('.bank-instructions');
        if (!instructionsBox) return;
        var instructionsText = instructionsBox.querySelector('.bank-instructions-text');
        if (!instructionsText) return;

        var raw = (instructionsText.textContent || '').trim();
        var prefix = 'Cuentas bancarias disponibles:';
        if (raw.indexOf(prefix) !== 0) return;

        var entries = raw.substring(prefix.length).trim().split(/\s*\|\s*/).filter(Boolean);
        if (!entries.length) return;

        var accounts = entries.map(function(entry) {
            var parts = entry.split(/\s+—\s+/);
            var account = {
                bank: (parts[0] || '').trim(),
                typeCurrency: (parts[1] || '').trim(),
                number: '',
                holder: '',
                instructions: ''
            };
            parts.slice(2).forEach(function(part) {
                var value = part.trim();
                if (/^Cuenta\s+/i.test(value)) account.number = value.replace(/^Cuenta\s+/i, '').trim();
                else if (/^Titular:\s*/i.test(value)) account.holder = value.replace(/^Titular:\s*/i, '').trim();
                else account.instructions += (account.instructions ? ' — ' : '') + value;
            });
            return account;
        }).filter(function(account) { return account.bank && account.number; });

        if (!accounts.length) return;

        var detailsGrid = offlineSection.querySelector('.bank-details-grid');
        if (!detailsGrid) return;

        var picker = document.createElement('div');
        picker.className = 'mb-3';
        picker.innerHTML = '<label class="form-label fw-600 mb-2"><i class="bi bi-bank me-1"></i> Seleccione el banco donde desea realizar la transferencia</label>' +
            '<select class="form-select" id="checkoutBankSelector" aria-label="Seleccione banco"></select>';
        detailsGrid.parentNode.insertBefore(picker, detailsGrid);

        var select = picker.querySelector('select');
        accounts.forEach(function(account, index) {
            var option = document.createElement('option');
            option.value = String(index);
            option.textContent = account.bank + (account.typeCurrency ? ' — ' + account.typeCurrency : '');
            select.appendChild(option);
        });

        var rows = detailsGrid.querySelectorAll('.bank-detail-row');
        function rowByLabel(labels) {
            for (var i = 0; i < rows.length; i++) {
                var label = rows[i].querySelector('.bank-detail-label');
                if (!label) continue;
                var text = label.textContent.trim().toLowerCase();
                if (labels.some(function(candidate) { return text.indexOf(candidate) !== -1; })) return rows[i];
            }
            return null;
        }
        var bankRow = rowByLabel(['nombre del banco', 'bank name']);
        var holderRow = rowByLabel(['titular', 'account holder']);
        var numberRow = rowByLabel(['número de cuenta', 'numero de cuenta', 'account number']);

        function setRow(row, value) {
            if (!row) return;
            var el = row.querySelector('.bank-detail-value');
            if (el) el.textContent = value || '—';
        }

        function renderAccount(index) {
            var account = accounts[index] || accounts[0];
            setRow(bankRow, account.bank);
            setRow(holderRow, account.holder);
            setRow(numberRow, account.number);

            instructionsBox.style.display = account.instructions ? '' : 'none';
            instructionsText.textContent = account.instructions || '';

            var existingMeta = detailsGrid.querySelector('.checkout-bank-meta');
            if (existingMeta) existingMeta.remove();
            if (account.typeCurrency) {
                var meta = document.createElement('div');
                meta.className = 'bank-detail-row checkout-bank-meta';
                meta.innerHTML = '<span class="bank-detail-label">Tipo / Moneda</span><span class="bank-detail-value"></span>';
                meta.querySelector('.bank-detail-value').textContent = account.typeCurrency;
                detailsGrid.appendChild(meta);
            }
        }

        select.addEventListener('change', function() { renderAccount(parseInt(this.value, 10) || 0); });
        renderAccount(0);
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

    updateOfflineVisibility();
    setupBankSelector();

    if (uploadArea) {
        uploadArea.addEventListener('click', function(e) {
            if (e.target.closest('#removeFile')) return;
            paymentProof.click();
        });
        uploadArea.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('drag-over'); });
        uploadArea.addEventListener('dragleave', function() { this.classList.remove('drag-over'); });
        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');
            if (e.dataTransfer.files.length) {
                paymentProof.files = e.dataTransfer.files;
                showFilePreview(e.dataTransfer.files[0]);
            }
        });
    }

    if (paymentProof) paymentProof.addEventListener('change', function() { if (this.files.length) showFilePreview(this.files[0]); });

    function showFilePreview(file) {
        var maxSize = 5 * 1024 * 1024;
        if (file.size > maxSize) { alert(trans.fileTooLarge); paymentProof.value = ''; return; }
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
