document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('reloadPageBtn').addEventListener('click', function() {
        window.location.reload();
    });

    document.getElementById('statusFilter').addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
});
