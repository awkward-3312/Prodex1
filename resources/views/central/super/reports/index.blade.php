@extends('central.super.layout')

@section('title', __('super.reports.title'))

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
        <h1>{{ __('super.reports.title') }}</h1>
        <p class="page-subtitle">{{ __('super.reports.subtitle') }}</p>
    </div>
</div>

{{-- KPI stat cards --}}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="content-card stat-card h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon stat-icon-primary"><i class="bi bi-building"></i></div>
                <div>
                    <div class="stat-label">{{ __('super.reports.total_tenants') }}</div>
                    <div class="stat-value">{{ number_format($totalTenants) }}</div>
                    <div class="small text-muted">{{ $activeTenants }} {{ __('super.reports.active') }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="content-card stat-card h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon stat-icon-success"><i class="bi bi-credit-card-2-front"></i></div>
                <div>
                    <div class="stat-label">{{ __('super.reports.active_subscriptions') }}</div>
                    <div class="stat-value">{{ number_format($activeSubscriptions) }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="content-card stat-card h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon stat-icon-info"><i class="bi bi-arrow-repeat"></i></div>
                <div>
                    <div class="stat-label">{{ __('super.reports.mrr') }}</div>
                    <div class="stat-value">{{ $currencySymbol }}{{ number_format($mrr, 2) }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="content-card stat-card h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon stat-icon-warning"><i class="bi bi-percent"></i></div>
                <div>
                    <div class="stat-label">{{ __('super.reports.churn_rate') }}</div>
                    <div class="stat-value">{{ $churnRate }}%</div>
                    <div class="small text-muted">{{ __('super.reports.last_30_days') }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Charts row --}}
<div class="row g-4 mb-4">
    {{-- Tenant growth --}}
    <div class="col-lg-8">
        <div class="content-card h-100">
            <div class="card-header-custom">
                <h2><i class="bi bi-graph-up-arrow me-2 text-muted"></i>{{ __('super.reports.tenant_growth') }}</h2>
            </div>
            <div class="card-body-custom">
                <div id="tenantGrowthChart" style="height: 320px;"></div>
            </div>
        </div>
    </div>

    {{-- Tenant status donut --}}
    <div class="col-lg-4">
        <div class="content-card h-100">
            <div class="card-header-custom">
                <h2><i class="bi bi-pie-chart me-2 text-muted"></i>{{ __('super.reports.tenant_status') }}</h2>
            </div>
            <div class="card-body-custom d-flex align-items-center justify-content-center">
                @if(array_sum($tenantStatuses) > 0)
                    <div id="tenantStatusChart" style="width: 100%; min-height: 300px;"></div>
                @else
                    <p class="text-muted mb-0">{{ __('super.reports.no_data') }}</p>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Revenue + Subscriptions by plan --}}
<div class="row g-4 mb-4">
    {{-- Revenue trend --}}
    <div class="col-lg-8">
        <div class="content-card h-100">
            <div class="card-header-custom d-flex align-items-center justify-content-between">
                <h2><i class="bi bi-currency-dollar me-2 text-muted"></i>{{ __('super.reports.revenue_trend') }}</h2>
                <span class="badge bg-light text-dark fw-600 fs-xs2">{{ $currencySymbol }}{{ number_format($totalRevenue, 2) }} {{ __('super.reports.total') }}</span>
            </div>
            <div class="card-body-custom">
                <div id="revenueChart" style="height: 320px;"></div>
            </div>
        </div>
    </div>

    {{-- Subscriptions by plan --}}
    <div class="col-lg-4">
        <div class="content-card h-100">
            <div class="card-header-custom">
                <h2><i class="bi bi-diagram-3 me-2 text-muted"></i>{{ __('super.reports.by_plan') }}</h2>
            </div>
            <div class="card-body-custom">
                @if($subscriptionsByPlan->isNotEmpty())
                    <div id="planChart" style="width: 100%; min-height: 300px;"></div>
                @else
                    <p class="text-muted text-center py-3 mb-0">{{ __('super.reports.no_data') }}</p>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Top plans by revenue + Recent tenants --}}
