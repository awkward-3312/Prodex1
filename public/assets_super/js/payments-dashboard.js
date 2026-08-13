document.addEventListener('DOMContentLoaded', function() {
    var ctx = document.getElementById('revenueChart');
    if (!ctx) return;

    var data = window.PaymentsDashboardData || {};
    var currencySymbol = data.currencySymbol || '$';

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Revenue (' + currencySymbol + ')',
                data: data.revenue,
                backgroundColor: 'rgba(99, 102, 241, 0.15)',
                borderColor: '#6366f1',
                borderWidth: 2,
                borderRadius: 8,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) { return currencySymbol + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2}); }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(v) { return currencySymbol + v.toLocaleString(); },
                        font: { size: 11 }
                    },
                    grid: { color: '#f1f5f9' }
                },
                x: {
                    ticks: { font: { size: 11 } },
                    grid: { display: false }
                }
            }
        }
    });
});
