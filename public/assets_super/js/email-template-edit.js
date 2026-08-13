document.addEventListener('DOMContentLoaded', function() {
    var data = window.EmailTemplateData || {};
    var trans = data.trans || {};

    var showingVisual = false;
    var toggleBtn = document.getElementById('toggleEditor');
    var toggleLabel = document.getElementById('toggleLabel');
    var visualEditor = document.getElementById('visualEditor');
    var codeEditor = document.getElementById('codeEditor');
    var textarea = document.getElementById('bodyHtml');
    var richEditor = document.getElementById('richEditor');

    toggleBtn.addEventListener('click', function() {
        showingVisual = !showingVisual;
        if (showingVisual) {
            richEditor.innerHTML = textarea.value;
            richEditor.contentEditable = true;
            visualEditor.style.display = 'block';
            codeEditor.style.display = 'none';
            toggleLabel.textContent = trans.sourceCode;
        } else {
            textarea.value = richEditor.innerHTML;
            visualEditor.style.display = 'none';
            codeEditor.style.display = '';
            toggleLabel.textContent = trans.visualEditor;
        }
    });

    document.getElementById('templateForm').addEventListener('submit', function() {
        if (showingVisual) {
            textarea.value = richEditor.innerHTML;
        }
    });

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
            var defaultBody = data.defaultBody;
            textarea.value = defaultBody;
            if (showingVisual) {
                richEditor.innerHTML = defaultBody;
            }

            var subjectInput = document.querySelector('input[name="subject"]');
            if (subjectInput && !subjectInput.value) {
                subjectInput.value = data.defaultSubject;
            }
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
