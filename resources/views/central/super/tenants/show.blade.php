@extends('central.super.layout')

@section('title', __('super.tenants.tenant') . ' ' . $tenant->id)

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.tenants.index') }}">{{ __('super.tenants.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ $tenant->id }}</span>
</div>

<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div class="d-flex align-items-center gap-3">
        @php
            $displayName = $tenant->company_name ?? ($tenant->domains->first()->domain ?? $tenant->id);
            $colors = ['purple', 'blue', 'green', 'amber', 'rose'];
            $color = $colors[crc32($tenant->id) % count($colors)];
            $initial = strtoupper(mb_substr($displayName, 0, 1));
        @endphp
        <div class="tenant-avatar tenant-avatar-{{ $color }} tenant-avatar-xl">{{ $initial }}</div>
        <div>
            <h1 class="mb-2xs">{{ $displayName }}</h1>
            <div class="d-flex align-items-center gap-2">
                <span class="badge badge-{{ $tenant->status }}">{{ ucfirst($tenant->status) }}</span>
                <span class="text-muted fs-sm3">{{ __('super.tenants.registered') }} {{ $tenant->created_at->format('M d, Y') }}</span>
            </div>
        </div>
    </div>
    <div class="btn-group-custom">
        <a href="{{ route('super.tenants.edit', $tenant) }}" class="btn btn-primary btn-sm">
            <i class="bi bi-pencil"></i> {{ __('super.common.edit') }}
        </a>
        @if($tenant->status !== 'suspended')
            <form method="POST" action="{{ route('super.tenants.suspend', $tenant) }}" class="d-inline">
                @csrf
                <button type="button" class="btn btn-warning btn-sm" data-swal-action='{"title":@json(__('super.tenants.suspend')),"text":@json(__('super.tenants.confirm_suspend')),"icon":"warning","confirmText":@json(__('super.tenants.suspend')),"color":"#f59e0b"}'>
                    <i class="bi bi-pause-circle"></i> {{ __('super.tenants.suspend') }}
                </button>
            </form>
        @else
            <form method="POST" action="{{ route('super.tenants.reactivate', $tenant) }}" class="d-inline">
                @csrf
                <button type="button" class="btn btn-success btn-sm" data-swal-action='{"title":@json(__('super.tenants.reactivate')),"text":@json(__('central.ReactivateTenantText')),"icon":"question","confirmText":@json(__('central.YesReactivate')),"color":"#10b981"}'>
                    <i class="bi bi-play-circle"></i> {{ __('super.tenants.reactivate') }}
                </button>
            </form>
        @endif
        @if($tenant->status !== 'cancelled')
            <form method="POST" action="{{ route('super.tenants.cancel', $tenant) }}" class="d-inline">
                @csrf
                <button type="button" class="btn btn-sm btn-cancel-tenant" data-swal-action='{"title":@json(__('super.common.cancel')),"text":@json(__('super.tenants.confirm_cancel')),"icon":"warning","confirmText":@json(__('super.common.yes')),"cancelText":@json(__('super.common.no')),"color":"#f43f5e"}'>
                    <i class="bi bi-x-circle"></i> {{ __('super.common.cancel') }}
                </button>
            </form>
        @endif
        <form method="POST" action="{{ route('super.tenants.destroy', $tenant) }}" class="d-inline">
            @csrf
            @method('DELETE')
            <button type="button" class="btn btn-danger btn-sm" data-swal-action='{"title":@json(__('super.tenants.delete_title')),"text":@json(__('super.tenants.delete_confirm')),"icon":"error","confirmText":@json(__('super.tenants.delete_confirm_btn')),"color":"#dc2626"}'>
                <i class="bi bi-trash"></i> {{ __('super.tenants.delete') }}
            </button>
        </form>
    </div>
</div>