<div class="row g-4 mb-4">
    {{-- Top plans by revenue --}}
    <div class="col-lg-5">
        <div class="content-card h-100">
            <div class="card-header-custom">
                <h2><i class="bi bi-trophy me-2 text-muted"></i>{{ __('super.reports.top_plans') }}</h2>
            </div>
            @if($topPlansByRevenue->isNotEmpty())
            <div class="card-body-custom">
                <div class="d-flex flex-column gap-3">
                    @php $maxRevenue = $topPlansByRevenue->max('revenue'); @endphp
                    @foreach($topPlansByRevenue as $plan)
                    <div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-600 fs-sm3">{{ $plan->plan_name }}</span>
                            <span class="fw-700 fs-sm3">{{ $currencySymbol }}{{ number_format($plan->revenue, 2) }}</span>
                        </div>
                        @php $pct = $maxRevenue > 0 ? ($plan->revenue / $maxRevenue * 100) : 0; @endphp
                        <div class="progress-bar-track">
                            <div class="progress-bar-fill" style="--progress: {{ $pct }}%;"></div>
                        </div>
                        <p class="mb-0 text-muted fs-xs2">{{ $plan->transactions }} {{ __('super.reports.transactions') }}</p>
                    </div>
                    @endforeach
                </div>
            </div>
            @else
            <div class="card-body-custom">
                <p class="text-muted text-center py-3 mb-0">{{ __('super.reports.no_data') }}</p>
            </div>
            @endif
        </div>
    </div>

    {{-- Recent tenants --}}
    <div class="col-lg-7">
        <div class="content-card h-100">
            <div class="card-header-custom d-flex align-items-center justify-content-between">
                <h2><i class="bi bi-clock-history me-2 text-muted"></i>{{ __('super.reports.recent_tenants') }}</h2>
                <a href="{{ route('super.tenants.index') }}" class="btn btn-outline btn-sm">{{ __('super.reports.view_all') }}</a>
            </div>
            @if($recentTenants->isNotEmpty())
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('super.reports.tenant') }}</th>
                            <th>{{ __('super.reports.domain') }}</th>
                            <th>{{ __('super.common.status') }}</th>
                            <th>{{ __('super.reports.registered') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentTenants as $t)
                        <tr>
                            <td>
                                <a href="{{ route('super.tenants.show', $t) }}" class="fw-600 fs-sm3">{{ $t->company_name ?? $t->id }}</a>
                            </td>
                            <td class="fs-sm2 text-muted">{{ $t->domains->first()->domain ?? '—' }}</td>
                            <td>
                                @php
                                    $badgeMap = [
                                        'active'       => 'active',
                                        'trial'        => 'trial',
                                        'pending'      => 'pending',
                                        'provisioning' => 'pending',
                                        'suspended'    => 'suspended',
                                        'cancelled'    => 'cancelled',
                                        'failed'       => 'failed',
                                        'rejected'     => 'rejected',
                                    ];
                                @endphp
                                <span class="badge badge-{{ $badgeMap[$t->status] ?? 'secondary' }}">{{ ucfirst($t->status) }}</span>
                            </td>
                            <td class="fs-sm2 text-muted">{{ $t->created_at->format('M d, Y') }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="empty-state">
                <div class="empty-icon"><i class="bi bi-building"></i></div>
                <h3>{{ __('super.reports.no_tenants') }}</h3>
            </div>
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
window.ReportsData = {
    tenantGrowth:  @json($tenantGrowth),
    hasStatuses:   {{ array_sum($tenantStatuses) > 0 ? 'true' : 'false' }},
    statusLabels:  @json(array_keys($tenantStatuses)),
    statusValues:  @json(array_values($tenantStatuses)),
    revenueTrend:  @json($revenueTrend),
    hasPlanData:   {{ $subscriptionsByPlan->isNotEmpty() ? 'true' : 'false' }},
    planLabels:    @json($subscriptionsByPlan->pluck('plan_name')),
    planValues:    @json($subscriptionsByPlan->pluck('count')),
    currencySymbol: @json($currencySymbol),
    trans: {
        newTenants: @json(__('super.reports.new_tenants')),
        tenantsLc:  @json(__('super.reports.tenants_lc')),
        revenue:    @json(__('super.reports.revenue')),
        total:      @json(__('super.reports.total')),
    },
};
</script>
<script src="{{ asset('assets_super/js/apexcharts.min.js') }}"></script>
<script src="{{ asset('assets_super/js/reports.js') }}"></script>
@endpush
@endsection
