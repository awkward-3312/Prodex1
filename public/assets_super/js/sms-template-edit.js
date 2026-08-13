document.addEventListener('DOMContentLoaded', function() {
    var data = window.SmsTemplateData || {};
    var trans = data.trans || {};

    var textarea = document.getElementById('smsBody');
    var charCount = document.getElementById('charCount');

    function updateCount() {
        charCount.textContent = textarea.value.length;
    }

    textarea.addEventListener('input', updateCount);
    updateCount();

    // Active toggle label (default view only)
    var activeCheckbox = document.querySelector('input[name="is_active"]');
    if (activeCheckbox) {
        activeCheckbox.addEventListener('change', function() {
            this.nextElementSibling.textContent = this.checked ? trans.active : trans.inactive;
        });
    }

    // Copy default template into translation editor
    var copyDefaultBtn = document.getElementById('copyDefaultBtn');
    if (copyDefaultBtn) {
        copyDefaultBtn.addEventListener('click', function() {
            textarea.value = data.defaultBody;
            updateCount();
        });
    }

    // Copy variable buttons (event delegation)
    document.addEventListener('click', function(e) {
        var varBtn = e.target.closest('[data-copy-var]');
        if (varBtn) {
            var varText = varBtn.getAttribute('data-copy-var');
            navigator.clipboard.writeText(varText).then(function() {
                var originalBg = varBtn.style.background;
                varBtn.style.background = '#e0e7ff';
                setTimeout(function() { varBtn.style.background = originalBg; }, 300);
            });
        }
    });

    // Delete translation button -> submit external form with SweetAlert confirm
    var deleteTranslationBtn = document.getElementById('deleteTranslationBtn');
    if (deleteTranslationBtn) {
        deleteTranslationBtn.addEventListener('click', function() {
            Swal.fire({
                title: trans.removeTranslationTitle,
                text: trans.removeTranslationText,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: trans.yesRemove,
                confirmButtonColor: '#ef4444',
                cancelButtonText: trans.cancel,
                cancelButtonColor: '#64748b',
                reverseButtons: true,
            }).then(function(result) {
                if (result.isConfirmed) {
                    document.getElementById('deleteTranslationForm').submit();
                }
            });
        });
    }
});