<div class="row g-4">
    {{-- Tenant details --}}
    <div class="col-lg-7">
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-info-circle me-2 text-muted"></i>{{ __('super.tenants.details') }}</h2>
            </div>
            <div class="card-body-custom">
                <ul class="info-list">
                    <li>
                        <span class="info-label">{{ __('super.tenants.tenant_id') }}</span>
                        <span class="info-value text-mono">{{ $tenant->id }}</span>
                    </li>
                    <li>
                        <span class="info-label">{{ __('super.common.status') }}</span>
                        <span class="info-value"><span class="badge badge-{{ $tenant->status }}">{{ ucfirst($tenant->status) }}</span></span>
                    </li>
                    <li>
                        <span class="info-label">{{ __('super.tenants.plan') }}</span>
                        <span class="info-value">
                            @if($tenant->subscription?->plan)
                                <span class="fw-600">{{ $tenant->subscription->plan->name }}</span>
                                <span class="text-muted ms-1 fs-sm">— {{ $currencySymbol }}{{ number_format($tenant->subscription->plan->price, 2) }}/{{ __('super.plans.mo') }}</span>
                            @else
                                <span class="text-muted">{{ __('super.tenants.no_plan') }}</span>
                            @endif
                        </span>
                    </li>
                    <li>
                        <span class="info-label">{{ __('super.tenants.subscription') }}</span>
                        <span class="info-value">
                            @if($tenant->subscription)
                                <span class="badge badge-{{ $tenant->subscription->status }}">{{ ucfirst($tenant->subscription->status) }}</span>
                            @else
                                <span class="text-muted">{{ __('super.tenants.no_subscription') }}</span>
                            @endif
                        </span>
                    </li>
                    @if($tenant->subscription?->trial_ends_at)
                    <li>
                        <span class="info-label">{{ __('super.tenants.trial_ends') }}</span>
                        <span class="info-value">
                            {{ $tenant->subscription->trial_ends_at->format('M d, Y') }}
                            @if($tenant->subscription->trial_ends_at->isFuture())
                                <span class="badge badge-trial ms-1">{{ $tenant->subscription->trial_ends_at->diffForHumans() }}</span>
                            @else
                                <span class="badge badge-cancelled ms-1">{{ __('super.tenants.expired') }}</span>
                            @endif
                        </span>
                    </li>
                    @endif
                    @if($tenant->locale)
                    <li>
                        <span class="info-label">{{ __('central.EmailLanguage') }}</span>
                        <span class="info-value">{{ strtoupper($tenant->locale) }}</span>
                    </li>
                    @endif
                    @if($tenant->admin_email)
                    <li>
                        <span class="info-label">{{ __('central.AdminEmail') }}</span>
                        <span class="info-value">{{ $tenant->admin_email }}</span>
                    </li>
                    @endif
                    <li>
                        <span class="info-label">{{ __('central.OwnerPhone') }}</span>
                        <span class="info-value">{{ $tenant->owner_phone ?: '—' }}</span>
                    </li>
                    <li>
                        <span class="info-label">{{ __('super.tenants.created') }}</span>
                        <span class="info-value">{{ $tenant->created_at->format('M d, Y \a\t h:i A') }}</span>
                    </li>
                    @if($tenant->updated_at && $tenant->updated_at->ne($tenant->created_at))
                    <li>
                        <span class="info-label">{{ __('super.tenants.last_updated') }}</span>
                        <span class="info-value">{{ $tenant->updated_at->format('M d, Y \a\t h:i A') }}</span>
                    </li>
                    @endif
                </ul>
            </div>
        </div>

        {{-- Plan limits & features --}}
        @if($tenant->subscription?->plan)
        @php $plan = $tenant->subscription->plan; @endphp
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-speedometer2 me-2 text-muted"></i>{{ __('super.tenants.plan_limits') }}</h2>
                <a href="{{ route('super.plans.edit', $plan) }}" class="btn btn-ghost btn-xs">
                    <i class="bi bi-pencil"></i> {{ __('super.tenants.edit_plan') }}
                </a>
            </div>
            <div class="card-body-custom">
                {{-- Limits with usage --}}
                <div class="mb-4">
                    <p class="form-label mb-2 text-section-label">{{ __('super.tenants.usage_limits') }}</p>
                    <div class="row g-2">
                        @foreach($plan->getFormattedLimits() as $key => $lim)
                        @php
                            $current = $usageStats[$key] ?? null;
                            $max = $lim['value'];
                            $isUnlimited = $max < 0;
                            $percentage = (!$isUnlimited && $max > 0 && $current !== null) ? min(100, round(($current / $max) * 100)) : 0;
                            $isNearLimit = $percentage >= 80;
                            $isAtLimit = $percentage >= 100;
                        @endphp
                        <div class="col-sm-6">
                            <div class="p-2 rounded-2 bg-surface-dark">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <span class="d-flex align-items-center gap-2 limit-label">
                                        <i class="bi {{ $lim['icon'] }} icon-w-1rem"></i>
                                        {{ $lim['label'] }}
                                    </span>
                                    <span class="fw-700 fs-sm3" style="color: {{ $isAtLimit ? '#ef4444' : ($isNearLimit ? '#f59e0b' : ($isUnlimited ? 'var(--color-primary)' : 'var(--color-dark)')) }};">
                                        @if($current !== null && !$isUnlimited)
                                            {{ number_format($current) }}/{{ $lim['display'] }}
                                        @elseif($current !== null && $isUnlimited)
                                            {{ number_format($current) }} / <i class="bi bi-infinity"></i>
                                        @elseif($isUnlimited)
                                            <i class="bi bi-infinity"></i>
                                        @else
                                            {{ $lim['display'] }}
                                        @endif
                                    </span>
                                </div>
                                @if(!$isUnlimited && $current !== null && $max > 0)
                                <div class="usage-bar-track">
                                    <div style="height: 100%; width: {{ $percentage }}%; background: {{ $isAtLimit ? '#ef4444' : ($isNearLimit ? '#f59e0b' : '#6366f1') }}; border-radius: 2px; transition: width .3s;"></div>
                                </div>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- Features --}}
                @php $activeFeatures = $plan->getActiveFeatures(); @endphp
                <div>
                    <p class="form-label mb-2 text-section-label">{{ __('super.tenants.feature_access') }}</p>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach(\App\Models\Central\Plan::AVAILABLE_FEATURES as $fKey => $fMeta)
                        @php $enabled = $plan->hasFeature($fKey); @endphp
                        <div class="d-flex align-items-center gap-2 px-3 py-2 rounded-2 {{ $enabled ? 'feature-badge-enabled' : 'feature-badge-disabled' }}">
                            <i class="bi {{ $enabled ? 'bi-check-circle-fill' : 'bi-x-circle' }}"></i>
                            <span>{{ $fMeta['label'] }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        @endif
    </div>

    {{-- Domains --}}
    <div class="col-lg-5">
        <div class="content-card h-100">
            <div class="card-header-custom">
                <h2><i class="bi bi-globe me-2 text-muted"></i>{{ __('super.tenants.domains') }}</h2>
                <span class="badge badge-trial badge-sm">{{ $tenant->domains->count() }} {{ Str::plural(__('central.DomainWord'), $tenant->domains->count()) }}</span>
            </div>
            <div class="card-body-custom">
                @if($tenant->domains->isNotEmpty())
                    <div class="d-flex flex-column gap-2">
                        @foreach($tenant->domains as $d)
                        @php
                            $fullDomain = str_contains($d->domain, '.') ? $d->domain : $d->domain . '.' . (preg_replace('/^www\./i', '', parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST) ?? 'localhost'));
                        @endphp
                        <div class="d-flex align-items-center justify-content-between p-3 rounded-3 domain-card">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-link-45deg text-muted"></i>
                                <div>
                                    <span class="text-mono fw-500 fs-sm4">{{ $fullDomain }}</span>
                                    @if(!str_contains($d->domain, '.'))
                                        <span class="text-muted d-block fs-xs2">{{ __('central.SubdomainLabel') }}: {{ $d->domain }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge badge-active badge-sm">{{ __('super.common.active') }}</span>
                                @if($tenant->domains->count() > 1)
                                <form method="POST" action="{{ route('super.tenants.domains.destroy', [$tenant, $d->id]) }}" class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" class="btn p-0 border-0 bg-transparent btn-delete-danger fs-sm3" data-swal-action='{"title":"Remove subdomain?","text":@json("Remove {$d->domain}? The tenant will no longer be accessible via this subdomain."),"icon":"warning","confirmText":"Yes, remove","color":"#ef4444"}'>
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @if(\App\Models\Central\GeneralSetting::instance()->isSharedHosting())
                    <div class="mt-3 p-2 rounded-2 notice-warning">
                        <p class="mb-0 d-flex align-items-center gap-2 fs-xs3 text-warning-dark">
                            <i class="bi bi-info-circle-fill"></i>
                            {{ __('central.SharedHostingSubdomainNotice') }}
                        </p>
                    </div>
                    @endif
                @else
                    <div class="text-center py-4">
                        <i class="bi bi-globe text-muted fs-3xl"></i>
                        <p class="text-muted mt-2 mb-0 small">{{ __('super.tenants.no_domains') }}</p>
                    </div>
                @endif

                {{-- Add subdomain form --}}
                @php $baseDomain = preg_replace('/^www\./i', '', parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST) ?? 'localhost'); @endphp
                <div class="border-top-border mt-3 pt-3">
                    <form method="POST" action="{{ route('super.tenants.domains.store', $tenant) }}" id="add-subdomain-form">
                        @csrf
                        <label class="form-label form-label-sm">{{ __('central.AddSubdomain') }}</label>
                        <div class="d-flex gap-2">
                            <div class="input-group input-group-sm flex-grow-1">
                                <input type="text" name="domain" class="form-control form-control-sm form-control-sm-custom" placeholder="myshop" required pattern="[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?" value="{{ old('domain') }}">
                                <span class="input-group-text fs-xs3 text-muted">.{{ $baseDomain }}</span>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary-action">
                                <i class="bi bi-plus-lg me-1"></i>{{ __('central.Add') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Custom Domains --}}
