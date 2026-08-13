@extends('central.super.layout')

@section('title', __('super.payments.overview_title'))

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.payments.overview_title') }}</h1>
        <p class="page-subtitle">{{ __('super.payments.overview_subtitle') }}</p>
    </div>
</div>

{{-- Stats row --}}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="content-card stat-card h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon stat-icon-primary"><i class="bi bi-currency-dollar"></i></div>
                <div>
                    <div class="stat-label">{{ __('super.payments.total_revenue') }}</div>
                    <div class="stat-value">{{ $currencySymbol }}{{ number_format($totalRevenue, 2) }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="content-card stat-card h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon stat-icon-success"><i class="bi bi-arrow-repeat"></i></div>
                <div>
                    <div class="stat-label">{{ __('super.payments.monthly_revenue') }}</div>
                    <div class="stat-value">{{ $currencySymbol }}{{ number_format($monthlyRevenue, 2) }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="content-card stat-card h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon stat-icon-info"><i class="bi bi-receipt"></i></div>
                <div>
                    <div class="stat-label">{{ __('super.payments.total_transactions') }}</div>
                    <div class="stat-value">{{ number_format($totalTransactions) }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="content-card stat-card h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon stat-icon-warning"><i class="bi bi-percent"></i></div>
                <div>
                    <div class="stat-label">{{ __('super.payments.total_tax') }}</div>
                    <div class="stat-value">{{ $currencySymbol }}{{ number_format($totalTax, 2) }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Pending offline registrations banner --}}
@php
    $pendingOfflineCount = \App\Models\Central\PendingRegistration::where('gateway', 'offline')
        ->where('status', \App\Models\Central\PendingRegistration::STATUS_PROCESSING)
        ->count();
@endphp
@if($pendingOfflineCount > 0)
<div class="alert alert-offline-pending d-flex align-items-center justify-content-between gap-3 mb-4">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-bank alert-offline-pending-icon"></i>
        <span class="alert-offline-pending-text">
            <strong>{{ $pendingOfflineCount }}</strong> offline {{ Str::plural('payment', $pendingOfflineCount) }} awaiting verification
        </span>
    </div>
    <a href="{{ route('super.payments.pending-registrations') }}" class="btn btn-sm btn-offline-review">
        Review Now <i class="bi bi-arrow-right ms-1"></i>
    </a>
</div>
@endif

{{-- Status summary --}}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="content-card status-summary-card">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <p class="mb-0 text-muted status-summary-label">{{ __('super.status.paid') }}</p>
                    <p class="mb-0 fw-800 fs-xl3 text-success-mid">{{ $paidCount }}</p>
                </div>
                <i class="bi bi-check-circle-fill status-summary-icon text-success-mid"></i>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="content-card status-summary-card">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <p class="mb-0 text-muted status-summary-label">{{ __('super.status.pending') }}</p>
                    <p class="mb-0 fw-800 fs-xl3 color-warning">{{ $pendingCount }}</p>
                </div>
                <i class="bi bi-clock-fill status-summary-icon color-warning"></i>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="content-card status-summary-card">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <p class="mb-0 text-muted status-summary-label">{{ __('super.status.failed') }}</p>
                    <p class="mb-0 fw-800 fs-xl3 color-danger">{{ $failedCount }}</p>
                </div>
                <i class="bi bi-x-circle-fill status-summary-icon color-danger"></i>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="content-card status-summary-card">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <p class="mb-0 text-muted status-summary-label">{{ __('super.status.refunded') }}</p>
                    <p class="mb-0 fw-800 fs-xl3 color-info">{{ $refundedCount }}</p>
                </div>
                <i class="bi bi-arrow-counterclockwise status-summary-icon color-info"></i>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    {{-- Revenue chart --}}
    <div class="col-lg-8">
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-graph-up me-2 text-muted"></i>{{ __('super.payments.revenue_chart') }}</h2>
            </div>
            <div class="card-body-custom">
                <canvas id="revenueChart" height="260"></canvas>
            </div>
        </div>
    </div>

    {{-- Gateway breakdown --}}
    <div class="col-lg-4">
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-pie-chart me-2 text-muted"></i>{{ __('super.payments.by_gateway') }}</h2>
            </div>
            <div class="card-body-custom">
                @if($gatewayBreakdown->isNotEmpty())
                <div class="d-flex flex-column gap-3">
                    @foreach($gatewayBreakdown as $gw)
                    <div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-600 fs-sm3">{{ \App\Models\Central\TenantBillingPayment::GATEWAYS[$gw->gateway] ?? ucfirst($gw->gateway ?? 'Unknown') }}</span>
                            <span class="fw-700 fs-sm3">{{ $currencySymbol }}{{ number_format($gw->total, 2) }}</span>
                        </div>
                        @php $pct = $totalRevenue > 0 ? ($gw->total / $totalRevenue * 100) : 0; @endphp
                        <div class="progress-bar-track">
                            <div class="progress-bar-fill" style="--progress: {{ $pct }}%;"></div>
                        </div>
                        <p class="mb-0 text-muted fs-xs2">{{ $gw->count }} {{ Str::plural('transaction', $gw->count) }} &middot; {{ number_format($pct, 1) }}%</p>
                    </div>
                    @endforeach
                </div>
                @else
                <p class="text-muted text-center py-3 mb-0">{{ __('super.payments.no_data') }}</p>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Recent payments --}}
