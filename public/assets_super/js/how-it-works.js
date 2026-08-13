document.addEventListener('DOMContentLoaded', function() {
    document.querySelector('.hiw-faq-list').addEventListener('click', function(e) {
        var toggle = e.target.closest('.hiw-faq-toggle');
        if (toggle) {
            toggle.parentElement.classList.toggle('open');
        }
    });
});
