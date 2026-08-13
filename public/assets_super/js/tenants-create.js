var hasGateways = window.TenantCreateData.hasGateways;
var trans = window.TenantCreateData.trans;
var currencySymbol = window.TenantCreateData.currencySymbol;

function updateCreateButton() {
    var btn = document.getElementById('btnCreateTenant');
    if (!btn || btn.dataset.submitting === '1') return;

    var planSelect = document.getElementById('planSelect');
    var opt = planSelect.options[planSelect.selectedIndex];
    var isPaid = opt && opt.value && opt.dataset.free !== '1' && opt.dataset.trial !== '1';

    if (isPaid && hasGateways) {
        var selectedGw = document.querySelector('.gateway-radio:checked');
        if (selectedGw && selectedGw.value === 'offline') {
            btn.innerHTML = '<i class="bi bi-bank me-1"></i> ' + trans.createBankBtn;
        } else {
            btn.innerHTML = '<i class="bi bi-credit-card me-1"></i> ' + trans.createPayBtn;
        }
    } else {
        btn.innerHTML = '<i class="bi bi-plus-lg"></i> ' + trans.createBtn;
    }

    if (isPaid && !hasGateways) {
        btn.disabled = true;
        return;
    }

    btn.disabled = !opt || !opt.value;
}

function updatePaymentSection() {
    var planSelect = document.getElementById('planSelect');
    var section = document.getElementById('paymentMethodSection');
    if (!section) return;

    var opt = planSelect.options[planSelect.selectedIndex];
    var isPaid = opt && opt.value && opt.dataset.free !== '1' && opt.dataset.trial !== '1';

    section.style.display = isPaid ? 'block' : 'none';

    var radios = section.querySelectorAll('.gateway-radio');
    radios.forEach(function(r) { r.required = isPaid; });

    updateCreateButton();
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-pw-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var input = this.closest('.position-relative').querySelector('input');
            var icon = this.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'bi bi-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'bi bi-eye';
            }
        });
    });

    document.querySelectorAll('.gateway-radio').forEach(function(radio) {
        radio.addEventListener('change', function() {
            document.querySelectorAll('.gateway-option').forEach(function(o) { o.classList.remove('selected'); });
            this.closest('.gateway-option').classList.add('selected');
            updateGatewayInfo();
            updateCreateButton();
        });
    });

    function updateGatewayInfo() {
        var infoBox = document.getElementById('gatewayInfoBox');
        var infoText = document.getElementById('gatewayInfoText');
        var proofSection = document.getElementById('paymentProofSection');
        if (!infoBox || !infoText) return;
        var selected = document.querySelector('.gateway-radio:checked');
        var isOffline = selected && selected.value === 'offline';
        if (isOffline) {
            infoBox.style.background = '#f0fdfa';
            infoBox.style.borderColor = '#99f6e4';
            infoText.style.color = '#115e59';
            infoText.innerHTML = '<i class="bi bi-bank me-1"></i> ' + trans.gatewayOfflineInfo;
        } else {
            infoBox.style.background = '#eef2ff';
            infoBox.style.borderColor = '#c7d2fe';
            infoText.style.color = '#4338ca';
            infoText.innerHTML = '<i class="bi bi-shield-check me-1"></i> ' + trans.gatewayOnlineInfo;
        }
        if (proofSection) {
            proofSection.style.display = isOffline ? 'block' : 'none';
        }
    }

    var proofArea = document.getElementById('proofUploadArea');
    var proofInput = document.getElementById('paymentProofInput');
    var proofPlaceholder = document.getElementById('proofPlaceholder');
    var proofPreview = document.getElementById('proofPreview');
    var proofFileName = document.getElementById('proofFileName');
    var proofRemoveBtn = document.getElementById('proofRemoveBtn');

    if (proofArea && proofInput) {
        proofArea.addEventListener('click', function(e) {
            if (e.target === proofRemoveBtn || e.target.closest('#proofRemoveBtn')) return;
            proofInput.click();
        });
        proofArea.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('drag-over'); });
        proofArea.addEventListener('dragleave', function() { this.classList.remove('drag-over'); });
        proofArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');
            if (e.dataTransfer.files.length) {
                proofInput.files = e.dataTransfer.files;
                showProofFile(e.dataTransfer.files[0]);
            }
        });
        proofInput.addEventListener('change', function() {
            if (this.files.length) showProofFile(this.files[0]);
        });
        if (proofRemoveBtn) {
            proofRemoveBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                proofInput.value = '';
                proofPlaceholder.style.display = '';
                proofPreview.style.display = 'none';
            });
        }
    }

    function showProofFile(file) {
        proofFileName.textContent = file.name;
        proofPlaceholder.style.display = 'none';
        proofPreview.style.display = 'block';
    }

    var createForm = document.getElementById('createTenantForm');
    if (createForm) {
        createForm.addEventListener('submit', function(e) {
            var btn = document.getElementById('btnCreateTenant');

            if (btn) {
                btn.dataset.submitting = '1';
                btn.disabled = true;
                var isPaidPlan = false;
                var ps = document.getElementById('planSelect');
                if (ps) {
                    var o = ps.options[ps.selectedIndex];
                    isPaidPlan = o && o.value && o.dataset.free !== '1' && o.dataset.trial !== '1';
                }
                var selectedGw2 = document.querySelector('.gateway-radio:checked');
                var isOffline = selectedGw2 && selectedGw2.value === 'offline';
                var spinnerText = isPaidPlan ? (isOffline ? trans.creatingTenant : trans.redirectingToPayment) : trans.creatingTenant;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + spinnerText;
            }
        });
    }

    var planSelect = document.getElementById('planSelect');
    var billingCycle = document.getElementById('billingCycle');
    var planInfo = document.getElementById('planInfo');
    var planInfoText = document.getElementById('planInfoText');

    function updatePlanInfo() {
        var opt = planSelect.options[planSelect.selectedIndex];
        if (!opt || !opt.value) {
            planInfo.style.display = 'none';
            updatePaymentSection();
            return;
        }

        var isFree = opt.dataset.free === '1';
        var isTrial = opt.dataset.trial === '1';
        var cycle = billingCycle.value;
        var price = cycle === 'yearly' ? parseFloat(opt.dataset.yearly) : parseFloat(opt.dataset.price);
        var cycleLabel = cycle === 'yearly' ? trans.yearly : trans.monthly;

        var isPrivate = opt.dataset.private === '1';
        var privateTag = isPrivate ? ' <span style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:4px; font-size:0.78rem; font-weight:600;"><i class="bi bi-lock-fill me-1"></i>' + trans.privateTag + '</span>' : '';

        var text = '';
        if (isFree) {
            text = '<i class="bi bi-check-circle-fill me-1"></i> ' + trans.freePlan + privateTag;
        } else if (isTrial) {
            text = '<i class="bi bi-clock me-1"></i> ' + trans.trialPlan.replace(':days', opt.dataset.trialDays).replace(':price', currencySymbol + price.toFixed(2)).replace(':cycle', cycleLabel) + privateTag;
        } else {
            text = '<i class="bi bi-credit-card me-1"></i> ' + trans.paidPlan.replace(':price', currencySymbol + price.toFixed(2)).replace(':cycle', cycleLabel) + privateTag;
        }

        planInfoText.innerHTML = text;
        planInfo.style.display = 'block';
        updatePaymentSection();
    }

    planSelect.addEventListener('change', updatePlanInfo);
    billingCycle.addEventListener('change', updatePlanInfo);
    updatePlanInfo();
    updateGatewayInfo();
});
