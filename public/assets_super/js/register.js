// Password toggle via event delegation
document.querySelectorAll('.toggle-password[data-target]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var fieldId = btn.getAttribute('data-target');
        var input = document.getElementById(fieldId);
        var open = btn.querySelector('.eye-open');
        var closed = btn.querySelector('.eye-closed');

        if (input.type === 'password') {
            input.type = 'text';
            open.style.display = 'none';
            closed.style.display = 'block';
        } else {
            input.type = 'password';
            open.style.display = 'block';
            closed.style.display = 'none';
        }
    });
});

// Loading state on submit
document.getElementById('register-form').addEventListener('submit', function () {
    var btn = document.getElementById('btn-register');
    btn.disabled = true;
    btn.classList.add('is-loading');
});

// Plan / billing cycle logic
(function () {
    var planSelect = document.getElementById('plan_id');
    var cycleGroup = document.getElementById('billing-cycle-group');
    if (!planSelect || !cycleGroup) return;

    var currencySymbol = (window.RegisterConfig && window.RegisterConfig.currencySymbol) || '$';

    function updateCycle() {
        var opt = planSelect.options[planSelect.selectedIndex];
        var isFree = opt.getAttribute('data-free') === '1';
        var price = opt.getAttribute('data-price');
        var yearly = opt.getAttribute('data-yearly');

        if (isFree || !price || parseFloat(price) <= 0) {
            cycleGroup.classList.remove('visible');
        } else {
            cycleGroup.classList.add('visible');
            var mp = document.getElementById('monthly-price');
            var yp = document.getElementById('yearly-price');
            if (mp) mp.textContent = currencySymbol + parseFloat(price).toFixed(2) + '/mo';
            if (yp) yp.textContent = currencySymbol + parseFloat(yearly || price * 12).toFixed(2) + '/yr';
        }
    }

    planSelect.addEventListener('change', updateCycle);
    updateCycle();
})();
