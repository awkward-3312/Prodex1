document.addEventListener('DOMContentLoaded', function() {
    var data = window.ReportsData || {};
    var trans = data.trans || {};
    var currencySymbol = data.currencySymbol || '$';

    var colors = {
        primary: '#6366f1',
        success: '#10b981',
        info:    '#3b82f6',
        warning: '#f59e0b',
        danger:  '#ef4444',
        muted:   '#94a3b8',
        purple:  '#8b5cf6',
    };

    var chartDefaults = {
        chart: { fontFamily: 'inherit', toolbar: { show: false } },
        grid: { borderColor: '#f1f5f9', strokeDashArray: 3 },
    };

    // ── Tenant Growth (Area chart) ──
    var tenantData = data.tenantGrowth;
    new ApexCharts(document.querySelector('#tenantGrowthChart'), {
        ...chartDefaults,
        chart: { ...chartDefaults.chart, type: 'area', height: 320 },
        series: [{ name: trans.newTenants, data: tenantData.map(d => d.count) }],
        xaxis: {
            categories: tenantData.map(d => d.month),
            labels: { style: { fontSize: '11px', colors: '#94a3b8' } },
            axisBorder: { show: false },
        },
        yaxis: {
            labels: { style: { fontSize: '11px', colors: '#94a3b8' } },
        },
        stroke: { curve: 'smooth', width: 3 },
        fill: {
            type: 'gradient',
            gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 100] }
        },
        colors: [colors.primary],
        dataLabels: { enabled: false },
        tooltip: { y: { formatter: v => v + ' ' + trans.tenantsLc } },
    }).render();

    // ── Tenant Status (Donut) ──
    if (data.hasStatuses) {
        var statusLabels = data.statusLabels;
        var statusValues = data.statusValues;
        var statusColors = statusLabels.map(function(s) {
            var map = { active: colors.success, pending: colors.warning, suspended: '#f97316', cancelled: colors.danger, provisioning: colors.info, failed: '#dc2626', rejected: '#ec4899' };
            return map[s] || colors.muted;
        });

        new ApexCharts(document.querySelector('#tenantStatusChart'), {
            chart: { type: 'donut', height: 300, fontFamily: 'inherit' },
            series: statusValues,
            labels: statusLabels.map(function(s) { return s.charAt(0).toUpperCase() + s.slice(1); }),
            colors: statusColors,
            legend: { position: 'bottom', fontSize: '12px' },
            plotOptions: {
                pie: {
                    donut: {
                        size: '65%',
                        labels: {
                            show: true,
                            total: { show: true, label: trans.total, fontSize: '13px', fontWeight: 700 }
                        }
                    }
                }
            },
            dataLabels: { enabled: false },
            stroke: { width: 3, colors: ['#fff'] },
        }).render();
    }

    // ── Revenue Trend (Bar chart) ──
    var revenueData = data.revenueTrend;
    new ApexCharts(document.querySelector('#revenueChart'), {
        ...chartDefaults,
        chart: { ...chartDefaults.chart, type: 'bar', height: 320 },
        series: [{ name: trans.revenue, data: revenueData.map(d => d.revenue) }],
        xaxis: {
            categories: revenueData.map(d => d.month),
            labels: { style: { fontSize: '11px', colors: '#94a3b8' } },
            axisBorder: { show: false },
        },
        yaxis: {
            labels: {
                style: { fontSize: '11px', colors: '#94a3b8' },
                formatter: v => currencySymbol + v.toLocaleString()
            },
        },
        colors: [colors.success],
        plotOptions: {
            bar: { borderRadius: 6, columnWidth: '55%' }
        },
        fill: {
            type: 'gradient',
            gradient: { shade: 'light', type: 'vertical', shadeIntensity: 0.2, opacityFrom: 0.95, opacityTo: 0.75 }
        },
        dataLabels: { enabled: false },
        tooltip: { y: { formatter: v => currencySymbol + v.toLocaleString(undefined, { minimumFractionDigits: 2 }) } },
    }).render();

    // ── Subscriptions by Plan (Donut) ──
    if (data.hasPlanData) {
        var planLabels = data.planLabels;
        var planValues = data.planValues;
        var planColors = [colors.primary, colors.success, colors.info, colors.warning, colors.purple, colors.danger, colors.muted];

        new ApexCharts(document.querySelector('#planChart'), {
            chart: { type: 'donut', height: 300, fontFamily: 'inherit' },
            series: planValues,
            labels: planLabels,
            colors: planColors.slice(0, planLabels.length),
            legend: { position: 'bottom', fontSize: '12px' },
            plotOptions: {
                pie: {
                    donut: {
                        size: '65%',
                        labels: {
                            show: true,
                            total: { show: true, label: trans.total, fontSize: '13px', fontWeight: 700 }
                        }
                    }
                }
            },
            dataLabels: { enabled: false },
            stroke: { width: 3, colors: ['#fff'] },
        }).render();
    }
});