@php
    $customDomainVerifier = app(\App\Services\CustomDomainVerifier::class);
    $centralHost = $customDomainVerifier->centralHost();
@endphp
<div class="content-card mt-4" id="custom-domains-card">
    <div class="card-header-custom d-flex align-items-center justify-content-between">
        <h2><i class="bi bi-globe2 me-2 text-muted"></i>{{ __('super.custom_domains.title') }}</h2>
        <span class="badge badge-trial badge-sm">{{ $tenant->customDomains->count() }}</span>
    </div>
    <div class="card-body-custom">
        <p class="text-muted fs-sm mb-3">{{ __('super.custom_domains.subtitle') }}</p>

        @if($tenant->customDomains->isNotEmpty())
            <div class="d-flex flex-column gap-2 mb-3">
                @foreach($tenant->customDomains as $cd)
                <div class="d-flex align-items-center justify-content-between p-3 rounded-3 domain-card flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2 flex-grow-1">
                        <i class="bi bi-{{ $cd->is_verified ? 'shield-check text-success' : 'shield-exclamation text-warning' }}"></i>
                        <div>
                            <span class="text-mono fw-500 fs-sm4">{{ $cd->domain }}</span>
                            <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
                                @if($cd->is_verified)
                                    <span class="badge badge-active badge-sm">{{ __('super.custom_domains.status_verified') }}</span>
                                @else
                                    <span class="badge badge-trial badge-sm">{{ __('super.custom_domains.status_pending') }}</span>
                                @endif
                                @if($cd->is_primary)
                                    <span class="badge badge-active badge-sm">{{ __('super.custom_domains.primary_badge') }}</span>
                                @endif
                                <span class="badge badge-sm badge-ssl-{{ $cd->ssl_status }}">
                                    {{ __('super.custom_domains.ssl_' . $cd->ssl_status) }}
                                </span>
                                <span class="text-muted fs-xs2">
                                    {{ __('super.custom_domains.last_check') }}:
                                    {{ $cd->last_check_at ? $cd->last_check_at->diffForHumans() : __('super.custom_domains.never_checked') }}
                                </span>
                            </div>
                            @if(!$cd->is_verified && $cd->last_check_error)
                                <div class="text-danger fs-xs2 mt-1"><i class="bi bi-exclamation-circle me-1"></i>{{ $cd->last_check_error }}</div>
                            @endif
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        @if(!$cd->is_verified)
                            <button type="button"
                                    class="btn btn-sm btn-primary-action js-verify-custom-domain"
                                    data-url="{{ route('super.tenants.custom-domains.verify', [$tenant, $cd->id]) }}">
                                <i class="bi bi-arrow-repeat me-1"></i>{{ __('super.custom_domains.verify') }}
                            </button>
                        @elseif(!$cd->is_primary)
                            <form method="POST" action="{{ route('super.tenants.custom-domains.primary', [$tenant, $cd->id]) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline">
                                    <i class="bi bi-star me-1"></i>{{ __('super.custom_domains.make_primary') }}
                                </button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('super.tenants.custom-domains.destroy', [$tenant, $cd->id]) }}" class="d-inline">
                            @csrf
                            @method('DELETE')
                            <button type="button" class="btn p-0 border-0 bg-transparent btn-delete-danger fs-sm3"
                                    data-swal-action='{"title":@json(__('super.custom_domains.confirm_remove_title')),"text":@json(__('super.custom_domains.confirm_remove_text')),"icon":"warning","confirmText":@json(__('super.common.yes')),"cancelText":@json(__('super.common.no')),"color":"#ef4444"}'>
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- DNS instructions accordion --}}
            <details class="custom-domain-dns">
                <summary class="d-flex align-items-center gap-2 fw-600 text-muted">
                    <i class="bi bi-question-circle"></i>{{ __('super.custom_domains.dns_instructions_title') }}
                </summary>
                <div class="mt-3 p-3 rounded-3 notice-info">
                    <p class="mb-2 fs-sm">{{ __('super.custom_domains.dns_intro') }}</p>
                    <ul class="list-unstyled mb-0 fs-sm">
                        @foreach($tenant->customDomains as $cd)
                            @continue($cd->is_verified)
                            <li class="mb-3 pb-3 border-bottom">
                                <div class="text-mono fs-xs2 text-muted mb-1">{{ $cd->domain }}</div>
                                <div class="mb-1"><strong>{{ __('super.custom_domains.dns_cname_label') }}:</strong>
                                    {{ __('super.custom_domains.dns_cname_value', ['host' => $centralHost]) }}
                                </div>
                                <div class="mb-1"><strong>{{ __('super.custom_domains.dns_a_label') }}:</strong>
                                    {{ __('super.custom_domains.dns_a_value', ['host' => $centralHost]) }}
                                </div>
                                <div><strong>{{ __('super.custom_domains.dns_txt_label') }}:</strong>
                                    {{ __('super.custom_domains.dns_txt_value', ['domain' => $cd->domain, 'token' => $cd->verification_token]) }}
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mb-0 fs-xs2 text-muted mt-2"><i class="bi bi-shield-lock me-1"></i>{{ __('super.custom_domains.ssl_hint') }}</p>
                </div>
            </details>
        @endif

        {{-- Add form --}}
        <div class="border-top-border mt-3 pt-3">
            <form method="POST" action="{{ route('super.tenants.custom-domains.store', $tenant) }}">
                @csrf
                <label class="form-label form-label-sm">{{ __('super.custom_domains.add') }}</label>
                <div class="d-flex gap-2 flex-wrap">
                    <input type="text" name="domain"
                           class="form-control form-control-sm form-control-sm-custom flex-grow-1"
                           style="min-width:260px;"
                           placeholder="{{ __('super.custom_domains.placeholder') }}"
                           value="{{ old('domain') }}"
                           required
                           pattern="[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+">
                    <button type="submit" class="btn btn-sm btn-primary-action">
                        <i class="bi bi-plus-lg me-1"></i>{{ __('super.custom_domains.add') }}
                    </button>
                </div>
                @error('domain')
                    <div class="text-danger fs-xs2 mt-1">{{ $message }}</div>
                @enderror
            </form>
        </div>
    </div>