<div class="content-card">
    <div class="card-header-custom d-flex align-items-center justify-content-between">
        <h2><i class="bi bi-clock-history me-2 text-muted"></i>{{ __('super.payments.recent_payments') }}</h2>
        <a href="{{ route('super.payments.index') }}" class="btn btn-outline btn-sm">{{ __('super.payments.view_all') }}</a>
    </div>
    @if($recentPayments->isNotEmpty())
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>{{ __('super.payments.invoice') }}</th>
                    <th>{{ __('super.payments.tenant') }}</th>
                    <th>{{ __('super.payments.plan') }}</th>
                    <th>{{ __('super.common.amount') }}</th>
                    <th>{{ __('super.payments.gateway') }}</th>
                    <th>{{ __('super.common.status') }}</th>
                    <th>{{ __('super.payments.date') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recentPayments as $p)
                <tr>
                    <td><a href="{{ route('super.payments.show', $p) }}" class="fw-600 fs-sm3">{{ $p->invoice_number ?? '—' }}</a></td>
                    <td>
                        <span class="fw-600 fs-sm3">{{ $p->tenant_id }}</span>
                    </td>
                    <td><span class="fs-sm3">{{ $p->plan->name ?? '—' }}</span></td>
                    <td class="fw-700 fs-sm3">{{ $currencySymbol }}{{ number_format($p->amount, 2) }}</td>
                    <td><span class="fs-sm2">{{ $p->gateway_label }}</span></td>
                    <td>
                        <span class="badge badge-{{ $p->status === 'paid' ? 'yes' : ($p->status === 'failed' ? 'no' : 'pending') }}">
                            {{ ucfirst($p->status) }}
                        </span>
                    </td>
                    <td class="fs-sm2">{{ $p->created_at->format('M d, Y') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="empty-state">
        <div class="empty-icon"><i class="bi bi-receipt"></i></div>
        <h3>{{ __('super.payments.no_payments') }}</h3>
        <p>{{ __('super.payments.no_payments_desc') }}</p>
    </div>
    @endif
</div>

@push('scripts')
<script>
window.PaymentsDashboardData = {
    labels:  @json($months->pluck('month')),
    revenue: @json($months->pluck('revenue')),
    currencySymbol: @json($currencySymbol),
};
</script>
<script src="{{ asset('assets_super/js/chart.umd.min.js') }}"></script>
<script src="{{ asset('assets_super/js/payments-dashboard.js') }}"></script>
@endpush
@endsection
