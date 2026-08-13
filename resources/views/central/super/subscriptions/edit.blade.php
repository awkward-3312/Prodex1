@extends('central.super.layout')

@section('title', __('super.subscriptions.edit_title'))

@section('content')
@php
    $tenant = $subscription->tenant;
    $tenantDisplayName = $tenant->company_name ?? ($tenant->domains->first()->domain ?? $tenant->id);
    $tenantIsProvisioned = in_array($tenant->status, ['active', 'suspended', 'cancelled']);
    $allowedTransitions = \App\Models\Central\TenantSubscription::ALLOWED_TRANSITIONS[$subscription->status] ?? [];
@endphp
<div class="breadcrumb-custom">
    <a href="{{ route('super.subscriptions.index') }}">{{ __('super.subscriptions.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ $tenantDisplayName }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.subscriptions.edit_sub') }}</h1>
    <p class="page-subtitle">{{ __('super.subscriptions.manage_sub') }} <strong>{{ $tenantDisplayName }}</strong>.</p>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-credit-card-2-front me-2 text-muted"></i>{{ __('super.subscriptions.sub_details') }}</h2>
            </div>
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.subscriptions.update', $subscription) }}">
                    @csrf
                    @method('PUT')

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.subscriptions.plan') }} <span class="text-danger">*</span></label>
                            <select name="plan_id" class="form-control" required>
                                @foreach($plans as $p)
                                <option value="{{ $p->id }}" {{ $subscription->plan_id == $p->id ? 'selected' : '' }}>
                                    {{ $p->name }} — {{ $currencySymbol }}{{ number_format($p->price, 2) }}/{{ __('super.plans.mo') }} · {{ $currencySymbol }}{{ number_format($p->yearly_price, 2) }}/{{ __('super.plans.yr') }}{{ $p->is_private ? ' [Private]' : '' }}
                                </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.common.status') }} <span class="text-danger">*</span></label>
                            @if(! $tenantIsProvisioned)
                            <div class="mb-2 p-3 rounded-3 approve-notice-amber">
                                <div class="d-flex gap-2">
                                    <i class="bi bi-info-circle-fill flex-shrink-0 approve-notice-amber-icon"></i>
                                    <p class="mb-0 approve-notice-amber-text">
                                        {!! __('super.tenants.activate_requires_provision', ['url' => route('super.tenants.show', $tenant)]) !!}
                                    </p>
                                </div>
                            </div>
                            @endif
                            <select name="status" class="form-control" required>
                                <option value="{{ $subscription->status }}" selected>{{ __('super.status.' . $subscription->status) }} ({{ __('super.subscriptions.current') }})</option>
                                @if(in_array('active', $allowedTransitions) && $tenantIsProvisioned)
                                    <option value="active">{{ __('super.status.active') }}</option>
                                @endif
                                @if(in_array('trial', $allowedTransitions))
                                    <option value="trial">{{ __('super.status.trial') }}</option>
                                @endif
                                @if(in_array('suspended', $allowedTransitions))
                                    <option value="suspended">{{ __('super.status.suspended') }}</option>
                                @endif
                                @if(in_array('cancelled', $allowedTransitions))
                                    <option value="cancelled">{{ __('super.status.cancelled') }}</option>
                                @endif
                                @if(in_array('expired', $allowedTransitions))
                                    <option value="expired">{{ __('super.status.expired') }}</option>
                                @endif
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.subscriptions.trial_ends_at') }}</label>
                            <input type="datetime-local" name="trial_ends_at" class="form-control" value="{{ old('trial_ends_at', $subscription->trial_ends_at?->format('Y-m-d\TH:i')) }}">
                            <p class="form-hint">{{ __('super.subscriptions.trial_hint') }}</p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.subscriptions.ends_at_label') }}</label>
                            <input type="datetime-local" name="ends_at" class="form-control" value="{{ old('ends_at', $subscription->ends_at?->format('Y-m-d\TH:i')) }}">
                            <p class="form-hint">{{ __('super.subscriptions.ends_hint') }}</p>
                        </div>
                    </div>

                    <div class="divider"></div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> {{ __('super.subscriptions.update_btn') }}
                        </button>
                        <a href="{{ route('super.subscriptions.index') }}" class="btn btn-secondary">{{ __('super.common.cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="content-card mb-4">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-info-circle text-muted fs-lg2"></i>
                    <h3 class="mb-0 fw-700 fs-md">{{ __('super.subscriptions.current_sub') }}</h3>
                </div>
                <ul class="info-list info-list-sm">
                    <li>
                        <span class="info-label min-w-90">{{ __('super.subscriptions.tenant') }}</span>
                        <span class="info-value fw-600">{{ $tenantDisplayName }}</span>
                    </li>
                    <li>
                        <span class="info-label min-w-90">{{ __('super.subscriptions.plan') }}</span>
                        <span class="info-value">{{ $subscription->plan->name ?? '—' }}</span>
                    </li>
                    <li>
                        <span class="info-label min-w-90">{{ __('super.common.status') }}</span>
                        <span class="info-value"><span class="badge badge-{{ $subscription->status }}">{{ __('super.status.' . $subscription->status) }}</span></span>
                    </li>
                    @if($subscription->trial_ends_at)
                    <li>
                        <span class="info-label min-w-90">{{ __('super.subscriptions.trial_ends') }}</span>
                        <span class="info-value">
                            {{ $subscription->trial_ends_at->format('M d, Y') }}
                            @if($subscription->trial_ends_at->isFuture())
                                <span class="badge badge-trial ms-1 badge-xs">{{ $subscription->trial_ends_at->diffForHumans() }}</span>
                            @endif
                        </span>
                    </li>
                    @endif
                    @if($subscription->ends_at)
                    <li>
                        <span class="info-label min-w-90">{{ __('super.subscriptions.ends_at') }}</span>
                        <span class="info-value">{{ $subscription->ends_at->format('M d, Y') }}</span>
                    </li>
                    @endif
                </ul>
            </div>
        </div>

        <div class="content-card">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-lightbulb text-muted fs-lg2"></i>
                    <h3 class="mb-0 fw-700 fs-md">{{ __('super.common.notes') }}</h3>
                </div>
                <div class="d-flex flex-column gap-3 sidebar-tip-list">
                    <div class="d-flex gap-2">
                        <i class="bi bi-dot sidebar-dot-icon"></i>
                        <span>{{ __('super.subscriptions.note_plan') }}</span>
                    </div>
                    <div class="d-flex gap-2">
                        <i class="bi bi-dot sidebar-dot-icon"></i>
                        <span>{{ __('super.subscriptions.note_suspend') }}</span>
                    </div>
                    <div class="d-flex gap-2">
                        <i class="bi bi-dot sidebar-dot-icon"></i>
                        <span>{{ __('super.subscriptions.note_reactivate') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