</div>

{{-- Approve & Provision (visible only for PENDING tenants) --}}
@if($tenant->isPending())
@php
    $approvePlan = $tenant->subscription?->plan;
    $approveIsFreeOrTrial = $approvePlan && ($approvePlan->isFree() || $approvePlan->hasTrial());
    $approveHasPaidPayment = $tenant->payments()->where('status', 'paid')->exists();
    $approveCanApprove = $approveIsFreeOrTrial || $approveHasPaidPayment;
    $approveIsShared = \App\Models\Central\GeneralSetting::instance()->isSharedHosting();
@endphp
<div class="content-card mt-4">
    <div class="card-header-custom">
        <h2><i class="bi bi-check-circle me-2 text-muted"></i>{{ __('central.ApproveAndProvision') }}</h2>
    </div>
    <div class="card-body-custom">
        @if(! $approveCanApprove)
            <div class="p-3 rounded-3 approve-payment-required">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-triangle-fill approve-payment-required-icon"></i>
                    <div>
                        <p class="mb-0 fw-600 approve-payment-required-title">{{ __('central.PaymentRequiredBeforeApproval') }}</p>
                        <p class="mb-0 text-muted approve-payment-required-desc">{{ __('central.PaymentRequiredDesc') }}</p>
                    </div>
                </div>
            </div>
        @else
            <form method="POST" action="{{ route('super.tenants.approve', $tenant) }}" id="approveForm">
                @csrf

                @if($approveIsFreeOrTrial)
                <div class="mb-3 p-3 rounded-3 approve-notice-green">
                    <p class="mb-0 d-flex align-items-center gap-2 approve-notice-green-text">
                        <i class="bi bi-check-circle-fill"></i>
                        {{ $approvePlan->isFree() ? __('central.FreePlanNoPayment') : __('central.TrialPlanDaysFree', ['days' => $approvePlan->getTrialDays()]) }}
                    </p>
                </div>
                @else
                <div class="mb-3 p-3 rounded-3 approve-notice-green">
                    <p class="mb-0 d-flex align-items-center gap-2 approve-notice-green-text">
                        <i class="bi bi-check-circle-fill"></i>
                        {{ __('central.PaymentConfirmedReadyToApprove') }}
                    </p>
                </div>
                @endif

                @php
                    $defaultDbHost = $approveIsShared
                        ? 'localhost'
                        : config('database.connections.central.host', '127.0.0.1');
                    $defaultDbUser = $approveIsShared
                        ? ''
                        : config('database.connections.central.username', 'root');
                    $defaultDbPassword = $approveIsShared
                        ? ''
                        : config('database.connections.central.password', '');
                    $defaultDbPort = $approveIsShared
                        ? 3306
                        : (int) config('database.connections.central.port', 3306);
                    $defaultDbName = $approveIsShared
                        ? 'tenant_' . ($tenant->domains->first()->domain ?? '')
                        : config('tenancy.database.prefix', 'tenant') . $tenant->id . config('tenancy.database.suffix', '');
                @endphp

                <div class="mb-3 p-3 rounded-3 approve-notice-amber">
                    <div class="d-flex gap-2">
                        <i class="bi bi-info-circle-fill flex-shrink-0 approve-notice-amber-icon"></i>
                        <p class="mb-0 approve-notice-amber-text">
                            @if($approveIsShared)
                                {!! __('central.SharedHostingDbHint') !!}
                            @else
                                {!! __('central.VpsDbHint') !!}
                            @endif
                        </p>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">{{ __('central.DbHost') }} <span class="text-danger">*</span></label>
                        <input type="text" name="db_host" class="form-control form-control-sm approve-db-field" value="{{ old('db_host', $defaultDbHost) }}" required placeholder="localhost">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">{{ __('central.DbName') }} <span class="text-danger">*</span></label>
                        <input type="text" name="db_name" id="approveDbName" class="form-control form-control-sm approve-db-field" value="{{ old('db_name', $defaultDbName) }}" required placeholder="tenant_name">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">{{ __('central.DbPort') }} <span class="text-danger">*</span></label>
                        <input type="number" name="db_port" class="form-control form-control-sm approve-db-field" value="{{ old('db_port', $defaultDbPort) }}" required min="1" max="65535">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">{{ __('central.DbUsername') }} <span class="text-danger">*</span></label>
                        <input type="text" name="db_username" class="form-control form-control-sm approve-db-field" value="{{ old('db_username', $defaultDbUser) }}" required placeholder="db_user">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">{{ __('central.DbPassword') }}</label>
                        <input type="password" name="db_password" class="form-control form-control-sm approve-db-field" value="{{ old('db_password', $defaultDbPassword) }}" placeholder="********">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="button" class="btn btn-sm btn-test-connection" id="btnTestApproveDb">
                            <i class="bi bi-plug me-1"></i>{{ __('central.TestConnection') }}
                        </button>
                    </div>
                </div>
                <div id="approve-db-test-result" class="mb-3"></div>

                <button type="button" class="btn btn-success approve-locked" id="btnApprove"
                    data-swal-action='{"title":@json(__('central.ApproveAndProvisionTitle')),"text":@json(__('central.ApproveAndProvisionText')),"icon":"question","confirmText":@json(__('central.YesApprove')),"color":"#10b981"}'>
                    <i class="bi bi-check-circle me-1"></i> {{ __('central.ApproveAndProvision') }}
                </button>
            </form>
        @endif
    </div>
