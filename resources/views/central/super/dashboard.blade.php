@extends('central.super.layout')

@section('title', __('super.dashboard.title'))

@push('styles')
<link href="{{ asset('assets_super/css/dashboard.css') }}" rel="stylesheet">
@endpush

@php
    $statusLabels = ['active' => __('super.status.active'), 'trial' => __('super.status.trial'), 'cancelled' => __('super.status.cancelled'), 'suspended' => __('super.status.suspended')];
    $statusColors = ['active' => '#10b981', 'trial' => '#3b82f6', 'cancelled' => '#ef4444', 'suspended' => '#f59e0b'];
    $subscriptionChartLabels = [];
    $subscriptionChartSeries = [];
    $subscriptionChartColors = [];
    foreach ($statusLabels as $key => $label) {
        $count = $subscriptionStatusCounts[$key] ?? 0;
        if ($count > 0 || $key === 'active' || $key === 'trial') {
            $subscriptionChartLabels[] = $label;
            $subscriptionChartSeries[] = (int) $count;
            $subscriptionChartColors[] = $statusColors[$key];
        }
    }
    if (empty($subscriptionChartSeries)) {
        $subscriptionChartLabels = [__('central.NoData')];
        $subscriptionChartSeries = [1];
        $subscriptionChartColors = ['#e2e8f0'];
    }
    $tenantsChartCategories = array_keys($tenantsByMonth);
    $tenantsChartData = array_values($tenantsByMonth);
    $plansChartCategories = array_keys($subscriptionsByPlan);
    $plansChartData = array_values($subscriptionsByPlan);

    $hour = now()->hour;
    $greeting = $hour < 12 ? __('super.dashboard.good_morning') : ($hour < 18 ? __('super.dashboard.good_afternoon') : __('super.dashboard.good_evening'));
    $adminUser = auth()->guard('central')->user();

    $envDbHost = config('database.connections.central.host', '127.0.0.1');
    $envDbPort = config('database.connections.central.port', '3306');
    $envDbUser = config('database.connections.central.username', '');
    $envDbPass = config('database.connections.central.password', '');
@endphp

@section('content')

{{-- Dashboard Header --}}
<div class="dash-header">
    <div>
        <h1 class="dash-greeting">{{ $greeting }}, {{ $adminUser->name ?? 'Admin' }}</h1>
        <p class="dash-subtitle">{{ __('super.dashboard.welcome') }}</p>
    </div>
    <div class="dash-date-badge">
        <i class="bi bi-calendar3"></i>
        {{ now()->translatedFormat('l, M d, Y') }}
    </div>
</div>

{{-- Key Metric Cards --}}
<div class="row g-3 g-xl-4 mb-4">
    <div class="col-6 col-xl-3">
        <div class="dash-metric dash-metric--indigo">
            <div class="dash-metric-icon dash-metric-icon--indigo">
                <i class="bi bi-building"></i>
            </div>
            <div class="dash-metric-value">{{ $tenantsCount }}</div>
            <div class="dash-metric-label">{{ __('super.dashboard.total_tenants') }}</div>
            <div class="dash-metric-footer">
                <a href="{{ route('super.tenants.index') }}" class="dash-metric-link">
                    {{ __('super.dashboard.view_all_tenants') }} <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="dash-metric dash-metric--emerald">
            <div class="dash-metric-icon dash-metric-icon--emerald">
                <i class="bi bi-check-circle"></i>
            </div>
            <div class="dash-metric-value">{{ $activeSubscriptions }}</div>
            <div class="dash-metric-label">{{ __('super.dashboard.active_trial') }}</div>
            <div class="dash-metric-footer">
                <a href="{{ route('super.subscriptions.index') }}" class="dash-metric-link">
                    {{ __('super.dashboard.view_subscriptions') }} <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="dash-metric dash-metric--blue">
            <div class="dash-metric-icon dash-metric-icon--blue">
                <i class="bi bi-tag-fill"></i>
            </div>
            <div class="dash-metric-value">{{ $plansCount }}</div>
            <div class="dash-metric-label">{{ __('super.dashboard.active_plans') }}</div>
            <div class="dash-metric-footer">
                <a href="{{ route('super.plans.index') }}" class="dash-metric-link">
                    {{ __('super.dashboard.manage_plans') }} <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="dash-metric dash-metric--amber">
            <div class="dash-metric-icon dash-metric-icon--amber">
                <i class="bi bi-pie-chart-fill"></i>
            </div>
            <div class="dash-metric-value">{{ array_sum($subscriptionStatusCounts ?? []) }}</div>
            <div class="dash-metric-label">{{ __('super.dashboard.total_subscriptions') }}</div>
            <div class="dash-metric-footer">
                <span class="dash-metric-detail">
                    {{ $subscriptionStatusCounts['active'] ?? 0 }} {{ __('super.dashboard.active') }}, {{ $subscriptionStatusCounts['trial'] ?? 0 }} {{ __('super.dashboard.trial') }}
                </span>
            </div>
        </div>
    </div>
