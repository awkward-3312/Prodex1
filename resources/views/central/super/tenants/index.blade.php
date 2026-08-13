@extends('central.super.layout')

@section('title', __('super.tenants.title'))

@php
    $envDbHost = config('database.connections.central.host', '127.0.0.1');
    $envDbPort = config('database.connections.central.port', '3306');
    $envDbUser = config('database.connections.central.username', '');
    $envDbPass = config('database.connections.central.password', '');
@endphp

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.tenants.title') }}</h1>
        <p class="page-subtitle">{{ __('super.tenants.subtitle') }}</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('super.tenants.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg"></i> {{ __('central.CreateTenant') }}
        </a>
        <button class="btn btn-outline btn-sm" id="btnRefreshPage">
            <i class="bi bi-arrow-clockwise"></i> {{ __('super.common.refresh') }}
        </button>
    </div>
</div>

<div class="content-card">
    <div class="filter-bar">
        <form method="GET" action="{{ route('super.tenants.index') }}" class="d-flex align-items-center gap-3 flex-wrap w-100" id="filterForm">
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('super.tenants.search') }}" autocomplete="off">
            </div>
            <select class="form-control filter-select w-auto" name="status" id="statusFilter">
                <option value="">{{ __('super.tenants.all_statuses') }}</option>
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>{{ __('super.status.active') }}</option>
                <option value="trial" {{ request('status') === 'trial' ? 'selected' : '' }}>{{ __('super.status.trial') }}</option>
                <option value="suspended" {{ request('status') === 'suspended' ? 'selected' : '' }}>{{ __('super.status.suspended') }}</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>{{ __('central.Pending') }}</option>
                <option value="provisioning" {{ request('status') === 'provisioning' ? 'selected' : '' }}>{{ __('central.Provisioning') }}</option>
                <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>{{ __('central.Failed') }}</option>
                <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>{{ __('super.status.rejected') }}</option>
                <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>{{ __('super.status.cancelled') }}</option>
                <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>{{ __('super.status.inactive') }}</option>
            </select>
            <span class="text-muted small ms-auto">{{ $tenants->total() }} {{ Str::plural(__('central.TenantWord'), $tenants->total()) }}</span>
        </form>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>{{ __('super.tenants.tenant') }}</th>
                    <th>{{ __('super.tenants.domains') }}</th>
                    <th>{{ __('super.common.status') }}</th>
                    <th>{{ __('super.tenants.plan') }}</th>
                    <th>{{ __('super.tenants.created') }}</th>
                    <th class="action-cell"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($tenants as $t)
                @php
                    $displayName = $t->company_name ?? ($t->domains->first()->domain ?? $t->id);
                    $colors = ['purple', 'blue', 'green', 'amber', 'rose'];
                    $color = $colors[crc32($t->id) % count($colors)];
                    $initial = strtoupper(mb_substr($displayName, 0, 1));
                @endphp
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-3">
                            <div class="tenant-avatar tenant-avatar-{{ $color }}">{{ $initial }}</div>
                            <div>
                                <p class="mb-0 fw-600 fs-md">{{ $displayName }}</p>
                                <p class="mb-0 text-muted fs-xs3">
                                    {{ $t->admin_email ?? ('Created ' . $t->created_at->diffForHumans()) }}
                                </p>
                            </div>
                        </div>
                    </td>
                    <td>
                        @if($t->domains->isNotEmpty())
                            @foreach($t->domains->take(2) as $d)
                                <span class="domain-tag me-1 mb-1">{{ $d->domain }}</span>
                            @endforeach
                            @if($t->domains->count() > 2)
                                <span class="text-muted small">+{{ $t->domains->count() - 2 }} {{ __('super.common.more') }}</span>
                            @endif
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td><span class="badge badge-{{ $t->status }}">{{ ucfirst($t->status) }}</span></td>
                    <td>
                        @if($t->subscription?->plan)
                            <span class="fw-500">{{ $t->subscription->plan->name }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="fs-sm3">{{ $t->created_at->format('M d, Y') }}</span>
                    </td>
                    <td class="action-cell">
                        <div class="d-flex gap-2 justify-content-end">
                            @if($t->status === 'pending')
                                <button type="button" class="btn btn-sm btn-approve" data-toggle-db="{{ $t->id }}">
                                    <i class="bi bi-database-gear me-1"></i>{{ __('central.ConfigureAndApprove') }}
                                </button>
                                <button type="button" class="btn btn-sm btn-reject-trigger btn-reject"
                                    data-tenant-id="{{ $t->id }}" data-tenant-name="{{ $t->company_name ?? $t->id }}">
                                    <i class="bi bi-x-lg me-1"></i>{{ __('central.Reject') }}
                                </button>
                            @else
                                <a href="{{ route('super.tenants.show', $t) }}" class="btn btn-sm btn-outline">
                                    <i class="bi bi-eye"></i> {{ __('super.common.view') }}
                                </a>
                                <button type="button" class="btn btn-sm btn-db-config" data-toggle-db="{{ $t->id }}" title="{{ __('central.DatabaseConfig') }}">
                                    <i class="bi bi-database-gear"></i>
                                </button>
                                <a href="{{ route('super.tenants.edit', $t) }}" class="btn btn-sm btn-ghost">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            @endif
                        </div>
                    </td>
                </tr>
                {{-- DB config form row --}}
                @if($t->status === 'pending')
                <tr id="db-form-{{ $t->id }}" class="db-form-row">
                    <td colspan="6" class="td-no-padding">
                        <div class="db-config-panel">
                            <div class="d-flex align-items-center gap-2 mb-3">
                                <i class="bi bi-database-fill-gear text-primary-mid fs-lg2"></i>
                                <h6 class="mb-0 fw-700 db-config-title">{{ __('central.DatabaseConfigFor', ['name' => $t->company_name ?? $t->id]) }}</h6>
                            </div>
                            <form method="POST" action="{{ route('super.tenants.approve', $t) }}" id="approve-form-{{ $t->id }}">
                                @csrf
                                {{-- Subdomain reminder for shared hosting --}}
                                @if(\App\Models\Central\GeneralSetting::instance()->isSharedHosting())
                                @php $subdomainName = $t->domains->first()->domain ?? null; @endphp
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
                                                    {{ __('central.SubdomainConfirmCheck') }}
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                @endif
                                @endif
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label db-form-label">{{ __('central.DbHost') }} <span class="text-danger">*</span></label>
                                        <input type="text" name="db_host" class="form-control form-control-sm db-form-input" value="{{ old('db_host', $envDbHost) }}" required placeholder="{{ $envDbHost }}">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label db-form-label">{{ __('central.DbName') }} <span class="text-danger">*</span></label>
                                        <input type="text" name="db_name" class="form-control form-control-sm db-form-input" value="{{ old('db_name') }}" required placeholder="{{ __('central.DbNamePlaceholder') }}">
                                        <small class="form-text text-muted db-form-hint">{{ __('central.DbNameHint', ['suggestion' => 'tenant_' . ($t->domains->first()->domain ?? 'name')]) }}</small>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label db-form-label">{{ __('central.DbPort') }} <span class="text-danger">*</span></label>
                                        <input type="number" name="db_port" class="form-control form-control-sm db-form-input" value="{{ old('db_port', $envDbPort) }}" required min="1" max="65535">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label db-form-label">{{ __('central.DbUser') }} <span class="text-danger">*</span></label>
                                        <input type="text" name="db_username" class="form-control form-control-sm db-form-input" value="{{ old('db_username', $envDbUser) }}" required placeholder="{{ $envDbUser ?: 'db_user' }}">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label db-form-label">{{ __('central.DbPassword') }}</label>
                                        <input type="password" name="db_password" class="form-control form-control-sm db-form-input" value="{{ old('db_password', $envDbPass) }}" placeholder="••••••••">
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end gap-2">
                                        <button type="button" class="btn btn-sm btn-test-db" data-test-db="{{ $t->id }}">
                                            <i class="bi bi-plug me-1"></i>{{ __('central.TestConnection') }}
                                        </button>
                                    </div>
                                </div>
                                <div id="db-test-result-{{ $t->id }}" class="mt-2 db-test-result"></div>
                                <div class="d-flex justify-content-end gap-2 mt-3 pt-3 border-top-light">
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-cancel-sm" data-toggle-db="{{ $t->id }}">{{ __('central.Cancel') }}</button>
                                    <button type="button" class="btn btn-sm btn-approve-db approve-locked" data-confirm-approve="{{ $t->id }}">
                                        <i class="bi bi-check-lg me-1"></i>{{ __('central.ApproveAndProvision') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </td>
                </tr>
                @else
                {{-- DB config form row for active/approved tenants --}}
                @php
                    $existingCreds = $t->getEffectiveDatabaseCredentials();
                    $isCustomCreds = $t->hasDatabaseCredentials();
                @endphp
                <tr id="db-form-{{ $t->id }}" class="db-form-row">
                    <td colspan="6" class="td-no-padding">
                        <div class="db-config-panel">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-database-fill-gear text-primary-mid fs-lg2"></i>
                                    <h6 class="mb-0 fw-700 db-config-title">{{ __('central.DatabaseConfigFor', ['name' => $displayName]) }}</h6>
                                </div>
                                @if($isCustomCreds)
                                <span class="badge badge-pill-primary">
                                    <i class="bi bi-person-gear me-1"></i>{{ __('central.AdminConfigured') }}
                                </span>
                                @elseif($existingCreds)
                                <span class="badge badge-pill-success">
                                    <i class="bi bi-gear me-1"></i>{{ __('central.AutoProvisioned') }}
                                </span>
                                @else
                                <span class="badge badge-pill-danger">
                                    <i class="bi bi-exclamation-circle me-1"></i>{{ __('central.NotConfigured') }}
                                </span>
                                @endif
                            </div>
                            <div class="mb-3 p-3 rounded-3" style="background:#fef2f2; border:1px solid #fecaca;">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="bi bi-exclamation-triangle-fill text-danger flex-shrink-0" style="font-size:1rem; margin-top:2px;"></i>
                                    <p class="mb-0" style="font-size:0.82rem; color:#991b1b;">{{ __('central.ActiveTenantDbWarning') }}</p>
                                </div>
                            </div>
                            <form method="POST" action="{{ route('super.tenants.update-credentials', $t) }}" id="creds-form-{{ $t->id }}">
                                @csrf
                                @method('PUT')
                                {{-- Subdomain info for shared hosting --}}
                                @if(\App\Models\Central\GeneralSetting::instance()->isSharedHosting())
                                @php $subdomainName = $t->domains->first()->domain ?? null; @endphp
                                @if($subdomainName)
                                <div class="mb-3 p-2 rounded-2 notice-subdomain">
                                    <p class="mb-0 d-flex align-items-center gap-2 notice-subdomain-text">
                                        <i class="bi bi-info-circle-fill"></i>
                                        {{ __('central.SubdomainLabel') }}: <code class="code-badge-warning">{{ $subdomainName }}.{{ preg_replace('/^www\./i', '', parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST) ?? 'localhost') }}</code>
                                        — {{ __('central.EnsureSubdomainExists') }}
                                    </p>
                                </div>
                                @endif
                                @endif
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label db-form-label">{{ __('central.DbHost') }} <span class="text-danger">*</span></label>
                                        <input type="text" name="db_host" class="form-control form-control-sm db-form-input" value="{{ $existingCreds['host'] ?? 'localhost' }}" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label db-form-label">{{ __('central.DbName') }} <span class="text-danger">*</span></label>
                                        <input type="text" name="db_name" class="form-control form-control-sm db-form-input" value="{{ $existingCreds['database'] ?? '' }}" required placeholder="tenant_{{ $t->domains->first()->domain ?? 'name' }}">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label db-form-label">{{ __('central.DbPort') }} <span class="text-danger">*</span></label>
                                        <input type="number" name="db_port" class="form-control form-control-sm db-form-input" value="{{ $existingCreds['port'] ?? 3306 }}" required min="1" max="65535">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label db-form-label">{{ __('central.DbUser') }} <span class="text-danger">*</span></label>
                                        <input type="text" name="db_username" class="form-control form-control-sm db-form-input" value="{{ $existingCreds['username'] ?? '' }}" required placeholder="db_user">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label db-form-label">{{ __('central.DbPassword') }}</label>
                                        <div class="input-group input-group-sm">
                                            <input type="password" name="db_password" id="db-pwd-{{ $t->id }}" class="form-control form-control-sm db-form-input" value="{{ $existingCreds['password'] ?? '' }}">
                                            <button type="button" class="btn btn-outline-secondary btn-eye-sm" data-toggle-pwd="db-pwd-{{ $t->id }}">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end gap-2">
                                        <button type="button" class="btn btn-sm btn-test-db" data-test-db="{{ $t->id }}">
                                            <i class="bi bi-plug me-1"></i>{{ __('central.TestConnection') }}
                                        </button>
                                    </div>
                                </div>
                                <div id="db-test-result-{{ $t->id }}" class="mt-2 db-test-result"></div>
                                <div class="d-flex justify-content-end gap-2 mt-3 pt-3 border-top-light">
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-cancel-sm" data-toggle-db="{{ $t->id }}">{{ __('central.Cancel') }}</button>
                                    <button type="button" class="btn btn-sm btn-save-db approve-locked" data-swal-creds-save="{{ $t->id }}" data-swal-opts="{{ json_encode(['title' => __('central.UpdateCredentialsTitle'), 'text' => __('central.UpdateCredentialsText'), 'icon' => 'question', 'confirmText' => __('central.YesSave'), 'color' => '#6366f1']) }}">
                                        <i class="bi bi-save me-1"></i>{{ __('central.SaveCredentials') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </td>
                </tr>
                @endif
                @empty
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <div class="empty-icon"><i class="bi bi-building"></i></div>
                            <h3>{{ __('super.tenants.no_tenants') }}</h3>
                            <p>{{ __('super.tenants.no_tenants_desc') }}</p>
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($tenants->hasPages())
<div class="mt-4 d-flex justify-content-center">
    {{ $tenants->links('vendor.pagination.bootstrap-5') }}
</div>
@endif

{{-- Reject Modal --}}
@if($tenants->contains('status', 'pending'))
<div id="rejectModal" class="reject-modal-overlay">
    <div class="reject-modal-box">
        <div class="reject-modal-icon">
            <i class="bi bi-x-circle"></i>
        </div>
        <h4 class="reject-modal-title">{{ __('central.RejectTenant') }}</h4>
        <p class="text-muted fs-sm4" id="rejectModalTenantName"></p>
        <form method="POST" id="rejectForm">
            @csrf
            <div class="mb-3 text-start">
                <label class="form-label fs-sm3 fw-600">{{ __('central.RejectionReason') }}</label>
                <textarea name="reason" class="form-control fs-sm4" rows="3" required placeholder="{{ __('central.RejectionReasonPlaceholder') }}"></textarea>
            </div>
            <div class="d-flex gap-2 justify-content-center">
                <button type="button" class="btn btn-outline-secondary" id="btnCloseRejectModal">{{ __('central.Cancel') }}</button>
                <button type="submit" class="btn btn-danger">{{ __('central.RejectTenant') }}</button>
            </div>
        </form>
    </div>
</div>
@endif

@push('scripts')
<script>
window.TenantsIndexData = {
    csrfToken: '{{ csrf_token() }}',
    tenantsBaseUrl: '{{ url("super/tenants") }}',
    trans: {
        rejectRegistrationFor: @json(__('central.RejectRegistrationFor')),
        approveTenantTitle: @json(__('central.ApproveTenantTitle')),
        approveTenantHtml: @json(__('central.ApproveTenantHtml')),
        yesApprove: @json(__('central.YesApprove')),
        cancelText: @json(__('central.Cancel')),
        provisioningTitle: @json(__('central.ProvisioningInProgress')),
        provisioningHtml: @json(__('central.ProvisioningInProgressHtml')),
        tenantApprovedTitle: @json(__('central.TenantApproved')),
        tenantApprovedText: @json(__('central.TenantApprovedText')),
        provisioningFailedTitle: @json(__('central.ProvisioningFailed')),
        testingConnection: @json(__('central.TestingConnection')),
        creatingDatabase: @json(__('central.CreatingDatabase')),
        createDatabase: @json(__('central.CreateDatabase')),
        requestFailed: @json(__('central.RequestFailed')),
        verifyingConnection: @json(__('central.VerifyingConnection')),
        connectionCheckFailed: @json(__('central.ConnectionCheckFailed')),
        fillDbFields: @json(__('central.FillDbFieldsBeforeTesting')),
        testConnectionFirst: @json(__('central.TestConnectionFirst')),
        testConnectionFirstText: @json(__('central.TestConnectionFirstText')),
        testBeforeSavingText: @json(__('central.TestBeforeSavingText')),
    },
};
</script>
<script src="{{ asset('assets_super/js/tenants-index.js') }}"></script>
@endpush
@endsection