</div>
@endif

{{-- Admin Actions (provisioned tenants + failed tenants so a broken
     provision can be retried via Re-provision / Reset / Re-seed). --}}
@if(in_array($tenant->status, ['active', 'suspended', 'cancelled', 'failed']))
<div class="content-card mt-4">
    <div class="card-header-custom">
        <h2><i class="bi bi-gear-wide-connected me-2 text-muted"></i>{{ __('central.AdminActions') }}</h2>
    </div>
    <div class="card-body-custom">
        <div class="d-flex flex-wrap gap-2">
            {{-- Re-run Migrations --}}
            <form method="POST" action="{{ route('super.tenants.rerun-migrations', $tenant) }}" class="d-inline" id="form-rerun-migrations">
                @csrf
                <button type="button" class="btn btn-sm btn-migration" data-swal-action='{"title":@json(__('central.RerunMigrationsTitle')),"text":@json(__('central.RerunMigrationsText')),"icon":"info","confirmText":@json(__('central.YesRunMigrations')),"color":"#d97706"}'>
                    <i class="bi bi-arrow-repeat me-1"></i>{{ __('central.RerunMigrations') }}
                </button>
            </form>

            {{-- Re-seed --}}
            <form method="POST" action="{{ route('super.tenants.reseed', $tenant) }}" class="d-inline" id="form-reseed">
                @csrf
                <button type="button" class="btn btn-sm btn-reseed" data-swal-action='{"title":@json(__('central.ReseedTitle')),"text":@json(__('central.ReseedText')),"icon":"info","confirmText":@json(__('central.YesReseed')),"color":"#6366f1"}'>
                    <i class="bi bi-database-down me-1"></i>{{ __('central.Reseed') }}
                </button>
            </form>

            {{-- Re-provision (Full Re-install) --}}
            <form method="POST" action="{{ route('super.tenants.reprovision', $tenant) }}" class="d-inline" id="form-reprovision">
                @csrf
                <button type="button" class="btn btn-sm btn-reprovision" data-swal-action='{"title":@json(__('central.ReprovisionTitle')),"html":@json(__('central.ReprovisionHtml')),"icon":"warning","confirmText":@json(__('central.YesReprovision')),"color":"#ea580c"}'>
                    <i class="bi bi-arrow-clockwise me-1"></i>{{ __('central.Reprovision') }}
                </button>
            </form>

            {{-- Reset Database (Drop & Migrate) --}}
            <form method="POST" action="{{ route('super.tenants.reset-database', $tenant) }}" class="d-inline" id="form-reset-db">
                @csrf
                <button type="button" class="btn btn-sm btn-reset-db" data-swal-action='{"title":@json(__('central.ResetDatabaseTitle')),"html":@json(__('central.ResetDatabaseHtml')),"icon":"error","confirmText":@json(__('central.YesResetDatabase')),"color":"#dc2626"}'>
                    <i class="bi bi-exclamation-triangle me-1"></i>{{ __('central.ResetDatabase') }}
                </button>
            </form>
        </div>
    </div>