</div>

{{-- Health Alert Strip --}}
@if($inactiveTenantsCount > 0 || $expiredSubscriptions > 0 || $suspendedTenantsCount > 0)
<div class="dash-alerts">
    @if($inactiveTenantsCount > 0)
    <div class="dash-alert dash-alert--warning">
        <div class="dash-alert-icon dash-alert-icon--warning">
            <i class="bi bi-moon-stars"></i>
        </div>
        <div class="dash-alert-body">
            <div>
                <span class="dash-alert-count">{{ $inactiveTenantsCount }}</span>
                <span class="dash-alert-label">{{ __('super.dashboard.inactive_tenants') }}</span>
            </div>
            <div class="dash-alert-desc">{{ __('super.dashboard.inactive_tenants_desc') }}</div>
        </div>
        <a href="{{ route('super.tenants.index', ['status' => 'inactive']) }}" class="dash-alert-action">
            {{ __('super.dashboard.view_inactive') }} <i class="bi bi-arrow-right"></i>
        </a>
    </div>
    @endif
    @if($expiredSubscriptions > 0)
    <div class="dash-alert dash-alert--danger">
        <div class="dash-alert-icon dash-alert-icon--danger">
            <i class="bi bi-clock-history"></i>
        </div>
        <div class="dash-alert-body">
            <div>
                <span class="dash-alert-count">{{ $expiredSubscriptions }}</span>
                <span class="dash-alert-label">{{ __('super.dashboard.expired_subs') }}</span>
            </div>
        </div>
        <a href="{{ route('super.subscriptions.index') }}" class="dash-alert-action">
            {{ __('super.dashboard.view_expired') }} <i class="bi bi-arrow-right"></i>
        </a>
    </div>
    @endif
    @if($suspendedTenantsCount > 0)
    <div class="dash-alert dash-alert--indigo">
        <div class="dash-alert-icon dash-alert-icon--indigo">
            <i class="bi bi-pause-circle"></i>
        </div>
        <div class="dash-alert-body">
            <div>
                <span class="dash-alert-count">{{ $suspendedTenantsCount }}</span>
                <span class="dash-alert-label">{{ __('super.dashboard.suspended_tenants') }}</span>
            </div>
        </div>
        <a href="{{ route('super.tenants.index') }}" class="dash-alert-action">
            {{ __('super.dashboard.view_suspended') }} <i class="bi bi-arrow-right"></i>
        </a>
    </div>
    @endif
</div>
@endif

