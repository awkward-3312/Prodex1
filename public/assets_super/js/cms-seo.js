document.addEventListener('DOMContentLoaded', function() {
    var data = window.SeoData || {};
    var titleInput = document.querySelector('input[name="meta_title"]');
    var descInput = document.querySelector('textarea[name="meta_description"]');
    var titleCount = document.getElementById('titleCount');
    var descCount = document.getElementById('descCount');
    var previewTitle = document.getElementById('previewTitle');
    var previewDesc = document.getElementById('previewDesc');

    if (titleInput) {
        titleInput.addEventListener('input', function() {
            titleCount.textContent = this.value.length;
            previewTitle.textContent = this.value || data.previewTitle;
        });
    }
    if (descInput) {
        descInput.addEventListener('input', function() {
            descCount.textContent = this.value.length;
            previewDesc.textContent = this.value || data.previewDesc;
        });
    }
});