</div>
@endif

{{-- Database Configuration (only for provisioned tenants) --}}
@if(in_array($tenant->status, ['active', 'suspended', 'cancelled']))
@php
    $dbCreds = $tenant->getEffectiveDatabaseCredentials();
    $isCustomCreds = $tenant->hasDatabaseCredentials();
@endphp
<div class="content-card mt-4">
    <div class="card-header-custom d-flex align-items-center justify-content-between">
        <h2><i class="bi bi-database-fill-gear me-2 text-muted"></i>{{ __('central.DatabaseConfiguration') }}</h2>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-test-connection" id="testConnBtn">
                <i class="bi bi-plug me-1"></i>{{ __('central.TestConnection') }}
            </button>
            <button type="button" class="btn btn-sm btn-toggle-edit" id="btnToggleDbEdit">
                <i class="bi bi-pencil me-1"></i>{{ __('super.common.edit') }}
            </button>
        </div>
    </div>
    <div class="card-body-custom">
        <div id="db-test-result" class="mb-3"></div>

        {{-- Credential source indicator --}}
        <div class="mb-3">
            @if($isCustomCreds)
            <span class="badge badge-config-admin">
                <i class="bi bi-person-gear me-1"></i>{{ __('central.AdminConfiguredShared') }}
            </span>
            @else
            <span class="badge badge-config-auto">
                <i class="bi bi-gear me-1"></i>{{ __('central.AutoProvisionedVps') }}
            </span>
            @endif
        </div>
        {{-- Read-only view --}}
        <ul class="info-list" id="db-readonly-view">
            <li>
                <span class="info-label">{{ __('central.DbHost') }}</span>
                <span class="info-value text-mono">{{ $dbCreds['host'] }}</span>
            </li>
            <li>
                <span class="info-label">{{ __('central.DbName') }}</span>
                <span class="info-value text-mono">{{ $dbCreds['database'] }}</span>
            </li>
            <li>
                <span class="info-label">{{ __('central.DbUsername') }}</span>
                <span class="info-value text-mono">{{ $dbCreds['username'] }}</span>
            </li>
            <li>
                <span class="info-label">{{ __('central.DbPassword') }}</span>
                <span class="info-value text-mono">{{ $isCustomCreds ? '••••••••' : __('central.FromEnv') }}</span>
            </li>
            <li>
                <span class="info-label">{{ __('central.DbPort') }}</span>
                <span class="info-value text-mono">{{ $dbCreds['port'] }}</span>
            </li>
        </ul>
    </div>
</div>