{{-- Pending Tenants --}}
@if($pendingTenants->count() > 0)
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="content-card">
            <div class="card-header-custom d-flex align-items-center justify-content-between">
                <h2><i class="bi bi-hourglass-split me-2 text-warning"></i>{{ __('central.PendingTenants') }} <span class="badge bg-warning text-dark ms-2 fs-xs2">{{ $pendingTenants->count() }}</span></h2>
            </div>
            <div class="card-body-custom p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 table-pending">
                        <thead>
                            <tr>
                                <th>{{ __('central.TenantName') }}</th>
                                <th>{{ __('central.Subdomain') }}</th>
                                <th>{{ __('central.Email') }}</th>
                                <th>{{ __('central.RegistrationDate') }}</th>
                                <th class="text-end">{{ __('central.Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($pendingTenants as $pt)
                            <tr>
                                <td>
                                    <span class="fw-600">{{ $pt->company_name ?? $pt->id }}</span>
                                </td>
                                <td>
                                    <code class="code-badge">{{ $pt->domains->first()->domain ?? '—' }}</code>
                                </td>
                                <td>
                                    {{ $pt->admin_email ?? '—' }}
                                </td>
                                <td>
                                    {{ $pt->created_at->format('M d, Y H:i') }}
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-approve-trigger btn-approve"
                                        data-toggle-db="{{ $pt->id }}">
                                        <i class="bi bi-database-gear me-1"></i>{{ __('central.ConfigureAndApprove') }}
                                    </button>
                                    <button type="button" class="btn btn-sm btn-reject-trigger btn-reject ms-4px"
                                        data-tenant-id="{{ $pt->id }}" data-tenant-name="{{ $pt->company_name ?? $pt->id }}">
                                        <i class="bi bi-x-lg me-1"></i>{{ __('central.Reject') }}
                                    </button>
                                </td>
                            </tr>
                            {{-- Database configuration row (hidden by default) --}}
                            <tr id="db-form-{{ $pt->id }}" class="db-form-row">
                                <td colspan="5" class="db-config-td">
                                    <div class="db-config-panel">
                                        <div class="d-flex align-items-center gap-2 mb-3">
                                            <i class="bi bi-database-fill-gear text-primary-mid fs-lg2"></i>
                                            <h6 class="mb-0 fw-700 db-config-title">{{ __('central.DatabaseConfigFor', ['name' => $pt->company_name ?? $pt->id]) }}</h6>
                                        </div>
                                        <form method="POST" action="{{ route('super.tenants.approve', $pt) }}" id="approve-form-{{ $pt->id }}">
                                            @csrf
                                            {{-- Subdomain reminder (shared hosting only) --}}
                                            @if(\App\Models\Central\GeneralSetting::instance()->isSharedHosting())
                                            @php $subdomainName = $pt->domains->first()->domain ?? null; @endphp
                                            @if($subdomainName)
                                            <div class="mb-3 p-3 rounded-3 notice-warning">
                                                <div class="d-flex align-items-start gap-2">
                                                    <i class="bi bi-exclamation-triangle-fill notice-warning-icon"></i>
                                                    <div>
                                                        <p class="mb-1 notice-title">{{ __('central.SubdomainSetupRequired') }}</p>
                                                        <p class="mb-2 notice-text">
                                                            {{ __('central.SubdomainSetupDesc') }}
                                                        </p>
                                                        <code class="code-badge-warning">
                                                            {{ $subdomainName }}.{{ preg_replace('/^www\./i', '', parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST) ?? 'localhost') }}
                                                        </code>
                                                        <div class="mt-2">
                                                            <label class="d-flex align-items-center gap-2 notice-label">
                                                                <input type="checkbox" name="subdomain_confirmed" value="1" class="accent-warning">
                                                                {{ __('central.SubdomainCreatedConfirm') }}
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            @endif
                                            @endif
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <label class="form-label form-label-sm">{{ __('central.DbHost') }} <span class="text-danger">*</span></label>
                                                    <input type="text" name="db_host" class="form-control form-control-sm form-control-sm-custom" value="{{ old('db_host', $envDbHost) }}" required placeholder="{{ $envDbHost }}">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label form-label-sm">{{ __('central.DbName') }} <span class="text-danger">*</span></label>
                                                    <input type="text" name="db_name" class="form-control form-control-sm form-control-sm-custom" value="{{ old('db_name') }}" required placeholder="{{ __('central.DbNamePlaceholder') }}">
                                                    <small class="form-text text-muted db-form-hint">{{ __('central.DbNameHint', ['suggestion' => 'tenant_' . ($pt->domains->first()->domain ?? 'name')]) }}</small>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label form-label-sm">{{ __('central.DbPort') }} <span class="text-danger">*</span></label>
                                                    <input type="number" name="db_port" class="form-control form-control-sm form-control-sm-custom" value="{{ old('db_port', $envDbPort) }}" required min="1" max="65535">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label form-label-sm">{{ __('central.DbUsername') }} <span class="text-danger">*</span></label>
                                                    <input type="text" name="db_username" class="form-control form-control-sm form-control-sm-custom" value="{{ old('db_username', $envDbUser) }}" required placeholder="{{ $envDbUser ?: 'db_user' }}">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label form-label-sm">{{ __('central.DbPassword') }}</label>
                                                    <input type="password" name="db_password" class="form-control form-control-sm form-control-sm-custom" value="{{ old('db_password', $envDbPass) }}" placeholder="••••••••">
                                                </div>
                                                <div class="col-md-4 d-flex align-items-end gap-2">
                                                    <button type="button" class="btn btn-sm btn-test-connection" data-test-db="{{ $pt->id }}">
                                                        <i class="bi bi-plug me-1"></i>{{ __('central.TestConnection') }}
                                                    </button>
                                                </div>
                                            </div>
                                            <div id="db-test-result-{{ $pt->id }}" class="mt-2 db-test-result"></div>
                                            <div class="d-flex justify-content-end gap-2 mt-3 pt-3 border-top-border-light">
                                                <button type="button" class="btn btn-sm btn-outline-secondary btn-cancel-sm" data-toggle-db="{{ $pt->id }}">{{ __('central.Cancel') }}</button>
                                                <button type="button" class="btn btn-sm btn-approve approve-locked" data-confirm-approve="{{ $pt->id }}">
                                                    <i class="bi bi-check-lg me-1"></i>{{ __('central.ApproveAndProvision') }}
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Reject Modal --}}
<div id="rejectModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-icon-danger">
            <i class="bi bi-x-circle"></i>
        </div>
        <h4 class="modal-title">{{ __('central.RejectTenant') }}</h4>
        <p class="text-muted fs-sm4" id="rejectModalTenantName"></p>
        <form method="POST" id="rejectForm">
            @csrf
            <div class="mb-3 text-start">
                <label class="form-label form-control-sm-custom fw-600">{{ __('central.RejectionReason') }}</label>
                <textarea name="reason" class="form-control fs-sm4" rows="3" required placeholder="{{ __('central.RejectionReasonPlaceholder') }}"></textarea>
            </div>
            <div class="d-flex gap-2 justify-content-center">
                <button type="button" class="btn btn-outline-secondary" id="closeRejectModalBtn">{{ __('central.Cancel') }}</button>
                <button type="submit" class="btn btn-danger">{{ __('central.RejectTenant') }}</button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- Charts Row 1: Tenant Growth + Subscription Status --}}
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="content-card h-100">
            <div class="card-header-custom">
                <h2><i class="bi bi-graph-up me-2 text-muted"></i>{{ __('super.dashboard.tenants_chart') }}</h2>
                <span class="dash-chart-period">{{ __('super.dashboard.last_6_months') }}</span>
            </div>
            <div class="card-body-custom">
                <div id="chart-tenants" class="chart-min-height"></div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="content-card h-100">
            <div class="card-header-custom">
                <h2><i class="bi bi-pie-chart me-2 text-muted"></i>{{ __('super.dashboard.subscription_status') }}</h2>
            </div>
            <div class="card-body-custom">
                <div id="chart-subscriptions" class="chart-min-height"></div>
            </div>
        </div>
    </div>
