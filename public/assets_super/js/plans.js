document.addEventListener('DOMContentLoaded', function() {
    var currentCycle = 'monthly';

    document.querySelectorAll('.btn-cycle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            currentCycle = this.dataset.cycle;
            document.querySelectorAll('.btn-cycle').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');

            document.querySelectorAll('.plan-price').forEach(function(el) {
                el.style.display = el.dataset.cycle === currentCycle ? 'block' : 'none';
            });

            document.querySelectorAll('.plan-select-btn').forEach(function(link) {
                var url = new URL(link.href);
                url.searchParams.set('cycle', currentCycle);
                link.href = url.toString();
            });
        });
    });
});