{{-- Edit Database Credentials (separate card, toggled from the Database Configuration card) --}}
<div id="db-edit-form" class="content-card mt-4" style="display:none;">
    <div class="card-header-custom">
        <h2><i class="bi bi-pencil-square me-2 text-muted"></i>{{ __('central.EditDatabaseCredentials') }}</h2>
    </div>
    <div class="card-body-custom">
        <div class="mb-3 p-3 rounded-3" style="background:#fef2f2; border:1px solid #fecaca;">
            <div class="d-flex align-items-start gap-2">
                <i class="bi bi-exclamation-triangle-fill text-danger flex-shrink-0" style="font-size:1rem; margin-top:2px;"></i>
                <p class="mb-0" style="font-size:0.82rem; color:#991b1b;">{{ __('central.ActiveTenantDbWarning') }}</p>
            </div>
        </div>
        <form method="POST" action="{{ route('super.tenants.update-credentials', $tenant) }}" id="form-update-creds">
            @csrf
            @method('PUT')
            {{-- Subdomain info for shared hosting --}}
            @if(\App\Models\Central\GeneralSetting::instance()->isSharedHosting())
            @php $subdomainName = $tenant->domains->first()->domain ?? null; @endphp
            @if($subdomainName)
            <div class="mb-3 p-2 rounded-2 notice-warning">
                <p class="mb-0 d-flex align-items-center gap-2 fs-xs3 text-warning-dark">
                    <i class="bi bi-info-circle-fill"></i>
                    {{ __('central.SubdomainLabel') }}: <code class="code-badge-warning-sm">{{ $subdomainName }}.{{ preg_replace('/^www\./i', '', parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST) ?? 'localhost') }}</code>
                    — {{ __('central.EnsureSubdomainExists') }}
                </p>
            </div>
            @endif
            @endif
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label form-label-sm">{{ __('central.DbHost') }} <span class="text-danger">*</span></label>
                    <input type="text" name="db_host" class="form-control form-control-sm" value="{{ $dbCreds['host'] ?? 'localhost' }}" required class="form-control-sm-custom">
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">{{ __('central.DbName') }} <span class="text-danger">*</span></label>
                    <input type="text" name="db_name" class="form-control form-control-sm" value="{{ $dbCreds['database'] ?? '' }}" required placeholder="tenant_{{ $tenant->domains->first()->domain ?? 'name' }}" class="form-control-sm-custom">
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">{{ __('central.DbPort') }} <span class="text-danger">*</span></label>
                    <input type="number" name="db_port" class="form-control form-control-sm" value="{{ $dbCreds['port'] ?? 3306 }}" required min="1" max="65535" class="form-control-sm-custom">
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">{{ __('central.DbUser') }} <span class="text-danger">*</span></label>
                    <input type="text" name="db_username" class="form-control form-control-sm" value="{{ $dbCreds['username'] ?? '' }}" required placeholder="db_user" class="form-control-sm-custom">
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">{{ __('central.DbPassword') }}</label>
                    <div class="input-group input-group-sm">
                        <input type="password" name="db_password" id="db-password-field" class="form-control form-control-sm" value="{{ $dbCreds['password'] ?? '' }}" class="form-control-sm-custom">
                        <button type="button" class="btn btn-outline-secondary fs-xs3" id="btnToggleDbPassword">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-4 d-flex align-items-end gap-2">
                    <button type="button" class="btn btn-sm btn-test-connection" id="btnTestFormConnection">
                        <i class="bi bi-plug me-1"></i>{{ __('central.Test') }}
                    </button>
                </div>
            </div>
            <div id="db-edit-test-result" class="mt-2"></div>
            <div class="d-flex justify-content-end gap-2 mt-3 pt-3 border-top-border-light">
                <button type="button" class="btn btn-sm btn-outline-secondary btn-cancel-sm" id="btnCancelDbEdit">{{ __('central.Cancel') }}</button>
                <button type="button" class="btn btn-sm btn-primary-action approve-locked" id="btnSaveCreds" data-swal-action='{"title":@json(__('central.UpdateCredentialsTitle')),"text":@json(__('central.UpdateCredentialsText')),"icon":"question","confirmText":@json(__('central.YesSave')),"color":"#6366f1"}'>
                    <i class="bi bi-save me-1"></i>{{ __('central.SaveCredentials') }}
                </button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- Users --}}