</div>

{{-- Charts Row 2: Subs by Plan + Quick Actions --}}
<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="content-card h-100">
            <div class="card-header-custom">
                <h2><i class="bi bi-bar-chart me-2 text-muted"></i>{{ __('super.dashboard.subs_by_plan') }}</h2>
                <span class="dash-chart-period">Top 5</span>
            </div>
            <div class="card-body-custom">
                <div id="chart-plans" class="chart-min-height"></div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="content-card h-100">
            <div class="card-header-custom">
                <h2><i class="bi bi-lightning-charge me-2 text-muted"></i>{{ __('super.dashboard.quick_actions') }}</h2>
            </div>
            <div class="card-body-custom">
                <div class="dash-actions-grid">
                    <a href="{{ route('super.tenants.index') }}" class="dash-action">
                        <div class="dash-action-icon dash-action-icon--indigo">
                            <i class="bi bi-building"></i>
                        </div>
                        <span class="dash-action-title">{{ __('super.dashboard.manage_tenants') }}</span>
                        <span class="dash-action-desc">{{ __('super.dashboard.manage_tenants_desc') }}</span>
                    </a>
                    <a href="{{ route('super.plans.create') }}" class="dash-action">
                        <div class="dash-action-icon dash-action-icon--emerald">
                            <i class="bi bi-plus-lg"></i>
                        </div>
                        <span class="dash-action-title">{{ __('super.dashboard.create_plan') }}</span>
                        <span class="dash-action-desc">{{ __('super.dashboard.create_plan_desc') }}</span>
                    </a>
                    <a href="{{ route('super.subscriptions.index') }}" class="dash-action">
                        <div class="dash-action-icon dash-action-icon--blue">
                            <i class="bi bi-credit-card-2-front"></i>
                        </div>
                        <span class="dash-action-title">{{ __('super.dashboard.subscriptions') }}</span>
                        <span class="dash-action-desc">{{ __('super.dashboard.subscriptions_desc') }}</span>
                    </a>
                    <a href="{{ route('central.welcome') }}" target="_blank" class="dash-action">
                        <div class="dash-action-icon dash-action-icon--amber">
                            <i class="bi bi-box-arrow-up-right"></i>
                        </div>
                        <span class="dash-action-title">{{ __('super.dashboard.visit_landing') }}</span>
                        <span class="dash-action-desc">{{ __('super.dashboard.visit_landing_desc') }}</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
