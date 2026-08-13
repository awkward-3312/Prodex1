document.addEventListener('DOMContentLoaded', function() {
    var data = window.KbCategoryData || {};
    var nameInput = document.querySelector('input[name="name"]');
    var slugInput = document.querySelector('input[name="slug"]');
    var isNew = data.isNew;
    var slugEdited = false;

    if (isNew) {
        slugInput.addEventListener('input', function() { slugEdited = true; });
        nameInput.addEventListener('input', function() {
            if (!slugEdited) {
                slugInput.value = this.value.toLowerCase()
                    .replace(/[^a-z0-9\s-]/g, '')
                    .replace(/\s+/g, '-')
                    .replace(/-+/g, '-')
                    .replace(/^-|-$/g, '');
            }
        });
    }
});