<div class="content-card mt-4">
    <div class="card-header-custom">
        <h2><i class="bi bi-people me-2 text-muted"></i>{{ __('central.Users') }}</h2>
        <a href="{{ route('super.tenants.users', $tenant) }}" class="btn btn-ghost btn-xs">
            {{ __('central.ViewAll', ['count' => $tenantUserCount]) }} <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>
    <div class="card-body-custom p-0">
        @if(count($tenantUsers) > 0)
            <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th class="w-50px">#</th>
                        <th>{{ __('central.UserCol') }}</th>
                        <th>{{ __('central.EmailCol') }}</th>
                        <th>{{ __('central.Role') }}</th>
                        <th>{{ __('central.StatusCol') }}</th>
                        <th class="action-cell">{{ __('central.Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tenantUsers as $tUser)
                    <tr>
                        <td><span class="fw-600 text-muted">{{ $tUser->id }}</span></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-600 fs-sm4">{{ $tUser->firstname }} {{ $tUser->lastname }}</span>
                                @if((int) $tUser->id === 1)
                                    <span class="badge badge-trial badge-owner">{{ __('central.Owner') }}</span>
                                @endif
                            </div>
                        </td>
                        <td><span class="text-muted fs-sm3">{{ $tUser->email }}</span></td>
                        <td>
                            @php $role = $tUser->roles->first(); @endphp
                            @if($role)
                                <span class="badge badge-trial badge-sm">{{ $role->name }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($tUser->statut)
                                <span class="badge badge-active">{{ __('central.Active') }}</span>
                            @else
                                <span class="badge badge-cancelled">{{ __('central.Blocked') }}</span>
                            @endif
                        </td>
                        <td class="action-cell">
                            <button type="button"
                                    class="btn btn-outline btn-sm"
                                    data-change-password-url="{{ route('super.tenants.users.password', [$tenant, $tUser->id]) }}"
                                    data-user-name="{{ $tUser->firstname }} {{ $tUser->lastname }}">
                                <i class="bi bi-key"></i> {{ __('central.ChangePassword') }}
                            </button>
                            @if($tUser->statut)
                                <form method="POST" action="{{ route('super.tenants.users.block', [$tenant, $tUser->id]) }}" class="d-inline">
                                    @csrf
                                    <button type="button" class="btn btn-outline btn-sm btn-outline-danger-inline" data-swal-action='{"title":@json(__('central.BlockUserTitle')),"text":@json(__('central.BlockUserText', ['name' => $tUser->firstname . ' ' . $tUser->lastname])),"icon":"warning","confirmText":@json(__('central.YesBlock')),"color":"#ef4444"}'>
                                        <i class="bi bi-slash-circle"></i> {{ __('central.Block') }}
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('super.tenants.users.unblock', [$tenant, $tUser->id]) }}" class="d-inline">
                                    @csrf
                                    <button type="button" class="btn btn-outline btn-sm btn-outline-success-inline" data-swal-action='{"title":@json(__('central.UnblockUserTitle')),"text":@json(__('central.UnblockUserText', ['name' => $tUser->firstname . ' ' . $tUser->lastname])),"icon":"question","confirmText":@json(__('central.YesUnblock')),"color":"#10b981"}'>
                                        <i class="bi bi-check-circle"></i> {{ __('central.Unblock') }}
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @if($tenantUserCount > 5)
                <div class="text-center py-3 border-top-border">
                    <a href="{{ route('super.tenants.users', $tenant) }}" class="text-primary fw-600 fs-sm3 text-no-decoration">
                        {{ __('central.ViewAllUsers', ['count' => $tenantUserCount]) }} <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            @endif
        @else
            <div class="text-center py-4">
                <i class="bi bi-people text-muted fs-3xl"></i>
                <p class="text-muted mt-2 mb-0 small">{{ __('central.NoUsersForTenant') }}</p>
            </div>
        @endif
    </div>
</div>

<div class="mt-4">
    <a href="{{ route('super.tenants.index') }}" class="back-link">
        <i class="bi bi-arrow-left"></i> {{ __('super.tenants.back_to_tenants') }}
    </a>
</div>

@push('scripts')
<script>
window.TenantShowData = {
    csrfToken: '{{ csrf_token() }}',
    statusUrl: '{{ route("super.tenants.provisioning-status", $tenant) }}',
    testConnectionUrl: '{{ route("super.tenants.test-connection", $tenant) }}',
    testConnectionPreUrl: '{{ route("super.tenants.test-connection-pre") }}',
    createDatabasePreUrl: '{{ route("super.tenants.create-database-pre") }}',
    createDatabaseUrl: '{{ route("super.tenants.create-database", $tenant) }}',
    trans: {
        cancelText: @json(__('super.common.no')),
        approvingTitle: @json(__('central.ApprovingAndProvisioning')),
        processingTitle: @json(__('central.Processing')),
        provisioningHtml: @json(__('central.ProvisioningWaitHtml')),
        processingHtml: @json(__('central.ProcessingWaitHtml')),
        provisioningTimedOut: @json(__('central.ProvisioningTimedOut')),
        provisioningTimedOutText: @json(__('central.ProvisioningTimedOutText')),
        successTitle: @json(__('central.Success')),
        provisioningSuccessText: @json(__('central.ProvisioningSuccessText')),
        provisioningFailedTitle: @json(__('central.ProvisioningFailed')),
        testingConnection: @json(__('central.TestingConnection')),
        requestFailed: @json(__('central.RequestFailed')),
        fillDbFields: @json(__('central.FillDbFieldsBeforeTesting')),
        createDatabase: @json(__('central.CreateDatabase')),
        creatingDatabase: @json(__('central.CreatingDatabase')),
        testConnectionFirst: @json(__('central.TestConnectionFirst')),
        testConnectionFirstText: @json(__('central.TestConnectionFirstText')),
        testBeforeSavingText: @json(__('central.TestBeforeSavingText')),
        verifyingConnection: @json(__('central.VerifyingConnection')),
        connectionCheckFailed: @json(__('central.ConnectionCheckFailed')),
        customDomainVerifying:     @json(__('super.custom_domains.verifying')),
        customDomainVerifiedTitle: @json(__('super.custom_domains.status_verified')),
        customDomainFailedTitle:   @json(__('super.custom_domains.errors.unknown')),
    },
};
window.TenantUserPasswordData = {
    csrfToken: '{{ csrf_token() }}',
    trans: {
        title:           @json(__('central.ChangePasswordTitle')),
        subtitle:        @json(__('central.ChangePasswordSubtitle')),
        newPassword:     @json(__('central.NewPassword')),
        confirmPassword: @json(__('central.ConfirmNewPassword')),
        confirm:         @json(__('central.ChangePassword')),
        cancel:          @json(__('central.Cancel')),
        tooShort:        @json(__('central.PasswordTooShort')),
        mismatch:        @json(__('central.PasswordsDoNotMatch')),
    },
};
</script>
<script src="{{ asset('assets_super/js/tenants-show.js') }}"></script>
<script src="{{ asset('assets_super/js/tenant-user-password.js') }}"></script>
@endpush
@endsection
