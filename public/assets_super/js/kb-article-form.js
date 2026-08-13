document.addEventListener('DOMContentLoaded', function() {
    var data = window.KbArticleData || {};
    var trans = data.trans || {};

    // Auto-slug from title
    var titleInput = document.getElementById('articleTitle');
    var slugInput = document.getElementById('articleSlug');
    var isNew = data.isNew;
    var slugEdited = false;

    if (isNew) {
        slugInput.addEventListener('input', function() { slugEdited = true; });
        titleInput.addEventListener('input', function() {
            if (!slugEdited) {
                slugInput.value = this.value.toLowerCase()
                    .replace(/[^a-z0-9\s-]/g, '')
                    .replace(/\s+/g, '-')
                    .replace(/-+/g, '-')
                    .replace(/^-|-$/g, '');
            }
        });
    }

    // Visual preview toggle
    var showingPreview = false;
    var toggleBtn = document.getElementById('toggleEditor');
    var toggleLabel = document.getElementById('toggleLabel');
    var codeEditor = document.getElementById('codeEditor');
    var visualPreview = document.getElementById('visualPreview');
    var textarea = document.getElementById('articleContent');
    var previewContent = document.getElementById('previewContent');

    toggleBtn.addEventListener('click', function() {
        showingPreview = !showingPreview;
        if (showingPreview) {
            previewContent.innerHTML = textarea.value;
            visualPreview.style.display = 'block';
            codeEditor.style.display = 'none';
            toggleLabel.textContent = trans.labelSource;
        } else {
            visualPreview.style.display = 'none';
            codeEditor.style.display = '';
            toggleLabel.textContent = trans.labelVisual;
        }
    });
});