window.DashboardData = {
    tenantsUrl: @json(url('super/tenants')),
    csrfToken:  @json(csrf_token()),
    trans: {
        testingConnection:   @json(__('central.TestingConnection')),
        approveTenantTitle:  @json(__('central.ApproveTenantTitle')),
        approveTenantHtml:   @json(__('central.ApproveTenantHtml')),
        yesApprove:          @json(__('central.YesApprove')),
        cancelText:          @json(__('central.Cancel')),
        provisioningTitle:   @json(__('central.ProvisioningInProgress')),
        provisioningHtml:    @json(__('central.ApprovingInProgressHtml')),
        rejectRegistrationFor: @json(__('central.RejectRegistrationFor')),
        requestFailed:       @json(__('central.RequestFailed')),
        fillDbFields:        @json(__('central.FillDbFieldsBeforeTesting')),
        creatingDatabase:    @json(__('central.CreatingDatabase')),
        createDatabase:      @json(__('central.CreateDatabase')),
        testConnectionFirst: @json(__('central.TestConnectionFirst')),
        testConnectionFirstText: @json(__('central.TestConnectionFirstText')),
        tenantApprovedTitle: @json(__('central.TenantApproved')),
        tenantApprovedText:  @json(__('central.TenantApprovedText')),
        provisioningFailedTitle: @json(__('central.ProvisioningFailed')),
        provisioningTimedOutText: @json(__('central.ProvisioningTimedOutText')),
        verifyingConnection: @json(__('central.VerifyingConnection')),
        connectionCheckFailed: @json(__('central.ConnectionCheckFailed')),
    },
    charts: {
        tenantsCategories:    @json($tenantsChartCategories),
        tenantsData:          @json($tenantsChartData),
        newTenantsLabel:      @json(__('central.NewTenants')),
        subscriptionLabels:   @json($subscriptionChartLabels),
        subscriptionSeries:   @json($subscriptionChartSeries),
        subscriptionColors:   @json($subscriptionChartColors),
        planCategories:       @json($plansChartCategories),
        planData:             @json($plansChartData),
        subscriptionsLabel:   @json(__('central.Subscriptions')),
        noDataLabel:          @json(__('super.dashboard.no_data')),
        noDataDesc:           @json(__('super.dashboard.no_data_desc')),
    },
};
</script>
<script src="{{ asset('assets_super/js/apexcharts.min.js') }}"></script>
<script src="{{ asset('assets_super/js/dashboard.js') }}"></script>
@endpush
@endsection
