document.addEventListener('DOMContentLoaded', function() {
    var data = window.CheckoutData || {};
    var prices = data.prices || {};
    var currencySymbol = data.currencySymbol || '';
    var currencyCode = data.currencyCode || '';
    var trans = data.trans || {};

    var checkoutForm = document.getElementById('checkoutForm');
    var originalCheckoutAction = checkoutForm ? checkoutForm.action : '';
    var offlineSection = document.getElementById('offlineSection');
    var payBtnText = document.getElementById('payBtnText');
    var secureNote = document.getElementById('secureNote');
    var uploadArea = document.getElementById('uploadArea');
    var paymentProof = document.getElementById('paymentProof');
    var uploadPlaceholder = document.getElementById('uploadPlaceholder');
    var uploadPreview = document.getElementById('uploadPreview');
    var uploadFileName = document.getElementById('uploadFileName');
    var removeFileBtn = document.getElementById('removeFile');

    function ensureDLocalSection() {
        if (!document.querySelector('.gateway-radio[value="dlocal"]')) return null;

        var existing = document.getElementById('dlocalSection');
        if (existing) return existing;
        if (!checkoutForm) return null;

        var section = document.createElement('div');
        section.className = offlineSection && offlineSection.classList.contains('billing-card')
            ? 'billing-card mb-4'
            : 'checkout-card mb-4';
        section.id = 'dlocalSection';
        section.style.display = 'none';
        section.innerHTML = '' +
            '<div class="' + (section.classList.contains('billing-card') ? 'billing-card-header' : 'checkout-card-header') + '">' +
                '<i class="bi bi-person-vcard me-2 text-muted"></i>Datos del titular para dLocal' +
            '</div>' +
            '<div class="' + (section.classList.contains('billing-card') ? 'billing-card-body' : 'checkout-card-body') + '">' +
                '<p class="text-muted small mb-3">Estos datos se envían de forma segura a dLocal para validar el pago. PRODEX no almacena datos de tarjeta.</p>' +
                '<div class="row g-3">' +
                    '<div class="col-md-6"><label class="form-label fw-600">País</label>' +
                        '<select name="dlocal_country" class="form-select dlocal-required" disabled>' +
                            '<option value="HN">Honduras</option><option value="GT">Guatemala</option><option value="SV">El Salvador</option>' +
                            '<option value="NI">Nicaragua</option><option value="CR">Costa Rica</option><option value="PA">Panamá</option>' +
                            '<option value="MX">México</option><option value="CO">Colombia</option><option value="PE">Perú</option>' +
                            '<option value="CL">Chile</option><option value="BR">Brasil</option><option value="AR">Argentina</option>' +
                            '<option value="UY">Uruguay</option><option value="PY">Paraguay</option><option value="BO">Bolivia</option>' +
                            '<option value="DO">República Dominicana</option>' +
                        '</select></div>' +
                    '<div class="col-md-6"><label class="form-label fw-600">Nombre completo del titular</label>' +
                        '<input type="text" name="dlocal_name" maxlength="100" class="form-control dlocal-required" autocomplete="name" disabled></div>' +
                    '<div class="col-md-6"><label class="form-label fw-600">Documento de identidad</label>' +
                        '<input type="text" name="dlocal_document" maxlength="30" class="form-control dlocal-required" autocomplete="off" disabled>' +
                        '<div class="form-text">En Honduras: DNI de 13 dígitos.</div></div>' +
                    '<div class="col-md-6"><label class="form-label fw-600">Fecha de nacimiento</label>' +
                        '<input type="text" name="dlocal_birth_date" placeholder="DD-MM-AAAA" maxlength="10" class="form-control dlocal-required" autocomplete="bday" disabled></div>' +
                    '<div class="col-12"><label class="form-label fw-600">Teléfono</label>' +
                        '<input type="tel" name="dlocal_phone" maxlength="20" class="form-control dlocal-required" autocomplete="tel" disabled></div>' +
                '</div>' +
            '</div>';

        if (offlineSection && offlineSection.parentNode) {
            offlineSection.parentNode.insertBefore(section, offlineSection);
        } else {
            var button = document.getElementById('payBtn');
            checkoutForm.insertBefore(section, button || null);
        }

        return section;
    }

    var dlocalSection = ensureDLocalSection();

    function syncDLocalVisibility() {
        var selected = document.querySelector('.gateway-radio:checked');
        var isDLocal = !!selected && selected.value === 'dlocal';
        dlocalSection = document.getElementById('dlocalSection') || dlocalSection;

        if (dlocalSection) dlocalSection.style.display = isDLocal ? 'block' : 'none';
        document.querySelectorAll('.dlocal-required').forEach(function(field) {
            field.disabled = !isDLocal;
        });

        // Tenant billing keeps the legacy BillingController untouched. Only a
        // dLocal selection is routed to the isolated dLocal billing controller.
        if (checkoutForm && originalCheckoutAction.indexOf('/billing/checkout/') !== -1) {
            checkoutForm.action = isDLocal
                ? originalCheckoutAction.replace('/billing/checkout/', '/billing/dlocal/checkout/')
                : originalCheckoutAction;
        }
    }

    function currentAmount() {
        var cycle = document.querySelector('.cycle-radio:checked');
        return cycle ? prices[cycle.value].toFixed(2) : prices.monthly.toFixed(2);
    }

    function updateDisplay(cycle) {
        var amount = prices[cycle].toFixed(2);
        var cycleEl = document.getElementById('displayCycle');
        var amountEl = document.getElementById('displayAmount');
        var totalEl = document.getElementById('displayTotal');
        if (cycleEl) cycleEl.textContent = cycle.charAt(0).toUpperCase() + cycle.slice(1);
        if (amountEl) amountEl.textContent = currencySymbol + amount;
        if (totalEl) totalEl.textContent = currencySymbol + amount;
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

    function copyText(value, button) {
        if (!value) return;
        function success() {
            var original = button.innerHTML;
            button.innerHTML = '<i class="bi bi-check-lg me-1"></i>Copiado';
            button.classList.remove('btn-outline-secondary');
            button.classList.add('btn-outline-success');
            window.setTimeout(function() {
                button.innerHTML = original;
                button.classList.remove('btn-outline-success');
                button.classList.add('btn-outline-secondary');
            }, 1600);
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(value).then(success).catch(function() { fallbackCopy(value, success); });
        } else {
            fallbackCopy(value, success);
        }
    }

    function fallbackCopy(value, done) {
        var textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try { document.execCommand('copy'); if (done) done(); } catch (e) {}
        textarea.remove();
    }

    function addCopyButton(numberRow) {
        if (!numberRow || numberRow.querySelector('.checkout-copy-account')) return;
        var valueEl = numberRow.querySelector('.bank-detail-value');
        if (!valueEl) return;
        var wrapper = document.createElement('span');
        wrapper.className = 'd-inline-flex align-items-center gap-2 flex-wrap justify-content-end';
        valueEl.parentNode.insertBefore(wrapper, valueEl);
        wrapper.appendChild(valueEl);
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-outline-secondary checkout-copy-account';
        button.innerHTML = '<i class="bi bi-copy me-1"></i>Copiar';
        button.setAttribute('aria-label', 'Copiar número de cuenta');
        button.addEventListener('click', function() {
            copyText((valueEl.textContent || '').trim(), button);
        });
        wrapper.appendChild(button);
    }

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
            var account = { bank: (parts[0] || '').trim(), typeCurrency: (parts[1] || '').trim(), number: '', holder: '', instructions: '' };
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
        picker.innerHTML = '<label class="form-label fw-600 mb-2"><i class="bi bi-bank me-1"></i> Seleccione el banco donde desea realizar la transferencia</label><select class="form-select" id="checkoutBankSelector" aria-label="Seleccione banco"></select>';
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
        addCopyButton(numberRow);

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
            syncDLocalVisibility();
        });
    });

    updateOfflineVisibility();
    syncDLocalVisibility();
    setupBankSelector();

    if (uploadArea) {
        uploadArea.addEventListener('click', function(e) { if (e.target.closest('#removeFile')) return; paymentProof.click(); });
        uploadArea.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('drag-over'); });
        uploadArea.addEventListener('dragleave', function() { this.classList.remove('drag-over'); });
        uploadArea.addEventListener('drop', function(e) { e.preventDefault(); this.classList.remove('drag-over'); if (e.dataTransfer.files.length) { paymentProof.files = e.dataTransfer.files; showFilePreview(e.dataTransfer.files[0]); } });
    }
    if (paymentProof) paymentProof.addEventListener('change', function() { if (this.files.length) showFilePreview(this.files[0]); });
    function showFilePreview(file) {
        var maxSize = 5 * 1024 * 1024;
        if (file.size > maxSize) { alert(trans.fileTooLarge); paymentProof.value = ''; return; }
        uploadPlaceholder.style.display = 'none'; uploadPreview.style.display = 'block'; uploadFileName.textContent = file.name;
    }
    if (removeFileBtn) removeFileBtn.addEventListener('click', function(e) { e.stopPropagation(); paymentProof.value = ''; uploadPlaceholder.style.display = ''; uploadPreview.style.display = 'none'; });

    if (checkoutForm) checkoutForm.addEventListener('submit', function(e) {
        var btn = document.getElementById('payBtn');
        if (!btn) return;
        if (btn.dataset.submitting === '1') { e.preventDefault(); return; }
        btn.dataset.submitting = '1'; btn.disabled = true;
        var selected = document.querySelector('.gateway-radio:checked');
        var isOffline = selected && selected.value === 'offline';
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> ' + (isOffline ? trans.submitting : trans.redirecting);
    });
});
